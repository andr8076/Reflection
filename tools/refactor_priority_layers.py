from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text(encoding="utf-8")


def write(path, text):
    (ROOT / path).write_text(text, encoding="utf-8")


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{label}: expected exactly 1 match, found {count}")
    return text.replace(old, new, 1)


def regex_once(text, pattern, replacement, label, flags=re.S):
    new_text, count = re.subn(pattern, replacement, text, count=1, flags=flags)
    if count != 1:
        raise RuntimeError(f"{label}: expected exactly 1 regex match, found {count}")
    return new_text


# ---------------------------------------------------------------------------
# config.php: worker priority is now a core scheduling policy, not a toggle.
# ---------------------------------------------------------------------------
path = "config.php"
text = read(path)
text = replace_once(
    text,
    "        'prefer_lower_shutdown_layers_for_work' => true,\n",
    "",
    "remove old work-priority toggle default",
)
write(path, text)


# ---------------------------------------------------------------------------
# ui_helpers.php: persist the renamed priority_layer field while keeping the
# legacy comma-list layout compatible (column five keeps the same integer).
# ---------------------------------------------------------------------------
path = "ui_helpers.php"
text = read(path)
text = text.replace("'shutdown_layer' => max(0, (int) ($parts[4] ?? 0)),", "'priority_layer' => max(0, (int) ($parts[4] ?? 0)),")
text = text.replace("$shutdownLayers = is_array($post['machine_shutdown_layer'] ?? null) ? $post['machine_shutdown_layer'] : [];", "$priorityLayers = is_array($post['machine_priority_layer'] ?? null) ? $post['machine_priority_layer'] : (is_array($post['machine_shutdown_layer'] ?? null) ? $post['machine_shutdown_layer'] : []);")
text = text.replace("array_keys($shutdownLayers)", "array_keys($priorityLayers)")
text = text.replace("'shutdown_layer' => max(0, (int) ($shutdownLayers[$key] ?? 0)),", "'priority_layer' => max(0, (int) ($priorityLayers[$key] ?? 0)),")
text = text.replace("max(0, (int) ($machine['shutdown_layer'] ?? 0)),", "max(0, (int) ($machine['priority_layer'] ?? ($machine['shutdown_layer'] ?? 0))),")
if "machine_shutdown_layer" not in text:
    # One legacy fallback should remain in reflection_parse_machine_form.
    raise RuntimeError("ui_helpers.php: expected legacy machine_shutdown_layer fallback")
write(path, text)


# ---------------------------------------------------------------------------
# settings.php: expose the new name and make the behavior explicit.
# ---------------------------------------------------------------------------
path = "settings.php"
text = read(path)
text = text.replace("                'prefer_lower_shutdown_layers_for_work' => isset($_POST['prefer_lower_shutdown_layers_for_work']),\n", "")
text = text.replace("['pc_id' => '', 'mac' => '', 'min_soc_percent' => '', 'wake_enabled' => true, 'shutdown_layer' => 0]", "['pc_id' => '', 'mac' => '', 'min_soc_percent' => '', 'wake_enabled' => true, 'priority_layer' => 0]")
text = regex_once(
    text,
    r"\s*<label class=\"check-row form-check d-flex gap-2 align-items-center\">\s*<input type=\"checkbox\" name=\"prefer_lower_shutdown_layers_for_work\".*?</label>",
    "",
    "remove old priority preference checkbox",
)
text = text.replace("<th>Shutdown layer</th>", "<th>Priority layer</th>")
text = text.replace("name=\"machine_shutdown_layer[<?= (int) $machineIndex ?>]\"", "name=\"machine_priority_layer[<?= (int) $machineIndex ?>]\"")
text = text.replace("value=\"<?= (int) ($machine['shutdown_layer'] ?? 0) ?>\"", "value=\"<?= (int) ($machine['priority_layer'] ?? ($machine['shutdown_layer'] ?? 0)) ?>\"")
text = text.replace("name=\"machine_shutdown_layer[__INDEX__]\"", "name=\"machine_priority_layer[__INDEX__]\"")
text = replace_once(
    text,
    "                    <small>Higher shutdown layers power off first. Normal jobs are offered to lower layers first, but no job is reserved for a specific computer or layer. Wake-on-LAN is also phased by layer, so the master wakes the lowest eligible offline layer first before moving upward. Wake can be disabled for a computer while still keeping its SOC and layer policy.</small>",
    "                    <small>Priority layer controls the whole farm order. Lower numbers are core workers: they wake first, receive normal jobs first, and shut down last. Higher numbers are overflow workers: they are used only when lower eligible workers are already busy, and they shut down first. If two jobs need workers across layers 0, 1, and 2, layers 0 and 1 are used while layer 2 stays available to power off. Wake can be disabled for a computer while still keeping its SOC and priority policy.</small>",
    "replace machine priority help",
)
write(path, text)


