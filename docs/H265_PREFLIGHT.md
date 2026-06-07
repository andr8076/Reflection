# H.265 Optional Preflight Test

This document explains how to use the optional preflight test built into the `h265_encode` task.

The preflight test is used by automation rules as a worker-side gate before the real H.265 conversion starts. The master only queues matching files as **candidate jobs**. No separate filter-test job is created. When a farm computer reaches that normal queue item, it prepares the source file, runs the preflight locally, and either continues with the real encode or marks that same queue item as skipped. It can skip files that are already efficient, skip or target 4K files, run short sample encodes, compare estimated size saving, and optionally check quality with VMAF or SSIM.

## Important behavior

The preflight test does **not** run by default.

It only runs when an automation rule calls it through **Optional worker command filter**.

The normal `h265_encode` task will still encode normally unless the automation rule has a command filter configured.

## Basic automation setup

In the automation rule for `h265_encode`, configure:

```text
Command mode: Include if command exits 0
Timeout seconds: 900
Command output regex: leave empty
```

Then use this command:

```bash
python3 {task_file} --preflight {path}
```

Meaning:

```text
Exit 0 = worker accepts the candidate and starts the encode
Exit 1 = worker marks the job skipped
```

`{task_file}` points to the worker's local `h265_encode.py` task module.

`{path}` is replaced with the worker-local prepared source file. If the source came from FTP/SFTP, the worker downloads the file first and then runs the preflight against that local temporary copy.

Dry runs and filter tests on the web UI do **not** execute this command. They only show that a candidate would be queued and that the worker command filter is pending. The dashboard shows these queued items as `candidate`; when a farm computer takes one, it can move through `preparing`, `preflight`, `running`, or `preflight skipped`.

Automatic demand wake treats candidate-only backlogs conservatively. It will not wake every configured PC just because hundreds of files are waiting for worker-side preflight. Confirmed normal jobs still count fully; candidate-only queues count as one effective work slot until workers start proving there is real encode work to do.

## Encoder profiles

The actual `h265_encode` task now supports encoder profiles. By default it uses:

```text
encode_profile: auto
```

`auto` means:

```text
4K source      -> use the 4k profile
anything else  -> use the standard profile
```

Built-in encode profiles:

```text
standard      libx265 CRF 20, preset slow, 10-bit output
4k            libx265 CRF 22, preset slow, 10-bit output
4k_quality    libx265 CRF 20, preset slow, 10-bit output
space_saver   libx265 CRF 24, preset medium, 10-bit output
```

For most rules, you do not need to set this manually. The task chooses automatically from the video resolution.

To force a profile for the real encode job, use JSON as the source value/job template:

```json
{"path":"{worker_path}","encode_profile":"4k_quality"}
```

You can also override profile pieces:

```json
{"path":"{worker_path}","encode_profile":"4k","crf":20,"preset":"slow"}
```

Supported source JSON encoder keys:

```text
encode_profile: auto, standard, 4k, 4k_quality, space_saver
mode: software, hardware, auto
crf: libx265 CRF value when using software mode
preset: libx265 preset when using software mode
pix_fmt / pixel_format: output pixel format, or none
x265_params: extra x265 parameter string
```

The optional preflight command can use the same profile so its sample encode matches the real encode decision.

## Default preflight profile

The default command:

```bash
python3 {task_file} --preflight {path}
```

uses this profile:

```text
Skip already H.265 / HEVC: yes
Skip AV1 / VP9: yes
Skip 4K: yes
Run sample encode: yes
Sample length: 24 seconds
Sample points: 12%, 35%, 60%, 82%
Minimum sample saving: 25%
Quality metric: auto
Minimum SSIM: 0.985
Minimum VMAF: 93
```

So the default profile is meant for normal non-4K library cleanup. It tries to queue only files that are likely to become meaningfully smaller without too much extra generation loss.

## Command options

### 4K handling

Allow 4K files to be tested instead of skipped:

```bash
python3 {task_file} --preflight {path} --allow-4k
```

Only test 4K files and skip everything below 4K:

```bash
python3 {task_file} --preflight {path} --only-4k
```

4K-only with stricter saving requirement, using the task's 4K encoder profile:

