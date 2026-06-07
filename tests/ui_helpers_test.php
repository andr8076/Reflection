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
$stylesheetLinks = reflection_stylesheet_links();
assertSameValue(true, strpos($stylesheetLinks, 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css') !== false, 'Stylesheet helper should include Bootstrap from the official jsDelivr CDN.');
assertSameValue(true, strpos($stylesheetLinks, 'integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"') !== false, 'Stylesheet helper should pin Bootstrap with subresource integrity.');
assertSameValue(false, strpos($stylesheetLinks, 'styles.css') !== false, 'Stylesheet helper should not include the removed app-specific stylesheet.');
assertSameValue('1.50 KB', reflection_format_bytes(1536), 'Byte helper should format values consistently.');
assertSameValue('parse failed', reflection_ess_status_label(['ess_soc_status' => 'parse_error']), 'ESS helper should preserve the dashboard label.');
assertSameValue('abcdef…', reflection_short_value('abcdefgh', 7), 'Short-value helper should preserve truncation behavior.');

$machines = reflection_parse_machine_list("# ignored
worker-a,aa:bb:cc:dd:ee:ff,7,off
worker-b,11:22:33:44:55:66");
assertSameValue([
    ['pc_id' => 'worker-a', 'mac' => 'aa:bb:cc:dd:ee:ff', 'wake_enabled' => false, 'shutdown_layer' => 0, 'min_soc_percent' => 7, 'soc_margin_percent' => 7],
    ['pc_id' => 'worker-b', 'mac' => '11:22:33:44:55:66', 'wake_enabled' => true, 'shutdown_layer' => 0],
], $machines, 'Machine-list parser should treat the third column as per-worker minimum ESS SOC.');
assertSameValue(
    "worker-a,aa:bb:cc:dd:ee:ff,7,0,0
worker-b,11:22:33:44:55:66,,1,0",
    reflection_machine_list_text($machines),
    'Machine-list formatter should leave the minimum ESS SOC blank when the global fallback is used.'
);

$formMachines = reflection_parse_machine_form([
    'machine_pc_id' => ['0' => 'farm1', '1' => 'farm2', '2' => ''],
    'machine_mac' => ['0' => 'AA:BB:CC:DD:EE:01', '1' => 'AA:BB:CC:DD:EE:02', '2' => ''],
    'machine_min_soc_percent' => ['0' => '25', '1' => '', '2' => '80'],
    'machine_wake_enabled' => ['0' => '1'],
    'machine_shutdown_layer' => ['0' => '2', '1' => '0', '2' => '5'],
]);
assertSameValue([
    ['pc_id' => 'farm1', 'mac' => 'AA:BB:CC:DD:EE:01', 'wake_enabled' => true, 'shutdown_layer' => 2, 'min_soc_percent' => 25, 'soc_margin_percent' => 25],
    ['pc_id' => 'farm2', 'mac' => 'AA:BB:CC:DD:EE:02', 'wake_enabled' => false, 'shutdown_layer' => 0],
], $formMachines, 'Machine form parser should preserve blank per-computer SOC as global fallback and unchecked wake boxes as false.');
assertSameValue(null, reflection_parse_machine_form([]), 'Machine form parser should return null when the new UI fields are not present.');
assertSameValue('yes', reflection_ess_charging_label(['ess_charging' => true]), 'Charging label helper should show a known charging state.');
assertSameValue('unknown', reflection_ess_charging_label(['ess_charging' => null]), 'Charging label helper should show unknown when the endpoint does not provide charging.');
assertSameValue(true, reflection_ess_charging_override_active(['ess_charging_override_enabled' => true, 'ess_soc_status' => 'online', 'ess_charging' => true]), 'Charging override helper should require online ESS charging true.');
assertSameValue(false, reflection_ess_charging_override_active(['ess_charging_override_enabled' => true, 'ess_soc_status' => 'offline', 'ess_charging' => true]), 'Charging override helper should not trust stale charging state when ESS is offline.');

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


$config = reflection_master_config();
assertSameValue(true, isset($config['task_specs']['compress_archive']), 'Master should discover task contracts from task module files.');
assertSameValue('auto', $config['task_specs']['compress_archive']['delivery']['mode'], 'compress_archive delivery should be automatic.');
assertSameValue('.zip', $config['task_specs']['compress_archive']['delivery']['extension'], 'compress_archive should declare .zip output.');
assertSameValue('auto', $config['task_specs']['h265_encode']['delivery']['mode'], 'h265_encode delivery should be automatic.');
assertSameValue('.mkv', $config['task_specs']['h265_encode']['delivery']['extension'], 'h265_encode should declare MKV output.');
assertSameValue('mkv', $config['task_specs']['h265_encode']['output']['container'], 'h265_encode should declare MKV container output.');
assertSameValue(true, $config['task_specs']['h265_encode']['output']['preserve_audio'], 'h265_encode should declare audio preservation.');
assertSameValue(true, $config['task_specs']['h265_encode']['output']['preserve_subtitles'], 'h265_encode should declare subtitle preservation.');
assertSameValue(true, $config['task_specs']['h265_encode']['output']['preserve_chapters'], 'h265_encode should declare chapter preservation.');
assertSameValue(true, $config['task_specs']['h265_encode']['output']['preserve_metadata'], 'h265_encode should declare metadata preservation.');

echo "ui helper tests passed\n";