# ---------------------------------------------------------------------------
# settings.js: serialize the renamed field into the legacy machine-list mirror.
# ---------------------------------------------------------------------------
path = "assets/js/settings.js"
text = read(path)
text = replace_once(
    text,
    'var layer = row.querySelector(\'input[name^="machine_shutdown_layer"]\');',
    'var layer = row.querySelector(\'input[name^="machine_priority_layer"]\');',
    "rename settings JS layer field",
)
write(path, text)


# ---------------------------------------------------------------------------
# FarmStore.php: priority layer becomes the single scheduling/power concept.
# ---------------------------------------------------------------------------
path = "FarmStore.php"
text = read(path)

# Old setting is ignored and removed when settings are next saved.
text = replace_once(
    text,
    "            $data['settings']['prefer_lower_shutdown_layers_for_work'] = !empty($data['settings']['prefer_lower_shutdown_layers_for_work']);\n",
    "            unset($data['settings']['prefer_lower_shutdown_layers_for_work']);\n",
    "drop old work-priority toggle",
)

# New machine writes use priority_layer, with migration fallback for callers that
# still submit shutdown_layer.
text = replace_once(
    text,
    "                    'shutdown_layer' => max(0, (int) ($machine['shutdown_layer'] ?? 0)),",
    "                    'priority_layer' => max(0, (int) ($machine['priority_layer'] ?? ($machine['shutdown_layer'] ?? 0))),",
    "rename persisted machine layer",
)

# Rename the internal accessor everywhere, then make it read both schemas.
text = text.replace("machineShutdownLayerByPcId", "machinePriorityLayerByPcId")
text = regex_once(
    text,
    r"    private function machinePriorityLayerByPcId\(array \$data, string \$pcId\): int\n    \{.*?\n    \}\n\n    private function shutdownLayerStatusFromData",
    '''    private function machinePriorityLayerByPcId(array $data, string $pcId): int
    {
        foreach (($data['machines'] ?? []) as $machine) {
            if (!is_array($machine)) {
                continue;
            }
            if ((string) ($machine['pc_id'] ?? '') === $pcId) {
                return max(0, (int) ($machine['priority_layer'] ?? ($machine['shutdown_layer'] ?? 0)));
            }
        }

        return 0;
    }

    private function priorityLayerStatusFromData''',
    "rename priority accessor/status helper",
)
text = text.replace("shutdownLayerStatusFromData", "priorityLayerStatusFromData")

# Replace public layer API with priority terminology, retaining tiny compatibility
# wrappers for older callers while all current code moves to the new API.
text = regex_once(
    text,
    r"    public function shutdownLayerStatus\(string \$pcId, int \$staleAfterSeconds\): array\n    \{.*?\n    \}\n\n    public function workerMayShutdownByLayer\(string \$pcId, int \$staleAfterSeconds\): bool\n    \{.*?\n    \}",
    '''    public function priorityLayerStatus(string $pcId, int $staleAfterSeconds): array
    {
        $data = $this->read();
        return $this->priorityLayerStatusFromData($data, $pcId, $staleAfterSeconds);
    }

    public function workerMayShutdownByPriority(string $pcId, int $staleAfterSeconds): bool
    {
        $status = $this->priorityLayerStatus($pcId, $staleAfterSeconds);
        return !empty($status['allowed']);
    }

    // Compatibility aliases for integrations using the pre-priority-layer API.
    public function shutdownLayerStatus(string $pcId, int $staleAfterSeconds): array
    {
        return $this->priorityLayerStatus($pcId, $staleAfterSeconds);
    }

    public function workerMayShutdownByLayer(string $pcId, int $staleAfterSeconds): bool
    {
        return $this->workerMayShutdownByPriority($pcId, $staleAfterSeconds);
    }''',
    "replace public priority layer API",
)

