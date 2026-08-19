<?php
/**
 * Pruebas del loop del agente.
 *
 * Aquí NO se prueba que el modelo sea inteligente — eso no se puede probar y no
 * depende de nosotros. Se prueba que el loop AGUANTE cuando el modelo se porta mal:
 * si se cicla, si repite, si pide herramientas inexistentes, si nunca termina.
 *
 * Por eso se inyecta un modelo falso: los frenos deben poder probarse sin gastar
 * dinero real y sin depender de que un LLM decida fallar justo cuando lo necesitamos.
 */

use App\Services\Tools\AgentRunner;
use App\Services\Tools\ToolContext;

$_SESSION['user_id'] = 1;
$_SESSION['active_business_id'] = 1;

/** Construye un turno normalizado como el que devuelve la fachada Llm. */
function turnText(string $text): array
{
    return ['text' => $text, 'tool_calls' => [], 'assistant_message' => ['role' => 'assistant', 'content' => $text]];
}

function turnCall(string $name, array $args, string $id = 'call_1'): array
{
    return [
        'text' => null,
        'tool_calls' => [['id' => $id, 'name' => $name, 'args' => $args]],
        'assistant_message' => ['role' => 'assistant', 'content' => null],
    ];
}

/** Modelo falso que va devolviendo turnos de una lista, y registra cuántas veces lo llamaron. */
function fakeLlm(array $turns, ?array &$calls = null): callable
{
    $i = 0;
    $calls = ['count' => 0];
    return function () use ($turns, &$i, &$calls) {
        $calls['count']++;
        $turn = $turns[$i] ?? turnText('(sin más turnos)');
        $i++;
        return $turn;
    };
}

$SQL_OK = 'SELECT COUNT(*) AS n FROM inventory_items WHERE business_id = {{BUSINESS_ID}}';

// ---------------------------------------------------------------

test('sin herramientas pedidas, termina en 1 paso', function () {
    $r = AgentRunner::run('hola', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        turnText('Puedo ayudarte con tu inventario.'),
    ]));
    assertSame(1, $r['steps']);
    assertSame('Puedo ayudarte con tu inventario.', $r['answer']);
    assertSame([], $r['tools_used']);
    assertSame('completado', $r['stop_reason']);
});

test('una herramienta y luego respuesta: termina en 2 pasos', function () use ($SQL_OK) {
    $r = AgentRunner::run('cuántas piezas', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        turnCall('consultar_inventario', ['sql' => $SQL_OK]),
        turnText('Tienes 253 piezas.'),
    ]));
    assertSame(2, $r['steps']);
    assertSame('Tienes 253 piezas.', $r['answer']);
    assertSame(['consultar_inventario'], $r['tools_used']);
});

test('encadena dos herramientas distintas', function () use ($SQL_OK) {
    $r = AgentRunner::run('lo más vendido y la proyección', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        turnCall('consultar_inventario', ['sql' => $SQL_OK], 'c1'),
        turnCall('proyectar_ventas', ['meses' => 2], 'c2'),
        turnText('Listo.'),
    ]));
    assertSame(3, $r['steps']);
    assertSame(['consultar_inventario', 'proyectar_ventas'], $r['tools_used']);
    assertTrue($r['is_projection']);
});

test('FRENO 1: corta al llegar al tope de pasos, sin colgarse', function () use ($SQL_OK) {
    // Un modelo que SIEMPRE pide herramienta y nunca responde: el caso que sin
    // freno haría un ciclo infinito y quemaría dinero hasta agotar la cuota.
    $turns = [];
    for ($i = 0; $i < 20; $i++) {
        // args distintos cada vez para no activar el freno de repetición
        $turns[] = turnCall('consultar_inventario', ['sql' => $SQL_OK . ' LIMIT ' . ($i + 1)], 'c' . $i);
    }
    $calls = null;
    $r = AgentRunner::run('pregunta imposible', new ToolContext(1, 1), 'sys', 100, fakeLlm($turns, $calls));

    assertSame(AgentRunner::MAX_STEPS, $r['steps']);
    assertSame('limite_de_pasos', $r['stop_reason']);
    assertTrue($r['answer'] !== null, 'debe dar una respuesta útil, no null');
    assertTrue($calls['count'] <= AgentRunner::MAX_STEPS, 'no debe llamar al modelo más veces que el tope');
});

test('FRENO 2: no re-ejecuta una llamada idéntica repetida', function () use ($SQL_OK) {
    // Modelo atorado: pide exactamente lo mismo una y otra vez.
    $same = turnCall('consultar_inventario', ['sql' => $SQL_OK], 'c1');
    $r = AgentRunner::run('pregunta', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        $same, $same, $same, turnText('Ya con esto respondo.'),
    ]));
    // La herramienta se ejecutó UNA sola vez, aunque el modelo la pidió tres.
    assertSame(1, count($r['tools_used']), 'la repetición no debe volver a ejecutarse');
    assertSame('Ya con esto respondo.', $r['answer']);
});

test('FRENO 3: una herramienta inexistente no rompe el loop', function () {
    $r = AgentRunner::run('pregunta', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        turnCall('borrar_base_de_datos', ['todo' => true]),
        turnText('No puedo hacer eso.'),
    ]));
    assertSame(2, $r['steps']);
    assertSame('No puedo hacer eso.', $r['answer']);
});

test('FRENO 4: un SQL rechazado no detiene al agente, le da otra oportunidad', function () use ($SQL_OK) {
    $r = AgentRunner::run('pregunta', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        turnCall('consultar_inventario', ['sql' => 'DROP TABLE users'], 'c1'),
        turnCall('consultar_inventario', ['sql' => $SQL_OK], 'c2'),
        turnText('Corregido, aquí está el dato.'),
    ]));
    assertSame(3, $r['steps']);
    assertSame('Corregido, aquí está el dato.', $r['answer']);
    assertSame(true, $r['sql_valid'], 'el estado final debe reflejar la consulta que SÍ pasó');
});

test('el estado expuesto a la UI trae el SQL realmente ejecutado', function () use ($SQL_OK) {
    $r = AgentRunner::run('pregunta', new ToolContext(1, 1), 'sys', 100, fakeLlm([
        turnCall('consultar_inventario', ['sql' => $SQL_OK]),
        turnText('ok'),
    ]));
    assertTrue(str_contains($r['sql'], 'business_id = 1'), 'debe traer el id real sustituido');
    assertSame(false, str_contains($r['sql'], '{{BUSINESS_ID}}'));
});
