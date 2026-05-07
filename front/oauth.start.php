<?php

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\Client\NinjaOneClient;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

$id = (int) ($_GET['id'] ?? 0);
$config = new Config();
if ($id <= 0 || !$config->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('NinjaOne configuration not found.', 'ninjaone'), false, ERROR);
    Html::redirect(Config::getSearchURL(false));
}

if (!empty($config->fields['refresh_token']) && !isset($_GET['force'])) {
    Session::addMessageAfterRedirect(__('NinjaOne is already authorized. Use reconnect only if you need to renew the token.', 'ninjaone'));
    Html::redirect(Config::getFormURLWithID($id));
}

$redirect_uri = $config->fields['redirect_uri'] ?: Config::getDefaultRedirectUri();
$state = bin2hex(random_bytes(16));
$config->update([
    'id'           => $id,
    'redirect_uri' => $redirect_uri,
    'oauth_state'  => $state,
]);

$client = new NinjaOneClient(
    $config->fields['base_url'],
    $config->fields['client_id'],
    $config->fields['client_secret'] ?? '',
    $config->fields['scopes'] ?? 'monitoring'
);

Html::redirect($client->buildAuthorizationUrl($redirect_uri, $state));
