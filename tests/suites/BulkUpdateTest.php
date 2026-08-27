<?php
/**
 * Pruebas de la edición en lote.
 *
 * El riesgo de esta función no es que no funcione: es que funcione DE MÁS. Cambiar la
 * versión de 116 piezas y borrarles el costo sin avisar sería mucho peor que no tener
 * la función. Por eso la mayoría de estas pruebas verifican lo que NO debe pasar.
 *
 * Trabajan sobre un negocio de pruebas propio, con datos creados y borrados aquí.
 */

use App\Models\AttributeDefinition as AD;
use App\Models\InventoryItem;

const BU_BIZ = 900911;
const BU_USER = 900811;

$db = App\Database::connection();

$limpiar = function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([BU_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([BU_BIZ]);
    $db->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([BU_BIZ]);
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([BU_BIZ]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([BU_BIZ]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([BU_USER]);
};
$limpiar();

$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([BU_USER, 'Lote', 'lote@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([BU_BIZ, 'Negocio de pruebas de lote', BU_USER]);
AD::seedCanonical(BU_BIZ, BU_USER);
// Dos atributos propios: uno de texto y uno de lista.
$db->prepare('INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, storage, sort_order) VALUES (?,?,?,?,?,?,?)')
   ->execute([BU_BIZ, BU_USER, 'variante_x', 'Variante X', 'text', 'json', 1]);
$db->prepare('INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, storage, sort_order) VALUES (?,?,?,?,?,?,?)')
   ->execute([BU_BIZ, BU_USER, 'medida', 'Medida', 'text', 'json', 2]);

/** Crea N piezas con atributos iniciales y devuelve sus ids. */
function buCrear(int $n, array $attrs = ['medida' => 'M']): array
{
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $ids[] = InventoryItem::create(BU_BIZ, BU_USER, [
            'name' => 'Pieza ' . $i,
            'cost' => 100 + $i,
            'shipping_cost' => 10,
            'sale_price' => 250,
            'purchase_date' => '2026-05-01',
            'attributes' => $attrs,
        ]);
    }
    return $ids;
}

function buLeer(int $id): array
{
    return InventoryItem::find($id, BU_BIZ);
}

// ---------------------------------------------------------------
// El caso que lo motivó
// ---------------------------------------------------------------

test('EL CASO REAL: asigna un atributo a todas las seleccionadas de un golpe', function () {
    // "Me llegó el pedido 42 y no puse si eran de visita o local."
    $ids = buCrear(5);
    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['variante_x' => 'Visita']);

    assertSame(5, $r['updated']);
    assertSame(['Variante X'], $r['fields']);
    foreach ($ids as $id) {
        assertSame('Visita', buLeer($id)['attributes']['variante_x']);
    }
});

test('CLAVE: asignar un atributo NO borra los demás atributos', function () {
    // Si se escribiera el JSON completo en vez de usar JSON_SET, poner la versión
    // habría borrado la talla de las 116 piezas en silencio.
    $ids = buCrear(3, ['medida' => 'XL', 'otro' => 'conservar']);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['variante_x' => 'Local']);

    foreach ($ids as $id) {
        $a = buLeer($id)['attributes'];
        assertSame('Local', $a['variante_x'], 'el nuevo debe estar');
        assertSame('XL', $a['medida'], 'y el anterior NO debe haberse borrado');
        assertSame('conservar', $a['otro']);
    }
});

test('CLAVE: un campo NO marcado se queda exactamente como estaba', function () {
    // La otra mitad del mismo riesgo: editar un campo no debe tocar los demás.
    $ids = buCrear(3);
    $antes = array_map(fn($id) => buLeer($id), $ids);

    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['variante_x' => 'Alternativa']);

    foreach ($ids as $i => $id) {
        $d = buLeer($id);
        assertSame($antes[$i]['cost'], $d['cost'], 'el costo no debía cambiar');
        assertSame($antes[$i]['sale_price'], $d['sale_price'], 'ni el precio');
        assertSame($antes[$i]['name'], $d['name'], 'ni el nombre');
        assertSame($antes[$i]['purchase_date'], $d['purchase_date'], 'ni la fecha');
    }
});

test('funciona igual con una pieza que NO tenía atributos', function () {
    // JSON_SET(NULL, ...) devuelve NULL: sin el COALESCE, estas piezas se habrían
    // quedado sin el dato nuevo y nadie lo habría notado.
    $id = InventoryItem::create(BU_BIZ, BU_USER, ['name' => 'Sin atributos', 'cost' => 50]);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, [$id], ['variante_x' => 'Local']);
    assertSame('Local', buLeer($id)['attributes']['variante_x']);
});

