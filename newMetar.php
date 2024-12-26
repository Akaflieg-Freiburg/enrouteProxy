<?php
// metar.php

// Set error reporting for production
error_reporting(E_ERROR);
ini_set('display_errors', 0);

class MetarService {
    private $pdo;
    private $sourceUrl = 'https://aviationweather.gov/data/cache/metars.cache.xml.gz';
    private $maxAge = 300; // 5 minutes in seconds

    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
        
        $this->ensureTableExists();
    }

    private function ensureTableExists() {
        $sql = "CREATE TABLE IF NOT EXISTS metar_cache (
            station_id VARCHAR(10) PRIMARY KEY,
            latitude DECIMAL(10, 6),
            longitude DECIMAL(10, 6),
            metar_data TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }

    private function needsUpdate() {
        $sql = "SELECT MAX(last_updated) as last_update FROM metar_cache";
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result['last_update']) {
            return true;
        }
        
        $lastUpdate = strtotime($result['last_update']);
        return (time() - $lastUpdate) > $this->maxAge;
    }

    private function updateDatabase($xmlData) {
        $xml = new SimpleXMLElement($xmlData);
        
        if (!$this->pdo->beginTransaction()) {
            throw new Exception("Failed to begin transaction.");
        }

        try {
            // Clear existing data
            $this->pdo->exec("DELETE FROM metar_cache");
            
            $insertSql = "INSERT INTO metar_cache 
                         (station_id, latitude, longitude, metar_data) 
                         VALUES (?, ?, ?, ?)";
            $stmt = $this->pdo->prepare($insertSql);
            
            foreach ($xml->data->METAR as $metar) {
                $stmt->execute([
                    (string)$metar->station_id,
                    (float)$metar->latitude,
                    (float)$metar->longitude,
                    $metar->asXML()
                ]);
            }
            
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Failed to update database: " . $e->getMessage());
        }
    }

    private function fetchMetarData() {
        $gzData = file_get_contents($this->sourceUrl);
        if ($gzData === false) {
            throw new Exception("Failed to download METAR data");
        }
        
        $xmlData = gzdecode($gzData);
        if ($xmlData === false) {
            throw new Exception("Failed to decompress METAR data");
        }
        
        return $xmlData;
    }

    public function getMetarsInBoundingBox($minLon, $minLat, $maxLon, $maxLat) {
        if ($this->needsUpdate()) {
            $xmlData = $this->fetchMetarData();
            $this->updateDatabase($xmlData);
        }
        
        $sql = "SELECT metar_data FROM metar_cache 
                WHERE latitude BETWEEN ? AND ?
                AND longitude BETWEEN ? AND ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$minLat, $maxLat, $minLon, $maxLon]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Configuration
$config = [
    'host' => 'sql731.your-server.de',
    'dbname' => 'enroutecaches',
    'username' => getenv('DB_USER'),
    'password' => getenv('DB_PASS')
];

// Validate input parameters
$format = $_GET['format'] ?? '';
$bbox = $_GET['bbox'] ?? '';

if ($format !== 'xml') {
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Invalid format parameter. Only XML is supported.</error></errors></response>');
    echo $error->asXML();
    exit;
}

if (empty($bbox)) {
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Missing bbox parameter</error></errors></response>');
    echo $error->asXML();
    exit;
}

// Parse and validate bbox parameter
$coords = array_map('floatval', explode(',', $bbox));
if (count($coords) !== 4) {
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Invalid bbox format. Expected: minLon,minLat,maxLon,maxLat</error></errors></response>');
    echo $error->asXML();
    exit;
}

[$minLon, $minLat, $maxLon, $maxLat] = $coords;

// Validate coordinate ranges
if ($minLat < -90 || $maxLat > 90 || $minLon < -180 || $maxLon > 180 || $minLat > $maxLat || $minLon > $maxLon) {
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Invalid coordinate ranges</error></errors></response>');
    echo $error->asXML();
    exit;
}

try {
    $metarService = new MetarService(
        $config['host'],
        $config['dbname'],
        $config['username'],
        $config['password']
    );

    $metars = $metarService->getMetarsInBoundingBox($minLon, $minLat, $maxLon, $maxLat);
    
    // Create response XML
    $output = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>' .
        '<response version="1.3" ' .
        'xsi:noNamespaceSchemaLocation="https://aviationweather.gov/data/schema/metar1_3.xsd" ' .
        'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
        '<data_source name="metars"/>' .
        '<request type="retrieve"/>' .
        '<errors/>' .
        '<warnings/>' .
        '<time_taken_ms>0</time_taken_ms>' .
        '<data/></response>');
    
    // Set number of results
    $output->data->addAttribute('num_results', count($metars));
    
    // Add each METAR to the response
    foreach ($metars as $metarXml) {
        $metar = new SimpleXMLElement($metarXml);
        $newMetar = $output->data->addChild('METAR');
        foreach ($metar->children() as $child) {
            $newChild = $newMetar->addChild($child->getName(), (string)$child);
            // Copy all attributes
            foreach ($child->attributes() as $key => $value) {
                $newChild->addAttribute($key, (string)$value);
            }
        }
    }
    
    // Output XML
    header('Content-Type: application/xml');
    echo $output->asXML();
    
} catch (Exception $e) {
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>' . 
        htmlspecialchars($e->getMessage()) . 
        '</error></errors></response>');
    echo $error->asXML();
}