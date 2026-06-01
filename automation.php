<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/StorageStore.php';

$config = reflection_master_config();
$farmStore = reflection_farm_store($config);
$dataDirectory = dirname((string) $config['storage_path']);
$automationStore = new AutomationStore($dataDirectory);
$storageStore = new StorageStore($dataDirectory, $config['transfer_server'] ?? null);
$message = null;
$error = null;
$testResult = null;
$runResult = null;

function reflection_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reflection_post_bool(string $key): bool
{
    return isset($_POST[$key]) && in_array((string) $_POST[$key], ['1', 'true', 'yes', 'on'], true);
}

function reflection_rule_from_post(AutomationStore $automationStore): array
{
    return $automationStore->normalizeRule([
        'id' => (string) ($_POST['rule_id'] ?? ''),
        'name' => (string) ($_POST['name'] ?? ''),
        'enabled' => reflection_post_bool('enabled'),
        'module' => (string) ($_POST['module'] ?? ''),
        'scan_roots' => (string) ($_POST['scan_roots'] ?? ''),
        'recursive' => reflection_post_bool('recursive'),
        'worker_path_mappings' => (string) ($_POST['worker_path_mappings'] ?? ''),
        'transfer_server_id' => (string) ($_POST['transfer_server_id'] ?? ''),
        'source_template' => (string) ($_POST['source_template'] ?? '{path}'),
        'delivery_mode' => (string) ($_POST['delivery_mode'] ?? 'template'),
        'delivery_template' => (string) ($_POST['delivery_template'] ?? ''),
        'output_suffix' => (string) ($_POST['output_suffix'] ?? '_processed'),
        'overwrite_allowed' => reflection_post_bool('overwrite_allowed'),
        'extensions' => (string) ($_POST['extensions'] ?? ''),
        'include_globs' => (string) ($_POST['include_globs'] ?? ''),
        'exclude_globs' => (string) ($_POST['exclude_globs'] ?? ''),
        'include_regex' => (string) ($_POST['include_regex'] ?? ''),
        'exclude_regex' => (string) ($_POST['exclude_regex'] ?? ''),
        'min_size_mb' => (string) ($_POST['min_size_mb'] ?? ''),
        'max_size_mb' => (string) ($_POST['max_size_mb'] ?? ''),
        'require_unchanged_seconds' => (string) ($_POST['require_unchanged_seconds'] ?? '0'),
        'command_filter_mode' => (string) ($_POST['command_filter_mode'] ?? 'disabled'),
        'command_filter_command' => (string) ($_POST['command_filter_command'] ?? ''),
        'command_filter_regex' => (string) ($_POST['command_filter_regex'] ?? ''),
        'command_timeout_seconds' => (string) ($_POST['command_timeout_seconds'] ?? '20'),
        'max_files_per_scan' => (string) ($_POST['max_files_per_scan'] ?? '500'),
        'max_jobs_per_scan' => (string) ($_POST['max_jobs_per_scan'] ?? '25'),
        'scan_interval_minutes' => (string) ($_POST['scan_interval_minutes'] ?? '60'),
        'requeue_unchanged' => reflection_post_bool('requeue_unchanged'),
        'state_keep_paths' => (string) ($_POST['state_keep_paths'] ?? '10000'),
        'last_scan_at' => (string) ($_POST['last_scan_at'] ?? ''),
        'created_at' => (string) ($_POST['created_at'] ?? ''),
        'updated_at' => (string) ($_POST['updated_at'] ?? ''),
    ]);
}

function reflection_short_auto_value($value, int $limit = 90): string
{
    $value = (string) ($value ?? '');
    if ($value === '') {
        return '—';
    }
    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }
    if (strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }
    return $value;
}

function reflection_auto_summary(array $summary): string
{
    return sprintf(
        '%d scanned · %d matched · %d queued · %d skipped · %d error(s)',
        (int) ($summary['scanned'] ?? 0),
        (int) ($summary['matched'] ?? 0),
        (int) ($summary['queued'] ?? 0),
        (int) ($summary['skipped'] ?? 0),
        (int) ($summary['errors'] ?? 0)
    );
}


function reflection_auto_append(?string $message, string $addition): string
{
    $addition = trim($addition);
    if ($addition === '') {
        return (string) ($message ?? '');
    }
    $current = trim((string) ($message ?? ''));
    return $current === '' ? $addition : $current . ' ' . $addition;
}

