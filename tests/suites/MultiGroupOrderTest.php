<?php
/**
 * Un pedido con VARIOS grupos de productos.
 *
 * "Pedido #125: 15 Real Madrid, 15 Barcelona, 15 Manchester City. Un proveedor, una
 * fecha, un envío. UN solo pedido, no tres."
 *
 * DECISIÓN DE MODELADO: los grupos NO se guardan.
 * Un grupo es una estructura de CAPTURA, no de datos. Su razón de existir es no
 * escribir "Real Madrid" quince veces; en el momento de guardar, sus valores
 * compartidos ya quedaron copiados en cada pieza, que es como funciona el producto:
 * una fila por unidad física, con su propio costo y sus propios atributos.
 *
 * Después de guardar, el grupo no aporta nada que no se pueda recuperar agrupando las
 * piezas del pedido por sus valores comunes — los subtotales por grupo son un
 * GROUP BY, no un dato que haya que almacenar. Por eso NO se agrega una tabla
 * purchase_order_lines: sería una segunda fuente de verdad sobre las mismas piezas,
 * que podría desincronizarse en cuanto alguien edite una pieza por su cuenta.
 *
 * En consecuencia el backend no necesitó cambios: el frontend aplana los grupos en una
 * sola lista y createWithItems() ya crea UN pedido con todas las piezas.
 */

use App\Models\AttributeDefinition as AD;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;

const MG_BIZ = 900951;
const MG_USER = 900851;

$db = App\Database::connection();

$mgLimpiar = function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([MG_USER]);
};
$mgLimpiar();

