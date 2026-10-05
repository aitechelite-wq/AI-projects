<?php
declare(strict_types=1);

/** Administrative and reconciliation endpoints. Amounts are integer paisa. */
function ops_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ops_row(string $sql, array $params = []): ?array
{
    $rows = ops_rows($sql, $params);
    return $rows[0] ?? null;
}

function ops_run(string $sql, array $params = []): void
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

function ops_text(mixed $value, string $label, int $max = 200, bool $required = false): string
{
    if (!is_string($value) && !is_numeric($value)) {
        fail($label . ' must be text.');
    }
    $value = trim((string)$value);
    if (strlen($value) > $max || ($required && $value === '')) {
        fail($label . ($value === '' ? ' is required.' : ' is too long.'));
    }
    if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        fail($label . ' contains unsupported control characters.');
    }
    return $value;
}

function ops_id(mixed $value, string $label = 'Record'): int
{
    if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int)$value < 1) {
        fail($label . ' ID is invalid.');
    }
    return (int)$value;
}

function ops_bool(mixed $value, string $label): bool
{
    if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
        fail($label . ' must be true or false.');
    }
    return (bool)$value;
}

function ops_date(mixed $value, string $label = 'Date'): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        fail($label . ' must use YYYY-MM-DD.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        fail($label . ' is invalid.');
    }
    return $value;
}

function ops_range(): array
{
    $today = substr(now(), 0, 10);
    $from = ops_date($_GET['from'] ?? $today, 'Start date');
    $to = ops_date($_GET['to'] ?? $today, 'End date');
    if ($from > $to) {
        fail('Start date must be on or before the end date.');
    }
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    if ($start->diff($end)->days > 3660) {
        fail('Choose a reporting period of no more than ten years.');
    }
    return [$from, $to, $end->modify('+1 day')->format('Y-m-d')];
}

function ops_account(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['active'] = (bool)$row['active'];
    return $row;
}

function ops_user(array $row): array
{
    unset($row['password_hash']);
    $row['id'] = (int)$row['id'];
    $row['active'] = (bool)$row['active'];
    return $row;
}

function ops_shift(array $row): array
{
    foreach (['id', 'opened_by', 'closed_by', 'opening_cash', 'counted_cash', 'expected_cash', 'discrepancy'] as $key) {
        if (isset($row[$key])) {
            $row[$key] = (int)$row[$key];
        }
    }
    if (!$row['closed_at']) {
        $cash = ops_row("SELECT COALESCE(SUM(CASE WHEN type='payment' THEN amount ELSE -amount END),0) AS amount FROM payments WHERE shift_id=? AND method='cash'", [$row['id']]);
        $spent = ops_row("SELECT COALESCE(SUM(amount),0) AS amount FROM expenses WHERE shift_id=? AND method='cash'", [$row['id']]);
        $row['cash_payments'] = (int)$cash['amount'];
        $row['cash_expenses'] = (int)$spent['amount'];
        $row['expected_cash'] = $row['opening_cash'] + $row['cash_payments'] - $row['cash_expenses'];
    }
    return $row;
}

