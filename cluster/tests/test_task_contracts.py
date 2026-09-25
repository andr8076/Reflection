import importlib.util
import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

from task_registry import discover_task_definitions

TASKS_DIR = WORKER_ROOT / "tasks"


def load_task(name):
    spec = importlib.util.spec_from_file_location(name, TASKS_DIR / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class TaskContractTest(unittest.TestCase):
    def test_task_modules_declare_contracts(self):
        registry = discover_task_definitions(TASKS_DIR)
        self.assertEqual(set(registry), {"compress_archive", "hardcore_archive", "h265_encode", "invert_image"})
        self.assertTrue(registry["compress_archive"].spec["production_ready"])
        self.assertTrue(registry["h265_encode"].spec["production_ready"])
        self.assertTrue(registry["hardcore_archive"].spec["production_ready"])
        self.assertTrue(registry["invert_image"].spec["production_ready"])
        self.assertEqual(registry["compress_archive"].spec["delivery"]["extension"], ".zip")
        self.assertEqual(registry["compress_archive"].spec["delivery"]["mode"], "auto")
        self.assertEqual(registry["hardcore_archive"].spec["delivery"]["extension"], ".7z")
        self.assertEqual(registry["hardcore_archive"].spec["delivery"]["mode"], "auto")
        self.assertEqual(registry["hardcore_archive"].spec["output"]["container"], "7z")
        self.assertEqual(registry["hardcore_archive"].spec["requirements"]["command_alternatives"], [["7zz", "7z", "7za"]])
        dependency = registry["hardcore_archive"].spec["dependencies"]["repositories"][0]
        self.assertEqual(dependency["repository"], "https://github.com/andr8076/Hardcore-Archive")
        self.assertEqual(dependency["branch"], "main")
        self.assertEqual(dependency["update"], "before_each_hardcore_archive_run")
        self.assertEqual(dependency["submodules"], "pinned_to_parent_commit")
        self.assertEqual(registry["h265_encode"].spec["delivery"]["extension"], ".mkv")
        self.assertEqual(registry["h265_encode"].spec["delivery"]["mode"], "auto")
        self.assertEqual(registry["h265_encode"].spec["output"]["container"], "mkv")
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_audio"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_subtitles"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_chapters"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_metadata"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_attachments"])
        self.assertEqual(registry["h265_encode"].spec["output"]["encoded_streams"], ["video:0"])
        self.assertEqual(registry["h265_encode"].spec["output"]["kind"], "file")

    def test_compress_archive_writes_zip_and_rejects_wrong_extension(self):
        module = load_task("compress_archive")

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

    def test_h265_encode_defaults_to_mkv_delivery_paths(self):
        module = load_task("h265_encode")

        with tempfile.TemporaryDirectory() as temp_dir:
            source_file = Path(temp_dir) / "movie.mp4"
            source_file.write_text("placeholder", encoding="utf-8")
            self.assertEqual(module._output_path(source_file, None).name, "movie_h265.mkv")

    def test_h265_encode_rejects_non_mkv_delivery(self):
        module = load_task("h265_encode")

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source_file = root / "movie.mp4"
            source_file.write_text("placeholder", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "must end with .mkv"):
                module.run(str(source_file), str(root / "movie_h265.mp4"), True)

    def test_h265_encode_rejects_directory_jobs(self):
        module = load_task("h265_encode")
        with tempfile.TemporaryDirectory() as temp_dir:
            source_dir = Path(temp_dir) / "videos"
            source_dir.mkdir()
            with self.assertRaisesRegex(IsADirectoryError, "one video per job"):
                module.run(str(source_dir), "", False)

    def test_h265_encode_declares_linked_auto_updating_dependency(self):
        module = load_task("h265_encode")
        dependency = module.TASK_SPEC["dependencies"]["repositories"][0]
        self.assertEqual(dependency["repository"], "https://github.com/andr8076/265Encode")
        self.assertEqual(dependency["branch"], "main")
        self.assertEqual(dependency["protocol_version"], 2)
        self.assertEqual(dependency["update"], "before_each_h265_run")
        self.assertNotIn("libx265", module.TASK_SPEC["requirements"].get("ffmpeg_encoders", []))

    def test_h265_requirements_default_to_hardware_only_and_preserve_streams(self):
        module = load_task("h265_encode")
        requirements = module._requirements_payload(
            Path("/tmp/input.mp4"),
            Path("/tmp/output.mkv"),
            {},
        )
        self.assertEqual(requirements["hardware_policy"], "auto_hardware_only")
        self.assertEqual(requirements["quality"]["metric"], "vmaf")
        self.assertEqual(requirements["quality"]["target"], 92.0)
        self.assertEqual(requirements["optimization"]["primary"], "smallest_output")
        self.assertEqual(requirements["preservation"]["streams"], "all")
        self.assertEqual(requirements["audio"]["mode"], "copy_all")

    def test_h265_software_encoding_requires_explicit_semantic_mode(self):
        module = load_task("h265_encode")
        requirements = module._requirements_payload(
            Path("/tmp/input.mp4"),
            Path("/tmp/output.mkv"),
            {"mode": "software", "encode_profile": "4k_quality"},
        )
        self.assertEqual(requirements["hardware_policy"], "manual_software")
        self.assertEqual(requirements["quality"]["target"], 95.0)
        self.assertEqual(requirements["quality"]["p10_minimum"], 91.0)
        self.assertEqual(requirements["quality"]["sustained_floor"], 89.0)

    def test_h265_rejects_legacy_ffmpeg_tuning_flags(self):
        module = load_task("h265_encode")
        with self.assertRaisesRegex(ValueError, "265Encode owns encoder tuning"):
            module._requirements_payload(
                Path("/tmp/input.mp4"),
                Path("/tmp/output.mkv"),
                {"crf": 20},
            )

    def test_h265_skips_already_hevc_without_updating_dependency(self):
        module = load_task("h265_encode")
        with tempfile.TemporaryDirectory() as temp_dir:
            source_file = Path(temp_dir) / "movie.mkv"
            source_file.write_text("placeholder", encoding="utf-8")
            with mock.patch.object(module, "_analyze_video", return_value={
                "codec": "hevc", "width": 1920, "height": 1080, "duration": 60, "size": 10,
            }), mock.patch.object(module, "ensure_265encode") as ensure:
                result = module.run(str(source_file), "", False)
            self.assertTrue(result["success"])
            self.assertTrue(result["skipped"])
            ensure.assert_not_called()

    def test_h265_run_uses_protocol_plan_and_commits_validated_output(self):
        module = load_task("h265_encode")

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source_file = root / "movie.mp4"
            output_file = root / "movie_h265.mkv"
            source_file.write_text("source", encoding="utf-8")
            dependency = module.EncoderDependency(
                name="265Encode",
                repository="https://github.com/andr8076/265Encode.git",
                branch="main",
                checkout=root / "dependency",
                script=root / "dependency" / "265Encode.sh",
                commit="a" * 40,
            )

            def evaluate(_dependency, requirements_path, plan_path):
                requirements = json.loads(requirements_path.read_text(encoding="utf-8"))
                plan = {
                    "schema": "encode265.plan",
                    "plan_id": "plan-test",
                    "requirements": requirements,
                    "execution": {"state": "ready", "reason": None},
                    "selection": {"encoder": "hevc_nvenc"},
                    "prediction": {},
                }
                plan_path.write_text(json.dumps(plan), encoding="utf-8")
                return plan

            def execute(_dependency, plan_path, result_path):
                plan = json.loads(plan_path.read_text(encoding="utf-8"))
                encoded = Path(plan["requirements"]["output"])
                encoded.write_text("validated", encoding="utf-8")
                result = {
                    "schema": "encode265.plan-result",
                    "status": "ok",
                    "exit_code": 0,
                    "output": str(encoded),
                    "encoder": "hevc_nvenc",
                }
                result_path.write_text(json.dumps(result), encoding="utf-8")
                return result

            with mock.patch.object(module, "_analyze_video", return_value={
                "codec": "h264", "width": 1920, "height": 1080, "duration": 60, "size": 10,
            }), mock.patch.object(module, "ensure_265encode", return_value=dependency), \
                 mock.patch.object(module, "_validate_dependency"), \
                 mock.patch.object(module, "_evaluate_plan", side_effect=evaluate), \
                 mock.patch.object(module, "_execute_plan", side_effect=execute):
                result = module.run(str(source_file), "", False)

            self.assertTrue(result["success"])
            self.assertEqual(result["encoder"], "hevc_nvenc")
            self.assertEqual(output_file.read_text(encoding="utf-8"), "validated")

if __name__ == "__main__":
    unittest.main()