```bash
python3 {task_file} --preflight {path} --only-4k --encode-profile 4k --min-saving-percent 30
```

4K-only with the higher-quality 4K profile:

```bash
python3 {task_file} --preflight {path} --only-4k --encode-profile 4k_quality --min-saving-percent 25
```

### Resolution filters

Skip anything below 1080p:

```bash
python3 {task_file} --preflight {path} --skip-under-height 1080
```

Only allow roughly 720p to 1080p:

```bash
python3 {task_file} --preflight {path} --skip-under-height 700 --skip-over-height 1080
```

Skip anything wider than 1920 pixels:

```bash
python3 {task_file} --preflight {path} --skip-over-width 1920
```

Useful flags:

```text
--skip-under-width N
--skip-under-height N
--skip-over-width N
--skip-over-height N
```

A value of `0` means no limit for that direction.

### Codec handling

By default, preflight skips these codecs before sample testing:

```text
HEVC / H.265
AV1
VP9
```

Allow efficient codecs to be tested anyway:

```bash
python3 {task_file} --preflight {path} --allow-efficient-codecs
```

Use this only if you want to standardize everything to H.265. For storage saving, reconverting AV1, VP9, or already-HEVC files is usually not useful.

### Encoder profile for the sample test

The preflight sample test defaults to `--encode-profile auto`, so the sample is encoded using the same profile the real task would normally choose.

Force the balanced 4K profile:

```bash
python3 {task_file} --preflight {path} --encode-profile 4k
```

Force the higher-quality 4K profile:

```bash
python3 {task_file} --preflight {path} --encode-profile 4k_quality
```

Override individual encoder settings for the sample test:

```bash
python3 {task_file} --preflight {path} --encode-profile 4k --crf 20 --preset slow
```

Useful encoder flags:

```text
--encode-profile auto|standard|4k|4k_quality|space_saver
--mode software|hardware|auto
--crf N
--preset preset-name
--pix-fmt yuv420p10le|none
--x265-params key=value:key=value
```

### Sample encoding

Disable sample encoding and only do hard metadata checks:

```bash
python3 {task_file} --preflight {path} --no-sample
```

Change the length of each sample:

```bash
python3 {task_file} --preflight {path} --sample-seconds 30
```

Change sample positions:

```bash
python3 {task_file} --preflight {path} --sample-points 12,35,60,82
```

The sample points can be written as percentages:

```text
12,35,60,82
```

or decimals:

```text
0.12,0.35,0.60,0.82
```

The script accepts up to 8 sample points.

Good sample points avoid the first few minutes, logos, black screens, and credits. Good defaults are spread through the file, for example:

```text
12%, 35%, 60%, 82%
```

### Minimum saving threshold

The saving threshold controls how much smaller the sample encode must be before the full video is queued.

More aggressive, converts more files:

```bash
python3 {task_file} --preflight {path} --min-saving-percent 15
```

Balanced default:

```bash
python3 {task_file} --preflight {path} --min-saving-percent 25
```

More conservative, skips more files:

```bash
python3 {task_file} --preflight {path} --min-saving-percent 35
```

Recommended starting point:

```text
15% or lower: aggressive
25%: balanced
35% or higher: conservative
```

### Quality metric

Automatic mode uses the best available quality check:

```bash
python3 {task_file} --preflight {path} --quality-metric auto
```

Behavior in `auto` mode:

```text
Use VMAF if FFmpeg supports libvmaf
otherwise use SSIM if available
otherwise fall back to sample size only
```

Force VMAF:

```bash
python3 {task_file} --preflight {path} --quality-metric vmaf --min-vmaf 95
```

Force SSIM:

```bash
python3 {task_file} --preflight {path} --quality-metric ssim --min-ssim 0.990
```

Disable quality checking and use sample size only:

```bash
python3 {task_file} --preflight {path} --quality-metric none
```

Practical threshold guide:

```text
VMAF 95+: good
VMAF 93-95: acceptable if saving is large
VMAF below 93: usually skip

SSIM 0.990+: good
SSIM 0.985-0.990: acceptable if saving is large
SSIM below 0.985: usually skip
```

