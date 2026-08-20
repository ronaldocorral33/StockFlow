<?php
/**
 * Integración end-to-end del agente, con un modelo falso.
 *
 * QUÉ SE PRUEBA AQUÍ Y QUÉ NO
 * Esto NO prueba que el modelo elija bien la herramienta — eso depende del LLM y no se
 * puede fijar en una prueba determinista (para eso está scripts/eval_agente.php, que
 * pega al proveedor real).
 *
 * Lo que sí se prueba es el CABLEADO: que las herramientas nuevas estén registradas y
 * expuestas con el catálogo de este negocio, que los resultados vuelvan al modelo, que
 * los frenos sigan puestos, y que el contrato con el frontend no se rompió.
 */

use App\Services\Agent\SchemaSemantics as SS;
use App\Services\Tools\AgentRunner;
use App\Services\Tools\ToolContext;
use App\Services\Tools\ToolRegistry;

$_SESSION['user_id'] = 1;
$_SESSION['active_business_id'] = 1;

const E2E_BIZ = 1;
$e2eCtx = new ToolContext(E2E_BIZ, 1, '2026-08-19');
SS::flush();

// La traza se captura en vez de escribirse: silencia la salida de la suite y, de paso,
// permite verificar el FORMATO del log más abajo.
$GLOBALS['e2eLog'] = [];
AgentRunner::$logSink = function (string $m) { $GLOBALS['e2eLog'][] = $m; };

/** Turnos normalizados, iguales a los que devuelve la fachada Llm. */
function e2eText(string $t): array
{
    return ['text' => $t, 'tool_calls' => [], 'assistant_message' => ['role' => 'assistant', 'content' => $t]];
}
function e2eCall(string $name, array $args, string $id = 'c1'): array
{
    return [
        'text' => null,
        'tool_calls' => [['id' => $id, 'name' => $name, 'args' => $args]],
        'assistant_message' => ['role' => 'assistant', 'content' => null],
    ];
}
/** Modelo falso que además CAPTURA lo que se le mandó, para poder inspeccionarlo. */
function e2eLlm(array $turns, ?array &$visto = null): callable
{
    $i = 0;
    $visto = ['tools' => null, 'system' => null, 'messages' => null, 'llamadas' => 0];
    return function (array $m, string $s, array $t, int $mt) use ($turns, &$i, &$visto) {
        $visto['llamadas']++;
        $visto['tools'] = $t;
        $visto['system'] = $s;
        $visto['messages'] = $m;
        $turn = $turns[$i] ?? e2eText('(sin más turnos)');
        $i++;
        return $turn;
    };
}

// ---------------------------------------------------------------
// El catálogo que recibe el modelo
// ---------------------------------------------------------------

test('las herramientas analíticas están registradas y se exponen al modelo', function () use ($e2eCtx) {
    $nombres = array_column(ToolRegistry::definitions($e2eCtx), 'name');
    foreach (['ranking_ventas', 'resumen_ventas', 'comparar_periodos', 'consultar_stock', 'productos_agotados'] as $t) {
        assertTrue(in_array($t, $nombres, true), "falta la herramienta {$t}");
    }
    assertTrue(in_array('consultar_inventario', $nombres, true), 'el fallback de SQL se conserva');
    assertTrue(in_array('proyectar_ventas', $nombres, true), 'la proyección se conserva');
});

test('las analíticas van ANTES del fallback en la lista', function () use ($e2eCtx) {
    // El orden es una señal para el modelo: lo primero que lee es lo que debe preferir.
    $nombres = array_column(ToolRegistry::definitions($e2eCtx), 'name');
    assertTrue(
        array_search('ranking_ventas', $nombres, true) < array_search('consultar_inventario', $nombres, true),
        'ranking_ventas debe aparecer antes que el SQL libre'
    );
});

test('CLAVE: el enum de dimensiones sale del negocio, no de una lista fija', function () use ($e2eCtx) {
    $defs = ToolRegistry::definitions($e2eCtx);
    $ranking = null;
    foreach ($defs as $d) {
        if ($d['name'] === 'ranking_ventas') { $ranking = $d; break; }
    }
    $enum = $ranking['parameters']['properties']['dimension']['enum'];

    assertTrue(in_array('name', $enum, true), 'las dimensiones fijas con datos entran');
    assertSame(false, in_array('category', $enum, true), 'las vacías NO se ofrecen');
    assertSame(false, in_array('team', $enum, true), 'una clave inventada nunca está en el enum');
    assertSame(SS::dimensionKeys(E2E_BIZ, true), $enum, 'el enum es exactamente el catálogo utilizable');
});

