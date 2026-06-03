"""Example render task placeholder."""

import json
import logging

TASK_NAME = "render_frame"
DESCRIPTION = "Render a frame with Blender, FFmpeg, or another configured renderer."
TASK_SPEC_JSON = r'''
{
  "name": "render_frame",
  "description": "Render a frame with the configured worker renderer.",
  "source": {
    "mode": "required",
    "label": "Render source",
    "help": "Scene/project input for the renderer."
  },
  "delivery": {
    "mode": "auto",
    "label": "Rendered frame output",
    "help": "Automatically written beside the source as {name}.png unless overridden.",
    "template": "{dir}/{name}.png",
    "extension": ".png"
  },
  "output": {
    "kind": "file",
    "extension": ".png"
  }
}
'''
TASK_SPEC = json.loads(TASK_SPEC_JSON)



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
