<?php

class Modules_CloudflarePro_TokenRepository
{
    private $db;
    private $owner;

    public function __construct()
    {
        $dbPath = $this->getDbPath();
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->owner = $this->getOwner();
        $this->init();
    }

    public function init()
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id TEXT NOT NULL DEFAULT "",
                owner_login TEXT NOT NULL DEFAULT "",
                name TEXT NOT NULL,
                token TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "inactive",
                cloudflare_token_id TEXT,
                expires_on TEXT,
                not_before TEXT,
                last_verified_at TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $this->addColumnIfMissing('owner_id', 'TEXT NOT NULL DEFAULT ""');
        $this->addColumnIfMissing('owner_login', 'TEXT NOT NULL DEFAULT ""');
        $this->addColumnIfMissing('status', 'TEXT NOT NULL DEFAULT "inactive"');
        $this->addColumnIfMissing('cloudflare_token_id', 'TEXT');
        $this->addColumnIfMissing('expires_on', 'TEXT');
        $this->addColumnIfMissing('not_before', 'TEXT');
        $this->addColumnIfMissing('last_verified_at', 'TEXT');
        $this->claimLegacyTokens();
    }

    public function add($name, $token, array $verification = [])
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO tokens (
                owner_id, owner_login, name, token, status, cloudflare_token_id,
                expires_on, not_before, last_verified_at, created_at
            ) VALUES (
                :owner_id, :owner_login, :name, :token, :status, :cloudflare_token_id,
                :expires_on, :not_before, :last_verified_at, :created_at
            )'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
            ':name' => $name,
            ':token' => pm_Crypt::encrypt($token),
            ':status' => $this->statusFromVerification($verification),
            ':cloudflare_token_id' => isset($verification['id']) ? $verification['id'] : null,
            ':expires_on' => isset($verification['expires_on']) ? $verification['expires_on'] : null,
            ':not_before' => isset($verification['not_before']) ? $verification['not_before'] : null,
            ':last_verified_at' => $now,
            ':created_at' => $now,
        ]);

        return $this->find((int) $this->db->lastInsertId());
    }

    public function all()
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, status, cloudflare_token_id, expires_on, not_before, last_verified_at, created_at
             FROM tokens WHERE owner_id = :owner_id ORDER BY id DESC'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeWithSecrets()
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, token, status
             FROM tokens WHERE owner_id = :owner_id AND status = :status ORDER BY id DESC'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':status' => 'active',
        ]);

        $tokens = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['secret'] = pm_Crypt::decrypt($row['token']);
            unset($row['token']);
            $tokens[] = $row;
        }

        return $tokens;
    }

    public function find($id)
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, status, cloudflare_token_id, expires_on, not_before, last_verified_at, created_at
             FROM tokens WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new pm_Exception('Token not found.');
        }

        return $row;
    }

    public function secret($id)
    {
        $stmt = $this->db->prepare(
            'SELECT token FROM tokens WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        $encrypted = $stmt->fetchColumn();
        if (!$encrypted) {
            throw new pm_Exception('Token not found.');
        }

        return pm_Crypt::decrypt($encrypted);
    }

    public function updateName($id, $name)
    {
        $stmt = $this->db->prepare(
            'UPDATE tokens SET name = :name WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':name' => $name,
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function updateToken($id, $name, $token, array $verification)
    {
        $stmt = $this->db->prepare(
            'UPDATE tokens
             SET name = :name,
                 token = :token,
                 status = :status,
                 cloudflare_token_id = :cloudflare_token_id,
                 expires_on = :expires_on,
                 not_before = :not_before,
                 last_verified_at = :last_verified_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':name' => $name,
            ':token' => pm_Crypt::encrypt($token),
            ':status' => $this->statusFromVerification($verification),
            ':cloudflare_token_id' => isset($verification['id']) ? $verification['id'] : null,
            ':expires_on' => isset($verification['expires_on']) ? $verification['expires_on'] : null,
            ':not_before' => isset($verification['not_before']) ? $verification['not_before'] : null,
            ':last_verified_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function markValidated($id, array $verification)
    {
        $stmt = $this->db->prepare(
            'UPDATE tokens
             SET status = :status,
                 cloudflare_token_id = :cloudflare_token_id,
                 expires_on = :expires_on,
                 not_before = :not_before,
                 last_verified_at = :last_verified_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':status' => $this->statusFromVerification($verification),
            ':cloudflare_token_id' => isset($verification['id']) ? $verification['id'] : null,
            ':expires_on' => isset($verification['expires_on']) ? $verification['expires_on'] : null,
            ':not_before' => isset($verification['not_before']) ? $verification['not_before'] : null,
            ':last_verified_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function markInvalid($id)
    {
        $stmt = $this->db->prepare(
            'UPDATE tokens SET status = :status, last_verified_at = :last_verified_at
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':status' => 'invalid',
            ':last_verified_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        return $this->find($id);
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tokens WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            ':id' => (int) $id,
            ':owner_id' => $this->owner['id'],
        ]);

        if (0 === $stmt->rowCount()) {
            throw new pm_Exception('Token not found.');
        }
    }

    private function addColumnIfMissing($name, $definition)
    {
        $stmt = $this->db->query('PRAGMA table_info(tokens)');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return;
            }
        }

        $this->db->exec('ALTER TABLE tokens ADD COLUMN ' . $name . ' ' . $definition);
    }

    private function claimLegacyTokens()
    {
        $stmt = $this->db->prepare(
            'UPDATE tokens SET owner_id = :owner_id, owner_login = :owner_login WHERE owner_id = ""'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
        ]);
    }

    private function statusFromVerification(array $verification)
    {
        $status = isset($verification['status']) ? strtolower((string) $verification['status']) : 'active';

        if (in_array($status, ['active', 'success'], true)) {
            return 'active';
        }

        if (in_array($status, ['pending', 'warning'], true)) {
            return 'warning';
        }

        if (in_array($status, ['disabled', 'expired', 'revoked', 'invalid'], true)) {
            return 'invalid';
        }

        return 'inactive';
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
        } catch (Exception $e) {
        }

        return [
            'id' => 'system',
            'login' => 'system',
        ];
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
