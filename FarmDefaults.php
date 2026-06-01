<?php

declare(strict_types=1);

final class FarmDefaults
{
    public static function runtimeSettings(): array
    {
        return [
            'enforce_version' => true,
            'failure_strategy' => 'mark_failed',
            'max_retries' => 0,
            'stale_job_strategy' => 'requeue_to_end',
            'stale_max_retries' => 1,
            'crash_loop_protection_enabled' => true,
            'crash_loop_lost_attempts' => 2,
            'crash_loop_distinct_workers' => 1,
            'ess_soc_percent' => 100,
            'ess_soc_url' => 'http://192.168.1.245:8076',
            'ess_min_soc_percent' => 20,
            'ess_shutdown_below_minimum' => true,
            'ess_ignore_when_unavailable' => true,
            'ess_soc_status' => 'manual',
            'ess_soc_error' => '',
            'ess_soc_raw_sample' => '',
            'ess_soc_last_checked_at' => null,
            'ess_soc_last_success_at' => null,
            'ess_soc_last_failure_at' => null,
            'idle_shutdown_after_no_job_checks' => 0,
            'auto_wake_for_queued_jobs' => true,
            'automation_run_due_on_worker_checkin' => true,
            'automation_checkin_cooldown_seconds' => 60,
            'wake_dispatch_mode' => 'worker_relay',
            'auto_wake_cooldown_seconds' => 300,
            'auto_wake_max_targets_per_run' => 20,
            'wake_broadcast_address' => '255.255.255.255',
            'wake_udp_port' => 9,
            'job_archive_keep_lines' => 5000,
            'worker_temp_max_age_hours' => 24,
            'quarantine_keep_days' => 14,
            'job_history_keep_completed' => 500,
            'event_log_keep_lines' => 1000,
            'file_history_keep_paths' => 500,
            'file_history_keep_entries_per_path' => 10,
        ];
    }

    public static function allowedTasks(): array
    {
        return [
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
            'storage_test' => 'Ask a worker to verify read/write/rename/delete access to a configured storage server.',
        ];
    }
}
