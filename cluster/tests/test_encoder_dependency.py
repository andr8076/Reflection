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

import encoder_dependency


class EncoderDependencyTest(unittest.TestCase):
    def make_checkout(self, root: Path) -> tuple[Path, dict[str, object]]:
        worker_root = root / "cluster"
        dependency_root = worker_root / ".dependencies"
        checkout = dependency_root / "265Encode"
        checkout.mkdir(parents=True)
        (checkout / ".git").mkdir()
        script = checkout / "265Encode.sh"
        script.write_text("#!/usr/bin/env bash\nexit 0\n", encoding="utf-8")
        script.chmod(0o755)
        (checkout / "tools").mkdir()
        (checkout / "tools" / "HEVCPlan.py").write_text("# planner\n", encoding="utf-8")
        manifest = worker_root / "dependencies.json"
        manifest.write_text(
            json.dumps({
                "schema": "reflection.external-dependencies.v1",
                "dependencies": [{
                    "name": "265Encode",
                    "repository": "https://github.com/andr8076/265Encode.git",
                    "branch": "main",
                    "path": ".dependencies/265Encode",
                }],
            }),
            encoding="utf-8",
        )
        return worker_root, {
            "MANIFEST_PATH": manifest,
            "DEPENDENCY_ROOT": dependency_root,
            "PROBE_CACHE_PATH": dependency_root / "265Encode-probe-cache.json",
        }

    def test_fetches_latest_main_before_returning_dependency(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            worker_root, paths = self.make_checkout(Path(temp_dir))
            values = [
                "https://github.com/andr8076/265Encode.git",
                "1" * 40,
                "2" * 40,
                "2" * 40,
            ]
            success = subprocess.CompletedProcess([], 0, "", "")
            with mock.patch.object(encoder_dependency, "WORKER_ROOT", worker_root), \
                 mock.patch.multiple(encoder_dependency, **paths), \
                 mock.patch.object(encoder_dependency.shutil, "which", return_value="/usr/bin/git"), \
                 mock.patch.object(encoder_dependency, "_git_value", side_effect=values), \
                 mock.patch.object(encoder_dependency, "_run", side_effect=[success, success]) as run_git:
                dependency = encoder_dependency.ensure_265encode(update=True)

            self.assertEqual(dependency.commit, "2" * 40)
            self.assertEqual(dependency.branch, "main")
            self.assertEqual(run_git.call_args_list[0].args[0][-5:], ["--depth", "1", "--prune", "origin", "main"])
            self.assertEqual(run_git.call_args_list[1].args[0][-2:], ["--hard", "FETCH_HEAD"])

    def test_uses_installed_commit_when_update_fetch_fails(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            worker_root, paths = self.make_checkout(Path(temp_dir))
            values = ["https://github.com/andr8076/265Encode.git", "3" * 40]
            failed_fetch = subprocess.CompletedProcess([], 128, "", "network unavailable")
            with mock.patch.object(encoder_dependency, "WORKER_ROOT", worker_root), \
                 mock.patch.multiple(encoder_dependency, **paths), \
                 mock.patch.object(encoder_dependency.shutil, "which", return_value="/usr/bin/git"), \
                 mock.patch.object(encoder_dependency, "_git_value", side_effect=values), \
                 mock.patch.object(encoder_dependency, "_run", return_value=failed_fetch) as run_git:
                dependency = encoder_dependency.ensure_265encode(update=True)

            self.assertEqual(dependency.commit, "3" * 40)
            self.assertEqual(run_git.call_count, 1)
            self.assertFalse(run_git.call_args.args[0][-2] == "reset")


if __name__ == "__main__":
    unittest.main()
