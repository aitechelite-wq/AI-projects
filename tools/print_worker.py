#!/usr/bin/env python3
"""Local, dependency-free ESC/POS queue worker for Windows USB or LAN printers.

No automatic retry: a failed or interrupted job needs an explicit operator decision.
Printer hardware varies. Test a nonfinancial slip before using this for service.
"""
from __future__ import annotations

import argparse
import ctypes
import getpass
import http.cookiejar
import ipaddress
import json
import os
import socket
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


class NotSent(Exception):
    """Transport could not start. No bytes were intentionally delivered."""


class UncertainDelivery(Exception):
    """Bytes may have reached the printer; human review is required."""


def safe_text(value: object) -> str:
    """ASCII ESC/POS text: strip device control codes, transliterate common accents."""
    value = str(value).replace("\r", " ").replace("\t", " ")
    value = "".join(ch for ch in value if ch == "\n" or (ord(ch) >= 32 and ord(ch) != 127))
    normalized = unicodedata.normalize("NFKD", value)
    normalized = "".join(ch for ch in normalized if not unicodedata.combining(ch))
    return normalized.encode("ascii", "replace").decode("ascii")


def wrap_text(value: object, width: int) -> list[str]:
    lines = []
    for paragraph in safe_text(value).split("\n"):
        words = paragraph.split()
        line = ""
        for word in words:
            if len(word) > width:
                if line:
                    lines.append(line)
                    line = ""
                while len(word) > width:
                    lines.append(word[:width])
                    word = word[width:]
            if len(line) + len(word) + bool(line) > width:
                lines.append(line)
                line = word
            else:
                line = line + (" " if line else "") + word
        lines.append(line)
    return lines


def amount(minor: object, currency: str = "PKR") -> str:
    value = int(minor or 0)
    sign = "-" if value < 0 else ""
    value = abs(value)
    return f"{safe_text(currency)} {sign}{value // 100:,}.{value % 100:02}"


def receipt_lines(payload: dict) -> list[str]:
    """Render immutable application snapshot; never execute markup or control bytes."""
    width = 32 if str(payload.get("receipt_width", "80")) == "58" else 48
    brand = payload.get("brand") or {}
    order = payload.get("order") or {}
    kind = payload.get("kind")
    currency = brand.get("currency", "PKR")
    separator = "-" * width
    lines: list[str] = []

    def add(value: object = "") -> None:
        lines.extend(wrap_text(value, width))

    def pair(label: object, value: object) -> None:
        left, right = safe_text(label), safe_text(value)
        if len(left) + len(right) + 1 <= width:
            lines.append(left + " " * (width - len(left) - len(right)) + right)
        else:
            add(left)
            lines.append(right[-width:].rjust(width))

    add(brand.get("cafe_name", "Cafe"))
    if kind != "kot":
        if brand.get("address"):
            add(brand["address"])
        if brand.get("phone"):
            add(brand["phone"])
    add(separator)
    add({"kot": "KITCHEN ORDER TICKET", "invoice": "FINAL INVOICE", "preorder": "PRE-ORDER / ORDER SLIP"}.get(kind, "ORDER SLIP"))
    add(f"Order: {order.get('number', '')}")
    if kind == "kot":
        add(f"KOT: {payload.get('ticket_number', payload.get('ticket_id', ''))}")
    if kind == "invoice":
        add(f"Invoice: {order.get('invoice_number', '')}")
    add(f"Date: {payload.get('created_at', order.get('created_at', ''))}")
    add(f"Type: {str(order.get('type', '')).replace('_', ' ')}")
    for key, label in (("table_name", "Table"), ("customer_name", "Customer"), ("customer_phone", "Phone"), ("due_at", "Due")):
        if order.get(key):
            add(f"{label}: {order[key]}")
    if payload.get("current_status", order.get("status")) == "void":
        add("*** VOIDED ORDER ***")
    if int(payload.get("current_refunded", 0)) > 0:
        add(f"Refunds issued: {amount(payload['current_refunded'], currency)}")
        add("This copy preserves the original invoice.")
    if order.get("notes"):
        add(f"Order note: {order['notes']}")
    add(separator)
    for item in payload.get("items", []):
        quantity = f"{float(item.get('quantity', 0)):.3f}".rstrip("0").rstrip(".")
        if kind == "kot":
            add(f"{quantity} x {item.get('name', '')}")
        else:
            add(item.get("name", ""))
            pair(f"{quantity} x {amount(item.get('price', 0), currency)}", amount(item.get("line_total", 0), currency))
            if order.get("tax_enabled") and float(item.get("tax_rate", 0)) > 0:
                add(f"Tax rate: {float(item['tax_rate']):g}%")
        if item.get("note"):
            add(f"  NOTE: {item['note']}")
    add(separator)
    if kind != "kot":
        pair("Subtotal", amount(order.get("subtotal", 0), currency))
        if int(order.get("discount", 0)):
            pair("Discount", amount(order.get("discount", 0), currency))
        pair("Tax" if order.get("tax_enabled") else "Tax (disabled)", amount(order.get("tax", 0), currency))
        pair("TOTAL", amount(order.get("total", 0), currency))
        pair("Paid at issue", amount(order.get("paid", 0), currency))
        pair("Balance at issue", amount(order.get("balance", 0), currency))
        for payment in payload.get("payments", []):
            is_refund = int(payment.get("amount", 0)) < 0 or payment.get("type") == "refund"
            label = ("Refund " if is_refund else "Paid ") + str(payment.get("method", ""))
            pair(label, amount(abs(int(payment.get("amount", 0))), currency))
            if payment.get("reference"):
                add(f"Reference: {payment['reference']}")
            if payment.get("tendered"):
                pair("Cash tendered", amount(payment["tendered"], currency))
                pair("Change", amount(payment.get("change_due", payment.get("change", 0)), currency))
        if kind == "preorder":
            add("ORDER SLIP - NOT A FINAL INVOICE")
            add("Bank/QR payments need staff confirmation.")
        if brand.get("footer"):
            add(brand["footer"])
    else:
        add("Prepare these quantities only.")
    return lines


