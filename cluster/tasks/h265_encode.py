"""Reflection H.265 task backed by the auto-updating 265Encode dependency."""

from __future__ import annotations

import argparse
import json
import logging
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

from encoder_dependency import (  # noqa: E402
    PROBE_CACHE_PATH,
    EncoderDependency,
    EncoderDependencyError,
    ensure_265encode,
)

TASK_NAME = "h265_encode"
DISPLAY_NAME = "265Encode"
DESCRIPTION = (
    "Encode video to H.265/HEVC MKV with the auto-updating 265Encode GitHub project; "
    "preserve all streams, chapters, and metadata."
)
TASK_SPEC_JSON = r'''
{
  "name": "h265_encode",
  "display_name": "265Encode",
  "description": "Encode video to H.265/HEVC MKV with the auto-updating 265Encode GitHub project. The worker refreshes 265Encode before each job and preserves all streams, chapters, and metadata.",
  "production_ready": true,
  "requirements": {
    "commands": ["git", "python3", "ffmpeg", "ffprobe"]
  },
  "dependencies": {
    "repositories": [{
      "name": "265Encode",
      "repository": "https://github.com/andr8076/265Encode",
      "branch": "main",
      "update": "before_each_h265_run",
      "protocol_version": 2
    }]
  },
  "source": {
    "mode": "required",
    "label": "Source video",
    "help": "One video file. Folders are expanded by the master into independent jobs. JSON source options use the 265Encode semantic requirements documented in docs/H265_PREFLIGHT.md."
  },
  "delivery": {
    "mode": "auto",
    "label": "H.265 MKV output",
    "help": "Written beside the source as {name}_h265.mkv when delivery is blank. All streams, chapters, and metadata are preserved.",
    "template": "{dir}/{name}_h265.mkv",
    "extension": ".mkv"
  },
  "preflight": {
    "mode": "optional_command",
    "label": "Optional 265Encode candidate evaluation",
    "help": "Only runs when an automation rule enables Optional worker command filter. Uses 265Encode protocol 2 to sample-plan the candidate and check predicted saving and quality.",
    "command": "python3 {task_file} --preflight {path}",
    "timeout_seconds": 3600,
    "profile_command_example": "python3 {task_file} --preflight {path} --profile '{\"min_saving_percent\":30}'",
    "four_k_only_example": "python3 {task_file} --preflight {path} --only-4k --min-vmaf 93",
    "hard_skips": ["already_hevc", "already_av1_or_vp9", "4k_source_by_default"],
    "sample_encode": true,
    "minimum_saving_percent": 25,
    "minimum_vmaf": 93
  },
  "output": {
    "kind": "file",
    "extension": ".mkv",
    "container": "mkv",
    "preserve_streams": true,
    "preserve_audio": true,
    "preserve_subtitles": true,
    "preserve_chapters": true,
    "preserve_metadata": true,
    "preserve_attachments": true,
    "encoded_streams": ["video:0"]
  }
}
'''
TASK_SPEC = json.loads(TASK_SPEC_JSON)

DEFAULT_QUALITY = {
    "mode": "required",
    "metric": "vmaf",
    "target": 92.0,
    "p10_minimum": 88.0,
    "sustained_floor": 86.0,
    "maximum_sustained_seconds": 1.0,
}
DEFAULT_OPTIMIZATION = {"primary": "smallest_output", "secondary": "fastest_encoding"}
DEFAULT_VIDEO = {"maximum_height": None, "denoise": "auto"}
DEFAULT_AUDIO = {"mode": "copy_all"}
DEFAULT_EVALUATION = {"sample_seconds": 3}
LEGACY_PROFILES = {
    "auto": 92.0,
    "standard": 92.0,
    "4k": 92.0,
    "4k_quality": 95.0,
    "space_saver": 88.0,
}
UNSUPPORTED_TUNING_KEYS = ("crf", "preset", "x265_params", "pixel_format", "pix_fmt")
EFFICIENT_CODECS = {"hevc", "h265", "av1", "vp9"}
DEFAULT_PREFLIGHT_OPTIONS = {
    "skip_4k": True,
    "only_4k": False,
    "skip_efficient_codecs": True,
    "skip_hevc": True,
    "sample_encode": True,
    "sample_seconds": 3,
    "min_saving_percent": 25.0,
    "min_ssim": 0.985,
    "min_vmaf": 93.0,
    "quality_metric": "auto",
    "encode_profile": "auto",
    "mode": None,
    "skip_under_width": 0,
    "skip_under_height": 0,
    "skip_over_width": 0,
    "skip_over_height": 0,
}


