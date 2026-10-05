# Validation

The application was tested on Debian with PHP 8.4.26 and SQLite. Browser checks used Chromium at laptop and narrow-screen sizes. Windows printer calls were tested with a mocked spooler, and LAN transports with mocked sockets. No physical printer was attached.

## Automated checks

- `python3 -m unittest discover -s tests -v`: HTTP integration tests create isolated temporary databases. They cover authentication, CSRF, roles, taxes and discounts, KOT deltas, insufficient stock rollback, split payments, deposits, fully prepaid future orders, cash change, invoices, refunds, physical counts, stock valuation, reports, shift differences, image uploads, consistent backups, print claims and financial retry safety.
- `python3 tools/test_print_worker.py`: 17 tests check receipt content and quantity precision, control-code filtering, Windows spooler failures and partial writes, LAN destination restrictions, uncertain delivery and acknowledgement failures without automatic replay.
- `python3 tests/browser_smoke.py`: actual forms create an owner, menu/recipes, stock receipts and a bank/QR account; dispatch a KOT; record cash and bank payments; export CSV; close a shift; edit branding; inspect the issued invoice; and traverse management/settings screens. Checks fail on external requests, JavaScript errors, or horizontal mobile overflow.
- PHP files pass `php -l`; local JavaScript files pass `node --check`; shell launchers pass `bash -n`.
- The retained PHP runtime and `tools/start-pos.sh` were exercised, including a clean first-owner startup on the default local port.

## Deployment checks on your laptop

Install PHP and both printers' signed Windows drivers, then follow README.md and PRINTING.md. Run a sample KOT and invoice through each actual printer before service. Confirm receipt width, quantities, tax treatment, tendered/change amounts and routing. RAW ESC/POS branding is text; logos require the browser/driver printing path. The app never verifies bank transfers automatically: staff checks receipt in the receiving account.

Take a consistent database backup and copy uploaded logo/QR files separately. Confirm restoration into a separate data directory before depending on the backup procedure. Printer processes and PHP must restart after reboot. Cloud configuration saving and publication are separate; a saved draft does not establish fresh-machine restoration.
