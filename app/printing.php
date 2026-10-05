<?php
declare(strict_types=1);

function printing_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS print_jobs (
        id INTEGER PRIMARY KEY,
        kind TEXT NOT NULL CHECK(kind IN ('kot','invoice','preorder')),
        order_id INTEGER NOT NULL REFERENCES orders(id),
        ticket_id INTEGER REFERENCES kitchen_tickets(id),
        payload TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','processing','printed','failed','uncertain','cancelled')),
        attempts INTEGER NOT NULL DEFAULT 0,
        error TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        printed_at TEXT,
        claim_token TEXT,
        claimed_at TEXT
    ); CREATE INDEX IF NOT EXISTS print_jobs_status ON print_jobs(status,id);");
}

/** Snapshot content at issue time. KOT lines come from the immutable delta ticket. */
function print_snapshot(string $kind, int $orderId, ?int $ticketId = null): array {
    if (!in_array($kind, ['kot','invoice','preorder'], true)) fail('Unknown receipt type.');
    $order = order_detail($orderId);
    if ($kind === 'invoice' && empty($order['invoice_number'])) fail('A final invoice is available after the order is fully paid.');
    $brand = public_settings();
    $payload = [
        'version'=>1, 'kind'=>$kind, 'created_at'=>now(),
        'receipt_width'=>(string)setting('receipt_width', '80'),
        'brand'=>array_intersect_key($brand, array_flip(['cafe_name','address','phone','footer','currency','logo_path'])),
        'order'=>array_intersect_key($order, array_flip(['id','number','invoice_number','status','type','table_name','customer_name','customer_phone','due_at','notes','tax_enabled','subtotal','discount','tax','total','paid','balance','refunded','created_at'])),
        'items'=>$order['items'], 'payments'=>$order['payments'] ?? [],
    ];
    if ($kind === 'kot') {
        if (!$ticketId) fail('Select a kitchen ticket to print.');
        $ticket = query('SELECT * FROM kitchen_tickets WHERE id=? AND order_id=?', [$ticketId,$orderId])->fetch();
        if (!$ticket) fail('Kitchen ticket not found.', 404);
        $items = query('SELECT name,quantity_milli,note FROM kitchen_ticket_items WHERE ticket_id=? ORDER BY id', [$ticketId])->fetchAll();
        foreach ($items as &$item) { $item['quantity'] = qty_float((int)$item['quantity_milli']); unset($item['quantity_milli']); }
        unset($item);
        $payload['items'] = $items;
        $payload['payments'] = [];
        $payload['ticket_id'] = (int)$ticket['id'];
        $payload['ticket_number'] = $ticket['number'];
        $payload['created_at'] = $ticket['created_at'];
    }
    return $payload;
}

/** Called within the order transaction: the ticket and its print job commit together. */
function queue_print(string $kind, int $orderId, ?int $ticketId = null): int {
    $payload = print_snapshot($kind, $orderId, $ticketId);
    query('INSERT INTO print_jobs(kind,order_id,ticket_id,payload,created_at) VALUES(?,?,?,?,?)', [$kind,$orderId,$ticketId,json_encode($payload, JSON_THROW_ON_ERROR),now()]);
    return (int)db()->lastInsertId();
}

function printer_destination(string $kind): array {
    $prefix = $kind === 'kot' ? 'kitchen' : 'customer';
    return [
        'transport'=>setting($prefix.'_transport', 'windows'),
        'name'=>setting($prefix.'_printer', ''),
        'host'=>setting($prefix.'_host', ''),
    ];
}

/** Worker authentication is deliberately separate from staff cookies and CSRF. */
function require_print_worker(): void {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1','::1'], true)) fail('The print worker must connect from this laptop.', 403);
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{40,200})$/D', $authorization, $matches)) fail('The print worker token is missing or invalid.', 401);
    $expected = setting('print_worker_token_hash', '');
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, hash('sha256', $matches[1]))) fail('The print worker token is missing or invalid.', 401);
}