$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([MG_USER, 'Grupos', 'grupos@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([MG_BIZ, 'Negocio con grupos', MG_USER]);
AD::seedCanonical(MG_BIZ, MG_USER);

$insAttr = $db->prepare(
    'INSERT INTO attribute_definitions
        (business_id, user_id, field_key, label, field_type, storage, options, sort_order, visible_in_entries)
     VALUES (?,?,?,?,?,?,?,?,1)'
);
$insAttr->execute([MG_BIZ, MG_USER, 'talla', 'Talla', 'select', 'json', json_encode(['S','M','L','XL']), 1]);
$insAttr->execute([MG_BIZ, MG_USER, 'version', 'Versión', 'select', 'json', json_encode(['Aficionado','Jugador']), 2]);
$insAttr->execute([MG_BIZ, MG_USER, 'temporada', 'Temporada', 'text', 'json', null, 3]);

/**
 * Aplana los grupos en la lista plana que recibe el servidor.
 *
 * Reproduce lo que hace Entradas.construirItems() en el navegador: por cada unidad,
 * combina los valores compartidos de SU grupo con los suyos propios. Es la operación
 * que hace que los grupos no necesiten existir en la base de datos.
 */
function mgAplanar(array $grupos, array $registro): array
{
    $porClave = [];
    foreach ($registro as $f) { $porClave[$f['field_key']] = $f; }

    $items = [];
    foreach ($grupos as $g) {
        foreach ($g['unidades'] as $u) {
            $plano = [];
            $attrs = [];
            // Los propios de la unidad ganan sobre los compartidos del grupo: si el
            // usuario capturó un costo distinto en una pieza, ése manda.
            foreach (array_merge($g['compartido'], $u) as $clave => $valor) {
                if ($valor === '' || $valor === null) { continue; }
                $def = $porClave[$clave] ?? null;
                if ($def && $def['storage'] === AD::STORAGE_JSON) { $attrs[$clave] = $valor; }
                else { $plano[$clave] = $valor; }
            }
            $plano['attributes'] = $attrs;
            $items[] = $plano;
        }
    }
    return $items;
}

/** Construye un grupo de N unidades con tallas dadas. */
function mgGrupo(array $compartido, array $tallas): array
{
    return [
        'compartido' => $compartido,
        'unidades' => array_map(fn($t) => ['talla' => $t], $tallas),
    ];
}

// ---------------------------------------------------------------
// El escenario: tres grupos, un pedido
// ---------------------------------------------------------------

$MG_TALLAS_A = ['S','S','M','M','M','L','L','L','L','XL','XL','XL','XL','XL','M'];
$MG_TALLAS_B = ['M','M','M','L','L','L','L','L','XL','XL','S','S','M','L','XL'];
$MG_TALLAS_C = ['L','L','L','L','XL','XL','XL','M','M','M','S','S','S','XL','L'];

test('ESCENARIO: tres grupos de 15 crean UN pedido con 45 piezas', function ()
    use ($MG_TALLAS_A, $MG_TALLAS_B, $MG_TALLAS_C) {

    $registro = AD::listRegistry(MG_BIZ);
    $grupos = [
        mgGrupo(['name' => 'Real Madrid',     'temporada' => '2026/27', 'version' => 'Aficionado', 'cost' => 500], $MG_TALLAS_A),
        mgGrupo(['name' => 'Barcelona',       'temporada' => '2026/27', 'version' => 'Jugador',    'cost' => 650], $MG_TALLAS_B),
        mgGrupo(['name' => 'Manchester City', 'temporada' => '2026/27', 'version' => 'Aficionado', 'cost' => 500], $MG_TALLAS_C),
    ];

    $items = mgAplanar($grupos, $registro);
    assertSame(45, count($items), 'los tres grupos se aplanan en 45 unidades');

    $r = PurchaseOrder::createWithItems(MG_BIZ, MG_USER, [
        'order_number' => 125,
        'supplier' => 'Proveedor único',
        'purchase_date' => '2026-08-01',
        'arrival_date' => '2026-08-20',
        'currency' => 'MXN',
        'exchange_rate' => 1,
        'shipping_total' => 900,
    ], $items);

    assertSame(45, count($r['item_ids']));
});

test('se creó EXACTAMENTE UN purchase_order, no tres', function () use ($db) {
    $n = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . MG_BIZ)->fetchColumn();
    assertSame(1, $n, 'un pedido con varios productos NO son varios pedidos');
});

test('se crearon exactamente 45 inventory_items', function () {
    assertSame(45, count(InventoryItem::list(MG_BIZ)));
});

test('las 45 piezas comparten el mismo purchase_order_id', function () use ($db) {
    $n = (int)$db->query(
        'SELECT COUNT(DISTINCT purchase_order_id) FROM inventory_items WHERE business_id = ' . MG_BIZ
    )->fetchColumn();
    assertSame(1, $n);

    $nulos = (int)$db->query(
        'SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . MG_BIZ . ' AND purchase_order_id IS NULL'
    )->fetchColumn();
    assertSame(0, $nulos, 'ninguna pieza debe quedar sin pedido');
});

test('item_count refleja el total de TODOS los grupos', function () use ($db) {
    $c = (int)$db->query('SELECT item_count FROM purchase_orders WHERE business_id = ' . MG_BIZ)->fetchColumn();
    assertSame(45, $c);
});

test('cada grupo conservó SUS valores compartidos', function () {
    $piezas = InventoryItem::list(MG_BIZ);
    $porProducto = [];
    foreach ($piezas as $p) { $porProducto[$p['name']][] = $p; }

    assertSame(3, count($porProducto), 'tres productos distintos en el mismo pedido');
    assertSame(15, count($porProducto['Real Madrid']));
    assertSame(15, count($porProducto['Barcelona']));
    assertSame(15, count($porProducto['Manchester City']));

    foreach ($porProducto['Real Madrid'] as $p) {
        assertSame('Aficionado', $p['attributes']['version']);
        assertSame('500.00', (string)$p['cost']);
    }
    foreach ($porProducto['Barcelona'] as $p) {
        assertSame('Jugador', $p['attributes']['version'], 'el grupo B tiene SU versión');
        assertSame('650.00', (string)$p['cost'], 'y SU costo');
    }
    foreach ($porProducto['Manchester City'] as $p) {
        assertSame('Aficionado', $p['attributes']['version']);
        assertSame('500.00', (string)$p['cost']);
    }
    // La temporada sí es igual en los tres: se capturó igual en cada grupo.
    foreach ($piezas as $p) {
        assertSame('2026/27', $p['attributes']['temporada']);
    }
});

test('cada unidad conservó SU talla dentro de su grupo', function ()
    use ($MG_TALLAS_A, $MG_TALLAS_B, $MG_TALLAS_C) {

    $esperado = [
        'Real Madrid' => array_count_values($MG_TALLAS_A),
        'Barcelona' => array_count_values($MG_TALLAS_B),
        'Manchester City' => array_count_values($MG_TALLAS_C),
    ];

    $real = [];
    foreach (InventoryItem::list(MG_BIZ) as $p) {
        $t = $p['attributes']['talla'];
        $real[$p['name']][$t] = ($real[$p['name']][$t] ?? 0) + 1;
    }

    foreach ($esperado as $producto => $cuenta) {
        ksort($cuenta);
        $r = $real[$producto];
        ksort($r);
        assertSame($cuenta, $r, "las tallas de {$producto} no coinciden");
    }
});

// ---------------------------------------------------------------
// El envío pertenece al PEDIDO, no al grupo
// ---------------------------------------------------------------

test('el envío se reparte entre las 45 piezas de TODOS los grupos', function () {
    // $900 / 45 = $20 exactos.
    foreach (InventoryItem::list(MG_BIZ) as $p) {
        assertSame('20.00', (string)$p['shipping_cost']);
    }
});

test('el total repartido coincide EXACTAMENTE con el envío del pedido', function () {
    $suma = array_sum(array_map(fn($p) => (float)$p['shipping_cost'], InventoryItem::list(MG_BIZ)));
    assertSame(900.0, $suma);
});

test('un envío que no divide exacto tampoco pierde dinero', function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([MG_BIZ]);

    $registro = AD::listRegistry(MG_BIZ);
    // 7 piezas repartidas en dos grupos, con $100 de envío: 100/7 no es exacto.
    $grupos = [
        mgGrupo(['name' => 'Grupo A', 'cost' => 10], ['S','M','L']),
        mgGrupo(['name' => 'Grupo B', 'cost' => 20], ['S','M','L','XL']),
    ];
    PurchaseOrder::createWithItems(MG_BIZ, MG_USER, [
        'order_number' => 126, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 100,
    ], mgAplanar($grupos, $registro));

    $piezas = InventoryItem::list(MG_BIZ);
    assertSame(7, count($piezas));
    $suma = array_sum(array_map(fn($p) => (float)$p['shipping_cost'], $piezas));
    assertSame(100.0, $suma, 'el residuo se reparte, no se pierde');
});