def escpos_bytes(payload: dict) -> bytes:
    # Initialize, normal font/size, left aligned; cut command is ignored by no-cutter units.
    content = "\n".join(receipt_lines(payload)).encode("ascii", "replace")
    return b"\x1b@\x1ba\x00\x1b!\x00" + content + b"\n\n\n\x1dV\x00"


def print_network(host: str, data: bytes, timeout: float = 10.0) -> None:
    # Only local/private IPs, never DNS or public hosts. POS is offline by design.
    try:
        address = ipaddress.ip_address(host.strip())
    except ValueError as exc:
        raise NotSent("LAN host must be a private/local printer IP address.") from exc
    if not (address.is_private or address.is_loopback or address.is_link_local) or address.is_unspecified or address.is_multicast:
        raise NotSent("LAN printer must use a private/local unicast IP address.")
    try:
        connection = socket.create_connection((str(address), 9100), timeout=timeout)
    except OSError as exc:
        raise NotSent(f"Cannot connect to LAN printer: {exc}") from exc
    try:
        # sendall failure may mean a prefix was sent. Never automatically replay it.
        connection.sendall(data)
    except OSError as exc:
        raise UncertainDelivery(f"LAN connection interrupted; receipt may be partially printed: {exc}") from exc
    finally:
        connection.close()
    # TCP acceptance means delivered to the device, not paper-out/physical confirmation.


