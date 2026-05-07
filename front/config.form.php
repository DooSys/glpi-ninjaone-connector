<?php

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\Client\NinjaOneClient;
use GlpiPlugin\Ninjaone\Sync\SyncRunner;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

$config = new Config();

function plugin_ninjaone_get_entities_for_select(): array
{
    global $DB;

    $entities = [];
    $rows = $DB->request([
        'SELECT' => ['id', 'completename'],
        'FROM'   => 'glpi_entities',
        'ORDER'  => 'completename',
    ]);
    foreach ($rows as $row) {
        $entities[(int) $row['id']] = (string) $row['completename'];
    }

    return $entities;
}

function plugin_ninjaone_get_organizations_for_select(int $config_id): array
{
    global $DB;

    $organizations = [];
    $rows = $DB->request([
        'SELECT' => ['ninjaone_organization_id', 'ninjaone_organization_name', 'entities_id'],
        'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
        'WHERE'  => ['plugin_ninjaone_configs_id' => $config_id],
        'ORDER'  => 'ninjaone_organization_name',
    ]);
    foreach ($rows as $row) {
        $organizations[(int) $row['ninjaone_organization_id']] = $row;
    }

    return $organizations;
}

function plugin_ninjaone_apply_single_organization_mode(int $config_id, array $input): void
{
    global $DB;

    $mode = (string) ($input['organization_mode'] ?? 'multi');
    if ($mode !== 'single') {
        return;
    }

    $organization_id = (int) ($input['single_ninjaone_organization_id'] ?? 0);
    $submitted_entity = (string) ($input['single_entities_id'] ?? '');
    $entities_id = $submitted_entity === '' ? null : (int) $submitted_entity;

    $DB->update(
        'glpi_plugin_ninjaone_organizationmappings',
        ['sync_enabled' => 0],
        ['plugin_ninjaone_configs_id' => $config_id]
    );

    if ($organization_id <= 0 || $entities_id === null) {
        return;
    }

    $DB->update(
        'glpi_plugin_ninjaone_organizationmappings',
        [
            'entities_id'  => $entities_id,
            'sync_enabled' => 1,
        ],
        [
            'plugin_ninjaone_configs_id' => $config_id,
            'ninjaone_organization_id'   => $organization_id,
        ]
    );
}

if (isset($_POST['test_connection'])) {
    if ($config->getFromDB((int) $_POST['id'])) {
        try {
            $client = new NinjaOneClient(
                $config->fields['base_url'],
                $config->fields['client_id'],
                $config->fields['client_secret'] ?? '',
                $config->fields['scopes'] ?? 'monitoring',
                $config->fields['access_token'] ?? null,
                $config->fields['refresh_token'] ?? null,
                $config->fields['redirect_uri'] ?? null
            );
            $result = $client->testConnection();
            Session::addMessageAfterRedirect(sprintf(
                __('NinjaOne connection successful. Organization sample count: %d', 'ninjaone'),
                $result['organization_sample']
            ));
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect(
                __('NinjaOne connection failed: ', 'ninjaone') . $e->getMessage(),
                false,
                ERROR
            );
        }
    }
    Html::back();
}

if (isset($_POST['run_sync'])) {
    if ($config->getFromDB((int) $_POST['id'])) {
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
    }
    Html::back();
}

if (isset($_POST['add'])) {
    $new_id = $config->add($_POST);
    Html::redirect(Config::getFormURLWithID($new_id));
}

if (isset($_POST['update'])) {
    $config->update($_POST);
    plugin_ninjaone_apply_single_organization_mode((int) $_POST['id'], $_POST);
    Html::back();
}

if (isset($_POST['purge'])) {
    $config->delete($_POST, true);
    $config->redirectToList();
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id > 0) {
    $config->getFromDB($id);
} else {
    $config->fields = [
        'id'            => 0,
        'name'          => 'GLPI NinjaOne Connector',
        'base_url'      => 'https://eu.ninjarmm.com',
        'client_id'     => '',
        'client_secret' => '',
        'scopes'        => 'monitoring',
        'redirect_uri'  => Config::getDefaultRedirectUri(),
        'refresh_token' => '',
        'is_active'     => 1,
        'organization_mode' => 'multi',
        'single_ninjaone_organization_id' => null,
        'sync_time' => '02:00:00',
        'sync_repeat_hours' => null,
    ];
}

$organizations = (int) ($config->fields['id'] ?? 0) > 0
    ? plugin_ninjaone_get_organizations_for_select((int) $config->fields['id'])
    : [];
