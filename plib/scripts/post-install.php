<?php

pm_Context::init('cloudflare-pro');

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';
require_once pm_Context::getPlibDir() . 'library/SettingsRepository.php';
require_once pm_Context::getPlibDir() . 'library/DomainRepository.php';

$repository = new Modules_CloudflarePro_TokenRepository();
$repository->init();

$settings = new Modules_CloudflarePro_SettingsRepository();
$settings->init();

$domains = new Modules_CloudflarePro_DomainRepository();
$domains->init();
