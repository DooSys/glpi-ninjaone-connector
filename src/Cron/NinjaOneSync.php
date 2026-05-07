<?php

namespace GlpiPlugin\Ninjaone\Cron;

use CronTask;
use GlpiPlugin\Ninjaone\Sync\SyncRunner;

final class NinjaOneSync
{
    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Synchronize NinjaOne inventory', 'ninjaone'),
        ];
    }

    public static function cronNinjaoneSync(CronTask $task): int
    {
        global $DB;

        $runner = new SyncRunner();
        $count = 0;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_configs',
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($iterator as $config) {
            $result = $runner->runFullSync($config, 'cron');
            $task->log(implode("\n", $result->messages));
            $count++;
        }

        return $count > 0 ? 1 : 0;
    }
}
