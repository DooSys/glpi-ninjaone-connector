<?php

use GlpiPlugin\Ninjaone\Config;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', READ);

global $DB;

$config_id = (int) ($_GET['config_id'] ?? 0);
$mapping_id = (int) ($_GET['id'] ?? 0);

function plugin_ninjaone_flatten_payload_keys(array $payload, string $prefix = ''): array
{
    $keys = [];
    foreach ($payload as $key => $value) {
        $name = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
        $keys[] = $name;
        if (is_array($value)) {
            $keys = array_merge($keys, plugin_ninjaone_flatten_payload_keys($value, $name));
        }
    }

    return array_values(array_unique($keys));
}

Html::header(
    __('NinjaOne device payloads', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="container-fluid">';
echo '<div class="card">';
echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<h3 class="card-title mb-0">' . __('NinjaOne device payloads', 'ninjaone') . '</h3>';
if ($config_id > 0) {
    echo '<a class="btn btn-outline-secondary" href="' . Config::getFormURLWithID($config_id) . '">' . __('Back') . '</a>';
}
echo '</div>';
echo '<div class="card-body">';

if ($mapping_id > 0) {
    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_ninjaone_devicemappings',
        'WHERE' => [
            'id' => $mapping_id,
        ],
        'LIMIT' => 1,
    ]);

    if (count($rows) === 0) {
        echo '<div class="alert alert-warning">' . __('Payload not found', 'ninjaone') . '</div>';
    } else {
        $row = $rows->current();
        $payload = json_decode((string) ($row['last_payload_json'] ?? ''), true);
        echo '<h4>' . __('Device mapping', 'ninjaone') . ' #' . (int) $row['id'] . '</h4>';
        echo '<p class="text-muted mb-3">';
        echo 'NinjaOne device ID: ' . (int) $row['ninjaone_device_id'];
        echo ' | GLPI computer ID: ' . (int) $row['computers_id'];
        echo ' | Status: ' . htmlspecialchars((string) $row['sync_status'], ENT_QUOTES, 'UTF-8');
        echo '</p>';

        if (!is_array($payload)) {
            echo '<div class="alert alert-warning">' . __('No JSON payload stored for this device yet.', 'ninjaone') . '</div>';
        } else {
            $keys = plugin_ninjaone_flatten_payload_keys($payload);
            sort($keys);

            echo '<div class="row">';
            echo '<div class="col-lg-4">';
            echo '<h5>' . __('Available keys', 'ninjaone') . '</h5>';
            echo '<pre class="border rounded p-2 bg-light" style="max-height: 600px; overflow: auto;">';
            echo htmlspecialchars(implode("\n", $keys), ENT_QUOTES, 'UTF-8');
            echo '</pre>';
            echo '</div>';
            echo '<div class="col-lg-8">';
            echo '<h5>' . __('Raw payload', 'ninjaone') . '</h5>';
            echo '<pre class="border rounded p-2 bg-light" style="max-height: 600px; overflow: auto;">';
            echo htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            echo '</pre>';
            echo '</div>';
            echo '</div>';
        }
    }
} else {
    $where = [];
    if ($config_id > 0) {
        $where['plugin_ninjaone_configs_id'] = $config_id;
    }

    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_ninjaone_devicemappings',
        'WHERE' => $where,
        'ORDER' => ['last_sync_at DESC', 'id DESC'],
        'LIMIT' => 50,
    ]);

    echo '<div class="table-responsive">';
    echo '<table class="table table-hover">';
    echo '<thead><tr>';
    echo '<th>' . __('NinjaOne device ID', 'ninjaone') . '</th>';
    echo '<th>' . __('Organization', 'ninjaone') . '</th>';
    echo '<th>' . __('Location', 'ninjaone') . '</th>';
    echo '<th>' . __('GLPI computer', 'ninjaone') . '</th>';
    echo '<th>' . __('Status') . '</th>';
    echo '<th>' . __('Last synchronization', 'ninjaone') . '</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';

    $count = 0;
    foreach ($rows as $row) {
        if ((string) ($row['last_payload_json'] ?? '') === '') {
            continue;
        }
        $count++;
        echo '<tr>';
        echo '<td>' . (int) $row['ninjaone_device_id'] . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['ninjaone_organization_id'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['ninjaone_location_id'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . (int) $row['computers_id'] . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['sync_status'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['last_sync_at'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td><a class="btn btn-sm btn-outline-primary" href="device.payload.php?config_id=' . $config_id . '&id=' . (int) $row['id'] . '">' . __('Inspect', 'ninjaone') . '</a></td>';
        echo '</tr>';
    }

    if ($count === 0) {
        echo '<tr><td colspan="7" class="text-center text-muted">' . __('No stored device payload found. Run a synchronization first.', 'ninjaone') . '</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

echo '</div>';
echo '</div>';
echo '</div>';

Html::footer();
