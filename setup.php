<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\DeviceMapping;
use GlpiPlugin\Ninjaone\LocationMapping;
use GlpiPlugin\Ninjaone\OrganizationMapping;
use GlpiPlugin\Ninjaone\SyncLog;

define('PLUGIN_NINJAONE_VERSION', '0.1.17-dev');
define('PLUGIN_NINJAONE_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_NINJAONE_MAX_GLPI_VERSION', '11.99.99');

function plugin_init_ninjaone(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Config::class);
    Plugin::registerClass(SyncLog::class);
    Plugin::registerClass(OrganizationMapping::class);
    Plugin::registerClass(LocationMapping::class);
    Plugin::registerClass(DeviceMapping::class);

    $PLUGIN_HOOKS['csrf_compliant']['ninjaone'] = true;
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['ninjaone'] = 'front/config.php';
    }
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['ninjaone'] = [
        'plugins' => Config::class,
    ];
}

function plugin_version_ninjaone(): array
{
    return [
        'name'           => 'NinjaOne connector',
        'version'        => PLUGIN_NINJAONE_VERSION,
        'author'         => 'DooSys',
        'license'        => 'GPLv3+',
        'homepage'       => 'https://github.com/DooSys/glpi-ninjaone-connector',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_NINJAONE_MIN_GLPI_VERSION,
                'max' => PLUGIN_NINJAONE_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min'  => '8.2',
                'exts' => [
                    'curl' => [
                        'required' => true,
                    ],
                    'json' => [
                        'required' => true,
                    ],
                    'openssl' => [
                        'required' => true,
                    ],
                ],
            ],
        ],
    ];
}

function plugin_ninjaone_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_NINJAONE_MIN_GLPI_VERSION, '<')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_NINJAONE_MIN_GLPI_VERSION;
        return false;
    }

    return true;
}

function plugin_ninjaone_check_config(bool $verbose = false): bool
{
    return true;
}
