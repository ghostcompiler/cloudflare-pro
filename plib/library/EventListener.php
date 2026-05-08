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
        return [];
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
                'Domain Name',
                'domain',
                'Domain',
                'site_name',
                'Site name',
                'Site Name',
                'NEW_DOMAIN_NAME',
                'OLD_DOMAIN_NAME',
            ]);

            $sourceDomainName = $domainName;
            $hostName = $domainName;

            if (false !== strpos($objectType, 'subdomain') || false !== strpos($action, 'subdomain')) {
                $subdomainName = $this->value($newValues, $oldValues, [
                    'subdomain_name',
                    'Subdomain name',
                    'Subdomain Name',
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
                $hostName = $this->firstDomainLikeValue($newValues, $oldValues);
                $sourceDomainName = $hostName;
            }

            if ('' === $hostName) {
                error_log(
                    'Cloudflare Pro autosync skipped: no host name for action ' . $action .
                    ', object=' . $objectType .
                    ', id=' . $objectId .
                    ', keys=' . implode(',', array_unique(array_merge(array_keys($newValues), array_keys($oldValues))))
                );
                return;
            }

            error_log('Cloudflare Pro autosync event: object=' . $objectType . ', action=' . $action . ', host=' . $hostName . ', source=' . $sourceDomainName);
            if ($this->isDeleteAction($action)) {
                CloudflarePro_DomainSyncService::autoDeleteHost($hostName);
                return;
            }

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
                false !== strpos($value, 'subdomain') ||
                false !== strpos($value, 'dns') ||
                false !== strpos($value, 'zone') ||
                false !== strpos($value, 'record')) {
                return true;
            }
        }

        return false;
    }

    private function isDeleteAction($action)
    {
        $action = strtolower((string) $action);

        return false !== strpos($action, 'delete') ||
            false !== strpos($action, 'remove') ||
            false !== strpos($action, 'removed');
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
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[$this->normalizeKey($key)] = true;
        }

        foreach ([$newValues, $oldValues] as $values) {
            foreach ($values as $key => $value) {
                if (isset($wanted[$this->normalizeKey($key)]) && '' !== trim((string) $value)) {
                    return strtolower(rtrim(trim((string) $value), '.'));
                }
            }
        }

        return '';
    }

    private function normalizeKey($key)
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $key));
    }

    private function firstDomainLikeValue(array $newValues, array $oldValues)
    {
        foreach ([$newValues, $oldValues] as $values) {
            foreach ($values as $value) {
                $value = strtolower(rtrim(trim((string) $value), '.'));
                if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $value)) {
                    return $value;
                }
            }
        }

        return '';
    }
}

return new Modules_CloudflarePro_EventListener();
