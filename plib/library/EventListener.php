<?php

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';
require_once pm_Context::getPlibDir() . 'library/SettingsRepository.php';
require_once pm_Context::getPlibDir() . 'library/DomainRepository.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/CloudflareClient.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/PleskDnsService.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/DomainSyncService.php';

class Modules_CloudflarePro_EventListener implements EventListener
{
    public function filterActions()
    {
        return [
            'domain_create',
            'domain_update',
            'domain_dns_update',
            'site_create',
            'site_update',
            'subdomain_create',
            'subdomain_update',
        ];
    }

    public function handleEvent($objectType, $objectId, $action, $oldValues, $newValues)
    {
        try {
            $objectType = strtolower((string) $objectType);
            $action = strtolower((string) $action);

            if (!$this->shouldHandle($objectType, $action)) {
                return;
            }

            $domainName = $this->value($newValues, $oldValues, [
                'domain_name',
                'Domain name',
                'domain',
                'Domain',
                'site_name',
                'Site name',
                'NEW_DOMAIN_NAME',
                'OLD_DOMAIN_NAME',
            ]);

            $sourceDomainName = $domainName;
            $hostName = $domainName;

            if (false !== strpos($objectType, 'subdomain') || false !== strpos($action, 'subdomain')) {
                $subdomainName = $this->value($newValues, $oldValues, [
                    'subdomain_name',
                    'Subdomain name',
                    'subdomain',
                    'Subdomain',
                    'name',
                    'Name',
                    'NEW_SUBDOMAIN_NAME',
                    'OLD_SUBDOMAIN_NAME',
                ]);
                if ('' !== $subdomainName) {
                    $hostName = $this->composeHostName($subdomainName, $domainName);
                    if ('' === $sourceDomainName && $hostName !== $subdomainName) {
                        $sourceDomainName = $domainName;
                    }
                }
            }

            if ('' === $hostName) {
                error_log('Cloudflare Pro autosync skipped: no host name for action ' . $action . ', object=' . $objectType . ', id=' . $objectId);
                return;
            }

            error_log('Cloudflare Pro autosync event: object=' . $objectType . ', action=' . $action . ', host=' . $hostName . ', source=' . $sourceDomainName);
            CloudflarePro_DomainSyncService::autoSyncHost($hostName, $sourceDomainName ?: null, 8);
        } catch (Throwable $e) {
            error_log('Cloudflare Pro event autosync failed: ' . $e->getMessage());
        }
    }

    private function shouldHandle($objectType, $action)
    {
        foreach ([$objectType, $action] as $value) {
            $value = strtolower((string) $value);
            if (false !== strpos($value, 'domain_dns') ||
                false !== strpos($value, 'domain') ||
                false !== strpos($value, 'site') ||
                false !== strpos($value, 'subdomain')) {
                return true;
            }
        }

        return false;
    }

    private function composeHostName($subdomainName, $domainName)
    {
        $subdomainName = strtolower(rtrim(trim((string) $subdomainName), '.'));
        $domainName = strtolower(rtrim(trim((string) $domainName), '.'));

        if ('' === $subdomainName) {
            return $domainName;
        }

        if ('' === $domainName || substr($subdomainName, -strlen('.' . $domainName)) === '.' . $domainName || $subdomainName === $domainName) {
            return $subdomainName;
        }

        return $subdomainName . '.' . $domainName;
    }

    private function value(array $newValues, array $oldValues, array $keys)
    {
        foreach ([$newValues, $oldValues] as $values) {
            foreach ($keys as $key) {
                if (isset($values[$key]) && '' !== trim((string) $values[$key])) {
                    return strtolower(rtrim(trim((string) $values[$key]), '.'));
                }
            }
        }

        return '';
    }
}

return new Modules_CloudflarePro_EventListener();
