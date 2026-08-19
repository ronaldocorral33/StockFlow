<?php
/**
 * Pruebas del registro de herramientas.
 *
 * Lo que más importa verificar aquí: que una herramienta NO puede salirse del negocio
 * de su contexto, sin importar qué argumentos mande el modelo.
 */

use App\Services\Tools\ToolContext;
use App\Services\Tools\ToolRegistry;

// ToolRegistry verifica permisos REALES contra el rol del usuario en la base de datos,
// y Authz lee de $_SESSION. En CLI no hay sesión, así que se simula la del usuario 1
// (Administrador del negocio 1). Que las pruebas necesiten esto no es un estorbo: es la
// evidencia de que el control de permisos está de verdad en el camino de ejecución.
$_SESSION['user_id'] = 1;
$_SESSION['active_business_id'] = 1;

test('las definiciones tienen la forma que exige un JSON Schema de herramienta', function () {
    foreach (ToolRegistry::definitions() as $def) {
        assertTrue(isset($def['name']), 'falta name');
        assertTrue(isset($def['description']), 'falta description en ' . $def['name']);
        assertTrue(isset($def['parameters']['type']), 'falta parameters.type en ' . $def['name']);
        assertSame('object', $def['parameters']['type']);
        assertTrue(isset($def['parameters']['required']), 'falta required en ' . $def['name']);
        assertSame(false, $def['parameters']['additionalProperties'], 'debe cerrar propiedades extra');
    }
});

test('expone exactamente las herramientas esperadas', function () {
    $names = array_column(ToolRegistry::definitions(), 'name');
    sort($names);
    assertSame(['consultar_inventario', 'proyectar_ventas'], $names);
});

test('una herramienta inexistente se rechaza sin reventar', function () {
    $r = ToolRegistry::run('borrar_todo', [], new ToolContext(1, 1));
    assertSame(false, $r['ok']);
    assertTrue(str_contains($r['error'], 'no existe'));
});

test('consultar_inventario sin argumento sql devuelve error, no excepción', function () {
    $r = ToolRegistry::run('consultar_inventario', [], new ToolContext(1, 1));
    assertSame(false, $r['ok']);
});

test('consultar_inventario rechaza SQL que no pasa SqlGuard', function () {
    $r = ToolRegistry::run('consultar_inventario', ['sql' => 'DROP TABLE users'], new ToolContext(1, 1));
    assertSame(false, $r['ok']);
    assertTrue(str_contains($r['error'], 'rechazada'));
});

test('CLAVE: el modelo no puede elegir el negocio — se ignora lo que mande en args', function () {
    // El modelo intenta colar un business_id propio. Como el token {{BUSINESS_ID}} es
    // obligatorio y SqlGuard exige que sea la ÚNICA forma de mencionar la columna,
    // esta consulta se rechaza antes de tocar la base de datos.
    $r = ToolRegistry::run(
        'consultar_inventario',
        ['sql' => 'SELECT * FROM inventory_items WHERE business_id = 999', 'business_id' => 999],
        new ToolContext(1, 1)
    );
    assertSame(false, $r['ok']);
});

test('el SQL ejecutado siempre lleva el business_id del contexto, no el del modelo', function () {
    $r = ToolRegistry::run(
        'consultar_inventario',
        ['sql' => 'SELECT COUNT(*) AS n FROM inventory_items WHERE business_id = {{BUSINESS_ID}}'],
        new ToolContext(1, 1)
    );
    assertTrue($r['ok'], $r['error'] ?? '');
    assertTrue(str_contains($r['sql_ejecutado'], 'business_id = 1'));
    assertSame(false, str_contains($r['sql_ejecutado'], '{{BUSINESS_ID}}'), 'no debe quedar el token sin sustituir');
});

test('proyectar_ventas recorta meses fuera de rango', function () {
    $r = ToolRegistry::run('proyectar_ventas', ['meses' => 999], new ToolContext(1, 1));
    assertTrue($r['ok']);
    if (($r['metodo'] ?? '') !== 'insufficient_data') {
        assertTrue(count($r['proyeccion']) <= 6, 'no debe proyectar más de 6 meses');
    }
});

test('proyectar_ventas rechaza un argumento no numérico', function () {
    $r = ToolRegistry::run('proyectar_ventas', ['meses' => 'muchos'], new ToolContext(1, 1));
    assertSame(false, $r['ok']);
});

test('los resultados siempre son serializables a JSON (van de vuelta al modelo)', function () {
    $r = ToolRegistry::run(
        'consultar_inventario',
        ['sql' => 'SELECT COUNT(*) AS n FROM inventory_items WHERE business_id = {{BUSINESS_ID}}'],
        new ToolContext(1, 1)
    );
    $json = json_encode($r, JSON_UNESCAPED_UNICODE);
    assertTrue($json !== false, 'json_encode falló: ' . json_last_error_msg());
});
