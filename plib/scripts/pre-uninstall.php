<?php

pm_Context::init('cloudflare-pro');

$dbPath = pm_Context::getVarDir() . DIRECTORY_SEPARATOR . 'cloudflare-pro.sqlite';

if (is_file($dbPath)) {
    unlink($dbPath);
}
