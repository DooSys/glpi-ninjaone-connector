<?php

namespace GlpiPlugin\Ninjaone;

use CommonDBTM;

class LocationMapping extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ninjaone_locationmappings';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('NinjaOne location mapping', 'NinjaOne location mappings', $nb, 'ninjaone');
    }
}