# Priority is mandatory for normal work: no separate preference setting.
text = regex_once(
    text,
    r"    public function normalWorkLayerAdmissionStatus\(string \$pcId, int \$staleAfterSeconds, string \$targetVersion = '', bool \$enforceVersion = false\): array\n    \{.*?\n    \}\n\n    public function nextQueuedJobForWorker",
    '''    public function normalWorkLayerAdmissionStatus(string $pcId, int $staleAfterSeconds, string $targetVersion = '', bool $enforceVersion = false): array
    {
        $data = $this->read();
        $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
        return $this->normalWorkLayerAdmissionStatusFromData($data, $settings, $pcId, $staleAfterSeconds, $targetVersion, $enforceVersion);
    }

    public function nextQueuedJobForWorker''',
    "make work priority mandatory",
)

# Clear, capability-aware normal-work admission. A higher numeric layer waits only
# when an idle lower numeric layer is actually eligible for at least one queued
# normal job.
text = regex_once(
    text,
    r"    private function normalWorkLayerAdmissionStatusFromData\(array \$data, array \$settings, string \$pcId, int \$staleAfterSeconds, string \$targetVersion, bool \$enforceVersion\): array\n    \{.*?\n    \}\n\n    private function versionUpdateLayerStatusFromData",
    '''    private function normalWorkLayerAdmissionStatusFromData(array $data, array $settings, string $pcId, int $staleAfterSeconds, string $targetVersion, bool $enforceVersion): array
    {
        $pcId = trim($pcId);
        $ownLayer = $this->machinePriorityLayerByPcId($data, $pcId);
        $queuedNormalJobs = [];
        foreach (($data['jobs'] ?? []) as $job) {
            if (($job['status'] ?? '') === 'queued' && !$this->isControlModule((string) ($job['module'] ?? ''))) {
                $queuedNormalJobs[] = $job;
            }
        }

        if ($queuedNormalJobs === []) {
            return [
                'allowed' => true,
                'reason' => 'no_normal_work_queued',
                'pc_id' => $pcId,
                'priority_layer' => $ownLayer,
                'higher_priority_idle_workers' => [],
            ];
        }

        // Control jobs must remain claimable even when normal work is waiting.
        foreach (($data['jobs'] ?? []) as $job) {
            if (($job['status'] ?? '') !== 'queued' || !$this->isControlModule((string) ($job['module'] ?? ''))) {
                continue;
            }
            if (($job['module'] ?? '') !== 'shutdown') {
                return [
                    'allowed' => true,
                    'reason' => 'control_task_pending',
                    'pc_id' => $pcId,
                    'priority_layer' => $ownLayer,
                    'higher_priority_idle_workers' => [],
                ];
            }
            $layer = $this->priorityLayerStatusFromData($data, $pcId, $staleAfterSeconds);
            if (!empty($layer['allowed'])) {
                return [
                    'allowed' => true,
                    'reason' => 'control_task_pending',
                    'pc_id' => $pcId,
                    'priority_layer' => $ownLayer,
                    'higher_priority_idle_workers' => [],
                ];
            }
        }

        $onlineWorkers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $preferredIdle = [];
        foreach ($onlineWorkers as $workerId => $worker) {
            $workerId = trim((string) $workerId);
            if ($workerId === '' || $workerId === $pcId || trim((string) ($worker['current_job'] ?? '')) !== '') {
                continue;
            }

            $workerLayer = $this->machinePriorityLayerByPcId($data, $workerId);
            if ($workerLayer >= $ownLayer) {
                continue;
            }
            if ($enforceVersion && $targetVersion !== '') {
                $workerVersion = trim((string) ($worker['version'] ?? ''));
                if (!$this->versionsMatch($workerVersion, $targetVersion)) {
                    continue;
                }
            }
            if (!$this->workerFitsCurrentSocFromData($data, $settings, $workerId)) {
                continue;
            }

            $canRunQueuedWork = false;
            foreach ($queuedNormalJobs as $queuedJob) {
                if ($this->jobEligibilityReasonsFromData($data, $queuedJob, $workerId) === []) {
                    $canRunQueuedWork = true;
                    break;
                }
            }
            if (!$canRunQueuedWork) {
                continue;
            }

            $preferredIdle[] = [
                'pc_id' => $workerId,
                'priority_layer' => $workerLayer,
            ];
        }

        usort($preferredIdle, static function (array $a, array $b): int {
            $layerComparison = ((int) ($a['priority_layer'] ?? 0)) <=> ((int) ($b['priority_layer'] ?? 0));
            return $layerComparison !== 0 ? $layerComparison : strcmp((string) ($a['pc_id'] ?? ''), (string) ($b['pc_id'] ?? ''));
        });

        return [
            'allowed' => $preferredIdle === [],
            'reason' => $preferredIdle === [] ? 'no_higher_priority_idle_worker' : 'higher_priority_worker_idle',
            'pc_id' => $pcId,
            'priority_layer' => $ownLayer,
            'higher_priority_idle_workers' => $preferredIdle,
        ];
    }

    private function versionUpdateLayerStatusFromData''',
    "rewrite normal work priority admission",
)

