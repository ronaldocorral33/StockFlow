<?php
/**
 * Pruebas de las herramientas analíticas, contra los datos REALES auditados.
 *
 * Las tres primeras son los casos exactos que fallaron en producción. Según la
 * auditoría, "jersey más vendida", "equipo más vendido" y "nombre más vendido" son la
 * MISMA pregunta para este negocio, así que deben dar la MISMA respuesta. Antes daban
 * tres distintas: 0 filas, "Sin equipo: 15" y la correcta.
 *
 * Las cifras concretas NO se fijan a mano: el dueño sigue vendiendo y un literal como
 * "America, 7" caduca al día siguiente. Se comparan contra un cálculo independiente.
 */

use App\Services\Agent\SchemaSemantics as SS;
use App\Services\Tools\Analytics;
use App\Services\Tools\ToolContext;

const AN_BIZ = 1;
$ctx = new ToolContext(AN_BIZ, 1, '2026-08-19');
SS::flush();

// ---------------------------------------------------------------
// CASOS 1, 2 y 3 del informe: la misma pregunta, una sola respuesta
// ---------------------------------------------------------------

test('CASO 1-3: el ranking por producto coincide con el cálculo directo', function () use ($ctx) {
    // LECCIÓN APRENDIDA: la primera versión de esta prueba afirmaba "America con 7".
    // Falló en cuanto el dueño registró tres ventas más — la prueba medía los DATOS,
    // no el CÓDIGO. Una prueba anclada a datos de producción caduca sola.
    //
    // Ahora se compara contra una consulta independiente que calcula lo mismo por otro
    // camino. Si la herramienta agrupa, cuenta u ordena mal, esto falla; si el dueño
    // vende otra pieza, sigue pasando.
    $esperado = App\Database::connection()->query(
        "SELECT name, COUNT(*) AS n
         FROM inventory_items
         WHERE business_id = 1 AND sale_date >= '2026-08-01' AND sale_date < '2026-09-01'
         GROUP BY name ORDER BY n DESC, name ASC LIMIT 1"
    )->fetch();

    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'name',
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => '2026-09-01',
    ]);

    assertTrue($r['encontrado']);
    assertSame('Producto', $r['label']);
    assertSame($esperado['name'], $r['top']['valor'], 'debe coincidir con el cálculo directo');
    assertSame((int)$esperado['n'], $r['top']['unidades'], 'y con el conteo real');
    assertTrue($r['top']['unidades'] > 0);
});

test('CASO 2: "equipo" ya NO puede resolverse a una clave JSON inventada', function () use ($ctx) {
    // Éste es el bug: el modelo inventó attributes.$.team y su COALESCE etiquetó el
    // hueco como "Sin equipo: 15 ventas". Ahora una dimensión inexistente se rechaza
    // ANTES de tocar la base, y el error le dice qué sí existe.
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'team',
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => '2026-09-01',
    ]);
    assertSame(false, $r['encontrado']);
    assertTrue(str_contains($r['motivo'], 'no existe'), 'debe decir que la dimensión no existe');
    assertTrue(str_contains($r['motivo'], 'No inventes'), 'y pedirle que no invente columnas');
    assertTrue(str_contains($r['motivo'], 'name'), 'y ofrecerle las válidas');
});

test('CASO 1: category (vacía) se rechaza en vez de devolver 0 filas sin explicación', function () use ($ctx) {
    // El modelo filtró por category='Jersey' y recibió "no encontré ventas", que suena
    // a que no vendiste nada. La causa real era otra: la dimensión está vacía.
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'category',
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => '2026-09-01',
    ]);
    assertSame(false, $r['encontrado']);
    assertTrue(str_contains($r['motivo'], 'no tiene datos'), 'debe explicar la CAUSA real');
    assertTrue(!empty($r['dimensiones_con_datos']), 'y sugerir alternativas');
});

