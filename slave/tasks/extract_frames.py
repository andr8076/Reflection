# Extract frames from video

import os
import subprocess
from task_base import BaseTask, register_task


@register_task
class ExtractFramesTask(BaseTask):
    name = "extract_frames"

    def run(self, job):
        input_path = job["input_path"]
        output_path = job["output_path"]

        os.makedirs(output_path, exist_ok=True)

        subprocess.run([
            "ffmpeg",
            "-i", input_path,
            os.path.join(output_path, "frame_%04d.png")
        ], check=True)
