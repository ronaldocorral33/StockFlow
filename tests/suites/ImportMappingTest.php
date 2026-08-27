<?php
/**
 * Pruebas del mapeo explícito de importación y de la exportación selectiva.
 *
 * El mapeo automático adivinaba con una lista de alias y no lo decía. Cuando falló
 * —la columna "Pedido" no estaba en la lista— se perdió en cada importación durante
 * semanas. Estas pruebas cubren el camino explícito: propuesta, confirmación y
 * validación de lo que el cliente manda.
 */

use App\Models\AttributeDefinition as AD;
use App\Models\InventoryItem;
use App\Services\ImportExportService as IE;

const IM_BIZ = 900941;
const IM_USER = 900841;

$db = App\Database::connection();

$imLimpiar = function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([IM_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([IM_BIZ]);
    $db->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([IM_BIZ]);
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([IM_BIZ]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([IM_BIZ]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([IM_USER]);
};
$imLimpiar();

$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([IM_USER, 'Mapeo', 'mapeo@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([IM_BIZ, 'Negocio con mapeo', IM_USER]);
AD::seedCanonical(IM_BIZ, IM_USER);
$db->prepare('INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, storage, sort_order, visible_in_export) VALUES (?,?,?,?,?,?,?,1)')
   ->execute([IM_BIZ, IM_USER, 'material', 'Material', 'text', 'json', 1]);

// ---------------------------------------------------------------
// La propuesta de mapeo
// ---------------------------------------------------------------

test('propone por ETIQUETA del campo, que es la señal más confiable', function () {
    $m = IE::suggestMapping(IM_BIZ, ['Material', 'Producto', 'Costo']);
    assertSame('material', $m[0]['field_key']);
    assertSame('name', $m[1]['field_key']);
    assertSame('cost', $m[2]['field_key']);
    assertTrue(str_contains($m[0]['reason'], 'etiqueta'));
});

test('propone por CLAVE interna: reconoce un archivo exportado', function () {
    $m = IE::suggestMapping(IM_BIZ, ['sale_price', 'material']);
    assertSame('sale_price', $m[0]['field_key']);
    assertTrue(str_contains($m[0]['reason'], 'clave'));
});

test('conserva los encabezados HISTÓRICOS, ahora como tercera opción', function () {
    // Los archivos viejos del negocio deben seguir importándose sin trabajo manual.
    $m = IE::suggestMapping(IM_BIZ, ['Equipo', 'Costo (MXN)', 'Fecha de Venta']);
    assertSame('name', $m[0]['field_key']);
    assertSame('cost', $m[1]['field_key']);
    assertTrue(str_contains($m[0]['reason'], 'histórico'));
});

test('reconoce la columna ID como llave de sincronización', function () {
    $m = IE::suggestMapping(IM_BIZ, ['ID']);
    assertSame('id', $m[0]['field_key']);
});

test('una columna desconocida queda SIN asignar, no se adivina', function () {
    $m = IE::suggestMapping(IM_BIZ, ['Columna Rarísima']);
    assertSame(null, $m[0]['field_key']);
    assertTrue(str_contains($m[0]['reason'], 'sin coincidencia'));
});

test('dos columnas al mismo campo: la segunda queda sin asignar', function () {
    // Si ambas se asignaran, una pisaría a la otra en silencio y el usuario acabaría
    // con el dato equivocado sin saber por qué.
    $m = IE::suggestMapping(IM_BIZ, ['Material', 'material']);
    assertSame('material', $m[0]['field_key']);
    assertSame(null, $m[1]['field_key']);
    assertTrue(str_contains($m[1]['reason'], 'ya está asignada'));
});

// ---------------------------------------------------------------
// Importar CON el mapeo confirmado
// ---------------------------------------------------------------

test('importa usando el mapeo que el usuario confirmó', function () {
    // Los encabezados no se parecen a nada: sin mapeo explícito no habría forma.
    $filas = [
        ['Col A' => 'Anillo de oro', 'Col B' => 'Oro', 'Col C' => '1500'],
        ['Col A' => 'Collar de plata', 'Col B' => 'Plata', 'Col C' => '900'],
    ];
    $mapeo = ['Col A' => 'name', 'Col B' => 'material', 'Col C' => 'cost'];

    $r = IE::importRows(IM_BIZ, IM_USER, $filas, IE::MODE_ADD, false, $mapeo);
    assertSame(2, $r['inserted']);

    $piezas = InventoryItem::list(IM_BIZ);
    assertSame(2, count($piezas));
    $porNombre = [];
    foreach ($piezas as $p) { $porNombre[$p['name']] = $p; }
    assertSame('Oro', $porNombre['Anillo de oro']['attributes']['material']);
    assertSame('1500.00', (string)$porNombre['Anillo de oro']['cost']);
});

test('una columna marcada como ignorar NO se guarda', function () {
    $filas = [['Nombre' => 'Pieza ignorada', 'Basura' => 'no debe guardarse']];
    $mapeo = ['Nombre' => 'name', 'Basura' => '__ignorar__'];

    IE::importRows(IM_BIZ, IM_USER, $filas, IE::MODE_ADD, false, $mapeo);
    $p = null;
    foreach (InventoryItem::list(IM_BIZ) as $x) {
        if ($x['name'] === 'Pieza ignorada') { $p = $x; }
    }
    assertTrue($p !== null);
    $attrs = (array)$p['attributes'];
    foreach ($attrs as $v) {
        assertSame(false, str_contains((string)$v, 'no debe guardarse'));
    }
});

test('SEGURIDAD: un mapeo a un campo inexistente se ignora', function () {
    // El mapeo llega del navegador: una clave inventada no puede escribir una columna.
    $antes = count(InventoryItem::list(IM_BIZ));
    $filas = [['A' => 'Pieza segura', 'B' => 'x']];
    $mapeo = ['A' => 'name', 'B' => 'business_id'];   // intento de fuga

    IE::importRows(IM_BIZ, IM_USER, $filas, IE::MODE_ADD, false, $mapeo);
    assertSame($antes + 1, count(InventoryItem::list(IM_BIZ)));
    foreach (InventoryItem::list(IM_BIZ) as $p) {
        assertSame(IM_BIZ, (int)$p['business_id'], 'ninguna pieza cambió de negocio');
    }
});

test('sin identificador del producto, la fila se omite', function () {
    $r = IE::importRows(IM_BIZ, IM_USER,
        [['A' => '', 'B' => 'Oro']], IE::MODE_ADD, false, ['A' => 'name', 'B' => 'material']);
    assertSame(0, $r['inserted']);
    assertSame(1, $r['skipped']);
});

test('el ENSAYO con mapeo no escribe nada', function () {
    $antes = count(InventoryItem::list(IM_BIZ));
    $r = IE::importRows(IM_BIZ, IM_USER,
        [['A' => 'Fantasma', 'B' => 'Oro']], IE::MODE_ADD, true, ['A' => 'name', 'B' => 'material']);
    assertSame(1, $r['inserted'], 'el ensayo SÍ reporta lo que pasaría');
    assertTrue($r['dry_run']);
    assertSame($antes, count(InventoryItem::list(IM_BIZ)), 'pero no crea la pieza');
});

test('sin mapeo se conserva el camino automático', function () {
    // Compatibilidad: las importaciones que ya funcionaban deben seguir funcionando.
    $antes = count(InventoryItem::list(IM_BIZ));
    $r = IE::importRows(IM_BIZ, IM_USER,
        [['Nombre' => 'Por alias', 'Costo' => '77']], IE::MODE_ADD);
    assertSame(1, $r['inserted']);
    assertSame($antes + 1, count(InventoryItem::list(IM_BIZ)));
});

// ---------------------------------------------------------------
// Exportación selectiva
// ---------------------------------------------------------------

test('exporta las columnas configuradas, con las etiquetas del negocio', function () {
    $filas = IE::exportRows(IM_BIZ);
    assertTrue(count($filas) > 0);
    $cols = array_keys($filas[0]);
    assertSame('ID', $cols[0], 'el ID va primero: es la llave para reimportar');
    assertTrue(in_array('Material', $cols, true), 'incluye los campos personalizados');
    assertTrue(in_array('Producto', $cols, true), 'con la etiqueta del negocio, no el nombre técnico');
});

test('exporta SOLO la selección pedida, en el orden pedido', function () {
    $filas = IE::exportRows(IM_BIZ, ['material', 'name'], 'all', false);
    assertSame(['Material', 'Producto'], array_keys($filas[0]), 'respeta el orden de la selección');
});

test('una clave inventada en la selección se ignora', function () {
    $filas = IE::exportRows(IM_BIZ, ['name', 'password', 'business_id'], 'all', false);
    assertSame(['Producto'], array_keys($filas[0]));
});

test('el alcance filtra las piezas exportadas', function () {
    $todas = count(IE::exportRows(IM_BIZ, ['name']));
    $stock = count(IE::exportRows(IM_BIZ, ['name'], 'stock'));
    $vendidas = count(IE::exportRows(IM_BIZ, ['name'], 'sold'));
    assertSame($todas, $stock + $vendidas, 'todo lo que existe está en stock o vendido');
});

test('la etiqueta renombrada se refleja en la exportación', function () {
    // Es la prueba de que la exportación lee el registro y no una lista fija.
    $material = null;
    foreach (AD::listRegistry(IM_BIZ) as $f) {
        if ($f['field_key'] === 'material') { $material = $f; }
    }
    AD::configure((int)$material['id'], IM_BIZ, ['label' => 'Metal']);

    $filas = IE::exportRows(IM_BIZ, ['material'], 'all', false);
    assertSame(['Metal'], array_keys($filas[0]), 'la columna sale con la etiqueta nueva');

    AD::configure((int)$material['id'], IM_BIZ, ['label' => 'Material']);
});

test('AISLAMIENTO: la exportación solo trae piezas del negocio', function () {
    $filas = IE::exportRows(IM_BIZ, ['name'], 'all', true);
    $ids = array_column($filas, 'ID');
    $db = App\Database::connection();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT COUNT(*) FROM inventory_items WHERE id IN ($ph) AND business_id <> ?");
    $stmt->execute([...$ids, IM_BIZ]);
    assertSame(0, (int)$stmt->fetchColumn(), 'ninguna pieza de otro negocio');
});

$imLimpiar();