## JSON profile

Instead of many flags, you can pass a JSON profile:

```bash
python3 {task_file} --preflight {path} --profile '{"min_saving_percent":30,"sample_seconds":30,"quality_metric":"auto"}'
```

You can also load the JSON from a file:

```bash
python3 {task_file} --preflight {path} --profile @/volume1/web/api/farm/config/h265_1080p_profile.json
```

Supported JSON keys:

```json
{
  "skip_4k": true,
  "only_4k": false,
  "skip_efficient_codecs": true,
  "skip_hevc": true,
  "sample_encode": true,
  "sample_seconds": 24,
  "sample_points": [0.12, 0.35, 0.60, 0.82],
  "min_saving_percent": 25,
  "quality_metric": "auto",
  "min_ssim": 0.985,
  "min_vmaf": 93,
  "encode_profile": "auto",
  "mode": "software",
  "crf": null,
  "preset": null,
  "pixel_format": null,
  "x265_params": null,
  "skip_under_width": 0,
  "skip_under_height": 0,
  "skip_over_width": 0,
  "skip_over_height": 0
}
```

The task also accepts the same keys with a `preflight_` prefix when used from JSON source options, for example:

```json
{
  "path": "/volume1/video/movie.mkv",
  "preflight_min_saving_percent": 30,
  "preflight_skip_4k": false,
  "encode_profile": "auto"
}
```

## Useful ready-made commands

### Normal non-4K cleanup

```bash
python3 {task_file} --preflight {path}
```

### Normal cleanup, stricter savings

```bash
python3 {task_file} --preflight {path} --min-saving-percent 35
```

### 1080p and below only

```bash
python3 {task_file} --preflight {path} --skip-over-height 1080 --min-saving-percent 25 --quality-metric auto
```

### 4K-only test

```bash
python3 {task_file} --preflight {path} --only-4k --encode-profile 4k --min-saving-percent 30 --sample-seconds 30 --quality-metric auto
```

### 4K-only high-quality test

```bash
python3 {task_file} --preflight {path} --only-4k --encode-profile 4k_quality --min-saving-percent 25 --sample-seconds 30 --quality-metric auto
```

### Fast metadata-only check

```bash
python3 {task_file} --preflight {path} --no-sample --skip-over-height 1080
```

### Conservative profile

```bash
python3 {task_file} --preflight {path} --min-saving-percent 35 --min-ssim 0.990 --min-vmaf 95
```

### Aggressive profile

```bash
python3 {task_file} --preflight {path} --min-saving-percent 15 --min-ssim 0.975 --min-vmaf 90
```

## Optional JSON output

For debugging, add `--json`:

```bash
python3 {task_file} --preflight {path} --json
```

This prints the decision data as JSON. The exit code still decides whether the automation queues or skips the file.

## Dependencies

The H.265 task setup/install checks for:

```text
ffmpeg
ffprobe
libx265 encoder support
```

Optional quality checks depend on FFmpeg filter support:

```text
libvmaf filter: enables VMAF
ssim filter: enables SSIM
```

If VMAF is not available, `quality_metric=auto` falls back to SSIM. If SSIM is not available either, it falls back to sample size only.

## Important performance note

The optional command runs on the farm computer after a candidate job has been assigned, not on the webserver. If many candidate jobs are queued and sample encoding is enabled, the workers can spend time skipping candidates before reaching files that actually encode.

For large scans, start with one of these:

```bash
python3 {task_file} --preflight {path} --no-sample
```

or use a shorter sample:

```bash
python3 {task_file} --preflight {path} --sample-seconds 10 --sample-points 20,60
```

Then use the full sample test when you are happy with the rule.

## Best-practice policy

A good default policy is:

```text
Skip already efficient codecs.
Skip 4K unless using a separate 4K rule.
Let the task use encode_profile auto unless you have a specific reason to force another profile.
Use sample encodes for the grey area.
Require at least 25-30% saving for normal unattended conversion.
Use VMAF or SSIM when available.
```

Do not treat the test as perfect. It is a safety filter. It helps avoid wasting farm time on files that are already efficient or unlikely to shrink enough to justify another lossy encode.
