"""Example render task placeholder."""

import logging

TASK_NAME = "render_frame"
DESCRIPTION = "Render a frame with Blender, FFmpeg, or another configured renderer."


def install():
    """Optional installer for render dependencies.

    Add dependency checks or installation commands here when this task is wired to
    real software such as Blender or FFmpeg.
    """
    logging.info(
        "render_frame installer placeholder: add Blender/FFmpeg setup here if needed."
    )


def run(source, delivery, overwrite_allowed):
    """Render one frame using the standardized task signature."""
    # Your Blender/FFmpeg/Processing logic goes here.
    raise NotImplementedError(
        "render_frame is a placeholder task and has not been implemented yet."
    )