# Rewrite shutdown ordering using priority language. Higher numeric priority layers
# are overflow capacity and therefore shut down before lower numeric core layers.
text = regex_once(
    text,
    r"    private function priorityLayerStatusFromData\(array \$data, string \$pcId, int \$staleAfterSeconds\): array\n    \{.*?\n    \}\n\n    private function normalWorkLayerAdmissionStatusFromData",
    '''    private function priorityLayerStatusFromData(array $data, string $pcId, int $staleAfterSeconds): array
    {
        $pcId = trim($pcId);
        $ownLayer = $this->machinePriorityLayerByPcId($data, $pcId);
        $onlineWorkers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $highestOnlineLayer = $ownLayer;
        $lowerPriorityOnline = [];

        foreach ($onlineWorkers as $workerId => $worker) {
            $workerLayer = $this->machinePriorityLayerByPcId($data, (string) $workerId);
            $highestOnlineLayer = max($highestOnlineLayer, $workerLayer);
            if ($workerLayer > $ownLayer) {
                $lowerPriorityOnline[] = [
                    'pc_id' => (string) $workerId,
                    'priority_layer' => $workerLayer,
                ];
            }
        }

        return [
            'allowed' => $lowerPriorityOnline === [],
            'pc_id' => $pcId,
            'priority_layer' => $ownLayer,
            'highest_online_priority_layer' => $highestOnlineLayer,
            'lower_priority_online_workers' => $lowerPriorityOnline,
        ];
    }

    private function normalWorkLayerAdmissionStatusFromData''',
    "rewrite shutdown priority status",
)

# Version-layer diagnostic output follows the renamed concept too.
text = text.replace("'shutdown_layer' => $workerLayer,", "'priority_layer' => $workerLayer,")
text = text.replace("'shutdown_layer' => $ownLayer,", "'priority_layer' => $ownLayer,")

