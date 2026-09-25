# H.265 optional preflight

Reflection's optional H.265 command filter is backed by the linked [265Encode](https://github.com/andr8076/265Encode) project. It evaluates representative samples through protocol 2; Reflection no longer performs its own libx265 sample encodes.

## When preflight runs

Preflight is off by default. In an automation rule for `h265_encode`, enable **Optional worker command filter** and use:

```text
Command mode: Include if command exits 0
Timeout seconds: 3600
Command output regex: leave empty
```

Command:

```bash
python3 {task_file} --preflight {path}
```

Exit code 0 means the candidate passes. Exit code 1 means the worker skips that queued candidate. The command runs on the worker after any remote source has been prepared locally. Dry runs on the master do not run sample encoding.

Before each preflight and real H.265 job, the worker fetches the latest `main` branch of 265Encode into `cluster/.dependencies/265Encode`. The dependency is declared in `cluster/dependencies.json). If a fetch fails and a prior checkout exists, the worker logs the failure and uses that checkout.

## Default candidate policy

The default preflight:

- skips HEVC/H.265, AV1, and VP9 sources;
- skips 4K sources unless `--allow-4k` is used;
- creates a protocol-2 sample plan with three representative windows;
- requests VMAF 93;
- requires a predicted output size saving of at least 25 percent.

The real encode task defaults to hardware-only AUTO with a VMAF target of 92. The preflight VMAF and saving thresholds only decide whether the job is queued; they do not change the real task's request.

## Source JSON options

A job can provide semantic requirements alongside the local input path:

```json
{
  "path": "{worker_path}",
  "mode": "auto",
  "quality": {
    "mode": "required",
    "metric": "vmaf",
    "target": 92,
    "p10_minimum": 88,
    "sustained_floor": 86,
    "maximum_sustained_seconds": 1
  },
  "optimization": {
    "primary": "smallest_output",
    "secondary": "fastest_encoding"
  },
  "video": {
    "maximum_height": null,
    "denoise": "auto"
  },
  "audio": {
    "mode": "copy_all"
  },
  "evaluation": {
    "sample_seconds": 3
  }
}
```

Supported semantic controls:

- `mode`: `auto` or `hardware` uses proven hardware only; `software` explicitly permits libx265.
- `requested_encoder`: optional protocol-2 encoder identifier, such as `hevc_nvenc`. For `libx265`, also set `"mode":"software"`.
- `quality`: `mode` is `required` or `off`; metric is `vmaf` or `ssim_percent`; thresholds are values from 0 to 100.
- `optimization`: two different goals chosen from `smallest_output`, `fastest_encoding`, and `highest_quality`.
- `video`: optional `maximum_height`; `denoise` may be `auto`, `required`, or `never`.
- `audio`: `copy_all` preserves audio, while `archive_optimize` requests the dependency's archival audio optimization.
- `evaluation.sample_seconds`: representative sample duration from 1 to 10 seconds.

The previous profile names remain accepted as quality aliases: `auto`, `standard`, and `4k` target VMAF 92; `4k_quality` targets 95; `space_saver` targets 88. 265Encode chooses the actual backend and recipe. Old direct tuning keys such as `crf`, `preset`, `pixel_format`, `pix_fmt`, and `x265_params` are rejected because the dependency owns those choices.

## Preflight options

Examples:

```bash
python3 {task_file} --preflight {path} --allow-4k --min-vmaf 93
python3 {task_file} --preflight {path} --only-4k --min-saving-percent 30
python3 {task_file} --preflight {path} --skip-under-width 1280 --sample-seconds 5
python3 {task_file} --preflight {path} --quality-metric ssim --min-ssim 0.985
python3 {task_file} --preflight {path} --allow-efficient-codecs
```

The sample planner chooses its own representative timestamps. Custom `--sample-points` and `--no-sample` are unsupported by protocol 2 and cause the candidate to be skipped with an explanation. Disabling quality checks is available as `{"quality":{"mode":"off"}}`; the dependency still performs bounded sampling for its plan.

Pass `--json` to print the candidate decision and predicted saving/quality details as JSON. Use `--profile '{...}'` or `--profile @file.json` to set the same options without editing the task.

## Encoding and output validation

For a real job, Reflection asks 265Encode to negotiate protocol 2, checks required feature flags, evaluates requirements, and executes the unchanged sealed plan. The dependency commits its staging output only after validating HEVC video, duration, full video/audio decode, and stream counts. Reflection then atomically moves the validated MKV to the requested delivery path. A failed plan or validation does not replace an existing delivery file.

AUTO never silently falls back to CPU encoding. To allow software encoding, set `"mode":"software"` in the job's source JSON. Workers need `git`, `python3`, `ffmpeg`, and `ffprobe`; 265Encode probes which real hardware paths are usable on each worker.
