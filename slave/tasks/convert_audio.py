# Convert audio file using ffmpeg

import subprocess
from task_base import BaseTask, register_task


@register_task
class ConvertAudioTask(BaseTask):
    name = "convert_audio"

    def run(self, job):
        input_path = job["input_path"]
        output_path = job["output_path"]

        subprocess.run([
            "ffmpeg",
            "-i", input_path,
            output_path
        ], check=True)