test('la descripción de la dimensión incluye ejemplos reales', function () use ($e2eCtx) {
    // Los ejemplos son el mecanismo que le permite mapear "equipo" a "name".
    $defs = ToolRegistry::definitions($e2eCtx);
    foreach ($defs as $d) {
        if ($d['name'] !== 'ranking_ventas') { continue; }
        $desc = $d['parameters']['properties']['dimension']['description'];
        assertTrue(str_contains($desc, 'Producto'), 'debe traer la etiqueta');
        assertTrue(str_contains($desc, 'ej.'), 'y ejemplos de valores');
        assertTrue(str_contains($desc, 'SIGNIFICADO'), 'y la instrucción de traducir por significado');
    }
});

test('sin contexto no se ofrecen herramientas que necesitan el catálogo', function () {
    $nombres = array_column(ToolRegistry::definitions(null), 'name');
    assertSame(false, in_array('ranking_ventas', $nombres, true));
    assertTrue(in_array('consultar_inventario', $nombres, true), 'el fallback no depende del catálogo');
});

// ---------------------------------------------------------------
// El ciclo completo
// ---------------------------------------------------------------

test('ranking_ventas → resultado estructurado → respuesta final', function () use ($e2eCtx) {
    $visto = null;
    $r = AgentRunner::run(
        '¿Cuál fue mi jersey más vendida en agosto de 2026?',
        $e2eCtx,
        'sys',
        400,
        e2eLlm([
            e2eCall('ranking_ventas', [
                'dimension' => 'name',
                'fecha_inicio' => '2026-08-01',
                'fecha_fin' => '2026-09-01',
                'limite' => 1,
            ]),
            e2eText('America fue el más vendido en agosto de 2026, con 7 unidades.'),
        ], $visto)
    );

    assertSame(2, $r['steps']);
    assertSame(['ranking_ventas'], $r['tools_used']);
    assertSame('completado', $r['stop_reason']);

    // El resultado de la herramienta debe haber vuelto al modelo con los datos reales.
    $json = json_encode($visto['messages'], JSON_UNESCAPED_UNICODE);
    assertTrue(str_contains($json, 'America'), 'el dato real debe llegar al modelo');
    // El contenido del tool_result es una CADENA JSON dentro del mensaje, así que al
    // serializar los mensajes queda doblemente escapado. Se busca la forma escapada.
    assertTrue(
        str_contains($json, 'encontrado') && str_contains($json, 'true'),
        'la bandera de resultado debe viajar al modelo'
    );
    assertTrue(str_contains($json, '"role":"assistant"'), 'y el turno del asistente debe quedar en el hilo');
});

test('una dimensión inventada vuelve como error y el agente puede corregir', function () use ($e2eCtx) {
    $r = AgentRunner::run('el equipo más vendido', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('ranking_ventas', ['dimension' => 'team', 'periodo' => 'este_mes'], 'c1'),
        e2eCall('ranking_ventas', ['dimension' => 'name', 'periodo' => 'este_mes'], 'c2'),
        e2eText('Corregido.'),
    ]));
    assertSame(3, $r['steps'], 'el error no detiene el ciclo: le da otra oportunidad');
    assertSame('Corregido.', $r['answer']);
});

test('los frenos del ciclo siguen puestos', function () use ($e2eCtx) {
    // MAX_STEPS: un modelo que nunca responde no puede colgar el sistema.
    $turnos = [];
    for ($i = 0; $i < 20; $i++) {
        $turnos[] = e2eCall('ranking_ventas', ['dimension' => 'name', 'limite' => $i + 1], 'c' . $i);
    }
    $visto = null;
    $r = AgentRunner::run('pregunta imposible', $e2eCtx, 'sys', 400, e2eLlm($turnos, $visto));
    assertSame(AgentRunner::MAX_STEPS, $r['steps']);
    assertSame('limite_de_pasos', $r['stop_reason']);
    assertTrue($visto['llamadas'] <= AgentRunner::MAX_STEPS);

    // Repetición: la misma llamada no se re-ejecuta.
    $igual = e2eCall('resumen_ventas', ['periodo' => 'este_mes'], 'c1');
    $r2 = AgentRunner::run('otra', $e2eCtx, 'sys', 400, e2eLlm([$igual, $igual, e2eText('ya')]));
    assertSame(1, count($r2['tools_used']), 'la repetición no debe volver a consultar la base');
});

test('el SQL libre sigue pasando por SqlGuard', function () use ($e2eCtx) {
    // El fallback no se debilitó: una escritura se rechaza igual que antes.
    $r = AgentRunner::run('borra todo', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('consultar_inventario', ['sql' => 'DELETE FROM inventory_items'], 'c1'),
        e2eText('No puedo modificar datos.'),
    ]));
    assertSame(false, $r['sql_valid'], 'SqlGuard debe rechazarlo');
    assertTrue(str_contains((string)$r['rejection_reason'], 'SELECT'), 'y decir por qué');
});

