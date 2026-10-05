"""Run with python3 -m unittest discover -s tests -v (PHP 8.2+ required)."""
import base64
import datetime
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('CAFE_PHP') or shutil.which('php') or '/workspace/.php-runtime/bin/php'


class ApiTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='cafe-pos-tests-')
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
        sock.close()
        self.url = f'http://127.0.0.1:{port}/index.php'
        self.log = open(Path(self.temp.name) / 'server.log', 'w+')
        self.server = subprocess.Popen(
            [PHP, '-S', f'127.0.0.1:{port}', '-t', str(ROOT / 'public')],
            cwd=ROOT, env={**os.environ, 'CAFE_DATA_DIR': self.temp.name},
            stdout=self.log, stderr=self.log,
        )
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''
        for _ in range(60):
            try:
                session = self.api('session')
                self.csrf = session['csrf']
                break
            except (urllib.error.URLError, ConnectionError):
                time.sleep(.05)
        else:
            self.fail('PHP server did not start')
        self.api('setup', {'name': 'Test Owner', 'username': 'owner', 'password': 'CafeTest!4321', 'cafe_name': 'Test Café', 'demo': False})
        self.csrf = self.api('session')['csrf']

    def tearDown(self):
        self.server.terminate()
        self.server.wait(timeout=5)
        self.log.close()
        self.temp.cleanup()

    def request(self, route, body=None, csrf=True, extra=''):
        headers = {'Accept': 'application/json'}
        if body is not None:
            headers['Content-Type'] = 'application/json'
            if csrf:
                headers['X-CSRF-Token'] = self.csrf
        req = urllib.request.Request(self.url + '?api=' + route + extra,
                                     data=None if body is None else json.dumps(body).encode(), headers=headers)
        try:
            response = self.client.open(req)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read()

    def api(self, route, body=None, extra=''):
        status, raw = self.request(route, body, extra=extra)
        try:
            result = json.loads(raw)
        except json.JSONDecodeError:
            self.fail(f'{route}: HTTP {status}: {raw[:1000]!r}')
        self.assertLess(status, 400, f'{route}: {result}')
        self.assertTrue(result.get('ok'), f'{route}: {result}')
        return result['data']

    def rejected(self, route, body, statuses=(400, 403, 409), extra=''):
        status, raw = self.request(route, body, extra=extra)
        self.assertIn(status, statuses, f'{route}: {raw[:500]!r}')
        self.assertFalse(json.loads(raw)['ok'])

    def catalog(self, stock=1000, rate=10):
        item = self.api('inventory/item', {'name': 'Coffee beans', 'unit': 'g', 'min_stock': 50, 'active': True})
        stock_id = item['id']
        self.api('inventory/move', {'stock_item_id': stock_id, 'type': 'receive', 'quantity': stock, 'cost_total': 20000, 'reference': 'Opening delivery'})
        category = self.api('categories', {'name': 'Coffee'})
        menu = self.api('menu', {'name': 'Latte', 'category_id': category['id'], 'price': 50000, 'tax_rate': rate, 'active': True, 'description': 'Fresh coffee', 'recipe': [{'stock_item_id': stock_id, 'quantity': 20}]})
        return stock_id, menu['id']

    def order(self, menu_id, quantity=1, **kwargs):
        return self.api('orders/save', {'type': 'takeaway', 'tax_enabled': True, 'discount': 0, 'items': [{'menu_item_id': menu_id, 'quantity': quantity, 'note': 'No sugar'}], **kwargs})

    def stock(self, stock_id):
        return next(row['on_hand'] for row in self.api('inventory')['items'] if row['id'] == stock_id)

    def detail(self, order_id):
        return self.api('orders/detail', extra=f'&id={order_id}')

    def test_setup_auth_csrf_and_role_permissions(self):
        self.rejected('setup', {'name': 'Other', 'username': 'other', 'password': 'LongEnough4321', 'cafe_name': 'Other'})
        status, _ = self.request('categories', {'name': 'No CSRF'}, csrf=False)
        self.assertEqual(status, 403)
        self.api('users', {'name': 'Cashier', 'username': 'cashier', 'password': 'CashierPass4321', 'role': 'cashier', 'active': True})
        self.api('logout', {})
        self.csrf = self.api('session')['csrf']
        self.api('login', {'username': 'cashier', 'password': 'CashierPass4321'})
        self.csrf = self.api('session')['csrf']
        self.rejected('settings', {'cafe_name': 'Hacked'}, (403,))
        self.rejected('inventory/item', {'name': 'Invalid', 'unit': 'g', 'min_stock': 0}, (403,))
        status, _ = self.request('reports')
        self.assertEqual(status, 403)

    def test_tax_discount_and_no_tax_are_server_calculated(self):
        _, menu_id = self.catalog()
        taxed = self.order(menu_id, 2, discount=10000)
        self.assertEqual(taxed['subtotal'], 100000)
        self.assertEqual(taxed['tax'], 9000)
        self.assertEqual(taxed['total'], 99000)
        untaxed = self.order(menu_id, 2, tax_enabled=False)
        self.assertEqual(untaxed['total'], 100000)
        self.assertEqual(untaxed['tax'], 0)
        self.rejected('orders/save', {'type': 'takeaway', 'discount': 1000000, 'items': [{'menu_item_id': menu_id, 'quantity': 1}]})

    def test_kitchen_stock_deltas_and_immutable_sent_lines(self):
        stock_id, menu_id = self.catalog()
        order = self.order(menu_id, 2)
        self.assertEqual(self.stock(stock_id), 1000)
        self.api('orders/send', {'id': order['id']})
        self.assertEqual(self.stock(stock_id), 960)
        self.request('orders/send', {'id': order['id']})
        self.assertEqual(self.stock(stock_id), 960)
        order = self.detail(order['id'])
        line_id = order['items'][0]['id']
        self.rejected('orders/save', {'id': order['id'], 'type': 'takeaway', 'items': [{'id': line_id, 'menu_item_id': menu_id, 'quantity': 1}]})
        self.api('orders/save', {'id': order['id'], 'type': 'takeaway', 'tax_enabled': True, 'items': [{'id': line_id, 'menu_item_id': menu_id, 'quantity': 3, 'note': 'No sugar'}]})
        self.api('orders/send', {'id': order['id']})
        self.assertEqual(self.stock(stock_id), 940)
        updated = self.detail(order['id'])
        self.assertEqual(updated['items'][0]['sent_quantity'], 3)
        self.assertEqual(len(updated['tickets']), 2)

    def test_insufficient_stock_rolls_back_entire_send(self):
        stock_id, menu_id = self.catalog(stock=30)
        order = self.order(menu_id, 2)
        self.rejected('orders/send', {'id': order['id']})
        self.assertEqual(self.stock(stock_id), 30)
        self.assertEqual(self.detail(order['id'])['items'][0]['sent_quantity'], 0)
        self.assertEqual(len(self.detail(order['id'])['tickets']), 0)

    def test_split_payment_deposit_cash_change_and_invoice(self):
        stock_id, menu_id = self.catalog()
        order = self.order(menu_id, type='preorder', customer_name='Amir', due_at='2026-11-01T10:00')
        self.api('orders/pay', {'id': order['id'], 'amount': 20000, 'method': 'cash', 'tendered': 25000})
        deposit = self.detail(order['id'])
        self.assertEqual(deposit['paid'], 20000)
        self.assertEqual(deposit['balance'], 35000)
        self.assertFalse(deposit.get('invoice_number'))
        self.assertEqual(self.stock(stock_id), 1000)
        self.api('orders/send', {'id': order['id']})
        account = self.api('accounts', {'name': 'Till bank', 'method': 'bank', 'details': 'Account ending 1234', 'active': True})
        self.rejected('orders/pay', {'id': order['id'], 'amount': 35000, 'method': 'bank', 'payment_account_id': account['id']})
        self.api('orders/pay', {'id': order['id'], 'amount': 35000, 'method': 'bank', 'payment_account_id': account['id'], 'reference': 'Confirmed TX123'})
        paid = self.detail(order['id'])
        self.assertEqual(paid['status'], 'paid')
        self.assertEqual(paid['balance'], 0)
        self.assertTrue(paid['invoice_number'])
        self.assertEqual(paid['payments'][0]['change_due'], 5000)
        self.rejected('orders/pay', {'id': order['id'], 'amount': 1, 'method': 'cash'})
        self.rejected('orders/save', {'id': order['id'], 'type': 'takeaway', 'items': [{'menu_item_id': menu_id, 'quantity': 2}]})

    def test_refund_does_not_replenish_prepared_ingredients(self):
        stock_id, menu_id = self.catalog()
        order = self.order(menu_id)
        self.api('orders/send', {'id': order['id']})
        self.api('orders/pay', {'id': order['id'], 'amount': 55000, 'method': 'cash'})
        self.api('orders/refund', {'id': order['id'], 'amount': 55000, 'method': 'cash', 'reason': 'Customer cancellation'})
        self.assertEqual(self.stock(stock_id), 980)
        self.rejected('orders/refund', {'id': order['id'], 'amount': 1, 'method': 'cash', 'reason': 'Duplicate'})
        self.assertTrue(self.detail(order['id'])['invoice_number'])

    def test_stock_receipts_waste_counts_and_report_equation(self):
        stock_id, menu_id = self.catalog()
        self.api('inventory/move', {'stock_item_id': stock_id, 'type': 'waste', 'quantity': 10, 'reference': 'Spillage'})
        self.api('inventory/move', {'stock_item_id': stock_id, 'type': 'count', 'quantity': 985, 'reference': 'Physical count'})
        order = self.order(menu_id)
        self.api('orders/send', {'id': order['id']})
        self.api('orders/pay', {'id': order['id'], 'amount': 55000, 'method': 'cash'})
        report = self.api('reports', extra='&from=2025-01-01&to=2030-12-31')
        row = next(r for r in report['stock'] if r['id'] == stock_id)
        self.assertEqual(row['opening'] + row['received'] - row['used'] - row['waste'] + row['adjustment'], row['closing'])
        self.assertEqual(row['closing'], 965)
        self.assertEqual(row['used'], 20)
        self.assertEqual(report['sales'], 55000)
        self.assertEqual(report['tax'], 5000)

    def test_shift_cash_reconciliation_includes_expenses_and_refunds(self):
        _, menu_id = self.catalog(rate=0)
        self.api('shifts/open', {'opening_cash': 10000})
        order = self.order(menu_id)
        self.api('orders/send', {'id': order['id']})
        self.api('orders/pay', {'id': order['id'], 'amount': 50000, 'method': 'cash', 'tendered': 100000})
        self.api('orders/refund', {'id': order['id'], 'amount': 10000, 'method': 'cash', 'reason': 'Partial refund'})
        self.api('expenses', {'amount': 5000, 'category': 'Supplies', 'note': 'Cleaning', 'date': datetime.date.today().isoformat(), 'method': 'cash'})
        closed = self.api('shifts/close', {'counted_cash': 44000, 'note': 'End of day'})
        self.assertEqual(closed['expected_cash'], 45000)
        self.assertEqual(closed['discrepancy'], -1000)

    def test_settings_rebranding_and_backup_is_consistent_sqlite(self):
        self.api('settings', {'cafe_name': 'My New Café', 'address': 'Main Street, Lahore', 'accent': '#007766', 'footer': 'See you soon', 'tax_enabled': False})
        settings = self.api('settings')
        self.assertEqual(settings['cafe_name'], 'My New Café')
        self.assertEqual(settings['address'], 'Main Street, Lahore')
        status, raw = self.request('backup')
        self.assertEqual(status, 200)
        self.assertTrue(raw.startswith(b'SQLite format 3\x00'))
        path = Path(self.temp.name) / 'verify-backup.sqlite'
        path.write_bytes(raw)
        with sqlite3.connect(path) as conn:
            self.assertEqual(conn.execute('PRAGMA integrity_check').fetchone()[0], 'ok')
            self.assertEqual(json.loads(conn.execute("SELECT value FROM settings WHERE key='cafe_name'").fetchone()[0]), 'My New Café')

    def test_fully_prepaid_preorder_can_be_prepared_later(self):
        stock_id, menu_id = self.catalog()
        order = self.order(menu_id, type='preorder', customer_name='Sara', due_at='2026-11-02T15:00')
        self.api('orders/pay', {'id': order['id'], 'amount': 55000, 'method': 'cash'})
        paid = self.detail(order['id'])
        self.assertTrue(paid['invoice_number'])
        self.assertEqual(self.stock(stock_id), 1000)
        self.api('orders/send', {'id': order['id']})
        self.assertEqual(self.stock(stock_id), 980)
        self.assertEqual(self.detail(order['id'])['status'], 'paid')
        self.assertEqual(self.detail(order['id'])['fulfillment_status'], 'kitchen')
        self.assertTrue(any(r['id'] == order['id'] for r in self.api('kitchen')))
        self.api('orders/status', {'id': order['id'], 'status': 'ready'})
        self.assertEqual(self.detail(order['id'])['fulfillment_status'], 'ready')

    def test_comp_order_closes_and_consumes_real_stock(self):
        stock_id, menu_id = self.catalog()
        order = self.order(menu_id, discount=50000)
        self.assertEqual(order['total'], 0)
        self.api('orders/pay', {'id': order['id'], 'amount': 0, 'method': 'cash', 'tendered': 0})
        self.assertTrue(self.detail(order['id'])['invoice_number'])
        self.assertEqual(self.stock(stock_id), 980)

    def test_settlement_failure_does_not_collect_money_or_issue_invoice(self):
        stock_id, menu_id = self.catalog(stock=5)
        order = self.order(menu_id)
        self.rejected('orders/pay', {'id': order['id'], 'amount': 55000, 'method': 'cash'})
        unchanged = self.detail(order['id'])
        self.assertEqual(unchanged['paid'], 0)
        self.assertFalse(unchanged['invoice_number'])
        self.assertEqual(self.stock(stock_id), 5)

    def test_duplicate_transfer_reference_and_deposit_void_guard(self):
        _, menu_id = self.catalog()
        account = self.api('accounts', {'name': 'Wallet', 'method': 'easypaisa', 'details': 'My account', 'active': True})
        first = self.order(menu_id)
        body = {'id': first['id'], 'amount': 10000, 'method': 'easypaisa', 'payment_account_id': account['id'], 'reference': 'TX-unique'}
        self.api('orders/pay', body)
        second = self.order(menu_id)
        self.rejected('orders/pay', {**body, 'id': second['id']}, (409,))
        self.rejected('orders/void', {'id': first['id'], 'reason': 'Cancelled'}, (409,))
        self.api('orders/refund', {'id': first['id'], 'amount': 10000, 'method': 'easypaisa', 'reason': 'Deposit returned'})
        self.assertEqual(self.api('orders/void', {'id': first['id'], 'reason': 'Cancelled'})['status'], 'void')

    def worker(self, token, route, body):
        request = urllib.request.Request(self.url + '?api=' + route, data=json.dumps(body).encode(), headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'})
        try:
            response = urllib.request.urlopen(request)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, json.loads(response.read())

    def test_print_queue_claim_ack_and_uncertain_manual_retry(self):
        _, menu_id = self.catalog()
        order = self.order(menu_id)
        self.api('orders/send', {'id': order['id']})
        token = self.api('print/config', {})['token']
        status, _ = self.worker('a' * 64, 'print/claim', {})
        self.assertEqual(status, 401)
        status, claimed = self.worker(token, 'print/claim', {})
        self.assertEqual(status, 200)
        job = claimed['data']['job']
        self.assertEqual(job['kind'], 'kot')
        self.assertEqual(job['payload']['items'][0]['quantity'], 1)
        self.assertEqual(job['payload']['items'][0]['note'], 'No sugar')
        self.assertIsNone(self.worker(token, 'print/claim', {})[1]['data']['job'])
        ack = {'id': job['id'], 'claim_token': job['claim_token'], 'status': 'uncertain', 'error': 'Connection ended after bytes were written'}
        self.assertEqual(self.worker(token, 'print/ack', ack)[0], 200)
        self.assertEqual(self.worker(token, 'print/ack', ack)[0], 409)
        self.assertIsNone(self.worker(token, 'print/claim', {})[1]['data']['job'])
        self.rejected('print/retry', {'id': job['id']})
        self.api('print/retry', {'id': job['id'], 'confirm_duplicate_risk': True})
        retry = self.worker(token, 'print/claim', {})[1]['data']['job']
        self.assertEqual(retry['payload'], job['payload'])
        self.assertNotEqual(retry['claim_token'], job['claim_token'])
        self.assertEqual(self.worker(token, 'print/ack', {'id': retry['id'], 'claim_token': retry['claim_token'], 'status': 'printed'})[0], 200)

    def test_logo_upload_image_serving_and_file_type_restriction(self):
        def upload(blob, name):
            boundary = 'cafe-upload-boundary'
            body = (f'--{boundary}\r\nContent-Disposition: form-data; name="kind"\r\n\r\nlogo\r\n'
                    f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n').encode() + blob + f'\r\n--{boundary}--\r\n'.encode()
            request = urllib.request.Request(self.url + '?api=upload', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary, 'X-CSRF-Token': self.csrf})
            try:
                response = self.client.open(request)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, json.loads(response.read())
        image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZc8AAAAASUVORK5CYII=')
        status, result = upload(image, 'logo.png')
        self.assertEqual(status, 200, result)
        filename = result['data']['path']
        with self.client.open(self.url + '?asset=' + filename) as response:
            self.assertEqual(response.headers['Content-Type'], 'image/png')
            self.assertEqual(response.read(), image)
        self.assertEqual(upload(b'<?php echo "evil"; ?>', 'logo.php')[0], 400)
        with self.assertRaises(urllib.error.HTTPError) as denied:
            self.client.open(self.url + '?asset=../cafe.sqlite')
        self.assertIn(denied.exception.code, (400, 404))
        denied.exception.close()

    def test_split_kitchen_usage_preserves_exact_stock_cost(self):
        stock_id, menu_id = self.catalog()
        self.api('inventory/move', {'stock_item_id': stock_id, 'type': 'receive', 'quantity': 1, 'cost_total': 1, 'reference': 'Rounding test delivery'})
        order = self.order(menu_id, 25)
        self.api('orders/send', {'id': order['id']})
        line_id = self.detail(order['id'])['items'][0]['id']
        self.api('orders/save', {'id': order['id'], 'type': 'takeaway', 'tax_enabled': True, 'items': [{'id': line_id, 'menu_item_id': menu_id, 'quantity': 50.05, 'note': 'No sugar'}]})
        self.api('orders/send', {'id': order['id']})
        self.assertEqual(self.stock(stock_id), 0)
        report = self.api('reports', extra='&from=2025-01-01&to=2030-12-31')
        row = next(r for r in report['stock'] if r['id'] == stock_id)
        self.assertEqual(row['value'], 0)
        self.assertEqual(report['cogs'], 20001)

    def test_returned_deposit_is_not_a_refund_of_final_invoice_sales(self):
        _, menu_id = self.catalog()
        order = self.order(menu_id, type='preorder', customer_name='Ali', due_at='2026-11-03T12:00')
        self.api('orders/pay', {'id': order['id'], 'amount': 20000, 'method': 'cash'})
        self.api('orders/refund', {'id': order['id'], 'amount': 10000, 'method': 'cash', 'reason': 'Reduce deposit'})
        self.api('orders/pay', {'id': order['id'], 'amount': 45000, 'method': 'cash'})
        report = self.api('reports', extra='&from=2025-01-01&to=2030-12-31')
        self.assertEqual(report['sales'], 55000)
        self.assertEqual(report['refunds'], 0)
        self.assertEqual(report['net_sales'], 55000)
        self.assertEqual(report['collected'], 55000)
        self.assertEqual(report['tax'], 5000)

    def test_payment_and_refund_retries_are_idempotent(self):
        _, menu_id = self.catalog()
        order = self.order(menu_id)
        payment = {'id': order['id'], 'amount': 20000, 'method': 'cash', 'tendered': 20000, 'request_id': '21b31dd0-f072-4c9f-9c80-f37dd588eb04'}
        self.api('orders/pay', payment)
        self.api('orders/pay', payment)
        self.assertEqual(self.detail(order['id'])['paid'], 20000)
        self.assertEqual(len(self.detail(order['id'])['payments']), 1)
        self.rejected('orders/pay', {**payment, 'amount': 10000}, (409,))
        refund = {'id': order['id'], 'amount': 5000, 'method': 'cash', 'reason': 'Returned portion of deposit', 'request_id': '63d01e5b-51ac-494a-a7ac-cc00bd2f905b'}
        self.api('orders/refund', refund)
        self.api('orders/refund', refund)
        self.assertEqual(self.detail(order['id'])['paid'], 15000)
        self.assertEqual(len(self.detail(order['id'])['payments']), 2)
        self.rejected('orders/refund', {**refund, 'amount': 1000}, (409,))

    def test_old_active_preorders_remain_visible_and_history_is_searchable(self):
        _, menu_id = self.catalog()
        prepaid = self.order(menu_id, type='preorder', customer_name='Advance guest', due_at='2026-12-01T10:00')
        self.api('orders/pay', {'id': prepaid['id'], 'amount': 55000, 'method': 'cash'})
        unpaid = self.order(menu_id, type='preorder', customer_name='Later guest', due_at='2026-12-02T10:00')
        # Seed historical zero-total invoices to exercise an older record efficiently.
        stamp = '2026-10-01T10:00:00+05:00'
        with sqlite3.connect(Path(self.temp.name) / 'cafe.sqlite') as conn:
            for i in range(106):
                conn.execute("INSERT INTO orders(number,invoice_number,status,fulfillment_status,type,created_at,updated_at,paid_at) VALUES(?,?,'paid','ready','takeaway',?,?,?)", (f'HISTORY-{i:03}', f'INV-HISTORY-{i:03}', stamp, stamp, stamp))
        ids = {o['id'] for o in self.api('orders')}
        self.assertIn(prepaid['id'], ids)
        self.assertIn(unpaid['id'], ids)
        history = self.api('orders', extra='&search=HISTORY-000')
        self.assertEqual(len(history), 1)
        self.assertEqual(history[0]['number'], 'HISTORY-000')


if __name__ == '__main__':
    unittest.main()
