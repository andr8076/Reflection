#!/usr/bin/env python3
"""Run setup installers exposed by Reflection task modules."""

from __future__ import annotations

import argparse
import getpass
import json
import logging
import os
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence
from urllib.parse import urlparse

from Reflection import (
    DEFAULT_CLEANUP_ROOTS,
    DEFAULT_PC_ID,
    DEFAULT_POLL_INTERVAL,
    DEFAULT_SERVER_URL,
    DEFAULT_TASK_ISOLATION,
    DEFAULT_TASK_LOG_TAIL_BYTES,
    DEFAULT_SHOW_TASK_TERMINAL,
    DEFAULT_TASK_TIMEOUT_SECONDS,
    DEFAULT_TRANSFER_AUTH,
    TaskDefinition,
    default_config_path,
    discover_tasks,
    load_agent_config,
)

EXIT_OK = 0
EXIT_INSTALL_FAILED = 1
EXIT_BAD_TASK = 2

LOGGER = logging.getLogger(__name__)


def _prompt_value(prompt: str, default: str, *, interactive: bool) -> str:
    """Ask for one config value, returning the default when input is blank."""
    if not interactive:
        return default

    value = input(f"{prompt} [{default}]: ").strip()
    return value or default


def _prompt_secret(prompt: str, default: str, *, interactive: bool) -> str:
    """Ask for one secret config value without echoing newly typed input."""
    if not interactive:
        return default

    default_label = "configured" if default else "blank"
    value = getpass.getpass(f"{prompt} [{default_label}]: ")
    return value if value else default


