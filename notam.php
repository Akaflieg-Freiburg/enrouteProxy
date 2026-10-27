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
// Older entries are never served, even if maintenance has not deleted them yet.
const CACHE_KEEP_SECONDS = 86400;
// After an FAA failure, no new FAA request is made for the same query for
// this long (negative cache), so that a swarm of clients does not add to the
// load on the FAA.
const NEGATIVE_CACHE_SECONDS = 120;
// After an HTTP 429 (rate limit, shared by all clients of our account) or a
// token failure, no FAA request is made for *any* query for the time given in
// the FAA's Retry-After header (default NEGATIVE_CACHE_SECONDS, capped here).
const GLOBAL_COOLDOWN_MAX_SECONDS = 600;
// Upper limit for the number of FAA pages per request (protection against
// endless loops). The current NMS API answers everything on one page.
const MAX_PAGES = 50;
// Timeout for HTTP requests to the FAA (per page).
const HTTP_TIMEOUT_SECONDS = 15;
// Time budget for fetching all pages of one query. Keeps a slow FAA from
// holding the query lock (and a PHP worker) for minutes.
const FETCH_DEADLINE_SECONDS = 30;
// How long to wait for the lock while another process is querying the FAA
// for the same query (or renewing the token), and for a free FAA slot.
const LOCK_WAIT_SECONDS = 20;
// Maximum number of FAA requests in flight at the same time from this server.
// The FAA answers further parallel requests with HTTP 429 (observed limit: 2),
// which would lock everyone out for NEGATIVE_CACHE_SECONDS. Requests beyond
// this number queue for a slot instead.
const FAA_MAX_CONCURRENT = 2;
// Pause between two attempts to grab a free FAA slot.
const FAA_SLOT_POLL_MICROSECONDS = 200000;
// Maximum radius; the FAA answers larger values with HTTP 400.
const MAX_RADIUS_NM = 100;
// The cached bearer token is renewed this long before it expires.
const TOKEN_RENEWAL_BUFFER_SECONDS = 60;
// Timeout for the HTTP request to the FAA auth endpoint.
const TOKEN_HTTP_TIMEOUT_SECONDS = 10;
// Token lifetime assumed if the auth endpoint does not send expires_in.
const DEFAULT_TOKEN_LIFETIME_SECONDS = 1799;
// Cache key of the global cooldown row (see GLOBAL_COOLDOWN_MAX_SECONDS).
const GLOBAL_FAIL_KEY = 'nfail_global';

/** Error from the FAA API (network, HTTP status, malformed response). */
class FaaApiException extends RuntimeException
{
    public int $statusCode;
    /** Seconds from the FAA's Retry-After header, if any. */
    public ?int $retryAfter = null;
    /** True if the failure concerns all queries (rate limit, token), not just this one. */
    public bool $affectsAllQueries = false;

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
 * Extracts the HTTP status code from the response headers of
 * file_get_contents(). When redirects were followed, the headers of all
 * responses are listed in order; the status of the final response is used.
 *
 * @param string[] $responseHeaders
 */
function extractHttpStatusCode(array $responseHeaders): int
{
    $status = 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
            $status = (int)$matches[1];
        }
    }
    return $status;
}

/**
 * Extracts the delay in seconds from a Retry-After header of the final
 * response, or null if there is none (the HTTP-date form is not supported).
 *
 * @param string[] $responseHeaders
 */
