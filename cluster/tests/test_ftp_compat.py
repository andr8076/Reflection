import ftplib
import sys
import unittest
from pathlib import Path


WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

import ftp_compat


class DummyFTP:
    def __init__(self, encoding="utf-8", utf8_confirmed=False):
        self.encoding = encoding
        self._reflection_utf8_confirmed = utf8_confirmed


class FtpFilenameCompatibilityTest(unittest.TestCase):
    def test_non_ascii_550_retries_with_legacy_encoding_and_keeps_successful_encoding(self):
        ftp = DummyFTP()
        calls = []

        def sender(client, command):
            calls.append((client.encoding, command.encode(client.encoding)))
            if client.encoding == "utf-8":
                raise ftplib.error_perm("550 /System/testvideos/TIDSFORLØB.mp4: No such file or directory")
            return "150 opening data connection"

        result = ftp_compat._run_filename_command(
            ftp,
            "RETR /System/testvideos/TIDSFORLØB.mp4",
            sender,
        )

        self.assertEqual(result, "150 opening data connection")
        self.assertEqual(calls[0][0], "utf-8")
        self.assertEqual(calls[1][0], "latin-1")
        self.assertIn("Ø".encode("utf-8"), calls[0][1])
        self.assertIn("Ø".encode("latin-1"), calls[1][1])
        self.assertEqual(ftp.encoding, "latin-1")
        self.assertEqual(ftp._reflection_legacy_filename_encoding, "latin-1")

    def test_ascii_550_is_not_retried(self):
        ftp = DummyFTP()
        calls = []

        def sender(client, command):
            calls.append((client.encoding, command))
            raise ftplib.error_perm("550 missing")

        with self.assertRaises(ftplib.error_perm):
            ftp_compat._run_filename_command(ftp, "RETR /System/testvideos/movie.mp4", sender)

        self.assertEqual(calls, [("utf-8", "RETR /System/testvideos/movie.mp4")])
        self.assertEqual(ftp.encoding, "utf-8")

    def test_confirmed_utf8_server_does_not_use_legacy_fallback(self):
        ftp = DummyFTP(utf8_confirmed=True)
        calls = []

        def sender(client, command):
            calls.append((client.encoding, command))
            raise ftplib.error_perm("550 missing")

        with self.assertRaises(ftplib.error_perm):
            ftp_compat._run_filename_command(ftp, "RETR /TIDSFORLØB.mp4", sender)

        self.assertEqual(calls, [("utf-8", "RETR /TIDSFORLØB.mp4")])
        self.assertEqual(ftp.encoding, "utf-8")

    def test_failed_legacy_retry_restores_utf8(self):
        ftp = DummyFTP()
        calls = []

        def sender(client, command):
            calls.append(client.encoding)
            raise ftplib.error_perm("550 missing")

        with self.assertRaisesRegex(ftplib.error_perm, "550 missing"):
            ftp_compat._run_filename_command(ftp, "RETR /TIDSFORLØB.mp4", sender)

        # latin-1 and cp1252 encode Ø to the same bytes, so the bounded retry
        # deliberately sends only one distinct legacy command.
        self.assertEqual(calls, ["utf-8", "latin-1"])
        self.assertEqual(ftp.encoding, "utf-8")

    def test_utf8_feature_detection(self):
        self.assertTrue(ftp_compat._server_advertises_utf8("211-Features:\n UTF8\n MLST\n211 End"))
        self.assertTrue(ftp_compat._server_advertises_utf8(" UTF-8"))
        self.assertFalse(ftp_compat._server_advertises_utf8("211-Features:\n MLST\n211 End"))

    def test_install_is_idempotent_and_patches_base_ftp_commands(self):
        ftp_compat.install_ftplib_filename_compat()
        sendcmd = ftplib.FTP.sendcmd
        voidcmd = ftplib.FTP.voidcmd
        login = ftplib.FTP.login

        ftp_compat.install_ftplib_filename_compat()

        self.assertIs(ftplib.FTP.sendcmd, sendcmd)
        self.assertIs(ftplib.FTP.voidcmd, voidcmd)
        self.assertIs(ftplib.FTP.login, login)
        self.assertTrue(getattr(ftplib.FTP, "_reflection_filename_compat_installed", False))


if __name__ == "__main__":
    unittest.main()
