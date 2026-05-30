import json
import tempfile
import unittest
from pathlib import Path

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
                },
                config_path,
            )

            self.assertEqual(
                Reflection.load_agent_config(config_path),
                {
                    "server_url": "http://localhost/farm_api.php",
                    "poll_interval": 5,
                    "pc_id": "local-worker",
                },
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


if __name__ == "__main__":
    unittest.main()
