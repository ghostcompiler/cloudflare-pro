<?php

pm_Context::init('cloudflare-pro');

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';
require_once pm_Context::getPlibDir() . 'library/SettingsRepository.php';
require_once pm_Context::getPlibDir() . 'library/DomainRepository.php';

function cloudflareProInstallLog($message)
{
    $message = 'Cloudflare Pro install: ' . $message;
    if (class_exists('pm_Log')) {
        pm_Log::info($message);
    } else {
        error_log($message);
    }
}

cloudflareProInstallLog('started.');

$repository = new Modules_CloudflarePro_TokenRepository();
$repository->init();
cloudflareProInstallLog('token storage initialized.');

$settings = new Modules_CloudflarePro_SettingsRepository();
$settings->init();
cloudflareProInstallLog('settings storage initialized.');

$domains = new Modules_CloudflarePro_DomainRepository();
$domains->init();
cloudflareProInstallLog('domain link storage initialized.');
cloudflareProInstallLog('completed successfully.');
