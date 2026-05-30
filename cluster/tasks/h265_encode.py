"""Batch transcode videos to H.265/HEVC MP4 outputs with FFmpeg."""

import json
import logging
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
from typing import Any

TASK_NAME = "h265_encode"
DESCRIPTION = "Transcode video files to H.265/HEVC MP4 using FFmpeg with optional hardware acceleration."

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

SOFTWARE_ARGS = ["-c:v", "libx265", "-crf", "20", "-preset", "slow"]
HARDWARE_ENCODERS = {
    "nvidia": {
        "ffmpeg_encoder": "hevc_nvenc",
        "args": ["-c:v", "hevc_nvenc", "-rc", "vbr", "-cq", "23", "-preset", "slow"],
    },
    "apple": {
        "ffmpeg_encoder": "hevc_videotoolbox",
        "args": ["-c:v", "hevc_videotoolbox", "-q:v", "65"],
    },
    "intel": {
        "ffmpeg_encoder": "hevc_qsv",
        "args": ["-c:v", "hevc_qsv", "-global_quality", "24", "-preset", "slow"],
    },
}
AUDIO_ARGS = ["-c:a", "aac", "-b:a", "192k"]
PIXEL_FORMAT_ARGS = ["-pix_fmt", "yuv420p10le"]


def install():
    """Validate that FFmpeg and FFprobe are available for this task."""
    _require_tool("ffmpeg")
    _require_tool("ffprobe")
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

    for input_file in input_files:
        output_file = _output_path(input_file, input_path, delivery_path, len(input_files))
        if output_file.exists() and not overwrite_allowed:
            raise FileExistsError(f"Target delivery file exists and overwrite is disabled: {output_file}")

        analysis = _analyze_video(input_file)
        if analysis["codec"] == "hevc" and skip_hevc:
            logging.info("Skipping already-HEVC source: %s", input_file)
            skipped_count += 1
            continue
        if analysis["height"] > 1080:
            logging.warning("%s is %sp; keeping original resolution.", input_file, analysis["height"])

        output_file.parent.mkdir(parents=True, exist_ok=True)
        _encode_file(input_file, output_file, encoder_args, pixel_format_args)
        encoded_count += 1

    message = f"Encoded {encoded_count} file(s); skipped {skipped_count} already-HEVC file(s)."
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


def _analyze_video(input_file):
    result = subprocess.run(
        [
            "ffprobe",
            "-v",
            "error",
            "-select_streams",
            "v:0",
            "-show_entries",
            "stream=codec_name,height",
            "-of",
            "json",
            str(input_file),
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    streams = json.loads(result.stdout).get("streams", [])
    if not streams:
        raise RuntimeError(f"Could not read a video stream from: {input_file}")

    stream = streams[0]
    codec = str(stream.get("codec_name") or "")
    height = int(stream.get("height") or 0)
    if codec == "" or height <= 0:
        raise RuntimeError(f"Could not read video codec and height from: {input_file}")

    logging.info("%s codec=%s resolution=%sp", input_file, codec, height)
    return {"codec": codec, "height": height}


def _output_path(input_file, input_root, delivery_path, input_count):
    if delivery_path is None:
        return input_file.with_name(f"{input_file.stem}_h265.mp4")

    if input_count == 1 and not delivery_path.is_dir() and delivery_path.suffix:
        return delivery_path

    if input_root.is_dir():
        relative_parent = input_file.parent.relative_to(input_root)
        return delivery_path / relative_parent / f"{input_file.stem}_h265.mp4"

    return delivery_path / f"{input_file.stem}_h265.mp4"


def _temporary_output_path(output_file):
    suffix = output_file.suffix or ".mp4"
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
        *encoder_args,
        *pixel_format_args,
        *AUDIO_ARGS,
        str(temp_output),
    ]
    logging.info("Encoding %s -> %s", input_file, output_file)
    try:
        subprocess.run(command, check=True)
        os.replace(temp_output, output_file)
    except Exception:
        temp_output.unlink(missing_ok=True)
        raise
