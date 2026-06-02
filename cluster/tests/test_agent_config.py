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
                    "cleanup_roots": [],
                    "task_timeout_seconds": Reflection.DEFAULT_TASK_TIMEOUT_SECONDS,
                    "task_timeouts": {},
                    "task_log_tail_bytes": Reflection.DEFAULT_TASK_LOG_TAIL_BYTES,
                    "task_isolation": Reflection.DEFAULT_TASK_ISOLATION,
                    "show_task_terminal": Reflection.DEFAULT_SHOW_TASK_TERMINAL,
                    "min_free_space_gb": Reflection.DEFAULT_MIN_FREE_SPACE_GB,
                    "min_free_space_multiplier": Reflection.DEFAULT_MIN_FREE_SPACE_MULTIPLIER,
                    "local_temp_max_age_hours": Reflection.DEFAULT_LOCAL_TEMP_MAX_AGE_HOURS,
                    "quarantine_keep_days": Reflection.DEFAULT_QUARANTINE_KEEP_DAYS,
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
                    "cleanup_roots": [],
                    "task_timeout_seconds": Reflection.DEFAULT_TASK_TIMEOUT_SECONDS,
                    "task_timeouts": {},
                    "task_log_tail_bytes": Reflection.DEFAULT_TASK_LOG_TAIL_BYTES,
                    "task_isolation": Reflection.DEFAULT_TASK_ISOLATION,
                    "show_task_terminal": Reflection.DEFAULT_SHOW_TASK_TERMINAL,
                    "min_free_space_gb": Reflection.DEFAULT_MIN_FREE_SPACE_GB,
                    "min_free_space_multiplier": Reflection.DEFAULT_MIN_FREE_SPACE_MULTIPLIER,
                    "local_temp_max_age_hours": Reflection.DEFAULT_LOCAL_TEMP_MAX_AGE_HOURS,
                    "quarantine_keep_days": Reflection.DEFAULT_QUARANTINE_KEEP_DAYS,
                },
            )

    def test_load_agent_config_can_disable_visible_task_terminal(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps({"show_task_terminal": False}),
                encoding="utf-8",
            )

            self.assertFalse(Reflection.load_agent_config(config_path)["show_task_terminal"])

    def test_load_agent_config_accepts_cleanup_roots(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            cleanup_root = Path(temp_dir) / "worker-input"
            config_path = Path(temp_dir) / "agent.json"
            config_path.write_text(
                json.dumps(
                    {
                        "server_url": "https://farm.example.test/farm_api.php",
                        "poll_interval": 15,
                        "pc_id": "worker-01",
                        "cleanup_roots": [str(cleanup_root)],
                    }
                ),
                encoding="utf-8",
            )

            loaded = Reflection.load_agent_config(config_path)

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

    def test_master_server_details_merge_with_worker_login(self):
        original_local_auth = Reflection.LOCAL_TRANSFER_AUTH
        try:
            Reflection.LOCAL_TRANSFER_AUTH = {
                "scheme": "ftp",
                "host": "",
                "port": 2121,
                "username": "worker-hostname",
                "password": "worker-secret",
            }

            self.assertEqual(
                Reflection._merge_transfer_settings(
                    {
                        "scheme": "ftps",
                        "host": "files.example.test",
                        "port": 990,
                        "root": "/shared",
                    },
                    {
                        "username": "legacy-master-user",
                        "password": "legacy-master-password",
                    },
                ),
                {
                    "scheme": "ftps",
                    "host": "files.example.test",
                    "port": 990,
                    "root": "/shared",
                    "username": "worker-hostname",
                    "password": "worker-secret",
                },
            )
        finally:
            Reflection.LOCAL_TRANSFER_AUTH = original_local_auth

    def test_plain_worker_path_becomes_transfer_uri_when_task_requests_transfer_mode(self):
        self.assertEqual(
            Reflection._transfer_uri_from_plain_path(
                "/System/images/DSC_457122.jpg",
                {
                    "scheme": "ftp",
                    "host": "nas.example.test",
                    "port": 21,
                    "root": "",
                },
            ),
            "ftp://nas.example.test/System/images/DSC_457122.jpg",
        )

    def test_plain_worker_path_applies_optional_transfer_root(self):
        self.assertEqual(
            Reflection._transfer_uri_from_plain_path(
                "/System/images/DSC 457122.jpg",
                {
                    "scheme": "sftp",
                    "host": "nas.example.test",
                    "port": 2222,
                    "root": "/volume1",
                },
            ),
            "sftp://nas.example.test:2222/volume1/System/images/DSC%20457122.jpg",
        )

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
        self.assertFalse(outcome.restart_agent)


class WakeFarmTest(unittest.TestCase):
    def test_wake_job_reads_relay_payload_and_sends_each_target(self):
        source = json.dumps({
            "targets": [{"pc_id": "node-1", "mac": "AA:BB:CC:DD:EE:01"}, "AA:BB:CC:DD:EE:02"],
            "broadcast": "192.0.2.255",
            "port": 7,
        })
        seen = []
        original_sender = Reflection._send_wake_packet
        try:
            Reflection._send_wake_packet = lambda mac, broadcast, port: seen.append((mac, broadcast, port))
            outcome = Reflection._normalize_task_result(Reflection._system_wake_farm(source, "", False))
        finally:
            Reflection._send_wake_packet = original_sender

        self.assertTrue(outcome.success)
        self.assertEqual([
            ("AA:BB:CC:DD:EE:01", "192.0.2.255", 7),
            ("AA:BB:CC:DD:EE:02", "192.0.2.255", 7),
        ], seen)

    def test_wake_job_normalizes_subnet_mask_broadcast(self):
        source = json.dumps({
            "targets": [{"pc_id": "node-1", "mac": "AA:BB:CC:DD:EE:01"}],
            "broadcast": "255.255.255.0",
            "port": 9,
        })
        targets, broadcast, port = Reflection._normalize_wake_job(source)

        self.assertEqual(["AA:BB:CC:DD:EE:01"], targets)
        self.assertEqual("255.255.255.255", broadcast)
        self.assertEqual(9, port)

    def test_wake_job_rejects_missing_target_payload(self):
        with self.assertRaisesRegex(ValueError, "did not include any target MAC addresses"):
            Reflection._system_wake_farm(None, "", False)


class WorkerUpdateTest(unittest.TestCase):
    def test_update_worker_runs_updater_and_requests_reboot(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            update_script = Path(temp_dir) / "update.sh"
            update_script.write_text("#!/usr/bin/env bash\necho fixture update complete\n", encoding="utf-8")
            update_script.chmod(0o755)
            original_update_script = Reflection.UPDATE_SCRIPT_PATH
            try:
                Reflection.UPDATE_SCRIPT_PATH = update_script
                outcome = Reflection._normalize_task_result(Reflection._system_update_worker("", "", False))
            finally:
                Reflection.UPDATE_SCRIPT_PATH = original_update_script

            self.assertTrue(outcome.success)
            self.assertTrue(outcome.reboot_system)
            self.assertFalse(outcome.restart_agent)
            self.assertFalse(outcome.stop_agent)
            self.assertIn("fixture update complete", outcome.message)

    def test_update_worker_reports_updater_failures(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            update_script = Path(temp_dir) / "update.sh"
            update_script.write_text("#!/usr/bin/env bash\necho fixture update failed >&2\nexit 7\n", encoding="utf-8")
            update_script.chmod(0o755)
            original_update_script = Reflection.UPDATE_SCRIPT_PATH
            try:
                Reflection.UPDATE_SCRIPT_PATH = update_script
                with self.assertRaisesRegex(RuntimeError, "fixture update failed"):
                    Reflection._system_update_worker("", "", False)
            finally:
                Reflection.UPDATE_SCRIPT_PATH = original_update_script

    def test_update_worker_can_pin_exact_commit(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            update_script = Path(temp_dir) / "update.sh"
            args_path = Path(temp_dir) / "args.txt"
            update_script.write_text(
                '#!/usr/bin/env bash\nprintf \'%s\\n\' "$@" > args.txt\necho pinned update complete\n',
                encoding="utf-8",
            )
            update_script.chmod(0o755)
            original_update_script = Reflection.UPDATE_SCRIPT_PATH
            try:
                Reflection.UPDATE_SCRIPT_PATH = update_script
                outcome = Reflection._normalize_task_result(
                    Reflection._system_update_worker("abcdef123456", "", False)
                )
            finally:
                Reflection.UPDATE_SCRIPT_PATH = original_update_script

            self.assertTrue(outcome.success)
            self.assertEqual(args_path.read_text(encoding="utf-8").splitlines(), ["--commit", "abcdef123456"])

    def test_version_follow_self_updates_before_accepting_task(self):
        class RebootRequested(Exception):
            pass

        class FakeAgent:
            def check_for_task(self):
                return {
                    "status": "task_available",
                    "master_commit": "abcdef123456",
                    "version_enforced": True,
                    "version_policy": "update_now",
                    "task": {"task_id": "job_normal", "module": "dummy_task"},
                }

            def confirm_task_taken(self, task_id):
                raise AssertionError("mismatched worker must update before confirming a job")

        original_version = Reflection.VERSION
        original_update = Reflection._run_update_script
        original_reboot = Reflection._request_system_reboot
        seen = []
        try:
            Reflection.VERSION = "old000000000"
            Reflection._run_update_script = lambda commit=None: seen.append(commit) or "updated"

            def record_reboot():
                seen.append("reboot")
                raise RebootRequested()

            Reflection._request_system_reboot = record_reboot
            result = Reflection.FarmAgent._run_lifecycle_cycle(FakeAgent())
        finally:
            Reflection.VERSION = original_version
            Reflection._run_update_script = original_update
            Reflection._request_system_reboot = original_reboot

        self.assertFalse(result)
        self.assertEqual(seen, ["abcdef123456", "reboot"])

    def test_missing_master_commit_ignores_version_follow_check(self):
        class FakeAgent:
            def __init__(self):
                self.confirmed = []

            def check_for_task(self):
                return {
                    "status": "task_available",
                    "version_enforced": True,
                    "version_policy": "update_now",
                    "task": {"task_id": "job_normal", "module": "dummy_task"},
                }

            def confirm_task_taken(self, task_id):
                self.confirmed.append(task_id)
                return False

        fake = FakeAgent()
        result = Reflection.FarmAgent._run_lifecycle_cycle(fake)
        self.assertTrue(result)
        self.assertEqual(fake.confirmed, ["job_normal"])

    def test_update_worker_is_always_available_as_a_builtin(self):
        self.assertIn("update_worker", Reflection.built_in_tasks())

    def test_confirmed_update_requests_system_reboot(self):
        class RebootRequested(Exception):
            pass

        class FakeAgent:
            def check_for_task(self):
                return {
                    "status": "task_available",
                    "task": {"task_id": "job_update", "module": "update_worker"},
                }

            def confirm_task_taken(self, task_id):
                return task_id == "job_update"

            def heartbeat_task(self, task_id):
                return {"status": "heartbeat_acknowledged"}

            def report_task_done(self, task_id, success, error_message):
                return {"status": "confirmed_by_server", "shutdown_after_task": False}

            def cleanup_files(self, source):
                raise AssertionError("update task should not clean source files")

            def reload_task_registry(self):
                raise AssertionError("update task should reboot rather than reload in-process")

        original_runner = Reflection._run_task_with_transfer_handling
        original_reboot = Reflection._request_system_reboot
        seen = []
        try:
            Reflection._run_task_with_transfer_handling = lambda *args, **kwargs: Reflection.TaskOutcome(
                success=True,
                reboot_system=True,
                message="updated",
            )

            def record_reboot():
                seen.append("reboot")
                raise RebootRequested()

            Reflection._request_system_reboot = record_reboot
            with self.assertRaises(RebootRequested):
                Reflection.FarmAgent._run_lifecycle_cycle(FakeAgent())
        finally:
            Reflection._run_task_with_transfer_handling = original_runner
            Reflection._request_system_reboot = original_reboot

        self.assertEqual(seen, ["reboot"])


class ShutdownRequestTest(unittest.TestCase):
    def test_idle_shutdown_debug_mode_only_stops_agent(self):
        class FakeAgent:
            def check_for_task(self):
                return {
                    "status": "no_jobs",
                    "shutdown_after_task": True,
                    "shutdown_debug_mode": True,
                    "reason": "idle_no_job_check_limit",
                }

        original_shutdown = Reflection._request_system_shutdown
        try:
            Reflection._request_system_shutdown = lambda: (_ for _ in ()).throw(
                AssertionError("debug shutdown must not issue an OS shutdown command")
            )
            result = Reflection.FarmAgent._run_lifecycle_cycle(FakeAgent())
        finally:
            Reflection._request_system_shutdown = original_shutdown

        self.assertFalse(result)

    def test_idle_shutdown_without_debug_requests_system_shutdown(self):
        class FakeAgent:
            def check_for_task(self):
                return {
                    "status": "no_jobs",
                    "shutdown_after_task": True,
                    "shutdown_debug_mode": False,
                    "reason": "idle_no_job_check_limit",
                }

        original_shutdown = Reflection._request_system_shutdown
        seen = []
        try:
            Reflection._request_system_shutdown = lambda: seen.append("shutdown")
            result = Reflection.FarmAgent._run_lifecycle_cycle(FakeAgent())
        finally:
            Reflection._request_system_shutdown = original_shutdown

        self.assertFalse(result)
        self.assertEqual(seen, ["shutdown"])

    def test_explicit_shutdown_task_uses_master_debug_mode(self):
        class FakeAgent:
            def check_for_task(self):
                return {
                    "status": "task_available",
                    "task": {"task_id": "job_shutdown", "module": "shutdown"},
                }

            def confirm_task_taken(self, task_id):
                return task_id == "job_shutdown"

            def heartbeat_task(self, task_id):
                return {"status": "heartbeat_acknowledged"}

            def report_task_done(self, task_id, success, error_message):
                return {
                    "status": "confirmed_by_server",
                    "shutdown_after_task": False,
                    "shutdown_debug_mode": True,
                }

            def cleanup_files(self, source):
                raise AssertionError("shutdown task should not clean source files")

            def reload_task_registry(self):
                raise AssertionError("shutdown task should not reload task registry")

        original_runner = Reflection._run_task_with_transfer_handling
        original_shutdown = Reflection._request_system_shutdown
        try:
            Reflection._run_task_with_transfer_handling = lambda *args, **kwargs: Reflection.TaskOutcome(
                success=True,
                stop_agent=True,
                message="shutdown requested",
            )
            Reflection._request_system_shutdown = lambda: (_ for _ in ()).throw(
                AssertionError("debug shutdown task must not issue an OS shutdown command")
            )
            result = Reflection.FarmAgent._run_lifecycle_cycle(FakeAgent())
        finally:
            Reflection._run_task_with_transfer_handling = original_runner
            Reflection._request_system_shutdown = original_shutdown

        self.assertFalse(result)


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
