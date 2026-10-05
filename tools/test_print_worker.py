#!/usr/bin/env python3
"""Hardware-free transport and safety tests. These do not certify a printer model."""
import ctypes
from ctypes import wintypes
import io
import socket
import unittest
from unittest.mock import Mock, patch

import print_worker as worker


def snapshot(kind="invoice"):
    return {
        "kind": kind, "receipt_width": "58", "created_at": "2026-10-05T17:00:00+05:00",
        "brand": {"cafe_name": "Café Test", "currency": "PKR", "footer": "Thanks!"},
        "order": {"number": "ORD-1", "invoice_number": "INV-1", "type": "takeaway", "status": "paid", "subtotal": 10000, "discount": 0, "tax": 1000, "total": 11000, "paid": 11000, "balance": 0, "tax_enabled": True},
        "items": [{"name": "Coffee", "quantity": 2, "price": 5000, "line_total": 10000, "tax_rate": 10, "note": "No sugar"}],
        "payments": [{"method": "cash", "amount": 11000, "type": "payment", "tendered": 12000, "change_due": 1000}],
    }


class ReceiptTests(unittest.TestCase):
    def test_money_integer_minor_units_and_change(self):
        text = "\n".join(worker.receipt_lines(snapshot()))
        self.assertIn("PKR 110.00", text)
        self.assertRegex(text, r"Change\s+PKR 10\.00")
        self.assertIn("Tax rate: 10%", text)
        self.assertTrue(all(len(line) <= 32 for line in text.splitlines()))

    def test_untrusted_content_cannot_inject_device_commands(self):
        payload = snapshot()
        payload["items"][0]["name"] = "Bad\x1b@\x1dV\x00\x07\x7fname"
        data = worker.escpos_bytes(payload)
        body = data[len(b"\x1b@\x1ba\x00\x1b!\x00"):-len(b"\n\n\n\x1dV\x00")]
        for control in (0, 7, 27, 29, 127):
            self.assertNotIn(control, body)
        self.assertIn(b"Cafe Test", body)

    def test_kot_contains_delta_quantity_and_notes_without_prices(self):
        payload = snapshot("kot")
        payload["ticket_number"] = "KOT-2"
        payload["items"][0]["quantity"] = 1
        text = "\n".join(worker.receipt_lines(payload))
        self.assertIn("1 x Coffee", text)
        self.assertIn("NOTE: No sugar", text)
        self.assertIn("KOT-2", text)
        self.assertNotIn("PKR", text)
        self.assertNotIn("FINAL INVOICE", text)

    def test_invoice_reprint_announces_refunds(self):
        payload = snapshot()
        payload["current_refunded"] = 2500
        text = "\n".join(worker.receipt_lines(payload))
        self.assertIn("Refunds issued: PKR 25.00", text)

    def test_large_quantity_preserves_thousandths(self):
        payload = snapshot("kot")
        payload["items"][0]["quantity"] = 100000.001
        self.assertIn("100000.001 x Coffee", "\n".join(worker.receipt_lines(payload)))


class NetworkTests(unittest.TestCase):
    def test_connection_failure_is_not_sent(self):
        with patch.object(worker.socket, "create_connection", side_effect=socket.timeout("offline")):
            with self.assertRaises(worker.NotSent):
                worker.print_network("192.168.1.50", b"receipt")

    def test_failed_send_is_uncertain_and_socket_closed(self):
        connection = Mock()
        connection.sendall.side_effect = OSError("connection closed after some bytes")
        with patch.object(worker.socket, "create_connection", return_value=connection):
            with self.assertRaises(worker.UncertainDelivery):
                worker.print_network("192.168.1.50", b"receipt")
        connection.close.assert_called_once()

    def test_success_delivers_exact_bytes(self):
        connection = Mock()
        with patch.object(worker.socket, "create_connection", return_value=connection) as connect:
            worker.print_network("192.168.1.50", b"receipt")
        connect.assert_called_once_with(("192.168.1.50", 9100), timeout=10.0)
        connection.sendall.assert_called_once_with(b"receipt")
        connection.close.assert_called_once()

    def test_public_host_or_dns_never_connects(self):
        with patch.object(worker.socket, "create_connection") as connect:
            for host in ("8.8.8.8", "example.com", "0.0.0.0", "224.0.0.1"):
                with self.assertRaises(worker.NotSent):
                    worker.print_network(host, b"receipt")
        connect.assert_not_called()


