<?php

class CloudflarePro_DomainSyncService
{
    private $cloudflare;
    private $tokens;
    private $domains;
    private $settings;
    private $plesk;

    public function __construct(array $owner = null)
    {
        $this->cloudflare = new CloudflarePro_CloudflareClient(new Modules_CloudflarePro_ApiLogRepository($owner));
        $this->tokens = new Modules_CloudflarePro_TokenRepository($owner);
        $this->domains = new Modules_CloudflarePro_DomainRepository($owner);
        $this->settings = new Modules_CloudflarePro_SettingsRepository($owner);
        $this->plesk = new CloudflarePro_PleskDnsService();
    }

    public static function autoSyncHost($hostName, $sourceDomainName = null, $attempts = 1)
    {
        $links = (new Modules_CloudflarePro_DomainRepository(['id' => 'system', 'login' => 'system']))
            ->findAutosyncLinksForHostAllOwners($hostName);
        $results = [];

        if (!$links) {
            error_log('Cloudflare Pro autosync skipped: no linked zone for ' . $hostName);

            return $results;
        }

        foreach ($links as $link) {
            $owner = [
                'id' => $link['owner_id'],
                'login' => $link['owner_login'],
            ];

            try {
                $settings = (new Modules_CloudflarePro_SettingsRepository($owner))->all();
                if (empty($settings['enable_autosync'])) {
                    continue;
                }

                $service = new self($owner);
                $lastError = null;
                for ($attempt = 1; $attempt <= max(1, (int) $attempts); $attempt++) {
                    try {
                        $result = $service->syncLinkIncremental($link['id'], $sourceDomainName);
                        if (empty($result['empty_source'])) {
                            $results[] = $result;
                            break;
                        }
                    } catch (Throwable $e) {
                        $lastError = $e;
                        if ($attempt === max(1, (int) $attempts)) {
                            throw $e;
                        }
                    }

                    sleep(1);
                }

                if ($lastError) {
                    throw $lastError;
                }
            } catch (Throwable $e) {
                error_log('Cloudflare Pro autosync failed for ' . $hostName . ': ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function linkedDomains()
    {
        $pleskDomains = $this->accessibleDomainMap();
        $tokens = $this->tokens->activeWithSecrets();

        $this->domains->keepOnlyDomainIds(array_keys($pleskDomains));
        $this->domains->keepOnlyTokenIds(array_map(function ($token) {
            return $token['id'];
        }, $tokens));

        foreach ($tokens as $token) {
            try {
                foreach ($this->cloudflare->listZones($token['secret']) as $zone) {
                    $zoneName = strtolower(rtrim(isset($zone['name']) ? $zone['name'] : '', '.'));
                    if (!isset($pleskDomains[$zoneName])) {
                        continue;
                    }

                    $domain = $pleskDomains[$zoneName];
                    $this->domains->upsert([
                        'domain_id' => (string) $domain->getId(),
                        'domain_name' => $zoneName,
                        'token_id' => (int) $token['id'],
                        'token_name' => $token['name'],
                        'zone_id' => (string) $zone['id'],
                        'zone_name' => $zoneName,
                        'status' => isset($zone['status']) ? (string) $zone['status'] : 'linked',
                    ]);
                }
            } catch (Throwable $e) {
                error_log('Cloudflare Pro domain discovery failed: ' . $e->getMessage());
            }
        }

        return $this->domains->all();
    }

    public function syncLink($linkId)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $settings = $this->settings->all();

        if (!empty($settings['validate_token_before_sync'])) {
            $this->cloudflare->verifyToken($token);
        }

        try {
            $records = array_values(array_filter(array_map(function ($record) {
                return $this->cloudflareRecord($record);
            }, $this->plesk->recordsForDomainId($link['domain_id']))));

            $existing = $this->cloudflare->listDnsRecords($token, $link['zone_id']);
            $deleted = 0;
            foreach ($existing as $record) {
                if (empty($record['id']) || !$this->shouldReplace($record)) {
                    continue;
                }
                $this->cloudflare->deleteDnsRecord($token, $link['zone_id'], $record['id']);
                $deleted++;
            }

            $created = 0;
            foreach ($records as $record) {
                $this->cloudflare->createDnsRecord($token, $link['zone_id'], $record);
                $created++;
            }

            $this->domains->markSynced($link['id'], $created);

            return [
                'created' => $created,
                'deleted' => $deleted,
            ];
        } catch (Throwable $e) {
            $this->domains->markError($link['id'], $e->getMessage());
            throw $e;
        }
    }

    public function queueItemsForLink($linkId)
    {
        $link = $this->domains->find($linkId);
        $records = array_values(array_filter(array_map(function ($record) {
            return $this->cloudflareRecord($record);
        }, $this->plesk->recordsForDomainId($link['domain_id']))));

        return $records;
    }

    public function queueImportItemsForLink($linkId)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);

        return array_values(array_filter(array_map(function ($record) use ($link) {
            $payload = $this->normalizeCloudflareRecord($record);
            return $payload && $this->shouldReplace($payload) && $this->recordBelongsToZone($payload, $link['zone_name']) ? $payload : null;
        }, $this->cloudflare->listDnsRecords($token, $link['zone_id']))));
    }

    public function processSyncQueueBatch($linkId, array $items)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $created = 0;
        $updated = 0;

        $existing = [];
        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            $normalized = $this->normalizeCloudflareRecord($record);
            if (!$this->shouldReplace($normalized) || empty($record['id'])) {
                continue;
            }
            $existing[$this->nameTypeKey($normalized)] = $record;
        }

