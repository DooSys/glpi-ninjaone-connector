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
        'SELECT' => ['ninjaone_organization_ref', 'ninjaone_organization_name', 'entities_id'],
        'FROM'   => 'glpi_plugin_ninjaone_organizationmappings',
        'WHERE'  => ['config_ref' => $config_id],
        'ORDER'  => 'ninjaone_organization_name',
    ]);
    foreach ($rows as $row) {
        $organizations[(int) $row['ninjaone_organization_ref']] = $row;
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

    $organization_id = (int) ($input['single_ninjaone_organization_ref'] ?? 0);
    $submitted_entity = (string) ($input['single_entities_id'] ?? '');
    $entities_id = $submitted_entity === '' ? null : (int) $submitted_entity;

    $DB->update(
        'glpi_plugin_ninjaone_organizationmappings',
        ['sync_enabled' => 0],
        ['config_ref' => $config_id]
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
            'config_ref' => $config_id,
            'ninjaone_organization_ref'   => $organization_id,
        ]
    );
}

function plugin_ninjaone_get_cron_url(
    string $itemtype = 'GlpiPlugin\\Ninjaone\\Cron\\NinjaOneSync',
    string $name = 'NinjaoneSync'
): string
{
    global $DB;

    if ($DB->tableExists('glpi_crontasks')) {
        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_crontasks',
            'WHERE'  => [
                'itemtype' => $itemtype,
                'name'     => $name,
            ],
            'LIMIT'  => 1,
        ]);
        if (count($rows) > 0) {
            return CronTask::getFormURLWithID((int) $rows->current()['id']);
        }
    }

    return CronTask::getSearchURL(false);
}

function plugin_ninjaone_config_anchor(string $anchor): string
{
    $allowed = ['connection', 'organizations', 'inventory', 'scheduling', 'diagnostics'];
    return in_array($anchor, $allowed, true) ? $anchor : 'connection';
}

function plugin_ninjaone_config_url_with_anchor(int $config_id, string $anchor): string
{
    return Config::getFormURLWithID($config_id) . '#plugin_ninjaone_config_' . plugin_ninjaone_config_anchor($anchor);
}

if (isset($_POST['test_connection'])) {
    $anchor = plugin_ninjaone_config_anchor((string) $_POST['test_connection']);
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
        Html::redirect(plugin_ninjaone_config_url_with_anchor((int) $_POST['id'], $anchor));
    }
    Html::back();
}

if (isset($_POST['run_sync'])) {
    $anchor = plugin_ninjaone_config_anchor((string) $_POST['run_sync']);
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
        Html::redirect(plugin_ninjaone_config_url_with_anchor((int) $_POST['id'], $anchor));
    }
    Html::back();
}

if (isset($_POST['add'])) {
    $new_id = $config->add($_POST);
    Html::redirect(plugin_ninjaone_config_url_with_anchor((int) $new_id, 'connection'));
}

if (isset($_POST['update'])) {
    $anchor = plugin_ninjaone_config_anchor((string) $_POST['update']);
    $config->update($_POST);
    plugin_ninjaone_apply_single_organization_mode((int) $_POST['id'], $_POST);
    Html::redirect(plugin_ninjaone_config_url_with_anchor((int) $_POST['id'], $anchor));
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
        'scopes'        => Config::DEFAULT_SCOPES,
        'redirect_uri'  => Config::getDefaultRedirectUri(),
        'refresh_token' => '',
        'is_active'     => 1,
        'organization_mode' => 'single',
        'single_ninjaone_organization_ref' => null,
        'inventory_mode' => 'full',
        'sync_stale_days' => 20,
    ];
}

$organizations = (int) ($config->fields['id'] ?? 0) > 0
    ? plugin_ninjaone_get_organizations_for_select((int) $config->fields['id'])
    : [];