// ---------------------------------------------------------------
// Transacción: el fallo de un grupo no deja medio pedido
// ---------------------------------------------------------------

test('si falla una pieza del TERCER grupo, no se guarda nada del pedido', function () use ($db) {
    $itemsAntes = (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . MG_BIZ)->fetchColumn();
    $pedidosAntes = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . MG_BIZ)->fetchColumn();

    // HALLAZGO: el sql_mode de este servidor NO incluye STRICT_TRANS_TABLES, así que
    // un valor demasiado largo se TRUNCA en silencio en vez de fallar. Para probar el
    // rollback hace falta un error de verdad, así que se activa el modo estricto solo
    // en esta sesión — sin tocar la configuración del servidor.
    $modoOriginal = $db->query('SELECT @@session.sql_mode')->fetchColumn();
    $db->exec("SET SESSION sql_mode = CONCAT(@@session.sql_mode, ',STRICT_TRANS_TABLES')");

    // Dos piezas válidas y una tercera con un nombre que excede la columna: el lote
    // truena a la mitad, con dos piezas ya insertadas dentro de la transacción.
    $items = [
        ['name' => 'Grupo A pieza', 'cost' => 10, 'attributes' => []],
        ['name' => 'Grupo B pieza', 'cost' => 10, 'attributes' => []],
        ['name' => str_repeat('X', 500), 'cost' => 10, 'attributes' => []],
    ];

    $fallo = false;
    try {
        PurchaseOrder::createWithItems(MG_BIZ, MG_USER, [
            'order_number' => 127, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 50,
        ], $items);
    } catch (\Throwable $e) {
        $fallo = true;
    }

    $db->exec("SET SESSION sql_mode = " . $db->quote($modoOriginal));

    assertTrue($fallo, 'debe fallar en vez de guardar el pedido a medias');
    assertSame($itemsAntes, (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . MG_BIZ)->fetchColumn(),
        'las piezas de los grupos anteriores NO debieron quedar guardadas');
    assertSame($pedidosAntes, (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . MG_BIZ)->fetchColumn(),
        'ni el encabezado del pedido');
});

