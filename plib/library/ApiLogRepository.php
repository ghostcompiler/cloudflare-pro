<?php

class Modules_CloudflarePro_ApiLogRepository
{
    private $db;
    private $owner;

    public function __construct(array $owner = null)
    {
        $dbPath = $this->getDbPath();
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->owner = $owner ?: $this->getOwner();
        $this->init();
    }

    public function init()
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS api_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id TEXT NOT NULL DEFAULT "",
                owner_login TEXT NOT NULL DEFAULT "",
                source TEXT NOT NULL DEFAULT "cloudflare",
                route TEXT NOT NULL,
                method TEXT NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 0,
                ok INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER,
                request TEXT,
                response TEXT,
                error TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $this->addColumnIfMissing('owner_id', 'TEXT NOT NULL DEFAULT ""');
        $this->addColumnIfMissing('owner_login', 'TEXT NOT NULL DEFAULT ""');
    }

    public function add($route, $method, $statusCode, $ok, array $request = [], $response = null, $durationMs = null, $error = null)
    {
        $stmt = $this->db->prepare(
            'INSERT INTO api_logs (
                owner_id, owner_login, source, route, method, status_code, ok, duration_ms, request, response, error, created_at
            ) VALUES (
                :owner_id, :owner_login, :source, :route, :method, :status_code, :ok, :duration_ms, :request, :response, :error, :created_at
            )'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
            ':source' => 'cloudflare',
            ':route' => $this->text($route, 500),
            ':method' => strtoupper($this->text($method, 12)),
            ':status_code' => (int) $statusCode,
            ':ok' => $ok ? 1 : 0,
            ':duration_ms' => $durationMs === null ? null : (int) $durationMs,
            ':request' => json_encode($this->sanitize($request), JSON_UNESCAPED_SLASHES),
            ':response' => json_encode($this->sanitize($response), JSON_UNESCAPED_SLASHES),
            ':error' => $error === null ? null : $this->text($this->redact($error), 1200),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->trim();
    }

    public function all($limit = 1000)
    {
        $stmt = $this->db->prepare(
            'SELECT id, source, route, method, status_code, ok, duration_ms, request, response, error, created_at
             FROM api_logs
             WHERE owner_id = :owner_id
             ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':owner_id', $this->owner['id']);
        $stmt->bindValue(':limit', max(1, min(1000, (int) $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['ok'] = (bool) $row['ok'];
            $row['request'] = $this->decodeJson($row['request']);
            $row['response'] = $this->decodeJson($row['response']);
            $items[] = $row;
        }

        return $items;
    }

    public function clear()
    {
        $stmt = $this->db->prepare('DELETE FROM api_logs WHERE source = "cloudflare" AND owner_id = :owner_id');
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
        ]);
    }

    public function owner()
    {
        return $this->owner;
    }

    private function trim()
    {
        $stmt = $this->db->prepare(
            'DELETE FROM api_logs
             WHERE owner_id = :owner_id
               AND id NOT IN (
                    SELECT id FROM api_logs
                    WHERE owner_id = :owner_id
                    ORDER BY id DESC LIMIT 1000
               )'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
        ]);
    }

    private function addColumnIfMissing($name, $definition)
    {
        $columns = $this->db->query('PRAGMA table_info(api_logs)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return;
            }
        }

        $this->db->exec('ALTER TABLE api_logs ADD COLUMN ' . $name . ' ' . $definition);
    }

    private function sanitize($value, $depth = 0)
    {
        if ($depth > 6) {
            return '[truncated]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (preg_match('/token|secret|authorization|password/i', (string) $key)) {
                    $clean[$key] = '[redacted]';
                } else {
                    $clean[$key] = $this->sanitize($item, $depth + 1);
                }
            }

            return $clean;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value, $depth + 1);
        }

        if (is_string($value)) {
            $value = $this->redact($value);
            if (strlen($value) > 4000) {
                return substr($value, 0, 4000) . '... [truncated]';
            }
        }

        return $value;
    }

    private function redact($value)
    {
        $value = (string) $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [redacted]', $value);

        return $value;
    }

    private function decodeJson($value)
    {
        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function text($value, $maxLength)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }

    private function getDbPath()
    {
        $varDir = pm_Context::getVarDir();

        if (!is_dir($varDir)) {
            mkdir($varDir, 0755, true);
        }

        return $varDir . DIRECTORY_SEPARATOR . 'cloudflare-pro.sqlite';
    }

    private function getOwner()
    {
        try {
            $client = pm_Session::getClient();
            $id = method_exists($client, 'getId') ? (string) $client->getId() : '';
            $login = method_exists($client, 'getLogin') ? (string) $client->getLogin() : '';

            if ('' !== $id) {
                return [
                    'id' => $id,
                    'login' => $login,
                ];
            }
        } catch (Throwable $e) {
        }

        return [
            'id' => 'system',
            'login' => 'system',
        ];
    }
}
