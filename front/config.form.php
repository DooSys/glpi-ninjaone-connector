<?php

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\Client\NinjaOneClient;
use GlpiPlugin\Ninjaone\Sync\SyncRunner;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

$config = new Config();

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
            Session::addMessageAfterRedirect(sprintf(
                __('NinjaOne synchronization finished: %d created, %d updated, %d skipped, %d errors.', 'ninjaone'),
                $result->created,
                $result->updated,
                $result->skipped,
                $result->errors
            ), false, $result->errors > 0 ? WARNING : INFO);
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
    ];
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
echo '<label class="form-label">' . __('Redirect URI', 'ninjaone') . '</label>';
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
}

echo '</div>';
echo '</div>';

echo '<div class="card-footer d-flex gap-2">';
if ((int) ($config->fields['id'] ?? 0) > 0) {
    echo '<button class="btn btn-primary" type="submit" name="update" value="1">' . _x('button', 'Save') . '</button>';
    echo '<button class="btn btn-secondary" type="submit" name="test_connection" value="1">' . __('Test NinjaOne connection', 'ninjaone') . '</button>';
    echo '<button class="btn btn-secondary" type="submit" name="run_sync" value="1">' . __('Run NinjaOne synchronization', 'ninjaone') . '</button>';
    echo '<a class="btn btn-outline-primary" href="organization.mapping.php?config_id=' . (int) $config->fields['id'] . '">' . __('Map organizations', 'ninjaone') . '</a>';
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
