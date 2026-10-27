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
 * - Automatic cache updates when data is older than 5 minutes; failed updates
 *   are retried at most once a minute, and the old data is served meanwhile
 * - XML output format matching aviationweather.gov schema
 *
 * URL Format:
 * https://your-server.com/path/to/taf.php?format=xml&bbox=minLat,minLon,maxLat,maxLon
 *
 * Example:
 * https://your-server.com/path/to/taf.php?format=xml&bbox=45,-5,55,15
 *
 * Errors are reported as <response><errors><error>...</error></errors></response>
 * with HTTP 400 (bad request), 503 (no data available yet) or 500 (anything else).
 *
 * Required Environment Variables:
 * - DB_USER: Database username
 * - DB_PASS: Database password
 *
 * @author Stefan Kebekus
 * @version 1.1
 */

// Set error reporting for production
error_reporting(E_ERROR);
ini_set('display_errors', 0);

/** Thrown when there is no dataset to serve, not even a stale one. */
class TafUnavailableException extends RuntimeException {
}

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

    /** @var int Minimum time between two refresh attempts in seconds (backoff after failures) */
    private $retryInterval = 60;

    /**
     * Constructor - Initializes database connection and ensures tables exist
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

        $this->ensureTablesExist();
    }

    /**
     * Creates the cache tables if they don't exist, and makes sure the data
     * table is transactional (the refresh relies on delete-and-replace in
     * one transaction).
     *
     * @throws Exception If table creation fails
     */
    private function ensureTablesExist() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS taf_cache (
            station_id VARCHAR(10) PRIMARY KEY,
            latitude DECIMAL(10, 6),
            longitude DECIMAL(10, 6),
            taf_data TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        // One row (id = 1): when the last refresh was attempted, and when the
        // last one succeeded.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS taf_cache_state (
            id TINYINT UNSIGNED PRIMARY KEY,
            last_attempt TIMESTAMP NULL DEFAULT NULL,
            last_success TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB");

        $stmt = $this->pdo->query("SELECT ENGINE FROM information_schema.TABLES
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'taf_cache'");
        $engine = $stmt->fetchColumn();
        if ($engine !== false && strcasecmp($engine, 'InnoDB') !== 0) {
            error_log("taf.php: converting taf_cache from $engine to InnoDB");
            $this->pdo->exec("ALTER TABLE taf_cache ENGINE=InnoDB");
        }
    }

    /**
     * Reads the refresh state. Ages are computed by the database, so the
     * time zones of PHP and MySQL do not matter.
     *
     * @return array ['age' => seconds since the last successful refresh or
     *                null if there never was one, 'sinceAttempt' => seconds
     *                since the last attempt or null]
     */
    private function getState() {
        $stmt = $this->pdo->query("SELECT TIMESTAMPDIFF(SECOND, last_success, NOW()) AS age,
                                          TIMESTAMPDIFF(SECOND, last_attempt, NOW()) AS since_attempt
                                   FROM taf_cache_state WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['age' => null, 'sinceAttempt' => null];
        }
        return [
            'age'          => $row['age'] === null ? null : (int)$row['age'],
            'sinceAttempt' => $row['since_attempt'] === null ? null : (int)$row['since_attempt'],
        ];
    }

    /**
     * @param array $state From getState()
     * @return bool True if there is no dataset or it is older than maxAge
     */
    private function needsUpdate($state) {
        return $state['age'] === null || $state['age'] > $this->maxAge;
    }

    /**
     * @param array $state From getState()
     * @return bool False while the backoff after the last attempt is running
     */
    private function mayAttemptUpdate($state) {
        return $state['sinceAttempt'] === null || $state['sinceAttempt'] >= $this->retryInterval;
    }

    /** Records that a refresh is being attempted now. */
    private function markAttempt() {
        $this->pdo->exec("INSERT INTO taf_cache_state (id, last_attempt) VALUES (1, NOW())
                          ON DUPLICATE KEY UPDATE last_attempt = NOW()");
    }

    /**
     * Extracts station id, coordinates and the serialized element from one
     * TAF record, or returns null if the record is unusable.
     *
     * @param SimpleXMLElement $taf
     * @return array|null [station_id, latitude, longitude, xml]
     */
    private function extractRecord($taf) {
        $stationId = trim((string)$taf->station_id);
        $latitude  = trim((string)$taf->latitude);
        $longitude = trim((string)$taf->longitude);
        if ($stationId === '' || strlen($stationId) > 10
            || !is_numeric($latitude) || !is_numeric($longitude)
            || abs((float)$latitude) > 90 || abs((float)$longitude) > 180) {
            return null;
        }
        return [$stationId, (float)$latitude, (float)$longitude, $taf->asXML()];
    }

    /**
     * Replaces the cached data with new TAF data. The document is validated
     * first: it must be the expected <response><data> envelope with at least
     * one usable TAF record, so that a well-formed but wrong document (error
     * page, empty feed) does not wipe the cache.
     *
     * @param string $xmlData Raw XML data from aviationweather.gov
     * @throws Exception If the data is unusable or the database update fails
     */
    private function updateDatabase($xmlData) {
        $xml = new SimpleXMLElement($xmlData);
        if ($xml->getName() !== 'response' || !isset($xml->data)) {
            throw new Exception("Unexpected XML document: no <response><data> envelope");
        }

        $valid = 0;
        $skipped = 0;
        foreach ($xml->data->TAF as $taf) {
            if ($this->extractRecord($taf) === null) {
                $skipped++;
            } else {
                $valid++;
            }
        }
        if ($valid === 0) {
            throw new Exception("No usable TAF records in the downloaded data ($skipped unusable); keeping the current cache");
        }
        if ($skipped > 0) {
            error_log("taf.php: skipped $skipped unusable TAF records ($valid usable)");
        }

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
                $record = $this->extractRecord($taf);
                if ($record !== null) {
                    $stmt->execute($record);
                }
            }

            $this->pdo->exec("INSERT INTO taf_cache_state (id, last_attempt, last_success) VALUES (1, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE last_success = NOW()");

            $this->pdo->commit();
        } catch (Throwable $e) {
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
     * logged, stale data continues to be served, and no new attempt is made
     * for retryInterval seconds.
     */
    private function refreshCache() {
        $stmt = $this->pdo->query("SELECT GET_LOCK('taf_cache_refresh', 0)");
        if ($stmt->fetchColumn() != 1) {
            return;
        }
        try {
            // Re-read under the lock: another process may just have refreshed
            // or failed.
            $state = $this->getState();
            if ($this->needsUpdate($state) && $this->mayAttemptUpdate($state)) {
                $this->markAttempt();
                $this->updateDatabase($this->fetchTafData());
            }
        } catch (Throwable $e) {
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
     * @throws TafUnavailableException If there is no dataset at all
     * @throws Exception If data retrieval fails
     */
    public function getTafsInBoundingBox($minLon, $minLat, $maxLon, $maxLat) {
        $state = $this->getState();
        if ($this->needsUpdate($state) && $this->mayAttemptUpdate($state)) {
            $this->refreshCache();
            $state = $this->getState();
        }
        if ($state['age'] === null) {
            // Never successfully refreshed (or another process is doing the
            // first refresh right now): an empty answer would be wrong.
            throw new TafUnavailableException("No TAF data available yet");
        }

        $sql = "SELECT taf_data FROM taf_cache
                WHERE latitude BETWEEN ? AND ?
                AND longitude BETWEEN ? AND ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$minLat, $maxLat, $minLon, $maxLon]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

/**
 * Sends an error response in the aviationweather.gov XML shape and exits.
 *
 * @param int $status HTTP status code
 * @param string $message Error text
 */
function sendError($status, $message) {
    http_response_code($status);
    if ($status == 503) {
        header('Retry-After: 60');
    }
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<response><errors><error>' . htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        . '</error></errors></response>' . "\n";
    exit;
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
    sendError(400, 'Invalid format parameter. Only XML is supported.');
}

if (!is_string($bbox) || $bbox === '') {
    sendError(400, 'Missing bbox parameter');
}

// Parse and validate bbox parameter: four finite numbers
$parts = explode(',', $bbox);
if (count($parts) !== 4) {
    sendError(400, 'Invalid bbox format. Expected: minLat,minLon,maxLat,maxLon');
}
foreach ($parts as $part) {
    if (!is_numeric($part) || !is_finite((float)$part)) {
        sendError(400, 'Invalid bbox format. Coordinates must be numbers');
    }
}
$coords = array_map('floatval', $parts);

[$minLat, $minLon, $maxLat, $maxLon] = $coords;

// Validate coordinate ranges
if ($minLat < -90 || $maxLat > 90 || $minLon < -180 || $maxLon > 180 || $minLat > $maxLat || $minLon > $maxLon) {
    sendError(400, 'Invalid coordinate ranges');
}

try {
    $tafService = new TafService(
        $config['host'],
        $config['dbname'],
        $config['username'],
        $config['password']
    );

    $tafs = $tafService->getTafsInBoundingBox($minLon, $minLat, $maxLon, $maxLat);

    // Create response XML. The cached rows hold the complete serialized
    // <TAF> elements, which are spliced in verbatim; this keeps nested
    // elements (forecast periods etc.) that a SimpleXML copy would drop.
    $output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<response version="1.3" '
        . 'xsi:noNamespaceSchemaLocation="https://aviationweather.gov/data/schema/taf1_3.xsd" '
        . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<data_source name="tafs"/>'
        . '<request type="retrieve"/>'
        . '<errors/>'
        . '<warnings/>'
        . '<time_taken_ms>0</time_taken_ms>'
        . '<data num_results="' . count($tafs) . '">'
        . implode('', $tafs)
        . '</data></response>' . "\n";

    // Output XML
    header('Content-Type: application/xml');
    echo $output;

} catch (TafUnavailableException $e) {
    error_log("taf.php: " . $e->getMessage());
    sendError(503, 'TAF data temporarily unavailable');
} catch (Throwable $e) {
    // Log the details; do not expose internals to the client
    error_log("taf.php: " . $e->getMessage());
    sendError(500, 'Internal server error');
}
