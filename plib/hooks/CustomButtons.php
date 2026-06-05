<?php

require_once pm_Context::getPlibDir() . '/library/CloudflarePro/Permissions.php';

class Modules_CloudflarePro_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        $isAdmin = CloudflarePro_Permissions::isAdmin();
        if (!$isAdmin && !CloudflarePro_Permissions::canAccess()) {
            return [];
        }

        $buttons = [];

        if ($isAdmin) {
            $buttons[] = [
                'place' => self::PLACE_ADMIN_NAVIGATION,
                'section' => self::SECTION_NAV_SERVER_MANAGEMENT,
                'title' => 'Cloudflare Pro',
                'description' => 'Ghost Compiler extension for Cloudflare management.',
                'icon' => pm_Context::getBaseUrl() . 'images/cloudflare-icon-32.png',
                'link' => pm_Context::getBaseUrl() . 'index.php/index/domains',
                'newWindow' => false,
                'order' => 2,
            ];
        } else {
            $buttons[] = [
                'place' => self::PLACE_RESELLER_NAVIGATION,
                'section' => self::SECTION_NAV_ADDITIONAL,
                'title' => 'Cloudflare Pro',
                'description' => 'Ghost Compiler extension for Cloudflare management.',
                'icon' => pm_Context::getBaseUrl() . 'images/cloudflare-icon-32.png',
                'link' => pm_Context::getBaseUrl() . 'index.php/index/domains',
                'newWindow' => false,
                'order' => 2,
            ];
            $buttons[] = [
                'place' => self::PLACE_HOSTING_PANEL_NAVIGATION,
                'section' => self::SECTION_NAV_ADDITIONAL,
                'title' => 'Cloudflare Pro',
                'description' => 'Ghost Compiler extension for Cloudflare management.',
                'icon' => pm_Context::getBaseUrl() . 'images/cloudflare-icon-32.png',
                'link' => pm_Context::getBaseUrl() . 'index.php/index/domains',
                'newWindow' => false,
                'order' => 2,
            ];
        }

        return $buttons;
    }
}
