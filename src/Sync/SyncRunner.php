<?php

namespace GlpiPlugin\Ninjaone\Sync;

use GlpiPlugin\Ninjaone\Client\NinjaOneClient;

final class SyncRunner
{
    public function runFullSync(array $config, string $mode = 'manual'): SyncResult
    {
        $result = new SyncResult();
        $startedAt = date('Y-m-d H:i:s');

        $client = new NinjaOneClient(
            $config['base_url'],
            $config['client_id'],
            $config['client_secret'] ?? '',
            $config['scopes'] ?? 'monitoring',
            null,
            null,
            $config['redirect_uri'] ?? null
        );

        try {
            $result->messages[] = 'NinjaOne auth mode: ' . $client->authMode();

            try {
                $organizations = $client->listOrganizationsDetailed();
                $result->messages[] = sprintf('Fetched %d NinjaOne detailed organizations.', count($organizations));
            } catch (\Throwable $e) {
                try {
                    $organizations = $client->listOrganizations();
                } catch (\Throwable $fallbackError) {
                    throw new \RuntimeException(sprintf(
                        'Organization discovery failed. Detailed error: %s. Basic error: %s',
                        $e->getMessage(),
                        $fallbackError->getMessage()
                    ));
                }
                $result->messages[] = sprintf(
                    'Detailed organizations endpoint failed, using basic organizations endpoint instead: %s',
                    $e->getMessage()
                );
                $result->messages[] = sprintf('Fetched %d NinjaOne organizations.', count($organizations));
            }

            $this->upsertOrganizationsAndLocations((int) $config['id'], $organizations, $result);

            $organizationIds = $this->getEnabledOrganizationIds((int) $config['id']);
            if ($organizationIds === []) {
                $result->messages[] = 'No NinjaOne organization mapping is enabled. Devices synchronization skipped.';
            } else {
                try {
                    $deviceFilter = $this->buildOrganizationDeviceFilter($organizationIds);
                    $devices = $client->listDevicesDetailed($deviceFilter);
                } catch (\Throwable $e) {
                    try {
                        $allDevices = $client->listDevicesDetailed();
                    } catch (\Throwable $fallbackError) {
                        throw new \RuntimeException(sprintf(
                            'Device discovery failed. Filtered error: %s. Unfiltered error: %s',
                            $e->getMessage(),
                            $fallbackError->getMessage()
                        ));
                    }
                    $devices = array_values(array_filter(
                        $allDevices,
                        static fn (array $device): bool => in_array((int) ($device['organizationId'] ?? 0), $organizationIds, true)
                    ));
                    $result->messages[] = sprintf(
                        'Filtered devices endpoint failed, filtered locally instead: %s',
                        $e->getMessage()
                    );
                }
                $result->messages[] = sprintf('Fetched %d NinjaOne devices for %d enabled organizations.', count($devices), count($organizationIds));
                $this->upsertDevicesAsComputers((int) $config['id'], $devices, $result);
                $this->syncAdvancedInventory((int) $config['id'], $client, $organizationIds, $result);
            }
        } catch (\Throwable $e) {
            $result->errors++;
            $result->messages[] = $e->getMessage();
        }

        $this->writeLog((int) ($config['id'] ?? 0), $mode, $startedAt, $result);

        return $result;
    }

    private function getEnabledOrganizationIds(int $configId): array
    {
        global $DB;

        $ids = [];
        $iterator = $DB->request([
            'SELECT' => ['ninjaone_organization_id'],
            'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
                'sync_enabled'               => 1,
            ],
        ]);

        foreach ($iterator as $row) {
            $ids[] = (int) $row['ninjaone_organization_id'];
        }

