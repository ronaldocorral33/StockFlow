<?php
define('APP_BOOTSTRAP', true);

error_reporting(E_ALL);
ini_set('display_errors', '1'); // proyecto de clase / desarrollo local

// --- Base URL del proyecto (la carpeta puede tener espacios: "Control De Inventario") ---
$documentRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__, 2)));
$baseUrlRaw = substr($projectRoot, strlen($documentRoot));
define('APP_BASE_URL', str_replace(' ', '%20', $baseUrlRaw));

/** Construye una URL absoluta (desde la raíz del sitio) hacia $path dentro del proyecto. */
function url(string $path = ''): string
{
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * URL de un archivo estático (CSS/JS) con "cache busting".
 *
 * El navegador guarda en caché los .js y .css. Sin esto, al cambiar un archivo el
 * usuario sigue viendo la versión vieja hasta que limpia el caché a mano — un bug
 * de los que hacen perder horas ("ya lo arreglé pero no se ve el cambio").
 *
 * Al colgarle la fecha de modificación del archivo (?v=...), la URL cambia sola cada
 * vez que el contenido cambia, y el navegador se ve obligado a bajarlo de nuevo.
 */
function asset(string $path): string
{
    $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    $version = is_file($full) ? filemtime($full) : time();
    return url($path) . '?v=' . $version;
}

require __DIR__ . '/autoload.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/icons.php';

$config = require dirname(__DIR__, 2) . '/config/config.php';
define('APP_CONFIG', $config);

session_name($config['session']['name']);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || !empty($config['session']['force_secure']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => APP_BASE_URL !== '' ? APP_BASE_URL : '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
]);
session_start();
