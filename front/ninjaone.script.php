<?php

use GlpiPlugin\Ninjaone\Config;
use GlpiPlugin\Ninjaone\NinjaOneScriptGenerator;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', UPDATE);

$values = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? NinjaOneScriptGenerator::normalizeInput($_POST)
    : NinjaOneScriptGenerator::defaults();
$config_id = (int) ($_GET['config_id'] ?? $_POST['config_id'] ?? 0);
$back_url = $config_id > 0
    ? Config::getFormURLWithID($config_id) . '#plugin_ninjaone_config_inventory'
    : Config::getSearchURL(false);
$script = '';
$generated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $generated = true;
    try {
        $script = NinjaOneScriptGenerator::render($values);
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect(
            __('Unable to generate NinjaOne script: ', 'ninjaone') . $e->getMessage(),
            false,
            ERROR
        );
    }
}

Html::header(
    __('NinjaOne automation script', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="container-fluid">';
echo '<div class="d-flex align-items-center justify-content-between mb-3">';
echo '<div>';
echo '<h2 class="mb-1">' . __('NinjaOne automation script', 'ninjaone') . '</h2>';
echo '<div class="text-muted">' . __('Generate a PowerShell script that runs GLPI Agent portable from NinjaOne.', 'ninjaone') . '</div>';
echo '</div>';
echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') . '">' . __('Back') . '</a>';
echo '</div>';

echo '<form method="post" action="ninjaone.script.php' . ($config_id > 0 ? '?config_id=' . $config_id : '') . '">';
echo '<input type="hidden" name="config_id" value="' . $config_id . '">';
echo '<div class="card mb-3">';
echo '<div class="card-header">';
echo '<h3 class="card-title">' . __('Script variables', 'ninjaone') . '</h3>';
echo '</div>';
echo '<div class="card-body">';
echo '<div class="row g-3">';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('GLPI base URL', 'ninjaone') . '</label>';
echo '<input class="form-control" type="url" name="glpi_base_url" value="' . htmlspecialchars($values['glpi_base_url'], ENT_QUOTES, 'UTF-8') . '">';
echo '<div class="form-text">' . __('Used to build /front/inventory.php when no final inventory URL is set.', 'ninjaone') . '</div>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Inventory endpoint URL', 'ninjaone') . '</label>';
echo '<input class="form-control" type="url" name="glpi_inventory_url" value="' . htmlspecialchars($values['glpi_inventory_url'], ENT_QUOTES, 'UTF-8') . '">';
echo '<div class="form-text">' . __('Leave empty to use GLPI base URL + /front/inventory.php.', 'ninjaone') . '</div>';
echo '</div>';

echo '<div class="col-md-8">';
echo '<label class="form-label">' . __('GLPI Agent ZIP source', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="agent_zip_source" value="' . htmlspecialchars($values['agent_zip_source'], ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . __('Inventory tag', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="inventory_tag" value="' . htmlspecialchars($values['inventory_tag'], ENT_QUOTES, 'UTF-8') . '">';
echo '<div class="form-text">' . __('The script appends the NinjaOne node ID when available.', 'ninjaone') . '</div>';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . __('Proxy URL', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="proxy_url" value="' . htmlspecialchars($values['proxy_url'], ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . __('CA certificate file', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="ca_cert_file" value="' . htmlspecialchars($values['ca_cert_file'], ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . __('SSL fingerprint', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="ssl_fingerprint" value="' . htmlspecialchars($values['ssl_fingerprint'], ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-check">';
echo '<input class="form-check-input" type="checkbox" name="keep_successful_inventories" value="1"' . ($values['keep_successful_inventories'] === '1' ? ' checked' : '') . '>';
echo '<span class="form-check-label">' . __('Archive successful inventories locally', 'ninjaone') . '</span>';
echo '</label>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-check">';
echo '<input class="form-check-input" type="checkbox" name="disable_ssl_check" value="1"' . ($values['disable_ssl_check'] === '1' ? ' checked' : '') . '>';
echo '<span class="form-check-label">' . __('Disable SSL certificate verification', 'ninjaone') . '</span>';
echo '</label>';
echo '</div>';

echo '</div>';
echo '</div>';
echo '<div class="card-footer">';
echo '<button class="btn btn-primary" type="submit" name="generate" value="1">' . __('Generate script', 'ninjaone') . '</button>';
echo '</div>';
echo '</div>';
Html::closeForm();

if ($generated && $script !== '') {
    echo '<div class="card">';
    echo '<div class="card-header d-flex align-items-center justify-content-between">';
    echo '<h3 class="card-title mb-0">' . __('Generated PowerShell', 'ninjaone') . '</h3>';
    echo '<button class="btn btn-outline-primary" type="button" id="plugin_ninjaone_copy_script">' . __('Copy script', 'ninjaone') . '</button>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<textarea id="plugin_ninjaone_generated_script" class="form-control font-monospace" rows="28" spellcheck="false">'
        . htmlspecialchars($script, ENT_QUOTES, 'UTF-8')
        . '</textarea>';
    echo '</div>';
    echo '</div>';

    echo '<script>';
    echo 'document.getElementById("plugin_ninjaone_copy_script").addEventListener("click", async function () {';
    echo 'const textarea = document.getElementById("plugin_ninjaone_generated_script");';
    echo 'textarea.focus(); textarea.select();';
    echo 'try { await navigator.clipboard.writeText(textarea.value); this.textContent = "' . addslashes(__('Copied', 'ninjaone')) . '"; }';
    echo 'catch (e) { document.execCommand("copy"); this.textContent = "' . addslashes(__('Copied', 'ninjaone')) . '"; }';
    echo '});';
    echo '</script>';
}

echo '</div>';

Html::footer();
