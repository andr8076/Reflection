"""Batch transcode videos to H.265/HEVC MKV outputs while preserving movie streams.

This module also exposes an optional H.265 preflight helper for Reflection
automation command filters. The task never runs the sample/quality preflight
by default; it only runs when the automation rule explicitly calls this file
with ``--preflight`` from its Optional command filter.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

TASK_NAME = "h265_encode"
DESCRIPTION = "Transcode the main video stream to H.265/HEVC MKV while preserving audio, subtitles, chapters, attachments, and metadata."
TASK_SPEC_JSON = r'''
{
  "name": "h265_encode",
  "description": "Transcode the main video stream to H.265/HEVC MKV while preserving the rest of the movie structure.",
  "source": {
    "mode": "required",
    "label": "Source video or folder",
    "help": "A video file or folder containing video files. JSON options may tune encoder mode, recursion, extension filters, and worker-side skip_hevc behavior."
  },
  "delivery": {
    "mode": "auto",
    "label": "H.265 MKV output",
    "help": "Automatically written beside each source as {name}_h265.mkv. Audio, subtitles, chapters, attachments, and metadata are copied when FFmpeg can preserve them.",
    "template": "{dir}/{name}_h265.mkv",
    "extension": ".mkv"
  },
  "preflight": {
    "mode": "optional_command",
    "label": "Optional H.265 candidate test",
    "help": "Only runs when an automation rule enables Optional command filter. Use command mode 'Include if command exits 0'. The task itself does not run this preflight by default.",
    "command": "python3 {task_file} --preflight {path}",
    "timeout_seconds": 900,
    "profile_command_example": "python3 {task_file} --preflight {path} --profile '{\"min_saving_percent\":30}'",
    "four_k_only_example": "python3 {task_file} --preflight {path} --only-4k --min-saving-percent 30",
    "hard_skips": ["already_h265", "already_av1_or_vp9", "4k_source_by_default"],
    "sample_encode": true,
    "minimum_saving_percent": 25,
    "minimum_ssim": 0.985,
    "minimum_vmaf": 93
  },
  "output": {
    "kind": "file_or_folder",
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


COMMON_VIDEO_EXTENSIONS = {
    "mp4",
    "mkv",
    "mov",
    "avi",
    "webm",
    "m4v",
    "ts",
    "mts",
    "m2ts",
    "wmv",
    "flv",
}

EFFICIENT_CODECS = {"hevc", "h265", "av1", "vp9"}
SOFTWARE_ARGS = ["-c:v:0", "libx265", "-crf", "20", "-preset", "slow"]
HARDWARE_ENCODERS = {
    "nvidia": {
        "ffmpeg_encoder": "hevc_nvenc",
        "args": ["-c:v:0", "hevc_nvenc", "-rc", "vbr", "-cq", "23", "-preset", "slow"],
    },
    "apple": {
        "ffmpeg_encoder": "hevc_videotoolbox",
        "args": ["-c:v:0", "hevc_videotoolbox", "-q:v", "65"],
    },
    "intel": {
        "ffmpeg_encoder": "hevc_qsv",
        "args": ["-c:v:0", "hevc_qsv", "-global_quality", "24", "-preset", "slow"],
    },
}
PIXEL_FORMAT_ARGS = ["-pix_fmt", "yuv420p10le"]

DEFAULT_PREFLIGHT_OPTIONS = {
    # This default controls the helper profile only. The main task does not run
    # preflight unless the automation Optional command filter calls --preflight.
    "enabled": True,
    "skip_4k": True,
    "skip_efficient_codecs": True,
    "skip_hevc": True,
    "sample_encode": True,
    "sample_seconds": 24,
    "sample_points": [0.12, 0.35, 0.60, 0.82],
    "min_saving_percent": 25.0,
    "min_ssim": 0.985,
    "min_vmaf": 93.0,
    "quality_metric": "auto",
    "only_4k": False,
    "skip_under_width": 0,
    "skip_under_height": 0,
    "skip_over_width": 0,
    "skip_over_height": 0,
}


def install():
    """Install/validate FFmpeg dependencies needed by this task."""
    if shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None:
        _install_system_packages(["ffmpeg"])

    _require_tool("ffmpeg")
    _require_tool("ffprobe")

    if not _ffmpeg_encoder_available("libx265"):
        raise RuntimeError(
            "ffmpeg is installed, but the libx265 encoder is not available. "
            "Install an FFmpeg build with x265/HEVC support."
        )

    if not _ffmpeg_filter_available("ssim"):
        logging.warning("FFmpeg SSIM filter is not available; h265 preflight quality checks will use size-only fallback.")
    if not _ffmpeg_filter_available("libvmaf"):
        logging.info("FFmpeg libvmaf filter is not available; h265 preflight will use SSIM when available.")

    logging.info("h265_encode dependencies are available.")


def run(source, delivery, overwrite_allowed):
    """Transcode one source file or every matching video in a source folder."""
    options = _parse_options(source)
    input_path = Path(options["path"]).expanduser()
    if not input_path.exists():
        raise FileNotFoundError(f"Source path does not exist: {input_path}")

    _require_tool("ffmpeg")
    _require_tool("ffprobe")

    allowed_extensions = _normalize_extensions(options.get("extensions"))
    recursive = _option_enabled(options.get("recursive", False))
    skip_hevc = _option_enabled(options.get("skip_hevc", True))
    encoder_args, pixel_format_args = _choose_encoder(str(options.get("mode", "software")).lower())
    delivery_path = Path(delivery).expanduser() if delivery else None

    input_files = _collect_files(input_path, allowed_extensions, recursive)
    if not input_files:
        raise FileNotFoundError(f"No matching video files found under: {input_path}")

    logging.info("h265_encode found %s matching source file(s).", len(input_files))
    encoded_count = 0
    skipped_count = 0
    skip_reasons: list[str] = []

    for input_file in input_files:
        output_file = _output_path(input_file, input_path, delivery_path, len(input_files))
        if output_file.suffix.lower() != ".mkv":
            raise ValueError(f"h265_encode delivery must end with .mkv: {output_file}")
        if output_file.exists() and not overwrite_allowed:
            raise FileExistsError(f"Target delivery file exists and overwrite is disabled: {output_file}")

        analysis = _analyze_video(input_file)
        if analysis["codec"] == "hevc" and skip_hevc:
            reason = f"already HEVC: {input_file}"
            logging.info("Skipping %s", reason)
            skipped_count += 1
            skip_reasons.append(reason)
            continue


        if analysis["height"] > 1080:
            logging.warning("%s is %sp; keeping original resolution.", input_file, analysis["height"])

        output_file.parent.mkdir(parents=True, exist_ok=True)
        _encode_file(input_file, output_file, encoder_args, pixel_format_args)
        encoded_count += 1

    if skip_reasons and encoded_count == 0:
        message = f"Encoded 0 MKV file(s); skipped {skipped_count} file(s). " + "; ".join(skip_reasons[:5])
    else:
        message = f"Encoded {encoded_count} MKV file(s); skipped {skipped_count} file(s)."
    logging.info("h265_encode complete. %s", message)
    return {"success": True, "message": message, "cleanup_source": False}


def preflight_file(input_file: Path | str, options: dict[str, Any] | None = None, *, analysis: dict[str, Any] | None = None, encoder_args: list[str] | None = None, pixel_format_args: list[str] | None = None) -> dict[str, Any]:
    """Return whether one file is a useful H.265 candidate."""
    path = Path(input_file).expanduser()
    if not path.exists():
        return _preflight_decision(False, "source path does not exist")

    options = options or {}
    config = _preflight_options(options)
    analysis = analysis or _analyze_video(path)
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

    min_width = int(config.get("skip_under_width") or 0)
    min_height = int(config.get("skip_under_height") or 0)
    max_width = int(config.get("skip_over_width") or 0)
    max_height = int(config.get("skip_over_height") or 0)
    if min_width > 0 and width < min_width:
        return _preflight_decision(False, f"width {width} below preflight minimum {min_width}", analysis=analysis)
    if min_height > 0 and height < min_height:
        return _preflight_decision(False, f"height {height} below preflight minimum {min_height}", analysis=analysis)
    if max_width > 0 and width > max_width:
        return _preflight_decision(False, f"width {width} above preflight maximum {max_width}", analysis=analysis)
    if max_height > 0 and height > max_height:
        return _preflight_decision(False, f"height {height} above preflight maximum {max_height}", analysis=analysis)

    if not _option_enabled(config["sample_encode"]):
        return _preflight_decision(True, f"hard checks passed ({codec} {width}x{height})", analysis=analysis)

    encoder_args = encoder_args or SOFTWARE_ARGS
    pixel_format_args = pixel_format_args if pixel_format_args is not None else PIXEL_FORMAT_ARGS
    return _sample_preflight(path, analysis, config, encoder_args, pixel_format_args)


def preflight_source(source: str, extra_options: dict[str, Any] | None = None) -> dict[str, Any]:
    """Run preflight checks for a CLI/source value. The CLI expects a single file."""
    options = _parse_options(source)
    if extra_options:
        options.update(extra_options)
    path = Path(options["path"]).expanduser()
    if path.is_dir():
        return _preflight_decision(False, "automation preflight expects a single file, not a folder")
    _require_tool("ffmpeg")
    _require_tool("ffprobe")
    return preflight_file(path, options)


def _preflight_decision(include: bool, reason: str, **extra: Any) -> dict[str, Any]:
    payload = {"include": bool(include), "reason": reason}
    payload.update(extra)
    return payload


def _preflight_options(options: dict[str, Any]) -> dict[str, Any]:
    config = dict(DEFAULT_PREFLIGHT_OPTIONS)
    aliases = {
        "skip_4k": "skip_4k",
        "preflight_skip_4k": "skip_4k",
        "skip_efficient_codecs": "skip_efficient_codecs",
        "preflight_skip_efficient_codecs": "skip_efficient_codecs",
        "skip_hevc": "skip_hevc",
        "preflight_skip_hevc": "skip_hevc",
        "sample_encode": "sample_encode",
        "preflight_sample_encode": "sample_encode",
        "sample_seconds": "sample_seconds",
        "preflight_sample_seconds": "sample_seconds",
        "sample_points": "sample_points",
        "preflight_sample_points": "sample_points",
        "min_saving_percent": "min_saving_percent",
        "preflight_min_saving_percent": "min_saving_percent",
        "min_ssim": "min_ssim",
        "preflight_min_ssim": "min_ssim",
        "min_vmaf": "min_vmaf",
        "preflight_min_vmaf": "min_vmaf",
        "quality_metric": "quality_metric",
        "preflight_quality_metric": "quality_metric",
        "only_4k": "only_4k",
        "preflight_only_4k": "only_4k",
        "skip_under_width": "skip_under_width",
        "preflight_skip_under_width": "skip_under_width",
        "skip_under_height": "skip_under_height",
        "preflight_skip_under_height": "skip_under_height",
        "skip_over_width": "skip_over_width",
        "preflight_skip_over_width": "skip_over_width",
        "skip_over_height": "skip_over_height",
        "preflight_skip_over_height": "skip_over_height",
    }

    for source_key, target_key in aliases.items():
        if source_key in options:
            config[target_key] = options[source_key]

    config["sample_seconds"] = max(5, min(120, int(float(config["sample_seconds"]))))
    config["sample_points"] = _normalize_sample_points(config["sample_points"])
    config["min_saving_percent"] = max(0.0, min(95.0, float(config["min_saving_percent"])))
    config["min_ssim"] = max(0.0, min(1.0, float(config["min_ssim"])))
    config["min_vmaf"] = max(0.0, min(100.0, float(config["min_vmaf"])))
    config["quality_metric"] = str(config["quality_metric"] or "auto").strip().lower()
    if config["quality_metric"] not in {"auto", "vmaf", "ssim", "none"}:
        config["quality_metric"] = "auto"
    for key in ("skip_under_width", "skip_under_height", "skip_over_width", "skip_over_height"):
        try:
            config[key] = max(0, int(float(config.get(key) or 0)))
        except (TypeError, ValueError):
            config[key] = 0
    if _option_enabled(config.get("only_4k")):
        # A 4K-only profile should accept 4K candidates rather than be blocked
        # by the normal default skip_4k profile.
        config["skip_4k"] = False
        config["only_4k"] = True
    return config


def _normalize_sample_points(value: Any) -> list[float]:
    if isinstance(value, str):
        value = value.replace(",", " ").split()
    if not isinstance(value, (list, tuple)):
        return list(DEFAULT_PREFLIGHT_OPTIONS["sample_points"])

    points: list[float] = []
    for item in value:
        try:
            point = float(item)
        except (TypeError, ValueError):
            continue
        if point > 1.0:
            point = point / 100.0
        if 0.0 < point < 1.0:
            points.append(point)
    return points[:8] or list(DEFAULT_PREFLIGHT_OPTIONS["sample_points"])


def _sample_preflight(path: Path, analysis: dict[str, Any], config: dict[str, Any], encoder_args: list[str], pixel_format_args: list[str]) -> dict[str, Any]:
    duration = float(analysis.get("duration") or 0.0)
    if duration <= 0:
        return _preflight_decision(False, "missing duration for sample test", analysis=analysis)

    total_source_size = 0
    total_encoded_size = 0
    quality_scores: list[float] = []
    quality_metric_used = "none"

    with tempfile.TemporaryDirectory(prefix="reflection_h265_preflight_") as temp_dir:
        temp_root = Path(temp_dir)
        for index, point in enumerate(config["sample_points"], start=1):
            start_seconds = max(0, int(duration * float(point)))
            source_sample = temp_root / f"sample_{index}_source.mkv"
            encoded_sample = temp_root / f"sample_{index}_h265.mkv"

            try:
                _make_source_sample(path, start_seconds, int(config["sample_seconds"]), source_sample)
                _encode_sample(source_sample, encoded_sample, encoder_args, pixel_format_args)
            except RuntimeError as exc:
                return _preflight_decision(False, str(exc), analysis=analysis)

            source_size = source_sample.stat().st_size if source_sample.exists() else 0
            encoded_size = encoded_sample.stat().st_size if encoded_sample.exists() else 0
            if source_size <= 0 or encoded_size <= 0:
                return _preflight_decision(False, "sample size check failed", analysis=analysis)

            total_source_size += source_size
            total_encoded_size += encoded_size

            metric_name, score = _quality_score(source_sample, encoded_sample, str(config["quality_metric"]))
            if score is not None:
                quality_metric_used = metric_name
                quality_scores.append(score)

    if total_source_size <= 0 or total_encoded_size <= 0:
        return _preflight_decision(False, "sample size check failed", analysis=analysis)

    saving_percent = 100.0 * (1.0 - (total_encoded_size / total_source_size))
    if saving_percent < float(config["min_saving_percent"]):
        return _preflight_decision(
            False,
            f"sample saving only {saving_percent:.1f}% below {float(config['min_saving_percent']):.1f}% minimum",
            analysis=analysis,
            saving_percent=saving_percent,
            quality_metric=quality_metric_used,
        )

    if quality_scores:
        average_quality = sum(quality_scores) / len(quality_scores)
        if quality_metric_used == "vmaf":
            minimum = float(config["min_vmaf"])
            if average_quality < minimum:
                return _preflight_decision(
                    False,
                    f"sample VMAF {average_quality:.2f} below {minimum:.2f} minimum",
                    analysis=analysis,
                    saving_percent=saving_percent,
                    quality_metric=quality_metric_used,
                    quality_score=average_quality,
                )
        elif quality_metric_used == "ssim":
            minimum = float(config["min_ssim"])
            if average_quality < minimum:
                return _preflight_decision(
                    False,
                    f"sample SSIM {average_quality:.4f} below {minimum:.4f} minimum",
                    analysis=analysis,
                    saving_percent=saving_percent,
                    quality_metric=quality_metric_used,
                    quality_score=average_quality,
                )

        return _preflight_decision(
            True,
            f"sample saving {saving_percent:.1f}% with {quality_metric_used.upper()} {average_quality:.4g}",
            analysis=analysis,
            saving_percent=saving_percent,
            quality_metric=quality_metric_used,
            quality_score=average_quality,
        )

    return _preflight_decision(
        True,
        f"sample saving {saving_percent:.1f}%; no quality metric available",
        analysis=analysis,
        saving_percent=saving_percent,
        quality_metric=quality_metric_used,
    )


def _make_source_sample(path: Path, start_seconds: int, sample_seconds: int, output: Path) -> None:
    command = [
        "ffmpeg",
        "-hide_banner",
        "-y",
        "-v",
        "error",
        "-ss",
        str(max(0, start_seconds)),
        "-i",
        str(path),
        "-t",
        str(sample_seconds),
        "-map",
        "0:v:0",
        "-map",
        "0:a?",
        "-c",
        "copy",
        str(output),
    ]
    result = subprocess.run(command, check=False, capture_output=True, text=True)
    if result.returncode != 0 or not output.exists() or output.stat().st_size <= 0:
        detail = (result.stderr or result.stdout or "source sample failed").strip().splitlines()[-1:]
        raise RuntimeError("source sample failed" + (f": {detail[0]}" if detail else ""))


def _encode_sample(source_sample: Path, encoded_sample: Path, encoder_args: list[str], pixel_format_args: list[str]) -> None:
    command = [
        "ffmpeg",
        "-hide_banner",
        "-y",
        "-v",
        "error",
        "-i",
        str(source_sample),
        "-map",
        "0",
        "-c",
        "copy",
        *encoder_args,
        *pixel_format_args,
        str(encoded_sample),
    ]
    result = subprocess.run(command, check=False, capture_output=True, text=True)
    if result.returncode != 0 or not encoded_sample.exists() or encoded_sample.stat().st_size <= 0:
        detail = (result.stderr or result.stdout or "sample encode failed").strip().splitlines()[-1:]
        raise RuntimeError("sample encode failed" + (f": {detail[0]}" if detail else ""))


def _quality_score(original: Path, encoded: Path, requested_metric: str) -> tuple[str, float | None]:
    if requested_metric in {"auto", "vmaf"} and _ffmpeg_filter_available("libvmaf"):
        score = _vmaf_score(original, encoded)
        if score is not None:
            return "vmaf", score
        if requested_metric == "vmaf":
            return "vmaf", None

    if requested_metric in {"auto", "ssim"} and _ffmpeg_filter_available("ssim"):
        score = _ssim_score(original, encoded)
        if score is not None:
            return "ssim", score

    return "none", None


def _ssim_score(original: Path, encoded: Path) -> float | None:
    result = subprocess.run(
        [
            "ffmpeg",
            "-hide_banner",
            "-v",
            "info",
            "-i",
            str(original),
            "-i",
            str(encoded),
            "-lavfi",
            "[0:v:0][1:v:0]ssim",
            "-f",
            "null",
            "-",
        ],
        check=False,
        capture_output=True,
        text=True,
    )
    matches = re.findall(r"All:([0-9.]+)", result.stderr or "")
    return float(matches[-1]) if matches else None


def _vmaf_score(original: Path, encoded: Path) -> float | None:
    result = subprocess.run(
        [
            "ffmpeg",
            "-hide_banner",
            "-v",
            "info",
            "-i",
            str(encoded),
            "-i",
            str(original),
            "-lavfi",
            "libvmaf",
            "-f",
            "null",
            "-",
        ],
        check=False,
        capture_output=True,
        text=True,
    )
    text = result.stderr or ""
    matches = re.findall(r"VMAF score:\s*([0-9.]+)", text)
    return float(matches[-1]) if matches else None


def _parse_options(source):
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


def _option_enabled(value):
    if isinstance(value, bool):
        return value
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "y", "on"}
    return bool(value)


def _require_tool(tool):
    if shutil.which(tool) is None:
        raise RuntimeError(f"{tool} is not installed. Please install it to use h265_encode.")


def _install_system_packages(packages: list[str]) -> None:
    apt = shutil.which("apt-get") or shutil.which("apt")
    if apt is None:
        logging.warning("apt/apt-get was not found; cannot auto-install packages: %s", ", ".join(packages))
        return

    prefix: list[str] = []
    if hasattr(os, "geteuid") and os.geteuid() != 0:
        sudo = shutil.which("sudo")
        if sudo is None:
            logging.warning("Not running as root and sudo is unavailable; cannot auto-install packages: %s", ", ".join(packages))
            return
        prefix = [sudo, "-n"]

    env = os.environ.copy()
    env["DEBIAN_FRONTEND"] = "noninteractive"
    logging.info("Installing h265_encode system dependencies with apt: %s", ", ".join(packages))
    subprocess.run([*prefix, apt, "update", "-y"], check=False, env=env)
    subprocess.run([*prefix, apt, "install", "-y", *packages], check=False, env=env)


def _normalize_extensions(extensions: Any):
    if extensions is None or extensions == [] or extensions == "":
        return COMMON_VIDEO_EXTENSIONS

    if isinstance(extensions, str):
        extensions = extensions.replace(",", " ").split()

    normalized = {str(extension).lower().lstrip(".") for extension in extensions}
    normalized.discard("")
    if not normalized:
        raise ValueError("Extension filter was provided but no valid extensions were found.")
    return normalized


def _collect_files(input_path, allowed_extensions, recursive):
    if input_path.is_file():
        return [input_path] if input_path.suffix.lower().lstrip(".") in allowed_extensions else []

    pattern = "**/*" if recursive else "*"
    return sorted(
        path
        for path in input_path.glob(pattern)
        if path.is_file() and path.suffix.lower().lstrip(".") in allowed_extensions
    )


def _detected_hardware():
    result = subprocess.run(
        ["ffmpeg", "-hide_banner", "-encoders"],
        check=False,
        capture_output=True,
        text=True,
    )
    encoders = result.stdout + result.stderr
    for hardware_name, encoder in HARDWARE_ENCODERS.items():
        if encoder["ffmpeg_encoder"] in encoders:
            return hardware_name
    return "none"


def _choose_encoder(mode):
    if mode in {"hardware", "hw", "auto"}:
        hardware_name = _detected_hardware()
        if hardware_name != "none":
            logging.info("Using %s hardware HEVC encoder.", hardware_name)
            # Hardware encoders can reject yuv420p10le even when HEVC itself is available.
            return HARDWARE_ENCODERS[hardware_name]["args"], []
        logging.warning("Hardware HEVC encoder was requested but none was detected; using libx265.")

    return SOFTWARE_ARGS, PIXEL_FORMAT_ARGS


def _ffmpeg_encoder_available(encoder_name: str) -> bool:
    if shutil.which("ffmpeg") is None:
        return False
    result = subprocess.run(["ffmpeg", "-hide_banner", "-encoders"], check=False, capture_output=True, text=True)
    return encoder_name in ((result.stdout or "") + (result.stderr or ""))


def _ffmpeg_filter_available(filter_name: str) -> bool:
    if shutil.which("ffmpeg") is None:
        return False
    result = subprocess.run(["ffmpeg", "-hide_banner", "-filters"], check=False, capture_output=True, text=True)
    text = (result.stdout or "") + (result.stderr or "")
    return re.search(r"(^|\n)\s*[.A-Z|]+\s+" + re.escape(filter_name) + r"\s", text) is not None


def _analyze_video(input_file):
    result = subprocess.run(
        [
            "ffprobe",
            "-v",
            "error",
            "-select_streams",
            "v:0",
            "-show_entries",
            "stream=codec_name,width,height,bit_rate",
            "-show_entries",
            "format=duration,size,bit_rate",
            "-of",
            "json",
            str(input_file),
        ],
        check=True,
        capture_output=True,
        text=True,
    )
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
    size = int(float(fmt.get("size") or 0))
    bitrate = int(float(stream.get("bit_rate") or fmt.get("bit_rate") or 0))
    if bitrate <= 0 and size > 0 and duration > 0:
        bitrate = int(size * 8 / duration)

    if codec == "" or height <= 0 or width <= 0:
        raise RuntimeError(f"Could not read video codec and resolution from: {input_file}")

    logging.info("%s codec=%s resolution=%sx%s bitrate=%s", input_file, codec, width, height, bitrate)
    return {"codec": codec, "width": width, "height": height, "duration": duration, "size": size, "bitrate": bitrate}


def _output_path(input_file, input_root, delivery_path, input_count):
    if delivery_path is None:
        return input_file.with_name(f"{input_file.stem}_h265.mkv")

    if input_count == 1 and not delivery_path.is_dir() and delivery_path.suffix:
        return delivery_path

    if input_root.is_dir():
        relative_parent = input_file.parent.relative_to(input_root)
        return delivery_path / relative_parent / f"{input_file.stem}_h265.mkv"

    return delivery_path / f"{input_file.stem}_h265.mkv"


def _temporary_output_path(output_file):
    suffix = output_file.suffix or ".mkv"
    with tempfile.NamedTemporaryFile(
        prefix=f".{output_file.stem}.",
        suffix=suffix,
        dir=output_file.parent,
        delete=False,
    ) as temp_file:
        return Path(temp_file.name)


def _encode_file(input_file, output_file, encoder_args, pixel_format_args):
    temp_output = _temporary_output_path(output_file)
    command = [
        "ffmpeg",
        "-hide_banner",
        "-y",
        "-i",
        str(input_file),
        "-map",
        "0",
        "-map_metadata",
        "0",
        "-map_chapters",
        "0",
        "-c",
        "copy",
        *encoder_args,
        *pixel_format_args,
        str(temp_output),
    ]
    logging.info("Encoding %s -> %s", input_file, output_file)
    try:
        subprocess.run(command, check=True)
        os.replace(temp_output, output_file)
    except Exception:
        temp_output.unlink(missing_ok=True)
        raise


def _format_cli_result(result: dict[str, Any]) -> str:
    prefix = "queue" if result.get("include") else "skip"
    reason = str(result.get("reason") or "")
    analysis = result.get("analysis") if isinstance(result.get("analysis"), dict) else {}
    details = []
    if analysis:
        codec = analysis.get("codec")
        width = analysis.get("width")
        height = analysis.get("height")
        if codec and width and height:
            details.append(f"{codec} {width}x{height}")
    if "saving_percent" in result:
        details.append(f"saving {float(result['saving_percent']):.1f}%")
    if "quality_metric" in result and result.get("quality_metric") not in {None, "none"} and "quality_score" in result:
        metric = str(result.get("quality_metric")).upper()
        details.append(f"{metric} {float(result['quality_score']):.4g}")
    suffix = f" ({', '.join(details)})" if details else ""
    return f"{prefix}: {reason}{suffix}"


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


def _csv_to_points(value: str | None) -> list[float] | None:
    if value is None:
        return None
    return _normalize_sample_points(value)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Reflection H.265 task helper")
    parser.add_argument("path", nargs="?", help="Video path for --preflight")
    parser.add_argument("--preflight", action="store_true", help="Run the optional H.265 candidate preflight check. Exit 0 only when this file should be queued.")
    parser.add_argument("--json", action="store_true", help="Print the preflight result as JSON.")
    parser.add_argument("--profile", help="JSON object, or @file containing JSON, that overrides the default preflight profile.")
    parser.add_argument("--allow-4k", action="store_true", help="Do not skip 4K sources.")
    parser.add_argument("--only-4k", action="store_true", help="Only queue 4K sources; lower resolutions are skipped.")
    parser.add_argument("--skip-under-width", type=int, help="Skip sources below this width.")
    parser.add_argument("--skip-under-height", type=int, help="Skip sources below this height.")
    parser.add_argument("--skip-over-width", type=int, help="Skip sources above this width.")
    parser.add_argument("--skip-over-height", type=int, help="Skip sources above this height.")
    parser.add_argument("--allow-efficient-codecs", action="store_true", help="Do not automatically skip AV1/VP9/HEVC before sample testing.")
    parser.add_argument("--no-sample", action="store_true", help="Only run hard metadata checks; do not run sample encodes.")
    parser.add_argument("--min-saving-percent", type=float, help="Required sample size saving percentage.")
    parser.add_argument("--sample-seconds", type=int, help="Length of each sample in seconds.")
    parser.add_argument("--sample-points", help="Comma/space-separated sample positions, e.g. 12,35,60,82 or 0.12,0.35,0.60,0.82.")
    parser.add_argument("--quality-metric", choices=["auto", "vmaf", "ssim", "none"], help="Quality metric to use after the sample encode.")
    parser.add_argument("--min-ssim", type=float, help="Minimum average SSIM score.")
    parser.add_argument("--min-vmaf", type=float, help="Minimum average VMAF score.")
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
        if args.skip_under_width is not None:
            extra_options["skip_under_width"] = args.skip_under_width
        if args.skip_under_height is not None:
            extra_options["skip_under_height"] = args.skip_under_height
        if args.skip_over_width is not None:
            extra_options["skip_over_width"] = args.skip_over_width
        if args.skip_over_height is not None:
            extra_options["skip_over_height"] = args.skip_over_height
        if args.allow_efficient_codecs:
            extra_options["skip_efficient_codecs"] = False
            extra_options["skip_hevc"] = False
        if args.no_sample:
            extra_options["sample_encode"] = False
        if args.min_saving_percent is not None:
            extra_options["min_saving_percent"] = args.min_saving_percent
        if args.sample_seconds is not None:
            extra_options["sample_seconds"] = args.sample_seconds
        points = _csv_to_points(args.sample_points)
        if points is not None:
            extra_options["sample_points"] = points
        if args.quality_metric is not None:
            extra_options["quality_metric"] = args.quality_metric
        if args.min_ssim is not None:
            extra_options["min_ssim"] = args.min_ssim
        if args.min_vmaf is not None:
            extra_options["min_vmaf"] = args.min_vmaf

        result = preflight_source(args.path, extra_options)
    except Exception as exc:  # noqa: BLE001 - CLI must produce a concise automation reason.
        result = _preflight_decision(False, f"preflight failed: {type(exc).__name__}: {exc}")

    if args.json:
        print(json.dumps(result, sort_keys=True))
    else:
        print(_format_cli_result(result))
    return 0 if result.get("include") else 1


if __name__ == "__main__":
    raise SystemExit(main())
