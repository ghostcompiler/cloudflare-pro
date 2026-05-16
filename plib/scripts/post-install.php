<?php

pm_Context::init('cloudflare-pro');

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';
require_once pm_Context::getPlibDir() . 'library/SettingsRepository.php';
require_once pm_Context::getPlibDir() . 'library/DomainRepository.php';

$systemOwner = ['id' => 'system', 'login' => 'system'];

$repository = new Modules_CloudflarePro_TokenRepository($systemOwner);
$repository->init();

$settings = new Modules_CloudflarePro_SettingsRepository($systemOwner);
$settings->init();

$domains = new Modules_CloudflarePro_DomainRepository($systemOwner);
$domains->init();
