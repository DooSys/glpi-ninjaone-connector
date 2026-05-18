<?php

namespace GlpiPlugin\Ninjaone;

use RuntimeException;

final class NinjaOneScriptGenerator
{
    public const DEFAULT_GLPI_BASE_URL = 'https://support.tinisys.fr';
    public const DEFAULT_AGENT_ZIP_SOURCE = 'https://github.com/glpi-project/glpi-agent/releases/download/1.17/GLPI-Agent-1.17-x64.zip';

    public static function defaults(): array
    {
        return [
            'glpi_base_url'              => self::DEFAULT_GLPI_BASE_URL,
            'glpi_inventory_url'         => '',
            'inventory_tag'              => 'NinjaOne',
            'agent_zip_source'           => self::DEFAULT_AGENT_ZIP_SOURCE,
            'keep_successful_inventories' => '1',
            'disable_ssl_check'          => '0',
            'proxy_url'                  => '',
            'ca_cert_file'               => '',
            'ssl_fingerprint'            => '',
        ];
    }

    public static function normalizeInput(array $input): array
    {
        $values = self::defaults();
        foreach (array_keys($values) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
            }
        }

        $values['keep_successful_inventories'] = isset($input['keep_successful_inventories']) ? '1' : '0';
        $values['disable_ssl_check'] = isset($input['disable_ssl_check']) ? '1' : '0';

        if ($values['glpi_base_url'] !== '') {
            $values['glpi_base_url'] = rtrim($values['glpi_base_url'], '/');
        }

        return $values;
    }

    public static function render(array $values): string
    {
        $template = self::loadTemplate();

        $replacements = [
            '$GlpiBaseUrl = \'https://support.tinisys.fr\'' => '$GlpiBaseUrl = ' . self::psString($values['glpi_base_url']),
            '$GlpiInventoryUrl = \'\'' => '$GlpiInventoryUrl = ' . self::psString($values['glpi_inventory_url']),
            '$InventoryTag = \'NinjaOne\'' => '$InventoryTag = ' . self::psString($values['inventory_tag']),
            '$AgentZipSource = \'https://github.com/glpi-project/glpi-agent/releases/download/1.17/GLPI-Agent-1.17-x64.zip\'' => '$AgentZipSource = ' . self::psString($values['agent_zip_source']),
            '$KeepSuccessfulInventories = $true' => '$KeepSuccessfulInventories = ' . self::psBool($values['keep_successful_inventories'] === '1'),
            '$DisableSslCheck = $false' => '$DisableSslCheck = ' . self::psBool($values['disable_ssl_check'] === '1'),
            '$ProxyUrl = \'\'' => '$ProxyUrl = ' . self::psString($values['proxy_url']),
            '$CaCertFile = \'\'' => '$CaCertFile = ' . self::psString($values['ca_cert_file']),
            '$SslFingerprint = \'\'' => '$SslFingerprint = ' . self::psString($values['ssl_fingerprint']),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private static function loadTemplate(): string
    {
        $path = dirname(__DIR__) . '/resources/glpi-connector/templates/Invoke-GlpiPortableInventory.ps1';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Unable to read NinjaOne PowerShell template: ' . $path);
        }

        return $content;
    }

    private static function psString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function psBool(bool $value): string
    {
        return $value ? '$true' : '$false';
    }
}

