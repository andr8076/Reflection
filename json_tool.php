<?php

declare(strict_types=1);

define('REFLECTION_EMBEDDED_API', true);
require_once __DIR__ . '/farm_api.php';

$config = reflection_master_config();
$scriptDirectory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$defaultEndpoint = $requestScheme . '://' . $requestHost . ($scriptDirectory === '' ? '' : $scriptDirectory) . '/farm_api.php';
$endpoint = trim((string) ($_POST['endpoint'] ?? $defaultEndpoint));
$requestJson = (string) ($_POST['request_json'] ?? json_encode([
    'action' => 'request_task',
    'pc_id' => 'manual-worker',
    'version' => $config['required_version'] ?? '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$responseBody = null;
$responseStatus = null;
$error = null;

function reflection_tool_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reflection_json_pretty(string $json): string
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $json;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function reflection_endpoint_is_local(string $endpoint, string $defaultEndpoint): bool
{
    if ($endpoint === $defaultEndpoint || $endpoint === 'farm_api.php') {
        return true;
    }

    $endpointPath = parse_url($endpoint, PHP_URL_PATH);
    $defaultPath = parse_url($defaultEndpoint, PHP_URL_PATH);

    return is_string($endpointPath) && is_string($defaultPath) && $endpointPath === $defaultPath;
}

function reflection_send_json(string $endpoint, string $json, array $config, string $defaultEndpoint, &$statusCode, &$error): ?string
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = 'Request JSON is invalid: ' . json_last_error_msg();
        return null;
    }

    if (reflection_endpoint_is_local($endpoint, $defaultEndpoint)) {
        $store = reflection_farm_store($config);
        $statusCode = 'local farm_api.php';
        return json_encode(reflection_handle_farm_api($decoded, $store, $config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($decoded, JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $body = @file_get_contents($endpoint, false, $context);
    if ($body === false) {
        $error = 'Unable to reach endpoint. Check the URL and PHP allow_url_fopen setting.';
        return null;
    }

    $statusCode = 'unknown';
    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        $statusCode = $http_response_header[0];
    }

    return $body;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestJson = reflection_json_pretty($requestJson);
    if ($endpoint === '') {
        $error = 'Endpoint is required.';
    } else {
        $responseBody = reflection_send_json($endpoint, $requestJson, $config, $defaultEndpoint, $responseStatus, $error);
        if ($responseBody !== null) {
            $responseBody = reflection_json_pretty($responseBody);
        }
    }
}

$presets = [
    'request_task' => [
        'action' => 'request_task',
        'pc_id' => 'manual-worker',
        'version' => $config['required_version'] ?? '',
    ],
    'confirm_taken' => [
        'action' => 'confirm_taken',
        'pc_id' => 'manual-worker',
        'version' => $config['required_version'] ?? '',
        'task_id' => 'job_1001',
    ],
    'report_success' => [
        'action' => 'report_done',
        'pc_id' => 'manual-worker',
        'version' => $config['required_version'] ?? '',
        'task_id' => 'job_1001',
        'status' => 'success',
        'error' => '',
    ],
    'report_failed' => [
        'action' => 'report_done',
        'pc_id' => 'manual-worker',
        'version' => $config['required_version'] ?? '',
        'task_id' => 'job_1001',
        'status' => 'failed',
        'error' => 'Simulated worker failure.',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reflection JSON Tool</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <div>
            <p class="eyebrow">Reflection</p>
            <h1>JSON Tool</h1>
            <p class="lede">Send editable JSON requests to <?= reflection_tool_h($config['farm_name'] ?? 'the farm master') ?> so you can manually act like a worker and inspect each response.</p>
        </div>
        <div class="version-card">
            <span>Default endpoint</span>
            <strong><?= reflection_tool_h($defaultEndpoint) ?></strong>
        </div>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert error"><?= reflection_tool_h($error) ?></div>
    <?php endif; ?>

    <main>
        <section class="panel submit-panel">
            <h2>Request</h2>
            <form method="post">
                <label>
                    Endpoint
                    <input name="endpoint" value="<?= reflection_tool_h($endpoint) ?>" placeholder="<?= reflection_tool_h($defaultEndpoint) ?>">
                    <small>Use the default master API or paste another full URL.</small>
                </label>
                <div class="preset-row">
                    <?php foreach ($presets as $name => $preset): ?>
                        <button type="button" data-preset="<?= reflection_tool_h(json_encode($preset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?>"><?= reflection_tool_h($name) ?></button>
                    <?php endforeach; ?>
                </div>
                <label>
                    Editable JSON payload
                    <textarea name="request_json" id="request-json" rows="16"><?= reflection_tool_h($requestJson) ?></textarea>
                </label>
                <button type="submit">Send JSON</button>
            </form>
        </section>

        <section class="panel stats-panel">
            <h2>How to simulate a worker</h2>
            <ol class="steps">
                <li>Send <code>request_task</code> to see whether a job is available.</li>
                <li>Copy the returned <code>task_id</code> into <code>confirm_taken</code>.</li>
                <li>Finish with <code>report_done</code> using <code>success</code> or <code>failed</code>.</li>
            </ol>
            <p class="api-note"><a href="index.php">Back to dashboard</a></p>
        </section>
    </main>

    <section class="panel">
        <h2>Response<?= $responseStatus !== null ? ' — ' . reflection_tool_h($responseStatus) : '' ?></h2>
        <?php if ($responseBody === null): ?>
            <p class="empty">No response yet.</p>
        <?php else: ?>
            <pre class="json-viewer"><code><?= reflection_tool_h($responseBody) ?></code></pre>
        <?php endif; ?>
    </section>

    <script>
        document.querySelectorAll('[data-preset]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('request-json').value = button.getAttribute('data-preset');
            });
        });
    </script>
</body>
</html>