# Wake candidates now return every eligible offline worker in priority order; the
# demand plan decides how many are actually necessary.
text = regex_once(
    text,
    r"    private function wakeTargetsFromData\(array \$data, array \$settings, int \$staleAfterSeconds, bool \$excludeOnline, bool \$ignoreCooldown\): array\n    \{.*?\n    \}\n\n    private function lowestWakeLayerTargets\(array \$targets\): array\n    \{.*?\n    \}\n\n    private function filterWakeTargetsByCooldown",
    '''    private function wakeTargetsFromData(array $data, array $settings, int $staleAfterSeconds, bool $excludeOnline, bool $ignoreCooldown): array
    {
        $onlineWorkers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $machines = [];
        foreach (($data['machines'] ?? []) as $machine) {
            if (!is_array($machine) || empty($machine['wake_enabled']) || trim((string) ($machine['mac'] ?? '')) === '') {
                continue;
            }
            $pcId = trim((string) ($machine['pc_id'] ?? ''));
            if ($excludeOnline && $pcId !== '' && isset($onlineWorkers[$pcId])) {
                continue;
            }
            $machine['priority_layer'] = max(0, (int) ($machine['priority_layer'] ?? ($machine['shutdown_layer'] ?? 0)));
            unset($machine['shutdown_layer']);
            $machine['min_soc_percent'] = $this->machineMinSocPercent($machine, $settings);
            $machine['soc_margin_percent'] = $machine['min_soc_percent'];
            $machines[] = $machine;
        }

        usort($machines, static function (array $a, array $b): int {
            $layerComparison = ((int) ($a['priority_layer'] ?? 0)) <=> ((int) ($b['priority_layer'] ?? 0));
            if ($layerComparison !== 0) {
                return $layerComparison;
            }
            $socComparison = ((int) ($a['min_soc_percent'] ?? ($a['soc_margin_percent'] ?? 20))) <=> ((int) ($b['min_soc_percent'] ?? ($b['soc_margin_percent'] ?? 20)));
            return $socComparison !== 0 ? $socComparison : strcmp((string) ($a['pc_id'] ?? ''), (string) ($b['pc_id'] ?? ''));
        });

        return array_values(array_filter($machines, function (array $machine) use ($settings): bool {
            return !$this->essSocCanLimitWorkers($settings) || $this->machineFitsCurrentSoc($machine, $settings);
        }));
    }

    private function filterWakeTargetsByCooldown''',
    "rewrite wake priority ordering",
)

# Demand wake selects exactly the additional capacity needed across the ordered
# priority layers. Recent wake attempts count as pending capacity so a slow boot
# does not cause the master to unnecessarily wake the next overflow layer.
text = regex_once(
    text,
    r"    public function demandWakePlan\(int \$staleAfterSeconds\): array\n    \{.*?\n    \}\n\n    public function autoWakeForQueuedJobs",
    '''    public function demandWakePlan(int $staleAfterSeconds): array
    {
        $data = $this->read();
        $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
        $queuedWork = 0;
        foreach ($data['jobs'] as $job) {
            if (($job['status'] ?? '') === 'queued' && !$this->isControlModule((string) ($job['module'] ?? ''))) {
                $queuedWork++;
            }
        }

        $onlineWorkers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $idleOnlineWorkers = 0;
        foreach ($onlineWorkers as $worker) {
            if (trim((string) ($worker['current_job'] ?? '')) === '') {
                $idleOnlineWorkers++;
            }
        }

        $needed = max(0, $queuedWork - $idleOnlineWorkers);
        $eligibleTargets = $this->wakeTargetsFromData($data, $settings, $staleAfterSeconds, true, false);
        $cooldownSeconds = max(0, (int) ($settings['auto_wake_cooldown_seconds'] ?? 300));
        $readyTargets = $this->filterWakeTargetsByCooldown($eligibleTargets, $data['wake_history'] ?? [], $cooldownSeconds);
        $pendingWakeTargets = max(0, count($eligibleTargets) - count($readyTargets));
        $neededAfterPendingWake = max(0, $needed - $pendingWakeTargets);
        $maxTargets = max(0, (int) ($settings['auto_wake_max_targets_per_run'] ?? 20));
        $targetLimit = $maxTargets > 0 ? min($neededAfterPendingWake, $maxTargets) : 0;
        $targets = array_slice($readyTargets, 0, $targetLimit);

        return [
            'enabled' => !empty($settings['auto_wake_for_queued_jobs']),
            'queued_work' => $queuedWork,
            'effective_queued_work' => $queuedWork,
            'online_workers' => count($onlineWorkers),
            'idle_online_workers' => $idleOnlineWorkers,
            'needed' => $needed,
            'pending_wake_targets' => $pendingWakeTargets,
            'needed_after_pending_wake' => $neededAfterPendingWake,
            'eligible_targets' => count($eligibleTargets),
            'ready_targets' => count($readyTargets),
            'cooldown_seconds' => $cooldownSeconds,
            'max_targets_per_run' => $maxTargets,
            'targets' => $targets,
        ];
    }

    public function autoWakeForQueuedJobs''',
    "rewrite demand wake priority selection",
)
write(path, text)


