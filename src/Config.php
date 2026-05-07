<?php

namespace GlpiPlugin\Ninjaone;

use CommonDBTM;
use Dropdown;
use Html;

class Config extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ninjaone_configs';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('NinjaOne connection', 'NinjaOne connections', $nb, 'ninjaone');
    }

    public static function getMenuName($nb = 0): string
    {
        return __('NinjaOne', 'ninjaone');
    }

    public static function getMenuContent(): array
    {
        return [
            'title' => self::getMenuName(),
            'page'  => self::getSearchURL(false),
            'icon'  => 'ti ti-cloud-network',
        ];
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $name = htmlspecialchars((string) ($this->fields['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $base_url = htmlspecialchars((string) ($this->fields['base_url'] ?? 'https://eu.ninjarmm.com'), ENT_QUOTES, 'UTF-8');
        $scopes = htmlspecialchars((string) ($this->fields['scopes'] ?? 'monitoring'), ENT_QUOTES, 'UTF-8');
        $client_id = htmlspecialchars((string) ($this->fields['client_id'] ?? ''), ENT_QUOTES, 'UTF-8');

        echo '<tr>';
        echo '<th>' . __('Name') . '</th>';
        echo '<td>';
        echo '<input type="text" name="name" value="' . $name . '" class="form-control">';
        echo '</td>';
        echo '<th>' . __('Active') . '</th>';
        echo '<td>';
        Dropdown::showYesNo('is_active', (int) ($this->fields['is_active'] ?? 0));
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th>' . __('Base URL', 'ninjaone') . '</th>';
        echo '<td>';
        echo '<input type="text" name="base_url" value="' . $base_url . '" class="form-control">';
        echo '</td>';
        echo '<th>' . __('Scopes', 'ninjaone') . '</th>';
        echo '<td>';
        echo '<input type="text" name="scopes" value="' . $scopes . '" class="form-control">';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th>' . __('Client ID', 'ninjaone') . '</th>';
        echo '<td>';
        echo '<input type="text" name="client_id" value="' . $client_id . '" class="form-control">';
        echo '</td>';
        echo '<th>' . __('Client secret', 'ninjaone') . '</th>';
        echo '<td>';
        echo '<input type="password" name="client_secret" value="" class="form-control" autocomplete="new-password">';
        echo '</td>';
        echo '</tr>';

        if ((int) ($this->fields['id'] ?? 0) > 0) {
            echo '<tr>';
            echo '<td class="center" colspan="4">';
            echo '<input type="hidden" name="id" value="' . (int) $this->fields['id'] . '">';
            echo Html::submit(__('Test NinjaOne connection', 'ninjaone'), [
                'name'  => 'test_connection',
                'class' => 'btn btn-secondary',
            ]);
            echo ' ';
            echo Html::submit(__('Run NinjaOne synchronization', 'ninjaone'), [
                'name'  => 'run_sync',
                'class' => 'btn btn-primary',
            ]);
            echo '</td>';
            echo '</tr>';
        }

        $this->showFormButtons($options);

        return true;
    }

    public function prepareInputForAdd($input): array
    {
        return $this->normalizeInput($input);
    }

    public function prepareInputForUpdate($input): array
    {
        return $this->normalizeInput($input);
    }

    private function normalizeInput(array $input): array
    {
        unset($input['single_entities_id']);

        $input['updated_at'] = date('Y-m-d H:i:s');

        if (!isset($input['id'])) {
            $input['created_at'] = date('Y-m-d H:i:s');
        }

        if (isset($input['base_url'])) {
            $input['base_url'] = rtrim($input['base_url'], '/');
        }

        if (array_key_exists('client_secret', $input) && $input['client_secret'] === '') {
            unset($input['client_secret']);
        }

        if (array_key_exists('redirect_uri', $input) && $input['redirect_uri'] === '') {
            $input['redirect_uri'] = $this->getDefaultRedirectUri();
        }

        if (isset($input['organization_mode']) && !in_array($input['organization_mode'], ['single', 'multi'], true)) {
            $input['organization_mode'] = 'multi';
        }

        if (($input['organization_mode'] ?? 'multi') === 'multi') {
            $input['single_ninjaone_organization_id'] = null;
        }

        if (array_key_exists('single_ninjaone_organization_id', $input)
            && (string) $input['single_ninjaone_organization_id'] === '') {
            $input['single_ninjaone_organization_id'] = null;
        }

        if (array_key_exists('sync_time', $input)) {
            $input['sync_time'] = $this->normalizeSyncTime((string) $input['sync_time']);
        }

        if (array_key_exists('sync_repeat_hours', $input)) {
            $repeat = trim((string) $input['sync_repeat_hours']);
            $input['sync_repeat_hours'] = $repeat === '' ? null : max(1, (int) $repeat);
        }

        return $input;
    }

    private function normalizeSyncTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) === 1) {
            return $time . ':00';
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $time) === 1) {
            return $time;
        }

        return '02:00:00';
    }

    public static function getDefaultRedirectUri(): string
    {
        global $CFG_GLPI;

        return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/ninjaone/front/oauth.callback.php';
    }
}
