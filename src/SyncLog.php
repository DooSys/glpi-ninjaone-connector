<?php

namespace GlpiPlugin\Ninjaone;

use CommonDBTM;

class SyncLog extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ninjaone_synclogs';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('NinjaOne synchronization log', 'NinjaOne synchronization logs', $nb, 'ninjaone');
    }
}
