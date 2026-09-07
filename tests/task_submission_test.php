<?php

declare(strict_types=1);

require_once __DIR__ . '/../TaskSubmission.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/reflection_h265_submission_' . bin2hex(random_bytes(6));
$nested = $root . '/nested';
mkdir($nested, 0775, true);
file_put_contents($root . '/b.mp4', str_repeat('b', 11));
file_put_contents($nested . '/a.MOV', str_repeat('a', 7));
file_put_contents($root . '/ignore.txt', 'not a video');

$expanded = reflection_expand_task_sources('h265_encode', $root, '');
assertSameValue(null, $expanded['error'], 'A readable H.265 source folder should expand successfully.');
assertSameValue(true, $expanded['expanded_folder'], 'Folder expansion should be identified in queue metadata.');
assertSameValue(2, count($expanded['sources']), 'Each supported video should become exactly one source payload.');
assertSameValue(str_replace('\\', '/', $nested . '/a.MOV'), $expanded['sources'][0], 'Expanded video paths should be naturally sorted.');
assertSameValue(str_replace('\\', '/', $root . '/b.mp4'), $expanded['sources'][1], 'Folder expansion should retain every supported video.');

$jsonSource = json_encode(['path' => $root, 'crf' => 24], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$expandedJson = reflection_expand_task_sources('h265_encode', $jsonSource, '');
assertSameValue(2, count($expandedJson['sources']), 'JSON H.265 options should still produce one payload per video.');
foreach ($expandedJson['sources'] as $payload) {
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    assertSameValue(24, $decoded['crf'], 'Per-job expansion should preserve encoding options.');
    assertSameValue(true, is_file($decoded['path']), 'Each expanded JSON payload should point to one file, never the folder.');
}

$deliveryRejected = reflection_expand_task_sources('h265_encode', $root, '/tmp/all.mkv');
assertSameValue(true, is_string($deliveryRejected['error']), 'Folder submissions should reject one shared delivery path.');
$single = reflection_expand_task_sources('h265_encode', $root . '/b.mp4', '');
assertSameValue([$root . '/b.mp4'], $single['sources'], 'A single video submission should remain one job.');
assertSameValue(['minimum_free_temp_bytes' => 22], reflection_job_resource_requirements($root . '/b.mp4'), 'Known local files should reserve twice their source size in temporary capacity.');

unlink($root . '/b.mp4');
unlink($root . '/ignore.txt');
unlink($nested . '/a.MOV');
rmdir($nested);
rmdir($root);

echo "task submission tests passed\n";
