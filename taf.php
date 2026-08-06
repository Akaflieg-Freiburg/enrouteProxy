<?php

/**
 * TAF Information Web Service
 * 
 * This script provides a web service that serves TAF (Terminal Aerodrome Forecast) 
 * weather reports for a specified geographic area. The data is sourced from aviationweather.gov 
 * and cached in a MySQL database for improved performance.
 * 
 * Features:
 * - Retrieves and caches TAF data from aviationweather.gov
 * - Provides TAF information within a specified bounding box
 * - Automatic cache updates when data is older than 5 minutes
 * - XML output format matching aviationweather.gov schema
 * 
 * URL Format:
 * https://your-server.com/path/to/taf.php?format=xml&bbox=minLat,minLon,maxLat,maxLon
 * 
 * Example:
 * https://your-server.com/path/to/taf.php?format=xml&bbox=45,-5,55,15
 * 
 * Required Environment Variables:
 * - DB_USER: Database username
 * - DB_PASS: Database password
 * 
 * @author Stefan Kebekus
 * @version 1.0
 */

// Set error reporting for production
error_reporting(E_ERROR);
ini_set('display_errors', 0);

/**
 * TafService Class
 * 
 * Handles the retrieval, caching, and serving of TAF weather information.
 */
class TafService {
    /** @var PDO Database connection */
    private $pdo;
    
    /** @var string URL for fetching TAF data */
    private $sourceUrl = 'https://aviationweather.gov/data/cache/tafs.cache.xml.gz';
    
    /** @var int Cache lifetime in seconds (5 minutes) */
    private $maxAge = 300;

    /**
     * Constructor - Initializes database connection and ensures table exists
     * 
     * @param string $host Database host
     * @param string $dbname Database name
     * @param string $username Database username
     * @param string $password Database password
     * @throws Exception If database connection fails
     */
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

    /**
     * Creates the cache table if it doesn't exist
     * 
     * @throws Exception If table creation fails
     */
    private function ensureTableExists() {
        $sql = "CREATE TABLE IF NOT EXISTS taf_cache (
            station_id VARCHAR(10) PRIMARY KEY,
            latitude DECIMAL(10, 6),
            longitude DECIMAL(10, 6),
            taf_data TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }

    /**
     * Checks if the cached data needs to be updated
     * 
     * @return bool True if cache is older than maxAge or empty
     */
    private function needsUpdate() {
        $sql = "SELECT MAX(last_updated) as last_update FROM taf_cache";
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result['last_update']) {
            return true;
        }
        
        $lastUpdate = strtotime($result['last_update']);
        return (time() - $lastUpdate) > $this->maxAge;
    }

    /**
     * Updates the database with new TAF data
     * 
     * @param string $xmlData Raw XML data from aviationweather.gov
     * @throws Exception If database update fails
     */
    private function updateDatabase($xmlData) {
        $xml = new SimpleXMLElement($xmlData);
        
        if (!$this->pdo->beginTransaction()) {
            throw new Exception("Failed to begin transaction.");
        }

        try {
            // Clear existing data
            $this->pdo->exec("DELETE FROM taf_cache");
            
            // The upstream cache file occasionally contains the same station
            // twice; keep the last entry instead of failing on the PRIMARY KEY.
            $insertSql = "INSERT INTO taf_cache
                         (station_id, latitude, longitude, taf_data)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                         latitude = VALUES(latitude),
                         longitude = VALUES(longitude),
                         taf_data = VALUES(taf_data)";
            $stmt = $this->pdo->prepare($insertSql);
            
            foreach ($xml->data->TAF as $taf) {
                $stmt->execute([
                    (string)$taf->station_id,
                    (float)$taf->latitude,
                    (float)$taf->longitude,
                    $taf->asXML()
                ]);
            }
            
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Failed to update database: " . $e->getMessage());
        }
    }

    /**
     * Fetches TAF data from aviationweather.gov
     * 
     * @return string Decompressed XML data
     * @throws Exception If download or decompression fails
     */
    private function fetchTafData() {
        $context = stream_context_create(['http' => ['timeout' => 15]]);
        $gzData = file_get_contents($this->sourceUrl, false, $context);
        if ($gzData === false) {
            throw new Exception("Failed to download TAF data");
        }
        
        $xmlData = gzdecode($gzData);
        if ($xmlData === false) {
            throw new Exception("Failed to decompress TAF data");
        }
        
        return $xmlData;
    }

    /**
     * Refreshes the cache from aviationweather.gov
     *
     * Only one process refreshes at a time; concurrent requests keep being
     * served from the existing cache. If the refresh fails, the error is
     * logged and stale data continues to be served.
     */
    private function refreshCache() {
        $stmt = $this->pdo->query("SELECT GET_LOCK('taf_cache_refresh', 0)");
        if ($stmt->fetchColumn() != 1) {
            return;
        }
        try {
            if ($this->needsUpdate()) {
                $this->updateDatabase($this->fetchTafData());
            }
        } catch (Exception $e) {
            error_log("TAF cache refresh failed: " . $e->getMessage());
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('taf_cache_refresh')");
        }
    }

    /**
     * Retrieves TAF data for stations within the specified bounding box
     * 
     * @param float $minLon Minimum longitude
     * @param float $minLat Minimum latitude
     * @param float $maxLon Maximum longitude
     * @param float $maxLat Maximum latitude
     * @return array Array of TAF XML strings
     * @throws Exception If data retrieval fails
     */
    public function getTafsInBoundingBox($minLon, $minLat, $maxLon, $maxLat) {
        if ($this->needsUpdate()) {
            $this->refreshCache();
        }
        
        $sql = "SELECT taf_data FROM taf_cache 
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
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Invalid bbox format. Expected: minLat,minLon,maxLat,maxLon</error></errors></response>');
    echo $error->asXML();
    exit;
}

[$minLat, $minLon, $maxLat, $maxLon] = $coords;

// Validate coordinate ranges
if ($minLat < -90 || $maxLat > 90 || $minLon < -180 || $maxLon > 180 || $minLat > $maxLat || $minLon > $maxLon) {
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Invalid coordinate ranges</error></errors></response>');
    echo $error->asXML();
    exit;
}

try {
    $tafService = new TafService(
        $config['host'],
        $config['dbname'],
        $config['username'],
        $config['password']
    );

    $tafs = $tafService->getTafsInBoundingBox($minLon, $minLat, $maxLon, $maxLat);
    
    // Create response XML
    $output = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>' .
        '<response version="1.3" ' .
        'xsi:noNamespaceSchemaLocation="https://aviationweather.gov/data/schema/taf1_3.xsd" ' .
        'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
        '<data_source name="tafs"/>' .
        '<request type="retrieve"/>' .
        '<errors/>' .
        '<warnings/>' .
        '<time_taken_ms>0</time_taken_ms>' .
        '<data/></response>');
    
    // Set number of results
    $output->data->addAttribute('num_results', count($tafs));
    
    // Add each TAF to the response
    foreach ($tafs as $tafXml) {
        $taf = new SimpleXMLElement($tafXml);
        $newTaf = $output->data->addChild('TAF');
        foreach ($taf->children() as $child) {
            // addChild() does not escape ampersands in the value
            $newChild = $newTaf->addChild($child->getName(), str_replace('&', '&amp;', (string)$child));
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
    // Log the details; do not expose internals to the client
    error_log("taf.php: " . $e->getMessage());
    header('Content-Type: application/xml');
    $error = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response><errors><error>Internal server error</error></errors></response>');
    echo $error->asXML();
}