def install() -> None:
    """Install runtime commands and refresh the linked 265Encode checkout."""
    _ensure_runtime_commands()
    dependency = ensure_265encode(update=True)
    _negotiate_protocol(dependency)
    logging.info(
        "265Encode dependency is ready: %s (%s).",
        dependency.repository,
        dependency.commit[:12],
    )


def _ensure_runtime_commands() -> None:
    missing = [
        command for command in ("git", "ffmpeg", "ffprobe")
        if shutil.which(command) is None
    ]
    if not missing:
        return

    packages = []
    if "git" in missing:
        packages.append("git")
    if "ffmpeg" in missing or "ffprobe" in missing:
        packages.append("ffmpeg")

    apt = shutil.which("apt-get") or shutil.which("apt")
    if apt is None:
        raise RuntimeError(
            "Missing required command(s): " + ", ".join(missing)
            + ". Install git and FFmpeg, then run the Reflection worker installer again."
        )

    prefix = []
    if hasattr(os, "geteuid") and os.geteuid() != 0:
        sudo = shutil.which("sudo")
        if sudo is None:
            raise RuntimeError(
                "Missing required command(s): " + ", ".join(missing)
                + "; automatic installation requires sudo on this system."
            )
        prefix = [sudo, "-n"]

    env = os.environ.copy()
    env["DEBIAN_FRONTEND"] = "noninteractive"
    logging.info("Installing H.265 worker system dependencies: %s", ", ".join(packages))
    update = subprocess.run([*prefix, apt, "update", "-y"], check=False, env=env)
    if update.returncode != 0:
        raise RuntimeError("Unable to refresh the system package list while installing H.265 dependencies.")
    install = subprocess.run([*prefix, apt, "install", "-y", *packages], check=False, env=env)
    if install.returncode != 0:
        raise RuntimeError("Unable to install H.265 worker system dependencies: " + ", ".join(packages))

    still_missing = [command for command in missing if shutil.which(command) is None]
    if still_missing:
        raise RuntimeError("Required command(s) remain unavailable after installation: " + ", ".join(still_missing))


def run(source, delivery, overwrite_allowed):
    """Encode exactly one source file through 265Encode protocol 2."""
    options = _parse_options(source)
    input_path = Path(options["path"]).expanduser().resolve()
    if not input_path.exists():
        raise FileNotFoundError(f"Source path does not exist: {input_path}")
    if not input_path.is_file():
        raise IsADirectoryError(
            "h265_encode accepts one video per job. Submit a folder through the "
            "master dashboard so it can create one job for each video."
        )

    delivery_path = Path(delivery).expanduser() if delivery else None
    output_file = _output_path(input_path, delivery_path)
    if output_file.suffix.lower() != ".mkv":
        raise ValueError(f"h265_encode delivery must end with .mkv: {output_file}")
    output_file.parent.mkdir(parents=True, exist_ok=True)
    if output_file.exists() and not overwrite_allowed:
        raise FileExistsError(f"Target delivery file exists and overwrite is disabled: {output_file}")

    analysis = _analyze_video(input_path)
    skip_hevc = _option_enabled(options.get("skip_hevc", True))
    skip_efficient = _option_enabled(options.get("skip_efficient_codecs", False))
    if analysis["codec"] in {"hevc", "h265"} and skip_hevc:
        message = f"Skipped source because it is already HEVC: {input_path}"
        logging.info(message)
        return {"success": True, "skipped": True, "message": message, "cleanup_source": False}
    if analysis["codec"] in EFFICIENT_CODECS and skip_efficient:
        message = f"Skipped source because it already uses an efficient codec ({analysis['codec']}): {input_path}"
        logging.info(message)
        return {"success": True, "skipped": True, "message": message, "cleanup_source": False}

    dependency = ensure_265encode(update=True)
    requirements = _requirements_payload(input_path, output_file, options, analysis=analysis)
    required_features = _required_features(requirements)
    _validate_dependency(dependency, required_features)

    with tempfile.TemporaryDirectory(prefix=".265encode-", dir=str(output_file.parent)) as work_dir_raw:
        work_dir = Path(work_dir_raw)
        staged_output = work_dir / output_file.name
        requirements["output"] = str(staged_output.resolve())
        plan_path = work_dir / "plan.json"
        result_path = work_dir / "result.json"
        _write_json(work_dir / "requirements.json", requirements)

        plan = _evaluate_plan(dependency, work_dir / "requirements.json", plan_path)
        if plan.get("execution", {}).get("state") != "ready":
            reason = plan.get("execution", {}).get("reason") or "265Encode rejected the sampled plan"
            quality = plan.get("prediction", {}).get("quality", {})
            score = quality.get("predicted_score")
            detail = f"; sampled quality={score}" if score is not None else ""
            raise RuntimeError(f"265Encode did not approve this encode: {reason}{detail}")

        result = _execute_plan(dependency, plan_path, result_path)
        _validate_execution_result(result, staged_output)
        if output_file.exists() and not overwrite_allowed:
            raise FileExistsError(f"Target delivery file appeared while encoding and overwrite is disabled: {output_file}")
        os.replace(staged_output, output_file)

    encoder = str(result.get("encoder") or "HEVC")
    message = f"Encoded {input_path.name} with 265Encode ({encoder}) -> {output_file}"
    logging.info("%s", message)
    return {
        "success": True,
        "message": message,
        "cleanup_source": False,
        "encoder": encoder,
        "dependency_commit": dependency.commit,
    }