function ops_dashboard(): array
{
    $today = substr(now(), 0, 10);
    $next = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $sales = ops_row('SELECT COALESCE(SUM(total),0) AS sales,COUNT(*) AS orders FROM orders WHERE paid_at>=? AND paid_at<?', [$today, $next]);
    $payments = ops_rows("SELECT method, SUM(CASE WHEN type='payment' THEN amount ELSE -amount END) AS total FROM payments WHERE created_at>=? AND created_at<? GROUP BY method", [$today, $next]);
    $cash = 0;
    foreach ($payments as &$payment) {
        $payment['total'] = (int)$payment['total'];
        if ($payment['method'] === 'cash') {
            $cash = $payment['total'];
        }
    }
    unset($payment);
    $alerts = ops_rows('SELECT id,name,unit,quantity_milli,min_stock_milli FROM stock_items WHERE active=1 AND quantity_milli<=min_stock_milli ORDER BY quantity_milli,name');
    foreach ($alerts as &$item) {
        $item['id'] = (int)$item['id'];
        $item['on_hand'] = qty_float((int)$item['quantity_milli']);
        $item['min_stock'] = qty_float((int)$item['min_stock_milli']);
        unset($item['quantity_milli'], $item['min_stock_milli']);
    }
    unset($item);
    $recent = ops_rows("SELECT o.*,COALESCE((SELECT SUM(CASE WHEN p.type='payment' THEN p.amount ELSE -p.amount END) FROM payments p WHERE p.order_id=o.id),0) AS paid FROM orders o ORDER BY o.id DESC LIMIT 8");
    foreach ($recent as &$order) {
        foreach (['id', 'total', 'subtotal', 'tax', 'discount', 'paid'] as $field) {
            $order[$field] = (int)$order[$field];
        }
        $order['balance'] = in_array($order['status'], ['paid', 'void'], true) ? 0 : max(0, $order['total'] - $order['paid']);
    }
    unset($order);
    $top = ops_rows('SELECT i.name,SUM(i.quantity_milli) AS quantity_milli,SUM(i.line_total) AS sales FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.paid_at>=? AND o.paid_at<? GROUP BY i.name ORDER BY sales DESC LIMIT 5', [$today, $next]);
    foreach ($top as &$item) {
        $item['quantity'] = qty_float((int)$item['quantity_milli']);
        $item['sales'] = (int)$item['sales'];
        unset($item['quantity_milli']);
    }
    unset($item);
    $open = ops_row("SELECT COUNT(*) AS count FROM orders WHERE status!='void' AND (status!='paid' OR fulfillment_status!='ready')");
    return [
        'sales_today' => (int)$sales['sales'], 'orders_today' => (int)$sales['orders'],
        'open_orders' => (int)$open['count'], 'low_stock' => count($alerts),
        'cash_today' => $cash, 'payment_totals' => $payments,
        'recent_orders' => $recent, 'top_items' => $top, 'stock_alerts' => $alerts,
    ];
}

