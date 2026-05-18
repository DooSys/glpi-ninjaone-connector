<?php

namespace GlpiPlugin\Ninjaone\Cron;

use CronTask;
use GlpiPlugin\Ninjaone\Sync\SyncRunner;

final class NinjaOneSync
{
    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Synchronize NinjaOne inventory twice a day', 'ninjaone'),
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
            $volume = $result->created + $result->updated + $result->skipped + $result->errors;
            $task->addVolume($volume);
            if ($result->messages !== []) {
                \Toolbox::logInFile('ninjaone', implode("\n", $result->messages) . "\n");
            }
            $DB->update(
                'glpi_plugin_ninjaone_configs',
                ['last_scheduled_sync_at' => date('Y-m-d H:i:s')],
                ['id' => (int) $config['id']]
            );
            $count++;
        }

        return $count > 0 ? 1 : 0;
    }
}