test('NUNCA fabrica un grupo "Sin dato" como si fuera un valor de negocio', function () use ($ctx) {
    foreach (SS::dimensionKeys(AN_BIZ, true) as $k) {
        $r = Analytics::rankingVentas($ctx, ['dimension' => $k, 'periodo' => 'este_ano']);
        if (empty($r['encontrado'])) { continue; }
        foreach ($r['resultados'] as $fila) {
            assertTrue(trim((string)$fila['valor']) !== '', "la dimensión {$k} devolvió un valor vacío");
            foreach (['sin equipo', 'sin dato', 'desconocido', 'otro', 'n/a'] as $inventado) {
                assertSame(
                    false,
                    mb_strtolower(trim((string)$fila['valor'])) === $inventado,
                    "se fabricó la etiqueta '{$fila['valor']}' en la dimensión {$k}"
                );
            }
        }
    }
});

// ---------------------------------------------------------------
// CASOS 4 y 5: dimensiones dinámicas desde attribute_definitions
// ---------------------------------------------------------------

test('CASO 4-5: cualquier atributo dinámico del negocio sirve como dimensión', function () use ($ctx) {
    // La prueba no nombra "talla" ni "liga" a propósito: recorre lo que ESTE negocio
    // definió. Así sigue valiendo para una joyería que defina "material".
    $dinamicas = array_filter(
        SS::forBusiness(AN_BIZ)['dimensions'],
        fn($d) => $d['source'] === 'attributes' && $d['usable']
    );
    assertTrue(count($dinamicas) > 0, 'este negocio debe tener atributos con datos');

    foreach ($dinamicas as $d) {
        $r = Analytics::rankingVentas($ctx, ['dimension' => $d['key'], 'periodo' => 'este_ano']);
        assertTrue(
            isset($r['encontrado']),
            "la dimensión dinámica {$d['key']} debe devolver un resultado estructurado"
        );
        if ($r['encontrado']) {
            assertSame($d['label'], $r['label']);
            assertTrue($r['top']['unidades'] > 0);
        }
    }
});

// ---------------------------------------------------------------
// Filtros estructurados
// ---------------------------------------------------------------

test('filtra por un valor concreto y el total cuadra con el ranking', function () use ($ctx) {
    $sinFiltro = Analytics::rankingVentas($ctx, [
        'dimension' => 'name', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-09-01',
    ]);
    $conFiltro = Analytics::resumenVentas($ctx, [
        'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-09-01',
        'filtros' => [['campo' => 'name', 'operador' => 'eq', 'valor' => $sinFiltro['top']['valor']]],
    ]);
    assertTrue($conFiltro['encontrado']);
    assertSame($sinFiltro['top']['unidades'], $conFiltro['unidades_vendidas'],
        'el resumen filtrado por el producto top debe dar las mismas unidades que el ranking');
});

test('SEGURIDAD: un filtro sobre un campo inexistente se rechaza', function () use ($ctx) {
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'name',
        'filtros' => [['campo' => 'password', 'operador' => 'eq', 'valor' => 'x']],
    ]);
    assertSame(false, $r['encontrado']);
    assertTrue(str_contains($r['motivo'], 'No puedes filtrar'));
});

test('SEGURIDAD: un operador fuera de la allowlist se rechaza', function () use ($ctx) {
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'name',
        'filtros' => [['campo' => 'name', 'operador' => 'OR 1=1 --', 'valor' => 'x']],
    ]);
    assertSame(false, $r['encontrado']);
    assertTrue(str_contains($r['motivo'], 'no permitido'));
});

test('SEGURIDAD: el valor de un filtro va enlazado, no concatenado', function () use ($ctx) {
    // Si se concatenara, esto rompería el SQL o devolvería todo. Va como parámetro,
    // así que simplemente no encuentra nada con ese nombre literal.
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'name',
        'filtros' => [['campo' => 'name', 'operador' => 'eq', 'valor' => "' OR '1'='1"]],
    ]);
    assertSame(false, $r['encontrado'], 'no debe encontrar nada, ni reventar');
    assertTrue(str_contains($r['motivo'], 'No hay ventas'));
});

test('SEGURIDAD: un filtro con valor compuesto se rechaza', function () use ($ctx) {
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'name',
        'filtros' => [['campo' => 'name', 'operador' => 'eq', 'valor' => ['a', 'b']]],
    ]);
    assertSame(false, $r['encontrado']);
});

// ---------------------------------------------------------------
// CASOS 6, 7 y 8: periodos y comparación
// ---------------------------------------------------------------

