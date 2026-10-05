"""Optional UI smoke test: requires Python Playwright and a Chromium installation.

Runs with an isolated temporary database. No café data or hardware is touched.
CAFE_PHP and CAFE_CHROMIUM can select locally installed executables.
"""
import base64
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('CAFE_PHP') or shutil.which('php') or '/workspace/.php-runtime/bin/php'
CHROMIUM = os.environ.get('CAFE_CHROMIUM') or shutil.which('chromium')
OUTPUT = ROOT / '.test-results'
OUTPUT.mkdir(exist_ok=True)

with tempfile.TemporaryDirectory(prefix='cafe-browser-') as data:
    sock = socket.socket()
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
    sock.close()
    log = open(Path(data) / 'server.log', 'w+')
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(ROOT / 'public')], env={**os.environ, 'CAFE_DATA_DIR': data}, stdout=log, stderr=log)
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True, **({'executable_path': CHROMIUM} if CHROMIUM else {}), args=['--no-sandbox'])
            context = browser.new_context(viewport={'width': 1366, 'height': 768})
            page = context.new_page()
            errors, external = [], []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.on('request', lambda request: external.append(request.url) if not request.url.startswith((f'http://127.0.0.1:{port}', 'data:', 'blob:')) else None)
            time.sleep(.25)
            page.goto(f'http://127.0.0.1:{port}', wait_until='networkidle')
            page.locator('[name=cafe_name]').fill('Ember Café')
            page.locator('[name=name]').fill('Test Owner')
            page.locator('[name=username]').fill('testowner')
            page.locator('[name=password]').fill('BrowserTest!2026')
            page.locator('[name=demo]').check()
            page.locator('#auth-form button[type=submit]').click()
            page.wait_for_selector('#cart-panel')
            page.screenshot(path=str(OUTPUT / 'pos.png'), full_page=True)

            def navigate(target, selector):
                page.locator(f'[data-page={target}]').click()
                page.wait_for_selector(selector)
                page.wait_for_timeout(100)
                assert page.locator('.error-state').count() == 0, target

            navigate('shifts', '#shift-open')
            page.locator('#shift-open').click()
            page.locator('[name=opening_cash]').fill('1000')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('#shift-close')

            navigate('pos', '[data-add]')
            page.locator('[data-add]').first.click()
            page.locator('[data-add]').nth(1).click()
            page.locator('#cart-primary').fill('Table 4')
            page.locator('[data-note]').first.click()
            page.locator('[name=note]').fill('No sugar')
            page.locator('#modal-form button[type=submit]').click()
            page.locator('#send-order').click()
            page.wait_for_function('() => POS.state.order && POS.state.order.items[0].sent_quantity > 0')
            assert page.evaluate('POS.state.order.items[0].note') == 'No sugar'
            page.locator('#pay-order').click()
            page.wait_for_selector('#payment-method')
            page.locator('[name=tendered]').fill('2000')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_function('() => POS.state.order.status === "paid"')
            assert page.evaluate('POS.state.order.invoice_number').startswith('INV-')
            invoice = page.evaluate('POS.state.order.id')

            checks = {'orders': '#orders-list', 'kitchen': '#kitchen-refresh', 'inventory': '#stock-add', 'menu': '#menu-add', 'reports': '#export-report', 'shifts': '#shift-close', 'settings': '#branding-form'}
            for target, selector in checks.items():
                navigate(target, selector)
                assert page.locator('#page-content h1').count() == 1
                page.screenshot(path=str(OUTPUT / f'{target}.png'), full_page=True)

            # Dynamic stock, recipes and receiving accounts use the real forms.
            navigate('inventory', '#stock-add')
            page.locator('#stock-add').click()
            page.locator('#modal-form [name=name]').fill('Cocoa powder')
            page.locator('#modal-form [name=min_stock]').fill('20')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('#modal-form', state='detached')
            cocoa = page.locator('tr').filter(has_text='Cocoa powder').first
            cocoa.get_by_role('button', name='Movement').click()
            page.locator('#modal-form [name=quantity]').fill('100')
            page.locator('#modal-form [name=cost_total]').fill('300')
            page.locator('#modal-form [name=reference]').fill('Test supplier delivery')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('#modal-form', state='detached')
            cocoa_id = page.evaluate('POS.state.inventory.items.find(i => i.name === "Cocoa powder").id')

            navigate('menu', '#menu-add')
            page.locator('#menu-add').click()
            page.locator('#modal-form [name=name]').fill('Hot cocoa')
            page.locator('#modal-form [name=price]').fill('200')
            page.locator('#modal-form [name=tax_rate]').fill('5')
            page.locator('#recipe-add').click()
            page.locator('[data-recipe-stock]').select_option(str(cocoa_id))
            page.locator('[data-recipe-qty]').fill('10')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('#modal-form', state='detached')
            assert page.locator('tr').filter(has_text='Hot cocoa').count() == 1

            navigate('settings', '#branding-form')
            page.locator('[data-settings-tab=payments]').click()
            page.wait_for_selector('[data-account-add]')
            page.locator('[data-account-add]').click()
            page.locator('#modal-form [name=name]').fill('Test bank')
            page.locator('#modal-form [name=method]').select_option('bank')
            page.locator('#modal-form [name=details]').fill('Test receiving account')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('[data-account-qr]')
            page.locator('[data-account-qr]').click()
            png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZc8AAAAASUVORK5CYII=')
            page.locator('#modal-form [name=file]').set_input_files({'name': 'test-qr.png', 'mimeType': 'image/png', 'buffer': png})
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('#modal-form', state='detached')
            assert page.locator('.account-qr img').count() == 1

            navigate('pos', '#cart-panel')
            page.locator('#new-order').click()
            page.locator('[data-add]').filter(has_text='Hot cocoa').click()
            page.locator('#pay-order').click()
            page.wait_for_selector('#payment-method')
            page.locator('#payment-method').select_option('bank')
            page.locator('#payment-account').select_option(label='Test bank · bank')
            assert page.locator('#payment-qr img').count() == 1
            page.locator('#modal-form [name=reference]').fill('TEST-BANK-001')
            page.locator('#modal-form [name=confirmed]').check()
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_function('() => POS.state.order.status === "paid"')
            assert page.evaluate('POS.state.order.total') == 21000
            assert page.evaluate('() => POS.api("inventory").then(d => d.items.find(i => i.name === "Cocoa powder").on_hand)') == 90

            navigate('reports', '#export-report')
            with page.expect_download() as download:
                page.locator('#export-report').click()
            exported = Path(download.value.path()).read_text(encoding='utf-8-sig')
            assert 'STOCK RECONCILIATION' in exported and 'Cocoa powder' in exported
            navigate('shifts', '#shift-close')
            page.locator('#shift-close').click()
            page.locator('#modal-form [name=counted_cash]').fill('2100')
            page.locator('#modal-form button[type=submit]').click()
            page.wait_for_selector('#shift-open')
            assert page.evaluate('() => POS.api("shifts").then(d => d.history[0].discrepancy)') == 0

            navigate('settings', '[data-settings-tab=branding]')
            page.locator('[data-settings-tab=branding]').click()
            page.wait_for_selector('#branding-form')

            page.locator('#branding-form [name=cafe_name]').fill('Willow Café')
            page.locator('#branding-form [name=address]').fill('Main Street, Lahore')
            page.locator('#branding-form button[type=submit]').click()
            page.wait_for_function('() => POS.session.settings.cafe_name === "Willow Café"')
            assert 'Willow Café' in page.locator('.brand').inner_text()
            for tab in ['payments', 'printers', 'team', 'audit', 'backups']:
                page.locator(f'[data-settings-tab={tab}]').click()
                page.wait_for_timeout(250)
                assert page.locator('.error-state').count() == 0, tab
                page.screenshot(path=str(OUTPUT / f'settings-{tab}.png'), full_page=True)

            receipt = page.context.new_page()
            receipt.goto(f'http://127.0.0.1:{port}/print.php?kind=invoice&id={invoice}')
            assert 'Final invoice' in receipt.locator('h2').text_content()
            assert 'Ember Café' in receipt.locator('h1').inner_text(), 'Issued branding must survive later rebranding'
            assert receipt.locator('.receipt-item').count() == 2
            receipt.screenshot(path=str(OUTPUT / 'invoice.png'), full_page=True)
            receipt.close()

            navigate('pos', '#cart-panel')
            page.set_viewport_size({'width': 390, 'height': 844})
            page.wait_for_timeout(100)
            assert not page.evaluate('document.documentElement.scrollWidth > innerWidth'), 'Mobile horizontal overflow'
            page.screenshot(path=str(OUTPUT / 'mobile.png'), full_page=True)
            assert not errors, errors
            assert not external, external
            print(json.dumps({'result': 'passed', 'checks': ['owner setup', 'demo menu', 'cash shift', 'order notes', 'KOT', 'payment and invoice', *checks.keys(), 'raw item and receipt', 'menu and recipe', 'bank account and QR upload', 'manual bank payment', 'CSV export', 'shift closing', 'settings tabs', 'rebranding', 'immutable invoice', 'mobile layout', 'no external requests', 'no JavaScript errors'], 'screenshots': str(OUTPUT)}))
            browser.close()
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