function extractRetryAfter(array $responseHeaders): ?int
{
    $retryAfter = null;
    foreach ($responseHeaders as $line) {
        if (preg_match('/^HTTP\//', $line)) {
            $retryAfter = null; // start of the next response in a redirect chain
        } elseif (preg_match('/^Retry-After:\s*(\d+)\s*$/i', $line, $matches)) {
            $retryAfter = (int)$matches[1];
        }
    }
    return $retryAfter;
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

/**
 * Takes one of the FAA_MAX_CONCURRENT slots for talking to the FAA (a
 * semaphore made of MySQL named locks 'notam_faa_slot_<n>'), waiting up to
 * $timeout seconds. Returns the lock name to release, or null on timeout.
 */
function acquireFaaSlot(PDO $pdo, int $timeout): ?string
{
    $deadline = microtime(true) + $timeout;
    while (true) {
        for ($i = 0; $i < FAA_MAX_CONCURRENT; $i++) {
            $name = 'notam_faa_slot_' . $i;
            if (acquireLock($pdo, $name, 0)) {
                return $name;
            }
        }
        if (microtime(true) >= $deadline) {
            return null;
        }
        usleep(FAA_SLOT_POLL_MICROSECONDS);
    }
}

/**
 * Deletes old cache entries and aggregates old daily metrics into monthly
 * ones. Only one process runs maintenance at a time; the roll-up is one
 * transaction, so a failure cannot leave daily rows that have already been
 * added to the monthly totals.
 */
function performMaintenance(PDO $pdo): void
{
    if (!acquireLock($pdo, 'notam_maintenance', 0)) {
        return; // another process is on it
    }
    try {
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

        // Aggregate metrics older than 30 days, then delete them, atomically.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO cache_metrics_monthly (year, month, metric, count)
                SELECT YEAR(date), MONTH(date), metric, SUM(count)
                FROM cache_metrics
                WHERE date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY YEAR(date), MONTH(date), metric
                ON DUPLICATE KEY UPDATE count = count + VALUES(count)
            ");
            $stmt->execute();

            $stmt = $pdo->prepare("DELETE FROM cache_metrics WHERE date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $stmt->execute();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } finally {
        releaseLock($pdo, 'notam_maintenance');
    }
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
 * Reads a cache entry. Returns null if there is none or if it is too old to
 * be served even as stale data, otherwise ['value' => string, 'fresh' => bool].
 */
function readCache(PDO $pdo, string $cacheKey): ?array
{
    $stmt = $pdo->prepare("SELECT cache_value, (expiration > NOW()) AS fresh
                           FROM notam_cache
                           WHERE cache_key = ?
                           AND expiration > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$cacheKey, CACHE_KEEP_SECONDS - CACHE_FRESH_SECONDS]);
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
 * Returns the reason why no FAA request should be made right now (global
 * cooldown or negative cache for this query), or null if the FAA may be asked.
 */
function faaBlockedReason(PDO $pdo, string $failKey): ?string
{
    $global = readCache($pdo, GLOBAL_FAIL_KEY);
    if ($global !== null && $global['fresh']) {
        return 'FAA rate limit or token failure recently (global cooldown)';
    }
    $failure = readCache($pdo, $failKey);
    if ($failure !== null && $failure['fresh']) {
        return 'FAA API recently failed (negative cache)';
    }
    return null;
}

/**
 * Remembers an FAA failure: always for this query (NEGATIVE_CACHE_SECONDS),
 * and for all queries if the failure was a rate limit or a token problem
 * (Retry-After if sent, else NEGATIVE_CACHE_SECONDS, capped).
 */
function recordFaaFailure(PDO $pdo, string $failKey, FaaApiException $e): void
{
    try {
        writeCache($pdo, $failKey, (string)$e->statusCode, NEGATIVE_CACHE_SECONDS);
        if ($e->affectsAllQueries) {
            $ttl = $e->retryAfter ?? NEGATIVE_CACHE_SECONDS;
            $ttl = min(max($ttl, 1), GLOBAL_COOLDOWN_MAX_SECONDS);
            writeCache($pdo, GLOBAL_FAIL_KEY, (string)$e->statusCode, $ttl);
        }
    } catch (Throwable $dbError) {
        // The negative cache is a protection, not a requirement for answering.
        error_log("notam.php: could not record FAA failure: " . $dbError->getMessage());
    }
}

/**
 * Returns ['body' => string, 'status' => 'hit'|'miss'|'stale'].
 *
 * Flow: a fresh cache entry is a hit. Otherwise, if the FAA is blocked (global
 * cooldown after a rate limit or token failure, or a recent failure of this
 * query), the old entry is served as stale without asking the FAA, or
 * ServiceUnavailableException (503) is thrown if there is none. Otherwise the
 * FAA is queried under a lock (one process per query) and within one of the
 * FAA_MAX_CONCURRENT slots shared by all queries; if that fails, the failure
 * is remembered and handled as above.
 */
function getCachedOrFreshData(PDO $pdo, string $url): array
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

    $blocked = faaBlockedReason($pdo, $failKey);
    if ($blocked !== null) {
        return serveStaleOrFail($pdo, $cached, $blocked);
    }

    if (!acquireLock($pdo, $lockName, LOCK_WAIT_SECONDS)) {
        // Another process has been querying the FAA for longer than we are
        // willing to wait.
        return serveStaleOrFail($pdo, $cached, 'Timeout waiting for concurrent FAA request');
    }

    try {
        // Another process may have filled the cache, or hit a failure, while
        // we waited.
        $cached = readCache($pdo, $dataKey);
        if ($cached !== null && $cached['fresh']) {
            logCacheMetrics($pdo, 'hit');
            return ['body' => $cached['value'], 'status' => 'hit'];
        }
        $blocked = faaBlockedReason($pdo, $failKey);
        if ($blocked !== null) {
            return serveStaleOrFail($pdo, $cached, $blocked);
        }

        // Limit the number of parallel FAA requests from this server.
        $slot = acquireFaaSlot($pdo, LOCK_WAIT_SECONDS);
        if ($slot === null) {
            return serveStaleOrFail($pdo, $cached, 'Timeout waiting for a free FAA slot');
        }
        try {
            $response = fetchNotams($pdo, $url);
        } catch (FaaApiException $e) {
            error_log("notam.php: " . $e->getMessage() . " [$url]");
            recordFaaFailure($pdo, $failKey, $e);
            return serveStaleOrFail($pdo, $cached, $e->getMessage());
        } finally {
            releaseLock($pdo, $slot);
        }

        // A good FAA answer is returned even if the cache cannot store it.
        try {
            writeCache($pdo, $dataKey, $response, CACHE_FRESH_SECONDS);
            logCacheMetrics($pdo, 'miss');
        } catch (Throwable $dbError) {
            error_log("notam.php: could not cache FAA response: " . $dbError->getMessage());
        }
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
 * Gets a bearer token and fetches the NOTAMs. If the FAA rejects the token
 * (HTTP 401) although it has not expired, the token is invalidated, renewed
 * and the fetch retried once. Throws FaaApiException on any FAA error.
 */
function fetchNotams(PDO $pdo, string $url): string
{
    $token = getToken($pdo);
    try {
        return getNotamsFromFaa($url, $token);
    } catch (FaaApiException $e) {
        if ($e->statusCode !== 401) {
            throw $e;
        }
        error_log("notam.php: FAA rejected the bearer token (HTTP 401); renewing it once");
        invalidateToken($pdo, $token);
        $token = getToken($pdo);
        return getNotamsFromFaa($url, $token);
    }
}

/**
 * Returns a bearer token for the NMS API: the cached one if it is still
 * valid, otherwise a new one from the FAA. Only one process renews the token;
 * the others wait for it and re-read the cache. Throws FaaApiException if the
 * auth endpoint fails or the wait times out, RuntimeException if the
 * configuration is missing.
 */
function getToken(PDO $pdo): string
{
    $row = fetchTokenFromCache($pdo);
    if ($row !== null && isTokenStillValid($row['expires_at'])) {
        return $row['access_token'];
    }

    if (!acquireLock($pdo, 'nms_token', LOCK_WAIT_SECONDS)) {
        // The renewing process is taking too long. Use its result if it has
        // one by now; never start a second, uncoordinated renewal.
        $row = fetchTokenFromCache($pdo);
        if ($row !== null && isTokenStillValid($row['expires_at'])) {
            return $row['access_token'];
        }
        $e = new FaaApiException('Timeout waiting for the bearer token to be renewed.');
        $e->affectsAllQueries = true;
        throw $e;
    }
    try {
        $row = fetchTokenFromCache($pdo);
        if ($row !== null && isTokenStillValid($row['expires_at'])) {
            return $row['access_token'];
        }
        return getTokenFromFaa($pdo);
    } finally {
        releaseLock($pdo, 'nms_token');
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

/**
 * Removes the cached token, but only if it is still the given one, so that a
 * rejection of an old token cannot discard a token renewed in the meantime.
 */
function invalidateToken(PDO $pdo, string $token): void
{
    $stmt = $pdo->prepare("DELETE FROM nms_token_cache WHERE id = 1 AND access_token = ?");
    $stmt->execute([$token]);
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
    $responseHeaders = $http_response_header ?? [];

    if ($raw === false) {
        $e = new FaaApiException('Network error while fetching the bearer token.');
        $e->affectsAllQueries = true;
        throw $e;
    }

    $statusCode = extractHttpStatusCode($responseHeaders);

    if ($statusCode < 200 || $statusCode >= 300) {
        $e = new FaaApiException("Auth endpoint answered with HTTP {$statusCode}.", $statusCode);
        $e->retryAfter = extractRetryAfter($responseHeaders);
        $e->affectsAllQueries = true;
        throw $e;
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($data['access_token'])) {
        $e = new FaaApiException('Invalid auth response: no access_token received.');
        $e->affectsAllQueries = true;
        throw $e;
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
 * Checks one page of an FAA response and returns its list of geojson
 * features. The NMS envelope is {"status": "Success", "data": {"geojson":
 * [...]}}; an empty result has no "geojson" key. Anything else is an error:
 * the FAA reports failures as {"status": "Failure", "message": "..."}, and a
 * missing envelope means the response is not what we expect.
 */
function extractPageItems(array $data, int $pageNum): array
{
    $status = $data['status'] ?? null;
    if (!is_string($status)) {
        throw new FaaApiException("Malformed FAA response (page $pageNum): no status field");
    }
    if ($status !== 'Success') {
        $message = is_string($data['message'] ?? null) ? $data['message'] : '';
        throw new FaaApiException("FAA reported '$status' (page $pageNum): $message");
    }
    if (!array_key_exists('data', $data) || !is_array($data['data'])) {
        throw new FaaApiException("Malformed FAA response (page $pageNum): no data field");
    }
    $items = $data['data']['geojson'] ?? [];
    if (!is_array($items) || ($items !== [] && !isset($items[0]))) {
        throw new FaaApiException("Malformed FAA response (page $pageNum): geojson is not a feature list");
    }
    return $items;
}

/**
 * Fetches all pages of the FAA response and merges them into one JSON string.
 * Throws FaaApiException on any error, including incomplete results.
 */
function getNotamsFromFaa(string $url, string $token): string
{
    $allItems = [];
    $start = microtime(true);

    for ($pageNum = 1; $pageNum <= MAX_PAGES; $pageNum++) {
        $remaining = FETCH_DEADLINE_SECONDS - (microtime(true) - $start);
        if ($remaining <= 0) {
            throw new FaaApiException(
                "Time budget of " . FETCH_DEADLINE_SECONDS . " s exhausted before page $pageNum"
            );
        }
        $context = stream_context_create(['http' => [
            'header' => [
                "Authorization: Bearer $token",
                "nmsResponseFormat: geojson",
            ],
            'timeout'       => min(HTTP_TIMEOUT_SECONDS, $remaining),
            'ignore_errors' => true,
        ]]);

        $paginatedUrl = $url . '&pageNum=' . $pageNum;
        $response = @file_get_contents($paginatedUrl, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = extractHttpStatusCode($responseHeaders);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            $e = new FaaApiException(
                "Failed to get data from FAA API. Status code: $statusCode", $statusCode
            );
            $e->retryAfter = extractRetryAfter($responseHeaders);
            $e->affectsAllQueries = ($statusCode === 429);
            throw $e;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new FaaApiException("Failed to decode JSON response from FAA API (page $pageNum)");
        }

        $allItems = array_merge($allItems, extractPageItems($data, $pageNum));

        // Stop when this was the last page (or pagination info is missing).
        // The local counter is used on purpose: relying on the pageNum echoed
        // by the FAA could loop forever if the FAA ignores the parameter.
        if (!isset($data['totalPages']) || $pageNum >= (int)$data['totalPages']) {
            break;
        }
        if ($pageNum == MAX_PAGES) {
            throw new FaaApiException(
                "Page limit of " . MAX_PAGES . " reached, FAA reports " . (int)$data['totalPages'] . " pages"
            );
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
    // Plain decimal number, e.g. "7", "-7.25"; no exponent notation. The D
    // modifier keeps $ from matching before a trailing newline.
    return preg_match('/^-?\d+(\.\d+)?$/D', $input) === 1;
}

function isValidRadius(string $input): bool
{
    // Plain integer, e.g. "9"; no sign, decimals, exponent or whitespace.
    return preg_match('/^\d+$/D', $input) === 1
        && (int)$input >= 1 && (int)$input <= MAX_RADIUS_NM;
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
    // Anything else (DB, configuration, ...): do not expose internals.
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
    $url = rtrim($apiBase, '/') . '/notams?'
    . 'longitude=' . $longitude
    . '&latitude=' . $latitude
    . '&radius='   . (int)$radius;

    $pdo = getDbConnection();
    $result = getCachedOrFreshData($pdo, $url);

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
