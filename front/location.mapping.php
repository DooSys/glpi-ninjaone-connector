<?php

use GlpiPlugin\Ninjaone\Config;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

global $DB;

$config_id = (int) ($_GET['config_id'] ?? $_POST['config_id'] ?? 0);
$config = new Config();
if ($config_id <= 0 || !$config->getFromDB($config_id)) {
    Session::addMessageAfterRedirect(__('NinjaOne configuration not found.', 'ninjaone'), false, ERROR);
    Html::redirect(Config::getSearchURL(false));
}

function plugin_ninjaone_get_location_entity(int $config_id, int $organization_id): ?int
{
    global $DB;

    $rows = $DB->request([
        'SELECT' => ['entities_id'],
        'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
        'WHERE'  => [
            'plugin_ninjaone_configs_id' => $config_id,
            'ninjaone_organization_id'   => $organization_id,
        ],
        'LIMIT' => 1,
    ]);

    if (count($rows) === 0) {
        return null;
    }

    $row = $rows->current();
    return $row['entities_id'] === null ? null : (int) $row['entities_id'];
}

function plugin_ninjaone_create_glpi_location(string $name, ?int $entities_id): int
{
    $name = trim($name);
    if ($name === '' || $entities_id === null) {
        return 0;
    }

    $location = new Location();
    $id = (int) $location->add([
        'name'        => $name,
        'entities_id' => $entities_id,
    ]);

    return $id > 0 ? $id : 0;
}

if (isset($_POST['save_mapping']) || isset($_POST['create_location'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $submitted_location = (string) ($_POST['locations_id'] ?? '');
    $locations_id = $submitted_location === '' ? null : (int) $submitted_location;
    $sync_enabled = isset($_POST['sync_enabled']) ? 1 : 0;

    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_ninjaone_locationmappings',
        'WHERE' => [
            'id'                          => $mapping_id,
            'plugin_ninjaone_configs_id' => $config_id,
        ],
        'LIMIT' => 1,
    ]);

    if (count($rows) === 0) {
        Session::addMessageAfterRedirect(__('NinjaOne location mapping not found.', 'ninjaone'), false, ERROR);
        Html::redirect('location.mapping.php?config_id=' . $config_id);
    }

    $row = $rows->current();
    $entity_id = plugin_ninjaone_get_location_entity($config_id, (int) $row['ninjaone_organization_id']);

    if (isset($_POST['create_location'])) {
        $created_id = plugin_ninjaone_create_glpi_location((string) $row['ninjaone_location_name'], $entity_id);
        if ($created_id > 0) {
            $locations_id = $created_id;
            $sync_enabled = 1;
            Session::addMessageAfterRedirect(__('GLPI location created and mapped.', 'ninjaone'));
        } else {
            $sync_enabled = 0;
            Session::addMessageAfterRedirect(
                __('Unable to create GLPI location. Check that the parent NinjaOne organization is mapped to a GLPI entity.', 'ninjaone'),
                false,
                ERROR
            );
        }
    } elseif ($sync_enabled === 1 && $locations_id === null) {
        $sync_enabled = 0;
        Session::addMessageAfterRedirect(
            __('Mapping was saved but synchronization was not enabled because no GLPI location was selected.', 'ninjaone'),
            false,
            WARNING
        );
    } else {
        Session::addMessageAfterRedirect(__('NinjaOne location mapping saved.', 'ninjaone'));
    }

    $DB->update(
        'glpi_plugin_ninjaone_locationmappings',
        [
            'locations_id' => $locations_id,
            'entities_id'  => $entity_id,
            'sync_enabled' => $sync_enabled,
        ],
        [
            'id'                          => $mapping_id,
            'plugin_ninjaone_configs_id' => $config_id,
        ]
    );

    Html::redirect('location.mapping.php?config_id=' . $config_id);
}

$locations = [];
$location_rows = $DB->request([
    'SELECT' => ['id', 'completename'],
    'FROM'   => 'glpi_locations',
    'ORDER'  => 'completename',
]);
foreach ($location_rows as $location_row) {
    $locations[(int) $location_row['id']] = (string) $location_row['completename'];
}

$organizations = [];
$organization_rows = $DB->request([
    'SELECT' => ['ninjaone_organization_id', 'ninjaone_organization_name', 'entities_id', 'sync_enabled'],
    'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
    'WHERE'  => ['plugin_ninjaone_configs_id' => $config_id],
]);
foreach ($organization_rows as $organization_row) {
    $organizations[(int) $organization_row['ninjaone_organization_id']] = $organization_row;
}

