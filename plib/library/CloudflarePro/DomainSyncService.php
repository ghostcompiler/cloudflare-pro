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
                        $result = $service->syncLinkIncremental($link['id'], $sourceDomainName, $hostName);
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

    public static function autoDeleteHost($hostName)
    {
        $hostName = strtolower(rtrim((string) $hostName, '.'));
        $links = (new Modules_CloudflarePro_DomainRepository(['id' => 'system', 'login' => 'system']))
            ->findLinksForHostAllOwners($hostName);
        $results = [];

        if (!$links) {
            error_log('Cloudflare Pro auto delete skipped: no linked zone for ' . $hostName);

            return $results;
        }

        foreach ($links as $link) {
            $owner = [
                'id' => $link['owner_id'],
                'login' => $link['owner_login'],
            ];

            try {
                $settings = (new Modules_CloudflarePro_SettingsRepository($owner))->all();
                if (empty($settings['remove_records_on_domain_delete'])) {
                    continue;
                }

                $service = new self($owner);
                $results[] = $service->deleteHostRecords($link['id'], $hostName);
            } catch (Throwable $e) {
                error_log('Cloudflare Pro auto delete failed for ' . $hostName . ': ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function linkedDomains()
    {
        $pleskDomains = $this->accessibleDomainMap();
        $tokens = $this->tokens->activeWithSecrets();

        $this->domains->keepOnlyDomainIds(array_map(function ($domain) {
            return (string) $domain->getId();
        }, $pleskDomains));
        $this->domains->keepOnlyTokenIds(array_map(function ($token) {
            return $token['id'];
        }, $tokens));

        $this->cloudflare->withoutLogging(function () use ($tokens, $pleskDomains) {
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
        });

        return $this->domains->all();
    }

    public function storedDomains()
    {
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
        $settings = $this->settings->all();
        $records = array_values(array_filter(array_map(function ($record) use ($settings) {
            return $this->cloudflareRecord($record, $settings, true);
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
        $failed = 0;
        $error = null;

        $existing = [];
        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            $normalized = $this->normalizeCloudflareRecord($record);
            if (!$this->shouldReplace($normalized) || empty($record['id'])) {
                continue;
            }
            $existing[$this->nameTypeKey($normalized)] = $record;
        }

        foreach ($items as $record) {
            try {
                if (!$this->recordBelongsToZone($record, $link['zone_name'])) {
                    continue;
                }

                $key = $this->nameTypeKey($record);
                if (isset($existing[$key]) && !empty($existing[$key]['id'])) {
                    $candidate = $this->recordForUpdate($record, $existing[$key]);
                    if ($this->recordsMatch($candidate, $this->normalizeCloudflareRecord($existing[$key]))) {
                        continue;
                    }

                    $this->cloudflare->updateDnsRecord(
                        $token,
                        $link['zone_id'],
                        $existing[$key]['id'],
                        $candidate
                    );
                    $updated++;
                } else {
                    $createdRecord = $this->cloudflare->createDnsRecord($token, $link['zone_id'], $record);
                    if (isset($createdRecord['result']) && is_array($createdRecord['result'])) {
                        $existing[$key] = $createdRecord['result'];
                    }
                    $created++;
                }
            } catch (Throwable $e) {
                $failed++;
                $error = $e->getMessage();
            }
        }

        $this->domains->markSynced($link['id'], $created + $updated);

        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'error' => $error,
        ];
    }

    public function processImportQueueBatch($linkId, array $items)
    {
        $link = $this->domains->find($linkId);
        $created = 0;
        $updated = 0;
        $failed = 0;
        $error = null;

        foreach ($items as $record) {
            try {
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
            } catch (Throwable $e) {
                $failed++;
                $error = $e->getMessage();
            }
        }

        $this->domains->markSynced($link['id'], $created + $updated);

        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'error' => $error,
        ];
    }

    public function syncLinkIncremental($linkId, $sourceDomainName = null, $targetHostName = null)
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
            $records = $this->appendWwwSubdomainRecords($records, $settings, $link['zone_name'], $targetHostName);

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
                    $candidate = $this->recordForUpdate($record, $existing[$key]);
                    if ($this->recordsMatch($candidate, $this->normalizeCloudflareRecord($existing[$key]))) {
                        continue;
                    }

                    $this->cloudflare->updateDnsRecord(
                        $token,
                        $link['zone_id'],
                        $existing[$key]['id'],
                        $candidate
                    );
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
        $cloudflareRecords = $this->cloudflare->withoutLogging(function () use ($token, $link) {
            return array_values(array_filter(array_map(function ($record) {
                return $this->normalizeCloudflareRecord($record);
            }, $this->cloudflare->listDnsRecords($token, $link['zone_id']))));
        });

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
        $settings = $this->settings->all();
        $target = null;

        foreach ($this->plesk->recordsForDomainId($link['domain_id']) as $record) {
            $payload = $this->cloudflareRecord($record, $settings, true);
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
            $this->cloudflare->updateDnsRecord(
                $token,
                $link['zone_id'],
                $existing['id'],
                $this->recordForUpdate($target, $existing)
            );
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

    public function deleteHostRecords($linkId, $hostName)
    {
        $link = $this->domains->find($linkId);
        $token = $this->tokens->secret($link['token_id']);
        $settings = $this->settings->all();
        $hostName = strtolower(rtrim((string) $hostName, '.'));
        $deleted = 0;

        if ($hostName === strtolower(rtrim((string) $link['domain_name'], '.')) ||
            $hostName === strtolower(rtrim((string) $link['zone_name'], '.'))) {
            error_log('Cloudflare Pro auto delete skipped for apex host ' . $hostName . ' to avoid deleting records after an ambiguous Plesk event.');

            return [
                'deleted' => 0,
                'skipped' => true,
            ];
        }

        foreach ($this->cloudflare->listDnsRecords($token, $link['zone_id']) as $record) {
            if (empty($record['id'])) {
                continue;
            }

            $normalized = $this->normalizeCloudflareRecord($record);
            if (!$this->shouldReplace($normalized) || !$this->recordMatchesDeletedHost($normalized, $hostName, $settings)) {
                continue;
            }

            $this->cloudflare->deleteDnsRecord($token, $link['zone_id'], $record['id']);
            $deleted++;
        }

        $this->domains->markSynced($link['id'], 0);
        error_log('Cloudflare Pro auto delete completed for ' . $hostName . ': deleted=' . $deleted);

        return [
            'deleted' => $deleted,
        ];
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

    private function appendWwwSubdomainRecords(array $records, array $settings, $zoneName, $targetHostName)
    {
        if (empty($settings['create_www_for_subdomains'])) {
            return $records;
        }

        $targetHostName = strtolower(rtrim((string) $targetHostName, '.'));
        $zoneName = strtolower(rtrim((string) $zoneName, '.'));
        if ('' === $targetHostName ||
            $targetHostName === $zoneName ||
            0 === strpos($targetHostName, 'www.') ||
            substr($targetHostName, -strlen('.' . $zoneName)) !== '.' . $zoneName) {
            return $records;
        }

        $wwwName = 'www.' . $targetHostName;
        $existingKeys = [];
        foreach ($records as $record) {
            $existingKeys[$this->nameTypeKey($record)] = true;
        }

        foreach ($records as $record) {
            $type = strtoupper(isset($record['type']) ? $record['type'] : '');
            $name = strtolower(rtrim(isset($record['name']) ? $record['name'] : '', '.'));
            if ($name !== $targetHostName || !in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                continue;
            }

            $companion = $record;
            $companion['name'] = $wwwName;
            $key = $this->nameTypeKey($companion);
            if (isset($existingKeys[$key])) {
                continue;
            }

            $existingKeys[$key] = true;
            $records[] = $companion;
        }

        return $records;
    }

    private function recordMatchesDeletedHost(array $record, $hostName, array $settings)
    {
        $name = strtolower(rtrim(isset($record['name']) ? $record['name'] : '', '.'));

        if ($name === $hostName) {
            return true;
        }

        return !empty($settings['create_www_for_subdomains']) &&
            0 !== strpos($hostName, 'www.') &&
            $name === 'www.' . $hostName;
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

    private function recordForUpdate(array $record, array $existingRecord)
    {
        if (!array_key_exists('proxied', $record)) {
            return $record;
        }

        if (array_key_exists('proxied', $existingRecord)) {
            $record['proxied'] = (bool) $existingRecord['proxied'];
        } else {
            unset($record['proxied']);
        }

        return $record;
    }

    private function shouldReplace(array $record)
    {
        $type = strtoupper(isset($record['type']) ? $record['type'] : '');

        return in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'PTR', 'CAA', 'DS', 'DNSKEY', 'TLSA', 'SRV'], true);
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

        if (!in_array($type, ['SRV', 'TLSA'], true) && isset($record['content'])) {
            $normalized['content'] = $this->normalizeContent($type, $record['content']);
        }
        if (!in_array($type, ['SRV', 'TLSA'], true) && isset($record['priority'])) {
            $normalized['priority'] = (int) $record['priority'];
        }
        if (array_key_exists('proxied', $record)) {
            $normalized['proxied'] = (bool) $record['proxied'];
        }
        if (isset($record['data']) && is_array($record['data'])) {
            $normalized['data'] = $this->normalizeRecordData($type, $record['data']);
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
            if ('SRV' === $record['type']) {
                return $this->displaySrvData($record['data']);
            }
            if ('TLSA' === $record['type']) {
                return $this->displayTlsaData($record['data']);
            }

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
        if (in_array($type, ['CNAME', 'MX', 'PTR', 'SRV'], true)) {
            return strtolower(rtrim($content, '.'));
        }

        return $content;
    }

    private function normalizeRecordData($type, array $data)
    {
        if ('SRV' === $type) {
            return [
                'priority' => isset($data['priority']) ? (int) $data['priority'] : 0,
                'weight' => isset($data['weight']) ? (int) $data['weight'] : 0,
                'port' => isset($data['port']) ? (int) $data['port'] : 0,
                'target' => isset($data['target']) ? strtolower(rtrim((string) $data['target'], '.')) : '',
            ];
        }

        if ('TLSA' === $type) {
            return [
                'usage' => isset($data['usage']) ? (int) $data['usage'] : 3,
                'selector' => isset($data['selector']) ? (int) $data['selector'] : 1,
                'matching_type' => isset($data['matching_type']) ? (int) $data['matching_type'] : 1,
                'certificate' => isset($data['certificate']) ? preg_replace('/\s+/', '', (string) $data['certificate']) : '',
            ];
        }

        return $data;
    }

    private function displaySrvData(array $data)
    {
        return (string) (isset($data['priority']) ? (int) $data['priority'] : 0) . ' ' .
            (isset($data['weight']) ? (int) $data['weight'] : 0) . ' ' .
            (isset($data['port']) ? (int) $data['port'] : 0) . ' ' .
            (isset($data['target']) ? strtolower(rtrim((string) $data['target'], '.')) : '');
    }

    private function displayTlsaData(array $data)
    {
        return (string) (isset($data['usage']) ? (int) $data['usage'] : 3) . ' ' .
            (isset($data['selector']) ? (int) $data['selector'] : 1) . ' ' .
            (isset($data['matching_type']) ? (int) $data['matching_type'] : 1) . ' ' .
            (isset($data['certificate']) ? preg_replace('/\s+/', '', (string) $data['certificate']) : '');
    }
}
