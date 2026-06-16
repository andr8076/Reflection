<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/StorageStore.php';
require_once __DIR__ . '/ui_helpers.php';

reflection_send_security_headers();

$config = reflection_master_config();
$taskSpecs = is_array($config['task_specs'] ?? null) ? $config['task_specs'] : [];
$store = reflection_farm_store($config);
$dataDirectory = dirname((string) $config['storage_path']);
$storageStore = new StorageStore($dataDirectory, $config['transfer_server'] ?? null);
$storageServers = $storageStore->enabledServers(true);
$storageServerIds = array_map(static function (array $server): string {
    return (string) ($server['id'] ?? '');
}, $storageServers);
$message = null;
$error = null;

// Get git commit hash
$gitCommit = null;
$gitHeadFile = __DIR__ . '/.git/HEAD';
if (file_exists($gitHeadFile)) {
    $headContent = trim(file_get_contents($gitHeadFile));
    if (strpos($headContent, 'ref: ') === 0) {
        $refPath = __DIR__ . '/.git/' . substr($headContent, 5);
        if (file_exists($refPath)) {
            $gitCommit = substr(trim(file_get_contents($refPath)), 0, 7);
        }
    } else {
        $gitCommit = substr($headContent, 0, 7);
    }
}

function reflection_storage_server_label(array $server): string
{
    $root = trim((string) ($server['root'] ?? ''));
    $suffix = $root !== '' ? ' · root ' . $root : '';
    return sprintf('%s — %s://%s:%d%s',
        (string) ($server['name'] ?? 'Unnamed server'),
        (string) ($server['scheme'] ?? 'ftp'),
        (string) ($server['host'] ?? ''),
        (int) ($server['port'] ?? 21),
        $suffix
    );
}

function reflection_job_display_status(array $job): array
{
    $status = (string) ($job['status'] ?? 'unknown');
    $hasWorkerFilter = is_array($job['worker_command_filter'] ?? null);
    $stage = (string) ($job['stage'] ?? '');
    $preflight = (string) ($job['worker_preflight_status'] ?? '');
    $label = $status;
    $class = $status;
    $detail = '';

    if ($hasWorkerFilter) {
        if ($status === 'queued') {
            $label = 'candidate';
            $class = 'candidate';
            $detail = 'Worker preflight pending';
        } elseif ($status === 'running') {
            if (in_array($stage, ['preparing_source', 'preflight_pending'], true) || $preflight === 'preparing') {
                $label = 'preparing';
                $class = 'running';
                $detail = 'Preparing source for worker preflight';
            } elseif ($stage === 'preflight_running' || $preflight === 'running') {
                $label = 'preflight';
                $class = 'running';
                $detail = 'Running worker command filter';
            } elseif ($preflight === 'passed') {
                $label = 'running';
                $class = 'running';
                $detail = 'Preflight passed';
            }
        } elseif ($status === 'skipped') {
            $label = 'preflight skipped';
            $class = 'skipped';
            $detail = 'Skipped by worker command filter';
        }
    }

    $note = trim((string) ($job['worker_preflight_note'] ?? $job['stage_message'] ?? ''));
    if ($note !== '') {
        $detail = $detail !== '' ? $detail . ' · ' . $note : $note;
    }

    return [
        'status' => $status,
        'label' => $label,
        'class' => $class,
        'detail' => $detail,
    ];
}

function reflection_validate_task(string $module, array $config): ?string
{
    return array_key_exists($module, $config['allowed_tasks']) ? null : 'Choose an allowed task.';
}

function reflection_path_allowed(?string $path, bool $required): ?string
{
    if ($path === null || $path === '') {
        return $required ? 'Path or URI is required for this task.' : null;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return 'Paths and URIs may not contain control characters.';
    }

    return null;
}

function reflection_import_lines(string $raw): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }

    $json = json_decode($trimmed, true);
    if (is_array($json)) {
        $paths = [];
        foreach ($json as $entry) {
            if (is_string($entry)) {
                $paths[] = $entry;
                continue;
            }

            if (is_array($entry) && isset($entry['source']) && is_string($entry['source'])) {
                $paths[] = $entry['source'];
            }
        }

        return $paths;
    }

    return preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
}

function reflection_clean_import_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || reflection_string_starts_with($path, '#')) {
        return '';
    }

    return str_replace('\\', '/', $path);
}

function reflection_apply_delivery_template(string $template, string $source): ?string
{
    $template = trim($template);
    if ($template === '') {
        return null;
    }

    $parts = reflection_split_path_template_parts($source);
    $rendered = strtr($template, [
        '{source}' => $parts['source'],
        '{dir}' => $parts['dir'],
        '{directory}' => $parts['directory'],
        '{basename}' => $parts['basename'],
        '{name}' => $parts['name'],
        '{ext}' => $parts['ext'],
        '{dot_ext}' => $parts['dot_ext'],
    ]);

    if ($parts['dir'] === '' && preg_match('#^\s*\{(?:dir|directory)\}/#', $template) === 1) {
        $rendered = ltrim($rendered, '/');
    }

    return reflection_join_template_path($rendered);
}


function reflection_task_spec(string $module, array $config): array
{
    $specs = is_array($config['task_specs'] ?? null) ? $config['task_specs'] : [];
    if (isset($specs[$module]) && is_array($specs[$module])) {
        return $specs[$module];
    }

    $description = isset($config['allowed_tasks'][$module]) ? (string) $config['allowed_tasks'][$module] : '';
    return reflection_normalize_task_spec($module, ['name' => $module, 'description' => $description], $description);
}

function reflection_task_source_mode(string $module, array $config): string
{
    $spec = reflection_task_spec($module, $config);
    return (string) ($spec['source']['mode'] ?? 'required');
}

function reflection_task_delivery_mode(string $module, array $config): string
{
    $spec = reflection_task_spec($module, $config);
    return (string) ($spec['delivery']['mode'] ?? 'optional');
}

function reflection_task_is_control_like(string $module, array $config): bool
{
    return reflection_task_source_mode($module, $config) === 'none'
        && reflection_task_delivery_mode($module, $config) === 'none';
}

function reflection_task_select_description(string $module, string $fallbackDescription, array $taskSpecs): string
{
    if (isset($taskSpecs[$module]['description']) && trim((string) $taskSpecs[$module]['description']) !== '') {
        return (string) $taskSpecs[$module]['description'];
    }

    return $fallbackDescription;
}

function reflection_split_path_template_parts(string $source): array
{
    $source = str_replace('\\', '/', trim($source));
    $sourceForName = rtrim($source, '/');
    $slash = strrpos($sourceForName, '/');
    $directory = $slash === false ? '' : substr($sourceForName, 0, $slash);
    $basename = $slash === false ? $sourceForName : substr($sourceForName, $slash + 1);
    if ($basename === '') {
        $basename = 'output';
    }

    $extension = pathinfo($basename, PATHINFO_EXTENSION);
    $name = $extension !== '' ? substr($basename, 0, -strlen($extension) - 1) : $basename;
    $dotExtension = $extension !== '' ? '.' . $extension : '';

    return [
        'source' => $source,
        'dir' => $directory,
        'directory' => $directory,
        'basename' => $basename,
        'name' => $name !== '' ? $name : $basename,
        'ext' => $extension,
        'dot_ext' => $dotExtension,
    ];
}

function reflection_join_template_path(string $value): string
{
    return preg_replace('#(?<!:)//+#', '/', $value) ?? $value;
}

function reflection_delivery_template_preview(string $template, string $source = 'ftp://storage/incoming/example.dat'): string
{
    $preview = reflection_apply_delivery_template($template, $source);
    return $preview ?? '';
}

function reflection_path_extension_target(string $path): string
{
    $queryPosition = strpos($path, '?');
    if ($queryPosition !== false) {
        return substr($path, 0, $queryPosition);
    }

    $fragmentPosition = strpos($path, '#');
    if ($fragmentPosition !== false) {
        return substr($path, 0, $fragmentPosition);
    }

    return $path;
}

function reflection_delivery_has_extension(string $path, string $extension): bool
{
    $extension = strtolower(trim($extension));
    if ($extension === '' || $extension === 'source') {
        return true;
    }
    if ($extension[0] !== '.') {
        $extension = '.' . $extension;
    }

    $target = strtolower(reflection_path_extension_target($path));
    return substr($target, -strlen($extension)) === $extension;
}

function reflection_resolve_task_paths(string $module, ?string $source, ?string $delivery, array $config): array
{
    $spec = reflection_task_spec($module, $config);
    $sourceMode = (string) ($spec['source']['mode'] ?? 'required');
    $deliverySpec = is_array($spec['delivery'] ?? null) ? $spec['delivery'] : [];
    $deliveryMode = (string) ($deliverySpec['mode'] ?? 'optional');
    $template = trim((string) ($deliverySpec['template'] ?? ''));
    $extension = trim((string) ($deliverySpec['extension'] ?? ''));

    $sourceValue = trim((string) ($source ?? ''));
    $deliveryValue = trim((string) ($delivery ?? ''));

    if ($sourceMode === 'none') {
        $sourceValue = '';
    }

    $sourceError = reflection_path_allowed($sourceValue !== '' ? $sourceValue : null, $sourceMode === 'required');
    if ($sourceError !== null) {
        return ['error' => $sourceError, 'source' => null, 'delivery' => null, 'auto_delivery' => false];
    }

    $autoDelivery = false;
    if ($deliveryMode === 'none') {
        $deliveryValue = '';
    } elseif ($deliveryMode === 'auto' && $deliveryValue === '' && $template !== '') {
        if ($sourceValue === '') {
            return ['error' => 'A source path is required before an automatic delivery path can be generated.', 'source' => null, 'delivery' => null, 'auto_delivery' => false];
        }
        $deliveryValue = reflection_apply_delivery_template($template, $sourceValue) ?? '';
        $autoDelivery = true;
    }

    $deliveryRequired = $deliveryMode === 'required' || ($deliveryMode === 'auto' && $template === '');
    $deliveryError = reflection_path_allowed($deliveryValue !== '' ? $deliveryValue : null, $deliveryRequired);
    if ($deliveryError !== null) {
        return ['error' => $deliveryError, 'source' => null, 'delivery' => null, 'auto_delivery' => $autoDelivery];
    }

    if ($deliveryValue !== '' && !reflection_delivery_has_extension($deliveryValue, $extension)) {
        return [
            'error' => sprintf('%s delivery must end with %s.', $module, $extension),
            'source' => null,
            'delivery' => null,
            'auto_delivery' => $autoDelivery,
        ];
    }

    return [
        'error' => null,
        'source' => $sourceValue !== '' ? $sourceValue : null,
        'delivery' => $deliveryValue !== '' ? $deliveryValue : null,
        'auto_delivery' => $autoDelivery,
        'spec' => $spec,
    ];
}

