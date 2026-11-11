<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

try {
    $pdo = require __DIR__ . '/config/database.php';
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        http_response_code(400);
        exit('Invalid download request.');
    }
    $statement = $pdo->prepare('SELECT file_path, original_file_name FROM archives WHERE archive_id = ?');
    $statement->execute([$id]);
    $record = $statement->fetch();
    if (!$record) {
        http_response_code(404);
        exit('Archive file not found.');
    }
    $base = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'files');
    $path = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $record['file_path']));
    if ($base === false || $path === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('The stored file is unavailable.');
    }
    $name = basename(str_replace('\\', '/', $record['original_file_name']));
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit(h($exception->getMessage()));
} catch (Throwable $exception) {
    error_log('Archive download failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The file could not be downloaded.');
}
