import contextlib
import importlib.util
import io
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

import Reflection

VIEWER_PATH = WORKER_ROOT / "task_log_viewer.py"
SPEC = importlib.util.spec_from_file_location("reflection_task_log_viewer", VIEWER_PATH)
task_log_viewer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(task_log_viewer)


class TaskLogViewerTest(unittest.TestCase):
    def test_stream_logs_prints_captured_output_and_closes_after_done_marker(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            runtime_dir = Path(temp_dir)
            stdout_path = runtime_dir / "stdout.log"
            stderr_path = runtime_dir / "stderr.log"
            done_path = runtime_dir / "done"
            stdout_path.write_text("progress 100%\n", encoding="utf-8")
            stderr_path.write_text("warning\n", encoding="utf-8")
            done_path.touch()

            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                task_log_viewer.stream_logs(stdout_path, stderr_path, done_path, poll_interval=0)

            self.assertEqual(output.getvalue(), "[stdout]\nprogress 100%\n[stderr]\nwarning\n")


class TaskTerminalLauncherTest(unittest.TestCase):
    def test_launcher_opens_first_available_terminal_for_log_viewer(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            runtime_dir = Path(temp_dir)
            stdout_path = runtime_dir / "stdout.log"
            stderr_path = runtime_dir / "stderr.log"
            done_path = runtime_dir / "done"
            stdout_path.touch()
            stderr_path.touch()

            with patch.object(Reflection, "SHOW_TASK_TERMINAL", True), patch.object(
                Reflection.shutil, "which", side_effect=lambda binary: "/usr/bin/xterm" if binary == "xterm" else None
            ), patch.object(Reflection.subprocess, "Popen") as popen:
                launched = Reflection._launch_task_log_terminal(
                    "h265_encode", "job_001", stdout_path, stderr_path, done_path
                )

            self.assertTrue(launched)
            command = popen.call_args.args[0]
            self.assertEqual(command[:4], ["xterm", "-T", "Reflection Task job_001 - h265_encode", "-e"])
            self.assertIn(str(Reflection.TASK_LOG_VIEWER_PATH), command)
            self.assertIn(str(done_path), command)
            self.assertTrue(popen.call_args.kwargs["start_new_session"])

    def test_launcher_is_disabled_without_opening_a_terminal(self):
        with patch.object(Reflection, "SHOW_TASK_TERMINAL", False), patch.object(
            Reflection.subprocess, "Popen"
        ) as popen:
            launched = Reflection._launch_task_log_terminal("dummy", "job_002", "stdout", "stderr", "done")

        self.assertFalse(launched)
        popen.assert_not_called()


if __name__ == "__main__":
    unittest.main()
