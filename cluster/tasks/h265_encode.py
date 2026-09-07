"""Transcode one video to H.265/HEVC MKV while preserving movie streams."""

from __future__ import annotations

import json
import logging
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
from typing import Any

TASK_NAME = "h265_encode"
DESCRIPTION = "Transcode the main video stream to H.265/HEVC MKV while preserving audio, subtitles, chapters, attachments, and metadata."
TASK_SPEC_JSON = r'''
{
  "name": "h265_encode",
  "description": "Transcode the main video stream to H.265/HEVC MKV while preserving the rest of the movie structure.",
  "production_ready": true,
  "requirements": {
    "commands": ["ffmpeg", "ffprobe"],
    "ffmpeg_encoders": ["libx265"]
  },
  "source": {
    "mode": "required",
    "label": "Source video",
    "help": "One video file. When a folder is submitted, the master expands it into one independently scheduled job per video. JSON options may tune encoder mode, encode_profile, and worker-side skip_hevc behavior."
  },
  "delivery": {
    "mode": "auto",
    "label": "H.265 MKV output",
    "help": "Automatically written beside the source as {name}_h265.mkv. Audio, subtitles, chapters, attachments, and metadata are copied when FFmpeg can preserve them.",
    "template": "{dir}/{name}_h265.mkv",
    "extension": ".mkv"
  },
  "encode_profiles": {
    "default": "auto",
    "auto": {
      "label": "Auto",
      "help": "Automatically uses the 4k profile for 4K sources and the standard profile otherwise."
    },
    "standard": {
      "label": "Standard / HD",
      "mode": "software",
      "crf": 20,
      "preset": "slow",
      "pixel_format": "yuv420p10le"
    },
    "4k": {
      "label": "4K balanced",
      "mode": "software",
      "crf": 22,
      "preset": "slow",
      "pixel_format": "yuv420p10le"
    },
    "4k_quality": {
      "label": "4K quality",
      "mode": "software",
      "crf": 20,
      "preset": "slow",
      "pixel_format": "yuv420p10le"
    },
    "space_saver": {
      "label": "Space saver",
      "mode": "software",
      "crf": 24,
      "preset": "medium",
      "pixel_format": "yuv420p10le"
    }
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


DEFAULT_ENCODE_PROFILE = "auto"
ENCODE_PROFILES = {
    "standard": {
        "label": "Standard / HD",
        "mode": "software",
        "crf": "20",
        "preset": "slow",
        "pixel_format": "yuv420p10le",
    },
    "4k": {
        "label": "4K balanced",
        "mode": "software",
        "crf": "22",
        "preset": "slow",
        "pixel_format": "yuv420p10le",
    },
    "4k_quality": {
        "label": "4K quality",
        "mode": "software",
        "crf": "20",
        "preset": "slow",
        "pixel_format": "yuv420p10le",
    },
    "space_saver": {
        "label": "Space saver",
        "mode": "software",
        "crf": "24",
        "preset": "medium",
        "pixel_format": "yuv420p10le",
    },
}
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

    logging.info("h265_encode dependencies are available.")


def run(source, delivery, overwrite_allowed):
    """Transcode exactly one source file; folders are expanded by the master."""
    options = _parse_options(source)
    input_path = Path(options["path"]).expanduser()
    if not input_path.exists():
        raise FileNotFoundError(f"Source path does not exist: {input_path}")
    if not input_path.is_file():
        raise IsADirectoryError(
            "h265_encode accepts one video per job. Submit the folder through the "
            "master dashboard so it can create one job for each video."
        )

    _require_tool("ffmpeg")
    _require_tool("ffprobe")

    skip_hevc = _option_enabled(options.get("skip_hevc", True))
    delivery_path = Path(delivery).expanduser() if delivery else None
    output_file = _output_path(input_path, input_path, delivery_path, 1)
    if output_file.suffix.lower() != ".mkv":
        raise ValueError(f"h265_encode delivery must end with .mkv: {output_file}")
    if output_file.exists() and not overwrite_allowed:
        raise FileExistsError(f"Target delivery file exists and overwrite is disabled: {output_file}")

    analysis = _analyze_video(input_path)
    if analysis["codec"] == "hevc" and skip_hevc:
        message = f"Skipped source because it is already HEVC: {input_path}"
        logging.info(message)
        return {"success": True, "skipped": True, "message": message, "cleanup_source": False}

    profile_name, encoder_args, pixel_format_args = _encoder_for_analysis(options, analysis)
    logging.info("Using H.265 encode profile %s for %s.", profile_name, input_path)
    if analysis["height"] > 1080:
        logging.warning("%s is %sp; keeping original resolution.", input_path, analysis["height"])

    output_file.parent.mkdir(parents=True, exist_ok=True)
    _encode_file(input_path, output_file, encoder_args, pixel_format_args)
    message = f"Encoded 1 MKV file: {output_file}"
    logging.info("h265_encode complete. %s", message)
    return {"success": True, "message": message, "cleanup_source": False}


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


def _normalize_profile_name(value: Any) -> str:
    profile = str(value or DEFAULT_ENCODE_PROFILE).strip().lower().replace("-", "_")
    aliases = {
        "": DEFAULT_ENCODE_PROFILE,
        "default": DEFAULT_ENCODE_PROFILE,
        "auto": DEFAULT_ENCODE_PROFILE,
        "normal": "standard",
        "hd": "standard",
        "1080p": "standard",
        "uhd": "4k",
        "4k_balanced": "4k",
        "quality_4k": "4k_quality",
    }
    profile = aliases.get(profile, profile)
    if profile != DEFAULT_ENCODE_PROFILE and profile not in ENCODE_PROFILES:
        raise ValueError(f"Unknown h265 encode_profile {profile!r}. Valid profiles: auto, " + ", ".join(sorted(ENCODE_PROFILES)))
    return profile


def _selected_profile_name(options: dict[str, Any], analysis: dict[str, Any]) -> str:
    profile = _normalize_profile_name(options.get("encode_profile", options.get("profile", DEFAULT_ENCODE_PROFILE)))
    if profile == DEFAULT_ENCODE_PROFILE:
        width = int(analysis.get("width") or 0)
        height = int(analysis.get("height") or 0)
        return "4k" if width >= 3840 or height >= 2160 else "standard"
    return profile


def _profile_options(options: dict[str, Any], analysis: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    profile_name = _selected_profile_name(options, analysis)
    profile = dict(ENCODE_PROFILES[profile_name])

    # Job/source JSON may override profile pieces while still using the
    # task-owned profile defaults as the base.
    if options.get("mode"):
        profile["mode"] = str(options.get("mode")).strip().lower()
    for key in ("crf", "preset", "x265_params"):
        if options.get(key) not in {None, ""}:
            profile[key] = str(options.get(key)).strip()
    pixel_format = options.get("pixel_format", options.get("pix_fmt"))
    if pixel_format not in {None, ""}:
        profile["pixel_format"] = str(pixel_format).strip()

    return profile_name, profile


def _encoder_for_analysis(options: dict[str, Any], analysis: dict[str, Any]) -> tuple[str, list[str], list[str]]:
    profile_name, profile = _profile_options(options, analysis)
    encoder_args, pixel_format_args = _choose_encoder(str(profile.get("mode") or "software").lower(), profile)
    return profile_name, encoder_args, pixel_format_args


def _choose_encoder(mode: str, profile: dict[str, Any] | None = None) -> tuple[list[str], list[str]]:
    profile = profile or ENCODE_PROFILES["standard"]
    if mode in {"hardware", "hw", "auto"}:
        hardware_name = _detected_hardware()
        if hardware_name != "none":
            logging.info("Using %s hardware HEVC encoder.", hardware_name)
            # Hardware encoders can reject yuv420p10le even when HEVC itself is available.
            return HARDWARE_ENCODERS[hardware_name]["args"], []
        logging.warning("Hardware HEVC encoder was requested but none was detected; using libx265.")

    encoder_args = [
        "-c:v:0",
        "libx265",
        "-crf",
        str(profile.get("crf") or "20"),
        "-preset",
        str(profile.get("preset") or "slow"),
    ]
    if profile.get("x265_params"):
        encoder_args.extend(["-x265-params", str(profile["x265_params"])])

    pixel_format = str(profile.get("pixel_format") or "").strip()
    pixel_format_args = ["-pix_fmt", pixel_format] if pixel_format and pixel_format.lower() not in {"none", "copy", "source"} else []
    return encoder_args, pixel_format_args


def _ffmpeg_encoder_available(encoder_name: str) -> bool:
    if shutil.which("ffmpeg") is None:
        return False
    result = subprocess.run(["ffmpeg", "-hide_banner", "-encoders"], check=False, capture_output=True, text=True)
    return encoder_name in ((result.stdout or "") + (result.stderr or ""))


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