function ops_reports(): array
{
    [$from, $to, $next] = ops_range();
    $summary = ops_row('SELECT COALESCE(SUM(total),0) AS sales,COALESCE(SUM(tax),0) AS tax,COUNT(*) AS orders FROM orders WHERE paid_at>=? AND paid_at<?', [$from, $next]);
    $refund = ops_row("SELECT COALESCE(SUM(amount),0) AS refunds,COALESCE(SUM(refunded_tax),0) AS tax_refunded FROM payments WHERE type='refund' AND invoice_refund=1 AND created_at>=? AND created_at<?", [$from, $next]);
    $allRefunds = ops_row("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE type='refund' AND created_at>=? AND created_at<?", [$from, $next]);
    $taxRefunded = (int)$refund['tax_refunded'];
    $totals = ops_rows("SELECT method,SUM(CASE WHEN type='payment' THEN amount ELSE -amount END) AS total FROM payments WHERE created_at>=? AND created_at<? GROUP BY method ORDER BY method", [$from, $next]);
    $collected = 0;
    foreach ($totals as &$payment) {
        $payment['total'] = (int)$payment['total'];
        $collected += $payment['total'];
    }
    unset($payment);
    $dailyMap = [];
    foreach (ops_rows('SELECT substr(paid_at,1,10) AS date,SUM(total) AS sales,COUNT(*) AS orders FROM orders WHERE paid_at>=? AND paid_at<? GROUP BY substr(paid_at,1,10)', [$from, $next]) as $row) {
        $dailyMap[$row['date']] = ['sales' => (int)$row['sales'], 'orders' => (int)$row['orders'], 'refunds' => 0];
    }
    foreach (ops_rows("SELECT substr(created_at,1,10) AS date,SUM(amount) AS refunds FROM payments WHERE type='refund' AND invoice_refund=1 AND created_at>=? AND created_at<? GROUP BY substr(created_at,1,10)", [$from, $next]) as $row) {
        $dailyMap[$row['date']] = array_merge(['sales' => 0, 'orders' => 0, 'refunds' => 0], $dailyMap[$row['date']] ?? [], ['refunds' => (int)$row['refunds']]);
    }
    $daily = [];
    for ($date = new DateTimeImmutable($from); $date->format('Y-m-d') <= $to; $date = $date->modify('+1 day')) {
        $key = $date->format('Y-m-d');
        $row = $dailyMap[$key] ?? ['sales' => 0, 'orders' => 0, 'refunds' => 0];
        $daily[] = ['date' => $key] + $row + ['net_sales' => $row['sales'] - $row['refunds']];
    }
    $stock = ops_rows("SELECT s.id,s.name,s.unit,
        COALESCE(SUM(CASE WHEN m.created_at<? THEN m.quantity_milli ELSE 0 END),0) AS opening_milli,
        COALESCE(SUM(CASE WHEN m.created_at>=? AND m.created_at<? AND m.type='receive' THEN m.quantity_milli ELSE 0 END),0) AS received_milli,
        COALESCE(SUM(CASE WHEN m.created_at>=? AND m.created_at<? AND m.type='use' THEN -m.quantity_milli ELSE 0 END),0) AS used_milli,
        COALESCE(SUM(CASE WHEN m.created_at>=? AND m.created_at<? AND m.type='waste' THEN -m.quantity_milli ELSE 0 END),0) AS waste_milli,
        COALESCE(SUM(CASE WHEN m.created_at>=? AND m.created_at<? AND m.type='count' THEN m.quantity_milli ELSE 0 END),0) AS adjustment_milli,
        COALESCE(SUM(CASE WHEN m.created_at<? THEN m.quantity_milli ELSE 0 END),0) AS closing_milli,
        COALESCE(SUM(CASE WHEN m.created_at<? THEN CASE WHEN m.type IN ('use','waste') THEN -m.cost_total ELSE m.cost_total END ELSE 0 END),0) AS value
        FROM stock_items s LEFT JOIN stock_movements m ON m.stock_item_id=s.id GROUP BY s.id ORDER BY s.name", [$from, $from, $next, $from, $next, $from, $next, $from, $next, $next, $next]);
    foreach ($stock as &$item) {
        $item['id'] = (int)$item['id'];
        foreach (['opening', 'received', 'used', 'waste', 'adjustment', 'closing'] as $key) {
            $item[$key] = qty_float((int)$item[$key . '_milli']);
            unset($item[$key . '_milli']);
        }
        $item['value'] = (int)$item['value'];
    }
    unset($item);
    $costs = ops_row("SELECT COALESCE(SUM(CASE WHEN type='use' THEN cost_total ELSE 0 END),0) AS cogs,COALESCE(SUM(CASE WHEN type='waste' THEN cost_total ELSE 0 END),0) AS waste_cost FROM stock_movements WHERE created_at>=? AND created_at<?", [$from, $next]);
    $expense = ops_row('SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE date>=? AND date<=?', [$from, $to]);
    $top = ops_rows('SELECT i.name,SUM(i.quantity_milli) AS quantity_milli,SUM(CASE WHEN o.subtotal>0 THEN ROUND(1.0*i.line_total*(o.subtotal-o.discount)/o.subtotal) ELSE 0 END) AS sales FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.paid_at>=? AND o.paid_at<? GROUP BY i.name ORDER BY sales DESC LIMIT 30', [$from, $next]);
    foreach ($top as &$item) {
        $item['quantity'] = qty_float((int)$item['quantity_milli']);
        $item['sales'] = (int)$item['sales'];
        unset($item['quantity_milli']);
    }
    unset($item);
    $sales = (int)$summary['sales'];
    $refunds = (int)$refund['refunds'];
    $tax = (int)$summary['tax'] - $taxRefunded;
    $cogs = (int)$costs['cogs'];
    $expenses = (int)$expense['total'];
    return [
        'from' => $from, 'to' => $to, 'sales' => $sales, 'refunds' => $refunds,
        'total_refunds' => (int)$allRefunds['total'], 'deposit_refunds' => (int)$allRefunds['total'] - $refunds,
        'net_sales' => $sales - $refunds, 'tax' => $tax, 'collected' => $collected,
        'orders' => (int)$summary['orders'], 'payment_totals' => $totals,
        'daily' => $daily, 'stock' => $stock, 'top_items' => $top,
        'expenses' => $expenses, 'cogs' => $cogs, 'waste_cost' => (int)$costs['waste_cost'],
        'profit_estimate' => $sales - $refunds - $tax - $cogs - $expenses - (int)$costs['waste_cost'],
        'basis' => 'Sales and revenue refunds are finalized invoices; collections include deposits and their refunds. Ingredient costs follow kitchen usage dates. Refunds retain prepared ingredient costs.',
    ];
}

function ops_currency_locked(): bool
{
    $records = ops_row("SELECT
        EXISTS(SELECT 1 FROM orders) OR EXISTS(SELECT 1 FROM payments)
        OR EXISTS(SELECT 1 FROM expenses) OR EXISTS(SELECT 1 FROM menu_items WHERE price>0)
        OR EXISTS(SELECT 1 FROM stock_items WHERE avg_cost>0 OR value_minor>0)
        OR EXISTS(SELECT 1 FROM stock_movements WHERE cost_total<>0) AS present");
    return (bool)$records['present'];
}

function ops_settings(): array
{
    $defaults = [
        'cafe_name' => 'Ember Café', 'address' => '', 'phone' => '', 'footer' => 'Thank you for visiting!',
        'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'accent' => '#137c74', 'logo_path' => '',
        'tax_enabled' => true, 'default_tax_rate' => 0, 'receipt_width' => '80',
        'kitchen_printer' => '', 'customer_printer' => '', 'kitchen_transport' => 'windows',
        'customer_transport' => 'windows', 'kitchen_host' => '', 'customer_host' => '',
    ];
    foreach ($defaults as $key => $default) {
        $defaults[$key] = setting($key, $default);
    }
    $defaults['tax_enabled'] = (bool)$defaults['tax_enabled'];
    $defaults['default_tax_rate'] = (float)$defaults['default_tax_rate'];
    $defaults['currency_locked'] = ops_currency_locked();
    $defaults['payment_accounts'] = array_map('ops_account', ops_rows('SELECT * FROM payment_accounts ORDER BY name'));
    return $defaults;
}

function ops_save_settings(array $input): array
{
    $allowed = ['cafe_name', 'address', 'phone', 'footer', 'currency', 'timezone', 'accent', 'logo_path', 'tax_enabled', 'default_tax_rate', 'receipt_width', 'kitchen_printer', 'customer_printer', 'kitchen_transport', 'customer_transport', 'kitchen_host', 'customer_host'];
    $updates = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        if ($key === 'tax_enabled') {
            $value = ops_bool($value, 'Tax enabled');
        } elseif ($key === 'default_tax_rate') {
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100) {
                fail('Default tax rate must be between 0 and 100.');
            }
            $value = round((float)$value, 4);
        } else {
            $max = in_array($key, ['address', 'footer'], true) ? 1000 : 200;
            $value = ops_text($value, ucwords(str_replace('_', ' ', $key)), $max, $key === 'cafe_name');
        }
        if ($key === 'currency') {
            $value = strtoupper($value);
            if (!preg_match('/^[A-Z]{3}$/D', $value)) {
                fail('Currency must be a three-letter code such as PKR.');
            }
        } elseif ($key === 'timezone' && !in_array($value, DateTimeZone::listIdentifiers(), true)) {
            fail('Choose a valid timezone such as Asia/Karachi.');
        } elseif ($key === 'accent' && !preg_match('/^#[0-9a-fA-F]{6}$/D', $value)) {
            fail('Accent color must use a six-digit hex value.');
        } elseif ($key === 'receipt_width' && !in_array($value, ['58', '80'], true)) {
            fail('Receipt width must be 58 or 80 millimetres.');
        } elseif (in_array($key, ['kitchen_transport', 'customer_transport'], true) && !in_array($value, ['windows', 'network'], true)) {
            fail('Printer transport must be windows or network.');
        } elseif (in_array($key, ['kitchen_host', 'customer_host'], true) && $value !== '' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            fail('LAN printer host must be a valid IP address.');
        } elseif ($key === 'logo_path' && $value !== '') {
            if ($value !== basename($value) || !preg_match('/^logo-[a-f0-9]{32}\.(png|jpg|webp)$/D', $value) || !is_file(DATA_DIR . '/uploads/' . $value)) {
                fail('Upload the logo through the logo upload form.');
            }
        }
        $updates[$key] = $value;
    }
    if (!$updates) {
        fail('No supported settings were supplied.');
    }
    transaction(function () use ($updates): void {
        if (isset($updates['currency']) && $updates['currency'] !== setting('currency', 'PKR')) {
            if (ops_currency_locked()) {
                fail('Currency cannot be changed after prices, stock costs or transactions exist. Existing amounts must keep their original currency.', 409);
            }
        }
        foreach ($updates as $key => $value) {
            put_setting($key, $value);
        }
        audit('settings.update', 'settings', null, ['fields' => array_keys($updates)]);
    });
    return ops_settings();
}

