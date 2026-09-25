import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

TASKS_DIR = WORKER_ROOT / "tasks"


def load_task():
    import importlib.util

    spec = importlib.util.spec_from_file_location(
        "hardcore_archive_task", TASKS_DIR / "hardcore_archive.py"
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class HardcoreArchiveTaskTests(unittest.TestCase):
    def test_blank_delivery_uses_source_name_plus_seven_zip_extension(self):
        module = load_task()
        with tempfile.TemporaryDirectory() as temp_dir:
            source = Path(temp_dir) / "My folder"
            source.mkdir()
            self.assertEqual(
                module._output_path(source, "").name,
                "My folder.7z",
            )

    def test_run_uses_linked_main_checkout_and_keeps_source(self):
        module = load_task()
        with tempfile.TemporaryDirectory() as temp_dir:
            source = Path(temp_dir) / "source folder"
            source.mkdir()
            (source / "hello.txt").write_text("hello", encoding="utf-8")
            output = Path(f"{source}.7z")
            script = Path(temp_dir) / "hardcore-archive"
            dependency = SimpleNamespace(
                repository="https://github.com/andr8076/Hardcore-Archive.git",
                commit="a" * 40,
                script=script,
            )
            commands = []

            def run_cli(command, check):
                commands.append(command)
                output.write_bytes(b"validated archive")
                return subprocess.CompletedProcess(command, 0)

            with mock.patch.object(
                module, "ensure_hardcore_archive", return_value=dependency
            ) as ensure, mock.patch.object(
                module.shutil, "which", return_value="/usr/bin/bash"
            ), mock.patch.object(
                module.subprocess, "run", side_effect=run_cli
            ):
                result = module.run(str(source), "", False)

            ensure.assert_called_once_with(update=True)
            self.assertEqual(
                commands,
                [["/usr/bin/bash", str(script), str(source)]],
            )
            self.assertNotIn("--remove-source", commands[0])
            self.assertTrue(source.is_dir())
            self.assertTrue((source / "hello.txt").is_file())
            self.assertTrue(output.is_file())
            self.assertIn("main commit aaaaaaaaaaaa", result["message"])

    def test_overwrite_permission_is_forwarded_as_force(self):
        module = load_task()
        with tempfile.TemporaryDirectory() as temp_dir:
            source = Path(temp_dir) / "input"
            source.mkdir()
            output = Path(temp_dir) / "output.7z"
            output.write_bytes(b"old")
            script = Path(temp_dir) / "hardcore-archive"
            dependency = SimpleNamespace(
                repository="https://github.com/andr8076/Hardcore-Archive.git",
                commit="b" * 40,
                script=script,
            )

            def run_cli(command, check):
                Path(command[-1]).write_bytes(b"new")
                return subprocess.CompletedProcess(command, 0)

            with mock.patch.object(
                module, "ensure_hardcore_archive", return_value=dependency
            ), mock.patch.object(
                module.shutil, "which", return_value="/usr/bin/bash"
            ), mock.patch.object(
                module.subprocess, "run", side_effect=run_cli
            ) as run:
                result = module.run(str(source), str(output), True)

            self.assertEqual(
                run.call_args.args[0],
                ["/usr/bin/bash", str(script), "--force", str(source), str(output)],
            )
            self.assertEqual(output.read_bytes(), b"new")
            self.assertTrue(result["success"])

    def test_rejects_existing_archive_without_overwrite_permission(self):
        module = load_task()
        with tempfile.TemporaryDirectory() as temp_dir:
            source = Path(temp_dir) / "input"
            source.mkdir()
            output = Path(temp_dir) / "input.7z"
            output.write_bytes(b"old")
            with mock.patch.object(module, "ensure_hardcore_archive") as ensure:
                with self.assertRaisesRegex(FileExistsError, "overwrite is disabled"):
                    module.run(str(source), "", False)
            ensure.assert_not_called()
            self.assertEqual(output.read_bytes(), b"old")

    def test_rejects_non_folder_sources(self):
        module = load_task()
        with tempfile.TemporaryDirectory() as temp_dir:
            source = Path(temp_dir) / "input.txt"
            source.write_text("data", encoding="utf-8")
            with self.assertRaisesRegex(NotADirectoryError, "requires one folder"):
                module.run(str(source), "", False)

    def test_rejects_delivery_inside_the_source_folder(self):
        module = load_task()
        with tempfile.TemporaryDirectory() as temp_dir:
            source = Path(temp_dir) / "input"
            source.mkdir()
            output = source / "output.7z"
            with self.assertRaisesRegex(ValueError, "outside the source folder"):
                module.run(str(source), str(output), False)


if __name__ == "__main__":
    unittest.main()
