<?php

require_once pm_Context::getPlibDir() . '/library/CloudflarePro/Permissions.php';

class Modules_CloudflarePro_Permissions extends pm_Hook_Permissions
{
    public function getPermissions()
    {
        return array(
            CloudflarePro_Permissions::ACCESS => array(
                'default' => false,
                'place' => self::PLACE_ADDITIONAL,
                'name' => 'Cloudflare Pro access',
                'description' => 'Show Cloudflare Pro extension and allow access for this subscription.',
            ),
        );
    }
}
