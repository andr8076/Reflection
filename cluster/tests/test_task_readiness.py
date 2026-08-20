import os
import sys
import unittest
from pathlib import Path
from unittest import mock

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

from task_readiness import available_terminal, supported_transfer_schemes


class TaskReadinessTests(unittest.TestCase):
    @mock.patch("task_readiness.platform.system", return_value="Linux")
    @mock.patch("task_readiness.shutil.which", return_value="/usr/bin/xterm")
    def test_headless_linux_does_not_advertise_a_visible_terminal(self, _which, _system):
        with mock.patch.dict(os.environ, {}, clear=True):
            self.assertIsNone(available_terminal())

    @mock.patch("task_readiness.platform.system", return_value="Linux")
    @mock.patch("task_readiness.shutil.which", side_effect=lambda name: f"/usr/bin/{name}" if name == "xterm" else None)
    def test_desktop_linux_advertises_supported_terminal(self, _which, _system):
        with mock.patch.dict(os.environ, {"DISPLAY": ":0"}, clear=True):
            self.assertEqual("/usr/bin/xterm", available_terminal())

    @mock.patch("task_readiness.importlib.util.find_spec", return_value=object())
    def test_sftp_is_advertised_when_paramiko_is_installed(self, _find_spec):
        self.assertEqual(["ftp", "ftps", "sftp"], supported_transfer_schemes())


if __name__ == "__main__":
    unittest.main()
