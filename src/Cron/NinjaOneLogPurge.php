<?php

namespace GlpiPlugin\Ninjaone\Cron;

use CronTask;

final class NinjaOneLogPurge
{
    private const RETENTION_DAYS = 30;

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Purge NinjaOne synchronization logs older than 30 days', 'ninjaone'),
        ];
    }

    public static function cronNinjaoneLogPurge(CronTask $task): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_ninjaone_synclogs')) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_TIMESTAMP));
        $escapedCutoff = addslashes($cutoff);
        $count = 0;

        $result = $DB->doQuery(
            "SELECT COUNT(*) AS purge_count
             FROM `glpi_plugin_ninjaone_synclogs`
             WHERE `ended_at` IS NOT NULL
               AND `ended_at` < '{$escapedCutoff}'"
        );
        if ($result !== false && ($row = $DB->fetchAssoc($result))) {
            $count = (int) ($row['purge_count'] ?? 0);
        }

        if ($count <= 0) {
            return 0;
        }

        $DB->doQuery(
            "DELETE FROM `glpi_plugin_ninjaone_synclogs`
             WHERE `ended_at` IS NOT NULL
               AND `ended_at` < '{$escapedCutoff}'"
        );
        $task->addVolume($count);

        return 1;
    }
}
