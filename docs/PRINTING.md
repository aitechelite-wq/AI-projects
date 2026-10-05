# Two thermal printers on a Windows laptop

Use one installed Windows printer queue for the kitchen and one for customer receipts. The POS runs entirely on the laptop. A small Python worker reads its local print queue and submits ESC/POS data to the selected printer. It has no Python package dependencies and needs no internet once Python and printer drivers are installed.

**Hardware has not been tested with this project.** Choose printers that support RAW ESC/POS (often sold as Epson-compatible), and test both printers before taking live orders. Many inexpensive USB thermal printers work this way; some Windows-only, GDI, proprietary or Bluetooth printers do not. For those, use the browser print dialog with the manufacturer's Windows driver, or select supported hardware. Windows 10/11 is supported by the worker; install a supported Python 3 release separately.

## Set up USB printers

1. Install the manufacturer's signed Windows driver for both printers. Give them distinct names, for example `Cafe Kitchen` and `Cafe Customer`. Print each driver's test page. Confirm which physical printer receives it.
2. Open the café POS at `http://127.0.0.1:8080`, sign in as owner, and open **Settings → Printers**.
3. Set each transport to **Windows printer (USB)** and enter its exact installed printer name. The same mode also works for LAN printers installed as Windows printer queues. Select 58 mm or 80 mm receipt width to match your paper.
4. From a terminal in the project folder, run:

   ```bat
   py -3 tools\print_worker.py --list-printers
   ```

   Copy the exact reported names into POS Settings. A USB port name such as `USB001` is not the printer queue name.
5. Use **Create / rotate worker token** as owner. The secret appears once. The server saves only its hash. Keep the POS open, then run:

   ```bat
   tools\start-printers.cmd
   ```

   Paste the token at the worker's hidden prompt. Keep that terminal running while the café is open. Do not paste secrets into menu item names, notes or shared screenshots. Rotating the token invalidates the previous worker token.

The launcher prefers the Windows `py -3` launcher, then `python`. If neither exists, install Python 3 using the official Windows installer, select its Python launcher option, reopen the terminal and retry.

## Set up direct LAN printing

Use a dedicated local network or router even when its internet cable is disconnected. Connect the laptop and printers to it. Give printers reserved/static addresses so they do not change.

In Settings, choose **LAN / ESC/POS** for each printer, and enter its private IP address, for example `192.168.1.50`. The worker uses TCP port 9100. Public addresses, DNS names, unspecified addresses and multicast addresses are rejected. A printer on a routed public network is outside this offline configuration. Use the same token and worker instructions above.

If a manufacturer driver is required, install the LAN printer as a Windows queue and use Windows transport instead. Do not expose port 8080 or port 9100 on the internet.

## Test the complete workflow

Use an explicitly created test order and review its accounting impact; a sent KOT consumes recipe ingredients. For clean trials, initialize a separate test data directory or use the opt-in sample café before real trading.

1. Save an order with a line note such as “no sugar,” send it to the kitchen, and verify that only the kitchen printer prints the KOT with its note and ticket number.
2. Add another item to the same open order, then send again. The second KOT must contain only additions. Previously sent quantities are never resent automatically.
3. Open **Order slip** from the order details and print it to the customer printer using the browser dialog. This is clearly marked as a slip, not a final invoice. It can be used for preorders and deposits.
4. Record a payment after checking any bank / Easypaisa / JazzCash transfer in your receiving account. Fully paying the order queues the final invoice to the customer printer. Verify totals, item tax rates, any discount, cash tendered and change.
5. Check the physical printer output, not only the status in the POS. Test both a tax-enabled and a tax-disabled bill.
6. Turn off a printer and send a test job. Review **Settings → Printers → Queue**. Restore the printer, check whether Windows already retained the job, and explicitly retry only if a new copy is needed.

The term `printed` in the queue means the worker submitted bytes to Windows or the LAN device. It does not certify that paper emerged. Windows may retain and later print an offline spool job. Resolve paper, cover, cutter and driver errors at the device / Windows queue before reprinting.

## Queue safety and manual reprints

Failed or uncertain jobs are **never retried automatically**. A disconnect may occur after some or all of the receipt reached the device. A worker crash or missing acknowledgement becomes `uncertain` after two minutes. Check physical receipts and the Windows print queue before using **Retry**; this can produce a duplicate. Manual retry records an audit entry and keeps the original receipt snapshot.

KOT snapshots preserve the quantities and notes issued in that ticket. Invoice snapshots preserve the original menu prices, tax and branding at issue time. Invoice reprints also show refunds issued since that invoice. A voided order's queued KOT is cancelled; already printed or already submitted instructions require the staff to tell the kitchen about cancellation. Prepared ingredient usage is not silently restored.

Browser reprints work independently of the worker. They are staff-authenticated, do not auto-open a print dialog, and print to the printer you explicitly choose. Reprints can also duplicate an original job which is still queued, so review the queue first.

## Branded receipts, paper and language

Edit the café name, logo, address, contact details, currency and footer in **Settings → Brand**. The application and browser receipts use the uploaded logo. The dependency-free RAW adapter prints branding as text; it does not rasterize logo images or payment QR images. Payment QR images are shown in the checkout screen for customers to scan.

Browser receipts support 58 mm and 80 mm layouts. In the browser print dialog choose the matching paper size in the driver, no margins, 100% scale, and disable browser headers and footers. Paper length comes from the printer driver; the browser cannot configure a Windows device's paper form or silently select two different printers. The worker avoids browser dialogs and routes the KOT and final invoice separately.

The RAW adapter uses safe ASCII, transliterates Latin accents and replaces unsupported characters with `?`. It strips injected ESC/POS control codes from item names and notes. Urdu / Arabic shaping and other non-Latin scripts require browser printing with a compatible driver, or a separate hardware-specific raster/code-page implementation. Cutter commands are ignored on printers without a cutter; test your unit's ESC/POS implementation.

## Optional unattended worker startup

The hidden token prompt is the default. If you need Windows Task Scheduler to start the worker without a prompt, save just the token into a UTF-8 text file outside `public/`, for example `data\printer-token.txt`, and protect it using Windows file permissions so only the café operator account can read it. Do not commit it, copy it into backups intended for sharing, or put it in a command-line argument.

```bat
py -3 tools\print_worker.py --token-file data\printer-token.txt
```

Alternatively, supply `CAFE_PRINT_TOKEN` in the worker's environment. Neither mode prints the token. To change the local POS port, pass `--url http://127.0.0.1:8081`; only loopback URLs are accepted. The worker ignores proxy environment settings and refuses redirects so its token stays on this laptop. Start the PHP server before starting the worker. Run only one worker for this POS database.

For diagnosis, `--once` claims at most one job and exits. `python tools/test_print_worker.py` runs hardware-free tests for receipt content, transport failures, partial writes, acknowledgements and duplicate avoidance. These tests do not substitute for physical printer validation.