test('CASO 6: producto más vendido en julio 2026', function () use ($ctx) {
    $r = Analytics::rankingVentas($ctx, [
        'dimension' => 'name', 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-08-01',
    ]);
    assertTrue($r['encontrado']);
    assertTrue($r['top']['unidades'] > 0);
    assertSame('2026-07-01', $r['periodo']['inicio']);
});

test('CASO 8: comparar julio contra agosto con variación calculada en PHP', function () use ($ctx) {
    $r = Analytics::compararPeriodos($ctx, [
        'fecha_inicio_a' => '2026-07-01', 'fecha_fin_a' => '2026-08-01',
        'fecha_inicio_b' => '2026-08-01', 'fecha_fin_b' => '2026-09-01',
    ]);
    assertTrue($r['encontrado']);
    assertTrue(isset($r['periodo_a']['unidades'], $r['periodo_b']['unidades']));
    // La variación absoluta debe ser exactamente la diferencia: nada de que el modelo
    // la calcule de cabeza.
    $esperada = $r['periodo_b']['unidades'] - $r['periodo_a']['unidades'];
    assertSame((float)$esperada, $r['variacion']['unidades']['absoluta']);
});

test('la variación porcentual es null cuando no hay base, no un +100% inventado', function () use ($ctx) {
    // Un periodo antiguo sin ventas: dividir entre cero no debe producir un número.
    $r = Analytics::compararPeriodos($ctx, [
        'fecha_inicio_a' => '2020-01-01', 'fecha_fin_a' => '2020-02-01',
        'fecha_inicio_b' => '2026-08-01', 'fecha_fin_b' => '2026-09-01',
    ]);
    assertSame(null, $r['variacion']['unidades']['pct']);
    assertTrue($r['variacion']['unidades']['nota'] !== null, 'debe explicar por qué no hay porcentaje');
});

// ---------------------------------------------------------------
// CASO 9: stock
// ---------------------------------------------------------------

test('CASO 9: resumen de stock', function () use ($ctx) {
    $r = Analytics::consultarStock($ctx, []);
    assertTrue($r['encontrado']);
    assertTrue($r['piezas_en_stock'] > 0);
});

test('CASO 9: productos agotados (vendidos y sin existencias)', function () use ($ctx) {
    $r = Analytics::sinStock($ctx, ['dimension' => 'name']);
    // Con estos datos puede haber o no agotados; lo que se exige es un resultado
    // estructurado y honesto, nunca una lista inventada.
    assertTrue(isset($r['encontrado']));
    if ($r['encontrado']) {
        foreach ($r['agotados'] as $a) {
            assertTrue(trim($a['valor']) !== '');
            assertTrue($a['vendidas_historico'] > 0);
        }
    } else {
        assertTrue(!empty($r['motivo']));
    }
});

test('el stock se puede agrupar por dimensión y ordenar ascendente', function () use ($ctx) {
    $r = Analytics::consultarStock($ctx, ['dimension' => 'name', 'orden' => 'asc', 'limite' => 5]);
    assertTrue($r['encontrado']);
    assertTrue(count($r['resultados']) <= 5);
    $piezas = array_column($r['resultados'], 'piezas');
    $ordenado = $piezas;
    sort($ordenado);
    assertSame($ordenado, $piezas, 'debe venir de menos a más');
});

// ---------------------------------------------------------------
// Contratos generales
// ---------------------------------------------------------------

test('el límite se acota: el modelo no puede pedir 10000 filas', function () use ($ctx) {
    $r = Analytics::rankingVentas($ctx, ['dimension' => 'name', 'limite' => 10000, 'periodo' => 'este_ano']);
    assertTrue(count($r['resultados']) <= Analytics::MAX_ROWS);
});

test('todo resultado es serializable a JSON (va de vuelta al modelo)', function () use ($ctx) {
    $llamadas = [
        fn() => Analytics::rankingVentas($ctx, ['dimension' => 'name', 'periodo' => 'este_mes']),
        fn() => Analytics::resumenVentas($ctx, ['periodo' => 'este_ano']),
        fn() => Analytics::consultarStock($ctx, ['dimension' => 'name']),
        fn() => Analytics::sinStock($ctx, []),
        fn() => Analytics::compararPeriodos($ctx, ['periodo_a' => 'mes_pasado', 'periodo_b' => 'este_mes']),
    ];
    foreach ($llamadas as $i => $fn) {
        $json = json_encode($fn(), JSON_UNESCAPED_UNICODE);
        assertTrue($json !== false, "la llamada {$i} no se pudo serializar");
        assertTrue(str_contains($json, 'encontrado'), 'toda respuesta declara si encontró datos');
    }
});

