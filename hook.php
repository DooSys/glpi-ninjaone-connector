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
            `organization_mode` varchar(20) NOT NULL DEFAULT 'multi',
            `single_ninjaone_organization_id` bigint unsigned NULL,
            `sync_time` time NULL DEFAULT '02:00:00',
            `sync_repeat_hours` int unsigned NULL,
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
            `plugin_ninjaone_configs_id` int {$default_key_sign} NOT NULL,
            `ninjaone_organization_id` bigint unsigned NOT NULL,
            `ninjaone_organization_name` varchar(255) NOT NULL,
            `entities_id` int {$default_key_sign} NULL,
            `sync_enabled` tinyint NOT NULL DEFAULT 1,
            `last_sync_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_org` (`plugin_ninjaone_configs_id`, `ninjaone_organization_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_locationmappings')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_locationmappings` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_ninjaone_configs_id` int {$default_key_sign} NOT NULL,
            `ninjaone_organization_id` bigint unsigned NOT NULL,
            `ninjaone_location_id` bigint unsigned NOT NULL,
            `ninjaone_location_name` varchar(255) NOT NULL,
            `locations_id` int {$default_key_sign} NULL,
            `entities_id` int {$default_key_sign} NULL,
            `sync_enabled` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_location` (`plugin_ninjaone_configs_id`, `ninjaone_location_id`),
            KEY `locations_id` (`locations_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_devicemappings')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_devicemappings` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_ninjaone_configs_id` int {$default_key_sign} NOT NULL,
            `ninjaone_device_id` bigint unsigned NOT NULL,
            `ninjaone_organization_id` bigint unsigned NULL,
            `ninjaone_location_id` bigint unsigned NULL,
            `computers_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `first_sync_at` timestamp NULL DEFAULT NULL,
            `last_seen_at` timestamp NULL DEFAULT NULL,
            `last_sync_at` timestamp NULL DEFAULT NULL,
            `last_payload_hash` varchar(64) NULL,
            `last_payload_json` mediumtext NULL,
            `sync_status` varchar(50) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_device` (`plugin_ninjaone_configs_id`, `ninjaone_device_id`),
            KEY `computers_id` (`computers_id`),
            KEY `sync_status` (`sync_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    if (!$DB->tableExists('glpi_plugin_ninjaone_synclogs')) {
        $query = "CREATE TABLE `glpi_plugin_ninjaone_synclogs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_ninjaone_configs_id` int {$default_key_sign} NULL,
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

    if ($DB->tableExists('glpi_plugin_ninjaone_organizationmappings')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_organizationmappings` '
            . 'MODIFY `ninjaone_organization_id` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_organizationmappings` '
            . 'MODIFY `entities_id` int ' . $default_key_sign . ' NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_locationmappings')) {
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
            . 'MODIFY `ninjaone_organization_id` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_locationmappings` '
            . 'MODIFY `ninjaone_location_id` bigint unsigned NOT NULL'
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
            . 'MODIFY `ninjaone_device_id` bigint unsigned NOT NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
            . 'MODIFY `ninjaone_organization_id` bigint unsigned NULL'
        );
        $DB->doQuery(
            'ALTER TABLE `glpi_plugin_ninjaone_devicemappings` '
            . 'MODIFY `ninjaone_location_id` bigint unsigned NULL'
        );
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_configs')) {
        $fields = [
            'redirect_uri'     => "varchar(255) NULL",
            'access_token'     => "text NULL",
            'refresh_token'    => "text NULL",
            'token_expires_at' => "timestamp NULL DEFAULT NULL",
            'oauth_state'      => "varchar(128) NULL",
            'organization_mode' => "varchar(20) NOT NULL DEFAULT 'multi'",
            'single_ninjaone_organization_id' => "bigint unsigned NULL",
            'sync_time' => "time NULL DEFAULT '02:00:00'",
            'sync_repeat_hours' => "int unsigned NULL",
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

    $frequency = MINUTE_TIMESTAMP;
    $options = [
        'comment'   => 'Synchronize NinjaOne inventory using the schedule configured in the NinjaOne connector.',
        'mode'      => CronTask::MODE_EXTERNAL,
        'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
        'state'     => CronTask::STATE_WAITING,
    ];

    CronTask::Register($itemtype, 'NinjaoneSync', $frequency, $options);

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
    }
}

function plugin_ninjaone_uninstall(): bool
{
    global $DB;

    CronTask::Unregister('Ninjaone');

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
