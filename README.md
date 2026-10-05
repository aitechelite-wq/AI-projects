# Ember POS — offline café management

A local PHP, CSS and vanilla JavaScript café POS. SQLite stores the café's data on your laptop. No CDN, cloud account, payment gateway, or internet connection is required to use the app. Your café name, logo, address, phone, receipt footer and accent colour are editable.

## Run on Windows 10 / 11

1. Download the PHP 8.2+ **x64 non-thread-safe ZIP** from [PHP for Windows](https://windows.php.net/download/) while preparing the laptop. Install the Microsoft Visual C++ runtime requested by that PHP release. Extract PHP into `C:\php`. Alternatively use an existing XAMPP PHP installation.
2. Copy `php.ini-development` to `php.ini` inside the PHP folder. Set `extension_dir="ext"`, enable `extension=pdo_sqlite` and `extension=fileinfo`. These extensions are included in the PHP ZIP; no Composer packages are needed.
3. Copy this project to a writable folder such as `C:\CafePOS`. Double-click `tools\start-pos.cmd`. Keep its command window open while using the POS. The launcher checks the runtime and opens the app in your browser.
4. On first launch create your owner account and password. Choose the optional demo menu only for evaluation. A live café should start with an empty database and enter its own menu, recipes, stock and prices.
5. In **Settings**, enter the café's branding, tax defaults, payment accounts and QR images. Install both printers' Windows drivers, configure their printer names, and follow [the printing guide](docs/PRINTING.md).

The server listens only on the laptop's loopback interface. Do not change it to `0.0.0.0` or expose it on the internet. Windows startup shortcuts may launch `tools\start-pos.cmd`; the server and print worker must be restarted after reboot. Put the laptop on AC power and prevent sleep during service. One laptop is the supported installation; this is not a cloud or multi-branch system.

## Café workflow

- **Menu & recipes:** create categories and unlimited menu items, prices and item tax rates. Attach ingredients in the raw item's unit: grams, millilitres, pieces, etc. Packaged sale items can use their own stock recipe; items without a recipe do not deduct stock.
- **Inventory:** receive purchases with their total cost, set low-stock thresholds, record wastage, and enter physical stock counts. Every adjustment remains in the stock ledger. Opening stock must be entered as a receipt, not assumed from a menu recipe.
- **Orders:** dine-in, takeaway and scheduled preorders; customer details, table, preparation notes, per-bill tax switch and discounts. Save orders and print preorder slips, then send to the kitchen when preparation should begin. Active orders and pending prepaid preorders stay visible alongside recent completed orders; search reaches older invoices and customer history.
- **Kitchen:** KOTs contain the newly sent quantities and preparation notes. Stock is consumed atomically on dispatch. Insufficient stock blocks the complete dispatch. Re-sending an unchanged order does not consume ingredients twice. Prepared quantities cannot be silently removed from an order.
- **Payments:** cash with tendered/change amounts, split payments and preorder deposits; Easypaisa, JazzCash and bank payments use your own QR images and account details. The cashier must check receipt of funds and enter a transaction reference. A displayed QR code does not verify a transfer. Customers use their own banking apps; the POS makes no bank requests.
- **Invoices & refunds:** invoice numbers are assigned after full settlement. Refunds require a manager and a reason. Refunds and voids retain their audit trail; prepared ingredients are not put back into stock. A deposited order must be refunded before voiding.
- **Cash shifts:** opening float, receipts, cash refunds, cash expenses, expected cash, physical closing count and discrepancy. The shift log supports investigation of differences.
- **Reports:** daily, monthly, half-year and custom periods; gross sales, tax, refunds, payment collections, expenses, recipe usage, stock reconciliation and cost estimates; CSV exports. Collections and sales differ when deposits and invoices occur in different periods.
- **Management:** owner/manager/cashier access, password-protected staff accounts, branding, payment account QR uploads, stock alerts, print queue diagnostics, audit history, and database backups.

Taxes are configurable, exclusive of the menu price, and calculated by the server after the discount. Set zero rates or disable tax on the bill for no-tax receipts. Confirm the rates and invoice requirements applicable to your café before real use. These local invoices do not integrate with a tax authority or fiscal device.

## Backups and recovery

Download regular database snapshots from **Settings → Backups** and copy them to another drive. This produces a consistent SQLite backup. Café logos and QR images are stored separately under `data/uploads`; keep a copy of that folder too. Staff password hashes and business records are in the database, so protect backup files and the laptop with Windows account security and disk encryption.

For a full file backup, stop the POS and print worker, then copy the complete `data` folder. To restore, stop both processes, preserve the current data folder, restore the known-good database to `data/cafe.sqlite` and restore matching uploads. Remove stale `cafe.sqlite-wal` / `cafe.sqlite-shm` files only after the server has stopped and the replacement database is in place. Start the POS and check inventory, invoices and printer settings before resuming sales. Downloaded backups omit the printer worker token and invalidate in-progress print claims: create a new token, restart the worker, and review pending jobs before printing. A database backup is not a substitute for saving uploaded images.

Use a fresh, separate `CAFE_DATA_DIR` when evaluating the demo or restoring for inspection. Never use the test database for live sales. The owner can reset staff passwords in Team settings. Do not delete live data to recover a forgotten owner password; preserve a full backup and use a reviewed local recovery procedure.

## Development and validation

Requirements: PHP 8.2+ with PDO SQLite and fileinfo. No npm or Composer install.

```sh
php tools/check-runtime.php
bash tools/start-pos.sh
python3 -m unittest discover -s tests -v
python3 tools/test_print_worker.py
```

Optional browser checks use Python Playwright and Chromium: `python3 tests/browser_smoke.py`. They exercise first-time setup, a cash shift, KOT dispatch, payment, management screens, rebranding, an issued invoice and a narrow screen, and fail on external requests or JavaScript errors. These tools are only needed for development; the POS itself has no browser automation dependency.

For Codex's non-root Debian environment, `bash tools/setup-cloud.sh` prepares an isolated PHP runtime under `/workspace/.php-runtime` using signed Debian package indexes and checksum-verified packages. The existing project checkout is used directly; a worktree is unnecessary. Set `CAFE_PHP` if PHP is installed at a custom path.

Application code lives in `app/`, public files in `public/`, operational tools in `tools/`. Only `public/` is the document root. SQLite data and uploaded images live outside it. `CAFE_DATA_DIR` overrides the data location for isolated tests. Integration tests create fresh databases and exercise real HTTP requests without altering café data.

The print worker has separate Windows spooler and LAN ESC/POS transports. Actual cutter, encoding, cash drawer and printer driver behaviour depend on the printer model. Verify both physical printers with a sample KOT and customer invoice before live service. A job acknowledged by the Windows spooler is accepted for printing; it does not establish that paper emerged.