$entities = plugin_ninjaone_get_entities_for_select();
$organization_mode = (string) ($config->fields['organization_mode'] ?? 'single');
if (!in_array($organization_mode, ['single', 'multi'], true)) {
    $organization_mode = 'single';
}
$inventory_mode = (string) ($config->fields['inventory_mode'] ?? 'full');
if (!in_array($inventory_mode, ['mapping_only', 'full'], true)) {
    $inventory_mode = 'full';
}
$single_organization_id = (int) ($config->fields['single_ninjaone_organization_ref'] ?? 0);
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
echo '<style>
.plugin-ninjaone-config-block {
    background: var(--tblr-bg-surface, #fff);
    border: 1.5px solid var(--tblr-border-color, #d9dee3) !important;
    scroll-margin-top: 5rem;
}
.plugin-ninjaone-config-block-header {
    background: var(--tblr-bg-surface-secondary, #f6f8fb);
    border-bottom: 1.5px solid var(--tblr-border-color, #d9dee3);
    margin: -1rem -1rem 1rem;
    padding: .75rem 1rem;
}
.plugin-ninjaone-config-block-header h4 {
    margin: 0;
}
.plugin-ninjaone-dirty-indicator {
    align-items: center;
    color: var(--tblr-warning, #f59f00);
    display: none;
    font-size: .875rem;
    gap: .4rem;
    margin: -.35rem 0 1rem;
}
.plugin-ninjaone-dirty-indicator::before {
    background: var(--tblr-warning, #f59f00);
    border-radius: 50%;
    content: "";
    display: inline-block;
    height: .55rem;
    width: .55rem;
}
.plugin-ninjaone-dirty-indicator.is-visible {
    display: flex;
}
</style>';
echo '<form method="post" action="' . Config::getFormURL(false) . '">';
echo '<input type="hidden" name="id" value="' . (int) ($config->fields['id'] ?? 0) . '">';

echo '<div id="plugin_ninjaone_config_connection" class="plugin-ninjaone-config-block rounded p-3 mb-3">';
echo '<div class="plugin-ninjaone-config-block-header rounded-top">';
echo '<h4>' . __('NinjaOne connection', 'ninjaone') . '</h4>';
echo '</div>';
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
echo '<label class="form-label">' . __('NinjaOne API scopes', 'ninjaone') . '</label>';
echo '<input type="hidden" name="scopes" value="' . Config::DEFAULT_SCOPES . '">';
echo '<div class="d-flex flex-column gap-2">';
echo '<label class="form-check mb-0">';
echo '<input class="form-check-input" type="checkbox" checked disabled>';
echo '<span class="form-check-label">' . __('Monitoring', 'ninjaone') . '</span>';
echo '</label>';
foreach (['Management' => __('Management', 'ninjaone'), 'Control' => __('Control', 'ninjaone')] as $scope_label) {
    echo '<label class="form-check mb-0 text-muted">';
    echo '<input class="form-check-input" type="checkbox" disabled>';
    echo '<span class="form-check-label">' . $scope_label . '</span>';
    echo '</label>';
}
echo '</div>';
echo '<div class="form-text">' . __('Aujourd hui : scope Monitoring uniquement. Gestion et Controle plus tard.', 'ninjaone') . '</div>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Client ID', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="client_id" value="' . htmlspecialchars((string) ($config->fields['client_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . __('Client secret', 'ninjaone') . '</label>';
$has_secret = (int) ($config->fields['id'] ?? 0) > 0 && !empty($config->fields['client_secret']);
$secret_marker = '********************************';
echo '<input class="form-control" type="password" name="client_secret" value="'
    . ($has_secret ? $secret_marker : '')
    . '" autocomplete="new-password">';
if ((int) ($config->fields['id'] ?? 0) > 0) {
    echo '<div class="form-text">';
    echo $has_secret
        ? __('A client secret is stored. Leave the hidden value unchanged to keep it.', 'ninjaone')
        : __('No client secret is stored yet.', 'ninjaone');
    echo '</div>';
}
echo '</div>';

echo '<div class="col-md-12">';
echo '<label class="form-label">' . __('Redirect URL', 'ninjaone') . '</label>';
echo '<input class="form-control" type="text" name="redirect_uri" value="' . htmlspecialchars((string) ($config->fields['redirect_uri'] ?? Config::getDefaultRedirectUri()), ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';

if ((int) ($config->fields['id'] ?? 0) > 0) {
    echo '<div class="mt-3 d-flex flex-wrap gap-2">';
    echo '<button class="btn btn-primary" type="submit" name="update" value="connection">' . _x('button', 'Save') . '</button>';
    echo '<button class="btn btn-success" type="submit" name="test_connection" value="connection">' . __('Test connection', 'ninjaone') . '</button>';
    echo '<button class="btn btn-info text-white" type="submit" name="run_sync" value="connection">' . __('Run synchronization', 'ninjaone') . '</button>';
    echo '</div>';
} else {
    echo '<div class="col-md-12 d-flex flex-wrap gap-2">';
    echo '<button class="btn btn-primary" type="submit" name="add" value="1">' . _x('button', 'Add') . '</button>';
    echo '</div>';
}

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

if ((int) ($config->fields['id'] ?? 0) > 0) {
    echo '<div id="plugin_ninjaone_config_organizations" class="plugin-ninjaone-config-block rounded p-3 mb-3">';
    echo '<div class="plugin-ninjaone-config-block-header rounded-top">';
    echo '<h4>' . __('Organization management', 'ninjaone') . '</h4>';
    echo '</div>';
    echo '<div class="plugin-ninjaone-dirty-indicator" data-ninjaone-dirty-indicator>';
    echo __('Un changement a ete detecte, merci de sauvegarder.', 'ninjaone');
    echo '</div>';

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
        echo '<select class="form-select" name="single_ninjaone_organization_ref">';
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
    }

    echo '<div class="col-md-12 d-flex flex-wrap gap-2 mt-3">';
    echo '<button class="btn btn-primary" type="submit" name="update" value="organizations">' . _x('button', 'Save') . '</button>';
    if ($organizations !== [] && $organization_mode === 'multi') {
        echo '<a class="btn btn-outline-primary" href="organization.mapping.php?config_id=' . (int) $config->fields['id'] . '">' . __('Map organizations', 'ninjaone') . '</a>';
    }
    if ($organizations !== []) {
        echo '<a class="btn btn-outline-primary" href="location.mapping.php?config_id=' . (int) $config->fields['id'] . '">' . __('Map locations', 'ninjaone') . '</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div id="plugin_ninjaone_config_inventory" class="plugin-ninjaone-config-block rounded p-3 mb-3">';
    echo '<div class="plugin-ninjaone-config-block-header rounded-top">';
    echo '<h4>' . __('Inventory source', 'ninjaone') . '</h4>';
    echo '</div>';
    echo '<div class="plugin-ninjaone-dirty-indicator" data-ninjaone-dirty-indicator>';
    echo __('Un changement a ete detecte, merci de sauvegarder.', 'ninjaone');
    echo '</div>';
    echo '<div class="row g-3">';
    echo '<div class="col-md-6">';
    echo '<label class="form-label">' . __('NinjaOne synchronization mode', 'ninjaone') . '</label>';
    echo '<select class="form-select" name="inventory_mode" id="plugin_ninjaone_inventory_mode">';
    echo '<option value="full"' . ($inventory_mode === 'full' ? ' selected' : '') . '>' . __('Minimal synchronization - inventory based on NinjaOne', 'ninjaone') . '</option>';
    echo '<option value="mapping_only"' . ($inventory_mode === 'mapping_only' ? ' selected' : '') . '>' . __('Advanced synchronization - GLPI Agent inventory through NinjaOne Automation', 'ninjaone') . '</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="col-md-3">';
    echo '<label class="form-label">' . __('Stale sync alert after', 'ninjaone') . '</label>';
    echo '<div class="input-group">';
    echo '<input class="form-control" type="number" min="1" step="1" name="sync_stale_days" value="' . htmlspecialchars((string) ($config->fields['sync_stale_days'] ?? 20), ENT_QUOTES, 'UTF-8') . '">';
    echo '<span class="input-group-text">' . __('days', 'ninjaone') . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="col-md-3 d-flex align-items-end">';
    $ninjaone_inventory_help = __('The NinjaOne inventory mode creates or updates computers from NinjaOne with the name, serial number, last contact and the basic inventory data available through the NinjaOne API.', 'ninjaone');
    $glpi_agent_inventory_help = __('The GLPI Agent mode keeps NinjaOne as the link and orchestration source, while GLPI Agent performs the complete GLPI inventory: hardware, operating system, software and the detailed inventory options supported by GLPI.', 'ninjaone');
    echo '<div id="plugin_ninjaone_inventory_help" class="alert alert-info py-2 px-3 mb-0 w-100" data-full="'
        . htmlspecialchars($ninjaone_inventory_help, ENT_QUOTES, 'UTF-8')
        . '" data-mapping-only="'
        . htmlspecialchars($glpi_agent_inventory_help, ENT_QUOTES, 'UTF-8')
        . '">';
    echo $inventory_mode === 'full'
        ? $ninjaone_inventory_help
        : $glpi_agent_inventory_help;
    echo '</div>';
    echo '</div>';
    echo '<div class="col-md-12 d-flex flex-wrap gap-2">';
    echo '<button class="btn btn-primary" type="submit" name="update" value="inventory">' . _x('button', 'Save') . '</button>';
    if ($inventory_mode === 'mapping_only') {
        echo '<a class="btn btn-outline-primary" href="ninjaone.script.php?config_id=' . (int) $config->fields['id'] . '">' . __('Generate NinjaOne automation script', 'ninjaone') . '</a>';
    }
    echo '<a class="btn btn-outline-secondary" href="https://support.tinisys.fr/front/ruleimportasset.php">' . __('Open GLPI asset import rules', 'ninjaone') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div id="plugin_ninjaone_config_scheduling" class="plugin-ninjaone-config-block rounded p-3 mb-3">';
    echo '<div class="plugin-ninjaone-config-block-header rounded-top">';
    echo '<h4>' . __('Scheduling', 'ninjaone') . '</h4>';
    echo '</div>';
    echo '<div class="row g-3">';
    echo '<div class="col-md-12">';
    echo '<div class="alert alert-info py-2 px-3 mb-0 w-100">';
    echo __('The GLPI automatic action runs the NinjaOne synchronization twice a day. Use the button below to force an immediate synchronization when needed.', 'ninjaone');
    echo '</div>';
    echo '</div>';
    echo '<div class="col-md-12 d-flex gap-2">';
    echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars(plugin_ninjaone_get_cron_url(), ENT_QUOTES, 'UTF-8') . '">' . __('Open GLPI cron task', 'ninjaone') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div id="plugin_ninjaone_config_diagnostics" class="plugin-ninjaone-config-block rounded p-3 mb-3">';
    echo '<div class="plugin-ninjaone-config-block-header rounded-top">';
    echo '<h4>' . __('Diagnostics', 'ninjaone') . '</h4>';
    echo '</div>';
    echo '<div class="alert alert-info py-2 px-3 mb-3">';
    echo __('NinjaOne synchronization logs older than 30 days are purged every 3 days by the dedicated GLPI automatic action.', 'ninjaone');
    echo '</div>';
    echo '<div class="d-flex flex-wrap gap-2">';
    echo '<a class="btn btn-outline-secondary" href="device.payload.php?config_id=' . (int) $config->fields['id'] . '">' . __('Inspect device payloads', 'ninjaone') . '</a>';
    echo '<a class="btn btn-outline-secondary" href="synclog.php?config_id=' . (int) $config->fields['id'] . '">' . __('Logs') . '</a>';
    echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars(plugin_ninjaone_get_cron_url('GlpiPlugin\\Ninjaone\\Cron\\NinjaOneLogPurge', 'NinjaoneLogPurge'), ENT_QUOTES, 'UTF-8') . '">' . __('Open log purge cron task', 'ninjaone') . '</a>';
    echo '</div>';
    echo '</div>';
}

echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const inventoryMode = document.getElementById("plugin_ninjaone_inventory_mode");
    const inventoryHelp = document.getElementById("plugin_ninjaone_inventory_help");
    if (inventoryMode && inventoryHelp) {
        const refreshInventoryHelp = function () {
            inventoryHelp.textContent = inventoryMode.value === "full"
                ? inventoryHelp.dataset.full
                : inventoryHelp.dataset.mappingOnly;
        };
        inventoryMode.addEventListener("change", refreshInventoryHelp);
        refreshInventoryHelp();
    }

    document.querySelectorAll("#plugin_ninjaone_config_organizations, #plugin_ninjaone_config_inventory").forEach(function (section) {
        const indicator = section.querySelector("[data-ninjaone-dirty-indicator]");
        if (!indicator) {
            return;
        }
        const showIndicator = function () {
            indicator.classList.add("is-visible");
        };
        section.querySelectorAll("input, select, textarea").forEach(function (field) {
            field.addEventListener("change", showIndicator);
            field.addEventListener("input", showIndicator);
        });
    });
});
</script>';

echo '<div class="d-flex flex-wrap gap-2">';
echo '<a class="btn btn-outline-secondary" href="' . Config::getSearchURL(false) . '">' . __('Cancel') . '</a>';
echo '</div>';
Html::closeForm();
echo '</div>';

Html::footer();