# ---------------------------------------------------------------------------
# farm_api.php: surface priority language to workers/dashboard responses.
# ---------------------------------------------------------------------------
path = "farm_api.php"
text = read(path)
text = text.replace("reflection_api_shutdown_layer_payload", "reflection_api_priority_layer_payload")
text = text.replace("reflection_api_shutdown_allowed", "reflection_api_priority_shutdown_allowed")
text = text.replace("$shutdownLayer", "$priorityLayer")
text = text.replace("'shutdown_layer' => $priorityLayer", "'priority_layer' => $priorityLayer")
text = text.replace("'lower_shutdown_layer_idle'", "'higher_priority_worker_idle'")
text = text.replace("'work_layer_priority'", "'work_priority'")
text = text.replace("'shutdown_layer_waiting'", "'priority_layer_waiting'")
text = text.replace("'shutdown_layer' => $priorityLayer", "'priority_layer' => $priorityLayer")
write(path, text)


# ---------------------------------------------------------------------------
# index.php: worker cards use the new name while reading legacy stores safely.
# ---------------------------------------------------------------------------
path = "index.php"
text = read(path)
text = text.replace("'shutdown_layer' => max(0, (int) ($machine['shutdown_layer'] ?? 0)),", "'priority_layer' => max(0, (int) ($machine['priority_layer'] ?? ($machine['shutdown_layer'] ?? 0))),")
text = text.replace("'shutdown_layer' => 0,", "'priority_layer' => 0,")
text = text.replace("$layer = (int) ($card['shutdown_layer'] ?? 0);", "$layer = (int) ($card['priority_layer'] ?? ($card['shutdown_layer'] ?? 0));")
text = text.replace(" · shutdown layer ' . $layer", " · priority layer ' . $layer")
write(path, text)


# ---------------------------------------------------------------------------
# Tests: rename the schema/API and lock in the two-jobs/three-layers behavior.
# ---------------------------------------------------------------------------
path = "tests/ui_helpers_test.php"
text = read(path)
text = text.replace("'shutdown_layer' =>", "'priority_layer' =>")
text = text.replace("'machine_shutdown_layer' =>", "'machine_priority_layer' =>")
write(path, text)

path = "tests/farm_master_test.php"
text = read(path)
text = text.replace("'prefer_lower_shutdown_layers_for_work' => true, ", "")
text = text.replace("'prefer_lower_shutdown_layers_for_work' => true", "")
text = text.replace("'shutdown_layer' =>", "'priority_layer' =>")
text = text.replace("Higher shutdown layers should wait when an eligible idle lower-layer worker is online.", "Lower-priority workers should wait when an eligible higher-priority worker is online.")
text = text.replace("'lower_shutdown_layer_idle'", "'higher_priority_worker_idle'")
text = text.replace("['work_layer_priority']['lower_idle_workers']", "['work_priority']['higher_priority_idle_workers']")
text = text.replace("Lower shutdown layers should receive normal work first.", "Higher-priority layers should receive normal work first.")
text = text.replace("Higher layers may take normal work when lower layers are already busy.", "Lower-priority layers may take normal work when higher-priority layers are already busy.")
text = text.replace("Layer priority must not change the queued job order.", "Worker priority must not change the queued job order.")
text = text.replace("Higher layers still take the next queued job rather than a reserved layer job.", "Overflow workers still take the next queued job rather than a reserved layer job.")
text = text.replace("Layer-priority workers should confirm their exclusive lease.", "Priority-ordered workers should confirm their exclusive lease.")