// ---------------------------------------------------------------
// Campos canónicos
// ---------------------------------------------------------------

test('edita campos canónicos y convierte los numéricos', function () {
    $ids = buCrear(3);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['cost' => '1,250.50', 'category' => 'Ropa']);
    foreach ($ids as $id) {
        $d = buLeer($id);
        assertSame('1250.50', (string)$d['cost'], 'la columna es DECIMAL(12,2): se conservan los centavos');
        assertSame('Ropa', $d['category']);
    }
});

test('el proveedor se resuelve por nombre, no por id', function () {
    $ids = buCrear(2);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['supplier' => 'Proveedor Nuevo']);
    foreach ($ids as $id) {
        assertSame('Proveedor Nuevo', buLeer($id)['supplier_name']);
    }
});

test('mover piezas a un pedido recalcula su contador', function () {
    $ids = buCrear(4);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['order_number' => '77']);

    $db = App\Database::connection();
    $po = $db->query('SELECT id, order_number, item_count FROM purchase_orders WHERE business_id = ' . BU_BIZ)->fetch();
    assertSame(77, (int)$po['order_number']);
    assertSame(4, (int)$po['item_count'], 'item_count es denormalizado: si no se recalcula, miente');
});

test('un valor vacío VACÍA el campo (a diferencia de no marcarlo)', function () {
    $ids = buCrear(2, ['medida' => 'L']);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['category' => 'Temporal']);
    assertSame('Temporal', buLeer($ids[0])['category']);

    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['category' => '']);
    assertSame(null, buLeer($ids[0])['category'], 'vaciar es una intención válida y explícita');
});

test('vaciar un atributo QUITA la clave del JSON', function () {
    $ids = buCrear(2, ['medida' => 'L', 'variante_x' => 'Local']);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['variante_x' => '']);
    $a = buLeer($ids[0])['attributes'];
    assertSame(false, isset($a['variante_x']), 'no debe quedar como cadena vacía');
    assertSame('L', $a['medida'], 'y los demás siguen ahí');
});

// ---------------------------------------------------------------
// Lo que NO se puede escribir
// ---------------------------------------------------------------

test('SEGURIDAD: un campo fuera del registro se ignora', function () {
    $ids = buCrear(2);
    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, [
        'business_id' => 1,          // intento de fuga entre negocios
        'user_id' => 999,
        'id' => 5,
        'campo_inventado' => 'x',
    ]);
    assertSame(0, $r['updated'], 'ningún campo válido = ninguna escritura');
    assertSame(BU_BIZ, (int)buLeer($ids[0])['business_id'], 'el negocio no se pudo cambiar');
});

test('SEGURIDAD: las columnas generadas no se pueden escribir', function () {
    $ids = buCrear(2);
    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['total_cost' => 1, 'profit' => 1]);
    assertSame(0, $r['updated'], 'total_cost y profit los calcula la base');
});

test('SEGURIDAD: sale_date no se puede asignar en lote', function () {
    // Marcaría piezas como vendidas sin precio, corrompiendo el estado que separa
    // stock de vendido. Para eso existe la venta en lote, que sí pide precio.
    $ids = buCrear(2);
    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['sale_date' => '2026-08-01']);
    assertSame(0, $r['updated']);
    assertSame(null, buLeer($ids[0])['sale_date']);
});