/** A crashed worker might have printed: an expired claim must never return to queued. */
function expire_print_claims(): void {
    query("UPDATE print_jobs SET status='uncertain', error='Worker interrupted or acknowledgement timed out. Check physical output before retrying.',claim_token=NULL WHERE status='processing' AND julianday(claimed_at)<julianday('now','-2 minutes')");
}

function print_route(string $route, string $method, array $input): mixed {
    if (!str_starts_with($route, 'print/')) return false;

    if ($route === 'print/claim' || $route === 'print/ack') {
        if ($method !== 'POST') fail('Use POST for print worker actions.', 405);
        require_print_worker();
        if ($route === 'print/claim') {
            return transaction(function (): array {
                expire_print_claims();
                query("UPDATE print_jobs SET status='cancelled',error='Order voided before this kitchen ticket was printed.' WHERE status='queued' AND kind='kot' AND order_id IN (SELECT id FROM orders WHERE status='void')");
                // A single local worker runs; do not reorder when a job is still processing.
                if (query("SELECT id FROM print_jobs WHERE status='processing' LIMIT 1")->fetch()) return ['job'=>null];
                $job = query("SELECT * FROM print_jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
                if (!$job) return ['job'=>null];
                $claim = bin2hex(random_bytes(24));
                query("UPDATE print_jobs SET status='processing',attempts=attempts+1,claim_token=?,claimed_at=?,error='' WHERE id=? AND status='queued'", [$claim,now(),$job['id']]);
                $payload = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
                $current = order_detail((int)$job['order_id']);
                $payload['current_status'] = $current['status'];
                $payload['current_refunded'] = (int)($current['refunded'] ?? 0);
                return ['job'=>[
                    'id'=>(int)$job['id'], 'kind'=>$job['kind'], 'claim_token'=>$claim,
                    'payload'=>$payload,
                    'destination'=>printer_destination($job['kind']),
                ]];
            });
        }
        $id = input_id($input['id'] ?? null);
        $claim = text_input($input['claim_token'] ?? '', 'Claim token', 200, true);
        $status = $input['status'] ?? '';
        if (!in_array($status, ['printed','failed','uncertain'], true)) fail('Invalid print acknowledgement status.');
        $error = text_input($input['error'] ?? '', 'Print error', 1000);
        return transaction(function () use ($id,$claim,$status,$error): array {
            $job = query('SELECT * FROM print_jobs WHERE id=?', [$id])->fetch();
            if (!$job || $job['status'] !== 'processing' || !is_string($job['claim_token']) || !hash_equals($job['claim_token'], $claim)) fail('Print claim has expired or was already acknowledged. Check the queue before retrying.', 409);
            query('UPDATE print_jobs SET status=?,error=?,printed_at=?,claim_token=NULL WHERE id=?', [$status,$error,$status === 'printed' ? now() : null,$id]);
            return ['id'=>$id,'status'=>$status];
        });
    }

    if ($route === 'print/queue' && $method === 'POST') {
        require_user(); verify_csrf();
        $kind = text_input($input['kind'] ?? '', 'Receipt type', 30, true);
        if (!in_array($kind, ['invoice','preorder'], true)) fail('Use the original kitchen ticket queue for KOT reprints.');
        $orderId = input_id($input['id'] ?? null);
        if ($kind === 'invoice' && ($input['confirm_duplicate_risk'] ?? false) !== true) fail('Confirm that this is a duplicate invoice print before continuing.');
        $jobId = transaction(function () use ($kind,$orderId): int {
            $payload = $kind === 'invoice' ? browser_print_payload($kind,$orderId,null) : print_snapshot($kind,$orderId);
            query('INSERT INTO print_jobs(kind,order_id,payload,created_at) VALUES(?,?,?,?)', [$kind,$orderId,json_encode($payload, JSON_THROW_ON_ERROR),now()]);
            return (int)db()->lastInsertId();
        });
        audit('print.manual_queue', 'orders', $orderId, ['kind'=>$kind,'job_id'=>$jobId]);
        return ['id'=>$jobId,'status'=>'queued'];
    }

    require_role(['owner','manager']);
    if ($route === 'print/config') {
        if ($method === 'GET') {
            return [
                'configured'=>(bool)setting('print_worker_token_hash', ''),
                'token_present'=>(bool)setting('print_worker_token_hash', ''),
                'kitchen'=>printer_destination('kot'), 'customer'=>printer_destination('invoice'),
            ];
        }
        if ($method !== 'POST') fail('Unsupported method.', 405);
        require_role(['owner']); verify_csrf();
        $token = bin2hex(random_bytes(32));
        put_setting('print_worker_token_hash', hash('sha256', $token));
        audit('print.token.rotate', 'settings');
        return ['configured'=>true,'token'=>$token];
    }
    if ($route === 'print/jobs' && $method === 'GET') {
        expire_print_claims();
        $jobs = query('SELECT p.id,p.kind,p.order_id,p.ticket_id,p.status,p.attempts,p.error,p.created_at,p.printed_at,p.claimed_at,o.number AS order_number FROM print_jobs p JOIN orders o ON o.id=p.order_id ORDER BY p.id DESC LIMIT 100')->fetchAll();
        foreach ($jobs as &$job) { foreach (['id','order_id','attempts'] as $field) $job[$field]=(int)$job[$field]; if ($job['ticket_id'] !== null) $job['ticket_id']=(int)$job['ticket_id']; }
        unset($job);
        return $jobs;
    }
    if ($route === 'print/retry' && $method === 'POST') {
        verify_csrf();
        if (($input['confirm_duplicate_risk'] ?? false) !== true) fail('Check the printer output and confirm the duplicate-print risk before retrying.');
        $id = input_id($input['id'] ?? null);
        return transaction(function () use ($id): array {
            expire_print_claims();
            $job = query('SELECT * FROM print_jobs WHERE id=?', [$id])->fetch();
            if (!$job) fail('Print job not found.', 404);
            if (!in_array($job['status'], ['failed','uncertain','printed'], true)) fail('Only completed, failed or uncertain jobs can be manually reprinted.', 409);
            if ($job['kind'] === 'kot' && query("SELECT id FROM orders WHERE id=? AND status='void'", [$job['order_id']])->fetch()) fail('This order was voided. Its kitchen ticket must not be resent.', 409);
            // Retain original attempts and immutable payload; log the explicit operator decision.
            query("UPDATE print_jobs SET status='queued',error='',printed_at=NULL,claim_token=NULL,claimed_at=NULL WHERE id=?", [$id]);
            audit('print.manual_retry', 'print_jobs', $id, ['previous_status'=>$job['status'],'duplicate_risk_acknowledged'=>true]);
            return ['id'=>$id,'status'=>'queued'];
        });
    }
    fail('Print route not found.', 404);
}

/** Browser reprints use the issued document, not changed recipes/menu prices. */
function browser_print_payload(string $kind, int $orderId, ?int $ticketId): array {
    if ($kind === 'kot') {
        $row = query("SELECT payload FROM print_jobs WHERE kind='kot' AND order_id=? AND ticket_id=? ORDER BY id LIMIT 1", [$orderId,$ticketId])->fetch();
    } elseif ($kind === 'invoice') {
        $row = query("SELECT payload FROM print_jobs WHERE kind='invoice' AND order_id=? ORDER BY id LIMIT 1", [$orderId])->fetch();
    } else { $row = false; }
    $payload = $row ? json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) : print_snapshot($kind,$orderId,$ticketId);
    // Explicit current status keeps old invoice reprints from disguising subsequent refunds.
    $current = order_detail($orderId);
    $payload['current_status'] = $current['status'];
    $payload['current_refunded'] = (int)($current['refunded'] ?? 0);
    return $payload;
}
