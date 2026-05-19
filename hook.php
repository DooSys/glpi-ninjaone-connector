<?php

function plugin_ninjaone_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_NINJAONE_VERSION);
    $default_charset = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_ninjaone_configs')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_configs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `base_url` varchar(255) NOT NULL,
            `client_id` varchar(255) NOT NULL,
            `client_secret` text NULL,
            `scopes` varchar(255) NOT NULL DEFAULT 'monitoring',
            `redirect_uri` varchar(255) NULL,
            `access_token` text NULL,
            `refresh_token` text NULL,
            `token_expires_at` timestamp NULL DEFAULT NULL,
            `oauth_state` varchar(128) NULL,
            `organization_mode` varchar(20) NOT NULL DEFAULT 'single',
            `single_ninjaone_organization_ref` bigint unsigned NULL,
            `inventory_mode` varchar(20) NOT NULL DEFAULT 'full',
            `sync_stale_days` int unsigned NOT NULL DEFAULT 20,
            `last_scheduled_sync_at` timestamp NULL DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 0,
            `last_token_refresh` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT NULL,
            `updated_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_organizationmappings')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_organizationmappings` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `config_ref` int {$default_key_sign} NOT NULL,
            `ninjaone_organization_ref` bigint unsigned NOT NULL,
            `ninjaone_organization_name` varchar(255) NOT NULL,
            `entities_id` int {$default_key_sign} NULL,
            `sync_enabled` tinyint NOT NULL DEFAULT 1,
            `last_sync_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_org` (`config_ref`, `ninjaone_organization_ref`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_locationmappings')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_locationmappings` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `config_ref` int {$default_key_sign} NOT NULL,
            `ninjaone_organization_ref` bigint unsigned NOT NULL,
            `ninjaone_location_ref` bigint unsigned NOT NULL,
            `ninjaone_location_name` varchar(255) NOT NULL,
            `locations_id` int {$default_key_sign} NULL,
            `entities_id` int {$default_key_sign} NULL,
            `sync_enabled` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_location` (`config_ref`, `ninjaone_location_ref`),
            KEY `locations_id` (`locations_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_devicemappings')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_devicemappings` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `config_ref` int {$default_key_sign} NOT NULL,
            `ninjaone_device_ref` bigint unsigned NOT NULL,
            `ninjaone_organization_ref` bigint unsigned NULL,
            `ninjaone_location_ref` bigint unsigned NULL,
            `computers_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `first_sync_at` timestamp NULL DEFAULT NULL,
            `last_seen_at` timestamp NULL DEFAULT NULL,
            `last_sync_at` timestamp NULL DEFAULT NULL,
            `last_payload_hash` varchar(64) NULL,
            `last_payload_json` mediumtext NULL,
            `sync_status` varchar(50) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_device` (`config_ref`, `ninjaone_device_ref`),
            KEY `computers_id` (`computers_id`),
            KEY `sync_status` (`sync_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_synclogs')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_synclogs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `config_ref` int {$default_key_sign} NULL,
            `started_at` timestamp NULL DEFAULT NULL,
            `ended_at` timestamp NULL DEFAULT NULL,
            `status` varchar(50) NOT NULL DEFAULT 'running',
            `mode` varchar(50) NOT NULL DEFAULT 'manual',
            `created_count` int {$default_key_sign} NOT NULL DEFAULT 0,
            `updated_count` int {$default_key_sign} NOT NULL DEFAULT 0,
            `skipped_count` int {$default_key_sign} NOT NULL DEFAULT 0,
            `error_count` int {$default_key_sign} NOT NULL DEFAULT 0,
            `message` text NULL,
            PRIMARY KEY (`id`),
            KEY `status` (`status`),
            KEY `started_at` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_configs')
        && $DB->fieldExists('glpi_plugin_ninjaone_configs', 'single_ninjaone_organization_id')
        && !$DB->fieldExists('glpi_plugin_ninjaone_configs', 'single_ninjaone_organization_ref')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_configs` '
            . 'CHANGE `single_ninjaone_organization_id` `single_ninjaone_organization_ref` bigint unsigned NULL'
        );
    }

    $config_tables = [
        'glpi_plugin_ninjaone_organizationmappings' => 'int ' . $default_key_sign . ' NOT NULL',
        'glpi_plugin_ninjaone_locationmappings'     => 'int ' . $default_key_sign . ' NOT NULL',
        'glpi_plugin_ninjaone_devicemappings'       => 'int ' . $default_key_sign . ' NOT NULL',
        'glpi_plugin_ninjaone_synclogs'             => 'int ' . $default_key_sign . ' NULL',
    ];
    foreach ($config_tables as $table => $definition) {
        if ($DB->tableExists($table)
            && $DB->fieldExists($table, 'plugin_ninjaone_configs_id')
            && !$DB->fieldExists($table, 'config_ref')) {
            $DB->doQuery(
                "ALTER TABLE `$table` "
                . "CHANGE `plugin_ninjaone_configs_id` `config_ref` $definition"
            );
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_organizationmappings')
        && $DB->fieldExists('glpi_plugin_ninjaone_organizationmappings', 'ninjaone_organization_id')
        && !$DB->fieldExists('glpi_plugin_ninjaone_organizationmappings', 'ninjaone_organization_ref')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_organizationmappings` '
            . 'CHANGE `ninjaone_organization_id` `ninjaone_organization_ref` bigint unsigned NOT NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_locationmappings')) {
        if ($DB->fieldExists('glpi_plugin_ninjaone_locationmappings', 'ninjaone_organization_id')
            && !$DB->fieldExists('glpi_plugin_ninjaone_locationmappings', 'ninjaone_organization_ref')) {
            $DB->doQuery(
                'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
                . 'CHANGE `ninjaone_organization_id` `ninjaone_organization_ref` bigint unsigned NOT NULL'
            );
        }
        if ($DB->fieldExists('glpi_plugin_ninjaone_locationmappings', 'ninjaone_location_id')
            && !$DB->fieldExists('glpi_plugin_ninjaone_locationmappings', 'ninjaone_location_ref')) {
            $DB->doQuery(
                'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
                . 'CHANGE `ninjaone_location_id` `ninjaone_location_ref` bigint unsigned NOT NULL'
            );
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_devicemappings')) {
        if ($DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'ninjaone_device_id')
            && !$DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'ninjaone_device_ref')) {
            $DB->doQuery(
                'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
                . 'CHANGE `ninjaone_device_id` `ninjaone_device_ref` bigint unsigned NOT NULL'
            );
        }
        if ($DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'ninjaone_organization_id')
            && !$DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'ninjaone_organization_ref')) {
            $DB->doQuery(
                'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
                . 'CHANGE `ninjaone_organization_id` `ninjaone_organization_ref` bigint unsigned NULL'
            );
        }
        if ($DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'ninjaone_location_id')
            && !$DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'ninjaone_location_ref')) {
            $DB->doQuery(
                'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
                . 'CHANGE `ninjaone_location_id` `ninjaone_location_ref` bigint unsigned NULL'
            );
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_organizationmappings')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_organizationmappings` '
            . 'MODIFY `ninjaone_organization_ref` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_organizationmappings` '
            . 'MODIFY `entities_id` int ' . $default_key_sign . ' NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_locationmappings')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
            . 'MODIFY `ninjaone_organization_ref` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
            . 'MODIFY `ninjaone_location_ref` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
            . 'MODIFY `locations_id` int ' . $default_key_sign . ' NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
            . 'MODIFY `entities_id` int ' . $default_key_sign . ' NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_devicemappings')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
            . 'MODIFY `ninjaone_device_ref` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
            . 'MODIFY `ninjaone_organization_ref` bigint unsigned NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
            . 'MODIFY `ninjaone_location_ref` bigint unsigned NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_configs')) {
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_ninjaone_configs` "
            . "MODIFY `scopes` varchar(255) NOT NULL DEFAULT 'monitoring'"
        );
        $DB->doQuery(
            "UPDATE `glpi_plugin_ninjaone_configs` SET `scopes` = 'monitoring' "
            . "WHERE `scopes` <> 'monitoring'"
        );

        $fields = [
            'redirect_uri'     => "varchar(255) NULL",
            'access_token'     => "text NULL",
            'refresh_token'    => "text NULL",
            'token_expires_at' => "timestamp NULL DEFAULT NULL",
            'oauth_state'      => "varchar(128) NULL",
            'organization_mode' => "varchar(20) NOT NULL DEFAULT 'single'",
            'single_ninjaone_organization_ref' => "bigint unsigned NULL",
            'inventory_mode' => "varchar(20) NOT NULL DEFAULT 'full'",
            'sync_stale_days' => "int unsigned NOT NULL DEFAULT 20",
            'last_scheduled_sync_at' => "timestamp NULL DEFAULT NULL",
        ];

        foreach ($fields as $field => $definition) {
            if (!$DB->fieldExists('glpi_plugin_ninjaone_configs', $field)) {
                $DB->doQuery(
                    "ALTER TABLE `glpi_plugin_ninjaone_configs` ADD `$field` $definition"
                );
            }
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_devicemappings')
        && !$DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'last_payload_json')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` ADD `last_payload_json` mediumtext NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_devicemappings')
        && !$DB->fieldExists('glpi_plugin_ninjaone_devicemappings', 'first_sync_at')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` ADD `first_sync_at` timestamp NULL DEFAULT NULL AFTER `computers_id`'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_organizationmappings')) {
        $DB->update('glpi_plugin_ninjaone_organizationmappings', ['sync_enabled' => 0], ['entities_id' => null]);
    }

    plugin_ninjaone_register_cron_task();

    $migration->executeMigration();

    return true;
}

function plugin_ninjaone_register_cron_task(): void
{
    global $DB;

    $itemtype = 'GlpiPlugin\\Ninjaone\\Cron\\NinjaOneSync';
    if (!class_exists($itemtype)) {
        $cron_file = __DIR__ . '/src/Cron/NinjaOneSync.php';
        if (file_exists($cron_file)) {
            require_once $cron_file;
        }
    }

    $purge_itemtype = 'GlpiPlugin\\Ninjaone\\Cron\\NinjaOneLogPurge';
    if (!class_exists($purge_itemtype)) {
        $purge_cron_file = __DIR__ . '/src/Cron/NinjaOneLogPurge.php';
        if (file_exists($purge_cron_file)) {
            require_once $purge_cron_file;
        }
    }

    $frequency = 12 * HOUR_TIMESTAMP;
    $options = [
        'comment'   => 'Synchronizes active NinjaOne connector configurations twice a day. Use the connector page button for an immediate manual synchronization.',
        'mode'      => CronTask::MODE_EXTERNAL,
        'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
        'state'     => CronTask::STATE_WAITING,
    ];

    CronTask::Register($itemtype, 'NinjaoneSync', $frequency, $options);

    $purge_frequency = 3 * DAY_TIMESTAMP;
    $purge_options = [
        'comment'   => 'Purge NinjaOne synchronization logs older than 30 days.',
        'mode'      => CronTask::MODE_EXTERNAL,
        'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
        'state'     => CronTask::STATE_WAITING,
    ];
    CronTask::Register($purge_itemtype, 'NinjaoneLogPurge', $purge_frequency, $purge_options);

    if ($DB->tableExists('glpi_crontasks')) {
        $DB->update(
            'glpi_crontasks',
            [
                'itemtype'  => $itemtype,
                'frequency' => $frequency,
                'mode'      => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
                'state'     => CronTask::STATE_WAITING,
                'comment'   => $options['comment'],
            ],
            ['name' => 'NinjaoneSync']
        );
        $DB->update(
            'glpi_crontasks',
            [
                'itemtype'  => $purge_itemtype,
                'frequency' => $purge_frequency,
                'mode'      => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
                'state'     => CronTask::STATE_WAITING,
                'comment'   => $purge_options['comment'],
            ],
            ['name' => 'NinjaoneLogPurge']
        );
    }
}

function plugin_ninjaone_uninstall(): bool
{
    global $DB;

    if ($DB->tableExists('glpi_crontasks')) {
        $DB->delete('glpi_crontasks', [
            'name' => ['Ninjaone', 'NinjaoneSync', 'NinjaoneLogPurge'],
        ]);
    }

    $tables = [
        'synclogs',
        'devicemappings',
        'locationmappings',
        'organizationmappings',
        'configs',
    ];

    foreach ($tables as $table) {
        $table_name = 'glpi_plugin_ninjaone_' . $table;
        if ($DB->tableExists($table_name)) {
            $DB->doQuery("DROP TABLE `$table_name`");
        }
    }

    return true;
}