test('CASO 10: ninguna herramienta del catálogo puede escribir', function () use ($e2eCtx) {
    // Revisión estructural: ni una definición expuesta al modelo admite una operación
    // de escritura. Si algún día alguien agrega una, esta prueba lo delata.
    $defs = ToolRegistry::definitions($e2eCtx);
    $prohibidas = ['crear', 'insert', 'update', 'delete', 'borrar', 'eliminar', 'registrar', 'modificar', 'vender'];
    foreach ($defs as $d) {
        foreach ($prohibidas as $p) {
            assertSame(
                false,
                str_contains(mb_strtolower($d['name']), $p),
                "la herramienta '{$d['name']}' sugiere una operación de escritura"
            );
        }
    }
});

// ---------------------------------------------------------------
// Contrato con el frontend
// ---------------------------------------------------------------

test('el contrato answer + rows no se rompió', function () use ($e2eCtx) {
    $r = AgentRunner::run('cuántas piezas', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('consultar_inventario', [
            'sql' => 'SELECT name FROM inventory_items WHERE business_id = {{BUSINESS_ID}} LIMIT 3',
        ]),
        e2eText('Listo.'),
    ]));
    foreach (['answer', 'rows', 'sql', 'is_projection', 'tools_used', 'steps', 'row_count'] as $campo) {
        assertTrue(array_key_exists($campo, $r), "el frontend espera '{$campo}'");
    }
    assertTrue(is_array($r['rows']));
});

test('las analíticas exponen filas tabulares para el frontend', function () use ($e2eCtx) {
    // rowsTable() del frontend dibuja $r['rows']; un ranking debe poder llenarlo.
    $r = AgentRunner::run('top productos', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('ranking_ventas', ['dimension' => 'name', 'periodo' => 'este_ano', 'limite' => 5]),
        e2eText('Listo.'),
    ]));
    assertTrue(count($r['rows']) > 0, 'un ranking debe llegar a la tabla del frontend');
    // La columna genérica "valor" se renombra con la etiqueta real de la dimensión,
    // para que el encabezado de la tabla diga "Producto" y no "valor".
    assertTrue(isset($r['rows'][0]['Producto']), 'la columna debe llevar la etiqueta de la dimensión');
    assertSame(false, isset($r['rows'][0]['valor']), 'y no la clave interna');
    assertTrue(isset($r['rows'][0]['unidades']), 'más la de unidades');
});

// ---------------------------------------------------------------
// Observabilidad
// ---------------------------------------------------------------

test('el log traza cada paso con herramienta, argumentos y resultado', function () use ($e2eCtx) {
    $GLOBALS['e2eLog'] = [];
    AgentRunner::run('top', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('ranking_ventas', ['dimension' => 'name', 'periodo' => 'este_mes']),
        e2eText('Listo.'),
    ]));

    $todo = implode("\n", $GLOBALS['e2eLog']);
    assertTrue(str_contains($todo, '[PASO 1]'), 'debe numerar los pasos');
    assertTrue(str_contains($todo, 'herramienta=ranking_ventas'), 'y nombrar la herramienta');
    assertTrue(str_contains($todo, 'argumentos:'), 'y registrar los argumentos');
    assertTrue(str_contains($todo, 'resultado:'), 'y el resultado');
    assertTrue(str_contains($todo, 'respuesta final'), 'y cerrar con la respuesta final');
});

test('el log distingue "sin datos" de un fallo del sistema', function () use ($e2eCtx) {
    $GLOBALS['e2eLog'] = [];
    AgentRunner::run('x', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('ranking_ventas', ['dimension' => 'inventada']),
        e2eText('No existe esa dimensión.'),
    ]));
    assertTrue(str_contains(implode("\n", $GLOBALS['e2eLog']), 'SIN-DATOS'));
});

test('el log recorta resultados largos: es para depurar, no para archivar', function () use ($e2eCtx) {
    $GLOBALS['e2eLog'] = [];
    AgentRunner::run('todo', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('ranking_ventas', ['dimension' => 'name', 'limite' => 25]),
        e2eText('Listo.'),
    ]));
    $paso = $GLOBALS['e2eLog'][0];
    assertTrue(str_contains($paso, 'recortado'), 'un resultado grande debe truncarse');
    assertTrue(strlen($paso) < 1500, 'y la línea no debe crecer sin límite');
});

test('SEGURIDAD: el log no contiene credenciales', function () use ($e2eCtx) {
    $GLOBALS['e2eLog'] = [];
    AgentRunner::run('x', $e2eCtx, 'sys', 400, e2eLlm([
        e2eCall('resumen_ventas', ['periodo' => 'este_mes']),
        e2eText('Listo.'),
    ]));
    $todo = mb_strtolower(implode("\n", $GLOBALS['e2eLog']));
    foreach (['api_key', 'apikey', 'password', 'passwd', 'secret', 'token', 'chatbot_ro', 'bearer'] as $fuga) {
        assertSame(false, str_contains($todo, $fuga), "el log filtró '{$fuga}'");
    }
});