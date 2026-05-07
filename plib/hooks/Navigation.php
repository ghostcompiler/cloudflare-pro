<?php

require_once pm_Context::getPlibDir() . '/library/CloudflarePro/Permissions.php';

class Modules_CloudflarePro_Navigation extends pm_Hook_Navigation
{
    public function getNavigation()
    {
        if (!CloudflarePro_Permissions::canAccess()) {
            return [];
        }

        return [
            [
                'controller' => 'index',
                'action' => 'domains',
                'label' => 'Cloudflare Pro',
                'tabbed' => true,
                'pages' => [
                    [
                        'controller' => 'index',
                        'action' => 'records',
                        'label' => 'Domain',
                    ],
                    [
                        'controller' => 'index',
                        'action' => 'tokens',
                    ],
                    [
                        'controller' => 'index',
                        'action' => 'logs',
                    ],
                    [
                        'controller' => 'index',
                        'action' => 'settings',
                    ],
                    [
                        'controller' => 'index',
                        'action' => 'about',
                    ],
                ],
            ],
        ];
    }
}
