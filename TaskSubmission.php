<?php

declare(strict_types=1);

/**
 * Expand an H.265 folder submission into one source payload per video.
 * Other task modules retain their original single source payload.
 */
function reflection_expand_task_sources(string $module, string $source, string $delivery = ''): array
{
    if ($module !== 'h265_encode') {
        return ['sources' => [$source], 'expanded_folder' => false, 'error' => null];
    }

    $path = trim($source);
    $options = null;
    $decoded = json_decode($path, true);
    if (is_array($decoded) && isset($decoded['path']) && is_string($decoded['path'])) {
        $options = $decoded;
        $path = trim($decoded['path']);
    }

    if (!is_dir($path)) {
        if ($path !== '' && preg_match('#[\\/]$#', $path) === 1) {
            return [
                'sources' => [],
                'expanded_folder' => false,
                'error' => 'The H.265 source looks like a folder, but the master cannot enumerate it. Mount the folder on the master or import one video path per line.',
            ];
        }
        return ['sources' => [$source], 'expanded_folder' => false, 'error' => null];
    }

    if (trim($delivery) !== '') {
        return [
            'sources' => [],
            'expanded_folder' => true,
            'error' => 'Leave delivery blank when submitting an H.265 folder. Reflection generates one .mkv delivery path for each video.',
        ];
    }

    $extensions = ['3g2', '3gp', 'avi', 'flv', 'm2ts', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'mts', 'ts', 'vob', 'webm', 'wmv'];
    $videoPaths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || !in_array(strtolower($entry->getExtension()), $extensions, true)) {
            continue;
        }

        $videoPaths[] = str_replace('\\', '/', $entry->getPathname());

        if (count($videoPaths) > 10000) {
            return [
                'sources' => [],
                'expanded_folder' => true,
                'error' => 'The H.265 folder contains more than 10,000 videos. Split it into smaller submissions.',
            ];
        }
    }

    if ($videoPaths === []) {
        return [
            'sources' => [],
            'expanded_folder' => true,
            'error' => 'No supported video files were found in the H.265 source folder.',
        ];
    }

    // Present folder imports in human-friendly filename order regardless of
    // which nested directory the filesystem iterator happens to visit first.
    usort($videoPaths, static function (string $left, string $right): int {
        $byName = strnatcasecmp(basename($left), basename($right));
        return $byName !== 0 ? $byName : strnatcasecmp($left, $right);
    });

    $sources = [];
    foreach ($videoPaths as $videoPath) {
        if ($options !== null) {
            $videoOptions = $options;
            $videoOptions['path'] = $videoPath;
            $sources[] = json_encode($videoOptions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            $sources[] = $videoPath;
        }
    }

    return ['sources' => $sources, 'expanded_folder' => true, 'error' => null];
}

function reflection_job_resource_requirements(?string $source): array
{
    $path = trim((string) $source);
    $decoded = json_decode($path, true);
    if (is_array($decoded) && isset($decoded['path']) && is_string($decoded['path'])) {
        $path = trim($decoded['path']);
    }
    if ($path === '' || !is_file($path)) {
        return [];
    }

    $size = @filesize($path);
    return $size === false ? [] : ['minimum_free_temp_bytes' => max(0, (int) $size * 2)];
}
