<?php

namespace GlpiPlugin\Ninjaone;

use CommonDBTM;
use CommonGLPI;
use Computer;

final class ComputerLink extends CommonDBTM
{
    public static $rightname = 'computer';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ninjaone_devicemappings';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('NinjaOne device', 'NinjaOne devices', $nb, 'ninjaone');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() !== Computer::class || (int) $item->getID() <= 0) {
            return '';
        }

        return self::getMappingForComputer((int) $item->getID()) === null
            ? ''
            : self::createTabEntry(__('NinjaOne', 'ninjaone'), 0, null, 'ti ti-cloud-network');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Computer::class) {
            self::showForComputer((int) $item->getID());
        }

        return true;
    }

    public static function preItemForm(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof CommonDBTM
            || $item->getType() !== Computer::class
            || (int) $item->getID() <= 0) {
            return;
        }

        $mapping = self::getMappingForComputer((int) $item->getID());
        if ($mapping === null) {
            return;
        }

        self::showHeader($mapping);
    }

    private static function showForComputer(int $computer_id): void
    {
        $mapping = self::getMappingForComputer($computer_id);
        if ($mapping === null) {
            echo '<div class="alert alert-info">' . __('This computer is not linked to NinjaOne.', 'ninjaone') . '</div>';
            return;
        }

        $payload = self::decodePayload((string) ($mapping['last_payload_json'] ?? ''));

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h3 class="card-title mb-0">' . __('NinjaOne details', 'ninjaone') . '</h3>';
        echo '</div>';

        echo '<div class="card-body">';
        echo '<div class="row g-3">';
        self::showInfoTile(__('Technical status', 'ninjaone'), (string) ($mapping['sync_status'] ?? ''));
        self::showInfoTile(__('Inventory source', 'ninjaone'), self::inventoryModeLabel((string) ($mapping['inventory_mode'] ?? 'mapping_only')));
        self::showInfoTile(__('First synchronization', 'ninjaone'), (string) ($mapping['first_sync_at'] ?? ''));
        self::showInfoTile(__('Last synchronization', 'ninjaone'), (string) ($mapping['last_sync_at'] ?? ''));
        self::showInfoTile(__('Last NinjaOne contact', 'ninjaone'), (string) ($mapping['last_seen_at'] ?? ''));
        self::showInfoTile(__('Configuration', 'ninjaone'), (string) ($mapping['config_name'] ?? ''));
        echo '</div>';

        echo '<hr>';
        echo '<div class="row g-3">';
        self::showInfoTile(__('Organization', 'ninjaone'), self::payloadValue($payload, ['organizationId']));
        self::showInfoTile(__('Location'), self::payloadValue($payload, ['locationId']));
        self::showInfoTile(__('Node class', 'ninjaone'), self::payloadValue($payload, ['nodeClass']));
        self::showInfoTile(__('System name', 'ninjaone'), self::payloadValue($payload, ['systemName', 'system.name']));
        self::showInfoTile(__('Serial number'), self::payloadValue($payload, ['system.serialNumber', 'system.biosSerialNumber']));
        self::showInfoTile(__('Last logged in user', 'ninjaone'), self::payloadValue($payload, ['lastLoggedInUser']));
        echo '</div>';

        echo '<hr>';
        echo '<details>';
        echo '<summary class="fw-semibold">' . __('Last raw payload', 'ninjaone') . '</summary>';
        echo '<pre class="mt-3 p-3 bg-light border rounded text-wrap" style="max-height: 520px; overflow: auto;">'
            . htmlspecialchars(self::formatPayload($payload), ENT_QUOTES, 'UTF-8')
            . '</pre>';
        echo '</details>';

        echo '</div>';
        echo '</div>';
    }

    private static function showHeader(array $mapping): void
    {
        echo '<div class="card mb-3">';
        echo '<div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">';
        echo '<div>';
        echo '<h3 class="card-title mb-1">' . __('NinjaOne', 'ninjaone') . '</h3>';
        echo '<div class="text-muted small">' . __('NinjaOne ID', 'ninjaone') . ': <code>'
            . (int) $mapping['ninjaone_device_id'] . '</code></div>';
        echo '</div>';
        echo '<div class="d-flex flex-wrap align-items-center gap-2">';
        self::showSyncHealthBadge($mapping);
        self::showOpenButton($mapping);
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private static function showOpenButton(array $mapping): void
    {
        $url = self::getNinjaOneUrl($mapping);
        if ($url === '') {
            return;
        }

        echo '<a class="btn btn-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" target="_blank" rel="noopener noreferrer">';
        echo '<i class="ti ti-external-link me-1"></i>';
        echo __('Open in NinjaOne', 'ninjaone');
        echo '</a>';
    }

    private static function showSyncHealthBadge(array $mapping): void
    {
        $health = self::buildSyncHealth($mapping);

        echo '<span class="badge ' . htmlspecialchars($health['class'], ENT_QUOTES, 'UTF-8') . '"'
            . ' style="user-select: text;"'
            . ' title="' . htmlspecialchars($health['title'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<i class="ti ' . htmlspecialchars($health['icon'], ENT_QUOTES, 'UTF-8') . ' me-1"></i>';
        echo htmlspecialchars($health['label'], ENT_QUOTES, 'UTF-8');
        echo '</span>';
    }

    private static function buildSyncHealth(array $mapping): array
    {
        $technicalStatus = trim((string) ($mapping['sync_status'] ?? ''));
        $lastSync = trim((string) ($mapping['last_sync_at'] ?? ''));
        $mode = (string) ($mapping['inventory_mode'] ?? 'mapping_only');
        $modeLabel = self::inventoryModeLabel($mode);
        $staleDays = max(1, (int) ($mapping['sync_stale_days'] ?? 20));

        $failedStatuses = ['computer_update_failed', 'computer_create_failed', 'error', 'failed'];
        if (in_array($technicalStatus, $failedStatuses, true)) {
            return [
                'label' => __('Mapping error', 'ninjaone'),
                'class' => 'bg-danger text-white',
                'icon'  => 'ti-alert-triangle',
                'title' => self::statusTitle($technicalStatus, $lastSync),
            ];
        }

        if ($lastSync === '' || $technicalStatus === 'pending' || $technicalStatus === 'pending_inventory_link') {
            return [
                'label' => __('Waiting link', 'ninjaone'),
                'class' => 'bg-secondary text-white',
                'icon'  => 'ti-clock-question',
                'title' => self::statusTitle($technicalStatus, $lastSync),
            ];
        }

        $ageDays = self::syncAgeDays($lastSync);
        if ($mode === 'full' && $ageDays !== null && $ageDays > $staleDays) {
            return [
                'label' => __('Stale sync', 'ninjaone'),
                'class' => 'bg-warning text-dark',
                'icon'  => 'ti-clock-exclamation',
                'title' => self::statusTitle($technicalStatus, $lastSync),
            ];
        }

        return [
            'label' => $modeLabel,
            'class' => 'bg-success text-white',
            'icon'  => 'ti-circle-check',
            'title' => self::statusTitle($technicalStatus, $lastSync),
        ];
    }

    private static function syncAgeDays(string $lastSync): ?int
    {
        try {
            $date = new \DateTimeImmutable($lastSync);
            $now = new \DateTimeImmutable();
        } catch (\Throwable) {
            return null;
        }

        return max(0, (int) $date->diff($now)->format('%a'));
    }

    private static function inventoryModeLabel(string $mode): string
    {
        return $mode === 'full'
            ? __('NinjaOne inventory', 'ninjaone')
            : __('NinjaOne linked', 'ninjaone');
    }

    private static function statusTitle(string $technicalStatus, string $lastSync): string
    {
        return sprintf(
            __('Technical status: %s. Last synchronization: %s', 'ninjaone'),
            $technicalStatus !== '' ? $technicalStatus : '-',
            $lastSync !== '' ? $lastSync : '-'
        );
    }

    private static function getMappingForComputer(int $computer_id): ?array
    {
        global $DB;

        if ($computer_id <= 0
            || !$DB->tableExists('glpi_plugin_ninjaone_devicemappings')
            || !$DB->tableExists('glpi_plugin_ninjaone_configs')) {
            return null;
        }

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_devicemappings',
            'WHERE' => ['computers_id' => $computer_id],
            'ORDER' => ['last_sync_at DESC'],
            'LIMIT' => 1,
        ]);
        if (count($rows) === 0) {
            return null;
        }

        $mapping = $rows->current();
        $configs = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_configs',
            'WHERE' => ['id' => (int) $mapping['plugin_ninjaone_configs_id']],
            'LIMIT' => 1,
        ]);
        if (count($configs) > 0) {
            $config = $configs->current();
            $mapping['base_url'] = (string) ($config['base_url'] ?? '');
            $mapping['config_name'] = (string) ($config['name'] ?? '');
            $mapping['inventory_mode'] = (string) ($config['inventory_mode'] ?? 'mapping_only');
            $mapping['sync_stale_days'] = (int) ($config['sync_stale_days'] ?? 20);
        }

        return $mapping;
    }

    private static function getNinjaOneUrl(array $mapping): string
    {
        $device_id = (int) ($mapping['ninjaone_device_id'] ?? 0);
        $base_url = rtrim((string) ($mapping['base_url'] ?? ''), '/');

        return $device_id > 0 && $base_url !== ''
            ? $base_url . '/#/deviceDashboard/' . $device_id . '/overview'
            : '';
    }

    private static function showInfoTile(string $label, string $value): void
    {
        echo '<div class="col-md-4 col-xl-2">';
        echo '<div class="text-muted small">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="fw-semibold text-break">' . htmlspecialchars($value !== '' ? $value : '-', ENT_QUOTES, 'UTF-8') . '</div>';
        echo '</div>';
    }

    private static function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function formatPayload(array $payload): string
    {
        if ($payload === []) {
            return __('No payload stored yet.', 'ninjaone');
        }

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function payloadValue(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = self::getPathValue($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private static function getPathValue(array $source, string $path): mixed
    {
        $current = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
