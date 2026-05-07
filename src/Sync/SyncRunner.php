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
            'message'       => implode("\n", $result->messages),
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
            'system.serialNumber',
            'system.serial',
            'hardware.serialNumber',
            'hardware.serial',
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
            'system.serialNumber',
            'system.serial',
            'hardware.serialNumber',
            'hardware.serial',
        ]);

        $uuid = $this->extractFirstString($device, [
            'uuid',
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
        ]);
        $osVersion = $this->extractFirstString($device, [
            'os.version',
            'osVersion',
            'operatingSystem.version',
            'operatingSystemVersion',
        ]);
        $architecture = $this->extractFirstString($device, [
            'os.architecture',
            'osArchitecture',
            'architecture',
            'system.architecture',
        ]);
        $processor = $this->extractFirstString($device, [
            'processor',
            'cpu',
            'processors.0.name',
            'hardware.processor',
        ]);
        $memory = $this->extractFirstString($device, [
            'memory',
            'totalMemory',
            'memory.total',
            'hardware.memory',
        ]);
        $ipAddress = $this->extractFirstString($device, [
            'ipAddress',
            'lastIpAddress',
            'publicIP',
            'publicIp',
            'networkInterfaces.0.ipAddress',
            'networkAdapters.0.ipAddress',
        ]);
        $macAddress = $this->extractFirstString($device, [
            'macAddress',
            'networkInterfaces.0.macAddress',
            'networkAdapters.0.macAddress',
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
            $lines[] = 'Memory: ' . $memory;
        }
        if ($ipAddress !== '') {
            $lines[] = 'IP address: ' . $ipAddress;
        }
        if ($macAddress !== '') {
            $lines[] = 'MAC address: ' . $macAddress;
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
        ]);
    }

    private function extractModel(array $device): string
    {
        return $this->extractFirstString($device, [
            'model',
            'system.model',
            'hardware.model',
            'chassis.model',
            'references.role.name',
        ]);
    }

    private function extractComputerType(array $device): string
    {
        $candidate = $this->extractFirstString($device, [
            'chassis.type',
            'system.type',
            'hardware.type',
            'deviceType',
            'nodeClass',
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
        ])), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemarchitectures_id', $this->getOrCreateDropdownId('OperatingSystemArchitecture', $this->extractFirstString($device, [
            'os.architecture',
            'osArchitecture',
            'architecture',
            'system.architecture',
        ])), 'glpi_items_operatingsystems');
        $this->addRelationFieldIfExists($data, 'operatingsystemservicepacks_id', $this->getOrCreateDropdownId('OperatingSystemServicePack', $this->extractFirstString($device, [
            'os.servicePack',
            'servicePack',
            'operatingSystem.servicePack',
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

    private function addRelationFieldIfExists(array &$data, string $field, int $value, string $table): void
    {
        global $DB;

        if ($value <= 0 || !$DB->fieldExists($table, $field)) {
            return;
        }

        $data[$field] = $value;
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
            $DB->update('glpi_plugin_ninjaone_devicemappings', $data, ['id' => (int) $row['id']]);
            return;
        }

        $DB->insert('glpi_plugin_ninjaone_devicemappings', $data + [
            'plugin_ninjaone_configs_id' => $configId,
            'ninjaone_device_id'         => $deviceId,
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