def windows_api():
    if sys.platform != "win32":
        raise NotSent("Windows spool printing requires Windows; use LAN transport here.")
    from ctypes import wintypes
    spool = ctypes.WinDLL("winspool.drv", use_last_error=True)

    class DOC_INFO_1(ctypes.Structure):
        _fields_ = [("pDocName", wintypes.LPWSTR), ("pOutputFile", wintypes.LPWSTR), ("pDatatype", wintypes.LPWSTR)]

    spool.OpenPrinterW.argtypes = [wintypes.LPWSTR, ctypes.POINTER(wintypes.HANDLE), ctypes.c_void_p]
    spool.OpenPrinterW.restype = wintypes.BOOL
    spool.StartDocPrinterW.argtypes = [wintypes.HANDLE, wintypes.DWORD, ctypes.c_void_p]
    spool.StartDocPrinterW.restype = wintypes.DWORD
    spool.StartPagePrinter.argtypes = [wintypes.HANDLE]
    spool.StartPagePrinter.restype = wintypes.BOOL
    spool.WritePrinter.argtypes = [wintypes.HANDLE, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(wintypes.DWORD)]
    spool.WritePrinter.restype = wintypes.BOOL
    for method in ("EndPagePrinter", "EndDocPrinter", "AbortPrinter", "ClosePrinter"):
        getattr(spool, method).argtypes = [wintypes.HANDLE]
        getattr(spool, method).restype = wintypes.BOOL
    return spool, DOC_INFO_1, wintypes


def print_windows(name: str, data: bytes, title: str) -> None:
    if not name.strip():
        raise NotSent("Set the exact installed Windows printer name in POS Settings.")
    spool, doc_type, wintypes = windows_api()
    handle = wintypes.HANDLE()
    if not spool.OpenPrinterW(name, ctypes.byref(handle), None):
        raise NotSent(f"Cannot open Windows printer {name!r}; Windows error {ctypes.get_last_error()}.")
    started = False
    try:
        document = doc_type(title, None, "RAW")
        if not spool.StartDocPrinterW(handle, 1, ctypes.byref(document)):
            raise NotSent(f"Cannot start Windows spool job; Windows error {ctypes.get_last_error()}.")
        started = True
        if not spool.StartPagePrinter(handle):
            raise UncertainDelivery(f"Cannot start spool page; Windows error {ctypes.get_last_error()}.")
        written = wintypes.DWORD(0)
        buffer = ctypes.create_string_buffer(data)
        if not spool.WritePrinter(handle, buffer, len(data), ctypes.byref(written)) or written.value != len(data):
            raise UncertainDelivery(f"Incomplete spool write ({written.value}/{len(data)} bytes); Windows error {ctypes.get_last_error()}.")
        if not spool.EndPagePrinter(handle) or not spool.EndDocPrinter(handle):
            raise UncertainDelivery(f"Cannot finalize spool document; Windows error {ctypes.get_last_error()}.")
        started = False
    finally:
        if started:
            spool.AbortPrinter(handle)
        spool.ClosePrinter(handle)


def list_windows_printers() -> list[str]:
    spool, _, wintypes = windows_api()

    class PRINTER_INFO_4(ctypes.Structure):
        _fields_ = [("pPrinterName", wintypes.LPWSTR), ("pServerName", wintypes.LPWSTR), ("Attributes", wintypes.DWORD)]

    spool.EnumPrintersW.argtypes = [wintypes.DWORD, wintypes.LPWSTR, wintypes.DWORD, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(wintypes.DWORD), ctypes.POINTER(wintypes.DWORD)]
    spool.EnumPrintersW.restype = wintypes.BOOL
    needed, returned = wintypes.DWORD(), wintypes.DWORD()
    spool.EnumPrintersW(6, None, 4, None, 0, ctypes.byref(needed), ctypes.byref(returned))
    if not needed.value:
        return []
    buffer = ctypes.create_string_buffer(needed.value)
    if not spool.EnumPrintersW(6, None, 4, buffer, needed.value, ctypes.byref(needed), ctypes.byref(returned)):
        raise NotSent(f"Unable to list printers; Windows error {ctypes.get_last_error()}.")
    records = ctypes.cast(buffer, ctypes.POINTER(PRINTER_INFO_4))
    return [records[index].pPrinterName for index in range(returned.value)]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        raise RuntimeError("POS API redirected; refusing to forward the worker token.")


