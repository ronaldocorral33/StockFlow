<?php
/** Pruebas de ChatDecision: la validación PHP de la decisión que devuelve el modelo. */

use App\Services\ChatDecision;

test('esquema declara las 3 acciones y exige todas las propiedades (modo estricto)', function () {
    $s = ChatDecision::schema();
    assertSame(['consultar_datos', 'proyectar_ventas', 'explicar_sistema'], $s['properties']['accion']['enum']);
    // En modo estricto de OpenAI, TODA propiedad debe estar en required.
    assertSame(['accion', 'sql', 'meses'], $s['required']);
    assertSame(false, $s['additionalProperties']);
});

test('consultar_datos con SQL válido pasa y normaliza espacios', function () {
    $r = ChatDecision::validate(['accion' => 'consultar_datos', 'sql' => '  SELECT 1  ', 'meses' => null]);
    assertTrue($r['ok']);
    assertSame('consultar_datos', $r['action']);
    assertSame('SELECT 1', $r['sql']);
});

test('consultar_datos sin SQL se rechaza', function () {
    $r = ChatDecision::validate(['accion' => 'consultar_datos', 'sql' => null, 'meses' => null]);
    assertSame(false, $r['ok']);
});

test('consultar_datos con SQL vacío se rechaza', function () {
    $r = ChatDecision::validate(['accion' => 'consultar_datos', 'sql' => '   ', 'meses' => null]);
    assertSame(false, $r['ok']);
});

test('proyectar_ventas conserva los meses que decidió el modelo', function () {
    $r = ChatDecision::validate(['accion' => 'proyectar_ventas', 'sql' => null, 'meses' => 3]);
    assertTrue($r['ok']);
    assertSame(3, $r['months']);
});

test('proyectar_ventas sin meses usa 1 por defecto (no es error)', function () {
    $r = ChatDecision::validate(['accion' => 'proyectar_ventas', 'sql' => null, 'meses' => null]);
    assertTrue($r['ok']);
    assertSame(1, $r['months']);
});

test('proyectar_ventas recorta meses fuera de rango', function () {
    assertSame(3, ChatDecision::validate(['accion' => 'proyectar_ventas', 'sql' => null, 'meses' => 99])['months']);
    assertSame(1, ChatDecision::validate(['accion' => 'proyectar_ventas', 'sql' => null, 'meses' => -5])['months']);
});

test('explicar_sistema no necesita argumentos', function () {
    $r = ChatDecision::validate(['accion' => 'explicar_sistema', 'sql' => null, 'meses' => null]);
    assertTrue($r['ok']);
    assertSame('explicar_sistema', $r['action']);
});

test('accion desconocida se rechaza (no se ejecuta nada)', function () {
    $r = ChatDecision::validate(['accion' => 'borrar_todo', 'sql' => null, 'meses' => null]);
    assertSame(false, $r['ok']);
});

test('respuesta nula del proveedor se rechaza sin reventar', function () {
    $r = ChatDecision::validate(null);
    assertSame(false, $r['ok']);
});

test('objeto sin campo accion se rechaza', function () {
    $r = ChatDecision::validate(['sql' => 'SELECT 1']);
    assertSame(false, $r['ok']);
});

test('ChatDecision NO valida seguridad del SQL — eso sigue siendo trabajo de SqlGuard', function () {
    // Un SQL destructivo pasa esta capa a propósito: aquí solo se valida la FORMA
    // de la decisión. SqlGuard es quien lo rechaza después.
    $r = ChatDecision::validate(['accion' => 'consultar_datos', 'sql' => 'DROP TABLE users', 'meses' => null]);
    assertTrue($r['ok']);
    $guard = \App\Services\SqlGuard::validate($r['sql'], ['inventory_items']);
    assertSame(false, $guard['valid'], 'SqlGuard debe rechazar el DROP');
});
