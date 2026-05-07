<?php

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\Sync\SyncRunner;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

global $DB;

$config_id = (int) ($_GET['config_id'] ?? $_POST['config_id'] ?? 0);
$config = new Config();
if ($config_id <= 0 || !$config->getFromDB($config_id)) {
    Session::addMessageAfterRedirect(__('NinjaOne configuration not found.', 'ninjaone'), false, ERROR);
    Html::redirect(Config::getSearchURL(false));
}

$entities = [];
$entity_rows = $DB->request([
    'SELECT' => ['id', 'completename'],
    'FROM'   => 'glpi_entities',
    'ORDER'  => 'completename',
]);
foreach ($entity_rows as $entity_row) {
    $entities[(int) $entity_row['id']] = (string) $entity_row['completename'];
}

if (isset($_POST['save_mapping'])) {
    $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
    $submitted_entity = (string) ($_POST['entities_id'] ?? '');
    $entities_id = $submitted_entity === '' ? null : (int) $submitted_entity;
    $sync_enabled = isset($_POST['sync_enabled']) ? 1 : 0;

    if ($sync_enabled === 1 && $entities_id === null) {
        $sync_enabled = 0;
        Session::addMessageAfterRedirect(
            __('Mapping was saved but synchronization was not enabled because no GLPI entity was selected.', 'ninjaone'),
            false,
            WARNING
        );
    } else {
        Session::addMessageAfterRedirect(__('NinjaOne organization mapping saved.', 'ninjaone'));
    }

    $DB->update(
        'glpi_plugin_ninjaone_organizationmappings',
        [
            'entities_id'  => $entities_id,
            'sync_enabled' => $sync_enabled,
        ],
        [
            'id'                          => $mapping_id,
            'plugin_ninjaone_configs_id' => $config_id,
        ]
    );

    Html::redirect('organization.mapping.php?config_id=' . $config_id);
}

if (isset($_POST['run_asset_sync'])) {
    try {
        $runner = new SyncRunner();
        $result = $runner->runFullSync($config->fields);
        $message = sprintf(
            __('NinjaOne synchronization finished: %d created, %d updated, %d skipped, %d errors.', 'ninjaone'),
            $result->created,
            $result->updated,
            $result->skipped,
            $result->errors
        );
        if ($result->messages !== []) {
            $message .= ' ' . implode(' | ', $result->messages);
        }
        Session::addMessageAfterRedirect($message, false, $result->errors > 0 ? WARNING : INFO);
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect(
            __('NinjaOne synchronization failed: ', 'ninjaone') . $e->getMessage(),
            false,
            ERROR
        );
    }

    Html::redirect('organization.mapping.php?config_id=' . $config_id);
}

Html::header(
    __('NinjaOne organization mappings', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="container-fluid">';
echo '<div class="card">';

echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<h3 class="card-title mb-0">' . __('NinjaOne organization mappings', 'ninjaone') . '</h3>';
echo '<div class="d-flex gap-2">';
echo '<form method="post" action="organization.mapping.php?config_id=' . $config_id . '" class="m-0">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<input type="hidden" name="config_id" value="' . $config_id . '">';
echo '<button class="btn btn-secondary" type="submit" name="run_asset_sync" value="1">' . __('Run asset synchronization', 'ninjaone') . '</button>';
echo '</form>';
echo '<a class="btn btn-outline-primary" href="location.mapping.php?config_id=' . $config_id . '">' . __('Map locations', 'ninjaone') . '</a>';
echo '<a class="btn btn-outline-secondary" href="' . Config::getFormURLWithID($config_id) . '">' . __('Back') . '</a>';
echo '</div>';
echo '</div>';

echo '<div class="list-group list-group-flush">';

$rows = $DB->request([
    'FROM'  => 'glpi_plugin_ninjaone_organizationmappings',
    'WHERE' => ['plugin_ninjaone_configs_id' => $config_id],
    'ORDER' => 'ninjaone_organization_name',
]);

$count = 0;
foreach ($rows as $row) {
    $count++;
    $id = (int) $row['id'];
    $row_sync_enabled = (int) $row['sync_enabled'] === 1;

    echo '<div class="list-group-item">';
    echo '<form method="post" action="organization.mapping.php?config_id=' . $config_id . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<input type="hidden" name="config_id" value="' . $config_id . '">';
    echo '<input type="hidden" name="mapping_id" value="' . $id . '">';
    echo '<div class="row g-3 align-items-center">';

    echo '<div class="col-md-1">';
    echo '<input class="form-check-input" type="checkbox" name="sync_enabled" value="1"'
        . ($row_sync_enabled ? ' checked' : '') . '>';
    echo '</div>';

    echo '<div class="col-md-3">';
    echo '<div class="fw-semibold">' . htmlspecialchars((string) $row['ninjaone_organization_name'], ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="text-muted small">' . __('NinjaOne ID', 'ninjaone') . ': <code>' . (int) $row['ninjaone_organization_id'] . '</code></div>';
    echo '</div>';

    echo '<div class="col-md-3">';
    echo '<select class="form-select" name="entities_id">';
    echo '<option value=""' . ($row['entities_id'] === null ? ' selected' : '') . '>'
        . __('Select entity', 'ninjaone')
        . '</option>';
    foreach ($entities as $entity_id => $entity_name) {
        echo '<option value="' . $entity_id . '"'
            . ($row['entities_id'] !== null && (int) $row['entities_id'] === $entity_id ? ' selected' : '')
            . '>' . htmlspecialchars($entity_name, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="col-md-2">';
    if ($row_sync_enabled) {
        echo '<span class="badge bg-success">' . __('Ready', 'ninjaone') . '</span>';
    } elseif ($row['entities_id'] !== null) {
        echo '<span class="badge text-bg-light border">' . __('Mapped but disabled', 'ninjaone') . '</span>';
    } else {
        echo '<span class="badge bg-warning text-dark">' . __('Not mapped', 'ninjaone') . '</span>';
    }
    echo '<div class="text-muted small mt-1">' . htmlspecialchars((string) ($row['last_sync_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';

    echo '<div class="col-md-3 text-end">';
    echo '<button class="btn btn-sm btn-primary" type="submit" name="save_mapping" value="1">' . __('Apply') . '</button>';
    echo '</div>';

    echo '</div>';
    echo '</form>';
    echo '</div>';
}

if ($count === 0) {
    echo '<div class="list-group-item text-center text-muted">';
    echo __('No NinjaOne organization discovered yet. Run synchronization first.', 'ninjaone');
    echo '</div>';
}

echo '</div>';

echo '<div class="card-footer d-flex gap-2">';
echo '<form method="post" action="organization.mapping.php?config_id=' . $config_id . '" class="m-0">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<input type="hidden" name="config_id" value="' . $config_id . '">';
echo '<button class="btn btn-secondary" type="submit" name="run_asset_sync" value="1">' . __('Run asset synchronization', 'ninjaone') . '</button>';
echo '</form>';
echo '<a class="btn btn-outline-secondary" href="' . Config::getFormURLWithID($config_id) . '">' . __('Cancel') . '</a>';
echo '</div>';

echo '</div>';
echo '</div>';

Html::footer();
