<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

try {
    $pdo = require __DIR__ . '/config/database.php';
} catch (RuntimeException $exception) {
    http_response_code(500);
    $databaseError = $exception->getMessage();
}

if (isset($pdo) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = filter_var($_POST['archive_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $title = trim((string) ($_POST['title'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($title === '' || strlen($title) > 200 || !$categoryId) {
                throw new RuntimeException('Enter a title (up to 200 characters) and choose a category.');
            }
            $checkCategory = $pdo->prepare('SELECT category_id FROM categories WHERE category_id = ?');
            $checkCategory->execute([$categoryId]);
            if (!$checkCategory->fetch()) {
                throw new RuntimeException('Select a valid category.');
            }

            $newFile = null;
            $current = null;
            if ($id) {
                $find = $pdo->prepare('SELECT * FROM archives WHERE archive_id = ?');
                $find->execute([$id]);
                $current = $find->fetch();
                if (!$current) {
                    throw new RuntimeException('That archive record no longer exists.');
                }
            }
            if (isset($_FILES['archive_file']) && ($_FILES['archive_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newFile = save_uploaded_file($_FILES['archive_file']);
            } elseif (!$id) {
                throw new RuntimeException('Choose a file to upload.');
            }

            try {
                if ($id) {
                    if ($newFile) {
                        $statement = $pdo->prepare('UPDATE archives SET title = ?, file_path = ?, original_file_name = ?, file_size_kb = ?, category_id = ? WHERE archive_id = ?');
                        $statement->execute([$title, $newFile['path'], $newFile['original_name'], (int) ceil($_FILES['archive_file']['size'] / 1024), $categoryId, $id]);
                    } else {
                        $statement = $pdo->prepare('UPDATE archives SET title = ?, category_id = ? WHERE archive_id = ?');
                        $statement->execute([$title, $categoryId, $id]);
                    }
                    if ($newFile) {
                        remove_stored_file($current['file_path']);
                    }
                    set_flash('success', 'Archive record updated.');
                } else {
                    $statement = $pdo->prepare('INSERT INTO archives (title, file_path, original_file_name, file_size_kb, category_id) VALUES (?, ?, ?, ?, ?)');
                    $statement->execute([$title, $newFile['path'], $newFile['original_name'], (int) ceil($_FILES['archive_file']['size'] / 1024), $categoryId]);
                    set_flash('success', 'File uploaded and archive record created.');
                }
            } catch (Throwable $exception) {
                if ($newFile) {
                    remove_stored_file($newFile['path']);
                }
                throw $exception;
            }
            redirect();
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['archive_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                throw new RuntimeException('Invalid archive record.');
            }
            $find = $pdo->prepare('SELECT file_path FROM archives WHERE archive_id = ?');
            $find->execute([$id]);
            $archive = $find->fetch();
            if (!$archive) {
                throw new RuntimeException('That archive record no longer exists.');
            }
            $delete = $pdo->prepare('DELETE FROM archives WHERE archive_id = ?');
            $delete->execute([$id]);
            remove_stored_file($archive['file_path']);
            set_flash('success', 'Archive record and stored file deleted.');
            redirect();
        }
        throw new RuntimeException('Unknown request.');
    } catch (Throwable $exception) {
        error_log('Archive request failed: ' . $exception->getMessage());
        set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The request could not be completed. Check the database and try again.');
        $editingId = filter_var($_POST['archive_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        redirect($editingId ? 'index.php?edit=' . $editingId : 'index.php');
    }
}

$categories = [];
$records = [];
$editing = null;
$search = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'title');
if (!in_array($sort, ['title', 'category', 'oldest', 'size'], true)) {
    $sort = 'title';
}
if (isset($pdo)) {
    $categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll();
    $records = $pdo->query('SELECT a.archive_id, a.title, a.file_path, a.original_file_name, a.file_size_kb, a.category_id, a.created_at, c.category_name FROM archives a INNER JOIN categories c ON c.category_id = a.category_id')->fetchAll();
    $records = filter_archives($records, $search, $categoryFilter);
    $records = merge_sort_archives($records, $sort);
    if (isset($_GET['edit'])) {
        $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($editId) {
            $statement = $pdo->prepare('SELECT * FROM archives WHERE archive_id = ?');
            $statement->execute([$editId]);
            $editing = $statement->fetch() ?: null;
        }
    }
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archive Desk</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">A</span><span>Archive<span class="brand-light">Desk</span></span></a><span class="topbar-note">Documents, organized.</span></header>
<main class="page">
    <section class="hero"><div><p class="eyebrow">DIGITAL RECORDS</p><h1>Your archive, in order.</h1><p class="subtitle">Store, find, and manage your important files in one place.</p></div><div class="hero-icon" aria-hidden="true">▤</div></section>

    <?php if (!empty($databaseError)): ?>
        <div class="notice error"><strong>Database setup needed.</strong> <?= h($databaseError) ?> Import <code>schema.sql</code> in phpMyAdmin, then reload this page.</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>

    <div class="layout">
        <section class="panel upload-panel" id="form">
            <div class="section-heading"><div><p class="eyebrow">ARCHIVE ENTRY</p><h2><?= $editing ? 'Edit record' : 'Add a document' ?></h2></div><span class="heading-icon">＋</span></div>
            <form method="post" enctype="multipart/form-data" action="index.php<?= $editing ? '?edit=' . (int) $editing['archive_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save">
                <?php if ($editing): ?><input type="hidden" name="archive_id" value="<?= (int) $editing['archive_id'] ?>"><?php endif; ?>
                <label for="title">Document title</label>
                <input id="title" name="title" type="text" maxlength="200" placeholder="e.g. Annual Report 2025" required value="<?= h($editing['title'] ?? '') ?>">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id" required><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['category_id'] ?>" <?= isset($editing['category_id']) && (int) $editing['category_id'] === (int) $category['category_id'] ? 'selected' : '' ?>><?= h($category['category_name']) ?></option><?php endforeach; ?></select>
                <label for="archive_file">File <?= $editing ? '<span class="optional">(optional; leave blank to keep current file)</span>' : '' ?></label>
                <input id="archive_file" name="archive_file" type="file" <?= $editing ? '' : 'required' ?> accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png">
                <p class="field-hint">Max 20 MB · PDF, Office, TXT, CSV, JPG, PNG</p>
                <?php if ($editing): ?><p class="current-file">Current file: <?= h($editing['original_file_name']) ?></p><?php endif; ?>
                <button class="button primary full" type="submit"><?= $editing ? 'Save changes' : 'Upload document' ?></button>
                <?php if ($editing): ?><a class="cancel-link" href="index.php">Cancel editing</a><?php endif; ?>
            </form>
            <div class="privacy-note"><span>▣</span><span>Files are stored locally in this application and served only through the download handler.</span></div>
        </section>

        <section class="panel archive-panel">
            <div class="section-heading list-heading"><div><p class="eyebrow">YOUR COLLECTION</p><h2>Archived documents <span class="count"><?= count($records) ?></span></h2></div></div>
            <form class="filters" method="get" action="index.php">
                <div class="search-box"><span aria-hidden="true">⌕</span><input type="search" name="q" placeholder="Search title, file, category..." value="<?= h($search) ?>" aria-label="Search archive"></div>
                <select name="category" aria-label="Filter by category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['category_id'] ?>" <?= $categoryFilter === (string) $category['category_id'] ? 'selected' : '' ?>><?= h($category['category_name']) ?></option><?php endforeach; ?></select>
                <select name="sort" aria-label="Sort records"><option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Sort: title</option><option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>Sort: category</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Sort: oldest</option><option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>Sort: file size</option></select>
                <button class="button filter-button" type="submit">Apply</button>
            </form>
            <?php if (!$records): ?>
                <div class="empty-state"><div class="empty-icon">▱</div><h3>No documents found</h3><p><?= $search !== '' || $categoryFilter !== '' ? 'Try another search or category filter.' : 'Your archive is ready. Upload the first document to get started.' ?></p></div>
            <?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>DOCUMENT</th><th>CATEGORY</th><th>FILE SIZE</th><th>DATE ADDED</th><th class="actions-heading">ACTIONS</th></tr></thead><tbody>
                <?php foreach ($records as $record): ?><tr>
                    <td><div class="document-cell"><span class="file-icon <?= h(strtolower(pathinfo($record['original_file_name'], PATHINFO_EXTENSION))) ?>"><?= h(strtoupper(substr(pathinfo($record['original_file_name'], PATHINFO_EXTENSION), 0, 3))) ?></span><div><strong><?= h($record['title']) ?></strong><small><?= h($record['original_file_name']) ?></small></div></div></td>
                    <td><span class="category-pill"><?= h($record['category_name']) ?></span></td><td><?= number_format((int) $record['file_size_kb']) ?> KB</td><td><?= h(date('M j, Y', strtotime($record['created_at']))) ?></td>
                    <td><div class="actions"><a class="action-link download" href="download.php?id=<?= (int) $record['archive_id'] ?>" title="Download <?= h($record['original_file_name']) ?>">↓</a><a class="action-link edit" href="index.php?edit=<?= (int) $record['archive_id'] ?>#form" title="Edit record">✎</a><form method="post" onsubmit="return confirm('Delete this archive record and its stored file?');"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="archive_id" value="<?= (int) $record['archive_id'] ?>"><button class="action-link delete" type="submit" title="Delete record">×</button></form></div></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
    <p class="implementation-note">Custom in-memory merge sort and search (Option 2) · Data stored in MySQL</p>
</main>
<footer>Archive Desk <span>·</span> Capstone Archive Web Application</footer>
</body>
</html>