function reflection_task_contract_summary(array $spec): string
{
    $sourceMode = (string) ($spec['source']['mode'] ?? 'required');
    $delivery = is_array($spec['delivery'] ?? null) ? $spec['delivery'] : [];
    $deliveryMode = (string) ($delivery['mode'] ?? 'optional');
    $parts = ['source ' . $sourceMode, 'delivery ' . $deliveryMode];
    if (!empty($delivery['extension'])) {
        $parts[] = 'output ' . (string) $delivery['extension'];
    }
    if (!empty($delivery['template'])) {
        $parts[] = 'template ' . (string) $delivery['template'];
    }
    return implode(' · ', $parts);
}

function reflection_uploaded_import_text(string $field): string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return '';
    }

    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmpName = (string) ($_FILES[$field]['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return '';
    }

    return (string) file_get_contents($tmpName);
}

function reflection_worker_cards(array $workers, array $machines, int $staleAfterSeconds = 900): array
{
    $cards = [];
    $staleAfterSeconds = max(1, $staleAfterSeconds);
    foreach ($machines as $machine) {
        $pcId = (string) ($machine['pc_id'] ?? '');
        if ($pcId === '') {
            continue;
        }

        $cards[$pcId] = [
            'pc_id' => $pcId,
            'mac' => $machine['mac'] ?? '',
            'min_soc_percent' => $machine['min_soc_percent'] ?? ($machine['soc_margin_percent'] ?? null),
            'wake_enabled' => !empty($machine['wake_enabled']),
            'shutdown_layer' => max(0, (int) ($machine['shutdown_layer'] ?? 0)),
            'version' => '—',
            'current_job' => null,
            'last_check_in' => null,
            'idle_no_job_checkins' => 0,
            'expected_offline' => false,
            'expected_offline_reason' => '',
            'expected_offline_at' => null,
            'state' => 'configured',
        ];
    }

    foreach ($workers as $worker) {
        $pcId = (string) ($worker['pc_id'] ?? 'unknown');
        $lastCheckIn = (string) ($worker['last_check_in'] ?? '');
        $lastSeen = $lastCheckIn !== '' ? strtotime($lastCheckIn) : false;
        $expectedOffline = !empty($worker['expected_offline']);
        $state = !empty($worker['current_job']) ? 'running' : 'idle';
        if ($expectedOffline) {
            $state = 'offline';
        } elseif ($lastSeen === false || (time() - $lastSeen) > $staleAfterSeconds) {
            $state = 'stale';
        }

        $cards[$pcId] = array_merge($cards[$pcId] ?? [
            'pc_id' => $pcId,
            'mac' => '',
            'min_soc_percent' => null,
            'wake_enabled' => false,
            'shutdown_layer' => 0,
        ], [
            'version' => $worker['version'] ?? '—',
            'current_job' => $worker['current_job'] ?? null,
            'last_check_in' => $worker['last_check_in'] ?? null,
            'idle_no_job_checkins' => max(0, (int) ($worker['idle_no_job_checkins'] ?? 0)),
            'expected_offline' => $expectedOffline,
            'expected_offline_reason' => $worker['expected_offline_reason'] ?? '',
            'expected_offline_at' => $worker['expected_offline_at'] ?? null,
            'state' => $state,
        ]);
    }

    ksort($cards);
    return array_values($cards);
}

function reflection_count_worker_states(array $workerCards): array
{
    $counts = [];
    foreach ($workerCards as $card) {
        $state = (string) ($card['state'] ?? 'unknown');
        $counts[$state] = ($counts[$state] ?? 0) + 1;
    }

    return $counts;
}

function reflection_render_worker_summary(array $workerStateCounts): string
{
    ob_start();
    foreach (['running', 'idle', 'offline', 'stale', 'configured'] as $state):
        ?>
        <span><?= reflection_h($state) ?> <strong><?= (int) ($workerStateCounts[$state] ?? 0) ?></strong></span>
        <?php
    endforeach;
    return (string) ob_get_clean();
}

function reflection_render_worker_cards_html(array $workerCards): string
{
    ob_start();
    if ($workerCards === []):
        ?>
        <p class="empty text-secondary text-center py-4">No configured computers or worker check-ins yet.</p>
        <?php
    endif;

    foreach ($workerCards as $card):
        $state = (string) ($card['state'] ?? 'unknown');
        $stateClass = reflection_status_class($state);
        $currentJob = trim((string) ($card['current_job'] ?? ''));
        $currentJobDisplay = $currentJob !== '' ? $currentJob : '—';
        $lastCheckIn = $card['last_check_in'] ?? null;
        $version = trim((string) ($card['version'] ?? ''));
        $versionDisplay = $version !== '' ? $version : '—';
        $minSoc = ($card['min_soc_percent'] ?? null) === null ? 'global' : ((int) $card['min_soc_percent']) . '%';
        $layer = (int) ($card['shutdown_layer'] ?? 0);
        $wakeLabel = !empty($card['wake_enabled']) ? 'WOL on' : 'WOL off';
        $mac = trim((string) ($card['mac'] ?? ''));
        $powerTitle = trim($wakeLabel . ($mac !== '' ? ' · ' . $mac : '') . ' · minimum ESS SOC ' . $minSoc . ' · shutdown layer ' . $layer);
        ?>
        <article class="worker-wide-card col-12 bg-light border rounded-4 p-3">
            <div class="worker-wide-main row g-3 align-items-center">
                <div class="worker-wide-title col-12 col-lg-3 d-flex align-items-center gap-2">
                    <span class="worker-dot rounded-circle d-inline-block p-2 <?= reflection_h(reflection_status_dot_class($state)) ?>" aria-hidden="true"></span>
                    <div>
                        <strong><?= reflection_h($card['pc_id'] ?? 'unknown') ?></strong>
                        <span class="badge <?= reflection_h($stateClass) ?>"><?= reflection_h($state) ?></span>
                    </div>
                </div>
                <div class="worker-wide-focus col-12 col-lg bg-white border rounded-3 p-2">
                    <span>Current job</span>
                    <code title="<?= reflection_h($currentJobDisplay) ?>"><?= reflection_h(reflection_short_value($currentJobDisplay, 40)) ?></code>
                </div>
                <div class="worker-wide-focus col-12 col-lg bg-white border rounded-3 p-2 compact-focus col-lg-2">
                    <span>Seen</span>
                    <strong title="<?= reflection_h($lastCheckIn ?? '') ?>"><?= reflection_h(reflection_relative_time($lastCheckIn)) ?></strong>
                </div>
                <div class="worker-wide-layer col-12 col-lg-auto badge text-bg-light border text-dark soft-label text-secondary">Layer <?= $layer ?></div>
            </div>

            <dl class="worker-wide-details row row-cols-1 row-cols-md-2 row-cols-xl-4 g-2 mt-1">
                <div class="worker-info-box col bg-white border rounded-3 p-2 version-box">
                    <dt>Version</dt>
                    <dd><code title="<?= reflection_h($versionDisplay) ?>"><?= reflection_h(reflection_short_value($versionDisplay, 14)) ?></code></dd>
                </div>
                <div class="worker-info-box col bg-white border rounded-3 p-2 power-box">
                    <dt>Minimum ESS SOC</dt>
                    <dd><?= reflection_h($minSoc) ?></dd>
                </div>
                <div class="worker-info-box col bg-white border rounded-3 p-2 wake-box">
                    <dt>Wake</dt>
                    <dd title="<?= reflection_h($powerTitle) ?>"><?= reflection_h($wakeLabel) ?><?= $mac !== '' ? ' · ' . reflection_h(reflection_short_value($mac, 20)) : '' ?></dd>
                </div>
                <div class="worker-info-box col bg-white border rounded-3 p-2 polls-box">
                    <dt>No-job polls</dt>
                    <dd><?= (int) ($card['idle_no_job_checkins'] ?? 0) ?></dd>
                </div>
                <?php if (!empty($card['expected_offline'])): ?>
                    <div class="worker-info-box col bg-white border rounded-3 p-2 expected-box col-12 col-md-6" title="<?= reflection_h($card['expected_offline_at'] ?? '') ?>">
                        <dt>Expected offline</dt>
                        <dd><?= reflection_h(reflection_relative_time($card['expected_offline_at'] ?? null)) ?><?= ($card['expected_offline_reason'] ?? '') !== '' ? ' · ' . reflection_h($card['expected_offline_reason']) : '' ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($state === 'stale'): ?>
                    <div class="worker-info-box col bg-white border rounded-3 p-2 action-box col-12 col-md-6">
                        <dt>Action</dt>
                        <dd>
                            <form method="post" class="worker-wide-action m-0" data-confirm="Remove this stale worker check-in from the board?">
                                <input type="hidden" name="form_action" value="worker_action">
                                <input type="hidden" name="worker_action" value="remove_stale">
                                <input type="hidden" name="pc_id" value="<?= reflection_h($card['pc_id'] ?? '') ?>">
                                <button type="submit" class="danger-button btn btn-outline-danger small-button btn-sm">Remove stale</button>
                            </form>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </article>
        <?php
    endforeach;

    return (string) ob_get_clean();
}

