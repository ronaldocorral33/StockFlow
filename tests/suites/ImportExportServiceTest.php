<?php
/**
 * Pruebas de la importación de Excel.
 *
 * La prueba que da sentido a todo este archivo es "reimportar el mismo archivo no
 * duplica nada": ése fue un bug real que duplicó casi 700 piezas de inventario.
 * Un bug que ya te costó datos merece una prueba que lo vigile para siempre.
 *
 * Estas pruebas SÍ escriben en la base (importRows abre su propia transacción, así
 * que no se pueden envolver en otra). Por eso usan un negocio de pruebas dedicado
 * y limpian antes y después: si una prueba truena a media corrida, la siguiente
 * ejecución empieza limpia igual.
 */

use App\Database;
use App\Models\InventoryItem;
use App\Services\ImportExportService as IE;

const TEST_BIZ = 900903;
const TEST_USER = 900804;

$db = Database::connection();

$limpiar = function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([TEST_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([TEST_BIZ]);
    $db->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([TEST_BIZ]);
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([TEST_BIZ]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([TEST_BIZ]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([TEST_USER]);
};
$limpiar();

$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([TEST_USER, 'Import Test', 'import-test@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([TEST_BIZ, 'Negocio de pruebas de importación', TEST_USER]);
$db->prepare('INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, sort_order) VALUES (?,?,?,?,?,?)')
   ->execute([TEST_BIZ, TEST_USER, 'talla', 'Talla', 'text', 1]);

/** Una fila de Excel como la que produce SheetJS: claves = encabezados. */
function fila(array $over = []): array
{
    return $over + [
        'Pedido' => '7',
        'Nombre' => 'FC Barcelona',
        'Jugador' => 'Sin nombre',
        'Talla' => 'M',
        'Proveedor' => 'Alibaba',
        'Costo (MXN)' => '200',
        'Envío (MXN)' => '50',
        'Venta (MXN)' => '',
        'Fecha de compra' => '2026-01-10',
        'Fecha de llegada' => '2026-02-01',
        'Fecha de Venta' => '',
    ];
}

function piezasDePrueba(): array
{
    return InventoryItem::list(TEST_BIZ);
}

// ---------------------------------------------------------------
// La huella de identidad
// ---------------------------------------------------------------

test('la huella IGNORA el precio y la fecha de venta', function () {
    // Ésta es la regla que evita el bug: vender una pieza no la convierte en otra pieza.
    $sinVender = ['name' => 'Jersey', 'cost' => 200, 'attributes' => ['talla' => 'M']];
    $vendida = $sinVender + ['sale_price' => 700, 'sale_date' => '2026-03-01'];
    assertSame(IE::matchKey($sinVender), IE::matchKey($vendida));
});

test('la huella SÍ distingue por atributo (talla)', function () {
    $m = ['name' => 'Jersey', 'cost' => 200, 'attributes' => ['talla' => 'M']];
    $xl = ['name' => 'Jersey', 'cost' => 200, 'attributes' => ['talla' => 'XL']];
    assertTrue(IE::matchKey($m) !== IE::matchKey($xl));
});

test('reemplazar borra las piezas anteriores e importa el Excel desde cero', function () use ($db) {
    $limpiarDatos = function () use ($db) {
        $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([TEST_BIZ]);
        $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([TEST_BIZ]);
        $db->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([TEST_BIZ]);
    };
    $limpiarDatos();

    try {
        IE::importRows(TEST_BIZ, TEST_USER, [fila(['Nombre' => 'Inventario anterior'])], IE::MODE_ADD);

        $ensayo = IE::importRows(TEST_BIZ, TEST_USER, [fila(['Nombre' => 'Inventario actualizado'])], IE::MODE_REPLACE, true);
        assertSame(1, $ensayo['replaced_items'], 'el ensayo debe indicar la pieza que se reemplazará');
        assertSame(1, count(piezasDePrueba()), 'el ensayo nunca debe borrar datos');

        try {
            IE::importRows(TEST_BIZ, TEST_USER, [['Nombre' => 'Ignorado']], IE::MODE_REPLACE, false, ['Nombre' => '__ignorar__']);
            throw new \RuntimeException('un archivo sin filas importables debió rechazarse');
        } catch (\InvalidArgumentException $e) {
            assertSame(1, count(piezasDePrueba()), 'un Excel inválido no puede vaciar el inventario');
        }

        $resultado = IE::importRows(TEST_BIZ, TEST_USER, [fila(['Nombre' => 'Inventario actualizado'])], IE::MODE_REPLACE);
        assertSame(1, $resultado['inserted']);
        assertSame('Inventario actualizado', piezasDePrueba()[0]['name']);
    } finally {
        $limpiarDatos();
    }
});

test('la huella normaliza dinero y mayúsculas', function () {
    $a = ['name' => 'FC Barcelona', 'cost' => '1,200.00'];
    $b = ['name' => 'fc barcelona', 'cost' => 1200];
    assertSame(IE::matchKey($a), IE::matchKey($b));
});

test('la huella no depende del orden de los atributos', function () {
    $a = ['name' => 'J', 'attributes' => ['talla' => 'M', 'liga' => 'Europa']];
    $b = ['name' => 'J', 'attributes' => ['liga' => 'Europa', 'talla' => 'M']];
    assertSame(IE::matchKey($a), IE::matchKey($b));
});

test('un atributo vacío no cambia la huella', function () {
    // Si no fuera así, agregar una columna nueva vacía al Excel volvería "nuevas"
    // a todas las piezas y las duplicaría.
    $a = ['name' => 'J', 'attributes' => ['talla' => 'M']];
    $b = ['name' => 'J', 'attributes' => ['talla' => 'M', 'color' => '']];
    assertSame(IE::matchKey($a), IE::matchKey($b));
});

// ---------------------------------------------------------------
// Mapeo de columnas
// ---------------------------------------------------------------

test('lee la columna Pedido (la que antes se perdía)', function () {
    $d = IE::mapImportRow(fila(), []);
    assertSame('7', $d['order_number']);
});

test('lee la columna ID para emparejar exacto', function () {
    $d = IE::mapImportRow(fila(['ID' => '4321']), []);
    assertSame('4321', $d['id']);
});

test('una fila sin nombre se descarta', function () {
    assertSame(null, IE::mapImportRow(fila(['Nombre' => '']), []));
});

// ---------------------------------------------------------------
// Los dos modos, contra la base real
// ---------------------------------------------------------------

test('modo agregar: inserta y crea el pedido con su número', function () {
    $r = IE::importRows(TEST_BIZ, TEST_USER, [fila(), fila(['Talla' => 'L'])], IE::MODE_ADD);
    assertSame(2, $r['inserted']);

    $piezas = piezasDePrueba();
    assertSame(2, count($piezas));
    assertSame('7', (string)$piezas[0]['order_number'], 'la pieza debe quedar ligada al pedido #7');
});

test('REGRESIÓN: reimportar el mismo archivo en modo actualizar NO duplica', function () {
    // El bug real: importar de nuevo para "actualizar" y terminar con todo repetido.
    $antes = count(piezasDePrueba());
    $r = IE::importRows(TEST_BIZ, TEST_USER, [fila(), fila(['Talla' => 'L'])], IE::MODE_SYNC);

    assertSame(0, $r['inserted'], 'no debe insertar nada: ya existen');
    assertSame(2, $r['unchanged'], 'son idénticas, así que ni siquiera hay que actualizarlas');
    assertSame($antes, count(piezasDePrueba()), 'el total de piezas no cambia');
});

test('el MISMO archivo en modo agregar sí duplica (por eso existe el modo actualizar)', function () {
    $antes = count(piezasDePrueba());
    IE::importRows(TEST_BIZ, TEST_USER, [fila()], IE::MODE_ADD);
    assertSame($antes + 1, count(piezasDePrueba()));

    // Se deshace para no ensuciar las pruebas siguientes.
    $db = Database::connection();
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ? ORDER BY id DESC LIMIT 1')->execute([TEST_BIZ]);
});

test('modo actualizar: registra la venta en la pieza que ya existía', function () {
    $r = IE::importRows(TEST_BIZ, TEST_USER, [
        fila(['Venta (MXN)' => '700', 'Fecha de Venta' => '2026-03-05']),
    ], IE::MODE_SYNC);

    assertSame(0, $r['inserted'], 'vender no crea una pieza nueva');
    assertSame(1, $r['updated']);

    $vendidas = array_values(array_filter(piezasDePrueba(), fn($p) => $p['sale_date'] !== null));
    assertSame(1, count($vendidas));
    assertSame('700.00', (string)$vendidas[0]['sale_price']);
});

test('piezas gemelas se emparejan una a una, no todas contra la misma', function () {
    $db = Database::connection();
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([TEST_BIZ]);

    $gemela = fila(['Nombre' => 'Gemelo', 'Talla' => 'U']);
    IE::importRows(TEST_BIZ, TEST_USER, [$gemela, $gemela, $gemela], IE::MODE_ADD);
    assertSame(3, count(piezasDePrueba()));

    // Tres filas idénticas otra vez: deben emparejar con las tres, no con una.
    $r = IE::importRows(TEST_BIZ, TEST_USER, [$gemela, $gemela, $gemela], IE::MODE_SYNC);
    assertSame(0, $r['inserted'], 'las tres deben encontrar pareja');
    assertSame(3, $r['unchanged']);
    assertSame(3, count(piezasDePrueba()));

    // Una cuarta copia sí es nueva: solo había tres.
    $r2 = IE::importRows(TEST_BIZ, TEST_USER, [$gemela, $gemela, $gemela, $gemela], IE::MODE_SYNC);
    assertSame(1, $r2['inserted'], 'la cuarta no tiene con quién emparejar');
    assertSame(4, count(piezasDePrueba()));
});

test('el ensayo (dry run) no escribe absolutamente nada', function () {
    $antesPiezas = count(piezasDePrueba());
    $db = Database::connection();
    $antesPedidos = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . TEST_BIZ)->fetchColumn();

    $r = IE::importRows(TEST_BIZ, TEST_USER, [
        fila(['Nombre' => 'Nunca debe existir', 'Pedido' => '999']),
    ], IE::MODE_SYNC, true);

    assertSame(1, $r['inserted'], 'el ensayo SÍ debe reportar lo que pasaría');
    assertTrue($r['dry_run']);
    assertSame($antesPiezas, count(piezasDePrueba()), 'no debe haber creado la pieza');

    $despuesPedidos = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . TEST_BIZ)->fetchColumn();
    assertSame($antesPedidos, $despuesPedidos, 'tampoco debe haber creado el pedido #999');
});

test('el número de pedido se resuelve a UN pedido, no a uno por fila', function () {
    $db = Database::connection();
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([TEST_BIZ]);
    $db->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([TEST_BIZ]);

    $filas = [];
    for ($i = 1; $i <= 5; $i++) {
        $filas[] = fila(['Nombre' => "Pieza {$i}", 'Pedido' => '12']);
    }
    IE::importRows(TEST_BIZ, TEST_USER, $filas, IE::MODE_ADD);

    $n = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . TEST_BIZ)->fetchColumn();
    assertSame(1, $n, 'cinco filas del pedido #12 son UN pedido');

    $count = (int)$db->query('SELECT item_count FROM purchase_orders WHERE business_id = ' . TEST_BIZ)->fetchColumn();
    assertSame(5, $count, 'item_count debe recalcularse tras importar');
});

test('inventario se ordena por el último pedido aunque todas las filas se importen juntas', function () {
    IE::importRows(TEST_BIZ, TEST_USER, [
        fila(['Nombre' => 'Pedido nueve', 'Pedido' => '9']),
        fila(['Nombre' => 'Pedido quince', 'Pedido' => '15']),
    ], IE::MODE_ADD);

    $orden = array_values(array_unique(array_map(
        fn($p) => (int)$p['order_number'],
        InventoryItem::list(TEST_BIZ)
    )));
    assertSame([15, 12, 9], $orden, 'el pedido más reciente debe aparecer primero');
});

test('una fila sin columna Pedido no inventa un pedido', function () {
    $db = Database::connection();
    $antes = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . TEST_BIZ)->fetchColumn();
    IE::importRows(TEST_BIZ, TEST_USER, [fila(['Pedido' => '', 'Nombre' => 'Sin pedido'])], IE::MODE_ADD);
    $despues = (int)$db->query('SELECT COUNT(*) FROM purchase_orders WHERE business_id = ' . TEST_BIZ)->fetchColumn();
    assertSame($antes, $despues);
});

test('AISLAMIENTO: el modo actualizar solo empareja dentro del mismo negocio', function () {
    // Si el índice de emparejamiento no filtrara por negocio, una pieza idéntica de
    // otro negocio "absorbería" la actualización. InventoryItem::list ya filtra, pero
    // esta prueba lo deja clavado.
    $r = IE::importRows(TEST_BIZ, TEST_USER, [
        fila(['Nombre' => 'FC Barcelona', 'Talla' => 'M', 'Pedido' => '7']),
    ], IE::MODE_SYNC);
    // El negocio 1 tiene piezas así, pero este negocio de pruebas no: debe insertar.
    assertSame(1, $r['inserted'], 'no debe emparejar contra piezas de otro negocio');
});

$limpiar();
