<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
try {
    require_user();
    $kind = text_input($_GET['kind'] ?? '', 'Receipt type', 30, true);
    if (!in_array($kind, ['preorder','invoice','kot'], true)) fail('Unknown receipt type.');
    $orderId = input_id($_GET['id'] ?? null);
    $ticketId = isset($_GET['ticket_id']) ? input_id($_GET['ticket_id']) : null;
    $payload = browser_print_payload($kind,$orderId,$ticketId);
} catch (ApiError $error) {
    http_response_code($error->httpStatus);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Receipt unavailable</title><link rel="stylesheet" href="receipt.css"></head><body><main class="receipt-error"><h1>Receipt unavailable</h1><p><?= e($error->getMessage()) ?></p><a href="index.php">Return to POS</a></main></body></html><?php
    exit;
}
$order = $payload['order'];
$brand = $payload['brand'];
$width = (string)$payload['receipt_width'] === '58' ? '58' : '80';
$title = match($kind) {'kot'=>'Kitchen order ticket','invoice'=>'Final invoice',default=>'Pre-order / order slip'};
function receipt_money(mixed $minor, array $brand): string { return ($brand['currency'] ?? 'PKR') . ' ' . money((int)$minor); }
function receipt_date(mixed $value): string {
    if (!$value) return '';
    try { return (new DateTimeImmutable((string)$value))->format('d M Y, g:i a'); }
    catch (Exception) { return (string)$value; }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title . ' · ' . $order['number']) ?></title>
    <link rel="stylesheet" href="receipt.css">
    <script src="receipt.js" defer></script>
