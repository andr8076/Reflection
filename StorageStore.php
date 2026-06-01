<?php

declare(strict_types=1);

final class StorageStore
{
    private string $directory;
    private string $path;
    private string $lockPath;
    private ?array $legacyServer;

    public function __construct(string $directory, ?array $legacyServer = null)
    {
        $this->directory = $directory;
        $this->path = $directory . DIRECTORY_SEPARATOR . 'storage_servers.json';
        $this->lockPath = $directory . DIRECTORY_SEPARATOR . 'storage_servers.lock';
        $this->legacyServer = $this->sanitizeLegacyServer($legacyServer);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create storage-server data directory: %s', $directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('Storage-server data directory is not writable: %s', $directory));
        }
    }

    public function servers(bool $includeLegacy = true): array
    {
        $data = $this->readJson($this->path, ['servers' => []]);
        $servers = [];
        foreach (($data['servers'] ?? []) as $server) {
            if (is_array($server)) {
                $servers[] = $this->normalizeServer($server);
            }
        }

        if ($servers === [] && $includeLegacy && $this->legacyServer !== null) {
            $servers[] = $this->legacyServer;
        }

        usort($servers, static function (array $a, array $b): int {
            if (!empty($a['is_default']) && empty($b['is_default'])) {
                return -1;
            }
            if (empty($a['is_default']) && !empty($b['is_default'])) {
                return 1;
            }
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $servers;
    }

    public function enabledServers(bool $includeLegacy = true): array
    {
        return array_values(array_filter($this->servers($includeLegacy), static function (array $server): bool {
            return !empty($server['enabled']);
        }));
    }

    public function server(string $id): ?array
    {
        $id = $this->cleanId($id);
        if ($id === '') {
            return null;
        }

        foreach ($this->servers(true) as $server) {
            if (($server['id'] ?? '') === $id) {
                return $server;
            }
        }

        return null;
    }

    public function firstEnabledServer(): ?array
    {
        $enabled = $this->enabledServers(true);
        return $enabled[0] ?? null;
    }

    public function newServer(): array
    {
        return $this->normalizeServer([
            'id' => '',
            'name' => 'New FTP server',
            'enabled' => true,
            'scheme' => 'ftp',
            'host' => '',
            'port' => 21,
            'root' => '',
            'notes' => '',
            'created_at' => null,
            'updated_at' => null,
        ]);
    }

    public function saveServer(array $input): array
    {
        $server = $this->normalizeServer($input);
        if (($server['id'] ?? '') === '' || !empty($server['is_legacy'])) {
            $server['id'] = $this->newServerId($server['name'] !== '' ? $server['name'] : $server['host']);
            $server['created_at'] = gmdate(DATE_ATOM);
        }
        $server['updated_at'] = gmdate(DATE_ATOM);
        unset($server['is_legacy'], $server['is_default']);

        $this->validateServer($server);

        $this->withLock(function () use ($server): void {
            $data = $this->readJson($this->path, ['servers' => []]);
            $servers = [];
            $updated = false;
            foreach (($data['servers'] ?? []) as $existing) {
                if (!is_array($existing)) {
                    continue;
                }
                $existing = $this->normalizeServer($existing);
                if (($existing['id'] ?? '') === $server['id']) {
                    $servers[] = $server;
                    $updated = true;
                } else {
                    unset($existing['is_legacy'], $existing['is_default']);
                    $servers[] = $existing;
                }
            }

            if (!$updated) {
                $servers[] = $server;
            }

            $this->atomicWriteJson($this->path, ['servers' => array_values($servers)]);
        });

        return $server;
    }

    public function deleteServer(string $id): bool
    {
        $id = $this->cleanId($id);
        if ($id === '' || $id === 'legacy_default') {
            return false;
        }

        return $this->withLock(function () use ($id): bool {
            $data = $this->readJson($this->path, ['servers' => []]);
            $before = count($data['servers'] ?? []);
            $servers = [];
            foreach (($data['servers'] ?? []) as $server) {
                if (is_array($server) && ($this->normalizeServer($server)['id'] ?? '') !== $id) {
                    $normalized = $this->normalizeServer($server);
                    unset($normalized['is_legacy'], $normalized['is_default']);
                    $servers[] = $normalized;
                }
            }
            $this->atomicWriteJson($this->path, ['servers' => array_values($servers)]);
            return count($servers) !== $before;
        });
    }

    public function workerServerPayload(?string $id): ?array
    {
        $server = $id !== null && trim($id) !== '' ? $this->server($id) : $this->firstEnabledServer();
        if ($server === null || empty($server['enabled']) || trim((string) ($server['host'] ?? '')) === '') {
            return null;
        }

        return [
            'scheme' => (string) ($server['scheme'] ?? 'ftp'),
            'host' => (string) ($server['host'] ?? ''),
            'port' => (int) ($server['port'] ?? 21),
            'root' => (string) ($server['root'] ?? ''),
        ];
    }

    public function normalizeServer(array $input): array
    {
        $scheme = strtolower(trim((string) ($input['scheme'] ?? 'ftp')));
        if (!in_array($scheme, ['ftp', 'ftps', 'sftp'], true)) {
            $scheme = 'ftp';
        }

        $port = (int) ($input['port'] ?? 0);
        if ($port <= 0) {
            $port = $scheme === 'sftp' ? 22 : ($scheme === 'ftps' ? 990 : 21);
        }

        return [
            'id' => $this->cleanId((string) ($input['id'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'enabled' => !array_key_exists('enabled', $input) || !empty($input['enabled']),
            'scheme' => $scheme,
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => max(1, min(65535, $port)),
            'root' => $this->normalizeRoot((string) ($input['root'] ?? '')),
            'notes' => trim((string) ($input['notes'] ?? '')),
            'created_at' => $this->cleanOptionalString($input['created_at'] ?? null),
            'updated_at' => $this->cleanOptionalString($input['updated_at'] ?? null),
            'is_legacy' => !empty($input['is_legacy']),
            'is_default' => !empty($input['is_default']),
        ];
    }

    public function validateServer(array $server): void
    {
        $errors = [];
        if (trim((string) ($server['name'] ?? '')) === '') {
            $errors[] = 'Server name is required.';
        }
        if (trim((string) ($server['host'] ?? '')) === '') {
            $errors[] = 'Server host is required.';
        }
        if (!in_array((string) ($server['scheme'] ?? 'ftp'), ['ftp', 'ftps', 'sftp'], true)) {
            $errors[] = 'Server protocol must be FTP, FTPS, or SFTP.';
        }
        $port = (int) ($server['port'] ?? 0);
        if ($port <= 0 || $port > 65535) {
            $errors[] = 'Server port must be between 1 and 65535.';
        }

        foreach (['name', 'host', 'root', 'notes'] as $key) {
            if (preg_match('/[\x00-\x1F\x7F]/', (string) ($server[$key] ?? '')) === 1) {
                $errors[] = 'Server fields may not contain control characters.';
                break;
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    private function sanitizeLegacyServer(?array $legacyServer): ?array
    {
        if (!is_array($legacyServer)) {
            return null;
        }
        $server = $this->normalizeServer(array_merge($legacyServer, [
            'id' => 'legacy_default',
            'name' => 'Configured default server',
            'enabled' => true,
            'is_legacy' => true,
            'is_default' => true,
        ]));
        return trim((string) ($server['host'] ?? '')) !== '' ? $server : null;
    }

    private function cleanId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: '';
    }

    private function newServerId(string $seed): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $seed), '-')) ?: 'storage';
        $base = substr($base, 0, 36);
        $existing = [];
        foreach ($this->servers(false) as $server) {
            $existing[(string) ($server['id'] ?? '')] = true;
        }

        $id = $base;
        $counter = 2;
        while (isset($existing[$id]) || $id === 'legacy_default') {
            $id = $base . '-' . $counter;
            $counter++;
        }
        return $id;
    }

    private function normalizeRoot(string $root): string
    {
        $root = trim(str_replace('\\', '/', $root));
        if ($root === '/' || $root === '.') {
            return '';
        }
        return rtrim($root, '/');
    }

    private function cleanOptionalString($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function withLock(callable $callback)
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create storage-server lock directory.');
        }
        $handle = @fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open storage-server lock file.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to lock storage-server file.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readJson(string $path, array $fallback): array
    {
        if (!is_file($path)) {
            return $fallback;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $fallback;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function atomicWriteJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to write storage-server file: %s', $path));
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to replace storage-server file: %s', $path));
        }
    }
}
