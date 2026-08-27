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

/**
 * Piso de ids para los negocios y usuarios de prueba.
 *
 * Las suites limpian con DELETE ... WHERE business_id = N. Si ese N coincidiera con un
 * negocio REAL, la limpieza borraría datos de producción en cascada — y el
 * AUTO_INCREMENT de este proyecto ya iba en 956 con una cuenta real en el 929.
 *
 * Todo id de prueba vive por encima de este piso, imposible de alcanzar por uso normal.
 */
const ID_MIN_PRUEBAS = 900000;

/** Falla ruidosamente si una suite intenta operar sobre un id que podría ser real. */
function assertIdDePrueba(int $id): void
{
    if ($id < ID_MIN_PRUEBAS) {
        throw new \RuntimeException(
            "ID {$id} está por debajo de ID_MIN_PRUEBAS: una prueba NUNCA debe crear ni "
            . "borrar registros con un id que el sistema pueda asignar a un negocio real."
        );
    }
}


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
