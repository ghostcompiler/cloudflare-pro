<?php

class Modules_CloudflarePro_SettingsRepository
{
    private $db;
    private $owner;

    private $defaults = [
        'enable_autosync' => true,
        'remove_records_on_domain_delete' => true,
        'proxy_a' => true,
        'proxy_aaaa' => true,
        'proxy_cname' => true,
        'log_api_requests' => true,
        'validate_token_before_sync' => true,
    ];

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
            'CREATE TABLE IF NOT EXISTS user_settings (
                owner_id TEXT PRIMARY KEY,
                owner_login TEXT NOT NULL DEFAULT "",
                settings_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->migrateLegacySettings();
    }

    public function all()
    {
        $settings = $this->defaults;

        $stmt = $this->db->prepare(
            'SELECT settings_json FROM user_settings WHERE owner_id = :owner_id'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
        ]);

        $json = $stmt->fetchColumn();
        if ($json) {
            $saved = json_decode((string) $json, true);
            if (is_array($saved)) {
                foreach ($saved as $key => $value) {
                    if (array_key_exists($key, $settings)) {
                        $settings[$key] = (bool) $value;
                    }
                }
            }
        }

        return $settings;
    }

    public function save(array $values)
    {
        $settings = [];
        foreach ($this->defaults as $key => $default) {
            $settings[$key] = !empty($values[$key]);
        }

        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO user_settings (owner_id, owner_login, settings_json, updated_at)
             VALUES (:owner_id, :owner_login, :settings_json, :updated_at)'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
            ':settings_json' => json_encode($settings, JSON_UNESCAPED_SLASHES),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->all();
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

    private function migrateLegacySettings()
    {
        $legacyExists = $this->db
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'settings'")
            ->fetchColumn();

        if (!$legacyExists) {
            return;
        }

        $existing = $this->db->prepare(
            'SELECT owner_id FROM user_settings WHERE owner_id = :owner_id'
        );
        $existing->execute([
            ':owner_id' => $this->owner['id'],
        ]);

        if ($existing->fetchColumn()) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT setting_key, setting_value FROM settings WHERE owner_id = :owner_id'
        );
        $stmt->execute([
            ':owner_id' => $this->owner['id'],
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }

        $settings = $this->defaults;
        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = '1' === (string) $row['setting_value'];
            }
        }

        $save = $this->db->prepare(
            'INSERT OR REPLACE INTO user_settings (owner_id, owner_login, settings_json, updated_at)
             VALUES (:owner_id, :owner_login, :settings_json, :updated_at)'
        );
        $save->execute([
            ':owner_id' => $this->owner['id'],
            ':owner_login' => $this->owner['login'],
            ':settings_json' => json_encode($settings, JSON_UNESCAPED_SLASHES),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
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
