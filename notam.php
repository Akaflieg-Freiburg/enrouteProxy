<?php

// Proxy for the NOTAM Management Service (NMS) API of the US Federal Aviation
// Administration (FAA), used by Enroute Flight Navigation.
//
// Originally written for Enroute Flight Navigation by Markus Sachs
// (ms@squawk-vfr.de) in January 2024. Data source: https://www.faa.gov/
//
// Usage: notam.php?locationLongitude=<deg>&locationLatitude=<deg>&locationRadius=<NM>
//
// Parameters, response format, error codes, caching and configuration are
// documented in README.md; the database schema is in notam.sql.

error_reporting(E_ERROR);
ini_set('display_errors', 0);

// After this time, a cache entry is renewed on the next request.
const CACHE_FRESH_SECONDS = 3600;
// Entries are kept this long as a fallback ("stale") for when the FAA fails.
const CACHE_KEEP_SECONDS = 86400;
// After an FAA failure, no new FAA request is made for the same query for
// this long (negative cache), so that a swarm of clients does not add to the
// load on the FAA.
const NEGATIVE_CACHE_SECONDS = 120;
// Upper limit for the number of FAA pages per request (protection against
// endless loops).
const MAX_PAGES = 50;
// Timeout for HTTP requests to the FAA (per page).
const HTTP_TIMEOUT_SECONDS = 15;
// How long to wait for the lock while another process is querying the FAA
// for the same query.
const LOCK_WAIT_SECONDS = 20;
// Maximum radius; the FAA answers larger values with HTTP 400.
const MAX_RADIUS_NM = 100;
// The cached bearer token is renewed this long before it expires.
const TOKEN_RENEWAL_BUFFER_SECONDS = 60;
// Timeout for the HTTP request to the FAA auth endpoint.
const TOKEN_HTTP_TIMEOUT_SECONDS = 10;
// Token lifetime assumed if the auth endpoint does not send expires_in.
const DEFAULT_TOKEN_LIFETIME_SECONDS = 1799;

/** Error from the FAA API (network, HTTP status, malformed response). */
class FaaApiException extends RuntimeException
{
    public int $statusCode;

    public function __construct(string $message, int $statusCode = 0)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}

/** FAA unreachable and no cache entry available → HTTP 503. */
class ServiceUnavailableException extends RuntimeException
{
}

/**
 * Extrahiert den HTTP-Statuscode aus den Response-Headern von
 * file_get_contents().
 *
 * @param string[] $responseHeaders
 */
function extractHttpStatusCode(array $responseHeaders): int
{
    if (empty($responseHeaders[0])) {
        return 0;
    }

    preg_match('/HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $matches);

    return isset($matches[1]) ? (int)$matches[1] : 0;
}

/** Opens the connection to the cache database. */
function getDbConnection(): PDO
{
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
    return new PDO($dsn, $user, $pass, $options);
}

/**
 * Acquires a MySQL named lock, waiting up to $timeout seconds. Lock names are
 * limited to 64 characters.
 */
function acquireLock(PDO $pdo, string $name, int $timeout): bool
{
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, ?)");
    $stmt->execute([$name, $timeout]);
    return $stmt->fetchColumn() == 1;
}

/** Releases a lock acquired with acquireLock(). */
function releaseLock(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
    $stmt->execute([$name]);
}

