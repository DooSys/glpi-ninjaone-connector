<?php

use GlpiPlugin\Ninjaone\Config;

include(__DIR__ . '/../inc/bootstrap.php');

Session::checkRight('config', READ);

Html::header(
    __('NinjaOne connector', 'ninjaone'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Config::class
);

echo '<div class="mb-3">';
echo '<a class="btn btn-primary" href="' . Config::getFormURL(false) . '">';
echo __('Add a NinjaOne connection', 'ninjaone');
echo '</a>';
echo '</div>';

Search::show(Config::class);

Html::footer();