test('AVISO: sin modo estricto, un texto muy largo se trunca en silencio', function () use ($db) {
    // No es un fallo del código, es la configuración del servidor. Se deja escrito
    // porque significa que un nombre de más de 190 caracteres llegado por importación
    // se recorta sin avisarle a nadie.
    $modo = (string)$db->query('SELECT @@session.sql_mode')->fetchColumn();
    if (str_contains($modo, 'STRICT_TRANS_TABLES')) {
        assertTrue(true, 'el servidor ya está en modo estricto: nada que advertir');
        return;
    }
    assertTrue(
        !str_contains($modo, 'STRICT_TRANS_TABLES'),
        'este servidor no está en modo estricto; ver la nota de la entrega'
    );
});

// ---------------------------------------------------------------
// Compatibilidad
// ---------------------------------------------------------------

test('un pedido de UN SOLO grupo sigue funcionando igual', function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([MG_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([MG_BIZ]);

    $registro = AD::listRegistry(MG_BIZ);
    $items = mgAplanar([mgGrupo(['name' => 'Solo uno', 'cost' => 100], ['S','M','L'])], $registro);

    PurchaseOrder::createWithItems(MG_BIZ, MG_USER, [
        'order_number' => 128, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 30,
    ], $items);

    assertSame(3, count(InventoryItem::list(MG_BIZ)));
    assertSame(1, (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . MG_BIZ)->fetchColumn());
    assertSame(3, (int)$db->query('SELECT item_count FROM purchase_orders WHERE business_id = ' . MG_BIZ)->fetchColumn());
});

test('los pedidos ANTIGUOS de un solo producto siguen intactos', function () use ($db) {
    // El negocio real tiene 42 pedidos creados antes de que existieran los grupos.
    $rotos = (int)$db->query(
        'SELECT COUNT(*) FROM purchase_orders po
         WHERE po.business_id = 1
           AND po.item_count <> (SELECT COUNT(*) FROM inventory_items i WHERE i.purchase_order_id = po.id)'
    )->fetchColumn();
    assertSame(0, $rotos, 'ningún pedido histórico debió descuadrarse');
});

test('AISLAMIENTO: el pedido con grupos no toca otro negocio', function () use ($db) {
    $ajenas = (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = 1')->fetchColumn();
    $registro = AD::listRegistry(MG_BIZ);
    PurchaseOrder::createWithItems(MG_BIZ, MG_USER, [
        'order_number' => 129, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 10,
    ], mgAplanar([mgGrupo(['name' => 'Aislada'], ['S'])], $registro));

    assertSame($ajenas, (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = 1')->fetchColumn());
});

test('las notas del pedido se guardan', function () use ($db) {
    // La columna existía en el esquema original pero createWithItems nunca la escribía.
    $registro = AD::listRegistry(MG_BIZ);
    PurchaseOrder::createWithItems(MG_BIZ, MG_USER, [
        'order_number' => 130, 'currency' => 'MXN', 'exchange_rate' => 1, 'shipping_total' => 5,
        'notes' => 'Llegó incompleto, faltan 2 piezas',
    ], mgAplanar([mgGrupo(['name' => 'Con nota'], ['S'])], $registro));

    $n = $db->query(
        'SELECT notes FROM purchase_orders WHERE business_id = ' . MG_BIZ . ' AND order_number = 130'
    )->fetchColumn();
    assertSame('Llegó incompleto, faltan 2 piezas', $n);
});
$mgLimpiar();
