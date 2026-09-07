<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/StorageStore.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/ui_helpers.php';

reflection_send_security_headers();

$config = reflection_master_config();
$dataDirectory = dirname((string) $config['storage_path']);
$storageStore = new StorageStore($dataDirectory, $config['transfer_server'] ?? null);
$farmStore = reflection_farm_store($config);
$message = null;
$error = null;
$editingId = (string) ($_GET['edit'] ?? '');
$editingServer = null;

function reflection_server_from_post(StorageStore $store): array
{
    return $store->normalizeServer([
        'id' => (string) ($_POST['server_id'] ?? ''),
        'name' => (string) ($_POST['name'] ?? ''),
        'enabled' => reflection_post_bool('enabled'),
        'scheme' => (string) ($_POST['scheme'] ?? 'ftp'),
        'host' => (string) ($_POST['host'] ?? ''),
        'port' => (string) ($_POST['port'] ?? '21'),
        'root' => (string) ($_POST['root'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
        'created_at' => (string) ($_POST['created_at'] ?? ''),
        'updated_at' => (string) ($_POST['updated_at'] ?? ''),
    ]);
}

function reflection_server_endpoint(array $server): string
{
    $root = trim((string) ($server['root'] ?? ''));
    return sprintf('%s://%s:%d%s',
        (string) ($server['scheme'] ?? 'ftp'),
        (string) ($server['host'] ?? ''),
        (int) ($server['port'] ?? 21),
        $root !== '' ? $root : ''
    );
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['storage_action'] ?? '');
        if ($action === 'save_server') {
            $saved = $storageStore->saveServer(reflection_server_from_post($storageStore));
            $editingId = (string) ($saved['id'] ?? '');
            $editingServer = $saved;
            $message = 'Storage server saved.';
        } elseif ($action === 'delete_server') {
            $id = (string) ($_POST['server_id'] ?? '');
            if ($storageStore->deleteServer($id)) {
                $message = 'Storage server deleted.';
            } else {
                $message = 'Storage server was not deleted. Built-in/default servers cannot be deleted here.';
            }
            $editingId = '';
        } elseif ($action === 'test_server') {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['server_id'] ?? '')) ?: '';
            $server = $storageStore->server($id);
            if ($server === null) {
                throw new RuntimeException('Choose a saved storage server to test.');
            }
            $job = $farmStore->createJob('storage_test', json_encode(['server_id' => $id], JSON_UNESCAPED_SLASHES), '', false, [
                'transfer_server_id' => $id,
                'required_transfer_scheme' => (string) ($server['scheme'] ?? 'ftp'),
            ]);
            $message = 'Queued storage test job ' . ($job['task_id'] ?? '') . '. The next worker that checks in will test this server using its local login.';
            $editingId = $id;
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    if ($editingServer === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $editingServer = reflection_server_from_post($storageStore);
    }
}

