<?php

/**
 * NOTAM Proxy – NMS-API Edition
 *
 * Ruft NOTAM-Daten von der FAA NMS-API ab und gibt sie als JSON zurück.
 * Authentifizierung über OAuth2 Client Credentials (Bearer-Token),
 * Token-Verwaltung und NOTAM-Antworten werden in einer MySQL-Datenbank
 * gecacht.
 *
 * Aufruf:
 *   GET /notam.php?locationLongitude=<lon>&locationLatitude=<lat>&locationRadius=<nm>
 *
 * Optionale Parameter:
 *   pageSize=<1–1000>   (Default: 1000)
 *
 * Erforderliche Umgebungsvariablen (.env oder Server-Konfiguration):
 *   DB_HOST            Datenbankhost          (Default: localhost)
 *   DB_NAME            Datenbankname
 *   DB_USER            Datenbankbenutzer
 *   DB_PASS            Datenbankpasswort
 *   NMS_CLIENT_ID      KEY aus dem FAA-Onboarding-Excel
 *   NMS_CLIENT_SECRET  SECRET aus dem FAA-Onboarding-Excel
 *   NMS_AUTH_URL       z. B. https://api-nms.aim.faa.gov/v1/auth/token
 *   NMS_API_BASE       z. B. https://api-nms.aim.faa.gov/nmsapi/v1
 *
 * Kontakt: Markus Sachs, ms@squawk-vfr.de
 * Ursprünglich geschrieben für Enroute Flight Navigation, Jan. 2024.
 * Migriert auf NMS-API (OAuth2 / Bearer-Token), 2025.
 *
 * @license MIT
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Konfiguration & Konstanten
// ---------------------------------------------------------------------------

/** Sekunden, um die ein Token VOR seinem Ablauf erneuert wird. */
const TOKEN_RENEWAL_BUFFER_SECONDS = 60;

/** Standard-Cachezeit für NOTAM-Antworten in Sekunden. */
const NOTAM_CACHE_TTL_SECONDS = 3600;

/** Wahrscheinlichkeit (1/N) mit der Wartungsaufgaben ausgeführt werden. */
const MAINTENANCE_PROBABILITY = 100;

/** Tage, nach denen tägliche Metriken aggregiert werden. */
const METRICS_AGGREGATION_DAYS = 30;

/** Pflicht-Header für NOTAM-Requests an die NMS-API. */
const NMS_RESPONSE_FORMAT = 'AIXM';

// ---------------------------------------------------------------------------
// Datenbankverbindung
// ---------------------------------------------------------------------------

/**
 * Erstellt und gibt eine PDO-Datenbankverbindung zurück.
 *
 * @throws \RuntimeException Wenn die Verbindung fehlschlägt.
 */
