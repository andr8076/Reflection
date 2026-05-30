import json
import sys
import tempfile
import unittest
from pathlib import Path
from urllib.parse import urlparse

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

import Reflection
import run_setup


class AgentConfigTest(unittest.TestCase):
    def test_load_agent_config_uses_file_values(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps(
                    {
                        "server_url": "https://farm.example.test/farm_api.php",
                        "poll_interval": 15,
                        "pc_id": "worker-01",
                    }
                ),
                encoding="utf-8",
            )

            self.assertEqual(
                Reflection.load_agent_config(config_path),
                {
                    "server_url": "https://farm.example.test/farm_api.php",
                    "poll_interval": 15,
                    "pc_id": "worker-01",
                    "api_token": "",
                    "cleanup_roots": [],
                },
            )

    def test_write_agent_config_persists_reflection_runtime_values(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"
            run_setup.write_agent_config(
                {
                    "server_url": "http://localhost/farm_api.php",
                    "poll_interval": 5,
                    "pc_id": "local-worker",
                    "api_token": "",
                    "cleanup_roots": [],
                },
                config_path,
            )

            self.assertEqual(
                Reflection.load_agent_config(config_path),
                {
                    "server_url": "http://localhost/farm_api.php",
                    "poll_interval": 5,
                    "pc_id": "local-worker",
                    "api_token": "",
                    "cleanup_roots": [],
                },
            )

    def test_load_agent_config_accepts_api_token_and_cleanup_roots(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            cleanup_root = Path(temp_dir) / "worker-input"
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps(
                    {
                        "server_url": "https://farm.example.test/farm_api.php",
                        "poll_interval": 15,
                        "pc_id": "worker-01",
                        "api_token": "shared-secret",
                        "cleanup_roots": [str(cleanup_root)],
                    }
                ),
                encoding="utf-8",
            )

            loaded = Reflection.load_agent_config(config_path)

            self.assertEqual(loaded["api_token"], "shared-secret")
            self.assertEqual(loaded["cleanup_roots"], [str(cleanup_root.resolve())])

    def test_load_agent_config_accepts_local_transfer_auth(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps(
                    {
                        "server_url": "https://farm.example.test/farm_api.php",
                        "poll_interval": 15,
                        "pc_id": "worker-01",
                        "transfer_auth": {
                            "scheme": "sftp",
                            "host": "files.example.test",
                            "port": 2222,
                            "username": "worker-01",
                            "password": "secret",
                        },
                    }
                ),
                encoding="utf-8",
            )

            self.assertEqual(
                Reflection.load_agent_config(config_path)["transfer_auth"],
                {
                    "scheme": "sftp",
                    "host": "files.example.test",
                    "port": 2222,
                    "username": "worker-01",
                    "password": "secret",
                },
            )

    def test_collect_agent_config_defaults_transfer_username_to_hostname(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"

            config = run_setup.collect_agent_config(config_path, interactive=False)

            self.assertEqual(config["transfer_auth"]["scheme"], "ftp")
            self.assertEqual(config["transfer_auth"]["port"], 21)
            self.assertEqual(config["transfer_auth"]["username"], Reflection.DEFAULT_PC_ID)

    def test_collect_agent_config_preserves_existing_sftp_transfer_auth(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps(
                    {
                        "server_url": "https://farm.example.test/farm_api.php",
                        "poll_interval": 15,
                        "pc_id": "worker-01",
                        "transfer_auth": {
                            "scheme": "sftp",
                            "host": "files.example.test",
                            "port": 2222,
                            "username": "worker-login",
                            "password": "secret",
                        },
                    }
                ),
                encoding="utf-8",
            )

            config = run_setup.collect_agent_config(config_path, interactive=False)

            self.assertEqual(
                config["transfer_auth"],
                {
                    "scheme": "sftp",
                    "host": "files.example.test",
                    "port": 2222,
                    "username": "worker-login",
                    "password": "secret",
                },
            )

    def test_transfer_connection_uses_uri_before_config_defaults(self):
        parsed = urlparse("sftp://uri-user:uri-pass@uri-host.example.test:2200/data/file.bin")

        self.assertEqual(
            Reflection._transfer_connection_details(
                parsed,
                {
                    "scheme": "ftp",
                    "host": "config-host.example.test",
                    "port": 21,
                    "username": "config-user",
                    "password": "config-pass",
                },
            ),
            ("sftp", "uri-host.example.test", 2200, "uri-user", "uri-pass"),
        )

    def test_transfer_connection_uses_config_login_when_uri_omits_login(self):
        parsed = urlparse("ftp://files.example.test/data/file.bin")

        self.assertEqual(
            Reflection._transfer_connection_details(
                parsed,
                {
                    "scheme": "ftp",
                    "host": "fallback-host.example.test",
                    "port": 2121,
                    "username": "worker-login",
                    "password": "secret",
                },
            ),
            ("ftp", "files.example.test", 2121, "worker-login", "secret"),
        )

    def test_local_transfer_auth_overrides_master_login_without_blank_password(self):
        original_local_auth = Reflection.LOCAL_TRANSFER_AUTH
        try:
            Reflection.LOCAL_TRANSFER_AUTH = {
                "scheme": "ftp",
                "host": "",
                "port": 2121,
                "username": "worker-hostname",
                "password": "",
            }

            self.assertEqual(
                Reflection._merge_transfer_auth(
                    {
                        "scheme": "ftp",
                        "host": "files.example.test",
                        "port": 21,
                        "username": "reflection",
                        "password": "master-password",
                    }
                ),
                {
                    "scheme": "ftp",
                    "host": "files.example.test",
                    "port": 2121,
                    "username": "worker-hostname",
                    "password": "master-password",
                },
            )
        finally:
            Reflection.LOCAL_TRANSFER_AUTH = original_local_auth

    def test_collect_agent_config_rejects_invalid_poll_interval(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps(
                    {
                        "server_url": "https://farm.example.test/farm_api.php",
                        "poll_interval": 0,
                        "pc_id": "worker-01",
                    }
                ),
                encoding="utf-8",
            )

            with self.assertRaises(ValueError):
                Reflection.load_agent_config(config_path)


class TaskOutcomeTest(unittest.TestCase):
    def test_plain_true_task_result_does_not_cleanup_source_by_default(self):
        outcome = Reflection._normalize_task_result(True)

        self.assertTrue(outcome.success)
        self.assertFalse(outcome.cleanup_source)

    def test_dict_task_result_does_not_cleanup_source_by_default(self):
        outcome = Reflection._normalize_task_result({"success": True})

        self.assertTrue(outcome.success)
        self.assertFalse(outcome.cleanup_source)


class TransferHandlingTest(unittest.TestCase):
    def test_ftp_delivery_directory_uploads_with_source_filename(self):
        class FakeAgent:
            def run_task(self, module_name, source, delivery, overwrite_allowed):
                self.seen_delivery = delivery
                Path(delivery).parent.mkdir(parents=True, exist_ok=True)
                Path(delivery).write_text("result", encoding="utf-8")
                return Reflection.TaskOutcome(success=True, message="task succeeded")

        fake_agent = FakeAgent()
        upload_calls = []
        original_upload = Reflection._upload_ftp_file
        try:
            def record_upload(local_path, uri, transfer_auth):
                upload_calls.append((local_path, uri, transfer_auth))

            Reflection._upload_ftp_file = record_upload
            outcome = Reflection._run_task_with_transfer_handling(
                fake_agent,
                "invert_image",
                "local-source.jpg",
                "ftp://192.168.1.35/System/images_dump",
                False,
                "job_1005",
                {},
            )
        finally:
            Reflection._upload_ftp_file = original_upload

        self.assertTrue(outcome.success)
        self.assertEqual(len(upload_calls), 1)
        self.assertEqual(
            upload_calls[0][1],
            "ftp://192.168.1.35/System/images_dump/local-source.jpg",
        )
        seen_delivery = Path(fake_agent.seen_delivery)
        self.assertEqual(seen_delivery.name, "local-source.jpg")
        self.assertEqual(seen_delivery.parent.name, "delivery")

    def test_ftp_delivery_file_uses_separate_local_output_path_when_names_match(self):
        class FakeAgent:
            def run_task(self, module_name, source, delivery, overwrite_allowed):
                self.seen_source = source
                self.seen_delivery = delivery
                self.source_exists_during_run = Path(source).exists()
                self.delivery_exists_before_write = Path(delivery).exists()
                Path(delivery).parent.mkdir(parents=True, exist_ok=True)
                Path(delivery).write_text("result", encoding="utf-8")
                return Reflection.TaskOutcome(success=True, message="task succeeded")

        fake_agent = FakeAgent()
        original_download = Reflection._download_ftp_file
        original_upload = Reflection._upload_ftp_file
        try:
            def fake_download(uri, transfer_auth, local_directory):
                source_path = Path(local_directory) / "DSC_4562.jpg"
                source_path.write_text("source", encoding="utf-8")
                return str(source_path)

            def fake_upload(local_path, uri, transfer_auth):
                self.assertEqual(Path(local_path).read_text(encoding="utf-8"), "result")

            Reflection._download_ftp_file = fake_download
            Reflection._upload_ftp_file = fake_upload
            outcome = Reflection._run_task_with_transfer_handling(
                fake_agent,
                "invert_image",
                "ftp://192.168.1.35/System/images/DSC_4562.jpg",
                "ftp://192.168.1.35/System/images_dump/DSC_4562.jpg",
                False,
                "job_1017",
                {},
            )
        finally:
            Reflection._download_ftp_file = original_download
            Reflection._upload_ftp_file = original_upload

        self.assertTrue(outcome.success)
        self.assertTrue(fake_agent.source_exists_during_run)
        self.assertFalse(fake_agent.delivery_exists_before_write)
        self.assertNotEqual(fake_agent.seen_source, fake_agent.seen_delivery)
        self.assertEqual(Path(fake_agent.seen_delivery).parent.name, "delivery")

    def test_ftp_upload_failure_marks_task_failed(self):
        class FakeAgent:
            def run_task(self, module_name, source, delivery, overwrite_allowed):
                Path(delivery).parent.mkdir(parents=True, exist_ok=True)
                Path(delivery).write_text("result", encoding="utf-8")
                return Reflection.TaskOutcome(success=True, message="task succeeded")

        original_upload = Reflection._upload_ftp_file
        try:
            def fail_upload(local_path, uri, transfer_auth):
                raise RuntimeError("553 /System/output.jpg: Permission denied.")

            Reflection._upload_ftp_file = fail_upload
            outcome = Reflection._run_task_with_transfer_handling(
                FakeAgent(),
                "invert_image",
                "local-source.jpg",
                "ftp://192.168.1.35/System/output.jpg",
                False,
                "job_1005",
                {},
            )
        finally:
            Reflection._upload_ftp_file = original_upload

        self.assertFalse(outcome.success)
        self.assertEqual(outcome.message, "553 /System/output.jpg: Permission denied.")

    def test_ftp_upload_verifies_remote_md5_after_store(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            local_path = Path(temp_dir) / "output.txt"
            local_path.write_text("verified result", encoding="utf-8")

            class FakeFtp:
                def __init__(self):
                    self.files = {}
                    self.verified = False

                def pwd(self):
                    return "/"

                def mkd(self, path):
                    return None

                def storbinary(self, command, file_obj):
                    self.files[command.removeprefix("STOR ")] = file_obj.read()

                def retrbinary(self, command, callback):
                    self.verified = True
                    callback(self.files[command.removeprefix("RETR ")])

                def close(self):
                    return None

            fake_ftp = FakeFtp()
            original_connection = Reflection._ftp_connection
            try:
                Reflection._ftp_connection = lambda parsed, transfer_auth: fake_ftp
                Reflection._upload_ftp_file(
                    local_path,
                    "ftp://user:pass@files.example.test/System/output.txt",
                    {},
                )
            finally:
                Reflection._ftp_connection = original_connection

            self.assertTrue(fake_ftp.verified)
            self.assertEqual(fake_ftp.files["/System/output.txt"], b"verified result")

    def test_ftp_upload_md5_mismatch_raises_failure(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            local_path = Path(temp_dir) / "output.txt"
            local_path.write_text("expected result", encoding="utf-8")

            class FakeFtp:
                def pwd(self):
                    return "/"

                def mkd(self, path):
                    return None

                def storbinary(self, command, file_obj):
                    file_obj.read()

                def retrbinary(self, command, callback):
                    callback(b"different result")

                def close(self):
                    return None

            original_connection = Reflection._ftp_connection
            try:
                Reflection._ftp_connection = lambda parsed, transfer_auth: FakeFtp()
                with self.assertRaisesRegex(RuntimeError, "MD5 mismatch"):
                    Reflection._upload_ftp_file(
                        local_path,
                        "ftp://user:pass@files.example.test/System/output.txt",
                        {},
                    )
            finally:
                Reflection._ftp_connection = original_connection


if __name__ == "__main__":
    unittest.main()
