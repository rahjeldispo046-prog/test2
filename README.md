# Archive Desk — PHP/MySQL Archive Web Application

A complete capstone archive application built for **PHP 8+ and MySQL/MariaDB on XAMPP**. It supports file upload and download, archive metadata CRUD, category filtering, keyword search, and the required **Option 2: Custom Sorting & Search Logic** data-structures implementation.

## Run with XAMPP

1. Copy/clone this repository into `C:\xampp\htdocs\test2` (Windows) or `htdocs/test2` in your XAMPP installation.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`, choose **Import**, select this repository's `schema.sql`, and run the import. The script creates the `archive_app` database and its tables and starter categories.
4. Check `config/database.php`. The defaults match a typical XAMPP install: host `127.0.0.1`, database `archive_app`, user `root`, and an empty password.
5. Ensure PHP's `fileinfo` extension is enabled and `uploads/` is writable by Apache. Open `http://localhost/test2/`.

The PHP upload limit must accommodate 20 MB files. If necessary, adjust `upload_max_filesize` and `post_max_size` in `C:\xampp\php\php.ini` (for example, 20M and 22M), then restart Apache. The application rejects files larger than 20 MB.

## Features

- Add an archive record with a document title, category, and file. Accepted formats: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, CSV, JPG, and PNG.
- Download files using their archive record; stored names are generated randomly, and the original filename is retained for downloads.
- View, edit, and delete records. Editing metadata can leave the existing file in place or replace it. Deleting a record also removes its stored file.
- Search titles, categories, and original filenames; filter by category; sort by title, category, oldest first, or file size.
- PDO prepared statements, output escaping, CSRF tokens, upload MIME/size checks, and path validation for file downloads.

## Option 2: DSA design and complexity

The application retrieves the archive records and category names from MySQL, then invokes its PHP routines before rendering the result. `filter_archives()` performs keyword/category filtering in memory rather than adding search/sort clauses to SQL. The keyword scan is **O(n·m)** worst case, where `n` is the record count and `m` is the compared text/query length (O(n) for a fixed-size query); category-only filtering is O(n). `merge_sort_archives()` is a stable recursive merge sort: **O(n log n)** time and **O(n)** auxiliary space. Its comparator supports title, category, date, and size; a record ID tie-breaker keeps results deterministic. The live request flow calls both routines, so the DSA is integrated into the application rather than being unused sample code.

## Database and code layout

- `schema.sql` — database, normalized `categories` and `archives` tables, key/foreign-key constraint, and starter categories.
- `config/database.php` — PDO connection (XAMPP defaults; environment variables may override settings).
- `includes/functions.php` — escaping, CSRF, upload/path helpers, in-memory filtering, and merge sort.
- `index.php` — archive listing, search/sort/filter, and create/read/update/delete user interface.
- `download.php` — validated file download endpoint.
- `uploads/files/` — private-by-convention storage location for uploaded documents.

## Notes

Create category rows in phpMyAdmin if additional categories are needed. Do not expose the `uploads/` directory through a separate web server or move uploaded files outside it. Production deployments should configure a dedicated least-privilege MySQL account, HTTPS, backup/retention policies, and server-level upload limits.
