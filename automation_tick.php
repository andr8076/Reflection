<?php

declare(strict_types=1);

require_once __DIR__ . '/MasterTick.php';

function reflection_tick_output(array $payload, int $statusCode = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
        header('Content-Type: application/json');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$config = reflection_master_config();
try {
    $result = reflection_run_master_tick($config);
    reflection_tick_output($result, ($result['status'] ?? '') === 'busy' ? 409 : 200);
} catch (Throwable $exception) {
    reflection_tick_output(['status' => 'error', 'error' => $exception->getMessage()], 500);
}