$entities = plugin_ninjaone_get_entities_for_select();
$organization_mode = (string) ($config->fields['organization_mode'] ?? 'multi');
if (!in_array($organization_mode, ['single', 'multi'], true)) {
    $organization_mode = 'multi';
}
$single_organization_id = (int) ($config->fields['single_ninjaone_organization_id'] ?? 0);
$single_entity_id = null;
if ($single_organization_id > 0 && isset($organizations[$single_organization_id])) {
    $single_entity_id = $organizations[$single_organization_id]['entities_id'] === null
        ? null
        : (int) $organizations[$single_organization_id]['entities_id'];
}

Html::header(
    __('NinjaOne connector', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="container-fluid">';
echo '<form method="post" action="' . Config::getFormURL(false) . '">';
echo '<input type="hidden" name="id" value="' . (int) ($config->fields['id'] ?? 0) . '">';

echo '<div class="card">';
echo '<div class="card-header">';
echo '<h3 class="card-title">' . __('NinjaOne connection', 'ninjaone') . '</h3>';
echo '</div>';
echo '<div class="card-body">';

echo '<div class="row g-3">';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Name') . '</label>';
echo '<input class="form-control" type="text" name="name" value="' . htmlspecialchars((string) ($config->fields['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Active') . '</label>';
echo '<select class="form-select" name="is_active">';
$active = (int) ($config->fields['is_active'] ?? 1);
echo '<option value="1"' . ($active === 1 ? ' selected' : '') . '>' . __('Yes') . '</option>';
echo '<option value="0"' . ($active === 0 ? ' selected' : '') . '>' . __('No') . '</option>';
echo '</select>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Base URL', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="base_url" value="' . htmlspecialchars((string) ($config->fields['base_url'] ?? 'https://eu.ninjarmm.com'), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Scopes', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="scopes" value="' . htmlspecialchars((string) ($config->fields['scopes'] ?? 'monitoring'), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Client ID', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="client_id" value="' . htmlspecialchars((string) ($config->fields['client_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Client secret', 'ninjaone') . '</label>';
echo '<input class="form-control" type="password" name="client_secret" value="" autocomplete="new-password">';
if ((int) ($config->fields['id'] ?? 0) > 0) {
    $has_secret = !empty($config->fields['client_secret']);
    echo '<div class="form-text">';
    echo $has_secret
        ? __('A client secret is stored. Leave this field empty to keep it unchanged.', 'ninjaone')
        : __('No client secret is stored yet.', 'ninjaone');
    echo '</div>';
}
echo '</div>';

echo '<div class="col-md-12">';
echo '<label class="form-label">' . __('Redirect URL', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="redirect_uri" value="' . htmlspecialchars((string) ($config->fields['redirect_uri'] ?? Config::getDefaultRedirectUri()), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

if ((int) ($config->fields['id'] ?? 0) > 0) {
    $has_secret = !empty($config->fields['client_secret']);
    echo '<div class="col-md-12">';
    echo '<div class="alert ' . ($has_secret ? 'alert-success' : 'alert-warning') . ' mb-0">';
    echo $has_secret
        ? __('NinjaOne machine-to-machine credentials are configured.', 'ninjaone')
        : __('NinjaOne client secret is missing. Create a Services API client with Client Credentials.', 'ninjaone');
    echo '</div>';
    echo '</div>';

    echo '<div class="col-md-12"><hr class="my-1"></div>';

    echo '<div class="col-md-12">';
    echo '<div class="border rounded p-3">';
    echo '<h4 class="mb-3">' . __('Organization management', 'ninjaone') . '</h4>';

    echo '<div class="row g-3">';
    echo '<div class="col-md-4">';
    echo '<label class="form-label">' . __('Organization mode', 'ninjaone') . '</label>';
    echo '<select class="form-select" name="organization_mode">';
    echo '<option value="single"' . ($organization_mode === 'single' ? ' selected' : '') . '>' . __('Single organization', 'ninjaone') . '</option>';
    echo '<option value="multi"' . ($organization_mode === 'multi' ? ' selected' : '') . '>' . __('Multi organization', 'ninjaone') . '</option>';
    echo '</select>';
    echo '</div>';

    if ($organization_mode === 'single') {
        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . __('NinjaOne organization', 'ninjaone') . '</label>';
        echo '<select class="form-select" name="single_ninjaone_organization_id">';
        echo '<option value="">' . __('Select organization', 'ninjaone') . '</option>';
        foreach ($organizations as $organization_id => $organization) {
            echo '<option value="' . $organization_id . '"'
                . ($single_organization_id === $organization_id ? ' selected' : '')
                . '>' . htmlspecialchars((string) $organization['ninjaone_organization_name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . __('GLPI entity', 'ninjaone') . '</label>';
        echo '<select class="form-select" name="single_entities_id">';
        echo '<option value="">' . __('Select entity', 'ninjaone') . '</option>';
        foreach ($entities as $entity_id => $entity_name) {
            echo '<option value="' . $entity_id . '"'
                . ($single_entity_id !== null && $single_entity_id === $entity_id ? ' selected' : '')
                . '>' . htmlspecialchars($entity_name, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }
    echo '</div>';

    if ($organizations === []) {
        echo '<div class="alert alert-info mt-3 mb-0">' . __('Run a synchronization once to discover NinjaOne organizations.', 'ninjaone') . '</div>';
    } elseif ($organization_mode === 'multi') {
        echo '<div class="mt-3">';
        echo '<a class="btn btn-outline-primary" href="organization.mapping.php?config_id=' . (int) $config->fields['id'] . '">' . __('Map organizations', 'ninjaone') . '</a>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    echo '<div class="col-md-12">';
    echo '<div class="border rounded p-3">';
    echo '<h4 class="mb-3">' . __('Scheduling', 'ninjaone') . '</h4>';
    echo '<div class="row g-3 align-items-end">';
    echo '<div class="col-md-4">';
    echo '<label class="form-label">' . __('Run at', 'ninjaone') . '</label>';
    $sync_time = substr((string) ($config->fields['sync_time'] ?? '02:00:00'), 0, 5);
    echo '<input class="form-control" type="time" name="sync_time" value="' . htmlspecialchars($sync_time, ENT_QUOTES, 'UTF-8') . '">';
    echo '</div>';
    echo '<div class="col-md-4">';
    echo '<label class="form-label">' . __('Repeat every', 'ninjaone') . '</label>';
    echo '<div class="input-group">';
    echo '<input class="form-control" type="number" min="1" step="1" name="sync_repeat_hours" value="' . htmlspecialchars((string) ($config->fields['sync_repeat_hours'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
    echo '<span class="input-group-text">' . __('hours', 'ninjaone') . '</span>';
    echo '</div>';
    echo '<div class="form-text">' . __('Leave empty to run once per day at the configured time.', 'ninjaone') . '</div>';
    echo '</div>';
    echo '<div class="col-md-4">';
    echo '<div class="form-text">';
    echo __('The GLPI automatic action NinjaoneSync runs from GLPI cron and applies this schedule.', 'ninjaone');
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

echo '</div>';
echo '</div>';

echo '<div class="card-footer d-flex gap-2">';
if ((int) ($config->fields['id'] ?? 0) > 0) {
    echo '<button class="btn btn-primary" type="submit" name="update" value="1">' . _x('button', 'Save') . '</button>';
    echo '<button class="btn btn-secondary" type="submit" name="test_connection" value="1">' . __('Test NinjaOne connection', 'ninjaone') . '</button>';
    echo '<button class="btn btn-secondary" type="submit" name="run_sync" value="1">' . __('Run NinjaOne synchronization', 'ninjaone') . '</button>';
    if ($organization_mode === 'multi') {
        echo '<a class="btn btn-outline-primary" href="organization.mapping.php?config_id=' . (int) $config->fields['id'] . '">' . __('Map organizations', 'ninjaone') . '</a>';
    }
    echo '<a class="btn btn-outline-primary" href="location.mapping.php?config_id=' . (int) $config->fields['id'] . '">' . __('Map locations', 'ninjaone') . '</a>';
    echo '<a class="btn btn-outline-secondary" href="device.payload.php?config_id=' . (int) $config->fields['id'] . '">' . __('Inspect device payloads', 'ninjaone') . '</a>';
    echo '<a class="btn btn-outline-secondary" href="synclog.php?config_id=' . (int) $config->fields['id'] . '">' . __('Logs') . '</a>';
} else {
    echo '<button class="btn btn-primary" type="submit" name="add" value="1">' . _x('button', 'Add') . '</button>';
}
echo '<a class="btn btn-outline-secondary" href="' . Config::getSearchURL(false) . '">' . __('Cancel') . '</a>';
echo '</div>';

echo '</div>';
Html::closeForm();
echo '</div>';

Html::footer();