</head>
<body class="width-<?= e($width) ?>">
    <div class="print-toolbar"><a href="index.php#orders">← Return to orders</a><button type="button" id="print-receipt">Print <?= e($title) ?></button><p>Select <?= $kind === 'kot' ? 'the kitchen' : 'the customer' ?> printer in the dialog. Set paper to <?= e($width) ?> mm, margins to none and disable browser headers/footers. Reprints can duplicate output.</p></div>
    <main class="receipt">
        <header class="receipt-header">
            <?php if ($kind !== 'kot' && !empty($brand['logo_path'])): ?><img class="receipt-logo" src="index.php?asset=<?= e(rawurlencode(basename((string)$brand['logo_path']))) ?>" alt="<?= e($brand['cafe_name'] ?? 'Café') ?> logo"><?php endif; ?>
            <h1><?= e($brand['cafe_name'] ?? 'Café') ?></h1>
            <?php if ($kind !== 'kot'): ?>
                <?php if (!empty($brand['address'])): ?><p><?= nl2br(e($brand['address'])) ?></p><?php endif; ?>
                <?php if (!empty($brand['phone'])): ?><p><?= e($brand['phone']) ?></p><?php endif; ?>
            <?php endif; ?>
            <h2><?= e($title) ?></h2>
        </header>
        <dl class="receipt-meta">
            <div><dt>Order</dt><dd><?= e($order['number']) ?></dd></div>
            <?php if ($kind === 'kot'): ?><div><dt>KOT</dt><dd><?= e($payload['ticket_number']) ?></dd></div><?php endif; ?>
            <?php if ($kind === 'invoice'): ?><div><dt>Invoice</dt><dd><?= e($order['invoice_number']) ?></dd></div><?php endif; ?>
            <div><dt>Issued</dt><dd><?= e(receipt_date($payload['created_at'])) ?></dd></div>
            <div><dt>Type</dt><dd><?= e(ucwords(str_replace('_',' ',(string)$order['type']))) ?></dd></div>
            <?php foreach (['table_name'=>'Table','customer_name'=>'Customer','customer_phone'=>'Phone'] as $key=>$label): if (!empty($order[$key])): ?><div><dt><?= e($label) ?></dt><dd><?= e($order[$key]) ?></dd></div><?php endif; endforeach; ?>
            <?php if (!empty($order['due_at'])): ?><div><dt>Due</dt><dd><?= e(receipt_date($order['due_at'])) ?></dd></div><?php endif; ?>
        </dl>
        <?php if (($payload['current_status'] ?? $order['status']) === 'void'): ?><p class="receipt-warning">VOIDED ORDER</p><?php endif; ?>
        <?php if ((int)($payload['current_refunded'] ?? 0) > 0): ?><p class="receipt-warning">Refunds issued: <?= e(receipt_money($payload['current_refunded'],$brand)) ?><br>This copy preserves the original invoice.</p><?php endif; ?>
        <?php if (!empty($order['notes'])): ?><p class="order-note"><strong>Order note:</strong> <?= nl2br(e($order['notes'])) ?></p><?php endif; ?>
        <div class="receipt-items">
            <?php foreach ($payload['items'] as $item): ?>
                <article class="receipt-item">
                    <div class="item-heading"><strong><?= $kind === 'kot' ? e((string)(float)$item['quantity']) . ' × ' : '' ?><?= e($item['name']) ?></strong><?php if ($kind !== 'kot'): ?><span><?= e(receipt_money($item['line_total'],$brand)) ?></span><?php endif; ?></div>
                    <?php if ($kind !== 'kot'): ?><div class="item-detail"><?= e((string)(float)$item['quantity']) ?> × <?= e(receipt_money($item['price'],$brand)) ?><?php if ($order['tax_enabled'] && (float)$item['tax_rate'] > 0): ?> · Tax <?= e((string)(float)$item['tax_rate']) ?>%<?php endif; ?></div><?php endif; ?>
                    <?php if (!empty($item['note'])): ?><p class="item-note">NOTE: <?= nl2br(e($item['note'])) ?></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($kind !== 'kot'): ?>
            <dl class="receipt-totals">
                <div><dt>Subtotal</dt><dd><?= e(receipt_money($order['subtotal'],$brand)) ?></dd></div>
                <?php if ((int)$order['discount'] > 0): ?><div><dt>Discount</dt><dd>− <?= e(receipt_money($order['discount'],$brand)) ?></dd></div><?php endif; ?>
                <div><dt><?= $order['tax_enabled'] ? 'Tax' : 'Tax (disabled)' ?></dt><dd><?= e(receipt_money($order['tax'],$brand)) ?></dd></div>
                <div class="grand-total"><dt>Total</dt><dd><?= e(receipt_money($order['total'],$brand)) ?></dd></div>
                <div><dt>Net paid at issue</dt><dd><?= e(receipt_money($order['paid'],$brand)) ?></dd></div>
                <div><dt>Balance at issue</dt><dd><?= e(receipt_money($order['balance'],$brand)) ?></dd></div>
            </dl>
            <?php if (!empty($payload['payments'])): ?>
                <div class="receipt-payments"><h3>Payments</h3>
                <?php foreach ($payload['payments'] as $payment): ?><div class="payment-row"><strong><?= ($payment['type'] ?? '') === 'refund' ? 'Refund · ' : '' ?><?= e(ucfirst($payment['method'])) ?></strong><span><?= e(receipt_money($payment['amount'],$brand)) ?></span></div>
                    <?php if (!empty($payment['account_name'])): ?><p><?= e($payment['account_name']) ?></p><?php endif; ?>
                    <?php if (!empty($payment['reference'])): ?><p>Reference: <?= e($payment['reference']) ?></p><?php endif; ?>
                    <?php if ($payment['method'] === 'cash' && $payment['tendered'] !== null): ?><p>Cash received <?= e(receipt_money($payment['tendered'],$brand)) ?> · Change <?= e(receipt_money($payment['change_due'] ?? 0,$brand)) ?></p><?php endif; ?>
                <?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($kind === 'preorder'): ?><p class="receipt-warning">Order slip — not a final invoice</p><p class="receipt-small">Bank / QR payments require staff confirmation.</p><?php endif; ?>
            <footer class="receipt-footer"><?= nl2br(e($brand['footer'] ?? 'Thank you for visiting!')) ?></footer>
        <?php else: ?><footer class="receipt-footer"><strong>Prepare these quantities only.</strong><br>Additional tickets contain additions, not the full order.</footer><?php endif; ?>
    </main>
</body>
</html>
