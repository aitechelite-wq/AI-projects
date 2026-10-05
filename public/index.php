<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (isset($_GET['api'])) {
    require dirname(__DIR__) . '/app/api.php';
    exit;
}
if (isset($_GET['asset'])) {
    $name = $_GET['asset'];
    if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+\.(png|jpe?g|webp)$/i', $name)) {
        http_response_code(404);
        exit;
    }
    $root = realpath(DATA_DIR . '/uploads');
    $path = $root !== false ? realpath($root . DIRECTORY_SEPARATOR . $name) : false;
    if ($path === false || $root === false || !is_file($path) || dirname($path) !== $root) {
        http_response_code(404);
        exit;
    }
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    header('Content-Type: ' . $types[$extension]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
if (isset($_GET['theme'])) {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache');
    $accent = (string) setting('accent', '#0f766e');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#0f766e';
    echo ':root{--accent:' . $accent . ';}';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#252921">
    <meta name="description" content="Your café, from first order to closing time. Offline point of sale, kitchen tickets and stock reconciliation.">
    <title><?= e((string) setting('cafe_name', 'Ember Café')) ?> · POS</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="?theme=1" id="brand-theme">
    <script src="assets/settings.js" defer></script>
    <script src="assets/app.js" defer></script>
</head>
<body>
    <div id="app"><div class="loading-screen"><div class="brand-mark">E</div><p>Opening your café…</p></div></div>
    <div id="modal-root"></div>
    <div id="toast-root" role="status" aria-live="polite" aria-atomic="true"></div>
    <noscript><p class="noscript-message">Enable JavaScript in this browser to use the POS. All scripts run locally on your laptop.</p></noscript>
</body>
</html>
