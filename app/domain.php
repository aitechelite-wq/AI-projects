<?php
declare(strict_types=1);

function menu_data(): array {
    $items = query('SELECT id,name,category_id,price,tax_rate,active,description FROM menu_items ORDER BY name')->fetchAll();
    foreach ($items as &$item) {
        $item['active'] = (bool)$item['active'];
        $item['recipe'] = query('SELECT stock_item_id,quantity_milli FROM recipes WHERE menu_item_id=? ORDER BY stock_item_id',[$item['id']])->fetchAll();
        foreach ($item['recipe'] as &$ingredient) { $ingredient['quantity'] = qty_float((int)$ingredient['quantity_milli']); unset($ingredient['quantity_milli']); } unset($ingredient);
    } unset($item);
    return ['categories'=>query('SELECT id,name FROM categories ORDER BY name')->fetchAll(),'items'=>$items];
}
function inventory_data(): array {
    $items = query('SELECT * FROM stock_items ORDER BY name')->fetchAll();
    foreach ($items as &$item) { $item['on_hand']=qty_float((int)$item['quantity_milli']); $item['min_stock']=qty_float((int)$item['min_stock_milli']); $item['active']=(bool)$item['active']; unset($item['quantity_milli'],$item['min_stock_milli']); } unset($item);
    $movements = query('SELECT m.*,s.name AS item_name,s.unit FROM stock_movements m JOIN stock_items s ON s.id=m.stock_item_id ORDER BY m.id DESC LIMIT 200')->fetchAll();
    foreach ($movements as &$movement) { $movement['quantity']=qty_float((int)$movement['quantity_milli']); unset($movement['quantity_milli']); } unset($movement);
    return ['items'=>$items,'movements'=>$movements];
}
function order_detail(int $id): array {
    $order = query('SELECT * FROM orders WHERE id=?',[$id])->fetch();
    if (!$order) fail('Order not found.',404);
    $order['tax_enabled']=(bool)$order['tax_enabled'];
    $order['items']=query('SELECT id,menu_item_id,name,quantity_milli,sent_milli,price,tax_rate,note,line_total FROM order_items WHERE order_id=? ORDER BY id',[$id])->fetchAll();
    foreach ($order['items'] as &$item) { $item['quantity']=qty_float((int)$item['quantity_milli']); $item['sent_quantity']=qty_float((int)$item['sent_milli']); unset($item['quantity_milli'],$item['sent_milli']); } unset($item);
    $order['payments']=query('SELECT p.*,a.name AS account_name FROM payments p LEFT JOIN payment_accounts a ON a.id=p.payment_account_id WHERE p.order_id=? ORDER BY p.id',[$id])->fetchAll();
    $gross=0; $refund=0;
    foreach ($order['payments'] as $payment) { if ($payment['type']==='refund') $refund+=(int)$payment['amount']; else $gross+=(int)$payment['amount']; }
    $order['paid']=$gross-$refund; $order['gross_paid']=$gross; $order['refunded']=$refund;
    $order['balance']=in_array($order['status'],['paid','void'],true) ? 0 : max(0,(int)$order['total']-$order['paid']);
    $order['tickets']=query('SELECT id,number,created_at FROM kitchen_tickets WHERE order_id=? ORDER BY id',[$id])->fetchAll();
    foreach ($order['tickets'] as &$ticket) { $ticket['items']=query('SELECT id,order_item_id,name,quantity_milli,note FROM kitchen_ticket_items WHERE ticket_id=? ORDER BY id',[$ticket['id']])->fetchAll(); foreach ($ticket['items'] as &$item) { $item['quantity']=qty_float((int)$item['quantity_milli']); unset($item['quantity_milli']); } unset($item); } unset($ticket);
    return $order;
}
function order_list(): array {
    $summary="SELECT o.*,COALESCE((SELECT SUM(CASE WHEN p.type='refund' THEN -p.amount ELSE p.amount END) FROM payments p WHERE p.order_id=o.id),0) AS paid,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id AND p.type='refund'),0) AS refunded,(SELECT COUNT(*) FROM order_items i WHERE i.order_id=o.id) AS item_count FROM orders o";
    $search=text_input($_GET['search']??'','Order search',200);
    if ($search!=='') {
        $limit=filter_var($_GET['limit']??100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>200]]);
        $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if ($limit===false || $page===false) fail('Search limit must be 1–200 and page must be a positive integer.');
        $offset=($page-1)*$limit;
        $fields=['number','invoice_number','customer_name','customer_phone','table_name'];
        $matches=implode(' OR ',array_map(fn($field)=>"instr(lower(COALESCE($field,'')),lower(?))>0",$fields));
        $orders=query("WITH summaries AS ($summary) SELECT * FROM summaries WHERE $matches ORDER BY id DESC LIMIT $limit OFFSET $offset",array_fill(0,count($fields),$search))->fetchAll();
    } else {
        // Active work remains visible even after hundreds of more recent completed orders.
        $active="(status NOT IN ('paid','void') OR (status='paid' AND type='preorder' AND fulfillment_status IN ('pending','kitchen') AND paid>0))";
        $orders=query("WITH summaries AS ($summary) SELECT * FROM summaries WHERE $active OR id IN (SELECT id FROM summaries WHERE NOT $active ORDER BY id DESC LIMIT 100) ORDER BY id DESC")->fetchAll();
    }
    foreach ($orders as &$order) { $order['balance']=in_array($order['status'],['paid','void'],true) ? 0 : max(0,(int)$order['total']-(int)$order['paid']); } unset($order);
    return $orders;
}
function editable_order(int $id): array {
    $order=query('SELECT * FROM orders WHERE id=?',[$id])->fetch();
    if (!$order) fail('Order not found.',404);
    if (in_array($order['status'],['paid','void'],true)) fail('A finalized or voided order cannot be changed.',409);
    return $order;
}
function recalculate_order(int $id): void {
    $order=query('SELECT * FROM orders WHERE id=?',[$id])->fetch();
    $items=query('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$id])->fetchAll();
    $subtotal=0; $tax=0;
    foreach ($items as &$item) { $item['line_total']=(int)round((int)$item['price']*(int)$item['quantity_milli']/1000); $subtotal+=$item['line_total']; query('UPDATE order_items SET line_total=? WHERE id=?',[$item['line_total'],$item['id']]); } unset($item);
    if ($subtotal > 1000000000000) fail('Order total is too large.');
    $discount=(int)$order['discount'];
    if ($discount>$subtotal) fail('Discount cannot exceed the item subtotal.');
    $allocations=[]; $remainders=[]; $allocated=0;
    foreach ($items as $index=>$item) { $exact=$subtotal>0 ? $discount*$item['line_total']/$subtotal : 0; $allocations[$index]=(int)floor($exact); $allocated+=$allocations[$index]; $remainders[$index]=$exact-floor($exact); }
    arsort($remainders);
    foreach (array_keys($remainders) as $index) { if ($allocated>=$discount) break; $allocations[$index]++; $allocated++; }
    if ($order['tax_enabled']) foreach ($items as $index=>$item) $tax+=(int)round(($item['line_total']-$allocations[$index])*(float)$item['tax_rate']/100);
    $total=$subtotal-$discount+$tax;
    $paid=(int)query("SELECT COALESCE(SUM(CASE WHEN type='refund' THEN -amount ELSE amount END),0) FROM payments WHERE order_id=?",[$id])->fetchColumn();
    if ($total<$paid) fail('The new order total is below the deposit already collected. Refund the difference first.',409);
    query('UPDATE orders SET subtotal=?,tax=?,total=?,updated_at=? WHERE id=?',[$subtotal,$tax,$total,now(),$id]);
}
function save_order(array $input): array {
    $user=require_user();
    return transaction(function() use($input,$user) {
        $type=$input['type']??'takeaway';
        if (!in_array($type,['dine_in','takeaway','preorder'],true)) fail('Choose dine-in, takeaway, or preorder.');
        $table=text_input($input['table_name']??'','Table',80); $customer=text_input($input['customer_name']??'','Customer name',120); $phone=text_input($input['customer_phone']??'','Phone',50); $notes=text_input($input['notes']??'','Order notes',2000);
        $due=null;
        if (!empty($input['due_at'])) {
            $dateText=text_input($input['due_at'],'Due date',40);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D',$dateText)) fail('Use a valid date and time for the preorder.');
            try {
                $date=new DateTimeImmutable($dateText); $errors=DateTimeImmutable::getLastErrors();
                if ($errors && ($errors['warning_count'] || $errors['error_count'])) fail('The preorder due date is invalid.');
                $due=$date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:sP');
            } catch (Exception) { fail('The preorder due date is invalid.'); }
        }
        if ($type==='preorder' && (!$customer || !$due)) fail('Preorders require a customer name and due date.');
        $tax=bool_input($input['tax_enabled']??setting('tax_enabled',true)); $discount=int_money($input['discount']??0,'Discount');
        $cart=$input['items']??null;
        if (!is_array($cart) || !$cart || count($cart)>200) fail('Add between 1 and 200 order lines.');
        $id=empty($input['id']) ? null : input_id($input['id']);
        $existing=[];
        if ($id) { editable_order($id); foreach (query('SELECT * FROM order_items WHERE order_id=?',[$id])->fetchAll() as $line) $existing[(int)$line['id']]=$line; }
        else {
            $next=(int)query('SELECT COALESCE(MAX(id),0)+1 FROM orders')->fetchColumn(); $number='O-'.date('Ymd').'-'.str_pad((string)$next,4,'0',STR_PAD_LEFT);
            query('INSERT INTO orders(number,type,created_by,created_at,updated_at) VALUES(?,?,?,?,?)',[$number,$type,$user['id'],now(),now()]); $id=(int)db()->lastInsertId();
        }
        query('UPDATE orders SET type=?,table_name=?,customer_name=?,customer_phone=?,due_at=?,notes=?,tax_enabled=?,discount=?,updated_at=? WHERE id=?',[$type,$table,$customer,$phone,$due,$notes,(int)$tax,$discount,now(),$id]);
        $seen=[];
        foreach ($cart as $line) {
            if (!is_array($line)) fail('Order line is invalid.');
            $menuId=input_id($line['menu_item_id']??null,'Menu item'); $quantity=qty_int($line['quantity']??0);
            if ($quantity<=0) fail('Order quantities must be greater than zero.');
            $note=text_input($line['note']??'','Item note',500); $lineId=empty($line['id']) ? null : input_id($line['id']);
            if ($lineId) {
                if (!isset($existing[$lineId]) || isset($seen[$lineId])) fail('An order line is invalid or duplicated.');
                $old=$existing[$lineId];
                if ((int)$old['menu_item_id']!==$menuId) fail('A saved order line cannot be changed to a different menu item.');
                if ($quantity<(int)$old['sent_milli']) fail('Items already sent to the kitchen cannot be removed or reduced. Record waste if necessary.',409);
                if ((int)$old['sent_milli']>0 && $note!==$old['note']) fail('Notes already sent to the kitchen cannot be changed. Add a new line for a different preparation.',409);
                query('UPDATE order_items SET quantity_milli=?,note=? WHERE id=?',[$quantity,$note,$lineId]); $seen[$lineId]=true;
            } else {
                $menu=query('SELECT * FROM menu_items WHERE id=? AND active=1',[$menuId])->fetch();
                if (!$menu) fail('This menu item is unavailable.');
                $recipe=query('SELECT stock_item_id,quantity_milli FROM recipes WHERE menu_item_id=?',[$menuId])->fetchAll();
                query('INSERT INTO order_items(order_id,menu_item_id,name,quantity_milli,price,tax_rate,note,recipe_snapshot) VALUES(?,?,?,?,?,?,?,?)',[$id,$menuId,$menu['name'],$quantity,$menu['price'],$menu['tax_rate'],$note,json_encode($recipe,JSON_THROW_ON_ERROR)]);
            }
        }
        foreach ($existing as $lineId=>$line) if (!isset($seen[$lineId])) { if ((int)$line['sent_milli']>0) fail('Items already sent to the kitchen cannot be removed.',409); query('DELETE FROM order_items WHERE id=?',[$lineId]); }
        recalculate_order($id);
        // A new unsent addition takes a ready order back to the working queue.
        if ((int)query('SELECT COUNT(*) FROM order_items WHERE order_id=? AND quantity_milli>sent_milli',[$id])->fetchColumn()>0) query("UPDATE orders SET status=CASE WHEN status='ready' THEN 'sent' ELSE status END,fulfillment_status=CASE WHEN fulfillment_status='ready' THEN 'kitchen' ELSE fulfillment_status END WHERE id=?",[$id]);
        audit('order.saved','order',$id,['lines'=>count($cart)]);
        return order_detail($id);
    });
}
function send_order(int $id): array {
    $user=require_user();
    return transaction(function() use($id,$user) {
        $order=query('SELECT * FROM orders WHERE id=?',[$id])->fetch();
        if (!$order) fail('Order not found.',404);
        if ($order['status']==='void') fail('Voided orders cannot be sent to the kitchen.',409);
        if ($order['status']==='paid' && (int)$order['total']>0 && (int)query("SELECT COALESCE(SUM(CASE WHEN type='refund' THEN -amount ELSE amount END),0) FROM payments WHERE order_id=?",[$id])->fetchColumn()<=0) fail('A fully refunded order cannot be sent to the kitchen. Void the cancelled order.',409);
        $items=query('SELECT * FROM order_items WHERE order_id=? AND quantity_milli>sent_milli ORDER BY id',[$id])->fetchAll();
        if (!$items) fail('All current items have already been sent to the kitchen.',409);
        $needed=[];
        foreach ($items as $item) {
            $delta=(int)$item['quantity_milli']-(int)$item['sent_milli'];
            foreach (json_decode($item['recipe_snapshot'],true,512,JSON_THROW_ON_ERROR) as $ingredient) {
                $stockId=(int)$ingredient['stock_item_id'];
                $required=(int)ceil((int)$ingredient['quantity_milli']*(int)$item['quantity_milli']/1000)-(int)ceil((int)$ingredient['quantity_milli']*(int)$item['sent_milli']/1000);
                $needed[$stockId]=($needed[$stockId]??0)+$required;
            }
        }
        $stocks=[];
        foreach ($needed as $stockId=>$quantity) {
            if ($quantity===0) { unset($needed[$stockId]); continue; }
            $stock=query('SELECT * FROM stock_items WHERE id=?',[$stockId])->fetch();
            if (!$stock || (int)$stock['quantity_milli']<$quantity) { $name=$stock['name']??'Ingredient'; $available=$stock ? qty_float((int)$stock['quantity_milli']) : 0; fail('Insufficient stock: '.$name.' needs '.qty_float($quantity).' '.($stock['unit']??'units').'; available '.$available.'.',409); }
            $stocks[$stockId]=$stock;
        }
        $ticketNext=(int)query('SELECT COALESCE(MAX(id),0)+1 FROM kitchen_tickets')->fetchColumn();
        query('INSERT INTO kitchen_tickets(order_id,number,created_at,created_by) VALUES(?,?,?,?)',[$id,'KOT-'.date('Ymd').'-'.str_pad((string)$ticketNext,4,'0',STR_PAD_LEFT),now(),$user['id']]); $ticketId=(int)db()->lastInsertId();
        foreach ($items as $item) {
            query('INSERT INTO kitchen_ticket_items(ticket_id,order_item_id,name,quantity_milli,note) VALUES(?,?,?,?,?)',[$ticketId,$item['id'],$item['name'],(int)$item['quantity_milli']-(int)$item['sent_milli'],$item['note']]);
            query('UPDATE order_items SET sent_milli=quantity_milli WHERE id=?',[$item['id']]);
        }
        foreach ($needed as $stockId=>$quantity) {
            $cost=(int)round($quantity*(int)$stocks[$stockId]['value_minor']/(int)$stocks[$stockId]['quantity_milli']);
            query('UPDATE stock_items SET quantity_milli=quantity_milli-?,value_minor=value_minor-? WHERE id=?',[$quantity,$cost,$stockId]);
            query("INSERT INTO stock_movements(stock_item_id,quantity_milli,type,cost_total,reference,order_id,created_by,created_at) VALUES(?,?,'use',?,?,?,?,?)",[$stockId,-$quantity,$cost,$order['number'].' / KOT '.$ticketId,$id,$user['id'],now()]);
        }
        query("UPDATE orders SET status=CASE WHEN status='paid' THEN status ELSE 'sent' END,fulfillment_status='kitchen',updated_at=? WHERE id=?",[now(),$id]);
        if (!function_exists('queue_print')) fail('The printing module is unavailable.',503);
        queue_print('kot',$id,$ticketId);
        audit('order.sent','order',$id,['ticket_id'=>$ticketId]);
        return order_detail($id);
    });
}
function payment_method(mixed $value): string { if (!is_string($value) || !in_array($value,['cash','bank','easypaisa','jazzcash'],true)) fail('Choose a supported payment method.'); return $value; }
/** Idempotency keys are optional for old clients, and atomic with each financial write. */
function financial_request(string $route,array $input): ?array {
    if (!isset($input['request_id'])) return null;
    $key=text_input($input['request_id'],'Request ID',128,true);
    if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key)) fail('Financial request ID is invalid.');
    $amount=int_money($input['amount']??null); $method=payment_method($input['method']??'cash');
    $body=['id'=>input_id($input['id']??null),'amount'=>$amount,'method'=>$method];
    if ($route==='orders/pay') {
        $body['payment_account_id']=$method==='cash' ? null : input_id($input['payment_account_id']??null,'Payment account');
        $body['reference']=text_input($input['reference']??'','Payment reference',200);
        $body['tendered']=$method==='cash' ? int_money($input['tendered']??$amount,'Cash tendered') : null;
    } else $body['reason']=text_input($input['reason']??'','Refund reason',1000,true);
    $fingerprint=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
    $saved=query('SELECT * FROM financial_requests WHERE request_key=?',[$key])->fetch();
    if ($saved && ($saved['route']!==$route || !hash_equals($saved['fingerprint'],$fingerprint))) fail('That financial request ID was already used with different details. Start a new payment or refund.',409);
    return ['key'=>$key,'fingerprint'=>$fingerprint,'route'=>$route,'order_id'=>$body['id'],'replay'=>(bool)$saved];
}
function save_financial_request(?array $request): void {
    if ($request) query('INSERT INTO financial_requests(request_key,route,fingerprint,order_id,created_at) VALUES(?,?,?,?,?)',[$request['key'],$request['route'],$request['fingerprint'],$request['order_id'],now()]);
}
function pay_order(array $input): array {
    $user=require_user(); $id=input_id($input['id']??null);
    return transaction(function() use($input,$id,$user) {
        $request=financial_request('orders/pay',$input);
        if ($request && $request['replay']) return order_detail($id);
        editable_order($id); $order=order_detail($id); $amount=int_money($input['amount']??null);
        if ($amount<=0 && $order['total']!==0) fail('Payment amount must be greater than zero.');
        if ($amount>$order['balance']) fail('Payment exceeds the outstanding balance.',409);
        $method=payment_method($input['method']??'cash'); $reference=text_input($input['reference']??'','Payment reference',200);
        $accountId=null;
        if ($method!=='cash') {
            if (!$reference) fail('Confirm the transfer in your banking app and enter its transaction reference.');
            $accountId=input_id($input['payment_account_id']??null,'Payment account');
            if (!query('SELECT id FROM payment_accounts WHERE id=? AND active=1 AND method=?',[$accountId,$method])->fetchColumn()) fail('Choose an active matching payment account.');
            if (query("SELECT id FROM payments WHERE payment_account_id=? AND reference=? AND type='payment'",[$accountId,$reference])->fetchColumn()) fail('That transaction reference is already recorded. Check the earlier payment.',409);
        }
        $tendered=$method==='cash' ? int_money($input['tendered']??$amount,'Cash tendered') : null;
        if ($tendered!==null && $tendered<$amount) fail('Cash tendered must cover the recorded payment.');
        $settled=$order['paid']+$amount===$order['total'];
        if ($settled && $order['type']!=='preorder' && (int)query('SELECT COUNT(*) FROM order_items WHERE order_id=? AND quantity_milli>sent_milli',[$id])->fetchColumn()>0) send_order($id);
        query("INSERT INTO payments(order_id,amount,type,method,payment_account_id,reference,tendered,change_due,created_by,created_at,shift_id) VALUES(?,?,'payment',?,?,?,?,?,?,?,?)",[$id,$amount,$method,$accountId,$reference,$tendered,$tendered===null?0:$tendered-$amount,$user['id'],now(),shift_id()]);
        if ($settled) {
            $invoice='INV-'.date('Ymd').'-'.str_pad((string)$id,5,'0',STR_PAD_LEFT);
            query("UPDATE orders SET status='paid',invoice_number=?,paid_at=?,updated_at=? WHERE id=?",[$invoice,now(),now(),$id]);
            queue_print('invoice',$id);
        }
        audit('order.payment','order',$id,['amount'=>$amount,'method'=>$method,'settled'=>$settled]); save_financial_request($request); return order_detail($id);
    });
}
function inventory_move(array $input): array {
    $user=require_role(['owner','manager']);
    return transaction(function() use($input,$user) {
        $id=input_id($input['stock_item_id']??null,'Stock item'); $stock=query('SELECT * FROM stock_items WHERE id=?',[$id])->fetch(); if (!$stock) fail('Stock item not found.',404);
        $type=$input['type']??''; if (!in_array($type,['receive','waste','count'],true)) fail('Choose receive, waste, or physical count.');
        $quantity=qty_int($input['quantity']??null); $reference=text_input($input['reference']??'','Reference',500);
        if ($type!=='count' && $quantity<=0) fail('Quantity must be greater than zero.');
        $onHand=(int)$stock['quantity_milli']; $value=(int)$stock['value_minor']; $cost=0; $avg=(int)$stock['avg_cost'];
        if ($type==='receive') { $cost=int_money($input['cost_total']??0,'Total purchase cost'); $delta=$quantity; $avg=(int)round(($value+$cost)*1000/($onHand+$quantity)); }
        elseif ($type==='waste') { $delta=-$quantity; if ($quantity>$onHand) fail('Waste exceeds available stock.',409); $cost=(int)round($quantity*$value/$onHand); if (!$reference) fail('Provide a reason for waste.'); }
        else { $delta=$quantity-$onHand; $cost=$onHand>0 ? (int)round($delta*$value/$onHand) : (int)round($delta*$avg/1000); if (!$reference) fail('Provide a reason or count reference.'); }
        $newValue=$value+($type==='waste' ? -$cost : $cost);
        query('UPDATE stock_items SET quantity_milli=quantity_milli+?,avg_cost=?,value_minor=? WHERE id=?',[$delta,$avg,$newValue,$id]);
        query('INSERT INTO stock_movements(stock_item_id,quantity_milli,type,cost_total,reference,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$delta,$type,$cost,$reference,$user['id'],now()]);
        audit('inventory.'.$type,'stock_item',$id,['quantity'=>qty_float($delta),'cost_total'=>$cost]); return inventory_data();
    });
}

function seed_demo(): void {
    $categories=[];
    foreach (['Coffee','Kitchen','Cold drinks'] as $name) { query('INSERT INTO categories(name) VALUES(?)',[$name]); $categories[$name]=(int)db()->lastInsertId(); }
    $stocks=[];
    foreach ([['Coffee beans','g',2000,500,120000],['Milk','ml',10000,2000,250000],['Takeaway cups','pcs',100,20,120000],['Bread slices','pcs',100,20,75000],['Chicken','g',5000,1000,200000],['Lemon syrup','ml',3000,500,90000]] as [$name,$unit,$quantity,$minimum,$cost]) {
        query('INSERT INTO stock_items(name,unit,quantity_milli,min_stock_milli,avg_cost,value_minor,created_at) VALUES(?,?,?,?,?,?,?)',[$name,$unit,qty_int($quantity),qty_int($minimum),(int)round($cost/$quantity),$cost,now()]); $stockId=(int)db()->lastInsertId(); $stocks[$name]=$stockId;
        query("INSERT INTO stock_movements(stock_item_id,quantity_milli,type,cost_total,reference,created_by,created_at) VALUES(?,?,'receive',?,'Optional demo opening stock',?,?)",[$stockId,qty_int($quantity),$cost,current_user()['id']??null,now()]);
    }
    foreach ([['Espresso','Coffee',30000,[['Coffee beans',18],['Takeaway cups',1]],'A rich double shot.'],['Cappuccino','Coffee',45000,[['Coffee beans',18],['Milk',180],['Takeaway cups',1]],'Espresso, warm milk and foam.'],['Iced latte','Cold drinks',50000,[['Coffee beans',18],['Milk',220],['Takeaway cups',1]],'Our classic latte, served cold.'],['Chicken sandwich','Kitchen',65000,[['Bread slices',2],['Chicken',120]],'Toasted bread with chicken.'],['Fresh lemonade','Cold drinks',35000,[['Lemon syrup',60],['Takeaway cups',1]],'Bright, refreshing lemonade.']] as [$name,$category,$price,$recipe,$description]) {
        query('INSERT INTO menu_items(name,category_id,price,tax_rate,description,created_at) VALUES(?,?,?,0,?,?)',[$name,$categories[$category],$price,$description,now()]); $menuId=(int)db()->lastInsertId();
        foreach ($recipe as [$stockName,$quantity]) query('INSERT INTO recipes(menu_item_id,stock_item_id,quantity_milli) VALUES(?,?,?)',[$menuId,$stocks[$stockName],qty_int($quantity)]);
    }
    audit('demo.seeded','environment',null,['message'=>'Optional examples; replace and count stock before live trading.']);
}
function session_data(): array { return ['user'=>current_user(),'csrf'=>csrf_token(),'needs_setup'=>(int)query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'settings'=>public_settings()]; }
function domain_route(string $route,string $method,array $input): mixed {
    if ($route==='session' && $method==='GET') return session_data();
    if ($route==='setup' && $method==='POST') {
        return transaction(function() use($input) {
            if ((int)query('SELECT COUNT(*) FROM users')->fetchColumn()>0) fail('This café has already been set up.',409);
            $name=text_input($input['name']??'','Owner name',120,true); $username=strtolower(text_input($input['username']??'','Username',80,true));
            if (!preg_match('/^[a-z0-9_.-]{3,80}$/',$username)) fail('Username must contain 3–80 letters, numbers, dots, underscores or hyphens.');
            $password=$input['password']??''; if (!is_string($password) || strlen($password)<10 || strlen($password)>72 || str_contains($password,"\0")) fail('Use a password between 10 and 72 bytes without null characters.');
            $cafe=text_input($input['cafe_name']??'Ember Café','Café name',120,true);
            query("INSERT INTO users(name,username,password_hash,role,created_at) VALUES(?,?,?,'owner',?)",[$name,$username,password_hash($password,PASSWORD_DEFAULT),now()]);
            session_regenerate_id(true); $_SESSION['user_id']=(int)db()->lastInsertId(); unset($_SESSION['csrf']); put_setting('cafe_name',$cafe);
            if (bool_input($input['demo']??false)) seed_demo();
            audit('owner.setup','user',(int)$_SESSION['user_id']); return session_data();
        });
    }
    if ($route==='login' && $method==='POST') {
        $username=strtolower(text_input($input['username']??'','Username',80,true)); $password=$input['password']??'';
        if (!is_string($password) || strlen($password)>72 || str_contains($password,"\0")) fail('Invalid username or password.',401);
        $address=$_SERVER['REMOTE_ADDR']??'local'; $cutoff=date('Y-m-d\TH:i:sP',time()-900);
        $attempts=(int)query('SELECT COUNT(*) FROM login_attempts WHERE address=? AND created_at>=? AND successful=0',[$address,$cutoff])->fetchColumn();
        if ($attempts>=10) fail('Too many sign-in attempts. Try again after 15 minutes.',429);
        $user=query('SELECT * FROM users WHERE username=? AND active=1',[$username])->fetch();
        // Always verify a valid hash to reduce account-discovery timing differences.
        $valid=password_verify($password,$user['password_hash']??'$2y$10$KbQi6J7wNC.6glgHzVB8gOBDQovIrR7AXfc94ejqhfmYsEGlLD4ay') && $user;
        query('INSERT INTO login_attempts(address,username,created_at,successful) VALUES(?,?,?,?)',[$address,$username,now(),(int)(bool)$valid]);
        query('DELETE FROM login_attempts WHERE created_at<?',[date('Y-m-d\TH:i:sP',time()-86400)]);
        if (!$valid) fail('Invalid username or password.',401);
        session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; unset($_SESSION['csrf']); audit('user.login','user',(int)$user['id']); return session_data();
    }
    if ($route==='logout' && $method==='POST') { require_user(); audit('user.logout','user',(int)current_user()['id']); $_SESSION=[]; session_regenerate_id(true); return session_data(); }
    require_user();
    if ($route==='menu' && $method==='GET') return menu_data();
    if ($route==='categories' && $method==='POST') { require_role(['owner','manager']); $name=text_input($input['name']??'','Category name',80,true); return transaction(function() use($name) { if (query('SELECT id FROM categories WHERE name=?',[$name])->fetchColumn()) fail('That category already exists.',409); query('INSERT INTO categories(name) VALUES(?)',[$name]); $id=(int)db()->lastInsertId(); audit('category.created','category',$id); return ['id'=>$id,'name'=>$name]; }); }
    if ($route==='menu' && $method==='POST') {
        require_role(['owner','manager']);
        return transaction(function() use($input) {
            $id=empty($input['id']) ? null : input_id($input['id']); if ($id && !query('SELECT id FROM menu_items WHERE id=?',[$id])->fetchColumn()) fail('Menu item not found.',404);
            $name=text_input($input['name']??'','Item name',120,true); $price=int_money($input['price']??null,'Price'); $rate=tax_input($input['tax_rate']??setting('default_tax_rate',0)); $active=(int)bool_input($input['active']??true); $description=text_input($input['description']??'','Description',1000);
            $category=empty($input['category_id']) ? null : input_id($input['category_id'],'Category'); if ($category && !query('SELECT id FROM categories WHERE id=?',[$category])->fetchColumn()) fail('Category not found.');
            $recipe=$input['recipe']??[]; if (!is_array($recipe) || count($recipe)>100) fail('Recipe is invalid.'); $validated=[];
            foreach ($recipe as $ingredient) { if (!is_array($ingredient)) fail('Recipe ingredient is invalid.'); $stockId=input_id($ingredient['stock_item_id']??null,'Ingredient'); $quantity=qty_int($ingredient['quantity']??null); if (!$quantity || isset($validated[$stockId])) fail('Use positive quantities and each ingredient only once.'); if (!query('SELECT id FROM stock_items WHERE id=? AND active=1',[$stockId])->fetchColumn()) fail('Choose an active stock ingredient.'); $validated[$stockId]=$quantity; }
            if ($id) query('UPDATE menu_items SET name=?,category_id=?,price=?,tax_rate=?,active=?,description=? WHERE id=?',[$name,$category,$price,$rate,$active,$description,$id]);
            else { query('INSERT INTO menu_items(name,category_id,price,tax_rate,active,description,created_at) VALUES(?,?,?,?,?,?,?)',[$name,$category,$price,$rate,$active,$description,now()]); $id=(int)db()->lastInsertId(); }
            query('DELETE FROM recipes WHERE menu_item_id=?',[$id]); foreach ($validated as $stockId=>$quantity) query('INSERT INTO recipes(menu_item_id,stock_item_id,quantity_milli) VALUES(?,?,?)',[$id,$stockId,$quantity]);
            audit('menu.saved','menu_item',$id); foreach (menu_data()['items'] as $item) if ((int)$item['id']===$id) return $item; fail('Unable to read saved menu item.',500);
        });
    }
    if ($route==='menu/delete' && $method==='POST') { require_role(['owner','manager']); $id=input_id($input['id']??null); if (!query('SELECT id FROM menu_items WHERE id=?',[$id])->fetchColumn()) fail('Menu item not found.',404); return transaction(function() use($id) { query('UPDATE menu_items SET active=0 WHERE id=?',[$id]); audit('menu.archived','menu_item',$id); return ['id'=>$id,'active'=>false]; }); }
    if ($route==='inventory' && $method==='GET') { require_role(['owner','manager']); return inventory_data(); }
    if ($route==='inventory/item' && $method==='POST') {
        require_role(['owner','manager']);
        return transaction(function() use($input) {
            $id=empty($input['id']) ? null : input_id($input['id']); $name=text_input($input['name']??'','Stock name',120,true); $unit=text_input($input['unit']??'pcs','Unit',15,true);
            if (!in_array($unit,['g','kg','ml','l','pcs'],true)) fail('Use g, kg, ml, l or pcs. Recipe quantities use the same unit.');
            $minimum=qty_int($input['min_stock']??0); $active=(int)bool_input($input['active']??true);
            if ($id) { $stock=query('SELECT * FROM stock_items WHERE id=?',[$id])->fetch(); if (!$stock) fail('Stock item not found.',404); if ($unit!==$stock['unit'] && ((int)$stock['quantity_milli']!==0 || (int)query('SELECT COUNT(*) FROM recipes WHERE stock_item_id=?',[$id])->fetchColumn()>0 || (int)query('SELECT COUNT(*) FROM stock_movements WHERE stock_item_id=?',[$id])->fetchColumn()>0)) fail('Units cannot change after stock or recipes have been recorded. Create a new stock item.'); query('UPDATE stock_items SET name=?,unit=?,min_stock_milli=?,active=? WHERE id=?',[$name,$unit,$minimum,$active,$id]); }
            else { query('INSERT INTO stock_items(name,unit,min_stock_milli,active,created_at) VALUES(?,?,?,?,?)',[$name,$unit,$minimum,$active,now()]); $id=(int)db()->lastInsertId(); }
            audit('stock.saved','stock_item',$id); foreach (inventory_data()['items'] as $item) if ((int)$item['id']===$id) return $item; fail('Unable to read saved stock item.',500);
        });
    }
    if ($route==='inventory/move' && $method==='POST') return inventory_move($input);
    if ($route==='orders' && $method==='GET') return order_list();
    if ($route==='orders/detail' && $method==='GET') return order_detail(input_id($_GET['id']??null));
    if ($route==='orders/save' && $method==='POST') return save_order($input);
    if ($route==='orders/send' && $method==='POST') return send_order(input_id($input['id']??null));
    if ($route==='orders/pay' && $method==='POST') return pay_order($input);
    if ($route==='orders/status' && $method==='POST') {
        return transaction(function() use($input) { $id=input_id($input['id']??null); if (($input['status']??'')!=='ready') fail('Only the ready transition is supported.'); $order=order_detail($id); if ($order['status']==='void' || !$order['tickets'] || (int)query('SELECT COUNT(*) FROM order_items WHERE order_id=? AND quantity_milli>sent_milli',[$id])->fetchColumn()>0) fail('Send all items to the kitchen before marking ready.',409); query("UPDATE orders SET status=CASE WHEN status='paid' THEN status ELSE 'ready' END,fulfillment_status='ready',updated_at=? WHERE id=?",[now(),$id]); audit('order.ready','order',$id); return order_detail($id); });
    }
    if ($route==='orders/void' && $method==='POST') {
        require_role(['owner','manager']);
        return transaction(function() use($input) { $id=input_id($input['id']??null); $order=order_detail($id); if ($order['status']==='void') fail('Order is already void.',409); $reason=text_input($input['reason']??'','Void reason',1000,true); if ($order['paid']>0) fail('Refund the collected deposit or payment before voiding.',409); query("UPDATE orders SET status='void',void_reason=?,updated_at=? WHERE id=?",[$reason,now(),$id]); if (function_exists('printing_schema')) query("UPDATE print_jobs SET status='cancelled',error='Order voided before print' WHERE order_id=? AND kind='kot' AND status='queued'",[$id]); audit('order.voided','order',$id,['reason'=>$reason,'stock_restored'=>false]); return order_detail($id); });
    }
    if ($route==='orders/refund' && $method==='POST') {
        $user=require_role(['owner','manager']);
        return transaction(function() use($input,$user) { $id=input_id($input['id']??null); $request=financial_request('orders/refund',$input); if ($request && $request['replay']) return order_detail($id); $order=order_detail($id); $amount=int_money($input['amount']??null); if ($amount<=0 || $amount>$order['paid']) fail('Refund must be positive and cannot exceed the net collected amount.',409); $method=payment_method($input['method']??'cash'); $reason=text_input($input['reason']??'','Refund reason',1000,true);
            $invoiceRefund=!empty($order['invoice_number']);
            $priorRefund=(int)query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=? AND type='refund' AND invoice_refund=1",[$id])->fetchColumn();
            $refundTax=$invoiceRefund && $order['total']>0 ? (int)round(($priorRefund+$amount)*$order['tax']/$order['total'])-(int)round($priorRefund*$order['tax']/$order['total']) : 0;
            query("INSERT INTO payments(order_id,amount,type,method,reason,invoice_refund,refunded_tax,created_by,created_at,shift_id) VALUES(?,?,'refund',?,?,?,?,?,?,?)",[$id,$amount,$method,$reason,(int)$invoiceRefund,$refundTax,$user['id'],now(),shift_id()]); audit('order.refunded','order',$id,['amount'=>$amount,'method'=>$method,'reason'=>$reason,'stock_restored'=>false]); save_financial_request($request); return order_detail($id); });
    }
    if ($route==='kitchen' && $method==='GET') { $ids=query("SELECT id FROM orders WHERE status<>'void' AND (fulfillment_status='kitchen' OR (fulfillment_status='ready' AND status<>'paid')) ORDER BY COALESCE(due_at,created_at),id")->fetchAll(PDO::FETCH_COLUMN); return array_map(fn($id)=>order_detail((int)$id),$ids); }
    return false;
}