test('AISLAMIENTO: no toca piezas de otro negocio', function () {
    $ids = buCrear(2);
    // Ids del negocio real (1) mezclados con los propios.
    $ajenos = App\Database::connection()
        ->query('SELECT id FROM inventory_items WHERE business_id = 1 LIMIT 3')
        ->fetchAll(PDO::FETCH_COLUMN);

    $antes = [];
    foreach ($ajenos as $a) {
        $antes[$a] = App\Database::connection()
            ->query('SELECT category FROM inventory_items WHERE id = ' . (int)$a)->fetchColumn();
    }

    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, [...$ids, ...$ajenos], ['category' => 'INVASOR']);
    assertSame(2, $r['updated'], 'solo las 2 piezas propias');

    foreach ($antes as $id => $cat) {
        $ahora = App\Database::connection()
            ->query('SELECT category FROM inventory_items WHERE id = ' . (int)$id)->fetchColumn();
        assertSame($cat, $ahora, "la pieza {$id} de otro negocio NO debió cambiar");
    }
});

// ---------------------------------------------------------------
// Bordes
// ---------------------------------------------------------------

test('sin ids o sin campos no hace nada', function () {
    assertSame(0, InventoryItem::bulkUpdate(BU_BIZ, BU_USER, [], ['category' => 'x'])['updated']);
    assertSame(0, InventoryItem::bulkUpdate(BU_BIZ, BU_USER, [1, 2], [])['updated']);
});

test('ids repetidos o basura no rompen la consulta', function () {
    $ids = buCrear(2);
    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, [$ids[0], $ids[0], 0, -5, 'abc', $ids[1]], ['category' => 'Limpio']);
    assertSame(2, $r['updated'], 'se deduplican y se descarta la basura');
});

test('editableFields sale del registro, no de una lista fija', function () {
    // Es lo que hace que esto funcione para cualquier negocio: los dos atributos que
    // este negocio de prueba definió aparecen sin que nadie escriba código.
    $claves = array_column(AD::editableFields(BU_BIZ), 'field_key');
    assertTrue(in_array('variante_x', $claves, true));
    assertTrue(in_array('medida', $claves, true));
    assertSame(false, in_array('total_cost', $claves, true));
    assertSame(false, in_array('profit', $claves, true));
    assertSame(false, in_array('sale_date', $claves, true));
});

test('varios campos a la vez, en una sola operación', function () {
    $ids = buCrear(3, ['medida' => 'S']);
    $r = InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, [
        'variante_x' => 'Local',
        'category' => 'Jerseys',
        'shipping_cost' => '30',
    ]);
    assertSame(3, $r['updated']);
    assertSame(3, count($r['fields']));
    $d = buLeer($ids[0]);
    assertSame('Local', $d['attributes']['variante_x']);
    assertSame('S', $d['attributes']['medida'], 'el atributo previo sobrevive');
    assertSame('Jerseys', $d['category']);
    assertSame('30.00', (string)$d['shipping_cost']);
});

test('acepta números escritos por una persona (con coma de miles)', function () {
    $ids = buCrear(2);
    InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['cost' => '1,250.50']);
    assertSame('1250.50', (string)buLeer($ids[0])['cost']);
});

test('CLAVE: un número inválido FALLA en voz alta y no borra nada', function () {
    // Antes, un valor no numérico se convertía en NULL: habría vaciado el costo de
    // todas las piezas seleccionadas sin un solo aviso. Ahora se rechaza y nada cambia.
    $ids = buCrear(3);
    // Cada pieza tiene un costo distinto, así que se guarda el de CADA una: comparar
    // las tres contra un solo valor era un error de la prueba, no del código.
    $antes = [];
    foreach ($ids as $id) { $antes[$id] = buLeer($id)['cost']; }

    $mensaje = null;
    try {
        InventoryItem::bulkUpdate(BU_BIZ, BU_USER, $ids, ['cost' => 'como cien pesos']);
    } catch (\InvalidArgumentException $e) {
        $mensaje = $e->getMessage();
    }

    assertTrue($mensaje !== null, 'debe rechazar el valor, no aceptarlo como NULL');
    assertTrue(str_contains($mensaje, 'Costo'), 'y nombrar el campo para poder corregirlo');
    foreach ($ids as $id) {
        assertSame($antes[$id], buLeer($id)['cost'], 'ninguna pieza debió cambiar');
    }
});
$limpiar();