function createDatabaseConnection(): PDO
{
    $host    = getenv('DB_HOST') ?: 'localhost';
    $dbName  = getenv('DB_NAME');
    $user    = getenv('DB_USER');
    $pass    = getenv('DB_PASS');

    if (!$dbName || !$user) {
        throw new \RuntimeException('Datenbankumgebungsvariablen (DB_NAME, DB_USER) fehlen.');
    }

    $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

// ---------------------------------------------------------------------------
// Token-Verwaltung (OAuth2 Client Credentials)
// ---------------------------------------------------------------------------

/**
 * Gibt einen gültigen Bearer-Token zurück.
 * Liest aus dem Datenbank-Cache; holt einen neuen Token, wenn der
 * gespeicherte abgelaufen ist oder nicht existiert.
 *
 * @throws \RuntimeException Bei Authentifizierungsfehlern.
 */
function getValidBearerToken(PDO $pdo): string
{
    $row = fetchTokenFromCache($pdo);

    if ($row !== null && isTokenStillValid($row['expires_at'])) {
        return $row['access_token'];
    }

    return fetchAndStoreNewToken($pdo);
}

/**
 * Liest den gecachten Token aus der Datenbank.
 *
 * @return array<string,string>|null
 */
function fetchTokenFromCache(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT access_token, expires_at FROM nms_token_cache WHERE id = 1 LIMIT 1"
    );
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Prüft ob ein Token (mit Erneuerungspuffer) noch gültig ist.
 */
function isTokenStillValid(string $expiresAt): bool
{
    $threshold = new \DateTimeImmutable(
        '+' . TOKEN_RENEWAL_BUFFER_SECONDS . ' seconds'
    );
    $tokenExpiry = new \DateTimeImmutable($expiresAt);

    return $threshold < $tokenExpiry;
}

/**
 * Holt einen neuen Bearer-Token von der NMS-Auth-API und speichert
 * ihn in der Datenbank.
 *
 * @throws \RuntimeException Bei Netzwerk- oder Authentifizierungsfehlern.
 */
function fetchAndStoreNewToken(PDO $pdo): string
{
    $authUrl      = getenv('NMS_AUTH_URL');
    $clientId     = getenv('NMS_CLIENT_ID');
    $clientSecret = getenv('NMS_CLIENT_SECRET');

    if (!$authUrl || !$clientId || !$clientSecret) {
        throw new \RuntimeException(
            'NMS-Umgebungsvariablen (NMS_AUTH_URL, NMS_CLIENT_ID, NMS_CLIENT_SECRET) fehlen.'
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
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($authUrl, false, $context);

    if ($raw === false) {
        throw new \RuntimeException('Netzwerkfehler beim Abrufen des Bearer-Tokens.');
    }

    $statusCode = extractHttpStatusCode($http_response_header ?? []);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new \RuntimeException(
            "Auth-Endpunkt antwortete mit HTTP {$statusCode}."
        );
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($data['access_token'])) {
        throw new \RuntimeException(
            'Ungültige Auth-Antwort: kein access_token erhalten.'
        );
    }

    $expiresIn = (int)($data['expires_in'] ?? 1799);
    $expiresAt = (new \DateTimeImmutable())
        ->modify("+{$expiresIn} seconds")
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO nms_token_cache (id, access_token, expires_at, updated_at)
         VALUES (1, :token, :expires_at, NOW())
         ON DUPLICATE KEY UPDATE
             access_token = VALUES(access_token),
             expires_at   = VALUES(expires_at),
             updated_at   = NOW()"
    );
    $stmt->execute([
        ':token'      => $data['access_token'],
        ':expires_at' => $expiresAt,
    ]);

    return $data['access_token'];
}

// ---------------------------------------------------------------------------
// NOTAM-Abruf von der NMS-API
// ---------------------------------------------------------------------------

/**
 * Baut die NMS-API-URL für eine Geospatialsuche auf.
 */
function buildNotamApiUrl(float $latitude, float $longitude, int $radius): string
{
    $base = rtrim(getenv('NMS_API_BASE') ?: '', '/');

    return $base . '/notams?' . http_build_query([
        'latitude'  => $latitude,
        'longitude' => $longitude,
        'radius'    => $radius,
    ]);
}

/**
 * Ruft NOTAMs seitenweise von der NMS-API ab und gibt alle Items
 * als kombinierte JSON-Zeichenkette zurück.
 *
 * @throws \RuntimeException Bei Netzwerk- oder API-Fehlern.
 */
function fetchNotamsFromNmsApi(string $baseUrl, string $bearerToken, int $pageSize): string
{
    $allItems = [];
    $pageNum  = 1;

    do {
        $url = $baseUrl . '&pageSize=' . $pageSize . '&pageNum=' . $pageNum;

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => [
                    'Authorization: Bearer ' . $bearerToken,
                    'nmsResponseFormat: ' . NMS_RESPONSE_FORMAT,
                ],
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            throw new \RuntimeException(
                "Netzwerkfehler beim Abruf von NOTAM-Seite {$pageNum}."
            );
        }

        $statusCode = extractHttpStatusCode($http_response_header ?? []);

        if ($statusCode === 401) {
            throw new \RuntimeException(
                'Authentifizierungsfehler (401) beim NOTAM-Abruf – Token möglicherweise abgelaufen.'
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                "NMS-API antwortete mit HTTP {$statusCode} für Seite {$pageNum}."
            );
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Ungültige JSON-Antwort von der NMS-API.'
            );
        }

        if (!empty($data['items']) && is_array($data['items'])) {
            $allItems = array_merge($allItems, $data['items']);
        }

        $currentPage = (int)($data['pageNum']    ?? 1);
        $totalPages  = (int)($data['totalPages'] ?? 1);
        $pageNum++;

    } while ($currentPage < $totalPages);

    return json_encode([
        'pageSize'   => $pageSize,
        'pageNum'    => 1,
        'totalCount' => count($allItems),
        'totalPages' => 1,
        'items'      => $allItems,
    ], JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Cache-Verwaltung für NOTAM-Antworten
// ---------------------------------------------------------------------------

/**
 * Gibt gecachte oder frische NOTAM-Daten zurück.
 * Schreibt frische Daten automatisch in den Cache.
 *
 * @throws \RuntimeException Bei API- oder Datenbankfehlern.
 */
function getNotamsWithCache(
    PDO    $pdo,
    string $cacheKey,
    string $apiUrl,
    string $bearerToken,
    int    $pageSize,
    int    $ttl = NOTAM_CACHE_TTL_SECONDS
): string {
    // Cache-Treffer prüfen
    $stmt = $pdo->prepare(
        "SELECT cache_value FROM notam_cache
         WHERE cache_key = :key AND expiration > NOW()
         LIMIT 1"
    );
    $stmt->execute([':key' => $cacheKey]);
    $cached = $stmt->fetchColumn();

    if ($cached !== false) {
        recordCacheMetric($pdo, true);
        return (string)$cached;
    }

    recordCacheMetric($pdo, false);

    // Frische Daten von der API holen
    $fresh = fetchNotamsFromNmsApi($apiUrl, $bearerToken, $pageSize);

    // In Cache schreiben
    $stmt = $pdo->prepare(
        "INSERT INTO notam_cache (cache_key, cache_value, expiration)
         VALUES (:key, :value, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
         ON DUPLICATE KEY UPDATE
             cache_value = VALUES(cache_value),
             expiration  = VALUES(expiration)"
    );
    $stmt->execute([
        ':key'   => $cacheKey,
        ':value' => $fresh,
        ':ttl'   => $ttl,
    ]);

    return $fresh;
}

/**
 * Erstellt einen deterministischen Cache-Schlüssel für eine Anfrage.
 */
function buildCacheKey(float $latitude, float $longitude, int $radius, int $pageSize): string
{
    return 'nms_notam_' . md5("{$latitude}:{$longitude}:{$radius}:{$pageSize}");
}

// ---------------------------------------------------------------------------
// Cache-Metriken
// ---------------------------------------------------------------------------

/**
 * Protokolliert einen Cache-Treffer oder -Fehltreffer.
 * Führt gelegentlich Wartungsaufgaben durch.
 */
function recordCacheMetric(PDO $pdo, bool $isHit): void
{
    $metric = $isHit ? 'hit' : 'miss';

    $stmt = $pdo->prepare(
        "INSERT INTO cache_metrics (metric, count, date)
         VALUES (:metric, 1, CURDATE())
         ON DUPLICATE KEY UPDATE count = count + 1"
    );
    $stmt->execute([':metric' => $metric]);

    if (random_int(1, MAINTENANCE_PROBABILITY) === 1) {
        runCacheMaintenance($pdo);
    }
}

/**
 * Bereinigt abgelaufene Cache-Einträge und aggregiert alte Metriken.
 */
function runCacheMaintenance(PDO $pdo): void
{
    // Abgelaufene NOTAM-Cacheeinträge löschen
    $pdo->exec("DELETE FROM notam_cache WHERE expiration < NOW()");

    // Tägliche Metriken aggregieren und löschen
    $pdo->exec(
        "INSERT INTO cache_metrics_monthly (year, month, metric, count)
         SELECT YEAR(date), MONTH(date), metric, SUM(count)
         FROM cache_metrics
         WHERE date < DATE_SUB(CURDATE(), INTERVAL " . METRICS_AGGREGATION_DAYS . " DAY)
         GROUP BY YEAR(date), MONTH(date), metric
         ON DUPLICATE KEY UPDATE count = count + VALUES(count)"
    );

    $pdo->exec(
        "DELETE FROM cache_metrics
         WHERE date < DATE_SUB(CURDATE(), INTERVAL " . METRICS_AGGREGATION_DAYS . " DAY)"
    );
}

// ---------------------------------------------------------------------------
// Eingabevalidierung
// ---------------------------------------------------------------------------

/**
 * Liest und validiert alle Query-Parameter.
 * Gibt ein assoziatives Array mit den validierten Werten zurück.
 *
 * @return array{latitude: float, longitude: float, radius: int, pageSize: int}
 * @throws \InvalidArgumentException Bei ungültigen Parametern.
 */
function parseAndValidateInput(): array
{
    $latitude  = filter_input(INPUT_GET, 'locationLatitude',  FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_GET, 'locationLongitude', FILTER_VALIDATE_FLOAT);
    $radius    = filter_input(INPUT_GET, 'locationRadius',    FILTER_VALIDATE_INT);
    $pageSize  = filter_input(INPUT_GET, 'pageSize',          FILTER_VALIDATE_INT);

    if ($latitude === false || $latitude === null) {
        throw new \InvalidArgumentException("'locationLatitude' fehlt oder ist ungültig.");
    }
    if ($longitude === false || $longitude === null) {
        throw new \InvalidArgumentException("'locationLongitude' fehlt oder ist ungültig.");
    }
    if ($radius === false || $radius === null) {
        throw new \InvalidArgumentException("'locationRadius' fehlt oder ist ungültig.");
    }

    if ($latitude < -90.0 || $latitude > 90.0) {
        throw new \InvalidArgumentException(
            "Breitengrad muss zwischen -90 und 90 liegen, erhalten: {$latitude}."
        );
    }
    if ($longitude < -180.0 || $longitude > 180.0) {
        throw new \InvalidArgumentException(
            "Längengrad muss zwischen -180 und 180 liegen, erhalten: {$longitude}."
        );
    }
    if ($radius < 1 || $radius > 499) {
        throw new \InvalidArgumentException(
            "Radius muss zwischen 1 und 499 liegen, erhalten: {$radius}."
        );
    }

    // pageSize ist optional
    if ($pageSize === false || $pageSize === null) {
        $pageSize = 1000;
    } elseif ($pageSize < 1 || $pageSize > 1000) {
        throw new \InvalidArgumentException(
            "pageSize muss zwischen 1 und 1000 liegen, erhalten: {$pageSize}."
        );
    }

    return [
        'latitude'  => (float)$latitude,
        'longitude' => (float)$longitude,
        'radius'    => (int)$radius,
        'pageSize'  => (int)$pageSize,
    ];
}

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------

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

/**
 * Sendet eine JSON-Fehlerantwort und beendet die Ausführung.
 *
 * @param int    $httpStatus  HTTP-Statuscode (z. B. 400, 500)
 * @param string $message     Fehlermeldung für den Client
 */
function sendErrorResponse(int $httpStatus, string $message): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Einstiegspunkt
// ---------------------------------------------------------------------------

// Sicherstellen dass alle Ausgaben UTF-8-kodiertes JSON sind
header('Content-Type: application/json; charset=utf-8');

// Eingabe validieren
try {
    $params = parseAndValidateInput();
} catch (\InvalidArgumentException $e) {
    error_log('[NOTAM] Eingabefehler: ' . $e->getMessage());
    sendErrorResponse(400, $e->getMessage());
}

// Datenbankverbindung herstellen
try {
    $pdo = createDatabaseConnection();
} catch (\RuntimeException $e) {
    error_log('[NOTAM] Datenbankfehler: ' . $e->getMessage());
    sendErrorResponse(503, 'Datenbankverbindung fehlgeschlagen.');
}

// Bearer-Token beschaffen
try {
    $bearerToken = getValidBearerToken($pdo);
} catch (\RuntimeException $e) {
    error_log('[NOTAM] Auth-Fehler: ' . $e->getMessage());
    sendErrorResponse(502, 'Authentifizierung gegenüber der NMS-API fehlgeschlagen.');
}

// NOTAM-Anfrage durchführen (mit Cache)
try {
    $apiUrl   = buildNotamApiUrl($params['latitude'], $params['longitude'], $params['radius']);
    $cacheKey = buildCacheKey(
        $params['latitude'],
        $params['longitude'],
        $params['radius'],
        $params['pageSize']
    );

    $response = getNotamsWithCache($pdo, $cacheKey, $apiUrl, $bearerToken, $params['pageSize']);

    echo $response;

} catch (\JsonException $e) {
    error_log('[NOTAM] JSON-Fehler: ' . $e->getMessage());
    sendErrorResponse(500, 'Fehler bei der Verarbeitung der API-Antwort.');
} catch (\RuntimeException $e) {
    error_log('[NOTAM] API-Fehler: ' . $e->getMessage());
    sendErrorResponse(502, 'NOTAM-Daten konnten nicht abgerufen werden.');
}