def _validate_server_url(value: str) -> str:
    """Validate and normalize the master API URL."""
    server_url = value.strip()
    parsed = urlparse(server_url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ValueError("Server URL must be an absolute http:// or https:// URL.")
    return server_url


def _validate_poll_interval(value: str) -> int:
    """Validate and normalize the idle polling interval."""
    try:
        poll_interval = int(value)
    except ValueError as exc:
        raise ValueError("Poll interval must be a whole number of seconds.") from exc
    if poll_interval <= 0:
        raise ValueError("Poll interval must be greater than zero.")
    return poll_interval


def _validate_non_negative_seconds(value: str, field_name: str) -> int:
    """Validate a non-negative timeout value."""
    try:
        seconds = int(value)
    except ValueError as exc:
        raise ValueError(f"{field_name} must be a whole number of seconds.") from exc
    if seconds < 0:
        raise ValueError(f"{field_name} must be zero or greater.")
    return seconds


def _validate_log_tail_bytes(value: str) -> int:
    """Validate how much task output should be retained in error messages."""
    try:
        size = int(value)
    except ValueError as exc:
        raise ValueError("Task log tail bytes must be a whole number.") from exc
    if size < 1024:
        raise ValueError("Task log tail bytes must be at least 1024.")
    return size


def _validate_bool(value: str) -> bool:
    """Parse simple yes/no values."""
    normalized = value.strip().lower()
    if normalized in {"1", "true", "yes", "y", "on"}:
        return True
    if normalized in {"0", "false", "no", "n", "off"}:
        return False
    raise ValueError("Expected yes or no.")


def _validate_pc_id(value: str) -> str:
    """Validate and normalize the worker identifier sent to the master."""
    pc_id = value.strip()
    if not pc_id:
        raise ValueError("PC ID cannot be empty.")
    return pc_id


def _parse_cleanup_roots(value: str | Sequence[str]) -> list[str]:
    """Normalize cleanup roots from prompt/config values."""
    if isinstance(value, str):
        raw_roots = [part.strip() for part in value.split(os.pathsep)]
    else:
        raw_roots = [str(part).strip() for part in value]

    return [str(Path(root).expanduser().resolve()) for root in raw_roots if root]


def _default_transfer_port(scheme: str) -> int:
    """Return the conventional port for a transfer protocol."""
    return 22 if scheme == "sftp" else 21


def _validate_transfer_scheme(value: str) -> str:
    """Validate the optional worker transfer protocol."""
    scheme = value.strip().lower()
    if scheme in {"", "none", "disabled", "off"}:
        return "none"
    if scheme not in {"ftp", "sftp"}:
        raise ValueError("Transfer protocol must be ftp, sftp, or none.")
    return scheme


def _validate_transfer_port(value: str, scheme: str) -> int:
    """Validate and normalize the transfer port."""
    try:
        port = int(value)
    except ValueError as exc:
        raise ValueError("Transfer port must be a whole number.") from exc
    if port <= 0:
        raise ValueError("Transfer port must be greater than zero.")
    return port


def _current_transfer_auth(current_config: Mapping[str, Any]) -> dict[str, str | int]:
    """Return transfer defaults for setup prompts."""
    configured = current_config.get("transfer_auth")
    if not isinstance(configured, Mapping):
        configured = {}

    scheme = str(configured.get("scheme") or DEFAULT_TRANSFER_AUTH["scheme"]).lower()
    if scheme not in {"ftp", "sftp"}:
        scheme = DEFAULT_TRANSFER_AUTH["scheme"]

    return {
        "scheme": scheme,
        "host": str(configured.get("host") or DEFAULT_TRANSFER_AUTH["host"]),
        "port": int(configured.get("port") or _default_transfer_port(scheme)),
        "username": str(configured.get("username") or DEFAULT_PC_ID),
        "password": str(configured.get("password") or DEFAULT_TRANSFER_AUTH["password"]),
    }


def collect_transfer_auth(
    current_config: Mapping[str, Any],
    *,
    interactive: bool,
) -> dict[str, str | int] | None:
    """Prompt for optional local FTP/SFTP credentials used by file transfers."""
    current_transfer = _current_transfer_auth(current_config)
    scheme = _validate_transfer_scheme(
        _prompt_value(
            "File transfer protocol (ftp, sftp, or none)",
            str(current_transfer["scheme"]),
            interactive=interactive,
        )
    )
    if scheme == "none":
        return None

    host = _prompt_value(
        "Default transfer host (blank means use host from task URL)",
        str(current_transfer["host"]),
        interactive=interactive,
    ).strip()
    port = _validate_transfer_port(
        _prompt_value(
            "Default transfer port",
            str(current_transfer.get("port") or _default_transfer_port(scheme)),
            interactive=interactive,
        ),
        scheme,
    )
    username = _prompt_value(
        "Default transfer login username",
        str(current_transfer.get("username") or DEFAULT_PC_ID),
        interactive=interactive,
    ).strip()
    password = _prompt_secret(
        "Default transfer login password",
        str(current_transfer.get("password") or ""),
        interactive=interactive,
    )

    return {
        "scheme": scheme,
        "host": host,
        "port": port,
        "username": username,
        "password": password,
    }


def collect_agent_config(
    config_path: Path | None = None,
    *,
    interactive: bool | None = None,
) -> dict[str, Any]:
    """Prompt for every agent setting needed by Reflection.py."""
    path = config_path or default_config_path()
    try:
        current_config = load_agent_config(path)
    except Exception as exc:  # noqa: BLE001 - bad config should not prevent repair.
        LOGGER.warning("Could not read existing config %s: %s", path, exc)
        current_config = {
            "server_url": DEFAULT_SERVER_URL,
            "poll_interval": DEFAULT_POLL_INTERVAL,
            "pc_id": DEFAULT_PC_ID,
            "cleanup_roots": list(DEFAULT_CLEANUP_ROOTS),
            "task_timeout_seconds": DEFAULT_TASK_TIMEOUT_SECONDS,
            "task_timeouts": {},
            "task_log_tail_bytes": DEFAULT_TASK_LOG_TAIL_BYTES,
            "task_isolation": DEFAULT_TASK_ISOLATION,
            "show_task_terminal": DEFAULT_SHOW_TASK_TERMINAL,
            "min_free_space_gb": 5,
            "min_free_space_multiplier": 2.0,
            "local_temp_max_age_hours": 24,
            "quarantine_keep_days": 14,
        }

    should_prompt = sys.stdin.isatty() if interactive is None else interactive
    if should_prompt:
        print("Reflection agent configuration")
        print("Press Enter to keep the value shown in brackets.\n")
    else:
        LOGGER.info("No interactive stdin available; writing current/default agent config.")

    server_url = _validate_server_url(
        _prompt_value(
            "Master API URL",
            str(current_config.get("server_url") or DEFAULT_SERVER_URL),
            interactive=should_prompt,
        )
    )
    poll_interval = _validate_poll_interval(
        _prompt_value(
            "Idle poll interval in seconds",
            str(current_config.get("poll_interval") or DEFAULT_POLL_INTERVAL),
            interactive=should_prompt,
        )
    )
    pc_id = _validate_pc_id(
        _prompt_value(
            "Worker PC ID",
            str(current_config.get("pc_id") or DEFAULT_PC_ID),
            interactive=should_prompt,
        )
    )

    current_cleanup_roots = _parse_cleanup_roots(
        current_config.get("cleanup_roots") or DEFAULT_CLEANUP_ROOTS
    )
    cleanup_roots = _parse_cleanup_roots(
        _prompt_value(
            f"Allowed cleanup roots, separated by {os.pathsep!r} (blank disables source cleanup)",
            os.pathsep.join(current_cleanup_roots),
            interactive=should_prompt,
        )
    )

    task_isolation = _validate_bool(
        _prompt_value(
            "Run task modules in an isolated subprocess? (yes/no)",
            "yes" if bool(current_config.get("task_isolation", DEFAULT_TASK_ISOLATION)) else "no",
            interactive=should_prompt,
        )
    )
    show_task_terminal = _validate_bool(
        _prompt_value(
            "Open a visible terminal window for each isolated task? (yes/no)",
            "yes" if bool(current_config.get("show_task_terminal", DEFAULT_SHOW_TASK_TERMINAL)) else "no",
            interactive=should_prompt,
        )
    )
    task_timeout_seconds = _validate_non_negative_seconds(
        _prompt_value(
            "Default max task runtime in seconds (0 disables timeout)",
            str(current_config.get("task_timeout_seconds") or DEFAULT_TASK_TIMEOUT_SECONDS),
            interactive=should_prompt,
        ),
        "Default max task runtime",
    )
    task_log_tail_bytes = _validate_log_tail_bytes(
        _prompt_value(
            "Task log tail bytes included in failure reports",
            str(current_config.get("task_log_tail_bytes") or DEFAULT_TASK_LOG_TAIL_BYTES),
            interactive=should_prompt,
        )
    )

    config: dict[str, Any] = {
        "server_url": server_url,
        "poll_interval": poll_interval,
        "pc_id": pc_id,
        "cleanup_roots": cleanup_roots,
        "task_isolation": task_isolation,
        "show_task_terminal": show_task_terminal,
        "task_timeout_seconds": task_timeout_seconds,
        "task_timeouts": dict(current_config.get("task_timeouts") or {}),
        "task_log_tail_bytes": task_log_tail_bytes,
    }
    transfer_auth = collect_transfer_auth(current_config, interactive=should_prompt)
    if transfer_auth is not None:
        config["transfer_auth"] = transfer_auth
    return config


def write_agent_config(config: Mapping[str, Any], config_path: Path | None = None) -> Path:
    """Persist agent configuration for Reflection.py."""
    path = config_path or default_config_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_name(f".{path.name}.tmp")
    temporary_path.write_text(json.dumps(config, indent=2) + "\n", encoding="utf-8")
    temporary_path.replace(path)
    return path


def configure_agent(config_path: Path | None = None, *, interactive: bool | None = None) -> Path:
    """Collect and save Reflection.py runtime configuration."""
    config = collect_agent_config(config_path, interactive=interactive)
    path = write_agent_config(config, config_path)
    LOGGER.info("Saved Reflection agent config to %s", path)
    return path


@dataclass(frozen=True)
class SetupResult:
    """Outcome from one task setup attempt."""

    task_name: str
    status: str
    message: str = ""

    @property
    def failed(self) -> bool:
        return self.status == "failed"


def installable_task_names(registry: Mapping[str, TaskDefinition]) -> list[str]:
    """Return sorted task names that expose an install() hook."""
    return sorted(
        task_name
        for task_name, definition in registry.items()
        if definition.install is not None
    )


def selected_task_names(
    registry: Mapping[str, TaskDefinition], requested: Iterable[str]
) -> list[str]:
    """Resolve CLI task selections into task names to process."""
    requested_names = list(dict.fromkeys(requested))
    if not requested_names:
        return installable_task_names(registry)

    unknown = sorted(set(requested_names) - set(registry))
    if unknown:
        available = ", ".join(sorted(registry)) or "none"
        raise KeyError(
            f"Unknown task(s): {', '.join(unknown)}. Available tasks: {available}"
        )
    return requested_names


def run_setups(
    task_names: Iterable[str] = (),
    *,
    stop_on_error: bool = False,
    registry: Mapping[str, TaskDefinition] | None = None,
) -> list[SetupResult]:
    """Run install() for every selected task and return SetupResult entries."""
    resolved_registry = registry or discover_tasks()
    results: list[SetupResult] = []

    for task_name in selected_task_names(resolved_registry, task_names):
        definition = resolved_registry[task_name]
        if definition.install is None:
            result = SetupResult(task_name, "skipped", "No install() function.")
            LOGGER.info("Skipping %s: %s", task_name, result.message)
            results.append(result)
            continue

        LOGGER.info("Running setup for task '%s'...", task_name)
        try:
            definition.install()
        except Exception as exc:  # noqa: BLE001 - installers should not abort summary output.
            result = SetupResult(task_name, "failed", str(exc))
            LOGGER.error("Setup failed for task '%s': %s", task_name, exc)
            results.append(result)
            if stop_on_error:
                break
        else:
            result = SetupResult(task_name, "ok", "install() completed.")
            LOGGER.info("Setup finished for task '%s'.", task_name)
            results.append(result)

    return results


def print_task_list(registry: Mapping[str, TaskDefinition] | None = None) -> None:
    """Print every discovered task and whether it exposes install()."""
    resolved_registry = registry or discover_tasks()
    for task_name in sorted(resolved_registry):
        definition = resolved_registry[task_name]
        setup_state = "installer" if definition.install is not None else "no installer"
        description = f" - {definition.description}" if definition.description else ""
        print(f"{task_name}: {setup_state}{description}")


def print_summary(results: Sequence[SetupResult]) -> None:
    """Print a stable machine-readable-ish summary for operators."""
    print("\nReflection setup summary:")
    if not results:
        print("- no task installers found")
        return

    for result in results:
        print(f"- {result.task_name}: {result.status} ({result.message})")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Run setup/install functions from Reflection task modules.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument(
        "--task",
        action="append",
        default=[],
        help=(
            "Task name to set up. Repeat to run multiple tasks. "
            "Defaults to every task with install()."
        ),
    )
    parser.add_argument(
        "--list",
        action="store_true",
        help="List discovered tasks and exit without running installers.",
    )
    parser.add_argument(
        "--config-file",
        type=Path,
        default=None,
        help="Path for the Reflection.py agent config file.",
    )
    parser.add_argument(
        "--config-only",
        action="store_true",
        help="Write the agent config file and exit without running task installers.",
    )
    parser.add_argument(
        "--skip-agent-config",
        action="store_true",
        help="Do not prompt for or write Reflection.py agent configuration.",
    )
    parser.add_argument(
        "--accept-defaults",
        action="store_true",
        help="Write current/default config values without prompting.",
    )
    parser.add_argument(
        "--stop-on-error",
        action="store_true",
        help="Stop after the first failed installer instead of reporting all failures.",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Enable debug logging.",
    )
    return parser


def configure_logging(verbose: bool = False) -> None:
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s - [%(levelname)s] - %(message)s",
        force=True,
    )


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    configure_logging(args.verbose)

    if args.list:
        print_task_list()
        return EXIT_OK

    if not args.skip_agent_config:
        try:
            configure_agent(args.config_file, interactive=False if args.accept_defaults else None)
        except ValueError as exc:
            print(f"Invalid agent configuration: {exc}", file=sys.stderr)
            return EXIT_BAD_TASK

    if args.config_only:
        return EXIT_OK

    try:
        results = run_setups(args.task, stop_on_error=args.stop_on_error)
    except KeyError as exc:
        print(str(exc), file=sys.stderr)
        return EXIT_BAD_TASK

    print_summary(results)
    return EXIT_INSTALL_FAILED if any(result.failed for result in results) else EXIT_OK


if __name__ == "__main__":
    raise SystemExit(main())
