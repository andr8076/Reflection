"""Invert the colors of an image file."""

import importlib.util
import logging
import os
import struct
import tempfile
from pathlib import Path

TASK_NAME = "invert_image"
DESCRIPTION = (
    "Invert an image's colors while preserving alpha transparency when possible."
)

SUPPORTED_STDLIB_FORMATS = {".bmp"}
PILLOW_PACKAGE_HINT = "Install Pillow for PNG, JPEG, TIFF, WebP, and other image formats."


def install():
    """Report image support available to this worker."""
    if _pillow_available():
        logging.info("invert_image can use Pillow for common image formats.")
        return

    logging.warning(
        "invert_image is limited to uncompressed 24-bit/32-bit BMP files because "
        "Pillow is not installed. %s",
        PILLOW_PACKAGE_HINT,
    )


def run(source, delivery, overwrite_allowed):
    """Invert source image colors and write the result to delivery."""
    source_path = Path(source).expanduser().resolve()
    delivery_path = Path(delivery).expanduser().resolve() if delivery else None

    if not source_path.is_file():
        raise FileNotFoundError(f"Source image does not exist: {source_path}")
    if delivery_path is None:
        raise ValueError("Delivery path is required for invert_image.")
    if delivery_path.exists() and not overwrite_allowed:
        raise FileExistsError("Target delivery file exists and overwrite is disabled.")

    install()
    delivery_path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = _temporary_delivery_path(delivery_path)

    try:
        if _pillow_available():
            _invert_with_pillow(source_path, temp_path, delivery_path)
        elif source_path.suffix.lower() in SUPPORTED_STDLIB_FORMATS:
            _invert_bmp_with_stdlib(source_path, temp_path)
        else:
            raise RuntimeError(
                f"Unsupported image format without Pillow: {source_path.suffix or 'unknown'}. "
                f"{PILLOW_PACKAGE_HINT}"
            )
        os.replace(temp_path, delivery_path)
    except Exception:
        temp_path.unlink(missing_ok=True)
        raise

    logging.info("Inverted image written: %s -> %s", source_path, delivery_path)
    return True


def _pillow_available():
    return importlib.util.find_spec("PIL") is not None


def _temporary_delivery_path(delivery_path):
    suffix = delivery_path.suffix or ".tmp"
    with tempfile.NamedTemporaryFile(
        prefix=f".{delivery_path.stem}.",
        suffix=suffix,
        dir=delivery_path.parent,
        delete=False,
    ) as temp_file:
        return Path(temp_file.name)


def _invert_with_pillow(source_path, temp_path, delivery_path):
    from PIL import Image, ImageOps

    with Image.open(source_path) as image:
        output_format = image.format
        image = ImageOps.exif_transpose(image)
        metadata = dict(image.info)

        if image.mode in ("RGBA", "LA") or "transparency" in metadata:
            rgba = image.convert("RGBA")
            red, green, blue, alpha = rgba.split()
            inverted = Image.merge("RGB", (red, green, blue))
            inverted = ImageOps.invert(inverted)
            inverted.putalpha(alpha)
        elif image.mode == "L":
            inverted = ImageOps.invert(image)
        else:
            inverted = ImageOps.invert(image.convert("RGB"))

        if (
            delivery_path.suffix.lower() in (".jpg", ".jpeg")
            and inverted.mode == "RGBA"
        ):
            inverted = inverted.convert("RGB")

        save_kwargs = {}
        if output_format and not delivery_path.suffix:
            save_kwargs["format"] = output_format
        inverted.save(temp_path, **save_kwargs)


def _invert_bmp_with_stdlib(source_path, temp_path):
    data = bytearray(source_path.read_bytes())
    if len(data) < 54 or data[0:2] != b"BM":
        raise ValueError("Expected a BMP image file.")

    pixel_offset = struct.unpack_from("<I", data, 10)[0]
    dib_header_size = struct.unpack_from("<I", data, 14)[0]
    if dib_header_size < 40:
        raise ValueError("Unsupported BMP DIB header.")

    width = struct.unpack_from("<i", data, 18)[0]
    height = struct.unpack_from("<i", data, 22)[0]
    planes = struct.unpack_from("<H", data, 26)[0]
    bits_per_pixel = struct.unpack_from("<H", data, 28)[0]
    compression = struct.unpack_from("<I", data, 30)[0]

    if width <= 0 or height == 0 or planes != 1:
        raise ValueError("Unsupported BMP dimensions or plane count.")
    if compression != 0:
        raise ValueError("Only uncompressed BMP images are supported without Pillow.")
    if bits_per_pixel not in (24, 32):
        raise ValueError("Only 24-bit and 32-bit BMP images are supported without Pillow.")

    bytes_per_pixel = bits_per_pixel // 8
    row_stride = ((width * bits_per_pixel + 31) // 32) * 4
    height_abs = abs(height)
    expected_size = pixel_offset + (row_stride * height_abs)
    if len(data) < expected_size:
        raise ValueError("BMP pixel data is truncated.")

    for row in range(height_abs):
        row_start = pixel_offset + (row * row_stride)
        for column in range(width):
            pixel_start = row_start + (column * bytes_per_pixel)
            data[pixel_start] = 255 - data[pixel_start]
            data[pixel_start + 1] = 255 - data[pixel_start + 1]
            data[pixel_start + 2] = 255 - data[pixel_start + 2]

    temp_path.write_bytes(data)