function ops_upload(array $input): array
{
    $kind = $input['kind'] ?? $_POST['kind'] ?? '';
    if (!in_array($kind, ['logo', 'qr'], true)) {
        fail('Upload kind must be logo or qr.');
    }
    $accountId = null;
    if ($kind === 'qr') {
        $accountId = ops_id($input['account_id'] ?? $_POST['account_id'] ?? null, 'Payment account');
        if (!ops_row('SELECT id FROM payment_accounts WHERE id=?', [$accountId])) {
            fail('Payment account was not found.', 404);
        }
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('Choose an image no larger than 2 MB.');
    }
    if (!is_uploaded_file($file['tmp_name']) || (int)$file['size'] < 1 || (int)$file['size'] > 2 * 1024 * 1024) {
        fail('Image must be an uploaded file no larger than 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $size = getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || !$size || $size['mime'] !== $mime || $size[0] > 6000 || $size[1] > 6000 || $size[0] * $size[1] > 20000000) {
        fail('Upload a valid PNG, JPEG or WebP image up to 6000 pixels per side.');
    }
    $directory = DATA_DIR . '/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        fail('Could not create the image storage folder.', 500);
    }
    $path = $kind . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $path)) {
        fail('Could not store the image.', 500);
    }
    try {
        transaction(function () use ($kind, $accountId, $path): void {
            if ($kind === 'logo') {
                put_setting('logo_path', $path);
            } else {
                ops_run('UPDATE payment_accounts SET qr_path=? WHERE id=?', [$path, $accountId]);
            }
            audit('image.upload', $kind === 'logo' ? 'settings' : 'payment_account', $accountId, ['kind' => $kind, 'path' => $path]);
        });
    } catch (Throwable $error) {
        unlink($directory . '/' . $path);
        throw $error;
    }
    return ['path' => $path];
}

