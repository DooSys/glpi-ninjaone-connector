<?php

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\Sync\SyncRunner;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', READ);

if (isset($_POST['run_sync_now'])) {
    Session::checkRight('config', UPDATE);

    global $DB;

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    $connections = 0;
    $runner = new SyncRunner();

    if ($DB->tableExists('glpi_plugin_ninjaone_configs')) {
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_ninjaone_configs',
            'WHERE' => ['is_active' => 1],
        ]);
        foreach ($iterator as $config) {
            $result = $runner->runFullSync($config, 'manual');
            $created += $result->created;
            $updated += $result->updated;
            $skipped += $result->skipped;
            $errors += $result->errors;
            $connections++;
        }
    }

    if ($connections === 0) {
        Session::addMessageAfterRedirect(__('No active NinjaOne connection found.', 'ninjaone'), false, WARNING);
    } else {
        Session::addMessageAfterRedirect(sprintf(
            __('NinjaOne synchronization finished: %d created, %d updated, %d skipped, %d errors.', 'ninjaone'),
            $created,
            $updated,
            $skipped,
            $errors
        ), false, $errors > 0 ? WARNING : INFO);
    }

    Html::back();
}

function plugin_ninjaone_dashboard_metrics(): array
{
    global $DB;

    $metrics = [
        'connections' => 0,
        'active_connections' => 0,
        'active_organizations' => 0,
        'mapped_locations' => 0,
        'linked_computers' => 0,
        'pending_links' => 0,
    ];

    if ($DB->tableExists('glpi_plugin_ninjaone_configs')) {
        foreach ($DB->request(['FROM' => 'glpi_plugin_ninjaone_configs']) as $row) {
            $metrics['connections']++;
            if ((int) ($row['is_active'] ?? 0) === 1) {
                $metrics['active_connections']++;
            }
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_organizationmappings')) {
        foreach ($DB->request(['FROM' => 'glpi_plugin_ninjaone_organizationmappings']) as $row) {
            if ((int) ($row['sync_enabled'] ?? 0) === 1) {
                $metrics['active_organizations']++;
            }
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_locationmappings')) {
        foreach ($DB->request(['FROM' => 'glpi_plugin_ninjaone_locationmappings']) as $row) {
            if ((int) ($row['sync_enabled'] ?? 0) === 1 && (int) ($row['locations_id'] ?? 0) > 0) {
                $metrics['mapped_locations']++;
            }
        }
    }

    if ($DB->tableExists('glpi_plugin_ninjaone_devicemappings')) {
        foreach ($DB->request(['FROM' => 'glpi_plugin_ninjaone_devicemappings']) as $row) {
            if ((int) ($row['computers_id'] ?? 0) > 0) {
                $metrics['linked_computers']++;
            } else {
                $metrics['pending_links']++;
            }
        }
    }

    return $metrics;
}

function plugin_ninjaone_dashboard_tile(string $label, int $value, string $icon, string $tone): void
{
    echo '<div class="col-sm-6 col-lg-4 col-xl-2">';
    echo '<div class="card h-100">';
    echo '<div class="card-body d-flex align-items-center gap-3">';
    echo '<span class="avatar rounded bg-' . $tone . '-lt text-' . $tone . '">';
    echo '<i class="ti ' . $icon . '"></i>';
    echo '</span>';
    echo '<div>';
    echo '<div class="text-muted small">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="fs-2 fw-bold">' . $value . '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

Html::header(
    __('NinjaOne connector', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

$metrics = plugin_ninjaone_dashboard_metrics();

echo '<div class="container-fluid">';
echo '<div class="row g-3 mb-3">';
plugin_ninjaone_dashboard_tile(
    __('Declared connectors', 'ninjaone'),
    $metrics['connections'],
    'ti-plug-connected',
    'primary'
);
plugin_ninjaone_dashboard_tile(
    __('Active connectors', 'ninjaone'),
    $metrics['active_connections'],
    'ti-circle-check',
    'success'
);
plugin_ninjaone_dashboard_tile(
    __('Active organizations', 'ninjaone'),
    $metrics['active_organizations'],
    'ti-building',
    'info'
);
plugin_ninjaone_dashboard_tile(
    __('Mapped locations', 'ninjaone'),
    $metrics['mapped_locations'],
    'ti-map-pin',
    'purple'
);
plugin_ninjaone_dashboard_tile(
    __('Linked computers', 'ninjaone'),
    $metrics['linked_computers'],
    'ti-device-desktop-check',
    'warning'
);
plugin_ninjaone_dashboard_tile(
    __('Pending links', 'ninjaone'),
    $metrics['pending_links'],
    'ti-link-off',
    'danger'
);
echo '</div>';

echo '<div class="mb-3 d-flex flex-wrap gap-2">';
echo '<a class="btn btn-primary" href="' . Config::getFormURL(false) . '">';
echo __('Add a NinjaOne connection', 'ninjaone');
echo '</a>';

if ($metrics['connections'] > 0 && Session::haveRight('config', UPDATE)) {
    echo '<form class="m-0" method="post" action="' . Config::getSearchURL(false) . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<button class="btn btn-info text-white" type="submit" name="run_sync_now" value="1">';
    echo __('Run synchronization now', 'ninjaone');
    echo '</button>';
    echo '</form>';
}
echo '</div>';

Search::show(Config::class);

echo '</div>';

Html::footer();
