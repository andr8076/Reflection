<?php

declare(strict_types=1);

function reflection_send_security_headers(): void
{
    header("Content-Security-Policy: script-src 'self'; object-src 'none'; base-uri 'self'");
}

function reflection_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reflection_post_bool(string $key): bool
{
    return isset($_POST[$key]) && in_array((string) $_POST[$key], ['1', 'true', 'yes', 'on'], true);
}

function reflection_parse_machine_list(string $raw): array
{
    $machines = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || reflection_string_starts_with($line, '#')) {
            continue;
        }

        $parts = array_map('trim', explode(',', $line));
        $machines[] = [
            'pc_id' => $parts[0] ?? '',
            'mac' => $parts[1] ?? '',
            'soc_margin_percent' => (int) ($parts[2] ?? 5),
            'wake_enabled' => !isset($parts[3]) || !in_array(strtolower($parts[3]), ['0', 'false', 'no', 'off'], true),
            'shutdown_layer' => max(0, (int) ($parts[4] ?? 0)),
        ];
    }

    return $machines;
}

function reflection_machine_list_text(array $machines): string
{
    $lines = [];
    foreach ($machines as $machine) {
        $lines[] = implode(',', [
            $machine['pc_id'] ?? '',
            $machine['mac'] ?? '',
            $machine['soc_margin_percent'] ?? 5,
            !empty($machine['wake_enabled']) ? '1' : '0',
            max(0, (int) ($machine['shutdown_layer'] ?? 0)),
        ]);
    }

    return implode(PHP_EOL, $lines);
}

function reflection_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024) {
            return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
        }
        $value /= 1024;
    }

    return number_format($value, 2) . ' PB';
}

function reflection_relative_time($timestamp): string
{
    $timestamp = (string) ($timestamp ?? '');
    if ($timestamp === '') {
        return '—';
    }

    $time = strtotime($timestamp);
    if ($time === false) {
        return $timestamp;
    }

    $diff = max(0, time() - $time);
    if ($diff < 60) {
        return $diff . 's ago';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    return (int) floor($diff / 86400) . 'd ago';
}

function reflection_short_value($value, int $limit = 96): string
{
    $value = (string) ($value ?? '—');
    if ($value === '') {
        return '—';
    }
    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }
    if (!function_exists('mb_strlen') && strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }
    return $value;
}

function reflection_status_class($status): string
{
    $status = preg_replace('/[^a-z0-9_-]/i', '', (string) $status);
    return $status !== '' ? strtolower($status) : 'unknown';
}

function reflection_ess_soc_is_ignored(array $settings): bool
{
    $url = trim((string) ($settings['ess_soc_url'] ?? ''));
    if ($url === '' || empty($settings['ess_ignore_when_unavailable'])) {
        return false;
    }

    return ($settings['ess_soc_status'] ?? 'manual') !== 'online';
}

function reflection_ess_status_label(array $settings): string
{
    $status = (string) ($settings['ess_soc_status'] ?? 'manual');
    if ($status === 'online') {
        return 'online';
    }
    if ($status === 'offline') {
        return 'connection failed';
    }
    if ($status === 'parse_error') {
        return 'parse failed';
    }
    return 'manual';
}

function reflection_run_store_maintenance(FarmStore $store, array $settings): array
{
    return [
        'archived_jobs' => $store->archiveOldCompletedJobs((int) ($settings['job_history_keep_completed'] ?? 500)),
        'trimmed_events' => $store->trimEventLog((int) ($settings['event_log_keep_lines'] ?? 1000)),
        'trimmed_file_history' => $store->compactFileHistory(
            (int) ($settings['file_history_keep_paths'] ?? 500),
            (int) ($settings['file_history_keep_entries_per_path'] ?? 10),
        ),
        'trimmed_job_archive' => $store->trimJobArchive((int) ($settings['job_archive_keep_lines'] ?? 5000)),
    ];
}
