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
            if (!self::isConfigDue($config)) {
                continue;
            }
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

    private static function isConfigDue(array $config): bool
    {
        $syncTime = (string) ($config['sync_time'] ?? '02:00:00');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $syncTime)) {
            $syncTime = '02:00:00';
        }

        $now = time();
        $scheduledToday = strtotime(date('Y-m-d') . ' ' . $syncTime);
        if ($scheduledToday === false || $now < $scheduledToday) {
            return false;
        }

        $lastRun = empty($config['last_scheduled_sync_at'])
            ? null
            : strtotime((string) $config['last_scheduled_sync_at']);

        $repeatHours = isset($config['sync_repeat_hours']) ? (int) $config['sync_repeat_hours'] : 0;
        if ($repeatHours > 0) {
            return $lastRun === null || ($now - $lastRun) >= ($repeatHours * HOUR_TIMESTAMP);
        }

        return $lastRun === null || date('Y-m-d', $lastRun) !== date('Y-m-d', $now);
    }
}
