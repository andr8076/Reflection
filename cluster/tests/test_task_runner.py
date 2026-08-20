"""Regression tests for the isolated task runner."""

import importlib.util
import tempfile
import unittest
from pathlib import Path


TASK_RUNNER_PATH = Path(__file__).resolve().parents[1] / "task_runner.py"
SPEC = importlib.util.spec_from_file_location("reflection_task_runner", TASK_RUNNER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"Could not import task runner from {TASK_RUNNER_PATH}")
task_runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(task_runner)


class TaskDiscoveryTest(unittest.TestCase):
    def test_valid_task_runs_when_unrelated_task_fails_to_import(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            tasks_dir = Path(temp_dir)
            (tasks_dir / "broken.py").write_text("raise RuntimeError('broken import')\n", encoding="utf-8")
            (tasks_dir / "working.py").write_text(
                "TASK_NAME = 'working'\n"
                "TASK_SPEC = {'name': 'working', 'production_ready': True, 'source': {'mode': 'required'}, 'delivery': {'mode': 'required'}, 'output': {'kind': 'file'}}\n"
                "def run(source, delivery, overwrite_allowed):\n"
                "    return {'success': source == 'input' and delivery == 'output'}\n",
                encoding="utf-8",
            )

            result = task_runner.run_task(tasks_dir, "working", "input", "output", False)

            self.assertTrue(result["success"])

    def test_unknown_task_reports_failed_module_without_importing_modules_twice(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            tasks_dir = Path(temp_dir)
            counter_path = tasks_dir / "imports.txt"
            (tasks_dir / "broken.py").write_text("raise RuntimeError('broken import')\n", encoding="utf-8")
            (tasks_dir / "working.py").write_text(
                "from pathlib import Path\n"
                f"counter = Path({str(counter_path)!r})\n"
                "counter.write_text(counter.read_text() + 'imported\\n' if counter.exists() else 'imported\\n')\n"
                "def run(source, delivery, overwrite_allowed):\n"
                "    return True\n",
                encoding="utf-8",
            )

            with self.assertRaisesRegex(KeyError, r"broken \(failed to load\).+working"):
                task_runner.run_task(tasks_dir, "missing", "input", "output", False)

            self.assertEqual(counter_path.read_text(encoding="utf-8"), "imported\n")


if __name__ == "__main__":
    unittest.main()
