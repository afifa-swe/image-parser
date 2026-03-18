<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicDir = __DIR__ . '/public';
$file = $publicDir . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicDir . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $publicDir;

require $publicDir . '/index.php';
