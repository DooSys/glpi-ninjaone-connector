<?php

declare(strict_types=1);

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\Client\NinjaOneClient;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

if (isset($_GET['error'])) {
    Session::addMessageAfterRedirect(
        __('NinjaOne authorization failed: ', 'ninjaone') . (string) $_GET['error'],
        false,
        ERROR
    );
    Html::redirect(Config::getSearchURL(false));
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

if ($code === '' || $state === '') {
    echo 'NinjaOne OAuth callback endpoint is available.';
    exit;
}

$config = new Config();
$configs = $config->find(['oauth_state' => $state], [], 1);
if (count($configs) === 0) {
    Session::addMessageAfterRedirect(__('NinjaOne authorization callback was already processed or expired.', 'ninjaone'), false, WARNING);
    Html::redirect(Config::getSearchURL(false));
}

$id = (int) array_key_first($configs);
$config->getFromDB($id);
$redirect_uri = $config->fields['redirect_uri'] ?: Config::getDefaultRedirectUri();

try {
    $client = new NinjaOneClient(
        $config->fields['base_url'],
        $config->fields['client_id'],
        $config->fields['client_secret'] ?? '',
        $config->fields['scopes'] ?? 'monitoring'
    );
    $tokens = $client->exchangeAuthorizationCode($code, $redirect_uri);
    $expires_in = (int) ($tokens['expires_in'] ?? 3600);
    $updated_refresh_token = $tokens['refresh_token'] ?? ($config->fields['refresh_token'] ?? null);

    $config->update([
        'id'               => $id,
        'access_token'     => $tokens['access_token'],
        'refresh_token'    => $updated_refresh_token,
        'token_expires_at' => date('Y-m-d H:i:s', time() + $expires_in - 60),
        'oauth_state'      => null,
    ]);

    if (!empty($updated_refresh_token)) {
        Session::addMessageAfterRedirect(__('NinjaOne authorization successful.', 'ninjaone'));
    } else {
        $token_keys = implode(', ', array_keys($tokens));
        Session::addMessageAfterRedirect(
            __('NinjaOne authorization returned no refresh token. Token response keys: ', 'ninjaone') . $token_keys,
            false,
            WARNING
        );
    }
    Html::redirect(Config::getFormURLWithID($id));
} catch (Throwable $e) {
    Session::addMessageAfterRedirect(
        __('NinjaOne authorization failed: ', 'ninjaone') . $e->getMessage(),
        false,
        ERROR
    );
    Html::redirect(Config::getFormURLWithID($id));
}