        foreach ($items as $record) {
            if (!$this->recordBelongsToZone($record, $link['zone_name'])) {
                continue;
            }

            $key = $this->nameTypeKey($record);
            if (isset($existing[$key]) && !empty($existing[$key]['id'])) {
                $this->cloudflare->updateDnsRecord($token, $link['zone_id'], $existing[$key]['id'], $record);
                $updated++;
            } else {
                $createdRecord = $this->cloudflare->createDnsRecord($token, $link['zone_id'], $record);
                if (isset($createdRecord['result']) && is_array($createdRecord['result'])) {
                    $existing[$key] = $createdRecord['result'];
                }
                $created++;
            }
        }

        $this->domains->markSynced($link['id'], $created + $updated);

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function processImportQueueBatch($linkId, array $items)
    {
        $link = $this->domains->find($linkId);
        $created = 0;
        $updated = 0;

        foreach ($items as $record) {
            if (!$this->recordBelongsToZone($record, $link['zone_name'])) {
                continue;
            }

            $removed = $this->plesk->removeByNameType($link['domain_id'], $record['name'], $record['type']);
            $this->plesk->createRecordForDomainId($link['domain_id'], $record);
            if ($removed > 0) {
                $updated++;
            } else {
                $created++;
            }
        }

        $this->domains->markSynced($link['id'], $created + $updated);

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function syncLinkIncremental($linkId, $sourceDomainName = null)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $settings = $this->settings->all();

        if (empty($settings['enable_autosync']) || empty($link['auto_sync'])) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => true,
            ];
        }

        if (!empty($settings['validate_token_before_sync'])) {
            $this->cloudflare->verifyToken($token);
        }

        try {
            $sourceRecords = $this->recordsFromSource($link, $sourceDomainName);
            $records = array_values(array_filter(array_map(function ($record) use ($settings, $link) {
                $payload = $this->cloudflareRecord($record, $settings, true);
                return $payload && $this->recordBelongsToZone($payload, $link['zone_name']) ? $payload : null;
            }, $sourceRecords)));

            if (!$records) {
                error_log('Cloudflare Pro autosync found no source DNS records for ' . ($sourceDomainName ?: $link['domain_name']));

                return [
                    'created' => 0,
                    'updated' => 0,
                    'empty_source' => true,
                ];
            }

            $existing = [];
            foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
                $normalized = $this->normalizeCloudflareRecord($record);
                if (!$this->shouldReplace($normalized) || empty($record['id'])) {
                    continue;
                }
                $existing[$this->nameTypeKey($normalized)] = $record;
            }

            $created = 0;
            $updated = 0;
            foreach ($records as $record) {
                $key = $this->nameTypeKey($record);
                if (isset($existing[$key])) {
                    $this->cloudflare->updateDnsRecord($token, $link['zone_id'], $existing[$key]['id'], $record);
                    $updated++;
                } else {
                    $this->cloudflare->createDnsRecord($token, $link['zone_id'], $record);
                    $created++;
                }
            }

            $this->domains->markSynced($link['id'], $created + $updated);
            error_log('Cloudflare Pro autosync completed for ' . ($sourceDomainName ?: $link['domain_name']) . ': created=' . $created . ', updated=' . $updated);

            return [
                'created' => $created,
                'updated' => $updated,
            ];
        } catch (Throwable $e) {
            $this->domains->markError($link['id'], $e->getMessage());
            throw $e;
        }
    }

    public function recordsForLink($linkId)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $localRecords = array_values(array_filter(array_map(function ($record) {
            return $this->cloudflareRecord($record);
        }, $this->plesk->recordsForDomainId($link['domain_id']))));
        $cloudflareRecords = array_values(array_filter(array_map(function ($record) {
            return $this->normalizeCloudflareRecord($record);
        }, $this->cloudflare->listDnsRecords($token, $link['zone_id']))));

        $cloudflareByNameType = [];
        foreach ($cloudflareRecords as $record) {
            if (!$this->shouldReplace($record)) {
                continue;
            }
            $cloudflareByNameType[$this->nameTypeKey($record)][] = $record;
        }

        $rows = [];
        $matched = [];
        foreach ($localRecords as $local) {
            $key = $this->nameTypeKey($local);
            $cloudflare = isset($cloudflareByNameType[$key][0]) ? $cloudflareByNameType[$key][0] : null;
            if ($cloudflare) {
                $matched[$this->recordKey($cloudflare)] = true;
            }

            $rows[] = $this->recordRow($local, $cloudflare, $cloudflare ? ($this->recordsMatch($local, $cloudflare) ? 'synced' : 'mismatch') : 'not_synced');
        }

        foreach ($cloudflareRecords as $cloudflare) {
            if (!$this->shouldReplace($cloudflare)) {
                continue;
            }
            if (isset($matched[$this->recordKey($cloudflare)])) {
                continue;
            }
            if (isset($cloudflareByNameType[$this->nameTypeKey($cloudflare)][0])) {
                $first = $cloudflareByNameType[$this->nameTypeKey($cloudflare)][0];
                if ($this->recordKey($first) !== $this->recordKey($cloudflare)) {
                    continue;
                }
            }

            $rows[] = $this->recordRow(null, $cloudflare, 'cloudflare_only');
        }

        usort($rows, function ($left, $right) {
            $type = strcmp($left['type'], $right['type']);
            if (0 !== $type) {
                return $type;
            }

            return strcmp($left['name'], $right['name']);
        });

        return [
            'domain' => $link,
            'records' => $rows,
        ];
    }

    public function setRecordProxy($linkId, $recordId, $proxied)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $target = null;

        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            if (isset($record['id']) && (string) $record['id'] === (string) $recordId) {
                $target = $record;
                break;
            }
        }

        if (!$target) {
            throw new pm_Exception('Cloudflare DNS record not found.');
        }

        $type = strtoupper(isset($target['type']) ? $target['type'] : '');
        if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            throw new pm_Exception('Proxy can be changed only for A, AAAA, and CNAME records.');
        }

        $this->cloudflare->updateDnsRecord($token, $link['zone_id'], $recordId, [
            'proxied' => (bool) $proxied,
        ]);

        return $this->recordsForLink($linkId);
    }

    public function pushRecord($linkId, $recordKey)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $target = null;

        foreach ($this->plesk->recordsForDomainId($link['domain_id']) as $record) {
            $payload = $this->cloudflareRecord($record);
            if ($payload && $this->recordKey($payload) === $recordKey) {
                $target = $payload;
                break;
            }
        }

        if (!$target) {
            throw new pm_Exception('Local DNS record not found.');
        }

        $existing = null;
        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            $normalized = $this->normalizeCloudflareRecord($record);
            if ($this->nameTypeKey($normalized) === $this->nameTypeKey($target)) {
                $existing = $record;
                break;
            }
        }

        if ($existing && !empty($existing['id'])) {
            $this->cloudflare->updateDnsRecord($token, $link['zone_id'], $existing['id'], $target);
        } else {
            $this->cloudflare->createDnsRecord($token, $link['zone_id'], $target);
        }

        $this->domains->markSynced($link['id'], 1);

        return $this->recordsForLink($linkId);
    }

    public function pullRecord($linkId, $recordKey)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $target = null;

        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            $normalized = $this->normalizeCloudflareRecord($record);
            if ($this->recordKey($normalized) === $recordKey) {
                $target = $normalized;
                break;
            }
        }

        if (!$target) {
            throw new pm_Exception('Cloudflare DNS record not found.');
        }

        $this->plesk->removeByNameType($link['domain_id'], $target['name'], $target['type']);
        $this->plesk->createRecordForDomainId($link['domain_id'], $target);

        $this->domains->markSynced($link['id'], 1);

        return $this->recordsForLink($linkId);
    }

    public function deleteRecord($linkId, $recordKey)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $target = null;

        foreach ($this->plesk->recordsForDomainId($link['domain_id']) as $record) {
            $payload = $this->cloudflareRecord($record);
            if ($payload && $this->recordKey($payload) === $recordKey) {
                $target = $payload;
                break;
            }
        }

        if (!$target) {
            foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
                $normalized = $this->normalizeCloudflareRecord($record);
                if ($this->recordKey($normalized) === $recordKey) {
                    $target = $normalized;
                    break;
                }
            }
        }

        if (!$target) {
            throw new pm_Exception('DNS record not found.');
        }

        $this->plesk->removeByNameType($link['domain_id'], $target['name'], $target['type']);

        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            if (empty($record['id'])) {
                continue;
            }

            $normalized = $this->normalizeCloudflareRecord($record);
            if ($this->nameTypeKey($normalized) === $this->nameTypeKey($target)) {
                $this->cloudflare->deleteDnsRecord($token, $link['zone_id'], $record['id']);
            }
        }

        $this->domains->markSynced($link['id'], 1);

        return $this->recordsForLink($linkId);
    }

    private function accessibleDomainMap()
    {
        $domains = [];
        foreach (CloudflarePro_Permissions::accessibleDomains() as $domain) {
            $domains[strtolower(rtrim($domain->getName(), '.'))] = $domain;
        }

        return $domains;
    }

    private function recordsFromSource(array $link, $sourceDomainName = null)
    {
        if ($sourceDomainName) {
            try {
                return $this->plesk->recordsForDomainName($sourceDomainName);
            } catch (Throwable $e) {
                error_log('Cloudflare Pro source DNS lookup failed for ' . $sourceDomainName . ': ' . $e->getMessage());
            }
        }

        return $this->plesk->recordsForDomainId($link['domain_id']);
    }

    private function recordBelongsToZone(array $record, $zoneName)
    {
        $name = strtolower(rtrim(isset($record['name']) ? $record['name'] : '', '.'));
        $zoneName = strtolower(rtrim((string) $zoneName, '.'));

        return $name === $zoneName || substr($name, -strlen('.' . $zoneName)) === '.' . $zoneName;
    }

    private function cloudflareRecord($record, array $settings = [], $applyProxyDefaults = false)
    {
        $type = strtoupper($record['type']);
        if (!$this->shouldReplace(['type' => $type])) {
            return null;
        }

        $payload = [
            'type' => $type,
            'name' => $record['name'],
            'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : 1,
        ];

        if (isset($record['data'])) {
            $payload['data'] = $record['data'];
        } else {
            $payload['content'] = isset($record['content']) ? $record['content'] : '';
        }

        if ('MX' === $type && isset($record['priority'])) {
            $payload['priority'] = (int) $record['priority'];
        }

        if ($applyProxyDefaults && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = !empty($settings['proxy_' . strtolower($type)]);
        }

        return $payload;
    }

    private function shouldReplace(array $record)
    {
        $type = strtoupper(isset($record['type']) ? $record['type'] : '');

        return in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'PTR', 'CAA', 'DS', 'DNSKEY'], true);
    }

    private function normalizeCloudflareRecord(array $record)
    {
        $type = strtoupper(isset($record['type']) ? $record['type'] : '');
        $normalized = [
            'id' => isset($record['id']) ? (string) $record['id'] : '',
            'type' => $type,
            'name' => strtolower(rtrim(isset($record['name']) ? $record['name'] : '', '.')),
            'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : 1,
        ];

        if (isset($record['content'])) {
            $normalized['content'] = $this->normalizeContent($type, $record['content']);
        }
        if (isset($record['priority'])) {
            $normalized['priority'] = (int) $record['priority'];
        }
        if (array_key_exists('proxied', $record)) {
            $normalized['proxied'] = (bool) $record['proxied'];
        }
        if (isset($record['data']) && is_array($record['data'])) {
            $normalized['data'] = $record['data'];
        }

        return $normalized;
    }

    private function recordsMatch(array $local, array $cloudflare)
    {
        foreach (['type', 'name', 'content', 'priority'] as $field) {
            if ((isset($local[$field]) ? $local[$field] : null) != (isset($cloudflare[$field]) ? $cloudflare[$field] : null)) {
                return false;
            }
        }

        if (isset($local['data']) || isset($cloudflare['data'])) {
            if (json_encode(isset($local['data']) ? $local['data'] : null) !== json_encode(isset($cloudflare['data']) ? $cloudflare['data'] : null)) {
                return false;
            }
        }

        return true;
    }

    private function recordRow($local, $cloudflare, $status)
    {
        $record = $local ?: $cloudflare;

        return [
            'type' => $record['type'],
            'name' => $record['name'],
            'status' => $status,
            'local_content' => $this->displayContent($local),
            'cloudflare_content' => $this->displayContent($cloudflare),
            'local_ttl' => $local && isset($local['ttl']) ? $local['ttl'] : null,
            'cloudflare_ttl' => $cloudflare && isset($cloudflare['ttl']) ? $cloudflare['ttl'] : null,
            'local_proxied' => $local && array_key_exists('proxied', $local) ? (bool) $local['proxied'] : null,
            'cloudflare_proxied' => $cloudflare && array_key_exists('proxied', $cloudflare) ? (bool) $cloudflare['proxied'] : null,
            'cloudflare_id' => $cloudflare && isset($cloudflare['id']) ? $cloudflare['id'] : '',
            'can_proxy' => in_array($record['type'], ['A', 'AAAA', 'CNAME'], true) && $cloudflare && !empty($cloudflare['id']),
            'local_key' => $local ? $this->recordKey($local) : '',
            'cloudflare_key' => $cloudflare ? $this->recordKey($cloudflare) : '',
            'has_local' => (bool) $local,
            'has_cloudflare' => (bool) $cloudflare,
        ];
    }

    private function displayContent($record)
    {
        if (!$record) {
            return '-';
        }
        if (isset($record['data'])) {
            return json_encode($record['data'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($record['priority'])) {
            return (string) $record['priority'] . ' ' . (isset($record['content']) ? $record['content'] : '');
        }

        return isset($record['content']) ? (string) $record['content'] : '';
    }

    private function recordKey(array $record)
    {
        return $this->nameTypeKey($record) . '|' . $this->displayContent($record);
    }

    private function nameTypeKey(array $record)
    {
        return strtoupper(isset($record['type']) ? $record['type'] : '') . '|' . strtolower(rtrim(isset($record['name']) ? $record['name'] : '', '.'));
    }

    private function normalizeContent($type, $content)
    {
        $content = trim((string) $content);
        if (in_array($type, ['CNAME', 'MX', 'PTR'], true)) {
            return strtolower(rtrim($content, '.'));
        }

        return $content;
    }
}
