<?php

declare(strict_types=1);

return [
    // Give each deployed farm a stable id/name. These values also help derive
    // fallback temporary paths when the configured store is not writable.
    'farm_id' => 'default',
    'farm_name' => 'Default Reflection Farm',

    // Default dashboard / JSON Tool login for this farm. Change these before
    // exposing the master website outside a trusted network.
    'default_login' => [
        'username' => 'reflection',
        'password' => 'reflection',
    ],
    'auth' => [
        'enabled' => true,
        'realm' => 'Reflection Farm Master',
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
    ],

    'allowed_tasks' => [
        'dummy_task' => 'Placeholder pipeline test task.',
        'render_frame' => 'Render a frame with the configured worker renderer.',
        'noop' => 'Built-in worker connectivity check.',
        'status' => 'Built-in worker health snapshot.',
        'reload_tasks' => 'Ask a worker to reload its local task registry.',
        'shutdown' => 'Ask a worker to stop after reporting success.',
        'wake_farm' => 'Ask a worker to send Wake-on-LAN packets to configured farm computers.',
    ],
];
