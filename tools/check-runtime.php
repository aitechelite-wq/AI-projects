<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "PHP 8.2 or newer is required.\n");
    exit(1);
}
$missing = array_filter(['pdo_sqlite', 'fileinfo', 'ctype'], fn($name) => !extension_loaded($name));
if ($missing) {
    fwrite(STDERR, 'Enable these PHP extensions in php.ini: ' . implode(', ', $missing) . "\n");
    exit(1);
}
echo 'PHP ' . PHP_VERSION . ": SQLite and file uploads ready.\n";