$servers = $storageStore->servers(true);
if ($editingServer === null) {
    if ($editingId !== '') {
        $editingServer = $storageStore->server($editingId);
    }
    if ($editingServer === null) {
        $editingServer = $storageStore->newServer();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Storage servers · Reflection Farm Master</title>
    <?= reflection_stylesheet_links() ?>
</head>
<body class="automation-page bg-light text-dark container-fluid px-3 px-md-4 py-4 storage-page">
    <header class="hero row g-3 align-items-stretch mb-4 compact-hero">
        <div class="hero-main col-12 col-lg bg-white border rounded-4 shadow-sm p-4">
            <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Reflection farm master</p>
            <h1>Storage servers</h1>
            <p class="lede text-secondary fs-5 mb-3">Add FTP, FTPS, or SFTP servers that jobs and automation rules can point workers at. Worker usernames and passwords still stay on the worker computers.</p>
            <div class="hero-pills d-flex flex-wrap gap-2">
                <span>Servers <code><?= count($servers) ?></code></span>
                <span>Credentials <code>worker-side</code></span>
            </div>
            <nav class="top-nav nav nav-pills flex-wrap gap-2 mt-3">
                <a class="nav-link" href="index.php">Dashboard</a>
                <a class="nav-link" href="automation.php">Automation</a>
                <a class="nav-link active" href="storage_servers.php">Storage servers</a>
                <a class="nav-link" href="blocked_jobs.php">Blocked jobs</a>
                <a class="nav-link" href="system_checks.php">System checks</a><a class="nav-link" href="logs.php">Logs</a><a class="nav-link" href="settings.php">Settings</a>
            </nav>
        </div>
        <div class="version-card col-12 col-lg-4 bg-white border rounded-4 shadow-sm p-4 d-flex flex-column gap-2 storage-help-card">
            <span>What goes here?</span>
            <strong>Server address only</strong>
            <small>The master sends protocol, host, port, and root. Each worker uses its own login from <code>cluster/reflection_config.json</code>.</small>
        </div>
    </header>

    <?php if ($message !== null): ?>
        <div class="alert success text-bg-success"><?= reflection_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert error alert-danger text-bg-danger"><?= reflection_h($error) ?></div>
    <?php endif; ?>

    <main class="automation-layout row g-4 align-items-start storage-layout">
        <aside class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 automation-sidebar col-12 col-xl-4">
            <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Configured</p>
                    <h2>Storage CRUD</h2>
                </div>
                <a class="ghost-button btn btn-outline-primary small-button btn-sm" href="storage_servers.php?new=1">New server</a>
            </div>
            <div class="rule-list list-group">
                <?php if ($servers === []): ?>
                    <p class="empty text-secondary text-center py-4">No storage servers configured yet.</p>
                <?php endif; ?>
                <?php foreach ($servers as $server): ?>
                    <?php $selected = ($editingServer['id'] ?? '') !== '' && ($editingServer['id'] ?? '') === ($server['id'] ?? ''); ?>
                    <article class="rule-card <?= $selected ? 'selected' : '' ?>">
                        <div>
                            <strong><?= reflection_h($server['name'] ?? 'Unnamed server') ?></strong>
                            <small><?= reflection_h(reflection_server_endpoint($server)) ?></small>
                            <small><?= !empty($server['enabled']) ? 'enabled' : 'disabled' ?><?= !empty($server['is_legacy']) ? ' · from farm_settings.local.php' : '' ?></small>
                        </div>
                        <div class="rule-actions d-flex flex-wrap gap-2">
                            <a class="text-link link-primary" href="storage_servers.php?edit=<?= reflection_h($server['id'] ?? '') ?>">Edit</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 automation-editor col-12 col-xl-8">
            <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3 wrap-head">
                <div>
                    <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Server editor</p>
                    <h2><?= ($editingServer['id'] ?? '') === '' || !empty($editingServer['is_legacy']) ? 'Create server' : 'Edit server' ?></h2>
                    <p class="api-note text-secondary small">Do not put FTP usernames or passwords here. This page only stores the server endpoint that jobs should use.</p>
                </div>
                <?php if (($editingServer['id'] ?? '') !== '' && empty($editingServer['is_legacy'])): ?>
                    <form method="post" data-confirm="Delete this storage server? Existing queued jobs that reference it may fall back to the default server.">
                        <input type="hidden" name="storage_action" value="delete_server">
                        <input type="hidden" name="server_id" value="<?= reflection_h($editingServer['id'] ?? '') ?>">
                        <button type="submit" class="danger-button btn btn-outline-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!empty($editingServer['is_legacy'])): ?>
                <div class="alert muted alert-secondary text-bg-secondary">This is the legacy/default server from <code>farm_settings.local.php</code>. Saving it here will create an editable dashboard-managed copy.</div>
            <?php endif; ?>

            <form method="post" class="automation-form d-grid gap-3 rf-form">
                <input type="hidden" name="storage_action" value="save_server">
                <input type="hidden" name="server_id" value="<?= empty($editingServer['is_legacy']) ? reflection_h($editingServer['id'] ?? '') : '' ?>">
                <input type="hidden" name="created_at" value="<?= reflection_h($editingServer['created_at'] ?? '') ?>">
                <input type="hidden" name="updated_at" value="<?= reflection_h($editingServer['updated_at'] ?? '') ?>">

                <div class="form-block d-grid gap-2 mb-3 boxed-form-block bg-light border rounded-3 p-3">
                    <div class="form-section-header static-section-header"><span class="section-number">1</span><div><h3>Server details</h3><p>These details are sent with jobs. Credentials are not.</p></div></div>
                    <div class="settings-grid row g-3">
                        <label>
                            Server name
                            <input name="name" required value="<?= reflection_h($editingServer['name'] ?? '') ?>" class="form-control" placeholder="Main Synology FTP">
                        </label>
                        <label>
                            Protocol
                            <select class="form-select" name="scheme">
                                <?php foreach (['ftp' => 'FTP', 'ftps' => 'FTPS', 'sftp' => 'SFTP'] as $scheme => $label): ?>
                                    <option value="<?= reflection_h($scheme) ?>" <?= ($editingServer['scheme'] ?? 'ftp') === $scheme ? 'selected' : '' ?>><?= reflection_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Host
                            <input name="host" required value="<?= reflection_h($editingServer['host'] ?? '') ?>" class="form-control" placeholder="192.168.1.10">
                        </label>
                        <label>
                            Port
                            <input name="port" inputmode="numeric" value="<?= (int) ($editingServer['port'] ?? 21) ?>" class="form-control">
                        </label>
                        <label>
                            Remote root
                            <input name="root" value="<?= reflection_h($editingServer['root'] ?? '') ?>" class="form-control" placeholder="/ or /volume1">
                            <small>Optional. Leave blank if paths like <code>/movies/file.mkv</code> are already correct after FTP login.</small>
                        </label>
                        <label class="check-row form-check d-flex gap-2 align-items-center top-check col bg-light border rounded-3 p-3 option-card">
                            <input type="checkbox" name="enabled" value="1" <?= !array_key_exists('enabled', $editingServer) || !empty($editingServer['enabled']) ? 'checked' : '' ?>>
                            Enabled
                            <small>Disabled servers stay saved but are hidden from new job/rule selection.</small>
                        </label>
                    </div>
                    <label>
                        Notes
                        <textarea class="form-control" name="notes" rows="3" placeholder="Example: workers log in as their own FTP users; paths start at /System."><?= reflection_h($editingServer['notes'] ?? '') ?></textarea>
                    </label>
                </div>

                <div class="form-block d-grid gap-2 mb-3 boxed-form-block bg-light border rounded-3 p-3">
                    <div class="form-section-header static-section-header"><span class="section-number">2</span><div><h3>How this is used</h3><p>The job receives this server and the source/delivery paths from the queue or automation rule.</p></div></div>
                    <div class="template-preview-grid row row-cols-1 row-cols-md-2 g-3">
                        <div class="template-preview-card col bg-light border rounded-3 p-3">
                            <span>Server sent to worker</span>
                            <code><?= reflection_h(reflection_server_endpoint($editingServer)) ?></code>
                        </div>
                        <div class="template-preview-card col bg-light border rounded-3 p-3">
                            <span>Credentials</span>
                            <code>read locally by worker</code>
                        </div>
                        <div class="template-preview-card col bg-light border rounded-3 p-3">
                            <span>Example worker config</span>
                            <code>transfer_auth.username/password</code>
                        </div>
                    </div>
                </div>

                <div class="button-row d-flex flex-wrap gap-2 sticky-actions bg-white border rounded-3 p-3 sticky-bottom">
                    <button class="btn btn-primary" type="submit">Save server</button>
                    <a class="ghost-button btn btn-outline-primary" href="automation.php">Back to Automation</a>
                </div>
            </form>

            <?php if (($editingServer['id'] ?? '') !== ''): ?>
                <form method="post" class="button-row d-flex flex-wrap gap-2">
                    <input type="hidden" name="storage_action" value="test_server">
                    <input type="hidden" name="server_id" value="<?= reflection_h($editingServer['id'] ?? '') ?>">
                    <button type="submit" class="ghost-button btn btn-outline-primary">Queue worker storage test</button>
                    <small>The test uses an online worker's local FTP/SFTP login and verifies create/read/rename/delete access.</small>
                </form>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <p>Storage servers live in <code>data/storage_servers.json</code>. Passwords should stay in each worker’s local config.</p>
        <p><a href="index.php">Back to dashboard</a></p>
    </footer>
    <script src="<?= reflection_h(reflection_asset_url('assets/js/common.js')) ?>"></script>
<?= reflection_script_links() ?></body>
</html>
