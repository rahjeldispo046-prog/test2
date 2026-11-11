<?php
/**
 * Database connection for the XAMPP/MySQL development setup.
 * Override these defaults with environment variables for other deployments.
 */
$host = getenv('ARCHIVE_DB_HOST') ?: '127.0.0.1';
$port = getenv('ARCHIVE_DB_PORT') ?: '3306';
$name = getenv('ARCHIVE_DB_NAME') ?: 'archive_app';
$user = getenv('ARCHIVE_DB_USER') ?: 'root';
$password = getenv('ARCHIVE_DB_PASSWORD') ?: '';

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    return new PDO($dsn, $user, $password, $options);
} catch (PDOException $exception) {
    // Do not expose connection details or credentials to visitors.
    error_log('Archive database connection failed: ' . $exception->getMessage());
    throw new RuntimeException('Could not connect to MySQL. Check config/database.php and import schema.sql.', 0, $exception);
}
