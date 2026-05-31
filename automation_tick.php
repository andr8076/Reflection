<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/AutomationStore.php';

function reflection_tick_token_from_request(): string
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach (($argv ?? []) as $argument) {
            if (strpos((string) $argument, '--token=') === 0) {
                return substr((string) $argument, 8);
            }
        }
        return '';
    }

    $headerToken = (string) ($_SERVER['HTTP_X_REFLECTION_API_TOKEN'] ?? '');
    if ($headerToken !== '') {
        return $headerToken;
    }

    return (string) ($_GET['token'] ?? '');
}

function reflection_tick_output(array $payload, int $statusCode = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
        header('Content-Type: application/json');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$config = reflection_master_config();
$requiredToken = (string) ($config['api_token'] ?? '');
if ($requiredToken !== '' && !hash_equals($requiredToken, reflection_tick_token_from_request())) {
    reflection_tick_output(['status' => 'unauthorized', 'error' => 'Invalid or missing API token.'], 401);
    return;
}

try {
    $farmStore = reflection_farm_store($config);
    $automationStore = new AutomationStore(dirname((string) $config['storage_path']));
    $results = $automationStore->runDueRules($farmStore, false);
    $queued = 0;
    foreach ($results as $result) {
        $queued += (int) ($result['queued'] ?? 0);
    }

    reflection_tick_output([
        'status' => 'ok',
        'rules_run' => count($results),
        'jobs_queued' => $queued,
        'results' => $results,
    ]);
} catch (Throwable $exception) {
    reflection_tick_output(['status' => 'error', 'error' => $exception->getMessage()], 500);
}
