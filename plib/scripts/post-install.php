<?php

pm_Context::init('cloudflare-pro');

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';

$repository = new Modules_CloudflarePro_TokenRepository();
$repository->init();