test('AISLAMIENTO: el negocio sale del contexto, nunca de los argumentos', function () {
    // Aunque el modelo mande business_id en los argumentos, se ignora.
    $ajeno = new ToolContext(906, 1, '2026-08-19');
    SS::flush(906);
    $r = Analytics::rankingVentas($ajeno, [
        'dimension' => 'name',
        'business_id' => 1,          // intento de fuga
        'periodo' => 'este_ano',
    ]);
    assertSame(false, $r['encontrado'], 'el negocio 906 no tiene ventas, pese al argumento');
});

test('un periodo relativo inválido se explica, no truena', function () use ($ctx) {
    $r = Analytics::rankingVentas($ctx, ['dimension' => 'name', 'periodo' => 'la_semana_de_hace_tres_meses']);
    assertSame(false, $r['encontrado']);
    assertTrue(str_contains($r['motivo'], 'no reconocido'));
});

// ---------------------------------------------------------------
// CASO 10 y modularidad
// ---------------------------------------------------------------

test('CASO 10: SOLO LECTURA por credencial — la escritura es imposible', function () {
    // No importa lo que el modelo intente: la conexión del agente tiene GRANT SELECT
    // y nada más. Esta prueba lo comprueba contra el motor, no contra una regex.
    $pdo = App\Database::chatbotReadOnly();
    $intentos = [
        'DELETE FROM inventory_items WHERE business_id = 1',
        'UPDATE inventory_items SET sale_price = 0 WHERE business_id = 1',
        'INSERT INTO inventory_items (business_id, user_id, name) VALUES (1, 1, "x")',
        'DROP TABLE inventory_items',
        'TRUNCATE TABLE inventory_items',
        'CREATE TABLE colado (id INT)',
        'ALTER TABLE inventory_items ADD COLUMN colada INT',
    ];
    foreach ($intentos as $sql) {
        $rechazado = false;
        try {
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            $rechazado = true;
        }
        assertTrue($rechazado, "LA BASE ACEPTÓ UNA ESCRITURA: {$sql}");
    }
});

test('MODULARIDAD: una dimensión nueva funciona sin función propia', function () {
    // Se configura "marca" en un negocio de prueba, con datos, y ranking_ventas la
    // agrupa correctamente. No existe marca_mas_vendida() en ninguna parte.
    $db = App\Database::connection();
    $limpiar = function () use ($db) {
        $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([907]);
        $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([907]);
        $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([907]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([807]);
    };
    $limpiar();

    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
       ->execute([807, 'Refaccionaria', 'refa@test.local', 'x']);
    $db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
       ->execute([907, 'Refaccionaria de prueba', 807]);
    $db->prepare('INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, sort_order) VALUES (?,?,?,?,?,?)')
       ->execute([907, 807, 'marca', 'Marca', 'text', 1]);

    $ins = $db->prepare(
        'INSERT INTO inventory_items (business_id, user_id, name, attributes, cost, sale_price, sale_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    // 3 Bosch y 1 Valeo, todas vendidas en agosto.
    foreach ([['Balata', 'Bosch'], ['Balata', 'Bosch'], ['Filtro', 'Bosch'], ['Filtro', 'Valeo']] as $i => [$n, $marca]) {
        $ins->execute([907, 807, $n, json_encode(['marca' => $marca]), 100, 250, '2026-08-1' . $i]);
    }

    App\Services\Agent\SchemaSemantics::flush(907);
    $ctxRefa = new ToolContext(907, 807, '2026-08-19');

    $r = Analytics::rankingVentas($ctxRefa, [
        'dimension' => 'marca',
        'fecha_inicio' => '2026-08-01',
        'fecha_fin' => '2026-09-01',
    ]);

    assertTrue($r['encontrado'], 'ranking_ventas debe funcionar con una dimensión recién configurada');
    assertSame('Marca', $r['label'], 'y usar la etiqueta que puso el negocio');
    assertSame('Bosch', $r['top']['valor']);
    assertSame(3, $r['top']['unidades']);

    $limpiar();
    App\Services\Agent\SchemaSemantics::flush(907);
});