def _parse_options(source: Any) -> dict[str, Any]:
    if source is None or str(source).strip() == "":
        raise ValueError("Source path is required for h265_encode.")
    raw_source = str(source).strip()
    try:
        parsed = json.loads(raw_source)
    except json.JSONDecodeError:
        return {"path": raw_source}
    if isinstance(parsed, str):
        return {"path": parsed}
    if not isinstance(parsed, dict):
        raise ValueError("h265_encode JSON source must be an object or string path.")
    if not parsed.get("path"):
        raise ValueError("h265_encode JSON source must include a path value.")
    return parsed


def _option_enabled(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "y", "on"}
    return bool(value)


def _analyze_video(input_file: Path) -> dict[str, Any]:
    try:
        result = subprocess.run(
            [
                "ffprobe",
                "-v",
                "error",
                "-select_streams",
                "v:0",
                "-show_entries",
                "stream=codec_name,width,height",
                "-show_entries",
                "format=duration,size",
                "-of",
                "json",
                str(input_file),
            ],
            check=True,
            capture_output=True,
            text=True,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise RuntimeError(f"Could not inspect video with ffprobe: {exc}") from exc

    payload = json.loads(result.stdout or "{}")
    streams = payload.get("streams", [])
    if not streams:
        raise RuntimeError(f"Could not read a video stream from: {input_file}")
    stream = streams[0]
    fmt = payload.get("format", {}) if isinstance(payload.get("format"), dict) else {}
    codec = str(stream.get("codec_name") or "").lower()
    width = int(stream.get("width") or 0)
    height = int(stream.get("height") or 0)
    duration = float(fmt.get("duration") or 0.0)
    size = int(float(fmt.get("size") or input_file.stat().st_size))
    if not codec or width <= 0 or height <= 0:
        raise RuntimeError(f"Could not read video codec and resolution from: {input_file}")
    logging.info("%s codec=%s resolution=%sx%s size=%s", input_file, codec, width, height, size)
    return {"codec": codec, "width": width, "height": height, "duration": duration, "size": size}


def _output_path(input_file: Path, delivery_path: Path | None) -> Path:
    if delivery_path is None:
        return input_file.with_name(f"{input_file.stem}_h265.mkv")
    if not delivery_path.is_dir() and delivery_path.suffix:
        return delivery_path
    return delivery_path / f"{input_file.stem}_h265.mkv"


def _requirements_payload(
    input_path: Path,
    output_path: Path,
    options: dict[str, Any],
    *,
    analysis: dict[str, Any] | None = None,
) -> dict[str, Any]:
    unsupported = [key for key in UNSUPPORTED_TUNING_KEYS if options.get(key) not in (None, "")]
    if unsupported:
        joined = ", ".join(unsupported)
        raise ValueError(
            f"265Encode owns encoder tuning; remove legacy FFmpeg setting(s): {joined}. "
            "Use semantic quality, optimization, video, and audio options instead."
        )

    mode = str(options.get("mode") or "").strip().lower()
    policy_aliases = {
        "": "auto_hardware_only",
        "auto": "auto_hardware_only",
        "hardware": "auto_hardware_only",
        "hw": "auto_hardware_only",
        "auto_hardware_only": "auto_hardware_only",
        "software": "manual_software",
        "manual_software": "manual_software",
    }
    if mode not in policy_aliases:
        raise ValueError("mode must be auto, hardware, or software.")
    mode_policy = policy_aliases[mode]
    hardware_policy = str(options.get("hardware_policy") or mode_policy).strip().lower()
    if hardware_policy not in {"auto_hardware_only", "manual_software"}:
        raise ValueError("hardware_policy must be auto_hardware_only or manual_software.")
    if options.get("hardware_policy") and mode and hardware_policy != mode_policy:
        raise ValueError("mode and hardware_policy request conflicting encoder policies.")

    requested_encoder = options.get("requested_encoder", options.get("encoder"))
    if requested_encoder in (None, "", "auto"):
        requested_encoder = None
    else:
        requested_encoder = str(requested_encoder).strip()

    quality = dict(DEFAULT_QUALITY)
    nested_quality = options.get("quality")
    if nested_quality is not None:
        if not isinstance(nested_quality, dict):
            raise ValueError("quality must be an object.")
        quality.update(nested_quality)

    profile_name = str(options.get("encode_profile", options.get("profile", "auto")) or "auto").strip().lower().replace("-", "_")
    if profile_name not in LEGACY_PROFILES:
        raise ValueError(
            "encode_profile must be auto, standard, 4k, 4k_quality, or space_saver. "
            "These names select semantic quality targets; 265Encode chooses the actual encoder recipe."
        )
    if "target" not in (nested_quality or {}):
        quality["target"] = LEGACY_PROFILES[profile_name]

    quality_mode = options.get("quality_mode")
    if quality_mode not in (None, ""):
        quality["mode"] = str(quality_mode).strip().lower()
    quality_metric = options.get("quality_metric")
    if quality_metric not in (None, "", "auto"):
        normalized_metric = str(quality_metric).strip().lower()
        if normalized_metric in {"ssim", "ssim_percent"}:
            quality["metric"] = "ssim_percent"
        elif normalized_metric in {"vmaf"}:
            quality["metric"] = "vmaf"
        elif normalized_metric in {"none", "off"}:
            quality["mode"] = "off"
        else:
            raise ValueError("quality_metric must be auto, vmaf, ssim, or none.")
    if options.get("min_vmaf") not in (None, ""):
        quality["metric"] = "vmaf"
        quality["target"] = float(options["min_vmaf"])
    if options.get("min_ssim") not in (None, ""):
        score = float(options["min_ssim"])
        quality["metric"] = "ssim_percent"
        quality["target"] = score * 100 if score <= 1 else score
    quality["target"] = float(quality.get("target", 92.0))
    if "p10_minimum" not in (nested_quality or {}):
        quality["p10_minimum"] = max(0.0, quality["target"] - 4)
    else:
        quality["p10_minimum"] = float(quality["p10_minimum"])
    if "sustained_floor" not in (nested_quality or {}):
        quality["sustained_floor"] = max(0.0, quality["target"] - 6)
    else:
        quality["sustained_floor"] = float(quality["sustained_floor"])
    quality["maximum_sustained_seconds"] = float(quality.get("maximum_sustained_seconds", 1.0))

    optimization = dict(DEFAULT_OPTIMIZATION)
    nested_optimization = options.get("optimization")
    if nested_optimization is not None:
        if not isinstance(nested_optimization, dict):
            raise ValueError("optimization must be an object.")
        optimization.update(nested_optimization)

    video = dict(DEFAULT_VIDEO)
    nested_video = options.get("video")
    if nested_video is not None:
        if not isinstance(nested_video, dict):
            raise ValueError("video must be an object.")
        video.update(nested_video)
    if options.get("maximum_height") not in (None, ""):
        video["maximum_height"] = int(options["maximum_height"])
    if options.get("denoise") not in (None, ""):
        video["denoise"] = str(options["denoise"]).strip().lower()

    audio = dict(DEFAULT_AUDIO)
    nested_audio = options.get("audio")
    if nested_audio is not None:
        if not isinstance(nested_audio, dict):
            raise ValueError("audio must be an object.")
        audio.update(nested_audio)
    if options.get("audio_mode") not in (None, ""):
        audio_mode = str(options["audio_mode"]).strip().lower()
        audio["mode"] = {"copy": "copy_all", "copy_all": "copy_all", "archive_optimize": "archive_optimize"}.get(audio_mode, audio_mode)

    evaluation = dict(DEFAULT_EVALUATION)
    nested_evaluation = options.get("evaluation")
    if nested_evaluation is not None:
        if not isinstance(nested_evaluation, dict):
            raise ValueError("evaluation must be an object.")
        evaluation.update(nested_evaluation)
    if options.get("sample_seconds") not in (None, ""):
        evaluation["sample_seconds"] = options["sample_seconds"]
    evaluation["sample_seconds"] = float(evaluation["sample_seconds"])

    return {
        "schema": "encode265.requirements",
        "protocol_version": 2,
        "input": str(input_path.expanduser().resolve()),
        "output": str(output_path.expanduser().resolve()),
        "hardware_policy": hardware_policy,
        "requested_encoder": requested_encoder,
        "quality": {
            "mode": quality["mode"],
            "metric": quality["metric"],
            "target": quality["target"],
            "p10_minimum": quality["p10_minimum"],
            "sustained_floor": quality["sustained_floor"],
            "maximum_sustained_seconds": quality["maximum_sustained_seconds"],
        },
        "optimization": optimization,
        "video": video,
        "preservation": {"streams": "all", "chapters": True, "metadata": True},
        "audio": audio,
        "evaluation": evaluation,
    }


def _required_features(requirements: dict[str, Any]) -> set[str]:
    required = {
        "exact_output",
        "atomic_result",
        "preserve_all",
        "full_decode_validation",
        "semantic_planning",
        "opaque_plan_id",
        "fingerprint_invalidation",
        "sampled_predictions",
    }
    if requirements.get("requested_encoder") is not None:
        required.add("semantic_requested_encoder")
    if requirements.get("quality", {}).get("mode") == "off":
        required.add("semantic_quality_off")
    video = requirements.get("video", {})
    if video.get("maximum_height") is not None:
        required.add("semantic_scaling")
    if video.get("denoise") not in (None, "auto"):
        required.add("semantic_denoise")
    if requirements.get("audio", {}).get("mode") == "archive_optimize":
        required.add("semantic_audio_optimize")
    return required


def _dependency_command(
    dependency: EncoderDependency,
    arguments: list[str],
    *,
    timeout: int | None = None,
) -> subprocess.CompletedProcess[str]:
    try:
        result = subprocess.run(
            [str(dependency.script), *arguments],
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise EncoderDependencyError(f"Could not run 265Encode {arguments[0]}: {exc}") from exc
    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "no output").strip()
        raise EncoderDependencyError(
            f"265Encode {arguments[0]} failed with exit code {result.returncode}: {detail[-4000:]}"
        )
    return result


def _negotiate_protocol(dependency: EncoderDependency) -> dict[str, Any]:
    result = _dependency_command(dependency, ["--machine-negotiate", "2"], timeout=30)
    try:
        payload = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise EncoderDependencyError("265Encode returned invalid protocol negotiation JSON.") from exc
    if payload.get("compatible") is not True or payload.get("selected_protocol_version") != 2:
        raise EncoderDependencyError("The installed 265Encode version does not support protocol 2.")
    return payload


def _capability_report(dependency: EncoderDependency) -> dict[str, Any]:
    cache_path = PROBE_CACHE_PATH
    if cache_path.is_file():
        try:
            cached = json.loads(cache_path.read_text(encoding="utf-8"))
            if cached.get("commit") == dependency.commit and isinstance(cached.get("report"), dict):
                return cached["report"]
        except (OSError, json.JSONDecodeError, AttributeError):
            pass

    result = _dependency_command(dependency, ["--machine-probe"], timeout=3600)
    try:
        report = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise EncoderDependencyError("265Encode returned invalid capability-probe JSON.") from exc
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = cache_path.with_name(f".{cache_path.name}.{os.getpid()}.tmp")
    temporary.write_text(
        json.dumps({"commit": dependency.commit, "report": report}, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    os.replace(temporary, cache_path)
    return report


def _validate_dependency(dependency: EncoderDependency, required_features: set[str]) -> None:
    _negotiate_protocol(dependency)
    report = _capability_report(dependency)
    versions = report.get("supported_protocol_versions", [])
    if 2 not in versions:
        raise EncoderDependencyError("265Encode capability probe does not advertise protocol 2.")
    features = report.get("features")
    features = features if isinstance(features, dict) else {}
    missing = sorted(name for name in required_features if features.get(name) is not True)
    if missing:
        raise EncoderDependencyError(
            "265Encode protocol 2 is missing required feature(s): " + ", ".join(missing)
        )


def _write_json(path: Path, payload: dict[str, Any]) -> None:
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def _evaluate_plan(
    dependency: EncoderDependency,
    requirements_path: Path,
    plan_path: Path,
) -> dict[str, Any]:
    command = _dependency_command(
        dependency,
        ["--machine-evaluate", str(requirements_path), "--plan-json", str(plan_path)],
        timeout=3600,
    )
    try:
        reference = json.loads(command.stdout)
        plan = json.loads(plan_path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError) as exc:
        raise RuntimeError(f"265Encode did not produce a readable plan: {exc}") from exc
    if reference.get("schema") != "encode265.plan-reference" or plan.get("schema") != "encode265.plan":
        raise RuntimeError("265Encode returned an unsupported plan response.")
    if reference.get("plan_id") != plan.get("plan_id"):
        raise RuntimeError("265Encode plan reference does not match the saved plan.")
    return plan


def _execute_plan(
    dependency: EncoderDependency,
    plan_path: Path,
    result_path: Path,
) -> dict[str, Any]:
    _dependency_command(
        dependency,
        ["--execute-plan", str(plan_path), "--result-json", str(result_path)],
        timeout=None,
    )
    try:
        return json.loads(result_path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError) as exc:
        raise RuntimeError(f"265Encode did not produce a readable execution result: {exc}") from exc


def _validate_execution_result(result: dict[str, Any], expected_output: Path) -> None:
    if result.get("schema") != "encode265.plan-result" or result.get("status") != "ok" or result.get("exit_code") != 0:
        detail = result.get("error") or result.get("executor_result") or "unknown execution result"
        raise RuntimeError(f"265Encode execution was not validated successfully: {detail}")
    actual_output = Path(str(result.get("output") or "")).resolve()
    if actual_output != expected_output.resolve():
        raise RuntimeError(
            f"265Encode wrote an unexpected output path: {actual_output} (expected {expected_output.resolve()})"
        )
    if not expected_output.is_file():
        raise RuntimeError(f"265Encode reported success but the output file is missing: {expected_output}")


def _preflight_options(options: dict[str, Any]) -> dict[str, Any]:
    config = dict(DEFAULT_PREFLIGHT_OPTIONS)
    for key in config:
        if key in options:
            config[key] = options[key]
        elif f"preflight_{key}" in options:
            config[key] = options[f"preflight_{key}"]
    return config


def _preflight_decision(include: bool, reason: str, **extra: Any) -> dict[str, Any]:
    payload = {"include": bool(include), "reason": reason}
    payload.update(extra)
    return payload


def preflight_file(
    input_file: Path | str,
    options: dict[str, Any] | None = None,
    *,
    analysis: dict[str, Any] | None = None,
    encoder_args: list[str] | None = None,
    pixel_format_args: list[str] | None = None,
) -> dict[str, Any]:
    """Evaluate one candidate with 265Encode's protocol-2 sample planner."""
    path = Path(input_file).expanduser()
    if not path.is_file():
        return _preflight_decision(False, "source path does not exist or is not a file")

    options = options or {}
    config = _preflight_options(options)
    if not _option_enabled(config["sample_encode"]):
        return _preflight_decision(
            False,
            "265Encode protocol 2 requires bounded sample evaluation; --no-sample is unsupported.",
        )
    if options.get("sample_points", options.get("preflight_sample_points")) not in (None, ""):
        return _preflight_decision(
            False,
            "265Encode selects representative sample positions internally; custom sample_points are unsupported.",
        )
    for legacy_option in UNSUPPORTED_TUNING_KEYS:
        if options.get(legacy_option, options.get(f"preflight_{legacy_option}")) not in (None, ""):
            return _preflight_decision(
                False,
                f"265Encode owns encoder tuning; remove legacy setting {legacy_option}.",
            )

    try:
        analysis = analysis or _analyze_video(path)
    except Exception as exc:
        return _preflight_decision(False, f"could not inspect source: {type(exc).__name__}: {exc}")

    codec = str(analysis.get("codec") or "").lower()
    width = int(analysis.get("width") or 0)
    height = int(analysis.get("height") or 0)
    if _option_enabled(config["skip_hevc"]) and codec in {"hevc", "h265"}:
        return _preflight_decision(False, f"already H.265/HEVC ({codec})", analysis=analysis)
    if _option_enabled(config["skip_efficient_codecs"]) and codec in EFFICIENT_CODECS:
        return _preflight_decision(False, f"already efficient codec ({codec})", analysis=analysis)

    is_4k = width >= 3840 or height >= 2160
    if _option_enabled(config["only_4k"]) and not is_4k:
        return _preflight_decision(False, f"below 4K profile ({width}x{height})", analysis=analysis)
    if _option_enabled(config["skip_4k"]) and not _option_enabled(config["only_4k"]) and is_4k:
        return _preflight_decision(False, f"4K source blocked by preflight profile ({width}x{height})", analysis=analysis)

    bounds = (
        ("skip_under_width", width, "width", "below"),
        ("skip_under_height", height, "height", "below"),
        ("skip_over_width", width, "width", "above"),
        ("skip_over_height", height, "height", "above"),
    )
    for key, actual, label, relation in bounds:
        limit = int(config.get(key) or 0)
        if limit > 0 and ((relation == "below" and actual < limit) or (relation == "above" and actual > limit)):
            return _preflight_decision(False, f"{label} {actual} {relation} preflight limit {limit}", analysis=analysis)

    try:
        dependency = ensure_265encode(update=True)
        preflight_options = dict(options)
        preflight_options["sample_seconds"] = config["sample_seconds"]
        profile_supplied = any(
            options.get(key) not in (None, "")
            for key in ("encode_profile", "profile", "preflight_encode_profile")
        )
        min_vmaf_supplied = any(
            options.get(key) not in (None, "")
            for key in ("min_vmaf", "preflight_min_vmaf")
        )
        if "encode_profile" not in preflight_options and "profile" not in preflight_options:
            if config.get("encode_profile") not in (None, ""):
                preflight_options["encode_profile"] = config["encode_profile"]
        if not preflight_options.get("mode") and config.get("mode"):
            preflight_options["mode"] = config["mode"]
        if not isinstance(preflight_options.get("quality"), dict):
            preflight_options["quality_metric"] = config["quality_metric"]
            if config["quality_metric"] in {"auto", "vmaf"}:
                if min_vmaf_supplied or not profile_supplied:
                    preflight_options["min_vmaf"] = config["min_vmaf"]
                elif profile_supplied and "encode_profile" not in preflight_options:
                    preflight_options["encode_profile"] = options.get("preflight_encode_profile", options.get("profile"))
            elif config["quality_metric"] == "ssim":
                preflight_options["min_ssim"] = config["min_ssim"]
            elif config["quality_metric"] in {"none", "off"}:
                preflight_options["quality_mode"] = "off"

        with tempfile.TemporaryDirectory(prefix="265encode-preflight-") as temp_dir_raw:
            temp_dir = Path(temp_dir_raw)
            planned_output = temp_dir / "candidate.mkv"
            requirements = _requirements_payload(path, planned_output, preflight_options, analysis=analysis)
            _validate_dependency(dependency, _required_features(requirements))
            requirements_path = temp_dir / "requirements.json"
            plan_path = temp_dir / "plan.json"
            _write_json(requirements_path, requirements)
            plan = _evaluate_plan(dependency, requirements_path, plan_path)

        execution = plan.get("execution", {})
        prediction = plan.get("prediction", {})
        quality = prediction.get("quality", {})
        size = prediction.get("size", {})
        predicted_bytes = int(size.get("predicted_output_bytes") or 0)
        source_bytes = int(analysis.get("size") or path.stat().st_size)
        saving_percent = (
            (source_bytes - predicted_bytes) / source_bytes * 100.0
            if source_bytes > 0 and predicted_bytes > 0
            else None
        )
        if execution.get("state") != "ready":
            reason = execution.get("reason") or "sample quality did not meet the requested target"
            return _preflight_decision(
                False,
                f"265Encode rejected the sample plan: {reason}",
                analysis=analysis,
                saving_percent=saving_percent,
                quality_metric=quality.get("metric"),
                quality_score=quality.get("predicted_score"),
                selected_encoder=(plan.get("selection") or {}).get("encoder"),
            )

        minimum_saving = float(config["min_saving_percent"])
        if saving_percent is not None and saving_percent < minimum_saving:
            return _preflight_decision(
                False,
                f"predicted saving {saving_percent:.1f}% is below the {minimum_saving:.1f}% minimum",
                analysis=analysis,
                saving_percent=saving_percent,
                quality_metric=quality.get("metric"),
                quality_score=quality.get("predicted_score"),
                selected_encoder=(plan.get("selection") or {}).get("encoder"),
            )

        return _preflight_decision(
            True,
            "265Encode sample plan meets the requested quality and saving thresholds",
            analysis=analysis,
            saving_percent=saving_percent,
            predicted_output_bytes=predicted_bytes,
            quality_metric=quality.get("metric"),
            quality_score=quality.get("predicted_score"),
            selected_encoder=(plan.get("selection") or {}).get("encoder"),
            encode_profile=preflight_options.get("encode_profile", "auto"),
        )
    except Exception as exc:
        return _preflight_decision(False, f"preflight failed: {type(exc).__name__}: {exc}", analysis=analysis)


def preflight_source(source: str, extra_options: dict[str, Any] | None = None) -> dict[str, Any]:
    """Run the optional protocol-2 preflight for a single file."""
    try:
        options = _parse_options(source)
        if extra_options:
            options.update(extra_options)
        path = Path(options["path"]).expanduser()
        if path.is_dir():
            return _preflight_decision(False, "automation preflight expects a single file, not a folder")
        return preflight_file(path, options)
    except Exception as exc:
        return _preflight_decision(False, f"preflight failed: {type(exc).__name__}: {exc}")


def _format_cli_result(result: dict[str, Any]) -> str:
    prefix = "queue" if result.get("include") else "skip"
    details = []
    if result.get("selected_encoder"):
        details.append(f"encoder {result['selected_encoder']}")
    if result.get("saving_percent") is not None:
        details.append(f"saving {float(result['saving_percent']):.1f}%")
    if result.get("quality_metric") not in (None, "disabled") and result.get("quality_score") is not None:
        details.append(f"{str(result['quality_metric']).upper()} {float(result['quality_score']):.4g}")
    suffix = f" ({', '.join(details)})" if details else ""
    return f"{prefix}: {result.get('reason', '')}{suffix}"


def _load_profile(value: str | None) -> dict[str, Any]:
    if not value:
        return {}
    raw = value.strip()
    if raw.startswith("@"):
        raw = Path(raw[1:]).expanduser().read_text(encoding="utf-8")
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValueError(f"profile is not valid JSON: {exc}") from exc
    if not isinstance(payload, dict):
        raise ValueError("profile JSON must be an object")
    return payload


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Reflection H.265 task helper")
    parser.add_argument("path", nargs="?", help="Video path for --preflight")
    parser.add_argument("--preflight", action="store_true", help="Evaluate a candidate through the linked 265Encode protocol-2 dependency.")
    parser.add_argument("--json", action="store_true", help="Print the preflight result as JSON.")
    parser.add_argument("--profile", help="JSON object, or @file containing JSON, that overrides the preflight profile.")
    parser.add_argument("--allow-4k", action="store_true", help="Do not skip 4K sources.")
    parser.add_argument("--only-4k", action="store_true", help="Only queue 4K sources; lower resolutions are skipped.")
    parser.add_argument("--skip-under-width", type=int, help="Skip sources below this width.")
    parser.add_argument("--skip-under-height", type=int, help="Skip sources below this height.")
    parser.add_argument("--skip-over-width", type=int, help="Skip sources above this width.")
    parser.add_argument("--skip-over-height", type=int, help="Skip sources above this height.")
    parser.add_argument("--allow-efficient-codecs", action="store_true", help="Do not skip AV1/VP9/HEVC before sample planning.")
    parser.add_argument("--no-sample", action="store_true", help="Deprecated; protocol 2 requires bounded sample evaluation.")
    parser.add_argument("--min-saving-percent", type=float, help="Required predicted output size saving percentage.")
    parser.add_argument("--sample-seconds", type=int, help="Duration of each protocol-2 sample, from 1 to 10 seconds.")
    parser.add_argument("--sample-points", help="Deprecated; 265Encode chooses representative sample positions internally.")
    parser.add_argument("--encode-profile", choices=sorted(LEGACY_PROFILES), help="Compatibility alias for a semantic quality target.")
    parser.add_argument("--mode", choices=["software", "hardware", "hw", "auto"], help="software is explicit; auto/hardware only select proven hardware.")
    parser.add_argument("--quality-metric", choices=["auto", "vmaf", "ssim", "none"], help="Quality metric for the 265Encode sample plan.")
    parser.add_argument("--min-ssim", type=float, help="Minimum mean SSIM, as a fraction or percentage.")
    parser.add_argument("--min-vmaf", type=float, help="Minimum VMAF target.")
    args = parser.parse_args(argv)

    if not args.preflight:
        parser.error("This helper only runs when --preflight is supplied. Normal task work is done by the Reflection worker.")
    if not args.path:
        print("skip: no path supplied", file=sys.stderr)
        return 1

    try:
        extra_options = _load_profile(args.profile)
        if args.allow_4k:
            extra_options["skip_4k"] = False
        if args.only_4k:
            extra_options["only_4k"] = True
        for name in ("skip_under_width", "skip_under_height", "skip_over_width", "skip_over_height"):
            value = getattr(args, name)
            if value is not None:
                extra_options[name] = value
        if args.allow_efficient_codecs:
            extra_options["skip_efficient_codecs"] = False
            extra_options["skip_hevc"] = False
        if args.no_sample:
            extra_options["sample_encode"] = False
        if args.min_saving_percent is not None:
            extra_options["min_saving_percent"] = args.min_saving_percent
        if args.sample_seconds is not None:
            extra_options["sample_seconds"] = args.sample_seconds
        if args.sample_points is not None:
            extra_options["sample_points"] = args.sample_points
        for name in ("encode_profile", "mode", "quality_metric", "min_ssim", "min_vmaf"):
            value = getattr(args, name)
            if value is not None:
                extra_options[name] = value

        result = preflight_source(args.path, extra_options)
    except Exception as exc:  # noqa: BLE001 - CLI must return a concise automation reason.
        result = _preflight_decision(False, f"preflight failed: {type(exc).__name__}: {exc}")

    if args.json:
        print(json.dumps(result, sort_keys=True))
    else:
        print(_format_cli_result(result))
    return 0 if result.get("include") else 1


if __name__ == "__main__":
    raise SystemExit(main())
