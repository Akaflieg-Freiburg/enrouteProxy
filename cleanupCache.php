<?php

// Database connection details
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
    // Connect to the database
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Delete expired cache entries
    $stmt = $pdo->prepare("DELETE FROM notam_cache WHERE expiration < NOW()");
    $result = $stmt->execute();

    $deletedCount = $stmt->rowCount();

    // Log the result
    $logMessage = date('Y-m-d H:i:s') . " - Deleted $deletedCount expired cache entries\n";
    file_put_contents('cache_cleanup.log', $logMessage, FILE_APPEND);

    echo "Cache cleanup completed. Deleted $deletedCount expired entries.";

} catch (\PDOException $e) {
    $errorMessage = date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    file_put_contents('cache_cleanup_error.log', $errorMessage, FILE_APPEND);
    echo "An error occurred during cache cleanup. Check the error log for details.";
}
