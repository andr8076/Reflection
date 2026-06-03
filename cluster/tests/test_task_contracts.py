import importlib.util
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

from task_registry import discover_task_definitions

TASKS_DIR = WORKER_ROOT / "tasks"


class TaskContractTest(unittest.TestCase):
    def test_task_modules_declare_contracts(self):
        registry = discover_task_definitions(TASKS_DIR)
        self.assertEqual(registry["compress_archive"].spec["delivery"]["extension"], ".zip")
        self.assertEqual(registry["compress_archive"].spec["delivery"]["mode"], "auto")

    def test_compress_archive_writes_zip_and_rejects_wrong_extension(self):
        spec = importlib.util.spec_from_file_location("compress_archive", TASKS_DIR / "compress_archive.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "input"
            source.mkdir()
            (source / "hello.txt").write_text("hello", encoding="utf-8")
            delivery = root / "output.zip"

            self.assertTrue(module.run(str(source), str(delivery), False))
            self.assertTrue(zipfile.is_zipfile(delivery))
            with zipfile.ZipFile(delivery) as archive:
                self.assertIn("input/hello.txt", archive.namelist())

            with self.assertRaisesRegex(ValueError, "must end with .zip"):
                module.run(str(source), str(root / "output.tar.xz"), True)


if __name__ == "__main__":
    unittest.main()