class WorkerTests(unittest.TestCase):
    def job(self):
        return {"id": 9, "claim_token": "secret-claim", "kind": "invoice", "payload": snapshot(), "destination": {"transport": "network", "host": "192.168.1.50"}}

    def test_failed_transport_acknowledged_once_without_replay(self):
        api = Mock()
        with patch.object(worker, "print_network", side_effect=worker.NotSent("offline")) as printer, patch("sys.stderr", new=io.StringIO()):
            self.assertEqual("failed", worker.process_job(api, self.job()))
        printer.assert_called_once()
        self.assertEqual("failed", api.post.call_args.args[1]["status"])
        self.assertEqual("secret-claim", api.post.call_args.args[1]["claim_token"])
        api.post.assert_called_once()

    def test_uncertain_delivery_acknowledged_and_not_replayed(self):
        api = Mock()
        with patch.object(worker, "print_network", side_effect=worker.UncertainDelivery("partial")) as printer, patch("sys.stderr", new=io.StringIO()):
            self.assertEqual("uncertain", worker.process_job(api, self.job()))
        printer.assert_called_once()
        self.assertEqual("uncertain", api.post.call_args.args[1]["status"])

    def test_ack_failure_never_reprints(self):
        api = Mock()
        api.post.side_effect = RuntimeError("server restarted")
        with patch.object(worker, "print_network") as printer, patch("sys.stderr", new=io.StringIO()):
            self.assertEqual("uncertain", worker.process_job(api, self.job()))
        printer.assert_called_once()
        api.post.assert_called_once()

    def test_remote_api_cannot_receive_token(self):
        for url in ("http://example.com:8080", "https://127.0.0.1", "http://user:pass@localhost", "http://localhost?forward=1"):
            with self.assertRaises(ValueError):
                worker.Api(url, "token")
        api = worker.Api("http://127.0.0.1:8080", "token")
        self.assertEqual("http://127.0.0.1:8080", api.url)


class WindowsSpoolTests(unittest.TestCase):
    class Document(ctypes.Structure):
        _fields_ = [("name", wintypes.LPWSTR), ("output", wintypes.LPWSTR), ("datatype", wintypes.LPWSTR)]

    def spool(self, written=7):
        spool = Mock()
        spool.OpenPrinterW.return_value = True
        spool.StartDocPrinterW.return_value = 42
        spool.StartPagePrinter.return_value = True
        spool.EndPagePrinter.return_value = True
        spool.EndDocPrinter.return_value = True

        def write(handle, data, size, out):
            out._obj.value = written
            return True

        spool.WritePrinter.side_effect = write
        return spool

    def test_partial_raw_write_uncertain_and_aborts(self):
        spool = self.spool(written=2)
        with patch.object(worker, "windows_api", return_value=(spool, self.Document, wintypes)), patch.object(ctypes, "get_last_error", return_value=0, create=True):
            with self.assertRaises(worker.UncertainDelivery):
                worker.print_windows("Kitchen", b"receipt", "KOT")
        spool.WritePrinter.assert_called_once()
        spool.AbortPrinter.assert_called_once()
        spool.ClosePrinter.assert_called_once()

    def test_success_closes_without_abort(self):
        spool = self.spool(written=7)
        with patch.object(worker, "windows_api", return_value=(spool, self.Document, wintypes)):
            worker.print_windows("Kitchen", b"receipt", "KOT")
        spool.EndDocPrinter.assert_called_once()
        spool.ClosePrinter.assert_called_once()
        spool.AbortPrinter.assert_not_called()

    def test_open_failure_not_sent_and_no_write(self):
        spool = self.spool()
        spool.OpenPrinterW.return_value = False
        with patch.object(worker, "windows_api", return_value=(spool, self.Document, wintypes)), patch.object(ctypes, "get_last_error", return_value=5, create=True):
            with self.assertRaises(worker.NotSent):
                worker.print_windows("Kitchen", b"receipt", "KOT")
        spool.WritePrinter.assert_not_called()

    def test_finalize_error_is_uncertain(self):
        spool = self.spool()
        spool.EndDocPrinter.return_value = False
        with patch.object(worker, "windows_api", return_value=(spool, self.Document, wintypes)), patch.object(ctypes, "get_last_error", return_value=5, create=True):
            with self.assertRaises(worker.UncertainDelivery):
                worker.print_windows("Kitchen", b"receipt", "KOT")
        spool.WritePrinter.assert_called_once()
        spool.AbortPrinter.assert_called_once()
        spool.ClosePrinter.assert_called_once()


if __name__ == "__main__":
    unittest.main(verbosity=2)
