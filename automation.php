<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/AutomationStore.php';

$config = reflection_master_config();
$farmStore = reflection_farm_store($config);
$automationStore = new AutomationStore(dirname((string) $config['storage_path']));
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
        'source_template' => (string) ($_POST['source_template'] ?? '{path}'),
        'delivery_template' => (string) ($_POST['delivery_template'] ?? ''),
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

$selectedId = (string) ($_GET['edit'] ?? '');
$editingRule = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['automation_action'] ?? '');

        if ($action === 'save_rule') {
            $saved = $automationStore->saveRule(reflection_rule_from_post($automationStore), $config['allowed_tasks']);
            $selectedId = (string) ($saved['id'] ?? '');
            $editingRule = $saved;
            $message = 'Automation rule saved.';
        } elseif ($action === 'test_filter') {
            $editingRule = reflection_rule_from_post($automationStore);
            $errors = $automationStore->validateRule($editingRule, $config['allowed_tasks']);
            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
            $testResult = $automationStore->testRule($editingRule, (string) ($_POST['sample_paths'] ?? ''), 80);
            $message = 'Filter test complete.';
        } elseif ($action === 'dry_run_rule') {
            $editingRule = reflection_rule_from_post($automationStore);
            $errors = $automationStore->validateRule($editingRule, $config['allowed_tasks']);
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
        } elseif ($action === 'run_due') {
            $results = $automationStore->runDueRules($farmStore, false);
            $queued = 0;
            foreach ($results as $result) {
                $queued += (int) ($result['queued'] ?? 0);
            }
            $message = count($results) . ' due automation rule(s) scanned; ' . $queued . ' job(s) queued.';
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
<body>
    <header class="hero compact-hero">
        <div class="hero-main">
            <p class="eyebrow">Reflection farm master</p>
            <h1>Automation</h1>
            <p class="lede">Create universal scan rules that turn matching files into queued worker jobs. Rules can target any task and any filesystem location the master can read.</p>
            <div class="hero-pills">
                <span>Rules <code><?= count($rules) ?></code></span>
                <span>Tick endpoint <code><?= reflection_h($tickPath) ?></code></span>
            </div>
            <nav class="top-nav">
                <a href="index.php">Dashboard</a>
                <a class="active" href="automation.php">Automation</a>
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

            <form method="post" class="automation-form">
                <input type="hidden" name="rule_id" value="<?= reflection_h($editingRule['id'] ?? '') ?>">
                <input type="hidden" name="last_scan_at" value="<?= reflection_h($editingRule['last_scan_at'] ?? '') ?>">
                <input type="hidden" name="created_at" value="<?= reflection_h($editingRule['created_at'] ?? '') ?>">
                <input type="hidden" name="updated_at" value="<?= reflection_h($editingRule['updated_at'] ?? '') ?>">

                <section class="form-block">
                    <h3>Identity and task</h3>
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
                        <label class="check-row top-check">
                            <input type="checkbox" name="enabled" value="1" <?= !empty($editingRule['enabled']) ? 'checked' : '' ?>>
                            Enabled for scheduled runs
                        </label>
                    </div>
                </section>

                <section class="form-block">
                    <h3>Locations</h3>
                    <label>
                        Scan roots
                        <textarea name="scan_roots" rows="4" placeholder="/volume1/video/Movies"><?= reflection_h(implode(PHP_EOL, $editingRule['scan_roots'] ?? [])) ?></textarea>
                        <small>One path per line. These are paths the master website can read.</small>
                    </label>
                    <div class="settings-grid">
                        <label class="check-row top-check">
                            <input type="checkbox" name="recursive" value="1" <?= !empty($editingRule['recursive']) ? 'checked' : '' ?>>
                            Scan subfolders
                        </label>
                        <label>
                            File extensions
                            <input name="extensions" value="<?= reflection_h($editingRule['extensions'] ?? '') ?>" placeholder="mkv, mp4, avi">
                            <small>Blank means any file extension.</small>
                        </label>
                    </div>
                </section>

                <section class="form-block">
                    <h3>Job templates</h3>
                    <div class="settings-grid">
                        <label>
                            Source template
                            <input name="source_template" value="<?= reflection_h($editingRule['source_template'] ?? '{path}') ?>">
                        </label>
                        <label>
                            Delivery template
                            <input name="delivery_template" value="<?= reflection_h($editingRule['delivery_template'] ?? '') ?>" placeholder="/output/{relative}">
                        </label>
                    </div>
                    <p class="api-note">Available placeholders: <code>{path}</code>, <code>{root}</code>, <code>{relative}</code>, <code>{dir}</code>, <code>{basename}</code>, <code>{name}</code>, <code>{ext}</code>, <code>{size}</code>, <code>{mtime}</code>.</p>
                    <div class="settings-grid">
                        <label class="check-row top-check warning-check">
                            <input type="checkbox" name="overwrite_allowed" value="1" <?= !empty($editingRule['overwrite_allowed']) ? 'checked' : '' ?>>
                            Allow worker overwrite/replacement
                        </label>
                        <label class="check-row top-check">
                            <input type="checkbox" name="requeue_unchanged" value="1" <?= !empty($editingRule['requeue_unchanged']) ? 'checked' : '' ?>>
                            Queue unchanged files again
                        </label>
                    </div>
                </section>

                <section class="form-block">
                    <h3>Filter</h3>
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
                        </label>
                        <label>
                            Maximum size MB
                            <input name="max_size_mb" value="<?= reflection_h($editingRule['max_size_mb'] ?? '') ?>" inputmode="decimal">
                        </label>
                        <label>
                            Require unchanged seconds
                            <input name="require_unchanged_seconds" value="<?= (int) ($editingRule['require_unchanged_seconds'] ?? 0) ?>" inputmode="numeric">
                            <small>Helps avoid files still being copied.</small>
                        </label>
                    </div>
                </section>

                <section class="form-block">
                    <h3>Optional command filter</h3>
                    <p class="api-note">Use this for task-specific checks while keeping the automation system universal. Example: ffprobe can exclude movies that are already HEVC.</p>
                    <div class="settings-grid">
                        <label>
                            Command mode
                            <select name="command_filter_mode">
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
                        <input name="command_filter_command" value="<?= reflection_h($editingRule['command_filter_command'] ?? '') ?>" placeholder="ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 {path}">
                        <small>Placeholders are shell-escaped before being inserted.</small>
                    </label>
                    <label>
                        Command output regex
                        <input name="command_filter_regex" value="<?= reflection_h($editingRule['command_filter_regex'] ?? '') ?>" placeholder="/^hevc$/i">
                    </label>
                    <details class="example-box">
                        <summary>H.265 example filter</summary>
                        <p>For a movie conversion rule, set task <code>h265_encode</code>, extensions <code>mkv, mp4, avi, mov, m4v</code>, delivery template <code>{path}</code>, enable overwrite, command mode <code>Include if output does not match regex</code>, command:</p>
                        <pre>ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 {path}</pre>
                        <p>and regex:</p>
                        <pre>/^hevc$/i</pre>
                    </details>
                </section>

                <section class="form-block">
                    <h3>Limits and schedule</h3>
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
                        </label>
                        <label>
                            State entries kept
                            <input name="state_keep_paths" value="<?= (int) ($editingRule['state_keep_paths'] ?? 10000) ?>" inputmode="numeric">
                        </label>
                    </div>
                </section>

                <section class="form-block">
                    <h3>Test filter</h3>
                    <label>
                        Optional sample paths
                        <textarea name="sample_paths" rows="5" placeholder="Leave blank to test by scanning the configured roots. Or paste a few paths here, one per line."><?= reflection_h((string) ($_POST['sample_paths'] ?? '')) ?></textarea>
                    </label>
                </section>

                <div class="button-row sticky-actions">
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

    <footer>
        <p>Automation state lives in <code>data/automation_rules.json</code>, <code>data/automation_state.json</code>, and <code>data/automation_runs.jsonl</code>.</p>
        <p><a href="index.php">Back to dashboard</a></p>
    </footer>
</body>
</html>
