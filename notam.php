<?php
header("Content-Type: application/json");

// Dieses Skript liest NOTAMs über eine für maschinelle Anfragen 
// vorgesehene Schnittstelle der Federal Aviation Administration (FAA)
// der USA ein und gibt sie aus.
//
// Kontakt: Markus Sachs, ms@squawk-vfr.de
// Geschrieben für Enroute Flight Navigation im Jan. 2024.
//
// Weitergehende Informationen über die Datenquelle:
// https://www.faa.gov/
//
// Aufruf: [Server/htdocs]/notams.php?locationLongitude=a&locationLatitude=b&radius=c
// mit 
// a = Längengrad (Punkt als Dezimalkomma) des Zentrums der Suche
// b = Breitengrad (Punkt als Dezimalkomma) des Zentrums der Suche
// c = Radius der Suche in [Einheit?]
// Das Anhängen von &pageSize=d mit d als gewünschter Zahl ist optional; 
// ohne Nennung wird 1000 als Default gesetzt.

// Function to get database connection
function getDbConnection() {
    $host = 'sql731.your-server.de';
    $db   = 'enroutecaches';
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// Function to clean up old cache entries and aggregate metrics
function performMaintenance($pdo) {
    // Delete expired cache entries
    $stmt = $pdo->prepare("DELETE FROM notam_cache WHERE expiration < NOW()");
    $stmt->execute();

    // Aggregate metrics older than 30 days
    $stmt = $pdo->prepare("
        INSERT INTO cache_metrics_monthly (year, month, metric, count)
        SELECT YEAR(date), MONTH(date), metric, SUM(count)
        FROM cache_metrics
        WHERE date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY YEAR(date), MONTH(date), metric
        ON DUPLICATE KEY UPDATE count = count + VALUES(count)
    ");
    $stmt->execute();

    // Delete aggregated daily metrics
    $stmt = $pdo->prepare("DELETE FROM cache_metrics WHERE date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
}

// Log cache metrics
function logCacheMetrics($pdo, $isHit) {
    $metric = $isHit ? 'hit' : 'miss';
    $stmt = $pdo->prepare("INSERT INTO cache_metrics (metric, count, date) 
                           VALUES (?, 1, CURDATE())
                           ON DUPLICATE KEY UPDATE count = count + 1");
    $stmt->execute([$metric]);

    // Perform maintenance operations occasionally (e.g., 1% of the time)
    if (rand(1, 100) == 1) {
        performMaintenance($pdo);
    }
}

// Function to get cached data or fetch from API
function getCachedOrFreshData($pdo, $url, $opts, $cacheTime = 3600) {
    // Generate a unique cache key based on the URL
    $cacheKey = 'notam_' . md5($url);

    // Try to fetch from cache
    $stmt = $pdo->prepare("SELECT cache_value FROM notam_cache WHERE cache_key = ? AND expiration > NOW()");
    $stmt->execute([$cacheKey]);
    $result = $stmt->fetch();

    if ($result) {
        // Data found in cache
        logCacheMetrics($pdo, true); // Cache hit
        return $result['cache_value'];
    }

    logCacheMetrics($pdo, false); // Cache miss

    // If not in cache or expired, fetch from API
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    // Get the status code
    $status_code = 0;
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $matches);
        $status_code = intval($matches[0]);
    }
    if ($response === false || $status_code < 200 || $status_code >= 300) {
        $error_message = "Failed to get data from FAA API. Status code: $status_code";
        if ($status_code >= 500) {
            error_log("Server error when accessing FAA API: $status_code");
        } elseif ($status_code == 404) {
            error_log("Resource not found on FAA API: $url");
        }
        throw new Exception($error_message);
    }

    // Store in cache
    $stmt = $pdo->prepare("INSERT INTO notam_cache (cache_key, cache_value, expiration) 
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                           ON DUPLICATE KEY UPDATE 
                           cache_value = VALUES(cache_value), 
                           expiration = VALUES(expiration)");
    $stmt->execute([$cacheKey, $response, $cacheTime]);

    return $response;
}

function isValidLatitude($input) {
    // Define the regular expression pattern for latitude and longitude
    $pattern = '/^(-?\d+(\.\d+)?)$/';

    // Use preg_match to check if the input string matches the pattern
    if (preg_match($pattern, $input) !== 1) {
        return false; // Format is invalid
    }

    // Validate latitude and longitude ranges
    if (!is_numeric($input) || $input < -90 || $input > 90) {
        return false; // Invalid latitude range
    }

    return true; // Input string is valid
}

function isValidLongitude($input) {
    // Define the regular expression pattern for latitude and longitude
    $pattern = '/^(-?\d+(\.\d+)?)$/';

    // Use preg_match to check if the input string matches the pattern
    if (preg_match($pattern, $input) !== 1) {
        return false; // Format is invalid
    }

    // Validate latitude and longitude ranges
    if (!is_numeric($input) || $input < -180 || $input > 180) {
        return false; // Invalid longitude range
    }

    return true; // Input string is valid
}

function isValidRadius($input) {
    // Check if input is numeric and within the range
    return is_numeric($input) && $input > 0 && $input < 500 && intval($input) == $input;
}

function isValidPageSize($input) {
    // Check if input is numeric and within the range
    return is_numeric($input) && $input > 0 && $input <= 1000 && intval($input) == $input;
}


try {
    $pdo = getDbConnection();

    // Input validation and sanitization
    $longitude = filter_input(INPUT_GET, 'locationLongitude', FILTER_VALIDATE_FLOAT);
    $latitude = filter_input(INPUT_GET, 'locationLatitude', FILTER_VALIDATE_FLOAT);
    $radius = filter_input(INPUT_GET, 'locationRadius', FILTER_VALIDATE_INT);
    $pageSize = filter_input(INPUT_GET, 'pageSize', FILTER_VALIDATE_INT) ?: 1000;

    if (!$longitude || !$latitude || !$radius || !isValidLongitude($longitude) || !isValidLatitude($latitude) || !isValidRadius($radius) || !isValidPageSize($pageSize)) {
      throw new InvalidArgumentException("Invalid input parameters");
    }


    // Build request
    $url = 'https://external-api.faa.gov/notamapi/v1/notams?'
    . 'locationLongitude=' . $longitude
    . '&locationLatitude=' . $latitude
    . '&locationRadius=' . $radius
    . '&pageSize=' . $pageSize;

    $FAA_KEY = getenv('FAA_KEY');
    $FAA_ID  = getenv('FAA_ID');

    $opts = array(
        'http' => array(
            'header' => "client_id: $FAA_ID\r\n" .
                        "client_secret: $FAA_KEY\r\n"
        )
    );

    // Get data (cached or fresh)
    $response = getCachedOrFreshData($pdo, $url, $opts);
    if ($response === false) {
        throw new Exception("Failed to get NOTAM data from FAA API");
    }

    // Return data
    header('Content-Type: application/json');
    echo $response;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    // Log the error
    error_log($e->getMessage());
}

?>