        return array_values(array_unique($ids));
    }

    private function buildOrganizationDeviceFilter(array $organizationIds): string
    {
        $clauses = array_map(
            static fn (int $id): string => 'organizationId = ' . $id,
            $organizationIds
        );

        return implode(' OR ', $clauses);
    }

    private function writeLog(int $configId, string $mode, string $startedAt, SyncResult $result): void
    {
        global $DB;

        $message = implode("\n", $result->messages);
        if (strlen($message) > 60000) {
            $message = substr($message, 0, 60000) . "\n[log truncated]";
        }

        $DB->insert('glpi_plugin_ninjaone_synclogs', [
            'plugin_ninjaone_configs_id' => $configId > 0 ? $configId : null,
            'started_at'     => $startedAt,
            'ended_at'      => date('Y-m-d H:i:s'),
            'status'        => $result->status(),
            'mode'          => $mode,
            'created_count' => $result->created,
            'updated_count' => $result->updated,
            'skipped_count' => $result->skipped,
            'error_count'   => $result->errors,
            'message'       => $message,
        ]);
    }

    private function upsertOrganizationsAndLocations(int $configId, array $organizations, SyncResult $result): void
    {
        global $DB;

        foreach ($organizations as $organization) {
            if (!is_array($organization) || !isset($organization['id'])) {
                $result->skipped++;
                continue;
            }

            $organizationId = (int) $organization['id'];
            $organizationName = (string) ($organization['name'] ?? ('NinjaOne organization ' . $organizationId));
            $now = date('Y-m-d H:i:s');

            $existing = $DB->request([
                'FROM'  => 'glpi_plugin_ninjaone_organizationmappings',
                'WHERE' => [
                    'plugin_ninjaone_configs_id' => $configId,
                    'ninjaone_organization_id'   => $organizationId,
                ],
                'LIMIT' => 1,
            ]);

            if (count($existing) > 0) {
                $row = $existing->current();
                $DB->update(
                    'glpi_plugin_ninjaone_organizationmappings',
                    [
                        'ninjaone_organization_name' => $organizationName,
                        'last_sync_at'               => $now,
                    ],
                    ['id' => (int) $row['id']]
                );
                $result->updated++;
            } else {
                $DB->insert('glpi_plugin_ninjaone_organizationmappings', [
                    'plugin_ninjaone_configs_id' => $configId,
                    'ninjaone_organization_id'   => $organizationId,
                    'ninjaone_organization_name' => $organizationName,
                    'entities_id'                => null,
                    'sync_enabled'               => 0,
                    'last_sync_at'               => $now,
                ]);
                $result->created++;
            }

            $locations = is_array($organization['locations'] ?? null) ? $organization['locations'] : [];
            foreach ($locations as $location) {
                $this->upsertLocation($configId, $organizationId, $location, $result);
            }
        }
    }

    private function upsertLocation(int $configId, int $organizationId, array $location, SyncResult $result): void
    {
        global $DB;

        if (!isset($location['id'])) {
            $result->skipped++;
            return;
        }

        $locationId = (int) $location['id'];
        $locationName = (string) ($location['name'] ?? ('NinjaOne location ' . $locationId));

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_locationmappings',
            'WHERE' => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_location_id'       => $locationId,
            ],
            'LIMIT' => 1,
        ]);

        $entityId = $this->getOrganizationEntityId($configId, $organizationId);
        if (count($existing) > 0) {
            $row = $existing->current();
            $data = [
                'ninjaone_organization_id' => $organizationId,
                'ninjaone_location_name'   => $locationName,
            ];
            if ($entityId !== null && $row['entities_id'] === null) {
                $data['entities_id'] = $entityId;
            }
            $DB->update('glpi_plugin_ninjaone_locationmappings', $data, ['id' => (int) $row['id']]);
            $result->updated++;
            return;
        }

        $DB->insert('glpi_plugin_ninjaone_locationmappings', [
            'plugin_ninjaone_configs_id' => $configId,
            'ninjaone_organization_id'   => $organizationId,
            'ninjaone_location_id'       => $locationId,
            'ninjaone_location_name'     => $locationName,
            'locations_id'               => null,
            'entities_id'                => $entityId,
            'sync_enabled'               => 0,
        ]);
        $result->created++;
    }

    private function getOrganizationEntityId(int $configId, int $organizationId): ?int
    {
        global $DB;

        if ($organizationId <= 0) {
            return null;
        }

        $rows = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_organization_id'   => $organizationId,
            ],
            'LIMIT' => 1,
        ]);

        if (count($rows) === 0) {
            return null;
        }

        $row = $rows->current();
        return $row['entities_id'] === null ? null : (int) $row['entities_id'];
    }

    private function upsertDeviceMappings(int $configId, array $devices, SyncResult $result): void
    {
        global $DB;

        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['id'])) {
                $result->skipped++;
                continue;
            }

            $deviceId = (int) $device['id'];
            $organizationId = isset($device['organizationId']) ? (int) $device['organizationId'] : null;
            $locationId = isset($device['locationId']) ? (int) $device['locationId'] : null;
            $lastSeenAt = $this->timestampToSqlDate($device['lastContact'] ?? null);
            $payloadHash = hash('sha256', json_encode($device));
            $now = date('Y-m-d H:i:s');

            $existing = $DB->request([
                'FROM'  => 'glpi_plugin_ninjaone_devicemappings',
                'WHERE' => [
                    'plugin_ninjaone_configs_id' => $configId,
                    'ninjaone_device_id'         => $deviceId,
                ],
                'LIMIT' => 1,
            ]);

            if (count($existing) > 0) {
                $row = $existing->current();
                $DB->update(
                    'glpi_plugin_ninjaone_devicemappings',
                    [
                        'ninjaone_organization_id' => $organizationId,
                        'ninjaone_location_id'     => $locationId,
                        'last_seen_at'             => $lastSeenAt,
                        'last_sync_at'             => $now,
                        'last_payload_hash'        => $payloadHash,
                        'sync_status'              => 'mapped',
                    ],
                    ['id' => (int) $row['id']]
                );
                $result->updated++;
                continue;
            }

            $DB->insert('glpi_plugin_ninjaone_devicemappings', [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_device_id'         => $deviceId,
                'ninjaone_organization_id'   => $organizationId,
                'ninjaone_location_id'       => $locationId,
                'computers_id'               => 0,
                'last_seen_at'               => $lastSeenAt,
                'last_sync_at'               => $now,
                'last_payload_hash'          => $payloadHash,
                'sync_status'                => 'mapped',
            ]);
            $result->created++;
        }
    }

    private function upsertDevicesAsComputers(int $configId, array $devices, SyncResult $result): void
    {
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['id'])) {
                $result->skipped++;
                continue;
            }

            $entityId = $this->getMappedEntityId($configId, (int) ($device['organizationId'] ?? 0));
            if ($entityId === null) {
                $result->skipped++;
                continue;
            }

            if (!$this->isComputerDevice($device)) {
                $this->upsertSingleDeviceMapping($configId, $device, 0, 'skipped_not_computer');
                $result->skipped++;
                continue;
            }

            $computerId = $this->findComputerId($configId, $device);
            $input = $this->buildComputerInput($configId, $entityId, $device);

            $computer = new \Computer();
            if ($computerId > 0 && $computer->getFromDB($computerId)) {
                $input['id'] = $computerId;
                if ($computer->update($input)) {
                    try {
                        $this->upsertComputerOperatingSystem($computerId, $device);
                    } catch (\Throwable $e) {
                        $result->messages[] = sprintf('OS mapping skipped for NinjaOne device %d: %s', (int) $device['id'], $e->getMessage());
                    }
                    $this->upsertSingleDeviceMapping($configId, $device, $computerId, 'computer_updated');
                    $result->updated++;
                } else {
                    $this->upsertSingleDeviceMapping($configId, $device, $computerId, 'computer_update_failed');
                    $result->errors++;
                }
                continue;
            }

            $newComputerId = (int) $computer->add($input);
            if ($newComputerId > 0) {
                try {
                    $this->upsertComputerOperatingSystem($newComputerId, $device);
                } catch (\Throwable $e) {
                    $result->messages[] = sprintf('OS mapping skipped for NinjaOne device %d: %s', (int) $device['id'], $e->getMessage());
                }
                $this->upsertSingleDeviceMapping($configId, $device, $newComputerId, 'computer_created');
                $result->created++;
            } else {
                $this->upsertSingleDeviceMapping($configId, $device, 0, 'computer_create_failed');
                $result->errors++;
            }
        }
    }

    private function getMappedEntityId(int $configId, int $organizationId): ?int
    {
        global $DB;

        if ($organizationId <= 0) {
            return null;
        }

        $rows = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_organization_id'   => $organizationId,
                'sync_enabled'               => 1,
            ],
            'LIMIT' => 1,
        ]);

        if (count($rows) === 0) {
            return null;
        }

        $row = $rows->current();
        return (int) $row['entities_id'];
    }

    private function findComputerId(int $configId, array $device): int
    {
        global $DB;

        $deviceId = (int) $device['id'];
        $mapped = $DB->request([
            'SELECT' => ['computers_id'],
            'FROM'   => 'glpi_plugin_ninjaone_devicemappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_device_id'         => $deviceId,
            ],
            'LIMIT' => 1,
        ]);

        if (count($mapped) > 0) {
            $row = $mapped->current();
            if ((int) $row['computers_id'] > 0) {
                return (int) $row['computers_id'];
            }
        }

        $serial = $this->extractFirstString($device, [
            'serialNumber',
            'serial',
            'biosSerialNumber',
            'assetSerialNumber',
            'system.serialNumber',
            'system.serial',
            'hardware.serialNumber',
            'hardware.serial',
            'system.biosSerialNumber',
            'system.assetSerialNumber',
            'ninjaone_reports.computer-systems.0.serialNumber',
            'ninjaone_reports.computer-systems.0.biosSerialNumber',
        ]);
        if ($serial !== '') {
            $matches = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['serial' => $serial],
                'LIMIT'  => 1,
            ]);
            if (count($matches) > 0) {
                $row = $matches->current();
                return (int) $row['id'];
            }
        }

        return 0;
    }

    private function buildComputerInput(int $configId, int $entityId, array $device): array
    {
        $deviceId = (int) $device['id'];
        $name = $this->extractFirstString($device, [
            'displayName',
            'systemName',
            'dnsName',
            'netbiosName',
            'hostname',
            'system.hostname',
            'os.hostname',
        ]);
        if ($name === '') {
            $name = 'NinjaOne device ' . $deviceId;
        }

        $serial = $this->extractFirstString($device, [
            'serialNumber',
            'serial',
            'biosSerialNumber',
            'assetSerialNumber',
            'system.serialNumber',
            'system.serial',
            'hardware.serialNumber',
            'hardware.serial',
            'system.biosSerialNumber',
            'system.assetSerialNumber',
            'ninjaone_reports.computer-systems.0.serialNumber',
            'ninjaone_reports.computer-systems.0.biosSerialNumber',
        ]);

        $uuid = $this->extractFirstString($device, [
            'uuid',
            'uid',
            'systemUuid',
            'system.uuid',
            'hardware.uuid',
        ]);

        $comment = $this->buildComputerComment($device);

        $input = [
            'name'        => $name,
            'entities_id' => $entityId,
            'comment'     => $comment,
        ];

        $this->addComputerFieldIfExists($input, 'manufacturers_id', $this->getOrCreateDropdownId('Manufacturer', $this->extractManufacturer($device)));
        $this->addComputerFieldIfExists($input, 'computermodels_id', $this->getOrCreateDropdownId('ComputerModel', $this->extractModel($device)));
        $this->addComputerFieldIfExists($input, 'computertypes_id', $this->getOrCreateDropdownId('ComputerType', $this->extractComputerType($device)));
        $this->addComputerFieldIfExists($input, 'locations_id', $this->getMappedLocationId($configId, $device));

        $inventoryNumber = $this->extractFirstString($device, [
            'assetTag',
            'asset_tag',
            'inventoryNumber',
            'inventory_number',
            'system.assetTag',
            'hardware.assetTag',
            'chassis.assetTag',
            'system.assetSerialNumber',
        ]);
        if ($inventoryNumber !== '') {
            $this->addComputerFieldIfExists($input, 'otherserial', $inventoryNumber);
        }

        $lastInventory = $this->timestampToSqlDate($device['lastContact'] ?? ($device['lastUpdate'] ?? null));
        if ($lastInventory !== null) {
            $this->addComputerFieldIfExists($input, 'last_inventory_update', $lastInventory);
        }

        if ($serial !== '') {
            $input['serial'] = $serial;
        }
        if ($uuid !== '') {
            $input['uuid'] = $uuid;
        }

        return $input;
    }

    private function buildComputerComment(array $device): string
    {
        $lines = [
            'Imported from NinjaOne',
            'NinjaOne device ID: ' . (string) ($device['id'] ?? ''),
            'Organization ID: ' . (string) ($device['organizationId'] ?? ''),
            'Location ID: ' . (string) ($device['locationId'] ?? ''),
            'Node class: ' . (string) ($device['nodeClass'] ?? ''),
            'Offline: ' . (isset($device['offline']) ? ((bool) $device['offline'] ? 'yes' : 'no') : ''),
            'Last contact: ' . (string) ($device['lastContact'] ?? ''),
        ];

        $manufacturer = $this->extractManufacturer($device);
        $model = $this->extractModel($device);
        $os = $this->extractFirstString($device, [
            'os.name',
            'osName',
            'os',
            'operatingSystem',
            'operatingSystem.name',
            'operatingSystemName',
            'ninjaone_reports.operating-systems.0.name',
        ]);
        $osVersion = $this->extractFirstString($device, [
            'os.version',
            'osVersion',
            'operatingSystem.version',
            'operatingSystemVersion',
            'os.buildNumber',
            'os.releaseId',
            'ninjaone_reports.operating-systems.0.buildNumber',
            'ninjaone_reports.operating-systems.0.releaseId',
        ]);
        $architecture = $this->extractFirstString($device, [
            'os.architecture',
            'osArchitecture',
            'architecture',
            'system.architecture',
            'ninjaone_reports.operating-systems.0.architecture',
        ]);
        $processor = $this->extractFirstString($device, [
            'processor',
            'cpu',
            'processors.0.name',
            'ninjaone_reports.processors.0.name',
            'hardware.processor',
        ]);
        $memory = $this->extractFirstString($device, [
            'memory',
            'totalMemory',
            'memory.capacity',
            'totalPhysicalMemory',
            'system.totalPhysicalMemory',
            'memory.total',
            'hardware.memory',
            'ninjaone_reports.computer-systems.0.totalPhysicalMemory',
        ]);
        $ipAddress = $this->extractFirstString($device, [
            'ipAddress',
            'lastIpAddress',
            'publicIP',
            'publicIp',
            'ipAddresses.0',
            'networkInterfaces.0.ipAddress',
            'networkAdapters.0.ipAddress',
            'ninjaone_reports.network-interfaces.0.ipAddress.0',
        ]);
        $macAddress = $this->extractFirstString($device, [
            'macAddress',
            'macAddresses.0',
            'networkInterfaces.0.macAddress',
            'networkAdapters.0.macAddress',
            'ninjaone_reports.network-interfaces.0.macAddress.0',
        ]);
        $domain = $this->extractFirstString($device, [
            'system.domain',
            'domain',
            'ninjaone_reports.computer-systems.0.domain',
        ]);
        $domainRole = $this->extractFirstString($device, [
            'system.domainRole',
            'domainRole',
            'ninjaone_reports.computer-systems.0.domainRole',
        ]);
        $chassisType = $this->extractFirstString($device, [
            'system.chassisType',
            'chassisType',
            'ninjaone_reports.computer-systems.0.chassisType',
        ]);
        $lastBoot = $this->timestampToSqlDate($this->getPathValue($device, 'os.lastBootTime') ?? $this->getPathValue($device, 'ninjaone_reports.operating-systems.0.lastBootTime'));
        $lastLoggedInUser = $this->extractFirstString($device, [
            'lastLoggedInUser',
            'lastLoggedOnUser',
            'ninjaone_reports.logged-on-users.0.userName',
        ]);

        if ($manufacturer !== '') {
            $lines[] = 'Manufacturer: ' . $manufacturer;
        }
        if ($model !== '') {
            $lines[] = 'Model: ' . $model;
        }
        if ($os !== '') {
            $lines[] = 'OS: ' . $os;
        }
        if ($osVersion !== '') {
            $lines[] = 'OS version: ' . $osVersion;
        }
        if ($architecture !== '') {
            $lines[] = 'Architecture: ' . $architecture;
        }
        if ($processor !== '') {
            $lines[] = 'Processor: ' . $processor;
        }
        if ($memory !== '') {
            $lines[] = 'Memory: ' . $this->formatBytesForComment($memory);
        }
        if ($ipAddress !== '') {
            $lines[] = 'IP address: ' . $ipAddress;
        }
        if ($macAddress !== '') {
            $lines[] = 'MAC address: ' . $macAddress;
        }
        if ($domain !== '') {
            $lines[] = 'Domain: ' . $domain;
        }
        if ($domainRole !== '') {
            $lines[] = 'Domain role: ' . $domainRole;
        }
        if ($chassisType !== '') {
            $lines[] = 'Chassis type: ' . $chassisType;
        }
        if ($lastBoot !== null) {
            $lines[] = 'Last boot: ' . $lastBoot;
        }
        if ($lastLoggedInUser !== '') {
            $lines[] = 'Last logged in user: ' . $lastLoggedInUser;
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    private function addComputerFieldIfExists(array &$input, string $field, mixed $value): void
    {
        global $DB;

        if ($value === null || $value === '' || $value === 0) {
            return;
        }

        if (!$DB->fieldExists('glpi_computers', $field)) {
            return;
        }

        $input[$field] = $value;
    }

    private function extractManufacturer(array $device): string
    {
        return $this->extractFirstString($device, [
            'manufacturer',
            'system.manufacturer',
            'hardware.manufacturer',
            'bios.manufacturer',
            'chassis.manufacturer',
            'computerSystem.manufacturer',
            'ninjaone_reports.computer-systems.0.manufacturer',
        ]);
    }

    private function extractModel(array $device): string
    {
        return $this->extractFirstString($device, [
            'model',
            'system.model',
            'hardware.model',
            'chassis.model',
            'computerSystem.model',
            'ninjaone_reports.computer-systems.0.model',
            'references.role.name',
        ]);
    }

    private function extractComputerType(array $device): string
    {
        $candidate = $this->extractFirstString($device, [
            'chassis.type',
            'chassisType',
            'system.chassisType',
            'computerSystem.chassisType',
            'system.type',
            'hardware.type',
            'deviceType',
            'nodeClass',
            'ninjaone_reports.computer-systems.0.chassisType',
        ]);

        $normalized = strtoupper(str_replace([' ', '-'], '_', $candidate));
        if (str_contains($normalized, 'SERVER')) {
            return 'Server';
        }
        if (str_contains($normalized, 'LAPTOP') || str_contains($normalized, 'NOTEBOOK') || str_contains($normalized, 'PORTABLE')) {
            return 'Laptop';
        }
        if (str_contains($normalized, 'WORKSTATION') || str_contains($normalized, 'DESKTOP')) {
            return 'Workstation';
        }
        if (str_contains($normalized, 'MAC')) {
            return 'Mac';
        }

        return $candidate;
    }

    private function getOrCreateDropdownId(string $className, string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '' || !class_exists($className) || !is_subclass_of($className, \CommonDBTM::class)) {
            return 0;
        }

        $table = $className::getTable();
        if (!$DB->tableExists($table)) {
            return 0;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]);
        if (count($existing) > 0) {
            $row = $existing->current();
            return (int) $row['id'];
        }

        $item = new $className();
        $id = (int) $item->add(['name' => $name]);

        return $id > 0 ? $id : 0;
    }

    private function getMappedLocationId(int $configId, array $device): ?int
    {
        global $DB;

        if (!isset($device['locationId'])) {
            return null;
        }

        $rows = $DB->request([
            'SELECT' => ['locations_id'],
            'FROM'   => 'glpi_plugin_ninjaone_locationmappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_location_id'       => (int) $device['locationId'],
                'sync_enabled'               => 1,
            ],
            'LIMIT' => 1,
        ]);

        if (count($rows) === 0) {
            return null;
        }

        $row = $rows->current();
        if ($row['locations_id'] === null || (int) $row['locations_id'] <= 0) {
            return null;
        }

        return (int) $row['locations_id'];
    }

    private function upsertComputerOperatingSystem(int $computerId, array $device): void
    {
        global $DB;

        if ($computerId <= 0
            || !$DB->tableExists('glpi_items_operatingsystems')
            || !$DB->fieldExists('glpi_items_operatingsystems', 'items_id')
            || !$DB->fieldExists('glpi_items_operatingsystems', 'itemtype')) {
            return;
        }

        $osName = $this->extractFirstString($device, [
            'name',
            'os.name',
            'osName',
            'os',
            'operatingSystem',
            'operatingSystem.name',
            'operatingSystemName',
        ]);
        if ($osName === '') {
            return;
        }

        $data = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
        ];

        $this->addRelationFieldIfExists($data, 'operatingsystems_id', $this->getOrCreateDropdownId('OperatingSystem', $osName), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemversions_id', $this->getOrCreateDropdownId('OperatingSystemVersion', $this->extractFirstString($device, [
            'os.version',
            'osVersion',
            'operatingSystem.version',
            'operatingSystemVersion',
            'releaseId',
            'buildNumber',
            'os.buildNumber',
            'os.releaseId',
            'ninjaone_reports.operating-systems.0.buildNumber',
            'ninjaone_reports.operating-systems.0.releaseId',
        ])), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemarchitectures_id', $this->getOrCreateDropdownId('OperatingSystemArchitecture', $this->extractFirstString($device, [
            'os.architecture',
            'osArchitecture',
            'architecture',
            'system.architecture',
            'ninjaone_reports.operating-systems.0.architecture',
        ])), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemservicepacks_id', $this->getOrCreateDropdownId('OperatingSystemServicePack', $this->extractFirstString($device, [
            'os.servicePack',
            'servicePack',
            'operatingSystem.servicePack',
            'servicePackMajorVersion',
            'os.servicePackMajorVersion',
            'ninjaone_reports.operating-systems.0.servicePackMajorVersion',
        ])), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemkernels_id', $this->getOrCreateDropdownId('OperatingSystemKernel', $this->extractFirstString($device, [
            'os.kernel',
            'kernel',
            'operatingSystem.kernel',
        ])), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemeditions_id', $this->getOrCreateDropdownId('OperatingSystemEdition', $this->extractFirstString($device, [
            'os.edition',
            'edition',
            'operatingSystem.edition',
        ])), 'glpi_items_operatingsystems');

        $serial = $this->extractFirstString($device, [
            'os.serialNumber',
            'os.productKey',
            'operatingSystem.serialNumber',
            'operatingSystem.productKey',
        ]);
        if ($serial !== '' && $DB->fieldExists('glpi_items_operatingsystems', 'license_number')) {
            $data['license_number'] = $serial;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_items_operatingsystems',
            'WHERE'  => [
                'items_id' => $computerId,
                'itemtype' => 'Computer',
            ],
            'LIMIT' => 1,
        ]);

        if (count($existing) > 0) {
            $row = $existing->current();
            $DB->update('glpi_items_operatingsystems', $data, ['id' => (int) $row['id']]);
            return;
        }

        $DB->insert('glpi_items_operatingsystems', $data);
    }

    private function syncAdvancedInventory(int $configId, NinjaOneClient $client, array $organizationIds, SyncResult $result): void
    {
        $deviceMap = $this->getMappedComputerIdsByDeviceId($configId);
        if ($deviceMap === []) {
            $result->messages[] = 'Advanced inventory skipped: no mapped GLPI computer found.';
            return;
        }
        $devicePayloads = $this->getMappedDevicePayloadsByDeviceId($configId);

        $reports = [
            'computer-systems',
            'operating-systems',
            'network-interfaces',
            'processors',
            'disks',
            'volumes',
            'software',
            'logged-on-users',
        ];

        foreach ($reports as $reportName) {
            try {
                $fallbackUsed = false;
                $rows = $this->fetchQueryForOrganizations($client, $reportName, $organizationIds, array_keys($deviceMap), $fallbackUsed);
                $result->messages[] = sprintf('Fetched %d NinjaOne %s report rows.', count($rows), $reportName);
                if ($fallbackUsed) {
                    $result->messages[] = sprintf('NinjaOne %s report was filtered locally after unfiltered query fallback.', $reportName);
                }
            } catch (\Throwable $e) {
                $result->messages[] = sprintf('NinjaOne %s report skipped: %s', $reportName, $e->getMessage());
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $deviceId = $this->extractDeviceId($row);
                if ($deviceId <= 0 || !isset($deviceMap[$deviceId])) {
                    continue;
                }

                $computerId = $deviceMap[$deviceId];
                $this->mergeReportPayload($configId, $deviceId, $reportName, $row);

                try {
                    match ($reportName) {
                        'computer-systems'    => $this->applyComputerSystemReport($computerId, $row),
                        'operating-systems'   => $this->upsertComputerOperatingSystem($computerId, $row),
                        'network-interfaces'  => $this->upsertComputerNetworkInterface($computerId, $row, $devicePayloads[$deviceId] ?? []),
                        'processors'          => $this->upsertComputerProcessor($computerId, $row),
                        'disks'               => $this->upsertComputerHardDrive($computerId, $row),
                        'volumes'             => $this->upsertComputerVolume($computerId, $row),
                        'software'            => $this->upsertComputerSoftware($computerId, $row),
                        'logged-on-users'     => $this->applyLastLoggedOnUser($computerId, $row),
                        default               => null,
                    };
                } catch (\Throwable $e) {
                    $result->messages[] = sprintf(
                        'NinjaOne %s row skipped for device %d: %s',
                        $reportName,
                        $deviceId,
                        $e->getMessage()
                    );
                }
            }
        }
    }

    private function fetchQueryForOrganizations(
        NinjaOneClient $client,
        string $queryName,
        array $organizationIds,
        array $mappedDeviceIds,
        bool &$fallbackUsed
    ): array
    {
        $mappedDeviceIds = array_map('intval', $mappedDeviceIds);
        $fallbackUsed = false;

        try {
            $filteredRows = $client->listQueryForDeviceFilter($queryName, $this->buildOrganizationDeviceFilter($organizationIds));
            $filteredRows = $this->filterReportRowsForScope($filteredRows, $organizationIds, $mappedDeviceIds);
            if ($filteredRows !== []) {
                return $filteredRows;
            }
        } catch (\Throwable) {
            // Some NinjaOne query endpoints do not accept the same device filter syntax as devices-detailed.
        }

        $fallbackUsed = true;
        $rows = $client->listQuery($queryName);
        return $this->filterReportRowsForScope($rows, $organizationIds, $mappedDeviceIds);
    }

    private function filterReportRowsForScope(array $rows, array $organizationIds, array $mappedDeviceIds): array
    {
        return array_values(array_filter(
            $rows,
            function (mixed $row) use ($organizationIds, $mappedDeviceIds): bool {
                if (!is_array($row)) {
                    return false;
                }

                $deviceId = $this->extractDeviceId($row);
                if ($deviceId > 0 && in_array($deviceId, $mappedDeviceIds, true)) {
                    return true;
                }

                $organizationId = $this->extractOrganizationId($row);
                return $organizationId > 0 && in_array($organizationId, $organizationIds, true);
            }
        ));
    }

    private function getMappedComputerIdsByDeviceId(int $configId): array
    {
        global $DB;

        $map = [];
        $rows = $DB->request([
            'SELECT' => ['ninjaone_device_id', 'computers_id'],
            'FROM'   => 'glpi_plugin_ninjaone_devicemappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
            ],
        ]);

        foreach ($rows as $row) {
            $deviceId = (int) $row['ninjaone_device_id'];
            $computerId = (int) $row['computers_id'];
            if ($deviceId > 0 && $computerId > 0) {
                $map[$deviceId] = $computerId;
            }
        }

        return $map;
    }

    private function getMappedDevicePayloadsByDeviceId(int $configId): array
    {
        global $DB;

        $payloads = [];
        $rows = $DB->request([
            'SELECT' => ['ninjaone_device_id', 'last_payload_json'],
            'FROM'   => 'glpi_plugin_ninjaone_devicemappings',
            'WHERE'  => [
                'plugin_ninjaone_configs_id' => $configId,
            ],
        ]);

        foreach ($rows as $row) {
            $deviceId = (int) $row['ninjaone_device_id'];
            $payload = json_decode((string) ($row['last_payload_json'] ?? ''), true);
            if ($deviceId > 0 && is_array($payload)) {
                $payloads[$deviceId] = $payload;
            }
        }

        return $payloads;
    }

    private function mergeReportPayload(int $configId, int $deviceId, string $reportName, array $row): void
    {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_devicemappings',
            'WHERE' => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_device_id'         => $deviceId,
            ],
            'LIMIT' => 1,
        ]);

        if (count($existing) === 0) {
            return;
        }

        $mapping = $existing->current();
        $payload = json_decode((string) ($mapping['last_payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        if (!isset($payload['ninjaone_reports']) || !is_array($payload['ninjaone_reports'])) {
            $payload['ninjaone_reports'] = [];
        }
        if (!isset($payload['ninjaone_reports'][$reportName]) || !is_array($payload['ninjaone_reports'][$reportName])) {
            $payload['ninjaone_reports'][$reportName] = [];
        }
        $encodedRow = json_encode($row);
        foreach ($payload['ninjaone_reports'][$reportName] as $existingRow) {
            if (is_array($existingRow) && json_encode($existingRow) === $encodedRow) {
                return;
            }
        }
        $payload['ninjaone_reports'][$reportName][] = $row;

        $DB->update(
            'glpi_plugin_ninjaone_devicemappings',
            [
                'last_payload_json' => json_encode($payload),
                'last_payload_hash' => hash('sha256', json_encode($payload)),
            ],
            ['id' => (int) $mapping['id']]
        );
    }

    private function applyComputerSystemReport(int $computerId, array $row): void
    {
        $input = ['id' => $computerId];

        $serial = $this->extractFirstString($row, [
            'serialNumber',
            'serial',
            'biosSerialNumber',
            'assetSerialNumber',
            'system.serialNumber',
            'computerSystem.serialNumber',
        ]);
        if ($serial !== '') {
            $input['serial'] = $serial;
        }

        $uuid = $this->extractFirstString($row, [
            'uuid',
            'uid',
            'systemUuid',
            'system.uuid',
            'computerSystem.uuid',
        ]);
        if ($uuid !== '') {
            $input['uuid'] = $uuid;
        }

        $this->addComputerFieldIfExists($input, 'manufacturers_id', $this->getOrCreateDropdownId('Manufacturer', $this->extractManufacturer($row)));
        $this->addComputerFieldIfExists($input, 'computermodels_id', $this->getOrCreateDropdownId('ComputerModel', $this->extractModel($row)));
        $this->addComputerFieldIfExists($input, 'computertypes_id', $this->getOrCreateDropdownId('ComputerType', $this->extractComputerType($row)));

        $assetTag = $this->extractFirstString($row, [
            'assetTag',
            'inventoryNumber',
            'chassis.assetTag',
            'system.assetTag',
            'assetSerialNumber',
            'system.assetSerialNumber',
        ]);
        if ($assetTag !== '') {
            $this->addComputerFieldIfExists($input, 'otherserial', $assetTag);
        }

        $computer = new \Computer();
        if (count($input) > 1 && $computer->getFromDB($computerId)) {
            $computer->update($input);
        }

        $this->upsertComputerMemory($computerId, $row);
    }

    private function applyLastLoggedOnUser(int $computerId, array $row): void
    {
        global $DB;

        if (!$DB->fieldExists('glpi_computers', 'users_id')) {
            return;
        }

        $userId = $this->findGlpiUserId($row);
        if ($userId <= 0) {
            return;
        }

        $computer = new \Computer();
        if ($computer->getFromDB($computerId)) {
            $computer->update([
                'id'       => $computerId,
                'users_id' => $userId,
            ]);
        }
    }

    private function appendComputerInventoryNote(int $computerId, string $section, string $line): void
    {
        if ($line === '') {
            return;
        }

        $computer = new \Computer();
        if (!$computer->getFromDB($computerId)) {
            return;
        }

        $comment = (string) ($computer->fields['comment'] ?? '');
        $marker = 'NinjaOne ' . $section . ': ' . $line;
        if (str_contains($comment, $marker)) {
            return;
        }

        $lines = array_filter(explode("\n", $comment), static function (string $existingLine) use ($section): bool {
            return !str_starts_with($existingLine, 'NinjaOne ' . $section . ': ');
        });
        $lines[] = $marker;

        $computer->update([
            'id'      => $computerId,
            'comment' => implode("\n", $lines),
        ]);
    }

    private function upsertComputerProcessor(int $computerId, array $row): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_deviceprocessors')
            || !$DB->tableExists('glpi_items_deviceprocessors')
            || !class_exists('DeviceProcessor')) {
            $this->appendComputerInventoryNote($computerId, 'Processor', $this->summarizeProcessor($row));
            return;
        }

        $name = $this->extractFirstString($row, ['name', 'model', 'processor', 'processorName', 'cpu.name']);
        if ($name === '') {
            return;
        }

        $frequencyMhz = $this->clockSpeedToMhz($this->extractFirstString($row, ['clockSpeed', 'maxClockSpeed', 'frequency', 'speed']));
        $cores = $this->extractFirstInt($row, ['numCores', 'cores', 'coreCount', 'numberOfCores']);
        $threads = $this->extractFirstInt($row, ['numLogicalCores', 'threads', 'logicalProcessors', 'numberOfLogicalProcessors']);

        $processorInput = [];
        $this->addFieldIfExists($processorInput, 'glpi_deviceprocessors', 'designation', $name);
        $this->addFieldIfExists($processorInput, 'glpi_deviceprocessors', 'name', $name);
        $this->addFieldIfExists($processorInput, 'glpi_deviceprocessors', 'frequence', $frequencyMhz);
        $this->addFieldIfExists($processorInput, 'glpi_deviceprocessors', 'frequency_default', $frequencyMhz);
        $this->addFieldIfExists($processorInput, 'glpi_deviceprocessors', 'nbcores_default', $cores);
        $this->addFieldIfExists($processorInput, 'glpi_deviceprocessors', 'nbthreads_default', $threads);

        $processorId = $this->getOrCreateDeviceComponentId('DeviceProcessor', 'glpi_deviceprocessors', $name, $processorInput);
        if ($processorId <= 0) {
            $this->appendComputerInventoryNote($computerId, 'Processor', $this->summarizeProcessor($row));
            return;
        }

        $linkData = [
            'items_id'            => $computerId,
            'itemtype'            => 'Computer',
            'deviceprocessors_id' => $processorId,
        ];
        $this->addFieldIfExists($linkData, 'glpi_items_deviceprocessors', 'frequency', $frequencyMhz);
        $this->addFieldIfExists($linkData, 'glpi_items_deviceprocessors', 'nbcores', $cores);
        $this->addFieldIfExists($linkData, 'glpi_items_deviceprocessors', 'nbthreads', $threads);
        $this->upsertDeviceComponentLink('glpi_items_deviceprocessors', 'deviceprocessors_id', $computerId, $processorId, $linkData);
    }

    private function upsertComputerMemory(int $computerId, array $row): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_devicememories')
            || !$DB->tableExists('glpi_items_devicememories')
            || !class_exists('DeviceMemory')) {
            return;
        }

        $sizeMib = $this->bytesToMib($this->extractFirstString($row, [
            'totalPhysicalMemory',
            'memory.capacity',
            'memory',
            'capacity',
        ]));
        if ($sizeMib <= 0) {
            return;
        }

        $name = 'NinjaOne reported memory';
        $memoryInput = [];
        $this->addFieldIfExists($memoryInput, 'glpi_devicememories', 'designation', $name);
        $this->addFieldIfExists($memoryInput, 'glpi_devicememories', 'name', $name);
        $this->addFieldIfExists($memoryInput, 'glpi_devicememories', 'size_default', $sizeMib);

        $memoryId = $this->getOrCreateDeviceComponentId('DeviceMemory', 'glpi_devicememories', $name, $memoryInput);
        if ($memoryId <= 0) {
            return;
        }

        $linkData = [
            'items_id'           => $computerId,
            'itemtype'           => 'Computer',
            'devicememories_id'  => $memoryId,
        ];
        $this->addFieldIfExists($linkData, 'glpi_items_devicememories', 'size', $sizeMib);
        $this->upsertDeviceComponentLink('glpi_items_devicememories', 'devicememories_id', $computerId, $memoryId, $linkData);
    }

    private function upsertComputerHardDrive(int $computerId, array $row): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_deviceharddrives')
            || !$DB->tableExists('glpi_items_deviceharddrives')
            || !class_exists('DeviceHardDrive')) {
            $this->appendComputerInventoryNote($computerId, 'Disk', $this->summarizeStorage($row));
            return;
        }

        $name = $this->extractFirstString($row, ['model', 'name', 'description']);
        if ($name === '') {
            return;
        }

        $capacityMib = $this->bytesToMib($this->extractFirstString($row, ['size', 'capacity', 'totalSize', 'bytesTotal']));
        $serial = $this->extractFirstString($row, ['serialNumber', 'serial']);
        $manufacturer = $this->extractFirstString($row, ['manufacturer']);

        $driveInput = [];
        $this->addFieldIfExists($driveInput, 'glpi_deviceharddrives', 'designation', $name);
        $this->addFieldIfExists($driveInput, 'glpi_deviceharddrives', 'name', $name);
        $this->addFieldIfExists($driveInput, 'glpi_deviceharddrives', 'capacity_default', $capacityMib);
        $this->addFieldIfExists($driveInput, 'glpi_deviceharddrives', 'manufacturers_id', $this->getOrCreateDropdownId('Manufacturer', $manufacturer));

        $driveId = $this->getOrCreateDeviceComponentId('DeviceHardDrive', 'glpi_deviceharddrives', $name, $driveInput);
        if ($driveId <= 0) {
            $this->appendComputerInventoryNote($computerId, 'Disk', $this->summarizeStorage($row));
            return;
        }

        $linkData = [
            'items_id'            => $computerId,
            'itemtype'            => 'Computer',
            'deviceharddrives_id' => $driveId,
        ];
        $this->addFieldIfExists($linkData, 'glpi_items_deviceharddrives', 'capacity', $capacityMib);
        $this->addFieldIfExists($linkData, 'glpi_items_deviceharddrives', 'serial', $serial);
        $this->upsertDeviceComponentLink('glpi_items_deviceharddrives', 'deviceharddrives_id', $computerId, $driveId, $linkData);
    }

    private function upsertComputerVolume(int $computerId, array $row): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_items_disks') || !class_exists('Item_Disk')) {
            $this->appendComputerInventoryNote($computerId, 'Volume', $this->summarizeStorage($row));
            return;
        }

        $device = $this->extractFirstString($row, ['name', 'driveLetter', 'label']);
        $mountpoint = $this->extractFirstString($row, ['driveLetter', 'mountPoint', 'name']);
        if ($device === '' && $mountpoint === '') {
            return;
        }

        $data = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
        ];
        $this->addFieldIfExists($data, 'glpi_items_disks', 'name', $device !== '' ? $device : $mountpoint);
        $this->addFieldIfExists($data, 'glpi_items_disks', 'device', $device);
        $this->addFieldIfExists($data, 'glpi_items_disks', 'mountpoint', $mountpoint);
        $this->addFieldIfExists($data, 'glpi_items_disks', 'fsname', $this->extractFirstString($row, ['fileSystem', 'filesystem', 'fsType']));
        $this->addFieldIfExists($data, 'glpi_items_disks', 'totalsize', $this->bytesToMib($this->extractFirstString($row, ['capacity', 'size', 'totalSize', 'bytesTotal'])));
        $this->addFieldIfExists($data, 'glpi_items_disks', 'freesize', $this->bytesToMib($this->extractFirstString($row, ['freeSpace', 'bytesFree', 'available'])));
        $this->addFieldIfExists($data, 'glpi_items_disks', 'is_dynamic', 1);
        $this->addFieldIfExists($data, 'glpi_items_disks', 'is_deleted', 0);

        $where = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
        ];
        if ($DB->fieldExists('glpi_items_disks', 'mountpoint') && $mountpoint !== '') {
            $where['mountpoint'] = $mountpoint;
        } elseif ($DB->fieldExists('glpi_items_disks', 'device') && $device !== '') {
            $where['device'] = $device;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_items_disks',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);

        $volume = new \Item_Disk();
        if (count($existing) > 0) {
            $existingRow = $existing->current();
            $data['id'] = (int) $existingRow['id'];
            $volume->update($data);
            return;
        }

        $volume->add($data);
    }

    private function summarizeProcessor(array $row): string
    {
        $name = $this->extractFirstString($row, [
            'name',
            'model',
            'processor',
            'processorName',
            'cpu.name',
        ]);
        $cores = $this->extractFirstString($row, [
            'cores',
            'coreCount',
            'numCores',
            'numberOfCores',
        ]);
        $threads = $this->extractFirstString($row, [
            'threads',
            'numLogicalCores',
            'logicalProcessors',
            'numberOfLogicalProcessors',
        ]);

        return trim(implode(' ', array_filter([
            $name,
            $cores !== '' ? '(' . $cores . ' cores' : '',
            $threads !== '' ? $threads . ' threads)' : ($cores !== '' ? ')' : ''),
        ])));
    }

    private function summarizeStorage(array $row): string
    {
        $name = $this->extractFirstString($row, [
            'name',
            'caption',
            'label',
            'model',
            'driveLetter',
            'mountPoint',
        ]);
        $size = $this->extractFirstString($row, [
            'size',
            'capacity',
            'totalSize',
            'bytesTotal',
        ]);
        $free = $this->extractFirstString($row, [
            'freeSpace',
            'bytesFree',
            'available',
        ]);
        $filesystem = $this->extractFirstString($row, [
            'fileSystem',
            'filesystem',
            'fsType',
        ]);

        return trim(implode(' | ', array_filter([
            $name,
            $size !== '' ? 'size=' . $this->formatBytesForComment($size) : '',
            $free !== '' ? 'free=' . $this->formatBytesForComment($free) : '',
            $filesystem,
        ])));
    }

    private function formatBytesForComment(string $value): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $bytes = (float) $value;
        if ($bytes < 1024) {
            return (string) (int) $bytes . ' B';
        }

        foreach (['KB', 'MB', 'GB', 'TB'] as $unit) {
            $bytes /= 1024;
            if ($bytes < 1024) {
                return number_format($bytes, 2, '.', ' ') . ' ' . $unit;
            }
        }

        return number_format($bytes, 2, '.', ' ') . ' PB';
    }

    private function findGlpiUserId(array $row): int
    {
        global $DB;

        $candidates = array_values(array_unique(array_filter([
            $this->extractFirstString($row, ['email', 'mail', 'user.email', 'lastLoggedOnUser.email']),
            $this->extractFirstString($row, ['login', 'username', 'userName', 'lastLoggedInUser', 'user.name', 'lastLoggedOnUser.userName']),
            $this->extractFirstString($row, ['domainUser', 'lastLoggedOnUser.domainUser']),
        ])));

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $login = str_contains($candidate, '\\') ? substr($candidate, strrpos($candidate, '\\') + 1) : $candidate;

            foreach (['email', 'name'] as $field) {
                if (!$DB->fieldExists('glpi_users', $field)) {
                    continue;
                }
                $rows = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => [$field => $field === 'name' ? $login : $candidate],
                    'LIMIT'  => 1,
                ]);
                if (count($rows) > 0) {
                    $row = $rows->current();
                    return (int) $row['id'];
                }
            }
        }

        return 0;
    }

    private function upsertComputerNetworkInterface(int $computerId, array $row, array $devicePayload = []): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_networkports')
            || !$DB->fieldExists('glpi_networkports', 'items_id')
            || !$DB->fieldExists('glpi_networkports', 'itemtype')) {
            return;
        }

        $mac = $this->normalizeMac($this->extractFirstString($row, [
            'macAddress',
            'macAddress.0',
            'mac',
            'physicalAddress',
            'adapter.macAddress',
            'networkInterface.macAddress',
        ]));
        $name = $this->extractFirstString($row, [
            'name',
            'displayName',
            'interfaceName',
            'adapterName',
            'networkInterface.name',
        ]);
        if ($name === '') {
            $name = $this->extractFirstString($row, ['interfaceIndex']);
            if ($name !== '') {
                $name = 'Interface ' . $name;
            }
        }
        if ($name === '' && $mac !== '') {
            $name = $mac;
        }
        if ($name === '' && $mac === '') {
            return;
        }

        $where = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
        ];
        if ($mac !== '' && $DB->fieldExists('glpi_networkports', 'mac')) {
            $where['mac'] = $mac;
        } elseif ($DB->fieldExists('glpi_networkports', 'name')) {
            $where['name'] = $name;
        }

        $data = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
        ];
        if ($DB->fieldExists('glpi_networkports', 'name')) {
            $data['name'] = $name;
        }
        if ($mac !== '' && $DB->fieldExists('glpi_networkports', 'mac')) {
            $data['mac'] = $mac;
        }
        if ($DB->fieldExists('glpi_networkports', 'instantiation_type')) {
            $data['instantiation_type'] = 'NetworkPortEthernet';
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_networkports',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);
        if (count($existing) > 0) {
            $networkPort = $existing->current();
            $networkPortId = (int) $networkPort['id'];
            $DB->update('glpi_networkports', $data, ['id' => $networkPortId]);
            $this->upsertNetworkPortIpAddresses($networkPortId, $row, $devicePayload);
            return;
        }

        $DB->insert('glpi_networkports', $data);
        $created = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_networkports',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);
        if (count($created) > 0) {
            $createdPort = $created->current();
            $this->upsertNetworkPortIpAddresses((int) $createdPort['id'], $row, $devicePayload);
        }
    }

    private function upsertNetworkPortIpAddresses(int $networkPortId, array $row, array $devicePayload = []): void
    {
        global $DB;

        if ($networkPortId <= 0
            || !$DB->tableExists('glpi_networknames')
            || !$DB->tableExists('glpi_ipaddresses')
            || !class_exists('NetworkName')) {
            return;
        }

        $ipAddresses = $this->extractIpAddressList($row, ['ipAddress', 'ipAddresses', 'networkInterface.ipAddress']);
        if ($ipAddresses === []) {
            return;
        }

        $fqdnParts = $this->extractFqdnParts($row, $devicePayload);
        $name = $fqdnParts[0] ?? '';
        $domain = $fqdnParts[1] ?? '';
        $fqdnId = $domain !== '' ? $this->getOrCreateFqdnId($domain) : 0;

        $where = [
            'itemtype' => 'NetworkPort',
            'items_id' => $networkPortId,
        ];
        if ($name !== '' && $DB->fieldExists('glpi_networknames', 'name')) {
            $where['name'] = $name;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_networknames',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);

        $input = [
            'itemtype'     => 'NetworkPort',
            'items_id'     => $networkPortId,
            '_ipaddresses' => $this->buildIpAddressInput($ipAddresses),
        ];
        if ($name !== '') {
            $this->addFieldIfExists($input, 'glpi_networknames', 'name', $name);
        }
        if ($fqdnId > 0) {
            $this->addFieldIfExists($input, 'glpi_networknames', 'fqdns_id', $fqdnId);
        }

        $networkName = new \NetworkName();
        if (count($existing) > 0) {
            $networkNameRow = $existing->current();
            $input['id'] = (int) $networkNameRow['id'];
            $networkName->update($input);
            return;
        }

        $networkName->add($input);
    }

    private function upsertComputerSoftware(int $computerId, array $row): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_softwares')
            || !$DB->tableExists('glpi_softwareversions')
            || !$DB->tableExists('glpi_items_softwareversions')
            || !$DB->fieldExists('glpi_items_softwareversions', 'items_id')
            || !$DB->fieldExists('glpi_items_softwareversions', 'itemtype')
            || !$DB->fieldExists('glpi_items_softwareversions', 'softwareversions_id')) {
            return;
        }

        $softwareName = $this->extractFirstString($row, [
            'name',
            'softwareName',
            'displayName',
            'productName',
            'software.name',
        ]);
        if ($softwareName === '') {
            return;
        }

        $versionName = $this->extractFirstString($row, [
            'version',
            'softwareVersion',
            'productVersion',
            'software.version',
        ]);
        if ($versionName === '') {
            $versionName = 'unknown';
        }

        $softwareId = $this->getOrCreateSoftwareId($softwareName, $row);
        $versionId = $this->getOrCreateSoftwareVersionId($softwareId, $versionName);
        if ($softwareId <= 0 || $versionId <= 0) {
            return;
        }

        $where = [
            'items_id'            => $computerId,
            'itemtype'            => 'Computer',
            'softwareversions_id' => $versionId,
        ];
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_items_softwareversions',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);
        if (count($existing) > 0) {
            $existingRow = $existing->current();
            $data = ['id' => (int) $existingRow['id']];
            if ($DB->fieldExists('glpi_items_softwareversions', 'is_deleted')) {
                $data['is_deleted'] = 0;
            }
            if (class_exists('\\Item_SoftwareVersion')) {
                $installed = new \Item_SoftwareVersion();
                $installed->update($data);
            } else {
                $DB->update('glpi_items_softwareversions', $data, ['id' => (int) $existingRow['id']]);
            }
            return;
        }

        $data = $where;
        if ($DB->fieldExists('glpi_items_softwareversions', 'is_deleted')) {
            $data['is_deleted'] = 0;
        }
        if ($DB->fieldExists('glpi_items_softwareversions', 'date_install')) {
            $installedAt = $this->timestampToSqlDate($row['installDate'] ?? ($row['installedOn'] ?? null));
            if ($installedAt !== null) {
                $data['date_install'] = $installedAt;
            }
        }
        $computer = new \Computer();
        if ($computer->getFromDB($computerId)) {
            if ($DB->fieldExists('glpi_items_softwareversions', 'is_template_item')) {
                $data['is_template_item'] = $computer->maybeTemplate() ? (int) $computer->getField('is_template') : 0;
            }
            if ($DB->fieldExists('glpi_items_softwareversions', 'is_deleted_item')) {
                $data['is_deleted_item'] = $computer->maybeDeleted() ? (int) $computer->getField('is_deleted') : 0;
            }
        }

        if (class_exists('\\Item_SoftwareVersion')) {
            $installed = new \Item_SoftwareVersion();
            $installed->add($data);
            return;
        }

        $DB->insert('glpi_items_softwareversions', $data);
    }

    private function getOrCreateSoftwareId(string $name, array $row): int
    {
        global $DB;

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_softwares',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]);
        if (count($existing) > 0) {
            $software = $existing->current();
            return (int) $software['id'];
        }

        $data = ['name' => $name];
        $publisher = $this->extractFirstString($row, [
            'publisher',
            'vendor',
            'manufacturer',
            'software.publisher',
        ]);
        if ($publisher !== '' && $DB->fieldExists('glpi_softwares', 'manufacturers_id')) {
            $data['manufacturers_id'] = $this->getOrCreateDropdownId('Manufacturer', $publisher);
        }

        if (class_exists('\\Software')) {
            $software = new \Software();
            $id = (int) $software->add($data);
            return $id > 0 ? $id : 0;
        }

        $DB->insert('glpi_softwares', $data);
        $created = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_softwares',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]);
        if (count($created) > 0) {
            $software = $created->current();
            return (int) $software['id'];
        }

        return 0;
    }

    private function getOrCreateSoftwareVersionId(int $softwareId, string $version): int
    {
        global $DB;

        if ($softwareId <= 0) {
            return 0;
        }

        if (!$DB->fieldExists('glpi_softwareversions', 'softwares_id')
            || !$DB->fieldExists('glpi_softwareversions', 'name')) {
            return 0;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_softwareversions',
            'WHERE'  => [
                'softwares_id' => $softwareId,
                'name'         => $version,
            ],
            'LIMIT'  => 1,
        ]);
        if (count($existing) > 0) {
            $row = $existing->current();
            return (int) $row['id'];
        }

        $data = [
            'softwares_id' => $softwareId,
            'name'         => $version,
        ];
        if (class_exists('\\SoftwareVersion')) {
            $softwareVersion = new \SoftwareVersion();
            $id = (int) $softwareVersion->add($data);
            return $id > 0 ? $id : 0;
        }

        $DB->insert('glpi_softwareversions', $data);
        $created = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_softwareversions',
            'WHERE'  => [
                'softwares_id' => $softwareId,
                'name'         => $version,
            ],
            'LIMIT'  => 1,
        ]);
        if (count($created) > 0) {
            $row = $created->current();
            return (int) $row['id'];
        }

        return 0;
    }

    private function addRelationFieldIfExists(array &$data, string $field, int $value, string $table): void
    {
        global $DB;

        if ($value <= 0 || !$DB->fieldExists($table, $field)) {
            return;
        }

        $data[$field] = $value;
    }

    private function addFieldIfExists(array &$data, string $table, string $field, mixed $value): void
    {
        global $DB;

        if ($value === null || $value === '' || $value === 0 || !$DB->fieldExists($table, $field)) {
            return;
        }

        $data[$field] = $value;
    }

    private function getOrCreateDeviceComponentId(string $className, string $table, string $name, array $input): int
    {
        global $DB;

        if ($name === '' || !class_exists($className)) {
            return 0;
        }

        foreach (['designation', 'name'] as $field) {
            if (!$DB->fieldExists($table, $field)) {
                continue;
            }
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => $table,
                'WHERE'  => [$field => $name],
                'LIMIT'  => 1,
            ]);
            if (count($existing) > 0) {
                $row = $existing->current();
                return (int) $row['id'];
            }
        }

        $component = new $className();
        $id = (int) $component->add($input);

        return $id > 0 ? $id : 0;
    }

    private function getOrCreateFqdnId(string $domain): int
    {
        global $DB;

        $domain = strtolower(trim($domain));
        if ($domain === ''
            || !$this->isValidDnsDomain($domain)
            || !class_exists('FQDN')
            || !$DB->tableExists('glpi_fqdns')) {
            return 0;
        }

        $field = null;
        foreach (['fqdn', 'name'] as $candidate) {
            if ($DB->fieldExists('glpi_fqdns', $candidate)) {
                $field = $candidate;
                break;
            }
        }
        if ($field === null) {
            return 0;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_fqdns',
            'WHERE'  => [$field => $domain],
            'LIMIT'  => 1,
        ]);
        if (count($existing) > 0) {
            $row = $existing->current();
            return (int) $row['id'];
        }

        $fqdn = new \FQDN();
        $id = (int) $fqdn->add([$field => $domain]);

        return $id > 0 ? $id : 0;
    }

    private function upsertDeviceComponentLink(string $table, string $componentField, int $computerId, int $componentId, array $data): void
    {
        global $DB;

        if (!$DB->fieldExists($table, 'items_id')
            || !$DB->fieldExists($table, 'itemtype')
            || !$DB->fieldExists($table, $componentField)) {
            return;
        }

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => [
                'items_id'        => $computerId,
                'itemtype'        => 'Computer',
                $componentField   => $componentId,
            ],
            'LIMIT'  => 1,
        ]);

        if (count($existing) > 0) {
            $row = $existing->current();
            $DB->update($table, $data, ['id' => (int) $row['id']]);
            return;
        }

        $DB->insert($table, $data);
    }

    private function extractFirstInt(array $source, array $paths): int
    {
        foreach ($paths as $path) {
            $value = $this->getPathValue($source, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function clockSpeedToMhz(string $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $speed = (float) $value;
        if ($speed > 1000000) {
            return (int) round($speed / 1000000);
        }

        return (int) round($speed);
    }

    private function bytesToMib(string $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return (int) round(((float) $value) / 1024 / 1024);
    }

    private function extractIpAddressList(array $source, array $paths): array
    {
        $addresses = [];

        foreach ($paths as $path) {
            $value = $this->getPathValue($source, $path);
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_scalar($entry)) {
                        $addresses[] = trim((string) $entry);
                    }
                }
                continue;
            }
            if (is_scalar($value)) {
                $addresses[] = trim((string) $value);
            }
        }

        return array_values(array_unique(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false
        )));
    }

    private function buildIpAddressInput(array $ipAddresses): array
    {
        $input = [];
        $index = -1;
        foreach ($ipAddresses as $ipAddress) {
            $input[$index] = $ipAddress;
            --$index;
        }

        return $input;
    }

    private function extractFqdnParts(array $row, array $devicePayload): ?array
    {
        $fqdn = strtolower($this->extractFirstString($devicePayload, [
            'dnsName',
            'fqdn',
            'ninjaone_reports.network-interfaces.0.dnsName',
        ]));

        if ($fqdn !== '') {
            $parts = explode('.', $fqdn, 2);
            if (count($parts) === 2 && $this->isValidDnsDomain($parts[1])) {
                $normalizedLabel = $this->normalizeDnsLabel($parts[0]);
                if ($normalizedLabel !== '') {
                    return [$normalizedLabel, $parts[1]];
                }
            }
            if (!str_contains($fqdn, '.')) {
                $normalizedLabel = $this->normalizeDnsLabel($fqdn);
                if ($normalizedLabel !== '') {
                    return [$normalizedLabel, ''];
                }
            }
        }

        $host = strtolower($this->extractFirstString($row, ['dnsHostName', 'hostname']));
        $normalizedHost = $this->normalizeDnsLabel($host);
        $domain = $this->extractDomainFromPayload($devicePayload);
        if ($normalizedHost !== '' && $domain !== '' && $this->isValidDnsDomain($domain)) {
            return [$normalizedHost, $domain];
        }
        if ($normalizedHost !== '') {
            return [$normalizedHost, ''];
        }

        return null;
    }

    private function extractDomainFromPayload(array $devicePayload): string
    {
        $fqdn = strtolower($this->extractFirstString($devicePayload, ['dnsName', 'fqdn']));
        if ($fqdn !== '' && str_contains($fqdn, '.')) {
            return substr($fqdn, strpos($fqdn, '.') + 1);
        }

        return '';
    }

    private function isValidDnsLabel(string $label): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1;
    }

    private function normalizeDnsLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9-]+/', '-', $label) ?? '';
        $label = trim($label, '-');

        if (strlen($label) > 63) {
            $label = rtrim(substr($label, 0, 63), '-');
        }

        return $this->isValidDnsLabel($label) ? $label : '';
    }

    private function isValidDnsDomain(string $domain): bool
    {
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (!$this->isValidDnsLabel($label)) {
                return false;
            }
        }

        return true;
    }

    private function upsertSingleDeviceMapping(int $configId, array $device, int $computerId, string $status): void
    {
        global $DB;

        $deviceId = (int) $device['id'];
        $organizationId = isset($device['organizationId']) ? (int) $device['organizationId'] : null;
        $locationId = isset($device['locationId']) ? (int) $device['locationId'] : null;
        $lastSeenAt = $this->timestampToSqlDate($device['lastContact'] ?? null);
        $payloadHash = hash('sha256', json_encode($device));
        $now = date('Y-m-d H:i:s');

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_devicemappings',
            'WHERE' => [
                'plugin_ninjaone_configs_id' => $configId,
                'ninjaone_device_id'         => $deviceId,
            ],
            'LIMIT' => 1,
        ]);

        $data = [
            'ninjaone_organization_id' => $organizationId,
            'ninjaone_location_id'     => $locationId,
            'computers_id'             => $computerId,
            'last_seen_at'             => $lastSeenAt,
            'last_sync_at'             => $now,
            'last_payload_hash'        => $payloadHash,
            'last_payload_json'        => json_encode($device),
            'sync_status'              => $status,
        ];

        if (count($existing) > 0) {
            $row = $existing->current();
            if (empty($row['first_sync_at'])) {
                $data['first_sync_at'] = $now;
            }
            $DB->update('glpi_plugin_ninjaone_devicemappings', $data, ['id' => (int) $row['id']]);
            return;
        }

        $DB->insert('glpi_plugin_ninjaone_devicemappings', $data + [
            'plugin_ninjaone_configs_id' => $configId,
            'ninjaone_device_id'         => $deviceId,
            'first_sync_at'              => $now,
        ]);
    }

    private function extractFirstString(array $source, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->getPathValue($source, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function extractDeviceId(array $row): int
    {
        foreach ([
            'deviceId',
            'device_id',
            'nodeId',
            'id',
            'device.id',
            'node.id',
        ] as $path) {
            $value = $this->getPathValue($row, $path);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function extractOrganizationId(array $row): int
    {
        foreach ([
            'organizationId',
            'organization_id',
            'orgId',
            'device.organizationId',
            'organization.id',
        ] as $path) {
            $value = $this->getPathValue($row, $path);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function normalizeMac(string $mac): string
    {
        $mac = strtolower(trim($mac));
        $mac = preg_replace('/[^a-f0-9]/', '', $mac) ?? '';
        if (strlen($mac) !== 12) {
            return '';
        }

        return implode(':', str_split($mac, 2));
    }

    private function isComputerDevice(array $device): bool
    {
        $nodeClass = strtoupper((string) ($device['nodeClass'] ?? ''));
        if ($nodeClass === '') {
            return true;
        }

        $computerClasses = [
            'WINDOWS_WORKSTATION',
            'WINDOWS_SERVER',
            'MAC',
            'MAC_WORKSTATION',
            'MAC_SERVER',
            'LINUX',
            'LINUX_WORKSTATION',
            'LINUX_SERVER',
        ];

        return in_array($nodeClass, $computerClasses, true);
    }

    private function getPathValue(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function timestampToSqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) floor((float) $value);
            if ($timestamp > 0) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return null;
    }
}