function reflection_automation_wake_notice(FarmStore $store, int $staleAfterSeconds, string $reason): ?string
{
    $store->refreshEssSocFromConfiguredEndpoint();
    $plan = $store->autoWakeForQueuedJobs($staleAfterSeconds, $reason);
    if (empty($plan['enabled'])) {
        return null;
    }
    $sent = (int) ($plan['wake_result']['sent'] ?? 0);
    if ($sent > 0) {
        return 'Demand wake sent to ' . $sent . ' computer' . ($sent === 1 ? '' : 's') . '.';
    }
    $needed = (int) ($plan['needed'] ?? 0);
    if ($needed > 0 && (int) ($plan['ready_targets'] ?? 0) === 0) {
        return 'Demand wake wanted ' . $needed . ' more worker' . ($needed === 1 ? '' : 's') . ', but no eligible target is ready right now.';
    }
    return null;
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

function reflection_extension_presets(): array
{
    return [
        'Video' => 'mkv, mp4, m4v, mov, avi, wmv, webm, mpg, mpeg, ts, m2ts, flv',
        'Images' => 'jpg, jpeg, png, webp, gif, bmp, tif, tiff, heic, heif, avif, raw, cr2, nef, arw, dng',
        'Audio' => 'mp3, flac, wav, aac, m4a, ogg, opus, wma, aiff, alac',
        'Documents' => 'pdf, doc, docx, xls, xlsx, ppt, pptx, odt, ods, odp, rtf, txt, md',
        'Archives' => 'zip, 7z, rar, tar, gz, bz2, xz, tgz, tbz2, txz',
        'Subtitles' => 'srt, ass, ssa, sub, vtt',
        'Code/text' => 'txt, md, json, xml, yaml, yml, csv, log, py, php, js, ts, html, css, sh, ps1',
    ];
}

$selectedId = (string) ($_GET['edit'] ?? '');
$editingRule = null;
$storageServers = $storageStore->enabledServers(true);
$storageServerIds = array_map(static function (array $server): string {
    return (string) ($server['id'] ?? '');
}, $storageServers);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['automation_action'] ?? '');

        if ($action === 'save_rule') {
            $saved = $automationStore->saveRule(reflection_rule_from_post($automationStore), $config['allowed_tasks'], $storageServerIds);
            $selectedId = (string) ($saved['id'] ?? '');
            $editingRule = $saved;
            $message = 'Automation rule saved.';
        } elseif ($action === 'test_filter') {
            $editingRule = reflection_rule_from_post($automationStore);
            $errors = $automationStore->validateRule($editingRule, $config['allowed_tasks'], $storageServerIds);
            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
            $testResult = $automationStore->testRule($editingRule, (string) ($_POST['sample_paths'] ?? ''), 80);
            $message = 'Filter test complete.';
        } elseif ($action === 'dry_run_rule') {
            $editingRule = reflection_rule_from_post($automationStore);
            $errors = $automationStore->validateRule($editingRule, $config['allowed_tasks'], $storageServerIds);
            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
            $runResult = $automationStore->runRule($editingRule, $farmStore, true);
            $message = 'Dry run complete. No jobs were queued.';
        } elseif ($action === 'run_rule') {
            $id = (string) ($_POST['rule_id'] ?? '');
            $rule = $automationStore->rule($id);
            if ($rule === null) {
                throw new RuntimeException('Automation rule was not found. Save it before running it for real.');
            }
            $runResult = $automationStore->runRule($rule, $farmStore, false);
            $selectedId = $id;
            $editingRule = $automationStore->rule($id);
            $message = 'Automation scan finished. ' . reflection_auto_summary($runResult);
            if ((int) ($runResult['queued'] ?? 0) > 0) {
                $notice = reflection_automation_wake_notice($farmStore, (int) ($config['stale_after_seconds'] ?? 900), 'automation_rule');
                if ($notice !== null) {
                    $message = reflection_auto_append($message, $notice);
                }
            }
        } elseif ($action === 'run_due') {
            $results = $automationStore->runDueRules($farmStore, false);
            $queued = 0;
            foreach ($results as $result) {
                $queued += (int) ($result['queued'] ?? 0);
            }
            $message = count($results) . ' due automation rule(s) scanned; ' . $queued . ' job(s) queued.';
            if ($queued > 0) {
                $notice = reflection_automation_wake_notice($farmStore, (int) ($config['stale_after_seconds'] ?? 900), 'automation_due');
                if ($notice !== null) {
                    $message = reflection_auto_append($message, $notice);
                }
            }
        } elseif ($action === 'delete_rule') {
            $id = (string) ($_POST['rule_id'] ?? '');
            if ($automationStore->deleteRule($id)) {
                $message = 'Automation rule deleted.';
            } else {
                $message = 'Automation rule was already gone.';
            }
            $selectedId = '';
        } elseif ($action === 'toggle_rule') {
            $id = (string) ($_POST['rule_id'] ?? '');
            $enabled = reflection_post_bool('enabled');
            $updated = $automationStore->setEnabled($id, $enabled);
            if ($updated === null) {
                throw new RuntimeException('Automation rule was not found.');
            }
            $message = $enabled ? 'Automation rule enabled.' : 'Automation rule disabled.';
            $selectedId = $id;
            $editingRule = $updated;
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    if ($editingRule === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $editingRule = reflection_rule_from_post($automationStore);
    }
}