/** Deletes old cache entries and aggregates old daily metrics into monthly ones. */
function performMaintenance(PDO $pdo): void
{
    // Delete cache entries that are too old to serve even as stale data.
    // (expiration = insert time + CACHE_FRESH_SECONDS)
    $stmt = $pdo->prepare("DELETE FROM notam_cache
                           WHERE cache_key LIKE 'notam\_%'
                           AND expiration < DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([CACHE_KEEP_SECONDS - CACHE_FRESH_SECONDS]);

    // Delete expired negative-cache entries
    $stmt = $pdo->prepare("DELETE FROM notam_cache
                           WHERE cache_key LIKE 'nfail\_%' AND expiration < NOW()");
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

/**
 * Counts a cache event ($metric: 'hit', 'miss' or 'stale') and occasionally
 * runs performMaintenance(). Metrics are non-essential: failures are logged
 * but never prevent a response.
 */
function logCacheMetrics(PDO $pdo, string $metric): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO cache_metrics (metric, count, date)
                               VALUES (?, 1, CURDATE())
                               ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->execute([$metric]);

        // Perform maintenance operations occasionally (e.g., 1% of the time)
        if (rand(1, 100) == 1) {
            performMaintenance($pdo);
        }
    } catch (Throwable $e) {
        error_log("notam.php: metrics/maintenance failed: " . $e->getMessage());
    }
}

/**
 * Reads a cache entry. Returns null if there is none, otherwise
 * ['value' => string, 'fresh' => bool].
 */
function readCache(PDO $pdo, string $cacheKey): ?array
{
    $stmt = $pdo->prepare("SELECT cache_value, (expiration > NOW()) AS fresh
                           FROM notam_cache WHERE cache_key = ?");
    $stmt->execute([$cacheKey]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return ['value' => $row['cache_value'], 'fresh' => (bool)$row['fresh']];
}

/** Inserts or replaces a cache entry that stays fresh for $ttlSeconds. */
function writeCache(PDO $pdo, string $cacheKey, string $value, int $ttlSeconds): void
{
    $stmt = $pdo->prepare("INSERT INTO notam_cache (cache_key, cache_value, expiration) 
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                           ON DUPLICATE KEY UPDATE 
                           cache_value = VALUES(cache_value), 
                           expiration = VALUES(expiration)");
    $stmt->execute([$cacheKey, $value, $ttlSeconds]);
}

/**
 * Returns ['body' => string, 'status' => 'hit'|'miss'|'stale'].
 *
 * Flow: a fresh cache entry is a hit. Otherwise, if an FAA request for this
 * query failed recently (negative cache), the old entry is served as stale
 * without asking the FAA, or ServiceUnavailableException (503) is thrown if
 * there is none. Otherwise the FAA is queried under a lock (one process per
 * query); if that fails, the failure is remembered for NEGATIVE_CACHE_SECONDS
 * and handled as above.
 */
function getCachedOrFreshData(PDO $pdo, string $url, array $opts): array
{
    $hash     = md5($url);
    $dataKey  = 'notam_' . $hash;   // format kept from earlier versions, so existing rows stay valid
    $failKey  = 'nfail_' . $hash;
    $lockName = 'notamlock_' . $hash;

    $cached = readCache($pdo, $dataKey);
    if ($cached !== null && $cached['fresh']) {
        logCacheMetrics($pdo, 'hit');
        return ['body' => $cached['value'], 'status' => 'hit'];
    }

    $failure = readCache($pdo, $failKey);
    if ($failure !== null && $failure['fresh']) {
        return serveStaleOrFail($pdo, $cached, 'FAA API recently failed (negative cache)');
    }

    if (!acquireLock($pdo, $lockName, LOCK_WAIT_SECONDS)) {
        // Another process has been querying the FAA for longer than we are
        // willing to wait.
        return serveStaleOrFail($pdo, $cached, 'Timeout waiting for concurrent FAA request');
    }

    try {
        // Another process may have filled the cache while we waited.
        $cached = readCache($pdo, $dataKey);
        if ($cached !== null && $cached['fresh']) {
            logCacheMetrics($pdo, 'hit');
            return ['body' => $cached['value'], 'status' => 'hit'];
        }

        try {
            $response = getNotamsFromFaa($url, $opts);
        } catch (FaaApiException $e) {
            error_log("notam.php: " . $e->getMessage() . " [$url]");
            writeCache($pdo, $failKey, (string)$e->statusCode, NEGATIVE_CACHE_SECONDS);
            return serveStaleOrFail($pdo, $cached, $e->getMessage());
        }

        writeCache($pdo, $dataKey, $response, CACHE_FRESH_SECONDS);
        logCacheMetrics($pdo, 'miss');
        return ['body' => $response, 'status' => 'miss'];
    } finally {
        releaseLock($pdo, $lockName);
    }
}

/**
 * Serves the cache entry as stale data, or throws ServiceUnavailableException
 * if there is none.
 */
function serveStaleOrFail(PDO $pdo, ?array $cached, string $reason): array
{
    if ($cached !== null) {
        logCacheMetrics($pdo, 'stale');
        return ['body' => $cached['value'], 'status' => 'stale'];
    }
    throw new ServiceUnavailableException($reason);
}

/**
 * Returns a bearer token for the NMS API: the cached one if it is still
 * valid, otherwise a new one from the FAA.
 */
function getToken(PDO $pdo): string
{
    $row = fetchTokenFromCache($pdo);
    if ($row !== null && isTokenStillValid($row['expires_at'])) {
        return $row['access_token'];
    }

    // Only one process renews the token; the others wait and re-read it.
    $locked = acquireLock($pdo, 'nms_token', LOCK_WAIT_SECONDS);
    try {
        if ($locked) {
            $row = fetchTokenFromCache($pdo);
            if ($row !== null && isTokenStillValid($row['expires_at'])) {
                return $row['access_token'];
            }
        }
        return getTokenFromFaa($pdo);
    } finally {
        if ($locked) {
            releaseLock($pdo, 'nms_token');
        }
    }
}

function fetchTokenFromCache(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT access_token, expires_at FROM nms_token_cache WHERE id = 1 LIMIT 1"
    );
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/** $expiresAt is a UTC timestamp as stored in nms_token_cache. */
function isTokenStillValid(string $expiresAt): bool
{
    $utc = new DateTimeZone('UTC');

    $threshold   = new DateTimeImmutable('now', $utc);
    $threshold   = $threshold->modify('+' . TOKEN_RENEWAL_BUFFER_SECONDS . ' seconds');
    $tokenExpiry = new DateTimeImmutable($expiresAt, $utc);

    return $threshold < $tokenExpiry;
}

/**
 * Fetches a new bearer token from the FAA auth endpoint (OAuth client
 * credentials) and stores it in nms_token_cache.
 */
function getTokenFromFaa(PDO $pdo): string
{
    $authUrl      = getenv('NMS_AUTH_URL');
    $clientId     = getenv('NMS_CLIENT_ID');
    $clientSecret = getenv('NMS_CLIENT_SECRET');

    if (!$authUrl || !$clientId || !$clientSecret) {
        throw new RuntimeException(
            'NMS environment variables (NMS_AUTH_URL, NMS_CLIENT_ID, NMS_CLIENT_SECRET) are missing.'
        );
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
            ],
            'content'       => 'grant_type=client_credentials',
            'timeout'       => TOKEN_HTTP_TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($authUrl, false, $context);

    if ($raw === false) {
        throw new FaaApiException('Network error while fetching the bearer token.');
    }

    $statusCode = extractHttpStatusCode($http_response_header ?? []);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new FaaApiException(
            "Auth endpoint answered with HTTP {$statusCode}.", $statusCode
        );
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($data['access_token'])) {
        throw new FaaApiException(
            'Invalid auth response: no access_token received.'
        );
    }

    $expiresIn = (int)($data['expires_in'] ?? DEFAULT_TOKEN_LIFETIME_SECONDS);
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify("+{$expiresIn} seconds")
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO nms_token_cache (id, access_token, expires_at, updated_at)
         VALUES (1, :token, :expires_at, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
             access_token = VALUES(access_token),
             expires_at   = VALUES(expires_at),
             updated_at   = UTC_TIMESTAMP()"
    );
    $stmt->execute([
        ':token'      => $data['access_token'],
        ':expires_at' => $expiresAt,
    ]);
    return $data['access_token'];
}

/**
 * Fetches all pages of the FAA response and merges them into one JSON string.
 * Throws FaaApiException on any error.
 */
function getNotamsFromFaa(string $url, array $opts): string
{
    $allItems = [];
    $context = stream_context_create($opts);

    for ($pageNum = 1; $pageNum <= MAX_PAGES; $pageNum++) {
        $paginatedUrl = $url . '&pageNum=' . $pageNum;

        $response = @file_get_contents($paginatedUrl, false, $context);
        $statusCode = extractHttpStatusCode($http_response_header ?? []);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            throw new FaaApiException(
                "Failed to get data from FAA API. Status code: $statusCode", $statusCode
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new FaaApiException("Failed to decode JSON response from FAA API (page $pageNum)");
        }

        if (isset($data['data']['geojson']) && is_array($data['data']['geojson'])) {
            $allItems = array_merge($allItems, $data['data']['geojson']);
        }

        // Stop when this was the last page (or pagination info is missing).
        // The local counter is used on purpose: relying on the pageNum echoed
        // by the FAA could loop forever if the FAA ignores the parameter.
        if (!isset($data['totalPages']) || $pageNum >= (int)$data['totalPages']) {
            break;
        }
        if ($pageNum == MAX_PAGES) {
            error_log("notam.php: page limit of " . MAX_PAGES . " reached for $url; result truncated");
        }
    }

    // Construct the final response
    $itemCount = count($allItems);
    $finalResponse = [
        'pageSize' => $itemCount,
        'pageNum' => 1, // Since we're combining all pages, set the current page to 1
        'totalCount' => $itemCount,
        'totalPages' => 1, // Since all data is combined into one response, totalPages is 1
        'items' => $allItems
    ];
    $json = json_encode($finalResponse, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new FaaApiException("Failed to encode merged response: " . json_last_error_msg());
    }
    return $json;
}

function isValidLatitude(string $input): bool
{
    return isValidDegree($input) && $input >= -90 && $input <= 90;
}

function isValidLongitude(string $input): bool
{
    return isValidDegree($input) && $input >= -180 && $input <= 180;
}

function isValidDegree(string $input): bool
{
    // Plain decimal number, e.g. "7", "-7.25"; no exponent notation
    return preg_match('/^(-?\d+(\.\d+)?)$/', $input) === 1;
}

function isValidRadius(string $input): bool
{
    return is_numeric($input) && $input > 0 && $input <= MAX_RADIUS_NM && intval($input) == $input;
}

/** Maps an exception to the HTTP status and the message sent to the client. */
function errorResponse(Throwable $e): array
{
    if ($e instanceof InvalidArgumentException) {
        return [400, $e->getMessage()];
    }
    if ($e instanceof ServiceUnavailableException) {
        return [503, 'FAA NOTAM service temporarily unavailable'];
    }
    // Anything else (DB, configuration, token, ...): do not expose internals.
    return [500, 'Internal server error'];
}

try {
    // Input validation and sanitization. Raw strings are validated by regex,
    // so that the values can be passed to the FAA verbatim.
    $longitude = $_GET['locationLongitude'] ?? null;
    $latitude  = $_GET['locationLatitude'] ?? null;
    $radius    = $_GET['locationRadius'] ?? null;

    if (!is_string($longitude) || !is_string($latitude) || !is_string($radius)
        || !isValidLongitude($longitude) || !isValidLatitude($latitude)) {
        throw new InvalidArgumentException("Invalid input parameters: locationLongitude, locationLatitude and locationRadius are required");
    }
    if (!isValidRadius($radius)) {
        throw new InvalidArgumentException("Invalid locationRadius: must be an integer between 1 and " . MAX_RADIUS_NM . " (nautical miles)");
    }

    $apiBase = getenv('NMS_API_BASE');
    if (!$apiBase) {
        throw new RuntimeException('NMS environment variable NMS_API_BASE is missing.');
    }

    // Build request
    $url = $apiBase . '/notams?'
    . 'longitude=' . $longitude
    . '&latitude=' . $latitude
    . '&radius='   . (int)$radius;

    $pdo = getDbConnection();
    $token = getToken($pdo);
    $opts = ['http' => [
        'header' => [
            "Authorization: Bearer $token",
            "nmsResponseFormat: geojson"
        ],
        'timeout'       => HTTP_TIMEOUT_SECONDS,
        'ignore_errors' => true,
    ]];

    $result = getCachedOrFreshData($pdo, $url, $opts);

    header('Content-Type: application/json');
    header('X-Cache-Status: ' . $result['status']);
    echo $result['body'];

} catch (Throwable $e) {
    error_log("notam.php: " . get_class($e) . ": " . $e->getMessage());
    [$status, $message] = errorResponse($e);
    http_response_code($status);
    if ($status == 503) {
        header('Retry-After: ' . NEGATIVE_CACHE_SECONDS);
    }
    header('Content-Type: application/json');
    echo json_encode(["error" => $message]);
}
