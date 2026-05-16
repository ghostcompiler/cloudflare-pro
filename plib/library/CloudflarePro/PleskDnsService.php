<?php

class CloudflarePro_PleskDnsService
{
    private $types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'PTR', 'CAA', 'DS', 'DNSKEY', 'TLSA', 'SRV'];

    public function recordsForDomainId($domainId)
    {
        return $this->recordsForDomain($this->domainById($domainId));
    }

    public function recordsForDomainName($domainName)
    {
        return $this->recordsForDomain($this->domainByName($domainName));
    }

    public function createRecordForDomainId($domainId, array $record)
    {
        $domain = $this->domainById($domainId);
        $zone = $domain->getDnsZone();
        $zoneName = strtolower(rtrim($domain->getName(), '.'));
        $type = strtoupper((string) $record['type']);

        $dnsRecord = new pm_Dns_Record();
        $dnsRecord
            ->setZone($zone)
            ->setType($type)
            ->setHost($this->relativeHost(isset($record['name']) ? $record['name'] : $zoneName, $zoneName))
            ->setValue($this->recordValue($record))
            ->setTtl($this->ttlForPlesk(isset($record['ttl']) ? $record['ttl'] : 1));

        if ('MX' === $type && isset($record['priority'])) {
            $dnsRecord->setOption((string) (int) $record['priority']);
        }

        if ('SRV' === $type && isset($record['data']) && is_array($record['data'])) {
            $dnsRecord->setOption(
                (isset($record['data']['priority']) ? (int) $record['data']['priority'] : 0) . ' ' .
                (isset($record['data']['weight']) ? (int) $record['data']['weight'] : 0) . ' ' .
                (isset($record['data']['port']) ? (int) $record['data']['port'] : 0)
            );
        }

        if ('TLSA' === $type && isset($record['data']) && is_array($record['data'])) {
            $dnsRecord->setOption(
                (isset($record['data']['usage']) ? (int) $record['data']['usage'] : 3) . ' ' .
                (isset($record['data']['selector']) ? (int) $record['data']['selector'] : 1) . ' ' .
                (isset($record['data']['matching_type']) ? (int) $record['data']['matching_type'] : 1)
            );
        }

        $dnsRecord->save();
    }

    public function removeByNameType($domainId, $name, $type)
    {
        $domain = $this->domainById($domainId);
        $zoneName = strtolower(rtrim($domain->getName(), '.'));
        $targetName = strtolower(rtrim((string) $name, '.'));
        $targetType = strtoupper((string) $type);
        $removed = 0;

        foreach ($this->loadRecords($domain->getDnsZone()) as $record) {
            $mapped = $this->mapRecord($record, $zoneName);
            if (!$mapped) {
                continue;
            }
            if ($mapped['name'] === $targetName && $mapped['type'] === $targetType) {
                $record->remove();
                $removed++;
            }
        }

        return $removed;
    }

    private function domainById($domainId)
    {
        foreach (CloudflarePro_Permissions::accessibleDomains() as $domain) {
            if ((string) $domain->getId() === (string) $domainId) {
                return $domain;
            }
        }

        throw new pm_Exception('Domain not found or not accessible.');
    }

    private function domainByName($domainName)
    {
        $domainName = strtolower(rtrim((string) $domainName, '.'));
        foreach (pm_Domain::getAllDomains(false) as $domain) {
            if (strtolower(rtrim($domain->getName(), '.')) === $domainName) {
                return $domain;
            }
        }

        throw new pm_Exception('Domain not found: ' . $domainName);
    }

    private function recordsForDomain(pm_Domain $domain)
    {
        $zone = $domain->getDnsZone();
        $zoneName = strtolower(rtrim($domain->getName(), '.'));
        $records = [];

        foreach ($this->loadRecords($zone) as $record) {
            $mapped = $this->mapRecord($record, $zoneName);
            if ($mapped) {
                $records[] = $mapped;
            }
        }

        return $records;
    }

    private function loadRecords(pm_Dns_Zone $zone)
    {
        $records = [];
        $seen = [];

        foreach ($this->types as $type) {
            try {
                $items = $zone->getRecordsByType($type);
                foreach (is_array($items) ? $items : [$items] as $record) {
                    if (!$record) {
                        continue;
                    }
                    $id = method_exists($record, 'getId') ? (string) $record->getId() : spl_object_hash($record);
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $records[] = $record;
                }
            } catch (Exception $e) {
            }
        }

        return $records;
    }

    private function mapRecord(pm_Dns_Record $record, $zoneName)
    {
        $type = strtoupper((string) $record->getType());
        if (!in_array($type, $this->types, true)) {
            return null;
        }

        $item = [
            'type' => $type,
            'name' => $this->absoluteName($record->getHost(), $zoneName),
            'content' => $this->content($type, $record->getValue()),
            'ttl' => $this->ttl($record->getTtl()),
        ];

        if ('MX' === $type) {
            $item['priority'] = (int) $record->getOption();
        }

        if ('CAA' === $type) {
            $item['data'] = $this->caaData($item['content']);
            unset($item['content']);
        }

        if ('SRV' === $type) {
            $item['data'] = $this->srvData($item['content'], $record->getOption());
            unset($item['content']);
        }

        if ('TLSA' === $type) {
            $item['data'] = $this->tlsaData($item['content'], $record->getOption());
            unset($item['content']);
        }

        return $item;
    }

    private function absoluteName($host, $zoneName)
    {
        $host = strtolower(rtrim(trim((string) $host), '.'));
        if ('' === $host || '@' === $host || $host === $zoneName) {
            return $zoneName;
        }
        if (substr($host, -strlen('.' . $zoneName)) === '.' . $zoneName) {
            return $host;
        }

        return $host . '.' . $zoneName;
    }

    private function relativeHost($name, $zoneName)
    {
        $name = strtolower(rtrim((string) $name, '.'));
        if ($name === $zoneName) {
            return $zoneName;
        }
        if (substr($name, -strlen('.' . $zoneName)) === '.' . $zoneName) {
            return substr($name, 0, -strlen('.' . $zoneName));
        }

        return $name;
    }

    private function content($type, $value)
    {
        $value = trim((string) $value);
        if (in_array($type, ['CNAME', 'MX', 'PTR', 'SRV'], true)) {
            return strtolower(rtrim($value, '.'));
        }

        return $value;
    }

    private function ttl($ttl)
    {
        $ttl = (int) $ttl;

        return $ttl > 1 ? max(60, $ttl) : 1;
    }

    private function ttlForPlesk($ttl)
    {
        $ttl = (int) $ttl;

        return $ttl <= 1 ? 3600 : $ttl;
    }

    private function recordValue(array $record)
    {
        if (isset($record['content'])) {
            return $this->content(strtoupper((string) $record['type']), $record['content']);
        }

        if (isset($record['data']) && is_array($record['data'])) {
            $data = $record['data'];
            if (isset($data['flags'], $data['tag'], $data['value'])) {
                return (int) $data['flags'] . ' ' . $data['tag'] . ' "' . $data['value'] . '"';
            }
            if ('SRV' === strtoupper((string) $record['type']) && isset($data['target'])) {
                return $this->content('SRV', $data['target']);
            }
            if ('TLSA' === strtoupper((string) $record['type']) && isset($data['certificate'])) {
                return (string) $data['certificate'];
            }
        }

        return '';
    }

    private function caaData($value)
    {
        if (preg_match('/^(\d+)\s+([a-zA-Z0-9_-]+)\s+"?(.+?)"?$/', trim((string) $value), $matches)) {
            return [
                'flags' => (int) $matches[1],
                'tag' => $matches[2],
                'value' => $matches[3],
            ];
        }

        return [
            'flags' => 0,
            'tag' => 'issue',
            'value' => trim((string) $value),
        ];
    }

    private function srvData($target, $option)
    {
        $target = trim((string) $target);
        $option = trim((string) $option);
        $parts = preg_split('/\s+/', $option);

        if (count($parts) < 3 && preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $target, $matches)) {
            $parts = [$matches[1], $matches[2], $matches[3]];
            $target = $matches[4];
        }

        return [
            'priority' => isset($parts[0]) && '' !== $parts[0] ? (int) $parts[0] : 0,
            'weight' => isset($parts[1]) && '' !== $parts[1] ? (int) $parts[1] : 0,
            'port' => isset($parts[2]) && '' !== $parts[2] ? (int) $parts[2] : 0,
            'target' => $this->content('SRV', $target),
        ];
    }

    private function tlsaData($certificate, $option)
    {
        $certificate = trim((string) $certificate);
        $option = trim((string) $option);
        $parts = preg_split('/\s+/', $option);

        if (count($parts) < 3 && preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $certificate, $matches)) {
            $parts = [$matches[1], $matches[2], $matches[3]];
            $certificate = $matches[4];
        }

        return [
            'usage' => isset($parts[0]) && '' !== $parts[0] ? (int) $parts[0] : 3,
            'selector' => isset($parts[1]) && '' !== $parts[1] ? (int) $parts[1] : 1,
            'matching_type' => isset($parts[2]) && '' !== $parts[2] ? (int) $parts[2] : 1,
            'certificate' => preg_replace('/\s+/', '', $certificate),
        ];
    }
}
