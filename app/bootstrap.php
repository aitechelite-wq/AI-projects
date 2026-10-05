<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
define('DATA_DIR', rtrim(getenv('CAFE_DATA_DIR') ?: APP_ROOT . '/data', '/\\'));
foreach ([DATA_DIR, DATA_DIR . '/uploads', DATA_DIR . '/sessions'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the local data directory.');
    }
}
date_default_timezone_set('Asia/Karachi');

class ApiError extends RuntimeException {
    public function __construct(string $message, public int $httpStatus = 400) { parent::__construct($message); }
}
function fail(string $message, int $status = 400): never { throw new ApiError($message, $status); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int $minor): string { return number_format($minor / 100, 2); }
function now(): string { return date('Y-m-d\TH:i:sP'); }
function db(): PDO {
    static $connection;
    if ($connection instanceof PDO) return $connection;
    $connection = new PDO('sqlite:' . DATA_DIR . '/cafe.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $connection->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $connection->exec(file_get_contents(__DIR__ . '/schema.sql'));
    $stockColumns=array_column($connection->query('PRAGMA table_info(stock_items)')->fetchAll(),'name');
    if (!in_array('value_minor',$stockColumns,true)) {
        $connection->exec('ALTER TABLE stock_items ADD COLUMN value_minor INTEGER NOT NULL DEFAULT 0');
        $connection->exec("UPDATE stock_items SET value_minor=COALESCE((SELECT SUM(CASE WHEN type IN ('use','waste') THEN -cost_total ELSE cost_total END) FROM stock_movements WHERE stock_item_id=stock_items.id),0)");
    }
    $orderColumns=array_column($connection->query('PRAGMA table_info(orders)')->fetchAll(),'name');
    if (!in_array('fulfillment_status',$orderColumns,true)) {
        $connection->exec("ALTER TABLE orders ADD COLUMN fulfillment_status TEXT NOT NULL DEFAULT 'pending'");
        $connection->exec("UPDATE orders SET fulfillment_status=CASE WHEN status='ready' THEN 'ready' WHEN EXISTS(SELECT 1 FROM kitchen_tickets WHERE order_id=orders.id) THEN 'kitchen' ELSE 'pending' END");
    }
    $paymentColumns=array_column($connection->query('PRAGMA table_info(payments)')->fetchAll(),'name');
    if (!in_array('invoice_refund',$paymentColumns,true)) {
        $connection->exec('ALTER TABLE payments ADD COLUMN invoice_refund INTEGER NOT NULL DEFAULT 0');
        $connection->exec('ALTER TABLE payments ADD COLUMN refunded_tax INTEGER NOT NULL DEFAULT 0');
        $connection->exec("UPDATE payments SET invoice_refund=1,refunded_tax=COALESCE((SELECT CASE WHEN total>0 THEN ROUND(1.0*payments.amount*tax/total) ELSE 0 END FROM orders WHERE id=payments.order_id),0) WHERE type='refund' AND EXISTS(SELECT 1 FROM orders WHERE id=payments.order_id AND paid_at IS NOT NULL AND payments.created_at>=paid_at)");
    }
    return $connection;
}
function query(string $sql, array $params = []): PDOStatement { $statement = db()->prepare($sql); $statement->execute($params); return $statement; }
function setting(string $name, mixed $default = null): mixed {
    $raw = query('SELECT value FROM settings WHERE key=?', [$name])->fetchColumn();
    return $raw === false ? $default : json_decode($raw, true);
}
function put_setting(string $name, mixed $value): void { query('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$name, json_encode($value, JSON_THROW_ON_ERROR)]); }
function public_settings(): array {
    $defaults = ['cafe_name'=>'Ember Café','address'=>'','phone'=>'','footer'=>'Thank you for visiting!','currency'=>'PKR','timezone'=>'Asia/Karachi','accent'=>'#147d73','logo_path'=>'','tax_enabled'=>true,'default_tax_rate'=>0,'receipt_width'=>'80'];
    foreach ($defaults as $name => $value) $defaults[$name] = setting($name, $value);
    return $defaults;
}
function transaction(callable $callback): mixed {
    static $depth = 0;
    if ($depth > 0) return $callback();
    db()->exec('BEGIN IMMEDIATE'); $depth++;
    try { $result = $callback(); db()->exec('COMMIT'); return $result; }
    catch (Throwable $error) { db()->exec('ROLLBACK'); throw $error; }
    finally { $depth--; }
}
function int_money(mixed $value, string $label = 'Amount'): int {
    if ((!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) || (float)$value > 1000000000000 || (float)$value < 0) fail($label . ' must be a non-negative whole number of paisa.');
    return (int)$value;
}
function qty_int(mixed $value): int {
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 1000000) fail('Quantity must be between 0 and 1,000,000.');
    $scaled = (float)$value * 1000;
    if (abs($scaled - round($scaled)) > 0.00001) fail('Quantities support up to three decimal places.');
    return (int)round($scaled);
}
function qty_float(int $value): float { return $value / 1000; }
function text_input(mixed $value, string $label, int $max = 200, bool $required = false): string {
    if (!is_string($value) && !is_numeric($value)) fail($label . ' must be text.');
    $text = trim((string)$value);
    if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) fail($label . ' contains unsupported control characters.');
    if (strlen($text) > $max || ($required && $text === '')) fail($label . ' is required and must be at most ' . $max . ' characters.');
    return $text;
}
function input_id(mixed $value, string $label = 'ID'): int {
    if (!is_numeric($value) || (int)$value <= 0 || (float)$value !== (float)(int)$value) fail($label . ' is invalid.');
    return (int)$value;
}
function bool_input(mixed $value): bool {
    if (!in_array($value, [true,false,0,1,'0','1'], true)) fail('Expected a true or false value.');
    return (bool)$value;
}
function tax_input(mixed $value): float {
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100) fail('Tax rate must be between 0 and 100 percent.');
    return round((float)$value, 3);
}
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $user = query('SELECT id,name,username,role,active FROM users WHERE id=? AND active=1', [(int)$_SESSION['user_id']])->fetch();
    return $user ?: null;
}
function require_user(): array { return current_user() ?: fail('Please sign in to continue.', 401); }
function require_role(array $roles): array { $user = require_user(); if (!in_array($user['role'], $roles, true)) fail('You do not have permission for this action.', 403); return $user; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) fail('The security token expired. Reload the page and try again.', 403);
}
function audit(string $action, string $entity, ?int $entityId = null, array $details = []): void {
    query('INSERT INTO audit_log(user_id,action,entity,entity_id,details,created_at) VALUES(?,?,?,?,?,?)', [current_user()['id'] ?? null,$action,$entity,$entityId,json_encode($details, JSON_THROW_ON_ERROR),now()]);
}
function shift_id(): ?int { $id = query('SELECT id FROM shifts WHERE closed_at IS NULL LIMIT 1')->fetchColumn(); return $id === false ? null : (int)$id; }

$workerRoute = isset($_GET['api']) && in_array($_GET['api'], ['print/claim','print/ack'], true);
if ($workerRoute) $_SESSION = [];
if (!$workerRoute && session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path(DATA_DIR . '/sessions');
    session_name('cafe_pos');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','httponly'=>true,'samesite'=>'Strict']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
db();
$zone = setting('timezone', 'Asia/Karachi');
if (is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true)) date_default_timezone_set($zone);
if (PHP_SAPI !== 'cli') {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}
require_once __DIR__ . '/domain.php';
if (is_file(__DIR__ . '/printing.php')) { require_once __DIR__ . '/printing.php'; if (function_exists('printing_schema')) printing_schema(); }
if (is_file(__DIR__ . '/operations.php')) require_once __DIR__ . '/operations.php';
