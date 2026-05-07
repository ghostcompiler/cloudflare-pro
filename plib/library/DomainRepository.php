<?php

class Modules_CloudflarePro_DomainRepository
{
    private $db;
    private $owner;

    public function __construct()
    {
        $this->db = new PDO('sqlite:' . $this->getDbPath());
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->owner = $this->getOwner();
        $this->init();
    }

    public function init()
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS domain_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id TEXT NOT NULL DEFAULT "",
                owner_login TEXT NOT NULL DEFAULT "",
                domain_id TEXT NOT NULL,
                domain_name TEXT NOT NULL,
                token_id INTEGER NOT NULL,
                token_name TEXT NOT NULL DEFAULT "",
                zone_id TEXT NOT NULL,
                zone_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "linked",
                auto_sync INTEGER NOT NULL DEFAULT 1,
                records_count INTEGER NOT NULL DEFAULT 0,
                last_synced_at TEXT,
                last_discovered_at TEXT,
                last_error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(owner_id, domain_id, token_id)
            )'
        );

        foreach ([
            'owner_id' => 'TEXT NOT NULL DEFAULT ""',
            'owner_login' => 'TEXT NOT NULL DEFAULT ""',
            'domain_id' => 'TEXT NOT NULL DEFAULT ""',
            'domain_name' => 'TEXT NOT NULL DEFAULT ""',
            'token_id' => 'INTEGER NOT NULL DEFAULT 0',
            'token_name' => 'TEXT NOT NULL DEFAULT ""',
            'zone_id' => 'TEXT NOT NULL DEFAULT ""',
            'zone_name' => 'TEXT NOT NULL DEFAULT ""',
            'status' => 'TEXT NOT NULL DEFAULT "linked"',
            'auto_sync' => 'INTEGER NOT NULL DEFAULT 1',
            'records_count' => 'INTEGER NOT NULL DEFAULT 0',
            'last_synced_at' => 'TEXT',
            'last_discovered_at' => 'TEXT',
            'last_error' => 'TEXT',
            'created_at' => 'TEXT',
            'updated_at' => 'TEXT',
        ] as $name => $definition) {
            $this->addColumnIfMissing($name, $definition);
        }
    }

    public function all()
    {
        $stmt = $this->db->prepare(
            'SELECT id, domain_id, domain_name, token_id, token_name, zone_id, zone_name,
                    status, auto_sync, records_count, last_synced_at, last_discovered_at, last_error, created_at, updated_at
             FROM domain_links
             WHERE owner_id = :owner_id
             ORDER BY domain_name ASC, token_name ASC'
        );
        $stmt->execute([':owner_id' => $this->owner['id']]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id)
    {
        $stmt = $this->db->prepare(
            'SELECT id, domain_id, domain_name, token_id, token_name, zone_id, zone_name,
                    status, auto_sync, records_count, last_synced_at, last_discovered_at, last_error, created_at, updated_at
             FROM domain_links
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new pm_Exception('Linked domain not found.');
        }

        return $row;
    }

    public function findByDomainName($domainName)
    {
        $stmt = $this->db->prepare(
            'SELECT id, domain_id, domain_name, token_id, token_name, zone_id, zone_name,
                    status, auto_sync, records_count, last_synced_at, last_discovered_at, last_error, created_at, updated_at
             FROM domain_links
             WHERE owner_id = :owner_id AND lower(domain_name) = lower(:domain_name)
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':domain_name' => strtolower(rtrim((string) $domainName, '.')),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function upsert(array $link)
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->findExisting($link['domain_id'], $link['token_id']);

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE domain_links
                 SET domain_name = :domain_name,
                     token_name = :token_name,
                     zone_id = :zone_id,
                     zone_name = :zone_name,
                     status = :status,
                     last_discovered_at = :last_discovered_at,
                     last_error = NULL,
                     updated_at = :updated_at
                 WHERE id = :id AND owner_id = :owner_id'
            );
            $stmt->execute([
                ':domain_name' => $link['domain_name'],
                ':token_name' => $link['token_name'],
                ':zone_id' => $link['zone_id'],
                ':zone_name' => $link['zone_name'],
                ':status' => isset($link['status']) ? $link['status'] : 'linked',
                ':last_discovered_at' => $now,
                ':updated_at' => $now,
                ':id' => (int) $existing['id'],
                ':owner_id' => $this->owner['id'],
            ]);

            return $this->find($existing['id']);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO domain_links (
                owner_id, owner_login, domain_id, domain_name, token_id, token_name,
                zone_id, zone_name, status, last_discovered_at, created_at, updated_at
            ) VALUES (
                :owner_id, :owner_login, :domain_id, :domain_name, :token_id, :token_name,
                :zone_id, :zone_name, :status, :last_discovered_at, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
            ':domain_id' => (string) $link['domain_id'],
            ':domain_name' => $link['domain_name'],
            ':token_id' => (int) $link['token_id'],
            ':token_name' => $link['token_name'],
            ':zone_id' => $link['zone_id'],
            ':zone_name' => $link['zone_name'],
            ':status' => isset($link['status']) ? $link['status'] : 'linked',
            ':last_discovered_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->find((int) $this->db->lastInsertId());
    }

    public function markSynced($id, $recordsCount)
    {
        $stmt = $this->db->prepare(
            'UPDATE domain_links
             SET status = :status, records_count = :records_count, last_synced_at = :last_synced_at,
                 last_error = NULL, updated_at = :updated_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':status' => 'synced',
            ':records_count' => (int) $recordsCount,
            ':last_synced_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function markError($id, $message)
    {
        $stmt = $this->db->prepare(
            'UPDATE domain_links
             SET status = :status, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':status' => 'error',
            ':last_error' => substr((string) $message, 0, 1000),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function setAutoSync($id, $enabled)
    {
        $stmt = $this->db->prepare(
            'UPDATE domain_links
             SET auto_sync = :auto_sync, updated_at = :updated_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':auto_sync' => $enabled ? 1 : 0,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        if (0 === $stmt->rowCount()) {
            throw new pm_Exception('Linked domain not found.');
        }

        return $this->find($id);
    }

    public function removeByToken($tokenId)
    {
        $stmt = $this->db->prepare(
            'DELETE FROM domain_links WHERE token_id = :token_id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':token_id' => (int) $tokenId,
            ':owner_id' => $this->owner['id'],
        ]);
    }

    public function keepOnlyTokenIds(array $tokenIds)
    {
        $tokenIds = array_values(array_filter(array_map('intval', $tokenIds)));
        if (!$tokenIds) {
            $stmt = $this->db->prepare('DELETE FROM domain_links WHERE owner_id = :owner_id');
            $stmt->execute([':owner_id' => $this->owner['id']]);

            return;
        }

        $placeholders = implode(',', array_fill(0, count($tokenIds), '?'));
        $stmt = $this->db->prepare(
            'DELETE FROM domain_links WHERE owner_id = ? AND token_id NOT IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$this->owner['id']], $tokenIds));
    }

    public function keepOnlyDomainIds(array $domainIds)
    {
        $domainIds = array_values(array_filter(array_map('strval', $domainIds), 'strlen'));
        if (!$domainIds) {
            $stmt = $this->db->prepare('DELETE FROM domain_links WHERE owner_id = :owner_id');
            $stmt->execute([':owner_id' => $this->owner['id']]);

            return;
        }

        $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
        $stmt = $this->db->prepare(
            'DELETE FROM domain_links WHERE owner_id = ? AND domain_id NOT IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$this->owner['id']], $domainIds));
    }

    private function findExisting($domainId, $tokenId)
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM domain_links
             WHERE owner_id = :owner_id AND domain_id = :domain_id AND token_id = :token_id'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':domain_id' => (string) $domainId,
            ':token_id' => (int) $tokenId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function addColumnIfMissing($name, $definition)
    {
        $columns = $this->db->query('PRAGMA table_info(domain_links)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return;
            }
        }

        $this->db->exec('ALTER TABLE domain_links ADD COLUMN ' . $name . ' ' . $definition);
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
