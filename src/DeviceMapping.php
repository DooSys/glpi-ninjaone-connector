<?php

namespace GlpiPlugin\Ninjaone;

use CommonDBTM;

class DeviceMapping extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ninjaone_devicemappings';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('NinjaOne device mapping', 'NinjaOne device mappings', $nb, 'ninjaone');
    }
}
