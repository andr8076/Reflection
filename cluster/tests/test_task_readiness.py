import os
import sys
import unittest
from pathlib import Path
from unittest import mock

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

from types import SimpleNamespace

from task_readiness import available_terminal, supported_transfer_schemes, task_readiness


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


    def test_command_alternatives_accept_any_available_command(self):
        definition = SimpleNamespace(
            spec={
                "production_ready": True,
                "requirements": {
                    "commands": ["bash", "git", "python3"],
                    "command_alternatives": [["7zz", "7z", "7za"]],
                },
            }
        )
        available = {"bash", "git", "python3", "7z"}
        with mock.patch("task_readiness.available_terminal", return_value="/usr/bin/xterm"), mock.patch(
            "task_readiness.shutil.which",
            side_effect=lambda command: f"/usr/bin/{command}" if command in available else None,
        ):
            result = task_readiness({"hardcore_archive": definition})

        self.assertTrue(result["hardcore_archive"]["ready"])
        self.assertEqual(result["hardcore_archive"]["reason"], "")


if __name__ == "__main__":
    unittest.main()