class Api:
    def __init__(self, url: str, token: str):
        parts = urllib.parse.urlsplit(url)
        if parts.scheme != "http" or parts.hostname not in ("127.0.0.1", "localhost", "::1") or parts.username or parts.password or parts.query or parts.fragment:
            raise ValueError("POS URL must be a loopback HTTP URL, for example http://127.0.0.1:8080.")
        self.url = url.rstrip("/")
        self.token = token
        # Ignore ambient proxy settings so the bearer token stays on this laptop.
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect(),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        )

    def post(self, route: str, data: dict) -> dict:
        request = urllib.request.Request(
            self.url + "/index.php?api=" + urllib.parse.quote(route, safe="/"),
            data=json.dumps(data).encode("utf-8"), method="POST",
            headers={"Content-Type": "application/json", "Authorization": "Bearer " + self.token},
        )
        try:
            with self.opener.open(request, timeout=15) as response:
                result = json.loads(response.read(2 * 1024 * 1024).decode("utf-8"))
        except urllib.error.HTTPError as exc:
            # Never dump request headers, config, or token.
            try:
                message = json.loads(exc.read().decode("utf-8")).get("error", "API request failed")
            except (ValueError, UnicodeError):
                message = "API request failed"
            raise RuntimeError(f"POS API HTTP {exc.code}: {message}") from None
        if not result.get("ok"):
            raise RuntimeError(str(result.get("error", "POS API rejected the request.")))
        return result.get("data") or {}


def process_job(api: Api, job: dict) -> str:
    status, error = "printed", ""
    try:
        payload = job["payload"]
        destination = job.get("destination") or {}
        data = escpos_bytes(payload)
        if destination.get("transport") == "network":
            print_network(destination.get("host", ""), data)
        elif destination.get("transport") == "windows":
            print_windows(destination.get("name", ""), data, f"Cafe POS {job.get('kind', '')} #{job['id']}")
        else:
            raise NotSent("Printer transport is not configured.")
    except NotSent as exc:
        status, error = "failed", str(exc)
    except Exception as exc:
        status, error = "uncertain", str(exc)
    try:
        api.post("print/ack", {"id": job["id"], "claim_token": job["claim_token"], "status": status, "error": error[:1000]})
    except Exception as exc:
        # Never resend a physically submitted receipt after an acknowledgement failure.
        print(f"Job {job['id']}: acknowledgement failed ({exc}). Delivery may have occurred; review Print queue.", file=sys.stderr)
        return "uncertain"
    if error:
        print(f"Job {job['id']}: {status}: {error}", file=sys.stderr)
    else:
        print(f"Job {job['id']}: submitted to printer. Check paper/output on device.")
    return status


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default="http://127.0.0.1:8080")
    parser.add_argument("--token-file", type=Path, help="UTF-8 text file outside public/ containing the worker token")
    parser.add_argument("--poll", type=float, default=2.0)
    parser.add_argument("--once", action="store_true", help="Claim at most one queued job and exit")
    parser.add_argument("--list-printers", action="store_true", help="List Windows installed printer names and exit")
    args = parser.parse_args(argv)
    if args.list_printers:
        try:
            for name in list_windows_printers():
                print(name)
            return 0
        except NotSent as exc:
            print(str(exc), file=sys.stderr)
            return 1
    try:
        token = args.token_file.read_text(encoding="utf-8-sig").strip() if args.token_file else os.environ.get("CAFE_PRINT_TOKEN", "").strip()
        if not token:
            token = getpass.getpass("POS worker token (hidden; create once in Settings > Printers): ").strip()
        if not token:
            raise ValueError("A worker token is required.")
        api = Api(args.url, token)
        if args.poll < 0.5:
            raise ValueError("Poll interval must be at least 0.5 seconds.")
    except (OSError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print("Cafe print worker connected to local POS. Ctrl+C stops it. Failed/uncertain jobs are never retried automatically.")
    try:
        while True:
            try:
                response = api.post("print/claim", {})
                job = response.get("job")
                if job:
                    status = process_job(api, job)
                    if args.once:
                        return 0 if status == "printed" else 1
                elif args.once:
                    print("No queued print jobs.")
                    return 0
            except Exception as exc:
                print(f"Worker: {exc}", file=sys.stderr)
                if args.once:
                    return 1
            time.sleep(args.poll)
    except KeyboardInterrupt:
        print("\nPrint worker stopped. Interrupted claimed jobs require queue review.")
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