function reflection_render_power_panel_html(array $context): string
{
    $wakeButtonDisabled = !empty($context['wakeButtonDisabled']);
    $wakeTargetCount = (int) ($context['wakeTargetCount'] ?? 0);
    $wakeEnabledMachineCount = (int) ($context['wakeEnabledMachineCount'] ?? 0);
    $workerLimitDisplay = (string) ($context['workerLimitDisplay'] ?? 'off');
    $workerLimitHelp = (string) ($context['workerLimitHelp'] ?? '');
    $demandWakePlan = is_array($context['demandWakePlan'] ?? null) ? $context['demandWakePlan'] : [];
    $essSocIgnored = !empty($context['essSocIgnored']);
    $essChargingOverrideActive = !empty($context['essChargingOverrideActive']);
    $allowedActiveWorkers = (int) ($context['allowedActiveWorkers'] ?? 0);
    $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
    $queuedWork = (int) ($demandWakePlan['queued_work'] ?? 0);
    $queuedCandidateWork = (int) ($demandWakePlan['queued_candidate_work'] ?? 0);
    $queuedConfirmedWork = (int) ($demandWakePlan['queued_confirmed_work'] ?? max(0, $queuedWork - $queuedCandidateWork));
    $effectiveQueuedWork = (int) ($demandWakePlan['effective_queued_work'] ?? $queuedWork);
    $idleOnlineWorkers = (int) ($demandWakePlan['idle_online_workers'] ?? 0);
    $needed = (int) ($demandWakePlan['needed'] ?? 0);
    $readyTargets = (int) ($demandWakePlan['ready_targets'] ?? 0);
    $demandEnabled = !empty($demandWakePlan['enabled']);
    ob_start();
    ?>
    <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 compact-panel power-panel" id="power-panel">
        <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3 power-head">
            <div>
                <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Power</p>
                <h2>Wake status</h2>
                <small>Manual wake shows eligible offline PCs. Automatic demand wake is conservative for preflight-candidate queues.</small>
            </div>
            <form method="post" class="bare-form m-0">
                <input type="hidden" name="form_action" value="wake_farm">
                <button type="submit" class="secondary-button btn btn-secondary" <?= $wakeButtonDisabled ? 'disabled' : '' ?>>Manual wake <?= (int) $wakeTargetCount ?> PC<?= $wakeTargetCount === 1 ? '' : 's' ?></button>
            </form>
        </div>

        <div class="power-summary row row-cols-1 row-cols-sm-2 g-2 simplified-power-summary row-cols-lg-2">
            <div class="power-summary-item col bg-light border rounded-3 p-2">
                <span>Manual wake candidates</span>
                <strong><?= (int) $wakeTargetCount ?> / <?= (int) $wakeEnabledMachineCount ?></strong>
                <small>offline · WOL enabled · allowed now</small>
            </div>
            <div class="power-summary-item col bg-light border rounded-3 p-2">
                <span>Auto demand wake</span>
                <strong><?= $demandEnabled ? (int) $needed : 'off' ?></strong>
                <small>extra PCs needed now</small>
            </div>
            <div class="power-summary-item col bg-light border rounded-3 p-2">
                <span>Queue coverage</span>
                <strong><?= (int) $effectiveQueuedWork ?> / <?= (int) $idleOnlineWorkers ?></strong>
                <small>effective work / idle online workers</small>
            </div>
            <div class="power-summary-item col bg-light border rounded-3 p-2">
                <span>Candidate queue</span>
                <strong><?= (int) $queuedCandidateWork ?></strong>
                <small>worker preflight pending</small>
            </div>
            <div class="power-summary-item col bg-light border rounded-3 p-2">
                <span>SOC rules</span>
                <strong><?= reflection_h($workerLimitDisplay) ?></strong>
                <small><?= reflection_h($workerLimitHelp) ?></small>
            </div>
        </div>

        <?php if ($wakeEnabledMachineCount === 0): ?>
            <p class="api-note text-secondary small panel-warning-note alert alert-warning">No Wake-on-LAN targets are configured. Add machines in Settings to use farm wake control.</p>
        <?php elseif ($essSocIgnored): ?>
            <p class="api-note text-secondary small panel-warning-note alert alert-warning">ESS SOC is <?= reflection_h(reflection_ess_status_label($settings)) ?>. SOC limiting is paused, so every configured wake target is eligible until valid SOC data returns.</p>
        <?php elseif ($wakeTargetCount === 0): ?>
            <p class="api-note text-secondary small panel-warning-note alert alert-warning">No offline wake-enabled PC is currently eligible for manual wake. This can mean all eligible machines are already online, blocked by SOC, or inside wake cooldown.</p>
        <?php elseif ($essChargingOverrideActive): ?>
            <p class="api-note text-secondary small">ESS reports charging and charging override is enabled. Minimum SOC rules are bypassed, so eligible offline wake targets may be woken.</p>
        <?php elseif ($allowedActiveWorkers === PHP_INT_MAX): ?>
            <p class="api-note text-secondary small">SOC is not currently capping workers. Manual wake can target any configured offline WOL machine.</p>
        <?php else: ?>
            <p class="api-note text-secondary small">Current SOC allows <?= (int) $allowedActiveWorkers ?> configured worker<?= $allowedActiveWorkers === 1 ? '' : 's' ?> by minimum ESS SOC. <?= (int) $wakeTargetCount ?> offline WOL target<?= $wakeTargetCount === 1 ? '' : 's' ?> are currently eligible.</p>
        <?php endif; ?>

        <?php if (!$demandEnabled): ?>
            <p class="api-note text-secondary small">Automatic demand wake is off. Jobs can still be queued, but machines wake only when you press the manual button or by another external schedule.</p>
        <?php elseif ($needed > 0): ?>
            <p class="api-note text-secondary small">Automatic demand wake wants <?= (int) $needed ?> more PC<?= $needed === 1 ? '' : 's' ?> for queued work. <?= (int) $readyTargets ?> eligible target<?= $readyTargets === 1 ? '' : 's' ?> are ready after cooldown. Worker-preflight candidates are counted conservatively so the farm does not wake every PC just to discover many files should be skipped. Lower shutdown layers are woken first.</p>
        <?php else: ?>
            <p class="api-note text-secondary small">Automatic demand wake is satisfied. There are no queued jobs that need another worker right now. Candidate jobs remain in the queue until a farm computer tests and either processes or skips them.</p>
        <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

function reflection_render_storage_history_panel_html(array $settings, array $archiveInfo): string
{
    ob_start();
    ?>
    <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 compact-panel history-panel">
        <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Storage</p>
                <h2>History cleanup</h2>
                <small>Maintenance archives old completed jobs and trims dashboard history. It does not wake workers or delete source files.</small>
            </div>
        </div>
        <dl class="detail-list list-unstyled mb-0">
            <div><dt>Completed jobs kept</dt><dd><?= (int) ($settings['job_history_keep_completed'] ?? 500) ?></dd></div>
            <div><dt>Event lines kept</dt><dd><?= (int) ($settings['event_log_keep_lines'] ?? 1000) ?></dd></div>
            <div><dt>File-history paths kept</dt><dd><?= (int) ($settings['file_history_keep_paths'] ?? 500) ?></dd></div>
            <div><dt>Archive file</dt><dd><?= reflection_h(reflection_format_bytes((int) $archiveInfo['size_bytes'])) ?></dd></div>
        </dl>
        <form method="post" class="maintenance-form mt-3">
            <input type="hidden" name="form_action" value="maintenance">
            <button type="submit" class="ghost-button btn btn-outline-primary">Clean history now</button>
        </form>
    </section>
    <?php
    return (string) ob_get_clean();
}

function reflection_url_with(array $overrides): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    $queryString = http_build_query($query);
    return $queryString === '' ? '?' : '?' . $queryString;
}

function reflection_append_message(?string $message, string $addition): string
{
    $addition = trim($addition);
    if ($addition === '') {
        return (string) ($message ?? '');
    }

    $current = trim((string) ($message ?? ''));
    return $current === '' ? $addition : $current . ' ' . $addition;
}

function reflection_manual_wake_result(FarmStore $store, int $staleAfterSeconds): array
{
    $targets = $store->wakeTargetsForCurrentSoc(true, $staleAfterSeconds);
    if ($targets === []) {
        return [
            'message' => null,
            'error' => 'No Wake-on-LAN targets are currently eligible. Check configured machines, MAC addresses, minimum ESS SOC values, and current online workers.',
        ];
    }

    $wakeResult = $store->dispatchWakeTargets($targets, 'manual');
    $sent = (int) ($wakeResult['sent'] ?? 0);
    $queued = (int) ($wakeResult['queued'] ?? 0);
    if ($queued > 0) {
        $message = 'Queued a Wake-on-LAN relay task for ' . $queued . ' computer' . ($queued === 1 ? '' : 's') . '. The next worker that checks in will send the packets.';
    } else {
        $message = 'Sent Wake-on-LAN packets to ' . $sent . ' computer' . ($sent === 1 ? '' : 's') . '.';
    }
    if (!empty($wakeResult['relay_pending'])) {
        $message = 'A Wake-on-LAN relay task is already queued or running.';
    }

    $failed = (int) ($wakeResult['failed'] ?? 0);
    return [
        'message' => $message,
        'error' => $failed > 0 ? $failed . ' Wake-on-LAN attempt(s) failed. Check recent events for details.' : null,
    ];
}

