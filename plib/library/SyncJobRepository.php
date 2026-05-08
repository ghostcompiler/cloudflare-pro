<?php

class Modules_CloudflarePro_SyncJobRepository
{
    private $db;
    private $owner;

    public function __construct(array $owner = null)
    {
        $this->db = new PDO('sqlite:' . $this->getDbPath());
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->owner = $owner ?: $this->getOwner();
        $this->init();
    }

    public function init()
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS sync_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id TEXT NOT NULL DEFAULT "",
                owner_login TEXT NOT NULL DEFAULT "",
                link_id INTEGER NOT NULL,
                mode TEXT NOT NULL DEFAULT "export",
                status TEXT NOT NULL DEFAULT "queued",
                items_json TEXT NOT NULL,
                total INTEGER NOT NULL DEFAULT 0,
                processed INTEGER NOT NULL DEFAULT 0,
                created INTEGER NOT NULL DEFAULT 0,
                updated INTEGER NOT NULL DEFAULT 0,
                failed INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->addColumnIfMissing('mode', 'TEXT NOT NULL DEFAULT "export"');
    }

    public function create($linkId, array $items, $mode = 'export')
    {
        $now = date('Y-m-d H:i:s');
        $mode = in_array($mode, ['import', 'export', 'sync'], true) ? $mode : 'export';
        $stmt = $this->db->prepare(
            'INSERT INTO sync_jobs (
                owner_id, owner_login, link_id, mode, status, items_json, total,
                processed, created, updated, failed, created_at, updated_at
            ) VALUES (
                :owner_id, :owner_login, :link_id, :mode, :status, :items_json, :total,
                0, 0, 0, 0, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
            ':link_id' => (int) $linkId,
            ':mode' => $mode,
            ':status' => $items ? 'running' : 'done',
            ':items_json' => json_encode(array_values($items)),
            ':total' => count($items),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->find((int) $this->db->lastInsertId());
    }

    public function find($id)
    {
        $stmt = $this->db->prepare(
            'SELECT id, owner_id, owner_login, link_id, mode, status, items_json, total,
                    processed, created, updated, failed, error, created_at, updated_at
             FROM sync_jobs
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new pm_Exception('Sync job not found.');
        }

        return $this->normalize($row);
    }

    public function findRunningByLink($linkId)
    {
        $stmt = $this->db->prepare(
            'SELECT id, owner_id, owner_login, link_id, mode, status, items_json, total,
                    processed, created, updated, failed, error, created_at, updated_at
             FROM sync_jobs
             WHERE owner_id = :owner_id AND link_id = :link_id AND status IN ("queued", "running")
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':link_id' => (int) $linkId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalize($row) : null;
    }

    public function hasRunning()
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM sync_jobs
             WHERE owner_id = :owner_id AND status IN ("queued", "running")'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function markProgress($id, $processed, $created, $updated, $failed, $status, $error = null)
    {
        $stmt = $this->db->prepare(
            'UPDATE sync_jobs
             SET processed = :processed, created = :created, updated = :updated,
                 failed = :failed, status = :status, error = :error, updated_at = :updated_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':processed' => (int) $processed,
            ':created' => (int) $created,
            ':updated' => (int) $updated,
            ':failed' => (int) $failed,
            ':status' => $status,
            ':error' => $error,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function response(array $job)
    {
        $progress = $job['total'] > 0 ? (int) floor(($job['processed'] / $job['total']) * 100) : 100;

        return [
            'job' => [
                'id' => (int) $job['id'],
                'mode' => $job['mode'],
                'status' => $job['status'],
                'total' => (int) $job['total'],
                'processed' => (int) $job['processed'],
                'created' => (int) $job['created'],
                'updated' => (int) $job['updated'],
                'failed' => (int) $job['failed'],
                'progress' => min(100, max(0, $progress)),
                'error' => $job['error'],
            ],
        ];
    }

    private function normalize(array $row)
    {
        $row['items'] = json_decode($row['items_json'], true);
        if (!is_array($row['items'])) {
            $row['items'] = [];
        }

        foreach (['id', 'link_id', 'total', 'processed', 'created', 'updated', 'failed'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }

    private function addColumnIfMissing($name, $definition)
    {
        $columns = $this->db->query('PRAGMA table_info(sync_jobs)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return;
            }
        }

        $this->db->exec('ALTER TABLE sync_jobs ADD COLUMN ' . $name . ' ' . $definition);
    }

    private function getOwner()
    {
        try {
            $client = pm_Session::getClient();
            $id = method_exists($client, 'getId') ? (string) $client->getId() : '';
            $login = method_exists($client, 'getLogin') ? (string) $client->getLogin() : '';

            if ('' !== $id) {
                return ['id' => $id, 'login' => $login];
            }
        } catch (Exception $e) {
        }

        return ['id' => 'system', 'login' => 'system'];
    }

    private function getDbPath()
    {
        $varDir = pm_Context::getVarDir();
        if (!is_dir($varDir)) {
            mkdir($varDir, 0755, true);
        }

        return $varDir . DIRECTORY_SEPARATOR . 'cloudflare-pro.sqlite';
    }
}
