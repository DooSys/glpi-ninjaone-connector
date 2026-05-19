<?php

use GlpiPlugin\Ninjaone\Config;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', READ);

global $DB;

$config_id = (int) ($_GET['config_id'] ?? 0);

Html::header(
    __('NinjaOne synchronization logs', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="container-fluid">';
echo '<div class="card">';
echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<h3 class="card-title mb-0">' . __('NinjaOne synchronization logs', 'ninjaone') . '</h3>';
if ($config_id > 0) {
    echo '<a class="btn btn-outline-secondary" href="' . Config::getFormURLWithID($config_id) . '">' . __('Back') . '</a>';
}
echo '</div>';

echo '<div class="table-responsive">';
echo '<table class="table table-hover mb-0">';
echo '<thead><tr>';
echo '<th>' . __('Started') . '</th>';
echo '<th>' . __('Status') . '</th>';
echo '<th>' . __('Mode') . '</th>';
echo '<th>' . __('Created') . '</th>';
echo '<th>' . __('Updated') . '</th>';
echo '<th>' . __('Skipped') . '</th>';
echo '<th>' . __('Errors') . '</th>';
echo '<th>' . __('Message') . '</th>';
echo '</tr></thead><tbody>';

$where = [];
if ($config_id > 0) {
    $where['config_ref'] = $config_id;
}

$rows = $DB->request([
    'FROM'  => 'glpi_plugin_ninjaone_synclogs',
    'WHERE' => $where,
    'ORDER' => ['started_at DESC', 'id DESC'],
    'LIMIT' => 20,
]);

$count = 0;
foreach ($rows as $row) {
    $count++;
    echo '<tr>';
    echo '<td>' . htmlspecialchars((string) $row['started_at'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string) $row['mode'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . (int) $row['created_count'] . '</td>';
    echo '<td>' . (int) $row['updated_count'] . '</td>';
    echo '<td>' . (int) $row['skipped_count'] . '</td>';
    echo '<td>' . (int) $row['error_count'] . '</td>';
    echo '<td><pre class="mb-0 text-wrap">' . htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') . '</pre></td>';
    echo '</tr>';
}

if ($count === 0) {
    echo '<tr><td colspan="8" class="text-center text-muted">' . __('No log found', 'ninjaone') . '</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</div>';
echo '</div>';

Html::footer();

