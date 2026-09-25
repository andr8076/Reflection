import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

import archive_dependency


class HardcoreArchiveDependencyTests(unittest.TestCase):
    def make_worker_root(self, root: Path):
        worker_root = root / "cluster"
        dependency_root = worker_root / ".dependencies"
        checkout = dependency_root / "Hardcore-Archive"
        dependency_root.mkdir(parents=True)
        manifest = worker_root / "dependencies.json"
        manifest.write_text(
            json.dumps(
                {
                    "schema": "reflection.external-dependencies.v1",
                    "dependencies": [
                        {
                            "name": "Hardcore-Archive",
                            "repository": "https://github.com/andr8076/Hardcore-Archive.git",
                            "branch": "main",
                            "path": ".dependencies/Hardcore-Archive",
                        }
                    ],
                }
            ),
            encoding="utf-8",
        )
        return worker_root, dependency_root, checkout, manifest

    def make_checkout(self, checkout: Path):
        checkout.mkdir(parents=True, exist_ok=True)
        (checkout / ".git").mkdir(exist_ok=True)
        (checkout / "lib").mkdir(exist_ok=True)
        (checkout / "lib" / "scheduler.sh").write_text("# scheduler\n", encoding="utf-8")
        entry = checkout / "hardcore-archive"
        entry.write_text("#!/usr/bin/env bash\nexit 0\n", encoding="utf-8")
        entry.chmod(0o755)
        av1_directory = checkout / "vendor" / "AV1Encode"
        av1_directory.mkdir(parents=True, exist_ok=True)
        av1_entry = av1_directory / "AV1Encode.sh"
        av1_entry.write_text("#!/usr/bin/env bash\nexit 0\n", encoding="utf-8")
        av1_entry.chmod(0o755)

    def patched_paths(self, worker_root, dependency_root, manifest):
        return {
            "WORKER_ROOT": worker_root,
            "DEPENDENCY_ROOT": dependency_root,
            "MANIFEST_PATH": manifest,
        }

    def test_existing_checkout_fetches_only_main_and_refreshes_pinned_submodules(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            worker_root, dependency_root, checkout, manifest = self.make_worker_root(Path(temp_dir))
            self.make_checkout(checkout)
            values = [
                "https://github.com/andr8076/Hardcore-Archive.git",
                "1" * 40,
                "2" * 40,
                "2" * 40,
            ]
            success = subprocess.CompletedProcess([], 0, "", "")
            with mock.patch.multiple(
                archive_dependency,
                **self.patched_paths(worker_root, dependency_root, manifest),
            ), mock.patch.object(
                archive_dependency.shutil, "which", return_value="/usr/bin/git"
            ), mock.patch.object(
                archive_dependency, "_git_value", side_effect=values
            ), mock.patch.object(
                archive_dependency, "_run", side_effect=[success, success, success, success]
            ) as run_git:
                dependency = archive_dependency.ensure_hardcore_archive(update=True)

            self.assertEqual(dependency.commit, "2" * 40)
            self.assertEqual(dependency.branch, "main")
            calls = [call.args[0] for call in run_git.call_args_list]
            self.assertEqual(calls[0][-5:], ["--depth", "1", "--prune", "origin", "main"])
            self.assertEqual(calls[1][-2:], ["--hard", "FETCH_HEAD"])
            self.assertEqual(calls[2][-3:], ["submodule", "sync", "--recursive"])
            self.assertEqual(calls[3][-4:], ["submodule", "update", "--init", "--recursive"])

    def test_missing_checkout_clones_main_and_initializes_its_submodules(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            worker_root, dependency_root, checkout, manifest = self.make_worker_root(Path(temp_dir))
            success = subprocess.CompletedProcess([], 0, "", "")
            commands = []

            def fake_run(command, **_kwargs):
                commands.append(command)
                if "clone" in command:
                    self.make_checkout(Path(command[-1]))
                return success

            with mock.patch.multiple(
                archive_dependency,
                **self.patched_paths(worker_root, dependency_root, manifest),
            ), mock.patch.object(
                archive_dependency.shutil, "which", return_value="/usr/bin/git"
            ), mock.patch.object(
                archive_dependency, "_git_value", return_value="a" * 40
            ), mock.patch.object(
                archive_dependency, "_run", side_effect=fake_run
            ):
                dependency = archive_dependency.ensure_hardcore_archive(update=True)

            clone = next(command for command in commands if "clone" in command)
            self.assertIn("--branch", clone)
            self.assertIn("main", clone)
            self.assertIn("https://github.com/andr8076/Hardcore-Archive.git", clone)
            self.assertTrue(
                any(command[-4:] == ["submodule", "update", "--init", "--recursive"] for command in commands)
            )
            self.assertEqual(dependency.checkout, checkout)
            self.assertEqual(dependency.script, checkout / "hardcore-archive")

    def test_manifest_rejects_non_main_branch(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            worker_root, dependency_root, _checkout, manifest = self.make_worker_root(Path(temp_dir))
            data = json.loads(manifest.read_text(encoding="utf-8"))
            data["dependencies"][0]["branch"] = "codex/compression-judge"
            manifest.write_text(json.dumps(data), encoding="utf-8")
            with mock.patch.multiple(
                archive_dependency,
                **self.patched_paths(worker_root, dependency_root, manifest),
            ):
                with self.assertRaisesRegex(
                    archive_dependency.HardcoreArchiveDependencyError,
                    "must be checked out from main",
                ):
                    archive_dependency._manifest_entry()


if __name__ == "__main__":
    unittest.main()
