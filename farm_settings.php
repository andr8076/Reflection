<?php

declare(strict_types=1);

return [
    // Give each deployed farm a stable id/name. These values also help derive
    // fallback temporary paths when the configured store is not writable.
    'farm_id' => 'default',
    'farm_name' => 'Reflection Farm',

    // Optional shared secret for worker API requests. REFLECTION_API_TOKEN overrides this value.
    // Leave blank only on a trusted private network while testing.
    'api_token' => '',

    // Credentials workers use when source/delivery paths point at the farm FTP server.
    // REFLECTION_FTP_* environment variables override these values. Keep blanks here and
    // provide real credentials through environment variables or a local untracked override.
    'transfer_auth' => [
        'scheme' => 'ftp',
        'host' => '',
        'port' => 21,
        'username' => '',
        'password' => '',
    ],

    // Persistent farm data. REFLECTION_MASTER_STORE still overrides this value.
    'storage_path' => __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json',

    // REFLECTION_REQUIRED_VERSION still overrides this value. Leave null to use
    // the currently checked-out Git commit id when it can be detected.
    'required_version' => null,
    'stale_after_seconds' => 15 * 60,

    // Defaults used when the writable farm store does not yet contain a saved
    // operator setting. The dashboard can still update these at runtime.
    'runtime_defaults' => [
        'enforce_version' => true,
        'failure_strategy' => 'mark_failed',
        'max_retries' => 0,
        'ess_soc_percent' => 100,
        'ess_soc_url' => 'http://192.168.1.245:8076',
        'ess_min_soc_percent' => 20,
        'ess_shutdown_below_minimum' => true,
        'idle_shutdown_after_no_job_checks' => 0,
    ],

    'allowed_tasks' => [
        'dummy_task' => 'Placeholder pipeline test task.',
        'render_frame' => 'Render a frame with the configured worker renderer.',
        'h265_encode' => 'Transcode video files to H.265/HEVC MP4 with FFmpeg.',
        'compress_archive' => 'Compress a file or directory into a small .tar.xz archive with hardware-aware limits.',
        'invert_image' => 'Invert an image while preserving alpha transparency when possible.',
        'noop' => 'Built-in worker connectivity check.',
        'status' => 'Built-in worker health snapshot.',
        'reload_tasks' => 'Ask a worker to reload its local task registry.',
        'shutdown' => 'Ask a worker to stop after reporting success.',
        'wake_farm' => 'Ask a worker to send Wake-on-LAN packets to configured farm computers.',
    ],
];
