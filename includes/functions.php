<?php
/** Shared helpers and the Option 2 in-memory DSA implementation. */

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(400);
        exit('Invalid form token. Return to the application and try again.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirect(string $url = 'index.php'): void
{
    header('Location: ' . $url);
    exit;
}

function app_root(): string
{
    return dirname(__DIR__);
}

/**
 * Validate and store an uploaded document. Returns its relative path and
 * original client filename. MIME is checked from file content, not the name.
 */
function save_uploaded_file(array $upload): array
{
    if (!isset($upload['error']) || is_array($upload['error'])) {
        throw new RuntimeException('The uploaded file could not be processed.');
    }
    if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a file to upload.');
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error ' . (int) $upload['error'] . ').');
    }
    if (($upload['size'] ?? 0) < 1 || $upload['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('Files must be between 1 byte and 20 MB.');
    }

    $allowed = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? 'document')));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset($allowed[$extension])) {
        throw new RuntimeException('Allowed formats: PDF, Word, Excel, PowerPoint, TXT, CSV, JPG, and PNG.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']);
    if ($mime === false || !in_array($mime, $allowed[$extension], true)) {
        throw new RuntimeException('The file content does not match its file extension.');
    }

    $directory = app_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'files';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The upload directory could not be created.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('The uploaded file could not be saved. Check folder permissions.');
    }

    return ['path' => 'uploads/files/' . $storedName, 'original_name' => $originalName];
}

function remove_stored_file(?string $relativePath): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/files/') !== 0) {
        return;
    }
    $base = realpath(app_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'files');
    $file = realpath(app_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($base !== false && $file !== false && strpos($file, $base . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
        @unlink($file);
    }
}

/** Case-insensitive substring match that also works without mbstring. */
function contains_text(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

/** Compare archive records according to the requested sort key. */
function compare_archives(array $left, array $right, string $sort): int
{
    switch ($sort) {
        case 'category':
            $result = strnatcasecmp($left['category_name'], $right['category_name']);
            break;
        case 'oldest':
            $result = strcmp($left['created_at'], $right['created_at']);
            break;
        case 'size':
            $result = ((int) $left['file_size_kb']) <=> ((int) $right['file_size_kb']);
            break;
        default:
            $result = strnatcasecmp($left['title'], $right['title']);
            break;
    }
    return $result !== 0 ? $result : ((int) $left['archive_id'] <=> (int) $right['archive_id']);
}

/**
 * Option 2: stable, recursive merge sort over the records already fetched from
 * MySQL. Time complexity O(n log n); auxiliary space complexity O(n).
 */
function merge_sort_archives(array $records, string $sort): array
{
    $count = count($records);
    if ($count < 2) {
        return $records;
    }
    $middle = intdiv($count, 2);
    $left = merge_sort_archives(array_slice($records, 0, $middle), $sort);
    $right = merge_sort_archives(array_slice($records, $middle), $sort);
    $merged = [];
    $i = 0;
    $j = 0;
    while ($i < count($left) && $j < count($right)) {
        if (compare_archives($left[$i], $right[$j], $sort) <= 0) {
            $merged[] = $left[$i++];
        } else {
            $merged[] = $right[$j++];
        }
    }
    while ($i < count($left)) {
        $merged[] = $left[$i++];
    }
    while ($j < count($right)) {
        $merged[] = $right[$j++];
    }
    return $merged;
}

/**
 * Option 2 in-memory filtering. For n records and total compared text length
 * m, a scan is O(n*m) in the worst case (or O(n) for a fixed-size query).
 */
function filter_archives(array $records, string $keyword, string $categoryId): array
{
    return array_values(array_filter($records, static function (array $record) use ($keyword, $categoryId): bool {
        if ($categoryId !== '' && (string) $record['category_id'] !== $categoryId) {
            return false;
        }
        if ($keyword === '') {
            return true;
        }
        return contains_text($record['title'], $keyword)
            || contains_text($record['category_name'], $keyword)
            || contains_text($record['original_file_name'], $keyword);
    }));
}
