<?php
/** Pruebas de ResultSummary: cómo se reduce el resultado antes de mandarlo al modelo. */

use App\Services\ResultSummary;

function makeRows(int $n, float $priceEach = 100.0): array
{
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $rows[] = ['id' => $i, 'name' => "Producto $i", 'sale_price' => $priceEach];
    }
    return $rows;
}

test('resultado vacío no revienta', function () {
    $s = ResultSummary::build([], 20);
    assertSame(0, $s['total_filas']);
    assertSame([], $s['filas']);
});

test('si caben todas las filas, se mandan completas y sin nota de muestreo', function () {
    $s = ResultSummary::build(makeRows(5), 20);
    assertSame(5, $s['total_filas']);
    assertSame(5, count($s['filas']));
    assertSame(false, isset($s['nota']), 'no debe avisar de muestreo si no hubo muestreo');
});

test('si sobran filas, solo se manda la muestra pero se reporta el total real', function () {
    $s = ResultSummary::build(makeRows(200), 20);
    assertSame(200, $s['total_filas'], 'el total debe ser el real, no el truncado');
    assertSame(20, count($s['filas']));
    assertTrue(isset($s['nota']), 'debe advertir explícitamente que es una muestra');
});

test('CLAVE: los totales se calculan sobre TODAS las filas, no sobre la muestra', function () {
    // 200 filas × $100 = $20,000. Si el agregado se calculara sobre las 20 mostradas
    // daría $2,000 y el modelo respondería una cifra falsa con total seguridad.
    $s = ResultSummary::build(makeRows(200, 100.0), 20);
    assertSame(20000.0, $s['totales_calculados']['sale_price']['suma']);
});

test('calcula suma, mínimo, máximo y promedio de columnas numéricas', function () {
    $rows = [
        ['profit' => 10],
        ['profit' => 20],
        ['profit' => 60],
    ];
    $agg = ResultSummary::build($rows, 20)['totales_calculados']['profit'];
    assertSame(90.0, $agg['suma']);
    assertSame(10.0, $agg['minimo']);
    assertSame(60.0, $agg['maximo']);
    assertSame(30.0, $agg['promedio']);
});

test('no agrega columnas de texto', function () {
    $s = ResultSummary::build(makeRows(3), 20);
    assertSame(false, isset($s['totales_calculados']['name']), 'sumar nombres no significa nada');
});

test('no agrega una columna que mezcla texto y números', function () {
    $rows = [['talla' => 42], ['talla' => 'M'], ['talla' => 40]];
    $s = ResultSummary::build($rows, 20);
    assertSame(false, isset($s['totales_calculados']['talla']));
});

test('ignora nulos al agregar pero sigue sumando el resto', function () {
    $rows = [['profit' => 100], ['profit' => null], ['profit' => 50]];
    $agg = ResultSummary::build($rows, 20)['totales_calculados']['profit'];
    assertSame(150.0, $agg['suma']);
});

test('expone los nombres de columna para que el modelo sepa qué está viendo', function () {
    $s = ResultSummary::build(makeRows(2), 20);
    assertSame(['id', 'name', 'sale_price'], $s['columnas']);
});
