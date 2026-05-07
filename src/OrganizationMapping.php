<?php

namespace GlpiPlugin\Ninjaone;

use CommonDBTM;

class OrganizationMapping extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ninjaone_organizationmappings';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('NinjaOne organization mapping', 'NinjaOne organization mappings', $nb, 'ninjaone');
    }
}