Html::header(
    __('NinjaOne location mappings', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="container-fluid">';
echo '<div class="card">';

echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<h3 class="card-title mb-0">' . __('NinjaOne location mappings', 'ninjaone') . '</h3>';
echo '<div class="d-flex gap-2">';
if (($config->fields['organization_mode'] ?? 'multi') === 'multi') {
    echo '<a class="btn btn-outline-primary" href="organization.mapping.php?config_id=' . $config_id . '">' . __('Map organizations', 'ninjaone') . '</a>';
}
echo '<a class="btn btn-outline-secondary" href="' . Config::getFormURLWithID($config_id) . '">' . __('Back') . '</a>';
echo '</div>';
echo '</div>';

echo '<div class="list-group list-group-flush">';

$where = ['plugin_ninjaone_configs_id' => $config_id];
if (($config->fields['organization_mode'] ?? 'multi') === 'single'
    && (int) ($config->fields['single_ninjaone_organization_id'] ?? 0) > 0) {
    $where['ninjaone_organization_id'] = (int) $config->fields['single_ninjaone_organization_id'];
}

$rows = $DB->request([
    'FROM'  => 'glpi_plugin_ninjaone_locationmappings',
    'WHERE' => $where,
    'ORDER' => ['ninjaone_organization_id', 'ninjaone_location_name'],
]);

$count = 0;
foreach ($rows as $row) {
    $count++;
    $id = (int) $row['id'];
    $row_sync_enabled = (int) $row['sync_enabled'] === 1;
    $organization = $organizations[(int) $row['ninjaone_organization_id']] ?? null;
    $organization_name = is_array($organization)
        ? (string) $organization['ninjaone_organization_name']
        : (string) $row['ninjaone_organization_id'];
    $organization_ready = is_array($organization)
        && (int) $organization['sync_enabled'] === 1
        && $organization['entities_id'] !== null;

    echo '<div class="list-group-item">';
    echo '<form method="post" action="location.mapping.php?config_id=' . $config_id . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<input type="hidden" name="config_id" value="' . $config_id . '">';
    echo '<input type="hidden" name="mapping_id" value="' . $id . '">';
    echo '<div class="row g-3 align-items-center">';

    echo '<div class="col-md-1">';
    echo '<input class="form-check-input" type="checkbox" name="sync_enabled" value="1"'
        . ($row_sync_enabled ? ' checked' : '') . '>';
    echo '</div>';

    echo '<div class="col-md-3">';
    echo '<div class="fw-semibold">' . htmlspecialchars((string) $row['ninjaone_location_name'], ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="text-muted small">' . __('NinjaOne ID', 'ninjaone') . ': <code>' . (int) $row['ninjaone_location_id'] . '</code></div>';
    echo '</div>';

    echo '<div class="col-md-3">';
    echo '<div>' . htmlspecialchars($organization_name, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="text-muted small">' . __('Organization', 'ninjaone') . '</div>';
    echo '</div>';

    echo '<div class="col-md-3">';
    echo '<select class="form-select" name="locations_id">';
    echo '<option value=""' . ($row['locations_id'] === null ? ' selected' : '') . '>' . __('Select location', 'ninjaone') . '</option>';
    foreach ($locations as $location_id => $location_name) {
        echo '<option value="' . $location_id . '"'
            . ($row['locations_id'] !== null && (int) $row['locations_id'] === $location_id ? ' selected' : '')
            . '>' . htmlspecialchars($location_name, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="col-md-1">';
    if ($row_sync_enabled) {
        echo '<span class="badge bg-success">' . __('Ready', 'ninjaone') . '</span>';
    } elseif ($row['locations_id'] !== null) {
        echo '<span class="badge text-bg-light border">' . __('Mapped but disabled', 'ninjaone') . '</span>';
    } elseif (!$organization_ready) {
        echo '<span class="badge bg-warning text-dark">' . __('Organization required', 'ninjaone') . '</span>';
    } else {
        echo '<span class="badge bg-warning text-dark">' . __('Not mapped', 'ninjaone') . '</span>';
    }
    echo '</div>';

    echo '<div class="col-md-1 text-end">';
    echo '<button class="btn btn-sm btn-primary mb-1" type="submit" name="save_mapping" value="1">' . __('Apply') . '</button>';
    echo '<button class="btn btn-sm btn-outline-secondary" type="submit" name="create_location" value="1">' . __('Create') . '</button>';
    echo '</div>';

    echo '</div>';
    echo '</form>';
    echo '</div>';
}

if ($count === 0) {
    echo '<div class="list-group-item text-center text-muted">';
    echo __('No NinjaOne location discovered yet. Run synchronization first.', 'ninjaone');
    echo '</div>';
}

echo '</div>';

echo '<div class="card-footer d-flex gap-2">';
if (($config->fields['organization_mode'] ?? 'multi') === 'multi') {
    echo '<a class="btn btn-outline-primary" href="organization.mapping.php?config_id=' . $config_id . '">' . __('Map organizations', 'ninjaone') . '</a>';
}
echo '<a class="btn btn-outline-secondary" href="' . Config::getFormURLWithID($config_id) . '">' . __('Cancel') . '</a>';
echo '</div>';

echo '</div>';
echo '</div>';

Html::footer();
