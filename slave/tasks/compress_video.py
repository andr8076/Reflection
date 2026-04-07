# Compress video using ffmpeg

import subprocess
from task_base import BaseTask, register_task


@register_task
class CompressVideoTask(BaseTask):
    name = "compress_video"

    def run(self, job):
        input_path = job["input_path"]
        output_path = job["output_path"]
        params = job.get("params", {})

        crf = str(params.get("crf", 24))
        preset = params.get("preset", "medium")

        subprocess.run([
            "ffmpeg",
            "-i", input_path,
            "-c:v", "libx265",
            "-crf", crf,
            "-preset", preset,
            "-c:a", "copy",
            output_path
        ], check=True)
