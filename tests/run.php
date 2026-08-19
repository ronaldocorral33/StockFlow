<?php
/**
 * Runner de pruebas mínimo, sin dependencias (el proyecto no usa Composer).
 * Uso:  "C:\xampp\php\php.exe" tests\run.php
 */

define('APP_BOOTSTRAP', true);
require __DIR__ . '/../src/Support/autoload.php';
$config = require __DIR__ . '/../config/config.php';
define('APP_CONFIG', $config);

$GLOBALS['__tests'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__tests']['pass']++;
        echo "  ok   {$name}\n";
    } catch (\Throwable $e) {
        $GLOBALS['__tests']['fail']++;
        $GLOBALS['__tests']['failures'][] = "{$name}: {$e->getMessage()}";
        echo "  FAIL {$name}\n       {$e->getMessage()}\n";
    }
}

function assertTrue($cond, string $msg = 'se esperaba true'): void
{
    if ($cond !== true) {
        throw new \RuntimeException($msg);
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \RuntimeException(($msg ?: 'valores distintos') . " — esperado: {$e}, obtenido: {$a}");
    }
}

$suites = glob(__DIR__ . '/suites/*.php');
foreach ($suites as $suite) {
    echo "\n" . basename($suite, '.php') . "\n";
    require $suite;
}

$r = $GLOBALS['__tests'];
echo "\n" . str_repeat('-', 50) . "\n";
echo "{$r['pass']} pasaron, {$r['fail']} fallaron\n";
exit($r['fail'] > 0 ? 1 : 0);
