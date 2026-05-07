<?php

declare(strict_types=1);

$candidates = [];

$root = __DIR__;
while (dirname($root) !== $root) {
    $candidates[] = $root . '/inc/includes.php';
    $root = dirname($root);
}

if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
    $document_root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
    $candidates[] = $document_root . '/inc/includes.php';
    $candidates[] = dirname($document_root) . '/inc/includes.php';
    $candidates[] = dirname($document_root, 2) . '/inc/includes.php';
}

$candidates[] = '/var/www/html/inc/includes.php';
$candidates[] = '/var/www/html/glpi/inc/includes.php';
$candidates[] = '/var/glpi/inc/includes.php';

foreach (array_unique($candidates) as $candidate) {
    if (file_exists($candidate)) {
        include($candidate);
        return;
    }
}

http_response_code(500);
echo 'Unable to locate GLPI inc/includes.php. Checked: ' . implode(', ', array_unique($candidates));
exit;