function reflection_auto_wake_notice(FarmStore $store, int $staleAfterSeconds, string $reason): ?string
{
    $store->refreshEssSocFromConfiguredEndpoint();
    $plan = $store->autoWakeForQueuedJobs($staleAfterSeconds, $reason);
    if (empty($plan['enabled'])) {
        return null;
    }

    $sent = (int) ($plan['wake_result']['sent'] ?? 0);
    $queued = (int) ($plan['wake_result']['queued'] ?? 0);
    $failed = (int) ($plan['wake_result']['failed'] ?? 0);
    $needed = (int) ($plan['needed'] ?? 0);
    $ready = (int) ($plan['ready_targets'] ?? 0);
    if ($queued > 0) {
        return 'Demand wake queued a worker relay task for ' . $queued . ' computer' . ($queued === 1 ? '' : 's') . '.';
    }
    if (!empty($plan['wake_result']['relay_pending'])) {
        return 'Demand wake is waiting for an already queued/running Wake-on-LAN relay task.';
    }
    if ($sent > 0) {
        $notice = 'Demand wake sent to ' . $sent . ' computer' . ($sent === 1 ? '' : 's') . ' for ' . (int) ($plan['queued_work'] ?? 0) . ' queued job' . ((int) ($plan['queued_work'] ?? 0) === 1 ? '' : 's') . '.';
        if ($failed > 0) {
            $notice .= ' ' . $failed . ' wake attempt' . ($failed === 1 ? '' : 's') . ' failed.';
        }
        return $notice;
    }

    if ($needed > 0 && $ready === 0) {
        return 'Demand wake wanted ' . $needed . ' more worker' . ($needed === 1 ? '' : 's') . ', but no eligible Wake-on-LAN target is ready right now.';
    }

    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'single');
    $isAjax = (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');
    $module = trim((string) ($_POST['module'] ?? ''));
    $delivery = trim((string) ($_POST[$formAction === 'bulk' ? 'bulk_delivery' : 'single_delivery'] ?? ''));
    $overwriteAllowed = isset($_POST['overwrite_allowed']);
    $controlTasks = ['noop', 'status', 'reload_tasks', 'shutdown', 'update_worker', 'wake_farm', 'storage_test'];
    $isControlTask = in_array($module, $controlTasks, true);
    $taskSpec = $module !== '' ? reflection_task_spec($module, $config) : [];
    $transferServerId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['transfer_server_id'] ?? '')) ?: '';
    $transferExtra = (!$isControlTask && $transferServerId !== '') ? ['transfer_server_id' => $transferServerId] : [];

    if ($formAction === 'job_action') {
        $jobAction = (string) ($_POST['job_action'] ?? '');
        $taskId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['task_id'] ?? '')) ?: '';
        try {
            if ($taskId === '') {
                throw new RuntimeException('Missing task id.');
            }

            if ($jobAction === 'delete') {
                $message = $store->deleteJob($taskId)
                    ? 'Job moved to the bin.'
                    : 'Job was not moved to the bin. Running jobs must be held before deletion.';
            } elseif ($jobAction === 'hold') {
                $message = $store->holdJob($taskId)
                    ? 'Job placed on hold. Any assigned worker will relinquish it at its next heartbeat.'
                    : 'Job was not held. Only queued or running jobs can be held.';
            } elseif ($jobAction === 'release') {
                $message = $store->releaseHeldJob($taskId)
                    ? 'Job released back to the queue.'
                    : 'Job was not released. Only held jobs can be released.';
            } elseif ($jobAction === 'retry') {
                $retryJob = $store->retryJob($taskId);
                $message = $retryJob
                    ? 'Retry queued as ' . ($retryJob['task_id'] ?? 'new job') . '.'
                    : 'Job was not retried. Only failed, stale, or blocked jobs can be retried.';
            } elseif ($jobAction === 'move_earlier') {
                $message = $store->moveQueuedJob($taskId, 'earlier')
                    ? 'Job moved sooner in the queue.'
                    : 'Job was not moved. Only queued jobs can be reordered.';
            } elseif ($jobAction === 'move_later') {
                $message = $store->moveQueuedJob($taskId, 'later')
                    ? 'Job moved later in the queue.'
                    : 'Job was not moved. Only queued jobs can be reordered.';
            } else {
                throw new RuntimeException('Unknown job action.');
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $error === null,
                'message' => $message,
                'error' => $error,
            ]);
            exit;
        }
    } elseif ($formAction === 'worker_action') {
        $workerAction = (string) ($_POST['worker_action'] ?? '');
        $pcId = trim((string) ($_POST['pc_id'] ?? ''));
        try {
            if ($pcId === '' || preg_match('/[\x00-\x1F\x7F]/', $pcId) === 1) {
                throw new RuntimeException('Missing or invalid computer id.');
            }

            if ($workerAction === 'remove_stale') {
                $staleAfterSeconds = max(1, (int) ($config['stale_after_seconds'] ?? 900));
                $message = $store->removeWorker($pcId, true, $staleAfterSeconds)
                    ? 'Stale worker check-in removed.'
                    : 'Worker check-in was not removed. It may already be active again.';
            } else {
                throw new RuntimeException('Unknown worker action.');
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($formAction === 'settings') {
        $settings = $store->updateSettings([
            'enforce_version' => isset($_POST['enforce_version']),
            'failure_strategy' => (string) ($_POST['failure_strategy'] ?? 'mark_failed'),
            'max_retries' => (int) ($_POST['max_retries'] ?? 0),
            'stale_job_strategy' => (string) ($_POST['stale_job_strategy'] ?? 'requeue_to_end'),
            'stale_max_retries' => (int) ($_POST['stale_max_retries'] ?? 1),
            'crash_loop_protection_enabled' => isset($_POST['crash_loop_protection_enabled']),
            'crash_loop_lost_attempts' => (int) ($_POST['crash_loop_lost_attempts'] ?? 2),
            'crash_loop_distinct_workers' => (int) ($_POST['crash_loop_distinct_workers'] ?? 1),
            'ess_soc_url' => trim((string) ($_POST['ess_soc_url'] ?? '')),
            'ess_min_soc_percent' => (int) ($_POST['ess_min_soc_percent'] ?? 20),
            'ess_shutdown_below_minimum' => isset($_POST['ess_shutdown_below_minimum']),
            'ess_ignore_when_unavailable' => isset($_POST['ess_ignore_when_unavailable']),
            'idle_shutdown_after_no_job_checks' => (int) ($_POST['idle_shutdown_after_no_job_checks'] ?? 0),
            'shutdown_debug_mode' => isset($_POST['shutdown_debug_mode']),
            'auto_wake_for_queued_jobs' => isset($_POST['auto_wake_for_queued_jobs']),
            'automation_run_due_on_worker_checkin' => isset($_POST['automation_run_due_on_worker_checkin']),
            'automation_checkin_cooldown_seconds' => (int) ($_POST['automation_checkin_cooldown_seconds'] ?? 60),
            'wake_dispatch_mode' => (string) ($_POST['wake_dispatch_mode'] ?? 'worker_relay'),
            'auto_wake_cooldown_seconds' => (int) ($_POST['auto_wake_cooldown_seconds'] ?? 300),
            'auto_wake_max_targets_per_run' => (int) ($_POST['auto_wake_max_targets_per_run'] ?? 20),
            'wake_broadcast_address' => trim((string) ($_POST['wake_broadcast_address'] ?? '255.255.255.255')),
            'wake_udp_port' => (int) ($_POST['wake_udp_port'] ?? 9),
            'job_history_keep_completed' => (int) ($_POST['job_history_keep_completed'] ?? 500),
            'event_log_keep_lines' => (int) ($_POST['event_log_keep_lines'] ?? 1000),
            'file_history_keep_paths' => (int) ($_POST['file_history_keep_paths'] ?? 500),
            'file_history_keep_entries_per_path' => (int) ($_POST['file_history_keep_entries_per_path'] ?? 10),
            'job_archive_keep_lines' => (int) ($_POST['job_archive_keep_lines'] ?? 5000),
            'worker_temp_max_age_hours' => (int) ($_POST['worker_temp_max_age_hours'] ?? 24),
            'quarantine_keep_days' => (int) ($_POST['quarantine_keep_days'] ?? 14),
            'quarantine_max_gb' => (float) ($_POST['quarantine_max_gb'] ?? 100),
        ]);
        $store->updateMachines(reflection_parse_machine_list((string) ($_POST['machines'] ?? '')));
        $maintenance = reflection_run_store_maintenance($store, $settings);
        $message = 'Saved options. Archived ' . $maintenance['archived_jobs'] . ' old completed job(s), trimmed ' . $maintenance['trimmed_events'] . ' event(s), compacted ' . $maintenance['trimmed_file_history'] . ' file-history item(s), and trimmed ' . $maintenance['trimmed_job_archive'] . ' archived job line(s).';
    } elseif ($formAction === 'maintenance') {
        $maintenance = reflection_run_store_maintenance($store, $store->effectiveSettings());
        $message = 'Maintenance complete. Archived ' . $maintenance['archived_jobs'] . ' old completed job(s), trimmed ' . $maintenance['trimmed_events'] . ' event(s), compacted ' . $maintenance['trimmed_file_history'] . ' file-history item(s), and trimmed ' . $maintenance['trimmed_job_archive'] . ' archived job line(s).';
    } elseif ($formAction === 'wake_farm' || $module === 'wake_farm') {
        $wake = reflection_manual_wake_result($store, (int) ($config['stale_after_seconds'] ?? 900));
        $message = $wake['message'];
        $error = $wake['error'];
    } elseif ($formAction === 'bulk') {
        $importText = (string) ($_POST['source_list'] ?? '');
        $uploadedText = reflection_uploaded_import_text('source_file');
        if ($uploadedText !== '') {
            $importText .= PHP_EOL . $uploadedText;
        }

        $paths = reflection_import_lines($importText);
        $queued = 0;
        $skipped = [];
        $error = reflection_validate_task($module, $config);
        if ($error === null && !$isControlTask && $transferServerId !== '' && !in_array($transferServerId, $storageServerIds, true)) {
            $error = 'Choose an available storage server.';
        }

        if ($error === null) {
            foreach ($paths as $lineNumber => $path) {
                $source = reflection_clean_import_path((string) $path);
                if ($source === '') {
                    continue;
                }

                $lineLabel = 'line ' . ((int) $lineNumber + 1) . ' (' . $source . ')';
                $resolvedPaths = reflection_resolve_task_paths($module, $source, $delivery, $config);
                if (($resolvedPaths['error'] ?? null) !== null) {
                    $skipped[] = $lineLabel . ': ' . (string) $resolvedPaths['error'];
                    continue;
                }

                $jobExtra = $transferExtra;
                $jobExtra['task_contract'] = reflection_task_contract_summary($resolvedPaths['spec'] ?? reflection_task_spec($module, $config));
                if (!empty($resolvedPaths['auto_delivery'])) {
                    $jobExtra['delivery_auto_generated'] = true;
                }

                $store->createJob(
                    $module,
                    $resolvedPaths['source'] ?? null,
                    $resolvedPaths['delivery'] ?? null,
                    $overwriteAllowed,
                    $jobExtra
                );
                $queued++;
            }

            if ($queued > 0) {
                $message = 'Imported ' . $queued . ' job(s) for ' . $module . '.';
            }

            if ($skipped !== []) {
                $error = 'Skipped ' . count($skipped) . ' item(s): ' . implode(' | ', array_slice($skipped, 0, 6));
                if (count($skipped) > 6) {
                    $error .= ' | ...';
                }
            } elseif ($queued === 0) {
                $error = 'No importable source paths found.';
            }
            if ($queued > 0 && !$isControlTask) {
                $notice = reflection_auto_wake_notice($store, (int) ($config['stale_after_seconds'] ?? 900), 'queue_bulk');
                if ($notice !== null) {
                    $message = reflection_append_message($message, $notice);
                }
            }
        }
    } else {
        $source = trim((string) ($_POST['single_source'] ?? ''));
        $resolvedPaths = ['error' => null, 'source' => null, 'delivery' => null, 'auto_delivery' => false, 'spec' => $taskSpec];
        $error = reflection_validate_task($module, $config)
            ?? ((!$isControlTask && $transferServerId !== '' && !in_array($transferServerId, $storageServerIds, true)) ? 'Choose an available storage server.' : null);

        if ($error === null) {
            $resolvedPaths = reflection_resolve_task_paths($module, $source, $delivery, $config);
            $error = $resolvedPaths['error'] ?? null;
        }

        if ($error === null) {
            $jobExtra = $transferExtra;
            $jobExtra['task_contract'] = reflection_task_contract_summary($resolvedPaths['spec'] ?? reflection_task_spec($module, $config));
            if (!empty($resolvedPaths['auto_delivery'])) {
                $jobExtra['delivery_auto_generated'] = true;
            }

            $job = $store->createJob(
                $module,
                $resolvedPaths['source'] ?? null,
                $resolvedPaths['delivery'] ?? null,
                $overwriteAllowed,
                $jobExtra
            );
            $message = 'Queued ' . $job['task_id'] . ' for ' . $job['module'] . '.';
            if (!empty($resolvedPaths['auto_delivery']) && !empty($job['delivery'])) {
                $message .= ' Delivery auto-generated: ' . $job['delivery'] . '.';
            }
            if (!$isControlTask) {
                $notice = reflection_auto_wake_notice($store, (int) ($config['stale_after_seconds'] ?? 900), 'queue_single');
                if ($notice !== null) {
                    $message = reflection_append_message($message, $notice);
                }
            }
        }
    }
}

$store->refreshEssSocFromConfiguredEndpoint();
$staleCount = $store->requeueStaleJobs((int) $config['stale_after_seconds']);
$settings = $store->effectiveSettings();
$essSocIgnored = reflection_ess_soc_is_ignored($settings);
$essChargingOverrideActive = reflection_ess_charging_override_active($settings);
$automaticMaintenance = reflection_run_store_maintenance($store, $settings);
$data = $store->read();
$workers = $data['workers'];
$events = $store->readRecentEvents(5);
$fileHistory = array_slice($store->readFileHistory(), 0, 5, true);
$machines = $store->machines();
$allowedActiveWorkers = $store->allowedActiveWorkers();
$wakeTargets = $store->wakeTargetsForCurrentSoc(true, (int) ($config['stale_after_seconds'] ?? 900));
$wakeTargetCount = count($wakeTargets);
$wakeEnabledMachineCount = 0;
foreach ($machines as $machine) {
    if (!empty($machine['wake_enabled']) && trim((string) ($machine['mac'] ?? '')) !== '') {
        $wakeEnabledMachineCount++;
    }
}
$demandWakePlan = $store->demandWakePlan((int) ($config['stale_after_seconds'] ?? 900));
$wakeButtonDisabled = $wakeTargetCount === 0;
$workerLimitDisplay = $essSocIgnored
    ? 'paused'
    : ($essChargingOverrideActive ? 'charging' : ($allowedActiveWorkers === PHP_INT_MAX ? 'off' : (string) (int) $allowedActiveWorkers));
$workerLimitHelp = $essSocIgnored
    ? 'ESS unavailable'
    : ($essChargingOverrideActive ? 'charging override active' : ($allowedActiveWorkers === PHP_INT_MAX ? 'no SOC cap' : 'workers whose minimum ESS SOC is met'));
$workerStaleAfterSeconds = max(1, (int) ($config['stale_after_seconds'] ?? 900));
$workerCards = reflection_worker_cards($workers, $machines, $workerStaleAfterSeconds);
$workerStateCounts = reflection_count_worker_states($workerCards);
$archiveInfo = $store->archiveInfo();
$validJobFilters = ['all', 'active', 'queued', 'running', 'held', 'success', 'skipped', 'failed', 'stale', 'blocked', 'ignored', 'finished'];
$jobStatus = (string) ($_GET['job_status'] ?? 'all');
if (!in_array($jobStatus, $validJobFilters, true)) {
    $jobStatus = 'all';
}
$jobPageData = $store->jobPage(
    (int) ($_GET['job_page'] ?? 1),
    (int) ($_GET['job_per_page'] ?? 50),
    $jobStatus,
);
$jobs = $jobPageData['jobs'];
$statusCounts = $jobPageData['status_counts'];
$activeJobsPage = $store->jobPage(1, 200, 'active');
$activeJobsAll = $activeJobsPage['jobs'];
$activeJobsPreviewLimit = 5;
$activeJobsPreview = array_slice($activeJobsAll, 0, $activeJobsPreviewLimit);
$activeJobsMore = array_slice($activeJobsAll, $activeJobsPreviewLimit);
$activeJobsShownLimit = count($activeJobsAll);
$completedInStore = (int) ($statusCounts['success'] ?? 0) + (int) ($statusCounts['skipped'] ?? 0) + (int) ($statusCounts['failed'] ?? 0) + (int) ($statusCounts['stale'] ?? 0) + (int) ($statusCounts['blocked'] ?? 0) + (int) ($statusCounts['ignored'] ?? 0);
$activeCount = (int) ($statusCounts['queued'] ?? 0) + (int) ($statusCounts['running'] ?? 0) + (int) ($statusCounts['held'] ?? 0);
$maintenanceChanged = array_sum($automaticMaintenance) > 0;
$powerPanelContext = [
    'wakeButtonDisabled' => $wakeButtonDisabled,
    'wakeTargetCount' => $wakeTargetCount,
    'wakeEnabledMachineCount' => $wakeEnabledMachineCount,
    'workerLimitDisplay' => $workerLimitDisplay,
    'workerLimitHelp' => $workerLimitHelp,
    'demandWakePlan' => $demandWakePlan,
    'essSocIgnored' => $essSocIgnored,
    'essChargingOverrideActive' => $essChargingOverrideActive,
    'allowedActiveWorkers' => $allowedActiveWorkers,
    'settings' => $settings,
];

// Handle AJAX dashboard refresh
if ((strtolower((string) ($_GET['ajax'] ?? '')) === '1' || strtolower((string) ($_POST['ajax'] ?? '')) === '1')) {
    header('Content-Type: application/json');
    
    // Render overview metrics section
    ob_start();
    ?>
    <article class="metric col bg-white border rounded-4 shadow-sm p-3 primary border-primary bg-primary-subtle">
        <span>Active jobs</span>
        <strong><?= $activeCount ?></strong>
        <small><?= (int) ($statusCounts['queued'] ?? 0) ?> queued · <?= (int) ($statusCounts['running'] ?? 0) ?> running · <?= (int) ($statusCounts['held'] ?? 0) ?> held</small>
    </article>
    <article class="metric <?= $essSocIgnored ? 'warning-metric' : ($essChargingOverrideActive ? 'charging-metric' : '') ?>">
        <span>ESS SOC</span>
        <?php if ($essSocIgnored): ?>
            <strong>ignored</strong>
            <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · last good <?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</small>
        <?php else: ?>
            <strong><?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</strong>
            <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · charging <?= reflection_h(reflection_ess_charging_label($settings)) ?><?= $essChargingOverrideActive ? ' · SOC limits bypassed' : ' · minimum ' . (int) ($settings['ess_min_soc_percent'] ?? 20) . '%' ?></small>
        <?php endif; ?>
    </article>
    <article class="metric <?= $essSocIgnored ? 'warning-metric' : '' ?>">
        <span>SOC worker allowance</span>
        <strong><?= reflection_h($workerLimitDisplay) ?></strong>
        <small><?= reflection_h($workerLimitHelp) ?> · <?= (int) $wakeTargetCount ?>/<?= (int) $wakeEnabledMachineCount ?> eligible offline WOL</small>
    </article>
    <article class="metric col bg-white border rounded-4 shadow-sm p-3">
        <span>Completed kept</span>
        <strong><?= $completedInStore ?></strong>
        <small><?= (int) $archiveInfo['jobs'] ?> archived · <?= reflection_h(reflection_format_bytes((int) $archiveInfo['size_bytes'])) ?></small>
    </article>
    <article class="metric col bg-white border rounded-4 shadow-sm p-3">
        <span>Workers</span>
        <strong><?= count($workerCards) ?></strong>
        <small><?= (int) ($workerStateCounts['running'] ?? 0) ?> running · <?= (int) ($workerStateCounts['idle'] ?? 0) ?> idle</small>
    </article>
    <?php
    $metricsHtml = ob_get_clean();
    
    // Render workers section
    $workerSummaryHtml = reflection_render_worker_summary($workerStateCounts);
    $workersHtml = reflection_render_worker_cards_html($workerCards);
    $powerHtml = reflection_render_power_panel_html($powerPanelContext);
    
    // Render jobs table rows
    ob_start();
    ?>
    <?php if ($jobs === []): ?>
        <tr><td colspan="8" class="empty text-secondary text-center py-4">No jobs match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($jobs as $job): ?>
        <?php $jobStatusValue = (string) ($job['status'] ?? 'unknown'); $jobDisplay = reflection_job_display_status($job); ?>
        <tr>
            <td><code><?= reflection_h($job['task_id'] ?? '—') ?></code></td>
            <td><?= reflection_h($job['module'] ?? '—') ?></td>
            <td>
                <span class="badge <?= reflection_h(reflection_status_class($jobDisplay['class'])) ?>"><?= reflection_h($jobDisplay['label']) ?></span>
                <?php if ($jobDisplay['detail'] !== ''): ?><small class="job-stage-note text-secondary small d-block"><?= reflection_h(reflection_short_value($jobDisplay['detail'], 120)) ?></small><?php endif; ?>
            </td>
            <td><?= reflection_h($job['worker'] ?? '—') ?></td>
            <td class="path-cell text-break">
                <div><span class="subtle-label text-secondary text-uppercase fw-bold small">Source</span> <code title="<?= reflection_h($job['source'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['source'] ?? '—')) ?></code></div>
                <div><span class="subtle-label text-secondary text-uppercase fw-bold small">Delivery<?= !empty($job['delivery_auto_generated']) ? ' auto' : '' ?></span> <code title="<?= reflection_h($job['delivery'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['delivery'] ?? '—')) ?></code></div>
                <?php if (!empty($job['task_contract'])): ?>
                    <small><?= reflection_h($job['task_contract']) ?></small>
                <?php endif; ?>
            </td>
            <td>
                <span title="<?= reflection_h($job['created_at'] ?? '') ?>">Created <?= reflection_h(reflection_relative_time($job['created_at'] ?? null)) ?></span><br>
                <span title="<?= reflection_h($job['started_at'] ?? '') ?>">Started <?= reflection_h(reflection_relative_time($job['started_at'] ?? null)) ?></span><br>
                <span title="<?= reflection_h($job['finished_at'] ?? '') ?>">Finished <?= reflection_h(reflection_relative_time($job['finished_at'] ?? null)) ?></span>
            </td>
            <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 140)) ?></td>
            <td>
                <div class="button-row d-flex flex-wrap gap-2 table-actions align-items-center">
                    <?php if ($jobStatusValue === 'queued'): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="move_earlier">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Sooner</button>
                        </form>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="move_later">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Later</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($jobStatusValue, ['queued', 'running'], true)): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="hold">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Hold</button>
                        </form>
                    <?php elseif ($jobStatusValue === 'held'): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="release">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Release</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($jobStatusValue, ['failed', 'stale', 'blocked'], true)): ?>
                        <form method="post" style="display: inline;" data-confirm="Queue a fresh retry of this job?">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="retry">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Retry</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($jobStatusValue !== 'running'): ?>
                        <form method="post" style="display: inline;" data-confirm="Move this job to the bin?">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="delete">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="danger-button btn btn-outline-danger small-button btn-sm" type="submit">Delete</button>
                        </form>
                    <?php else: ?>
                        <span class="api-note text-secondary small">Hold a running job before deleting it.</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php
    $jobsHtml = ob_get_clean();

    // Render job status tabs
    ob_start();
    ?>
    <?php foreach (['all', 'active', 'queued', 'running', 'held', 'success', 'skipped', 'failed', 'stale', 'blocked', 'ignored', 'finished'] as $filter): ?>
        <?php
            if ($filter === 'all') {
                $tabCount = array_sum($statusCounts);
            } elseif ($filter === 'active') {
                $tabCount = $activeCount;
            } elseif ($filter === 'finished') {
                $tabCount = $completedInStore;
            } else {
                $tabCount = (int) ($statusCounts[$filter] ?? 0);
            }
        ?>
        <button type="button" class="nav-link <?= $jobStatus === $filter ? 'active' : '' ?>" data-job-status-filter="<?= reflection_h($filter) ?>"><?= reflection_h($filter) ?> <span><?= $tabCount ?></span></button>
    <?php endforeach; ?>
    <?php
    $jobTabsHtml = ob_get_clean();

    // Render job pagination
    ob_start();
    ?>
    <a class="<?= (int) $jobPageData['page'] <= 1 ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_url_with(['job_page' => max(1, (int) $jobPageData['page'] - 1)])) ?>">Previous</a>
    <span>Page <?= (int) $jobPageData['page'] ?> of <?= (int) $jobPageData['pages'] ?></span>
    <a class="<?= (int) $jobPageData['page'] >= (int) $jobPageData['pages'] ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_url_with(['job_page' => min((int) $jobPageData['pages'], (int) $jobPageData['page'] + 1)])) ?>">Next</a>
    <?php
    $jobPaginationHtml = ob_get_clean();
    $jobSummaryText = 'Showing ' . count($jobs) . ' of ' . (int) $jobPageData['total'] . ' job(s). Choose a filter or press Apply filters; the table updates without a full page reload. Queued jobs can be moved earlier or later in the worker pick-up order.';
    
    // Render events
    ob_start();
    ?>
    <?php if ($events === []): ?>
        <tr><td colspan="5" class="empty text-secondary text-center py-4">No log entries yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($events as $event): ?>
        <tr>
            <td title="<?= reflection_h($event['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($event['timestamp'] ?? null)) ?></td>
            <td><?= reflection_h($event['event'] ?? '—') ?></td>
            <td><code><?= reflection_h($event['task_id'] ?? '—') ?></code></td>
            <td><?= reflection_h($event['worker'] ?? '—') ?></td>
            <td><?= reflection_h(reflection_short_value($event['error'] ?? '', 90)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php
    $eventsHtml = ob_get_clean();
    
    // Render file history
    ob_start();
    ?>
    <?php if ($fileHistory === []): ?>
        <tr><td colspan="3" class="empty text-secondary text-center py-4">No file or URI history yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($fileHistory as $path => $touches): ?>
        <?php $recentTouches = array_slice(array_reverse($touches), 0, 3); ?>
        <tr>
            <td class="path-cell text-break"><code title="<?= reflection_h($path) ?>"><?= reflection_h(reflection_short_value($path, 80)) ?></code></td>
            <td title="<?= reflection_h($recentTouches[0]['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($recentTouches[0]['timestamp'] ?? null)) ?></td>
            <td>
                <?php foreach ($recentTouches as $touch): ?>
                    <div><strong><?= reflection_h($touch['action'] ?? '—') ?></strong> · <code><?= reflection_h($touch['task_id'] ?? '—') ?></code></div>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php
    $filesHtml = ob_get_clean();
    
    echo json_encode([
        'metrics' => $metricsHtml,
        'workers' => $workersHtml,
        'worker_summary' => $workerSummaryHtml,
        'power' => $powerHtml,
        'jobs' => $jobsHtml,
        'job_tabs' => $jobTabsHtml,
        'job_pagination' => $jobPaginationHtml,
        'job_summary' => $jobSummaryText,
        'job_status' => $jobStatus,
        'job_per_page' => (int) $jobPageData['per_page'],
        'events' => $eventsHtml,
        'files' => $filesHtml,
        'timestamp' => time(),
    ]);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reflection Farm Master</title>
    <?= reflection_stylesheet_links() ?>
</head>
<body class="bg-light text-dark container-fluid px-3 px-md-4 py-4">
    <header class="hero row g-3 align-items-stretch mb-4">
        <div class="hero-main col-12 col-lg bg-white border rounded-4 shadow-sm p-4">
            <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Reflection farm master</p>
            <h1><?= reflection_h($config['farm_name'] ?? 'Farm Master') ?></h1>
            <p class="lede text-secondary fs-5 mb-3">Queue cluster work, watch active machines, and keep the master store small enough to stay quick.</p>
            <div class="hero-pills d-flex flex-wrap gap-2">
                <span>Farm <code><?= reflection_h($config['farm_id'] ?? 'default') ?></code></span>
                <span>Master version <code><?= reflection_h((string) ($config['required_version'] ?? 'unknown')) ?></code></span>
                <span>Version enforcement <strong><?= !empty($settings['enforce_version']) ? 'on' : 'off' ?></strong></span>
            </div>
            <nav class="top-nav nav nav-pills flex-wrap gap-2 mt-3">
                <a class="nav-link active" href="index.php">Dashboard</a>
                <a class="nav-link" href="automation.php">Automation</a>
                <a class="nav-link" href="storage_servers.php">Storage servers</a>
                <a class="nav-link" href="blocked_jobs.php">Blocked jobs</a>
                <a class="nav-link" href="system_checks.php">System checks</a>
                <a class="nav-link" href="logs.php">Logs</a>
                <a class="nav-link" href="settings.php">Settings</a>
            </nav>
        </div>
        <aside class="version-card col-12 col-lg-4 bg-white border rounded-4 shadow-sm p-4 d-flex flex-column gap-2 active-work-card">
            <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Now</p>
                    <h2>Active work</h2>
                </div>
                <a class="text-link link-primary" href="<?= reflection_h(reflection_url_with(['job_status' => 'active', 'job_page' => 1])) ?>">View active</a>
            </div>
            <?php if ($activeJobsPreview === []): ?>
                <p class="empty text-secondary text-center py-4">No queued or running jobs.</p>
            <?php endif; ?>
            <div class="mini-list list-group list-group-flush active-work-preview-list">
                <?php foreach ($activeJobsPreview as $job): ?>
                    <article class="mini-row list-group-item d-flex gap-3 align-items-start px-0">
                        <?php $jobDisplay = reflection_job_display_status($job); ?>
                        <span class="badge <?= reflection_h(reflection_status_class($jobDisplay['class'])) ?>"><?= reflection_h($jobDisplay['label']) ?></span>
                        <div>
                            <strong><code><?= reflection_h($job['task_id'] ?? '—') ?></code> · <?= reflection_h($job['module'] ?? '—') ?></strong>
                            <small><?= reflection_h(reflection_short_value($job['source'] ?? '—', 70)) ?></small>
                            <?php if ($jobDisplay['detail'] !== ''): ?><small><?= reflection_h(reflection_short_value($jobDisplay['detail'], 70)) ?></small><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($activeJobsMore !== []): ?>
                <details class="active-work-more mt-3" id="active-work-more">
                    <summary>Show remaining <?= (int) count($activeJobsMore) ?> active job<?= count($activeJobsMore) === 1 ? '' : 's' ?></summary>
                    <div class="mini-list list-group list-group-flush active-work-expanded-list">
                        <?php foreach ($activeJobsMore as $job): ?>
                            <article class="mini-row list-group-item d-flex gap-3 align-items-start px-0">
                                <?php $jobDisplay = reflection_job_display_status($job); ?>
                                <span class="badge <?= reflection_h(reflection_status_class($jobDisplay['class'])) ?>"><?= reflection_h($jobDisplay['label']) ?></span>
                                <div>
                                    <strong><code><?= reflection_h($job['task_id'] ?? '—') ?></code> · <?= reflection_h($job['module'] ?? '—') ?></strong>
                                    <small><?= reflection_h(reflection_short_value($job['source'] ?? '—', 70)) ?></small>
                                    <?php if ($jobDisplay['detail'] !== ''): ?><small><?= reflection_h(reflection_short_value($jobDisplay['detail'], 70)) ?></small><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($activeCount > $activeJobsShownLimit): ?>
                        <p class="api-note text-secondary small active-work-overflow mt-2">Showing the first <?= (int) $activeJobsShownLimit ?> active jobs here. Use <a href="<?= reflection_h(reflection_url_with(['job_status' => 'active', 'job_page' => 1])) ?>">View active</a> for the full table.</p>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </aside>
    </header>

    <?php if ($message !== null): ?>
        <div class="alert success text-bg-success"><?= reflection_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert error alert-danger text-bg-danger"><?= reflection_h($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($config['storage_warning'])): ?>
        <div class="alert warning alert-warning text-bg-warning"><?= reflection_h($config['storage_warning']) ?></div>
    <?php endif; ?>
    <?php if ($essSocIgnored): ?>
        <div class="alert warning alert-warning text-bg-warning">ESS SOC <?= reflection_h(reflection_ess_status_label($settings)) ?>. SOC-based worker limits are being ignored until the endpoint returns a valid SOC value again. <?= reflection_h($settings['ess_soc_error'] ?? '') ?></div>
    <?php elseif ($essChargingOverrideActive): ?>
        <div class="alert muted alert-secondary text-bg-secondary">ESS reports charging. Minimum SOC limits are being bypassed while the charging override option is enabled.</div>
    <?php endif; ?>
    <?php if ($staleCount > 0): ?>
        <div class="alert warning alert-warning text-bg-warning"><?= reflection_h($staleCount) ?> lost/blocked job(s) were marked for operator review.</div>
    <?php endif; ?>
    <?php if ($maintenanceChanged): ?>
        <div class="alert muted alert-secondary text-bg-secondary">Automatic maintenance archived <?= (int) $automaticMaintenance['archived_jobs'] ?> old job(s), trimmed <?= (int) $automaticMaintenance['trimmed_events'] ?> event(s), compacted <?= (int) $automaticMaintenance['trimmed_file_history'] ?> file-history item(s), and trimmed <?= (int) $automaticMaintenance['trimmed_job_archive'] ?> archived job line(s).</div>
    <?php endif; ?>

    <section class="overview-grid row row-cols-1 row-cols-sm-2 row-cols-xl-5 g-3 mb-4" id="metrics-section">
        <article class="metric col bg-white border rounded-4 shadow-sm p-3 primary border-primary bg-primary-subtle">
            <span>Active jobs</span>
            <strong><?= $activeCount ?></strong>
            <small><?= (int) ($statusCounts['queued'] ?? 0) ?> queued · <?= (int) ($statusCounts['running'] ?? 0) ?> running · <?= (int) ($statusCounts['held'] ?? 0) ?> held</small>
        </article>
        <article class="metric <?= $essSocIgnored ? 'warning-metric' : ($essChargingOverrideActive ? 'charging-metric' : '') ?>">
            <span>ESS SOC</span>
            <?php if ($essSocIgnored): ?>
                <strong>ignored</strong>
                <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · last good <?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</small>
            <?php else: ?>
                <strong><?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</strong>
                <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · charging <?= reflection_h(reflection_ess_charging_label($settings)) ?><?= $essChargingOverrideActive ? ' · SOC limits bypassed' : ' · minimum ' . (int) ($settings['ess_min_soc_percent'] ?? 20) . '%' ?></small>
            <?php endif; ?>
        </article>
        <article class="metric <?= $essSocIgnored ? 'warning-metric' : '' ?>">
            <span>SOC worker allowance</span>
            <strong><?= reflection_h($workerLimitDisplay) ?></strong>
            <small><?= reflection_h($workerLimitHelp) ?> · <?= (int) $wakeTargetCount ?>/<?= (int) $wakeEnabledMachineCount ?> eligible offline WOL</small>
        </article>
        <article class="metric col bg-white border rounded-4 shadow-sm p-3">
            <span>Completed kept</span>
            <strong><?= $completedInStore ?></strong>
            <small><?= (int) $archiveInfo['jobs'] ?> archived · <?= reflection_h(reflection_format_bytes((int) $archiveInfo['size_bytes'])) ?></small>
        </article>
        <article class="metric col bg-white border rounded-4 shadow-sm p-3">
            <span>Workers</span>
            <strong><?= count($workerCards) ?></strong>
            <small><?= (int) ($workerStateCounts['running'] ?? 0) ?> running · <?= (int) ($workerStateCounts['idle'] ?? 0) ?> idle</small>
        </article>
    </section>

    <main class="dashboard-grid row g-4 dashboard-status-grid align-items-start">
        <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 workers-panel dashboard-workers-panel col-12 col-xl-8">
            <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Cluster</p>
                    <h2>Farm computers</h2>
                </div>
                <div class="worker-summary d-flex flex-wrap gap-2" id="worker-summary">
                    <?= reflection_render_worker_summary($workerStateCounts) ?>
                </div>
            </div>
            <div class="computer-grid row g-3" id="workers-grid">
                <?= reflection_render_worker_cards_html($workerCards) ?>
            </div>
        </section>

        <aside class="side-stack col-12 col-xl-4 d-grid gap-3">
            <?= reflection_render_power_panel_html($powerPanelContext) ?>
            <?= reflection_render_storage_history_panel_html($settings, $archiveInfo) ?>
        </aside>
    </main>

    <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 queue-panel create-jobs-panel">
        <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Queue</p>
                <h2>Create jobs</h2>
            </div>
            <span class="soft-label badge text-bg-light border text-secondary">Single or bulk</span>
        </div>
        <form method="post" enctype="multipart/form-data" id="job-form">
            <label>
                Submit mode
                <select class="form-select" name="form_action" id="submit-mode">
                    <option value="single">Single job</option>
                    <option value="bulk">Bulk import</option>
                </select>
                <small>Bulk accepts pasted paths, a JSON array, or a file generated by <code>cluster/tools/reflection-file-list.sh</code>.</small>
            </label>
            <label>
                Task
                <select class="form-select" name="module" id="task-module" required>
                    <?php foreach ($config['allowed_tasks'] as $taskName => $description): ?>
                        <?php $selectDescription = reflection_task_select_description((string) $taskName, (string) $description, $taskSpecs); ?>
                        <option value="<?= reflection_h($taskName) ?>"><?= reflection_h($taskName) ?> — <?= reflection_h($selectDescription) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div id="task-contract-data" data-task-specs="<?= reflection_h(json_encode($taskSpecs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"></div>
            <section class="task-contract" id="task-contract" aria-live="polite">
                <strong id="task-contract-title">Task contract</strong>
                <small id="task-contract-summary">The selected task declares its required source, delivery behavior, and output format.</small>
            </section>
            <label id="storage-server-field">
                Storage server
                <select class="form-select" name="transfer_server_id">
                    <option value="">Use first available/default server</option>
                    <?php foreach ($storageServers as $server): ?>
                        <option value="<?= reflection_h($server['id'] ?? '') ?>"><?= reflection_h(reflection_storage_server_label($server)) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Choose which FTP/SFTP server plain source/delivery paths belong to. Worker usernames/passwords stay on each worker. <a href="storage_servers.php">Add or edit storage servers</a>.</small>
            </label>
            <div class="mode-fields d-grid gap-3 mode-single">
                <label id="single-source-field">
                    <span id="single-source-label">Source path or URI</span>
                    <input name="single_source" id="single-source-input" placeholder="ftp://farm.local/incoming/source.dat" class="form-control">
                    <small id="single-source-help">Use an FTP URL or any path the worker can read. Control tasks can leave this blank.</small>
                </label>
                <label id="single-delivery-field">
                    <span id="single-delivery-label">Delivery path or URI</span>
                    <input name="single_delivery" id="single-delivery-input" placeholder="ftp://farm.local/outputs/result.txt" class="form-control">
                    <small id="single-delivery-help">Optional. The master passes this value through; workers do the writing.</small>
                </label>
                <p class="api-note text-secondary small" id="single-delivery-preview"></p>
            </div>
            <div class="mode-fields d-grid gap-3 mode-bulk" hidden>
                <label id="bulk-source-field">
                    <span id="bulk-source-label">Source list</span>
                    <textarea class="form-control" name="source_list" id="bulk-source-input" rows="8" placeholder="ftp://farm.local/incoming/img001.png&#10;ftp://farm.local/incoming/img002.png"></textarea>
                    <small id="bulk-source-help">One source per line, or paste a JSON array.</small>
                </label>
                <label id="bulk-upload-field">
                    Upload list file
                    <input type="file" name="source_file" accept=".txt,.list,.json,text/plain,application/json" class="form-control">
                </label>
                <label id="bulk-delivery-field">
                    <span id="bulk-delivery-label">Delivery template</span>
                    <input name="bulk_delivery" id="bulk-delivery-input" placeholder="ftp://farm.local/outputs/{name}.out" class="form-control">
                    <small id="bulk-delivery-help">Supports <code>{source}</code>, <code>{dir}</code>, <code>{basename}</code>, <code>{name}</code>, <code>{ext}</code>, and <code>{dot_ext}</code>.</small>
                </label>
            </div>
            <label class="check-row form-check d-flex gap-2 align-items-center">
                <input type="checkbox" name="overwrite_allowed" value="1">
                Allow worker to overwrite existing delivery output
            </label>
            <button class="btn btn-primary" type="submit" id="submit-button">Queue job</button>
        </form>
    </section>

    <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 jobs-panel">
        <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3 wrap-head">
            <div>
                <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Queue store</p>
                <h2>Jobs</h2>
                <p class="api-note text-secondary small" id="jobs-summary">Showing <?= count($jobs) ?> of <?= (int) $jobPageData['total'] ?> job(s). Choose a filter or press Apply filters; the table updates without a full page reload. Queued jobs can be moved earlier or later in the worker pick-up order.</p>
            </div>
            <form method="get" class="toolbar d-flex flex-wrap gap-2 align-items-end mb-3" id="job-filter-form">
                <label>
                    Status
                    <select class="form-select" name="job_status" id="job-status-select">
                        <?php foreach ($validJobFilters as $filter): ?>
                            <option value="<?= reflection_h($filter) ?>" <?= $jobStatus === $filter ? 'selected' : '' ?>><?= reflection_h($filter) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Per page
                    <select class="form-select" name="job_per_page">
                        <?php foreach ([25, 50, 100, 200] as $size): ?>
                            <option value="<?= $size ?>" <?= (int) $jobPageData['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="secondary-button btn btn-secondary">Apply filters</button>
            </form>
        </div>
        <nav class="status-tabs nav nav-pills flex-wrap gap-2 mb-3" aria-label="Job status filters" data-job-status-tabs id="job-status-tabs">
            <?php foreach (['all', 'active', 'queued', 'running', 'held', 'success', 'skipped', 'failed', 'stale', 'blocked', 'ignored', 'finished'] as $filter): ?>
                <?php
                    if ($filter === 'all') {
                        $tabCount = array_sum($statusCounts);
                    } elseif ($filter === 'active') {
                        $tabCount = $activeCount;
                    } elseif ($filter === 'finished') {
                        $tabCount = $completedInStore;
                    } else {
                        $tabCount = (int) ($statusCounts[$filter] ?? 0);
                    }
                ?>
                <button type="button" class="nav-link <?= $jobStatus === $filter ? 'active' : '' ?>" data-job-status-filter="<?= reflection_h($filter) ?>"><?= reflection_h($filter) ?> <span><?= $tabCount ?></span></button>
            <?php endforeach; ?>
        </nav>
        <div class="table-wrap table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Worker</th>
                        <th>Source → Delivery</th>
                        <th>Timing</th>
                        <th>Error</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="jobs-tbody">
                <?php if ($jobs === []): ?>
                    <tr><td colspan="8" class="empty text-secondary text-center py-4">No jobs match this filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <?php $jobStatusValue = (string) ($job['status'] ?? 'unknown'); $jobDisplay = reflection_job_display_status($job); ?>
                    <tr>
                        <td><code><?= reflection_h($job['task_id'] ?? '—') ?></code></td>
                        <td><?= reflection_h($job['module'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= reflection_h(reflection_status_class($jobDisplay['class'])) ?>"><?= reflection_h($jobDisplay['label']) ?></span>
                            <?php if ($jobDisplay['detail'] !== ''): ?><small class="job-stage-note text-secondary small d-block"><?= reflection_h(reflection_short_value($jobDisplay['detail'], 120)) ?></small><?php endif; ?>
                        </td>
                        <td><?= reflection_h($job['worker'] ?? '—') ?></td>
                        <td class="path-cell text-break">
                <div><span class="subtle-label text-secondary text-uppercase fw-bold small">Source</span> <code title="<?= reflection_h($job['source'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['source'] ?? '—')) ?></code></div>
                <div><span class="subtle-label text-secondary text-uppercase fw-bold small">Delivery<?= !empty($job['delivery_auto_generated']) ? ' auto' : '' ?></span> <code title="<?= reflection_h($job['delivery'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['delivery'] ?? '—')) ?></code></div>
                <?php if (!empty($job['task_contract'])): ?>
                    <small><?= reflection_h($job['task_contract']) ?></small>
                <?php endif; ?>
            </td>
                        <td>
                            <span title="<?= reflection_h($job['created_at'] ?? '') ?>">Created <?= reflection_h(reflection_relative_time($job['created_at'] ?? null)) ?></span><br>
                            <span title="<?= reflection_h($job['started_at'] ?? '') ?>">Started <?= reflection_h(reflection_relative_time($job['started_at'] ?? null)) ?></span><br>
                            <span title="<?= reflection_h($job['finished_at'] ?? '') ?>">Finished <?= reflection_h(reflection_relative_time($job['finished_at'] ?? null)) ?></span>
                        </td>
                        <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 140)) ?></td>
                        <td>
                            <div class="button-row d-flex flex-wrap gap-2 table-actions align-items-center">
                                <?php if ($jobStatusValue === 'queued'): ?>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="move_earlier">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Sooner</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="move_later">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Later</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($jobStatusValue, ['queued', 'running'], true)): ?>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="hold">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Hold</button>
                                    </form>
                                <?php elseif ($jobStatusValue === 'held'): ?>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="release">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Release</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($jobStatusValue, ['failed', 'stale', 'blocked'], true)): ?>
                                    <form method="post" data-confirm="Queue a fresh retry of this job?">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="retry">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Retry</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($jobStatusValue !== 'running'): ?>
                                    <form method="post" data-confirm="Move this job to the bin?">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="delete">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="danger-button btn btn-outline-danger small-button btn-sm" type="submit">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="api-note text-secondary small">Hold a running job before deleting it.</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination d-flex flex-wrap justify-content-center align-items-center gap-2 my-3" id="jobs-pagination">
            <a class="<?= (int) $jobPageData['page'] <= 1 ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_url_with(['job_page' => max(1, (int) $jobPageData['page'] - 1)])) ?>">Previous</a>
            <span>Page <?= (int) $jobPageData['page'] ?> of <?= (int) $jobPageData['pages'] ?></span>
            <a class="<?= (int) $jobPageData['page'] >= (int) $jobPageData['pages'] ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_url_with(['job_page' => min((int) $jobPageData['pages'], (int) $jobPageData['page'] + 1)])) ?>">Next</a>
        </div>
    </section>

    <section class="two-column row g-4">
        <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4">
            <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Log</p>
                    <h2>Recent events</h2>
                </div>
                <a class="ghost-button btn btn-outline-primary small-button btn-sm" href="logs.php?log=events">View all logs</a>
            </div>
            <div class="table-wrap table-responsive compact-table">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Job</th>
                            <th>Worker</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody id="events-tbody">
                    <?php if ($events === []): ?>
                        <tr><td colspan="5" class="empty text-secondary text-center py-4">No log entries yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td title="<?= reflection_h($event['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($event['timestamp'] ?? null)) ?></td>
                            <td><?= reflection_h($event['event'] ?? '—') ?></td>
                            <td><code><?= reflection_h($event['task_id'] ?? '—') ?></code></td>
                            <td><?= reflection_h($event['worker'] ?? '—') ?></td>
                            <td><?= reflection_h(reflection_short_value($event['error'] ?? '', 90)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4">
            <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Files</p>
                    <h2>Recent paths / URIs</h2>
                </div>
                <a class="ghost-button btn btn-outline-primary small-button btn-sm" href="logs.php?log=files">View all logs</a>
            </div>
            <div class="table-wrap table-responsive compact-table">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Path or URI</th>
                            <th>Last touched</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="files-tbody">
                    <?php if ($fileHistory === []): ?>
                        <tr><td colspan="3" class="empty text-secondary text-center py-4">No file or URI history yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($fileHistory as $path => $touches): ?>
                        <?php $recentTouches = array_slice(array_reverse($touches), 0, 3); ?>
                        <tr>
                            <td class="path-cell text-break"><code title="<?= reflection_h($path) ?>"><?= reflection_h(reflection_short_value($path, 80)) ?></code></td>
                            <td title="<?= reflection_h($recentTouches[0]['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($recentTouches[0]['timestamp'] ?? null)) ?></td>
                            <td>
                                <?php foreach ($recentTouches as $touch): ?>
                                    <div><strong><?= reflection_h($touch['action'] ?? '—') ?></strong> · <code><?= reflection_h($touch['task_id'] ?? '—') ?></code></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>


    <footer>
        <p>Protect this dashboard with your web server, VPN, or reverse-proxy auth.</p>
        <?php if ($gitCommit): ?>
            <p style="margin: 0; font-size: 0.85rem; opacity: 0.6;"><code><?= reflection_h($gitCommit) ?></code></p>
        <?php endif; ?>
    </footer>

    <script src="<?= reflection_h(reflection_asset_url('common.js')) ?>"></script>
    <script src="<?= reflection_h(reflection_asset_url('dashboard.js')) ?>"></script>
<?= reflection_script_links() ?></body>
</html>