function ops_backup(): never
{
    audit('backup.download', 'database', null);
    $file = DATA_DIR . '/backup-' . bin2hex(random_bytes(16)) . '.sqlite';
    try {
        $stmt = db()->prepare('VACUUM INTO ?');
        $stmt->execute([$file]);
        $copy = new PDO('sqlite:' . $file);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $copy->exec("DELETE FROM settings WHERE key LIKE '%token%' OR key LIKE '%secret%' OR key LIKE '%password%'");
        $copy->exec('DELETE FROM login_attempts');
        if ($copy->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='print_jobs'")->fetchColumn()) {
            $copy->exec("UPDATE print_jobs SET claim_token=NULL,claimed_at=NULL,
                error=CASE WHEN status='processing' THEN 'Restored backup captured an in-progress print. Check the physical receipt before any manual retry.' ELSE error END,
                status=CASE WHEN status='processing' THEN 'uncertain' ELSE status END");
        }
        $copy->exec('VACUUM');
        $copy = null;
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="cafe-pos-' . substr(now(), 0, 10) . '.sqlite"');
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
    exit;
}

function ops_route(string $route, string $method, array $input): mixed
{
    if ($route === 'dashboard' && $method === 'GET') {
        require_user();
        return ops_dashboard();
    }
    if ($route === 'reports' && $method === 'GET') {
        require_role(['owner', 'manager']);
        return ops_reports();
    }
    if ($route === 'settings') {
        require_role(['owner', 'manager']);
        if ($method === 'GET') {
            return ops_settings();
        }
        if ($method === 'POST') {
            return ops_save_settings($input);
        }
    }
    if ($route === 'upload' && $method === 'POST') {
        require_role(['owner', 'manager']);
        return ops_upload($input);
    }
    if ($route === 'accounts') {
        require_user();
        if ($method === 'GET') {
            return array_map('ops_account', ops_rows('SELECT * FROM payment_accounts ORDER BY active DESC,name'));
        }
        if ($method === 'POST') {
            require_role(['owner', 'manager']);
            $id = isset($input['id']) ? ops_id($input['id'], 'Payment account') : null;
            $name = ops_text($input['name'] ?? '', 'Account name', 120, true);
            $methodName = ops_text($input['method'] ?? '', 'Payment method', 20, true);
            if (!in_array($methodName, ['bank', 'easypaisa', 'jazzcash'], true)) {
                fail('Account method must be bank, easypaisa or jazzcash.');
            }
            $details = ops_text($input['details'] ?? '', 'Account details', 1000);
            $active = ops_bool($input['active'] ?? true, 'Active');
            return transaction(function () use ($id, $name, $methodName, $details, $active): array {
                if ($id !== null) {
                    if (!ops_row('SELECT id FROM payment_accounts WHERE id=?', [$id])) {
                        fail('Payment account was not found.', 404);
                    }
                    ops_run('UPDATE payment_accounts SET name=?,method=?,details=?,active=? WHERE id=?', [$name, $methodName, $details, (int)$active, $id]);
                } else {
                    ops_run('INSERT INTO payment_accounts(name,method,details,active) VALUES(?,?,?,?)', [$name, $methodName, $details, (int)$active]);
                    $id = (int)db()->lastInsertId();
                }
                audit('payment_account.save', 'payment_account', $id, ['name' => $name, 'method' => $methodName, 'active' => $active]);
                return ops_account(ops_row('SELECT * FROM payment_accounts WHERE id=?', [$id]));
            });
        }
    }
    if ($route === 'suppliers') {
        require_role(['owner', 'manager']);
        if ($method === 'GET') {
            return ops_rows('SELECT * FROM suppliers ORDER BY name');
        }
        if ($method === 'POST') {
            $id = isset($input['id']) ? ops_id($input['id'], 'Supplier') : null;
            $name = ops_text($input['name'] ?? '', 'Supplier name', 120, true);
            $phone = ops_text($input['phone'] ?? '', 'Phone', 80);
            return transaction(function () use ($id, $name, $phone): array {
                if ($id !== null) {
                    if (!ops_row('SELECT id FROM suppliers WHERE id=?', [$id])) {
                        fail('Supplier was not found.', 404);
                    }
                    ops_run('UPDATE suppliers SET name=?,phone=? WHERE id=?', [$name, $phone, $id]);
                } else {
                    ops_run('INSERT INTO suppliers(name,phone) VALUES(?,?)', [$name, $phone]);
                    $id = (int)db()->lastInsertId();
                }
                audit('supplier.save', 'supplier', $id, ['name' => $name]);
                return ['id' => $id, 'name' => $name, 'phone' => $phone];
            });
        }
    }
    if ($route === 'expenses') {
        require_role(['owner', 'manager']);
        if ($method === 'GET') {
            $rows = ops_rows('SELECT e.*,u.name AS actor_name FROM expenses e LEFT JOIN users u ON u.id=e.created_by ORDER BY e.date DESC,e.id DESC LIMIT 500');
            foreach ($rows as &$row) {
                $row['id'] = (int)$row['id'];
                $row['amount'] = (int)$row['amount'];
            }
            return $rows;
        }
        if ($method === 'POST') {
            $amount = int_money($input['amount'] ?? null, 'Expense amount');
            if ($amount <= 0) {
                fail('Expense amount must be greater than zero.');
            }
            $category = ops_text($input['category'] ?? '', 'Category', 100, true);
            $note = ops_text($input['note'] ?? '', 'Expense note', 1000);
            $date = ops_date($input['date'] ?? substr(now(), 0, 10));
            $paymentMethod = ops_text($input['method'] ?? 'cash', 'Expense payment method', 20);
            if (!in_array($paymentMethod, ['cash', 'bank', 'easypaisa', 'jazzcash'], true)) {
                fail('Choose a supported expense payment method.');
            }
            return transaction(function () use ($amount, $category, $note, $date, $paymentMethod): array {
                $shift = ops_row('SELECT id FROM shifts WHERE closed_at IS NULL');
                if ($paymentMethod === 'cash' && $date !== substr(now(), 0, 10) && $shift) {
                    fail('Backdated cash expenses cannot be assigned to the active shift. Close the shift first or use a non-cash method.');
                }
                $shiftId = $date === substr(now(), 0, 10) ? ($shift['id'] ?? null) : null;
                ops_run('INSERT INTO expenses(amount,category,note,date,method,created_by,created_at,shift_id) VALUES(?,?,?,?,?,?,?,?)', [$amount, $category, $note, $date, $paymentMethod, current_user()['id'], now(), $shiftId]);
                $id = (int)db()->lastInsertId();
                audit('expense.create', 'expense', $id, ['amount' => $amount, 'category' => $category, 'method' => $paymentMethod]);
                return ['id' => $id, 'amount' => $amount, 'category' => $category, 'note' => $note, 'date' => $date, 'method' => $paymentMethod];
            });
        }
    }
    if ($route === 'shifts' && $method === 'GET') {
        require_user();
        $current = ops_row('SELECT s.*,u.name AS opened_by_name FROM shifts s JOIN users u ON u.id=s.opened_by WHERE s.closed_at IS NULL');
        $history = ops_rows('SELECT s.*,u.name AS opened_by_name,c.name AS closed_by_name FROM shifts s JOIN users u ON u.id=s.opened_by LEFT JOIN users c ON c.id=s.closed_by WHERE s.closed_at IS NOT NULL ORDER BY s.id DESC LIMIT 100');
        return ['current' => $current ? ops_shift($current) : null, 'history' => array_map('ops_shift', $history)];
    }
    if ($route === 'shifts/open' && $method === 'POST') {
        require_user();
        $opening = int_money($input['opening_cash'] ?? null, 'Opening cash');
        return transaction(function () use ($opening): array {
            if (ops_row('SELECT id FROM shifts WHERE closed_at IS NULL')) {
                fail('A cash drawer shift is already open. Close it before opening another.', 409);
            }
            ops_run('INSERT INTO shifts(opened_by,opening_cash,opened_at) VALUES(?,?,?)', [current_user()['id'], $opening, now()]);
            $id = (int)db()->lastInsertId();
            audit('shift.open', 'shift', $id, ['opening_cash' => $opening]);
            return ops_shift(ops_row('SELECT * FROM shifts WHERE id=?', [$id]));
        });
    }
    if ($route === 'shifts/close' && $method === 'POST') {
        require_user();
        $counted = int_money($input['counted_cash'] ?? null, 'Counted cash');
        $note = ops_text($input['note'] ?? '', 'Closing note', 1000);
        return transaction(function () use ($counted, $note): array {
            $shift = ops_row('SELECT * FROM shifts WHERE closed_at IS NULL');
            if (!$shift) {
                fail('There is no open cash drawer shift.', 409);
            }
            if ((int)$shift['opened_by'] !== (int)current_user()['id'] && current_user()['role'] === 'cashier') {
                fail('Only the cashier who opened this shift or a manager may close it.', 403);
            }
            $shift = ops_shift($shift);
            $expected = $shift['expected_cash'];
            $discrepancy = $counted - $expected;
            if ($discrepancy !== 0 && $note === '') {
                fail('Add a note explaining the cash difference before closing.');
            }
            ops_run('UPDATE shifts SET closed_by=?,counted_cash=?,expected_cash=?,discrepancy=?,note=?,closed_at=? WHERE id=?', [current_user()['id'], $counted, $expected, $discrepancy, $note, now(), $shift['id']]);
            audit('shift.close', 'shift', $shift['id'], ['counted_cash' => $counted, 'expected_cash' => $expected, 'discrepancy' => $discrepancy, 'note' => $note]);
            return ops_shift(ops_row('SELECT * FROM shifts WHERE id=?', [$shift['id']]));
        });
    }
    if ($route === 'users') {
        require_role(['owner']);
        if ($method === 'GET') {
            return array_map('ops_user', ops_rows('SELECT id,name,username,role,active,created_at FROM users ORDER BY id'));
        }
        if ($method === 'POST') {
            $id = isset($input['id']) ? ops_id($input['id'], 'User') : null;
            $name = ops_text($input['name'] ?? '', 'Staff name', 120, true);
            $username = ops_text($input['username'] ?? '', 'Username', 40, true);
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D', $username)) {
                fail('Username must contain 3–40 letters, numbers, dots, underscores or hyphens.');
            }
            $active = ops_bool($input['active'] ?? true, 'Active');
            $password = $input['password'] ?? '';
            if (!is_string($password) || strlen($password) > 72 || ($password !== '' && (strlen($password) < 10 || str_contains($password, "\0")))) {
                fail('Password must contain 10–72 bytes and cannot contain null characters.');
            }
            if ($id === null && $password === '') {
                fail('A password is required for a new staff member.');
            }
            return transaction(function () use ($input, $id, $name, $username, $active, $password): array {
                $existing = $id !== null ? ops_row('SELECT * FROM users WHERE id=?', [$id]) : null;
                if ($id !== null && !$existing) {
                    fail('Staff member was not found.', 404);
                }
                $role = ops_text($input['role'] ?? ($existing['role'] ?? 'cashier'), 'Role', 20);
                if ($existing && $existing['role'] === 'owner') {
                    if ($role !== 'owner') {
                        fail('The owner role cannot be changed through staff management.');
                    }
                } elseif (!in_array($role, ['manager', 'cashier'], true)) {
                    fail('New staff roles must be manager or cashier.');
                }
                if ($existing && (int)$existing['id'] === (int)current_user()['id'] && !$active) {
                    fail('You cannot deactivate the account you are using.');
                }
                if ($existing && $existing['role'] === 'owner' && !$active) {
                    $owners = ops_row("SELECT COUNT(*) AS count FROM users WHERE role='owner' AND active=1 AND id<>?", [$id]);
                    if ((int)$owners['count'] === 0) {
                        fail('The last active owner cannot be deactivated.');
                    }
                }
                if (ops_row('SELECT id FROM users WHERE username=? COLLATE NOCASE AND id<>?', [$username, $id ?? 0])) {
                    fail('That username is already in use.', 409);
                }
                if ($existing) {
                    ops_run('UPDATE users SET name=?,username=?,role=?,active=? WHERE id=?', [$name, $username, $role, (int)$active, $id]);
                    if ($password !== '') {
                        ops_run('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                } else {
                    ops_run('INSERT INTO users(name,username,password_hash,role,active,created_at) VALUES(?,?,?,?,?,?)', [$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, (int)$active, now()]);
                    $id = (int)db()->lastInsertId();
                }
                audit('staff.save', 'user', $id, ['name' => $name, 'role' => $role, 'active' => $active, 'password_changed' => $password !== '']);
                return ops_user(ops_row('SELECT id,name,username,role,active,created_at FROM users WHERE id=?', [$id]));
            });
        }
    }
    if ($route === 'audit' && $method === 'GET') {
        require_role(['owner', 'manager']);
        $rows = ops_rows('SELECT a.*,u.name AS actor_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 300');
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['details'] = json_decode($row['details'], true) ?? [];
        }
        return $rows;
    }
    if ($route === 'backup' && $method === 'GET') {
        require_role(['owner', 'manager']);
        ops_backup();
    }
    return false;
}
