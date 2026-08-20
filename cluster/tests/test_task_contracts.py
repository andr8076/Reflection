import importlib.util
import subprocess
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
        self.assertTrue(registry["compress_archive"].spec["production_ready"])
        self.assertTrue(registry["invert_image"].spec["production_ready"])
        self.assertFalse(registry["dummy_task"].spec["production_ready"])
        self.assertFalse(registry["render_frame"].spec["production_ready"])
        self.assertEqual(registry["compress_archive"].spec["delivery"]["extension"], ".zip")
        self.assertEqual(registry["compress_archive"].spec["delivery"]["mode"], "auto")
        self.assertEqual(registry["h265_encode"].spec["delivery"]["extension"], ".mkv")
        self.assertEqual(registry["h265_encode"].spec["delivery"]["mode"], "auto")
        self.assertEqual(registry["h265_encode"].spec["output"]["container"], "mkv")
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_audio"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_subtitles"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_chapters"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_metadata"])
        self.assertTrue(registry["h265_encode"].spec["output"]["preserve_attachments"])
        self.assertEqual(registry["h265_encode"].spec["output"]["encoded_streams"], ["video:0"])
        self.assertEqual(registry["h265_encode"].spec["encode_profiles"]["default"], "auto")
        self.assertIn("4k", registry["h265_encode"].spec["encode_profiles"])
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
            root = Path(temp_dir)
            source_file = root / "movie.mp4"
            source_file.write_text("placeholder", encoding="utf-8")
            output_file = module._output_path(source_file, source_file, None, 1)
            self.assertEqual(output_file.name, "movie_h265.mkv")

    def test_h265_encode_rejects_non_mkv_delivery(self):
        module = load_task("h265_encode")

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source_file = root / "movie.mp4"
            source_file.write_text("placeholder", encoding="utf-8")
            bad_delivery = root / "movie_h265.mp4"

            with mock.patch.object(module, "_require_tool"), \
                 mock.patch.object(module, "_choose_encoder", return_value=(module.SOFTWARE_ARGS, module.PIXEL_FORMAT_ARGS)), \
                 mock.patch.object(module, "_analyze_video", return_value={"codec": "h264", "height": 1080}), \
                 mock.patch.object(module, "_encode_file"):
                with self.assertRaisesRegex(ValueError, "must end with .mkv"):
                    module.run(str(source_file), str(bad_delivery), True)

    def test_h265_encode_rejects_directory_jobs(self):
        module = load_task("h265_encode")
        with tempfile.TemporaryDirectory() as temp_dir:
            source_dir = Path(temp_dir) / "videos"
            source_dir.mkdir()
            with self.assertRaisesRegex(IsADirectoryError, "one video per job"):
                module.run(str(source_dir), "", False)

    def test_h265_encode_auto_profile_selects_4k_settings(self):
        module = load_task("h265_encode")

        standard_profile, standard_args, standard_pix_fmt = module._encoder_for_analysis({}, {"codec": "h264", "width": 1920, "height": 1080})
        four_k_profile, four_k_args, four_k_pix_fmt = module._encoder_for_analysis({}, {"codec": "h264", "width": 3840, "height": 2160})

        self.assertEqual(standard_profile, "standard")
        self.assertEqual(four_k_profile, "4k")
        self.assertIn("20", standard_args)
        self.assertIn("22", four_k_args)
        self.assertEqual(standard_pix_fmt, module.PIXEL_FORMAT_ARGS)
        self.assertEqual(four_k_pix_fmt, module.PIXEL_FORMAT_ARGS)

    def test_h265_encode_source_json_can_override_profile(self):
        module = load_task("h265_encode")

        profile_name, encoder_args, pixel_format_args = module._encoder_for_analysis(
            {"encode_profile": "4k_quality", "crf": "18", "preset": "slower", "pix_fmt": "none"},
            {"codec": "h264", "width": 3840, "height": 2160},
        )

        self.assertEqual(profile_name, "4k_quality")
        self.assertIn("18", encoder_args)
        self.assertIn("slower", encoder_args)
        self.assertEqual(pixel_format_args, [])

    def test_h265_encode_command_preserves_movie_streams(self):
        module = load_task("h265_encode")

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source_file = root / "movie.mkv"
            output_file = root / "movie_h265.mkv"
            source_file.write_text("placeholder", encoding="utf-8")

            with mock.patch.object(module.subprocess, "run", return_value=subprocess.CompletedProcess([], 0)) as run_mock, \
                 mock.patch.object(module.os, "replace") as replace_mock:
                module._encode_file(source_file, output_file, module.SOFTWARE_ARGS, module.PIXEL_FORMAT_ARGS)

            command = run_mock.call_args.args[0]
            self.assertIn("-map", command)
            self.assertIn("0", command)
            self.assertIn("-map_metadata", command)
            self.assertIn("-map_chapters", command)
            self.assertIn("-c", command)
            self.assertIn("copy", command)
            self.assertIn("-c:v:0", command)
            self.assertIn("libx265", command)
            self.assertNotIn("-c:a", command)
            self.assertEqual(Path(command[-1]).suffix, ".mkv")
            replace_mock.assert_called_once()


if __name__ == "__main__":
    unittest.main()
