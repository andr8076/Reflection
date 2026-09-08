"""FTP filename encoding compatibility for Reflection workers.

Modern FTP uses UTF-8 filenames, but some older or embedded servers still expect
Latin-1/Windows-1252 bytes and return a misleading 550 "not found" when a UTF-8
command contains non-ASCII characters. Reflection always tries UTF-8 first and
only retries a failed non-ASCII filename command with a bounded legacy fallback
when the server has not advertised UTF-8 support.
"""

from __future__ import annotations

import ftplib
import logging
from collections.abc import Callable
from typing import Any


LEGACY_FILENAME_ENCODINGS = ("latin-1", "cp1252")

# Preserve the real stdlib methods even if this module is reloaded after the
# compatibility hooks have already been installed.
_ORIGINAL_SENDCMD = getattr(ftplib.FTP, "_reflection_original_sendcmd", ftplib.FTP.sendcmd)
_ORIGINAL_VOIDCMD = getattr(ftplib.FTP, "_reflection_original_voidcmd", ftplib.FTP.voidcmd)
_ORIGINAL_LOGIN = getattr(ftplib.FTP, "_reflection_original_login", ftplib.FTP.login)


def _normalized_encoding(value: Any) -> str:
    return str(value or "").strip().lower().replace("_", "-")


def _contains_non_ascii(value: str) -> bool:
    return any(ord(char) > 127 for char in str(value))


def _is_file_not_found_style_error(error: BaseException) -> bool:
    # 550 is the usual FTP response for both a genuinely missing path and a
    # filename that the server failed to resolve because it decoded the command
    # bytes using a legacy character set.
    return str(error).lstrip().startswith("550")


def _server_advertises_utf8(features: str) -> bool:
    for line in str(features or "").splitlines():
        token = line.strip().upper().split(" ", 1)[0].replace("-", "")
        if token == "UTF8":
            return True
    return False


def _legacy_retry_encodings(command: str, current_encoding: str):
    """Yield bounded legacy encodings that produce distinct command bytes."""
    seen = set()
    try:
        seen.add(str(command).encode(current_encoding))
    except (LookupError, UnicodeEncodeError):
        pass

    for encoding in LEGACY_FILENAME_ENCODINGS:
        try:
            encoded = str(command).encode(encoding)
        except (LookupError, UnicodeEncodeError):
            continue
        if encoded in seen:
            continue
        seen.add(encoded)
        yield encoding


def _run_filename_command(
    ftp,
    command: str,
    sender: Callable[[Any, str], Any],
):
    """Run one FTP command, retrying a UTF-8 550 once per distinct legacy encoding."""
    try:
        return sender(ftp, command)
    except ftplib.error_perm as first_error:
        current_encoding = _normalized_encoding(getattr(ftp, "encoding", "utf-8"))
        if (
            current_encoding not in {"utf8", "utf-8"}
            or getattr(ftp, "_reflection_utf8_confirmed", False)
            or not _contains_non_ascii(command)
            or not _is_file_not_found_style_error(first_error)
        ):
            raise

        original_encoding = getattr(ftp, "encoding", "utf-8")
        for encoding in _legacy_retry_encodings(command, original_encoding):
            ftp.encoding = encoding
            try:
                result = sender(ftp, command)
            except ftplib.error_perm:
                continue
            except (LookupError, UnicodeEncodeError):
                continue
            except Exception:
                ftp.encoding = original_encoding
                raise

            ftp._reflection_legacy_filename_encoding = encoding
            if not getattr(ftp, "_reflection_legacy_filename_encoding_logged", False):
                logging.warning(
                    "FTP server rejected a non-ASCII filename as UTF-8 but accepted %s; "
                    "using %s for filenames on this connection.",
                    encoding,
                    encoding,
                )
                ftp._reflection_legacy_filename_encoding_logged = True
            return result

        ftp.encoding = original_encoding
        raise first_error


def _patched_sendcmd(self, command):
    return _run_filename_command(self, command, _ORIGINAL_SENDCMD)


def _patched_voidcmd(self, command):
    return _run_filename_command(self, command, _ORIGINAL_VOIDCMD)


def _negotiate_utf8(self) -> None:
    """Record whether the server explicitly advertises UTF-8 filename support."""
    if _normalized_encoding(getattr(self, "encoding", "utf-8")) not in {"utf8", "utf-8"}:
        return

    self._reflection_utf8_confirmed = False
    try:
        features = _ORIGINAL_SENDCMD(self, "FEAT")
    except ftplib.all_errors:
        return

    if not _server_advertises_utf8(features):
        return

    self._reflection_utf8_confirmed = True
    # RFC-compliant servers advertising UTF8 already support it. OPTS UTF8 ON
    # is nevertheless accepted by many older servers, so enable it when offered
    # without making support depend on that optional command.
    try:
        _ORIGINAL_SENDCMD(self, "OPTS UTF8 ON")
    except ftplib.all_errors:
        pass


def _patched_login(self, *args, **kwargs):
    response = _ORIGINAL_LOGIN(self, *args, **kwargs)
    _negotiate_utf8(self)
    return response


def install_ftplib_filename_compat() -> None:
    """Install the compatibility hooks once on Python's stdlib FTP class."""
    if getattr(ftplib.FTP, "_reflection_filename_compat_installed", False):
        return

    ftplib.FTP._reflection_original_sendcmd = _ORIGINAL_SENDCMD
    ftplib.FTP._reflection_original_voidcmd = _ORIGINAL_VOIDCMD
    ftplib.FTP._reflection_original_login = _ORIGINAL_LOGIN
    ftplib.FTP.sendcmd = _patched_sendcmd
    ftplib.FTP.voidcmd = _patched_voidcmd
    ftplib.FTP.login = _patched_login
    ftplib.FTP._reflection_filename_compat_installed = True