$rules = $automationStore->rules();
if ($editingRule === null) {
    if ($selectedId !== '') {
        $editingRule = $automationStore->rule($selectedId);
    }
    if ($editingRule === null) {
        $editingRule = $automationStore->newRule();
    }
}
$recentRuns = $automationStore->recentRuns(12);
$extensionPresets = reflection_extension_presets();
$scriptDirectory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$tickPath = ($scriptDirectory === '' ? '' : $scriptDirectory) . '/automation_tick.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Automation · Reflection Farm Master</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="automation-page">
    <header class="hero compact-hero">
        <div class="hero-main">
            <p class="eyebrow">Reflection farm master</p>
            <h1>Automation</h1>
            <p class="lede">Build scan rules with live previews, validation, and safe job creation for any worker task.</p>
            <div class="hero-pills">
                <span>Rules <code><?= count($rules) ?></code></span>
                <span>Storage servers <code><?= count($storageServers) ?></code></span>
                <span>Tick endpoint <code><?= reflection_h($tickPath) ?></code></span>
            </div>
            <nav class="top-nav">
                <a href="index.php">Dashboard</a>
                <a class="active" href="automation.php">Automation</a>
                <a href="storage_servers.php">Storage servers</a>
                <a href="blocked_jobs.php">Blocked jobs</a>
                <a href="system_checks.php">System checks</a><a href="logs.php">Logs</a><a href="settings.php">Settings</a>
            </nav>
        </div>
        <div class="version-card">
            <span>Automatic scans</span>
            <strong>universal rules</strong>
            <small>Use cron or a scheduled request to run <code><?= reflection_h($tickPath) ?></code>.</small>
        </div>
    </header>

    <?php if ($message !== null): ?>
        <div class="alert success"><?= reflection_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert error"><?= reflection_h($error) ?></div>
    <?php endif; ?>

    <main class="automation-layout">
        <aside class="panel automation-sidebar">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Rules</p>
                    <h2>Automation CRUD</h2>
                </div>
                <a class="ghost-button small-button" href="automation.php?new=1">New rule</a>
            </div>
            <form method="post" class="inline-button-form">
                <input type="hidden" name="automation_action" value="run_due">
                <button type="submit" class="secondary-button">Run due rules now</button>
            </form>
            <div class="rule-list">
                <?php if ($rules === []): ?>
                    <p class="empty">No automation rules yet.</p>
                <?php endif; ?>
                <?php foreach ($rules as $rule): ?>
                    <?php $ruleSelected = ($editingRule['id'] ?? '') !== '' && ($editingRule['id'] ?? '') === ($rule['id'] ?? ''); ?>
                    <article class="rule-card <?= $ruleSelected ? 'selected' : '' ?>">
                        <div>
                            <strong><?= reflection_h($rule['name'] ?? 'Unnamed rule') ?></strong>
                            <small><?= reflection_h($rule['module'] ?? '—') ?> · <?= !empty($rule['enabled']) ? 'enabled' : 'disabled' ?></small>
                            <?php if (!empty($rule['last_scan_summary'])): ?>
                                <small><?= reflection_h(reflection_auto_summary($rule['last_scan_summary'])) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="rule-actions">
                            <a class="text-link" href="automation.php?edit=<?= reflection_h($rule['id'] ?? '') ?>">Edit</a>
                            <form method="post" class="inline-button-form">
                                <input type="hidden" name="automation_action" value="toggle_rule">
                                <input type="hidden" name="rule_id" value="<?= reflection_h($rule['id'] ?? '') ?>">
                                <input type="hidden" name="enabled" value="<?= !empty($rule['enabled']) ? '0' : '1' ?>">
                                <button type="submit" class="mini-button"><?= !empty($rule['enabled']) ? 'Disable' : 'Enable' ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="panel automation-editor">
            <div class="panel-head wrap-head">
                <div>
                    <p class="eyebrow">Rule editor</p>
                    <h2><?= ($editingRule['id'] ?? '') === '' ? 'Create rule' : 'Edit rule' ?></h2>
                    <p class="api-note">A rule scans roots, filters files, applies source/delivery templates, and queues jobs for the selected worker task.</p>
                </div>
                <?php if (($editingRule['id'] ?? '') !== ''): ?>
                    <form method="post" onsubmit="return confirm('Delete this automation rule and its tracked state?');">
                        <input type="hidden" name="automation_action" value="delete_rule">
                        <input type="hidden" name="rule_id" value="<?= reflection_h($editingRule['id'] ?? '') ?>">
                        <button type="submit" class="danger-button">Delete</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="automation-section-toolbar" aria-label="Automation editor section controls">
                <span>Editor sections</span>
                <button type="button" class="mini-button" id="open-all-sections">Open all</button>
                <button type="button" class="mini-button" id="close-all-sections">Minimize all</button>
            </div>

            <form method="post" class="automation-form rf-form" id="automation-rule-form" novalidate>
                <input type="hidden" name="rule_id" value="<?= reflection_h($editingRule['id'] ?? '') ?>">
                <input type="hidden" name="last_scan_at" value="<?= reflection_h($editingRule['last_scan_at'] ?? '') ?>">
                <input type="hidden" name="created_at" value="<?= reflection_h($editingRule['created_at'] ?? '') ?>">
                <input type="hidden" name="updated_at" value="<?= reflection_h($editingRule['updated_at'] ?? '') ?>">

                <details class="form-block collapsible-form-block" data-section-key="identity" open>
                    <summary class="form-section-header"><span class="section-number">1</span><div><h3>Identity and task</h3><p>Name the rule, choose the worker task, and decide whether scheduled scans may run it.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <div class="settings-grid">
                        <label>
                            Rule name
                            <input name="name" required value="<?= reflection_h($editingRule['name'] ?? '') ?>" placeholder="Convert movies to H.265">
                        </label>
                        <label>
                            Worker task
                            <select name="module" required>
                                <?php foreach ($config['allowed_tasks'] as $taskName => $description): ?>
                                    <option value="<?= reflection_h($taskName) ?>" <?= ($editingRule['module'] ?? '') === $taskName ? 'selected' : '' ?>><?= reflection_h($taskName) ?> — <?= reflection_h($description) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Storage server
                            <select name="transfer_server_id">
                                <option value="">Use first available/default server</option>
                                <?php foreach ($storageServers as $server): ?>
                                    <option value="<?= reflection_h($server['id'] ?? '') ?>" <?= ($editingRule['transfer_server_id'] ?? '') === ($server['id'] ?? '') ? 'selected' : '' ?>><?= reflection_h(reflection_storage_server_label($server)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>This chooses which FTP/SFTP server the worker should use for jobs created by this rule. Credentials still live on each worker. <a href="storage_servers.php">Add or edit storage servers</a>.</small>
                        </label>
                        <label class="check-row top-check">
                            <input type="checkbox" name="enabled" value="1" <?= !empty($editingRule['enabled']) ? 'checked' : '' ?>>
                            Enabled for automatic scheduled scans
                            <small>Only enabled rules are run by <code>automation_tick.php</code> or “Run due rules now”. Manual test/dry-run buttons still work while disabled.</small>
                        </label>
                    </div>
                </details>

                <details class="form-block collapsible-form-block" data-section-key="locations" open>
                    <summary class="form-section-header"><span class="section-number">2</span><div><h3>Locations</h3><p>Choose where the master scans and which common file types should be considered.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <label>
                        Scan roots
                        <textarea id="scan-roots-input" name="scan_roots" rows="4" placeholder="/volume1/video/Movies"><?= reflection_h(implode(PHP_EOL, $editingRule['scan_roots'] ?? [])) ?></textarea>
                        <small>One path per line. These are paths the master website can read.</small>
                    </label>
                    <label>
                        Worker path mappings
                        <textarea id="worker-path-mappings-input" name="worker_path_mappings" rows="3" placeholder="/volume1/video => /video&#10;/volume1/movies => /movies"><?= reflection_h($editingRule['worker_path_mappings'] ?? '') ?></textarea>
                        <small>Optional. Translate master/NAS scan paths into the paths workers see through FTP. Format: <code>/master/path =&gt; /worker/path</code>. Longest matching path wins.</small>
                    </label>
                    <div class="settings-grid">
                        <label class="check-row top-check">
                            <input type="checkbox" name="recursive" value="1" <?= !empty($editingRule['recursive']) ? 'checked' : '' ?>>
                            Scan subfolders
                        </label>
                        <label>
                            File extensions
                            <input id="extensions-input" name="extensions" value="<?= reflection_h($editingRule['extensions'] ?? '') ?>" placeholder="mkv, mp4, avi">
                            <small>Blank means any file extension. Use a preset below, then edit it if needed.</small>
                        </label>
                    </div>
                    <div class="preset-row" aria-label="Common file-extension presets">
                        <?php foreach ($extensionPresets as $presetName => $presetExtensions): ?>
                            <button type="button" class="preset-chip" data-extensions="<?= reflection_h($presetExtensions) ?>"><?= reflection_h($presetName) ?></button>
                        <?php endforeach; ?>
                    </div>
                </details>

                <details class="form-block collapsible-form-block" data-section-key="job-templates" open>
                    <summary class="form-section-header"><span class="section-number">3</span><div><h3>Job templates</h3><p>Define exactly what source and delivery paths are sent when jobs are created.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <div class="template-config-grid">
                        <label class="template-field-card">
                            <span class="template-field-title">Source template</span>
                            <input id="source-template-input" class="template-input" data-template-label="Source template" name="source_template" value="<?= reflection_h($editingRule['source_template'] ?? '{path}') ?>">
                            <small>What the worker receives as the source. Usually <code>{worker_path}</code> when workers access files through FTP.</small>
                            <output class="inline-template-preview" id="source-template-preview">—</output>
                            <div class="field-status" id="source-template-status"></div>
                        </label>
                        <label class="template-field-card">
                            <span class="template-field-title">Delivery target</span>
                            <select id="delivery-mode-input" name="delivery_mode">
                                <option value="template" <?= ($editingRule['delivery_mode'] ?? 'template') === 'template' ? 'selected' : '' ?>>Use custom delivery template</option>
                                <option value="same_as_source" <?= ($editingRule['delivery_mode'] ?? 'template') === 'same_as_source' ? 'selected' : '' ?>>Same as source location</option>
                            </select>
                            <small>Same as source means overwrite the original when overwrite is enabled, or create a sibling file with the suffix below when overwrite is disabled.</small>
                        </label>
                        <label class="template-field-card">
                            <span class="template-field-title">Delivery template</span>
                            <input id="delivery-template-input" class="template-input" data-template-label="Delivery template" name="delivery_template" value="<?= reflection_h($editingRule['delivery_template'] ?? '') ?>" placeholder="/output/{relative}">
                            <small>Used only when the delivery target is custom.</small>
                            <output class="inline-template-preview" id="delivery-template-preview">—</output>
                            <div class="field-status" id="delivery-template-status"></div>
                        </label>
                        <label class="template-field-card">
                            <span class="template-field-title">Output suffix when not overwriting</span>
                            <input id="output-suffix-input" name="output_suffix" value="<?= reflection_h($editingRule['output_suffix'] ?? '_processed') ?>" placeholder="_processed">
                            <small>Example: <code>Movie.mkv</code> becomes <code>Movie_processed.mkv</code>.</small>
                            <output class="inline-template-preview" id="suffix-template-preview">—</output>
                        </label>
                    </div>
                    <div class="template-validation-summary template-validation-standalone" id="template-validation-summary">All active templates look valid.</div>
                    <div class="live-template-panel" aria-live="polite">
                        <div class="live-template-head">
                            <div>
                                <strong>Live template preview</strong>
                                <small>Change this example path to see how every template field will be expanded before a real job is queued.</small>
                            </div>
                            <label class="preview-path-label">
                                Example file path
                                <input id="template-sample-path" type="text" value="/volume1/video/Movies/Example Movie (2024)/Example Movie.mkv">
                            </label>
                        </div>
                        <div class="template-preview-grid">
                            <div class="template-preview-card">
                                <span>Detected root</span>
                                <code id="preview-root">—</code>
                            </div>
                            <div class="template-preview-card">
                                <span>Relative path</span>
                                <code id="preview-relative">—</code>
                            </div>
                            <div class="template-preview-card">
                                <span>Worker-visible path</span>
                                <code id="preview-worker-path">—</code>
                            </div>
                            <div class="template-preview-card">
                                <span>Source sent to worker</span>
                                <code id="preview-source">—</code>
                            </div>
                            <div class="template-preview-card">
                                <span>Delivery/result target</span>
                                <code id="preview-delivery">—</code>
                            </div>
                        </div>
                        <details class="placeholder-preview-details" open>
                            <summary>Placeholder values for the example file</summary>
                            <p class="placeholder-preview-note">These are the actual example values used in the live preview above. Change the example path to update every placeholder.</p>
                            <div class="placeholder-chip-row placeholder-chip-row-inside" id="placeholder-chip-row" aria-label="Available template placeholders"></div>
                            <div class="placeholder-preview-grid" id="placeholder-preview-grid"></div>
                        </details>
                    </div>
                    <div class="settings-grid option-box-grid">
                        <label class="check-row top-check option-card warning-check">
                            <input id="overwrite-allowed-input" type="checkbox" name="overwrite_allowed" value="1" <?= !empty($editingRule['overwrite_allowed']) ? 'checked' : '' ?>>
                            Allow worker overwrite/replacement
                            <small>For same-as-source delivery, this means the result replaces the original path after the task succeeds.</small>
                        </label>
                        <label class="check-row top-check option-card">
                            <input type="checkbox" name="requeue_unchanged" value="1" <?= !empty($editingRule['requeue_unchanged']) ? 'checked' : '' ?>>
                            Requeue even if already handled
                            <small>Normally off. Leave off to avoid creating the same job again for a file with the same path, size, and modified time.</small>
                        </label>
                    </div>
                </details>

                <details class="form-block collapsible-form-block" data-section-key="filters">
                    <summary class="form-section-header"><span class="section-number">4</span><div><h3>Filter</h3><p>Use globs, regex, size limits, and age checks to keep rules precise.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <div class="settings-grid">
                        <label>
                            Include globs
                            <textarea name="include_globs" rows="4" placeholder="*.mkv&#10;Movies/*"><?= reflection_h($editingRule['include_globs'] ?? '') ?></textarea>
                        </label>
                        <label>
                            Exclude globs
                            <textarea name="exclude_globs" rows="4" placeholder="@eaDir&#10;#recycle&#10;*.part"><?= reflection_h($editingRule['exclude_globs'] ?? '') ?></textarea>
                        </label>
                    </div>
                    <div class="settings-grid">
                        <label>
                            Include regex
                            <input name="include_regex" value="<?= reflection_h($editingRule['include_regex'] ?? '') ?>" placeholder="/Movies/i">
                        </label>
                        <label>
                            Exclude regex
                            <input name="exclude_regex" value="<?= reflection_h($editingRule['exclude_regex'] ?? '') ?>" placeholder="/(sample|trailer)/i">
                        </label>
                    </div>
                    <div class="settings-grid">
                        <label>
                            Minimum size MB
                            <input name="min_size_mb" value="<?= reflection_h($editingRule['min_size_mb'] ?? '') ?>" inputmode="decimal">
                            <small>Optional. Skip files smaller than this, for example tiny samples or thumbnails.</small>
                        </label>
                        <label>
                            Maximum size MB
                            <input name="max_size_mb" value="<?= reflection_h($editingRule['max_size_mb'] ?? '') ?>" inputmode="decimal">
                            <small>Optional. Skip files larger than this if a task should not touch very large files.</small>
                        </label>
                        <label>
                            Require unchanged seconds
                            <input name="require_unchanged_seconds" value="<?= (int) ($editingRule['require_unchanged_seconds'] ?? 0) ?>" inputmode="numeric">
                            <small>Optional safety delay. A file must not have changed for this many seconds before it can be queued.</small>
                        </label>
                    </div>
                </details>

                <details class="form-block collapsible-form-block" data-section-key="command-filter">
                    <summary class="form-section-header"><span class="section-number">5</span><div><h3>Optional command filter</h3><p>Run a custom check per file, such as ffprobe, before the file is queued.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <p class="api-note">Use this for custom checks while keeping the automation system universal. The command runs per candidate file; the selected mode decides whether its exit code or output includes/skips the file.</p>
                    <div class="settings-grid">
                        <label>
                            Command mode
                            <select id="command-mode-input" name="command_filter_mode">
                                <?php foreach ([
                                    'disabled' => 'Disabled',
                                    'exit_zero' => 'Include if command exits 0',
                                    'output_matches' => 'Include if output matches regex',
                                    'output_not_matches' => 'Include if output does not match regex',
                                ] as $mode => $label): ?>
                                    <option value="<?= reflection_h($mode) ?>" <?= ($editingRule['command_filter_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= reflection_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Timeout seconds
                            <input name="command_timeout_seconds" value="<?= (int) ($editingRule['command_timeout_seconds'] ?? 20) ?>" inputmode="numeric">
                        </label>
                    </div>
                    <label>
                        Command
                        <input id="command-template-input" class="template-input" data-template-label="Command template" name="command_filter_command" value="<?= reflection_h($editingRule['command_filter_command'] ?? '') ?>" placeholder="ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 {path}">
                        <small>Placeholders are shell-escaped before being inserted.</small>
                        <output class="inline-template-preview" id="command-template-preview">—</output>
                        <div class="field-status" id="command-template-status"></div>
                    </label>
                    <label>
                        Command output regex
                        <input name="command_filter_regex" value="<?= reflection_h($editingRule['command_filter_regex'] ?? '') ?>" placeholder="/^hevc$/i">
                    </label>
                    <details class="example-box">
                        <summary>H.265 example filter</summary>
                        <p>For a movie conversion rule, set task <code>h265_encode</code>, choose the Video extension preset, set delivery target to <code>Same as source location</code>, enable overwrite, command mode <code>Include if output does not match regex</code>, command:</p>
                        <pre>ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 {path}</pre>
                        <p>and regex:</p>
                        <pre>/^hevc$/i</pre>
                    </details>
                </details>

                <details class="form-block collapsible-form-block" data-section-key="limits-schedule">
                    <summary class="form-section-header"><span class="section-number">6</span><div><h3>Limits and schedule</h3><p>Control how much work a scan can create and how often scheduled scans are due.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <div class="settings-grid">
                        <label>
                            Max files checked per scan
                            <input name="max_files_per_scan" value="<?= (int) ($editingRule['max_files_per_scan'] ?? 500) ?>" inputmode="numeric">
                        </label>
                        <label>
                            Max jobs created per scan
                            <input name="max_jobs_per_scan" value="<?= (int) ($editingRule['max_jobs_per_scan'] ?? 25) ?>" inputmode="numeric">
                        </label>
                        <label>
                            Scan interval minutes
                            <input name="scan_interval_minutes" value="<?= (int) ($editingRule['scan_interval_minutes'] ?? 60) ?>" inputmode="numeric">
                            <small>Used only by scheduled/due scans. Manual runs ignore this.</small>
                        </label>
                        <label>
                            Handled-file entries kept
                            <input name="state_keep_paths" value="<?= (int) ($editingRule['state_keep_paths'] ?? 10000) ?>" inputmode="numeric">
                            <small>Used for duplicate protection so old handled files do not fill the live state forever.</small>
                        </label>
                    </div>
                </details>

                <details class="form-block collapsible-form-block" data-section-key="test-filter">
                    <summary class="form-section-header"><span class="section-number">7</span><div><h3>Test filter</h3><p>Paste examples or scan the configured roots before creating real jobs.</p></div><span class="section-toggle-text" aria-hidden="true"></span></summary>
                    <label>
                        Optional sample paths
                        <textarea name="sample_paths" rows="5" placeholder="Leave blank to test by scanning the configured roots. Or paste a few paths here, one per line."><?= reflection_h((string) ($_POST['sample_paths'] ?? '')) ?></textarea>
                    </label>
                </details>

                <div class="button-row sticky-actions" id="automation-action-bar">
                    <button type="submit" name="automation_action" value="save_rule">Save rule</button>
                    <button type="submit" name="automation_action" value="test_filter" class="ghost-button">Test filter</button>
                    <button type="submit" name="automation_action" value="dry_run_rule" class="ghost-button">Dry run</button>
                    <button type="submit" name="automation_action" value="run_rule" class="secondary-button" <?= ($editingRule['id'] ?? '') === '' ? 'disabled' : '' ?>>Run and create jobs</button>
                </div>
            </form>
        </section>
    </main>

    <?php if ($testResult !== null): ?>
        <section class="panel result-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Filter test</p>
                    <h2><?= (int) $testResult['matched'] ?> matched of <?= (int) $testResult['scanned'] ?> checked</h2>
                </div>
            </div>
            <div class="table-wrap compact-table">
                <table>
                    <thead><tr><th>Result</th><th>Path</th><th>Reason</th><th>Source</th><th>Delivery</th><th>Command output</th></tr></thead>
                    <tbody>
                        <?php foreach ($testResult['rows'] as $row): ?>
                            <tr>
                                <td><span class="badge <?= !empty($row['include']) ? 'success' : 'failed' ?>"><?= !empty($row['include']) ? 'match' : 'skip' ?></span></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_auto_value($row['path'] ?? '', 130)) ?></code></td>
                                <td><?= reflection_h($row['reason'] ?? '') ?></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_auto_value($row['source'] ?? '', 90)) ?></code></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_auto_value($row['delivery'] ?? '', 90)) ?></code></td>
                                <td><?= reflection_h(reflection_short_auto_value($row['command_output'] ?? '', 120)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($runResult !== null): ?>
        <section class="panel result-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Scan result</p>
                    <h2><?= reflection_h(reflection_auto_summary($runResult)) ?></h2>
                </div>
            </div>
            <div class="table-wrap compact-table">
                <table>
                    <thead><tr><th>Status</th><th>Path</th><th>Reason</th><th>Source</th><th>Delivery</th><th>Job</th></tr></thead>
                    <tbody>
                        <?php foreach (($runResult['rows'] ?? []) as $row): ?>
                            <tr>
                                <td><span class="badge <?= in_array(($row['status'] ?? ''), ['queued', 'would_queue'], true) ? 'success' : (($row['status'] ?? '') === 'error' ? 'failed' : 'configured') ?>"><?= reflection_h($row['status'] ?? '') ?></span></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_auto_value($row['path'] ?? '', 130)) ?></code></td>
                                <td><?= reflection_h($row['reason'] ?? '') ?></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_auto_value($row['source'] ?? '', 90)) ?></code></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_auto_value($row['delivery'] ?? '', 90)) ?></code></td>
                                <td><code><?= reflection_h($row['task_id'] ?? '') ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel result-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">History</p>
                <h2>Recent automation scans</h2>
            </div>
        </div>
        <?php if ($recentRuns === []): ?>
            <p class="empty">No automation scans have run yet.</p>
        <?php else: ?>
            <div class="table-wrap compact-table">
                <table>
                    <thead><tr><th>Finished</th><th>Rule</th><th>Mode</th><th>Summary</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentRuns as $run): ?>
                            <tr>
                                <td><?= reflection_h($run['finished_at'] ?? '—') ?></td>
                                <td><?= reflection_h($run['rule_name'] ?? '—') ?></td>
                                <td><?= !empty($run['dry_run']) ? 'dry run' : 'live' ?></td>
                                <td><?= reflection_h(reflection_auto_summary($run)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <script src="automation.js"></script>

    <footer>
        <p>Automation state lives in <code>data/automation_rules.json</code>, <code>data/automation_state.json</code>, and <code>data/automation_runs.jsonl</code>.</p>
        <p><a href="index.php">Back to dashboard</a></p>
    </footer>
</body>
</html>