# Replace the wake-layer scenario with an explicit 3-layer / 2-job assertion.
text = regex_once(
    text,
    r"\$wakeLayerStorePath = .*?@unlink\(\$wakeLayerStorePath \. '\\.lock'\);\n",
    '''$wakeLayerStorePath = sys_get_temp_dir() . '/reflection_wake_layer_store_' . bin2hex(random_bytes(6)) . '.json';
$wakeLayerStore = new FarmStore($wakeLayerStorePath);
$wakeLayerStore->updateSettings(['ess_soc_url' => '', 'auto_wake_for_queued_jobs' => true, 'auto_wake_cooldown_seconds' => 0, 'auto_wake_max_targets_per_run' => 20]);
$wakeLayerStore->updateMachines([
    ['pc_id' => 'wake-layer0', 'mac' => '00:11:22:33:44:20', 'min_soc_percent' => '', 'wake_enabled' => true, 'priority_layer' => 0],
    ['pc_id' => 'wake-layer1', 'mac' => '00:11:22:33:44:21', 'min_soc_percent' => '', 'wake_enabled' => true, 'priority_layer' => 1],
    ['pc_id' => 'wake-layer2', 'mac' => '00:11:22:33:44:22', 'min_soc_percent' => '', 'wake_enabled' => true, 'priority_layer' => 2],
]);
$wakeLayerStore->createJob('dummy_task', 'incoming/wake-a.dat', 'outputs/wake-a.txt', false);
$wakeLayerStore->createJob('dummy_task', 'incoming/wake-b.dat', 'outputs/wake-b.txt', false);
$wakePlan = $wakeLayerStore->demandWakePlan(900);
assertSameValue(2, $wakePlan['needed'], 'Two queued jobs with no online capacity should require two workers.');
assertSameValue(2, count($wakePlan['targets']), 'Demand wake should select exactly the number of workers needed across priority layers.');
assertSameValue('wake-layer0', $wakePlan['targets'][0]['pc_id'] ?? '', 'Core priority layer 0 should wake first.');
assertSameValue('wake-layer1', $wakePlan['targets'][1]['pc_id'] ?? '', 'Priority layer 1 should provide the second required worker.');
assertSameValue(false, in_array('wake-layer2', array_column($wakePlan['targets'], 'pc_id'), true), 'The highest overflow layer should remain off when two lower layers cover two jobs.');
$wakeLayerStore->recordWorkerCheckIn('wake-layer0', 'test-version');
$wakePlanAfterCore = $wakeLayerStore->demandWakePlan(900);
assertSameValue(1, $wakePlanAfterCore['needed'], 'An idle online core worker should cover one queued job.');
assertSameValue(1, count($wakePlanAfterCore['targets']), 'Only one additional worker should be woken after the core worker checks in.');
assertSameValue('wake-layer1', $wakePlanAfterCore['targets'][0]['pc_id'] ?? '', 'The next priority layer should wake while the highest overflow layer remains unused.');
@unlink($wakeLayerStorePath);
@unlink($wakeLayerStorePath . '.lock');
''',
    "replace wake priority test",
)

# Remove obsolete worker-command candidate wake behavior left from the earlier
# feature cleanup. Demand wake now counts actual queued normal jobs directly.
text = regex_once(
    text,
    r"\n\$candidateWakeStorePath = .*?@unlink\(\$candidateWakeStorePath \. '\\.lock'\);\n",
    "\n",
    "remove obsolete candidate wake test",
)
text = text.replace("'shutdown_layer_waiting'", "'priority_layer_waiting'")
text = text.replace("Lower shutdown layers must stay online while higher layers are still online.", "Core priority layers must stay online while overflow layers are still online.")
text = text.replace("Highest online shutdown layer should be allowed to power off first.", "Highest numeric priority layer should be allowed to power off first.")
text = text.replace("Lower layers must still wait until the higher layer confirms the shutdown order.", "Core layers must still wait until the overflow layer confirms the shutdown order.")
text = text.replace("Lower layers should power off after higher online layers have confirmed shutdown.", "Core layers should power off only after higher numeric priority layers have confirmed shutdown.")
write(path, text)

print("priority layer refactor applied")
