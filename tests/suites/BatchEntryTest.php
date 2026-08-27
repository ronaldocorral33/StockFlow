<?php
/**
 * Escenario de aceptación: 20 playeras del Real Madrid en una sola captura.
 *
 * Comparten producto, temporada y versión; cambia la talla. El usuario escribe los
 * datos compartidos UNA vez y el sistema genera las 20 unidades.
 *
 * Se prueba el lado del servidor, que es donde vive la garantía: 20 inventory_items,
 * cada uno con su talla, el envío repartido, y todo dentro de una transacción.
 * El armado de los valores compartidos ocurre en el navegador y se reproduce aquí
 * igual que lo hace Entradas.construirItems().
 */

use App\Models\AttributeDefinition as AD;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;

const BE_BIZ = 921;
const BE_USER = 821;

$db = App\Database::connection();

$beLimpiar = function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([BE_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([BE_BIZ]);
    $db->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([BE_BIZ]);
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([BE_BIZ]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([BE_BIZ]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([BE_USER]);
};
$beLimpiar();

// --- Negocio de jerseys configurado como pide el escenario ---
$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([BE_USER, 'Lote 20', 'lote20@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([BE_BIZ, 'Jerseys de prueba', BE_USER]);
AD::seedCanonical(BE_BIZ, BE_USER);

$insAttr = $db->prepare(
    'INSERT INTO attribute_definitions
        (business_id, user_id, field_key, label, field_type, storage, options, sort_order,
         visible_in_entries, show_in_table, visible_in_sales, visible_in_export)
     VALUES (?,?,?,?,?,?,?,?,1,1,0,1)'
);
$insAttr->execute([BE_BIZ, BE_USER, 'talla', 'Talla', 'select', 'json', json_encode(['S','M','L','XL']), 1]);
$insAttr->execute([BE_BIZ, BE_USER, 'version', 'Versión', 'select', 'json', json_encode(['Aficionado','Jugador']), 2]);
$insAttr->execute([BE_BIZ, BE_USER, 'temporada', 'Temporada', 'text', 'json', null, 3]);
$insAttr->execute([BE_BIZ, BE_USER, 'color', 'Color', 'text', 'json', null, 4]);

/**
 * Reproduce lo que hace el navegador: combina los valores compartidos del lote con los
 * que cambian por unidad. Es la operación central del escenario.
 */
function beConstruir(array $compartidos, array $porUnidad, array $registro): array
{
    $porClave = [];
    foreach ($registro as $f) { $porClave[$f['field_key']] = $f; }

    $items = [];
    foreach ($porUnidad as $u) {
        $plano = [];
        $attrs = [];
        foreach (array_merge($compartidos, $u) as $clave => $valor) {
            if ($valor === '' || $valor === null) { continue; }
            $def = $porClave[$clave] ?? null;
            if ($def && $def['storage'] === AD::STORAGE_JSON) { $attrs[$clave] = $valor; }
            else { $plano[$clave] = $valor; }
        }
        $plano['attributes'] = $attrs;
        $items[] = $plano;
    }
    return $items;
}

// ---------------------------------------------------------------
// El escenario
// ---------------------------------------------------------------

test('ACEPTACIÓN: 20 playeras con datos compartidos y tallas distintas', function () {
    $registro = AD::listRegistry(BE_BIZ);

    // Lo que el usuario escribe UNA sola vez.
    $compartidos = [
        'name' => 'Real Madrid',
        'temporada' => '2026/27',
        'version' => 'Aficionado',
        'cost' => 450,
    ];
    // Lo único que cambia: la talla, como si viniera pegada de una columna de Excel.
    $tallas = ['S','S','M','M','M','M','M','L','L','L','L','L','L','XL','XL','XL','XL','XL','XL','XL'];
    $porUnidad = array_map(fn($t) => ['talla' => $t], $tallas);

    $items = beConstruir($compartidos, $porUnidad, $registro);
    assertSame(20, count($items));

    $r = PurchaseOrder::createWithItems(BE_BIZ, BE_USER, [
        'order_number' => 500,
        'supplier' => 'Proveedor Madrid',
        'purchase_date' => '2026-08-01',
        'arrival_date' => '2026-08-15',
        'currency' => 'MXN',
        'exchange_rate' => 1,
        'shipping_total' => 1000,
    ], $items);

    // 1. Exactamente 20 piezas físicas.
    assertSame(20, count($r['item_ids']), 'una fila de inventario por pieza física');
    $piezas = InventoryItem::list(BE_BIZ);
    assertSame(20, count($piezas));

    // 2. Los datos compartidos llegaron a todas.
    foreach ($piezas as $p) {
        assertSame('Real Madrid', $p['name']);
        assertSame('2026/27', $p['attributes']['temporada']);
        assertSame('Aficionado', $p['attributes']['version']);
        assertSame('450.00', (string)$p['cost']);
    }

    // 3. Cada unidad conservó SU talla, con la misma distribución que se capturó.
    $cuenta = [];
    foreach ($piezas as $p) {
        $t = $p['attributes']['talla'];
        $cuenta[$t] = ($cuenta[$t] ?? 0) + 1;
    }
    ksort($cuenta);
    assertSame(['L' => 6, 'M' => 5, 'S' => 2, 'XL' => 7], $cuenta);

    // 4. El envío se repartió: 1000 / 20 = 50 por pieza.
    foreach ($piezas as $p) {
        assertSame('50.00', (string)$p['shipping_cost']);
    }
    $sumaEnvio = array_sum(array_map(fn($p) => (float)$p['shipping_cost'], $piezas));
    assertSame(1000.0, $sumaEnvio, 'la suma del envío repartido debe dar el envío del pedido');

    // 5. El costo total por pieza incluye su parte del envío (columna generada).
    assertSame('500.00', (string)$piezas[0]['total_cost']);
});

test('el pedido queda registrado con su número y su conteo', function () {
    $db = App\Database::connection();
    $po = $db->query('SELECT order_number, item_count FROM purchase_orders WHERE business_id = ' . BE_BIZ)->fetch();
    assertSame(500, (int)$po['order_number']);
    assertSame(20, (int)$po['item_count']);
});

test('el usuario escribió el producto UNA vez y quedó en las 20', function () {
    // Es la promesa del escenario, comprobada como dato: un solo valor distinto de
    // producto entre las 20 piezas.
    $db = App\Database::connection();
    $n = (int)$db->query(
        'SELECT COUNT(DISTINCT name) FROM inventory_items WHERE business_id = ' . BE_BIZ
    )->fetchColumn();
    assertSame(1, $n);
});

// ---------------------------------------------------------------
// Reparto del envío en casos que no dividen exacto
// ---------------------------------------------------------------

test('el envío se reparte sin perder ni inventar pesos', function () {
    $db = App\Database::connection();
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([BE_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([BE_BIZ]);

    // 1000 entre 3 no da exacto: es donde un reparto ingenuo pierde centavos.
    $items = array_map(fn($i) => ['name' => 'Pieza ' . $i, 'cost' => 100, 'attributes' => []], [1, 2, 3]);
    PurchaseOrder::createWithItems(BE_BIZ, BE_USER, [
        'order_number' => 501, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 1000,
    ], $items);

    $piezas = InventoryItem::list(BE_BIZ);
    $suma = array_sum(array_map(fn($p) => (float)$p['shipping_cost'], $piezas));
    assertSame(1000.0, $suma, 'la suma repartida debe ser EXACTAMENTE el envío del pedido');
});

// ---------------------------------------------------------------
// Transacción: un lote a medias sería peor que ninguno
// ---------------------------------------------------------------

test('si una unidad falla, NO se guarda medio pedido', function () {
    $db = App\Database::connection();
    $antesItems = (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . BE_BIZ)->fetchColumn();
    $antesPedidos = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . BE_BIZ)->fetchColumn();

    $fallo = false;
    try {
        // Ninguna unidad con nombre: createWithItems debe abortar el lote completo.
        PurchaseOrder::createWithItems(BE_BIZ, BE_USER, [
            'order_number' => 502, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 100,
        ], [['name' => '', 'cost' => 10], ['name' => '  ', 'cost' => 10]]);
    } catch (\Throwable $e) {
        $fallo = true;
    }

    assertTrue($fallo, 'debe fallar en vez de guardar un pedido vacío');
    assertSame($antesItems, (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . BE_BIZ)->fetchColumn(),
        'no debió quedar ninguna pieza');
    assertSame($antesPedidos, (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . BE_BIZ)->fetchColumn(),
        'ni el encabezado del pedido');
});

// ---------------------------------------------------------------
// Pegado tabular: la lógica que reparte el texto en unidades
// ---------------------------------------------------------------

/** Reproduce Entradas.procesarPegado() para poder probar la regla sin navegador. */
function bePegar(string $texto, array $columnas): array
{
    $lineas = array_values(array_filter(
        preg_split('/\r\n|\r|\n/', $texto),
        fn($l) => trim($l) !== ''
    ));
    if (!$lineas) { return []; }

    $filas = array_map(fn($l) => array_map('trim', explode("\t", $l)), $lineas);

    $primera = array_map('mb_strtolower', $filas[0]);
    $mapa = array_map(function ($h) use ($columnas) {
        foreach ($columnas as $c) {
            if (mb_strtolower($c['label']) === $h || mb_strtolower($c['field_key']) === $h) { return $c; }
        }
        return null;
    }, $primera);
    $hayEncabezado = count(array_filter($mapa)) >= max(1, (int)ceil(count($primera) / 2));

    $cuerpo = $hayEncabezado ? array_slice($filas, 1) : $filas;
    $destino = $hayEncabezado ? $mapa : $columnas;

    $out = [];
    foreach ($cuerpo as $celdas) {
        $valores = [];
        foreach ($celdas as $k => $valor) {
            if (!empty($destino[$k])) { $valores[$destino[$k]['field_key']] = $valor; }
        }
        $out[] = $valores;
    }
    return $out;
}

test('pegado tabular: reconoce encabezados y arma una unidad por fila', function () {
    $columnas = [
        ['field_key' => 'talla', 'label' => 'Talla'],
        ['field_key' => 'sale_price', 'label' => 'Precio'],
    ];
    $texto = "Talla\tPrecio\nS\t899\nM\t899\nM\t899\nL\t949\nXL\t949";

    $filas = bePegar($texto, $columnas);
    assertSame(5, count($filas), 'cinco unidades, sin contar el encabezado');
    assertSame('S', $filas[0]['talla']);
    assertSame('899', $filas[0]['sale_price']);
    assertSame('XL', $filas[4]['talla']);
    assertSame('949', $filas[4]['sale_price']);
});

test('pegado tabular sin encabezados: asigna por posición', function () {
    $columnas = [
        ['field_key' => 'talla', 'label' => 'Talla'],
        ['field_key' => 'sale_price', 'label' => 'Precio'],
    ];
    $filas = bePegar("S\t899\nM\t950", $columnas);
    assertSame(2, count($filas), 'ninguna fila se toma como encabezado');
    assertSame('S', $filas[0]['talla']);
    assertSame('950', $filas[1]['sale_price']);
});

test('pegado tabular: ignora líneas en blanco y saltos de Windows', function () {
    $columnas = [['field_key' => 'talla', 'label' => 'Talla']];
    $filas = bePegar("S\r\n\r\nM\r\nL\r\n", $columnas);
    assertSame(3, count($filas));
});

test('pegado tabular: una columna de más se ignora sin romper', function () {
    $columnas = [['field_key' => 'talla', 'label' => 'Talla']];
    $filas = bePegar("S\tsobra\tmás\nM\tsobra\tmás", $columnas);
    assertSame(2, count($filas));
    assertSame('S', $filas[0]['talla']);
    assertSame(1, count($filas[0]), 'solo el campo que existe');
});

// ---------------------------------------------------------------
// Aislamiento
// ---------------------------------------------------------------

test('AISLAMIENTO: el lote se guarda solo en su negocio', function () {
    $db = App\Database::connection();
    $ajenas = (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = 1')->fetchColumn();
    PurchaseOrder::createWithItems(BE_BIZ, BE_USER, [
        'order_number' => 503, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 10,
    ], [['name' => 'Aislada', 'cost' => 1, 'attributes' => []]]);
    assertSame($ajenas, (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = 1')->fetchColumn(),
        'el negocio 1 no debió cambiar');
});

$beLimpiar();
