from pathlib import Path

root = Path(__file__).resolve().parents[1]

farm_path = root / "FarmStore.php"
text = farm_path.read_text(encoding="utf-8")
old = '''            $canRunQueuedWork = false;
            foreach ($queuedNormalJobs as $queuedJob) {
                if ($this->jobEligibilityReasonsFromData($data, $queuedJob, $workerId) === []) {
                    $canRunQueuedWork = true;
                    break;
                }
            }
'''
new = '''            // Workers from older check-ins may not have a capability snapshot yet.
            // Preserve their priority rather than assuming they are incapable. Once
            // capabilities are known, only block overflow workers for jobs the
            // higher-priority idle worker can actually execute.
            $workerCapabilities = $worker['capabilities'] ?? null;
            $canRunQueuedWork = !is_array($workerCapabilities) || $workerCapabilities === [];
            if (!$canRunQueuedWork) {
                foreach ($queuedNormalJobs as $queuedJob) {
                    if ($this->jobEligibilityReasonsFromData($data, $queuedJob, $workerId) === []) {
                        $canRunQueuedWork = true;
                        break;
                    }
                }
            }
'''
if text.count(old) != 1:
    raise RuntimeError(f"Expected one priority capability block, found {text.count(old)}")
text = text.replace(old, new, 1)
text = text.replace("'shutdown layer is not currently allowed'", "'priority layer is not currently allowed'")
farm_path.write_text(text, encoding="utf-8")

api_path = root / "farm_api.php"
text = api_path.read_text(encoding="utf-8")
old = "return $store->shutdownLayerStatus($pcId, $staleAfterSeconds);"
if text.count(old) != 1:
    raise RuntimeError(f"Expected one legacy priority status call, found {text.count(old)}")
text = text.replace(old, "return $store->priorityLayerStatus($pcId, $staleAfterSeconds);", 1)
api_path.write_text(text, encoding="utf-8")

print("priority follow-up patch applied")
