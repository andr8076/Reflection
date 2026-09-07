import sys
import tempfile
import unittest
from pathlib import Path

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

import Reflection
from agent_state import AgentStateStore


class AgentStateStoreTest(unittest.TestCase):
    def test_completion_survives_reopen_until_matching_id_is_cleared(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "outbox.json"
            store = AgentStateStore(path)
            completion = {
                "completion_id": "completion-1",
                "task_id": "job-1",
                "lease_token": "lease-1",
                "status": "success",
            }

            store.save_completion(completion)
            self.assertEqual(AgentStateStore(path).pending_completion(), completion)
            self.assertFalse(store.clear_completion("another-completion"))
            self.assertEqual(store.pending_completion(), completion)
            self.assertTrue(store.clear_completion("completion-1"))
            self.assertIsNone(store.pending_completion())

    def test_invalid_outbox_is_never_treated_as_empty(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "outbox.json"
            path.write_text("{not-json", encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "unreadable"):
                AgentStateStore(path).pending_completion()


class CompletionReplayTest(unittest.TestCase):
    def _agent_with_outbox(self, path, response):
        agent = Reflection.FarmAgent.__new__(Reflection.FarmAgent)
        agent.state_store = AgentStateStore(path)
        agent.active_task_id = ""
        agent.active_lease_token = ""
        agent.cleanup_files = lambda source: None
        agent.reload_task_registry = lambda: None
        agent.report_task_done = lambda *args, **kwargs: response
        return agent

    def test_acknowledged_completion_is_cleared_once(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "outbox.json"
            agent = self._agent_with_outbox(
                path,
                {"status": "confirmed_by_server", "shutdown_after_task": False},
            )
            agent.state_store.save_completion(
                {
                    "completion_id": "completion-1",
                    "task_id": "job-1",
                    "lease_token": "lease-1",
                    "status": "success",
                }
            )
            original_sleep = Reflection.time.sleep
            try:
                Reflection.time.sleep = lambda _seconds: None
                self.assertTrue(Reflection.FarmAgent._flush_pending_completion(agent))
            finally:
                Reflection.time.sleep = original_sleep

            self.assertIsNone(agent.state_store.pending_completion())
            self.assertEqual(agent.active_lease_token, "")

    def test_unacknowledged_completion_blocks_new_work_and_remains_durable(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "outbox.json"
            agent = self._agent_with_outbox(path, None)
            agent.state_store.save_completion(
                {
                    "completion_id": "completion-2",
                    "task_id": "job-2",
                    "lease_token": "lease-2",
                    "status": "failed",
                }
            )
            original_sleep = Reflection.time.sleep
            try:
                Reflection.time.sleep = lambda _seconds: None
                self.assertTrue(Reflection.FarmAgent._flush_pending_completion(agent))
            finally:
                Reflection.time.sleep = original_sleep

            self.assertEqual(agent.state_store.pending_completion()["completion_id"], "completion-2")


if __name__ == "__main__":
    unittest.main()
