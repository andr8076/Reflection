<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../FarmStore.php';
require_once __DIR__ . '/../ui_helpers.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

assertSameValue('&lt;farm&gt;', reflection_h('<farm>'), 'HTML helper should escape output.');
assertSameValue('1.50 KB', reflection_format_bytes(1536), 'Byte helper should format values consistently.');
assertSameValue('parse failed', reflection_ess_status_label(['ess_soc_status' => 'parse_error']), 'ESS helper should preserve the dashboard label.');
assertSameValue('abcdef…', reflection_short_value('abcdefgh', 7), 'Short-value helper should preserve truncation behavior.');

$machines = reflection_parse_machine_list("# ignored\nworker-a,aa:bb:cc:dd:ee:ff,7,off\nworker-b,11:22:33:44:55:66");
assertSameValue([
    ['pc_id' => 'worker-a', 'mac' => 'aa:bb:cc:dd:ee:ff', 'wake_enabled' => false, 'shutdown_layer' => 0, 'min_soc_percent' => 7, 'soc_margin_percent' => 7],
    ['pc_id' => 'worker-b', 'mac' => '11:22:33:44:55:66', 'wake_enabled' => true, 'shutdown_layer' => 0],
], $machines, 'Machine-list parser should preserve minimum-SOC settings while allowing a blank global fallback.');
assertSameValue(
    "worker-a,aa:bb:cc:dd:ee:ff,7,0,0\nworker-b,11:22:33:44:55:66,,1,0",
    reflection_machine_list_text($machines),
    'Machine-list formatter should preserve blank per-machine SOC values for global fallback.'
);

$defaults = reflection_default_farm_settings();
assertSameValue(reflection_default_runtime_settings(), $defaults['runtime_defaults'], 'Master config should use canonical runtime defaults.');
assertSameValue(reflection_default_allowed_tasks(), $defaults['allowed_tasks'], 'Master config should use canonical allowed tasks.');
assertSameValue(true, array_key_exists('compress_archive', $defaults['allowed_tasks']), 'Canonical tasks should include archive compression.');
assertSameValue(true, array_key_exists('invert_image', $defaults['allowed_tasks']), 'Canonical tasks should include image inversion.');

$storePath = sys_get_temp_dir() . '/reflection_ui_helpers_' . bin2hex(random_bytes(6)) . '.json';
$store = new FarmStore($storePath);
assertSameValue(reflection_default_runtime_settings(), $store->effectiveSettings(), 'FarmStore should seed canonical runtime defaults.');
@unlink($storePath);
@unlink($storePath . '.lock');

echo "ui helper tests passed\n";
