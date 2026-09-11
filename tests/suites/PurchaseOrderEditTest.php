<?php
/**
 * Editar y borrar un pedido ya registrado.
 *
 * EL PROBLEMA QUE RESUELVE
 * "Registro algo en entradas, me di cuenta que está mal y ya no tengo forma de
 * quitarlo o cambiarlo." Un pedido solo se podía crear: nació como efecto secundario
 * de la captura y nadie lo volvía a mirar.
 *
 * POR QUÉ ESTAS PRUEBAS Y NO "guarda los cambios"
 * Este es el primer código del proyecto que BORRA INVENTARIO. Lo que hay que
 * demostrar no es que el UPDATE funcione, sino las tres cosas que pueden destruir
 * datos del usuario sin que se note hasta meses después:
 *
 *   · Borrar un pedido NO puede dejar sus piezas huérfanas. La llave foránea
 *     fk_items_po es ON DELETE SET NULL: un DELETE del pedido a secas las dejaría en
 *     el inventario sin pedido, contadas en el stock. Ya pasó una vez con 739 piezas.
 *   · Borrar un pedido con ventas NO puede proceder, y al negarse no debe haber
 *     borrado nada a medias.
 *   · Corregir la fecha del encabezado NO puede pisar la fecha que alguien le puso a
 *     una pieza específica.
 */

use App\Models\InventoryItem;
use App\Models\PurchaseOrder as PO;

const PE_BIZ = 900975;
const PE_OTRO = 900976;
const PE_USER = 900875;

$peDb = App\Database::connection();
assertIdDePrueba(PE_BIZ);
assertIdDePrueba(PE_OTRO);
assertIdDePrueba(PE_USER);

$peLimpiar = function () use ($peDb) {
    foreach ([PE_BIZ, PE_OTRO] as $b) {
        $peDb->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([$b]);
        $peDb->prepare('DELETE FROM purchase_orders WHERE business_id = ?')->execute([$b]);
        $peDb->prepare('DELETE FROM suppliers WHERE business_id = ?')->execute([$b]);
        $peDb->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([$b]);
        $peDb->prepare('DELETE FROM businesses WHERE id = ?')->execute([$b]);
    }
    $peDb->prepare('DELETE FROM users WHERE id = ?')->execute([PE_USER]);
};
$peLimpiar();

$peDb->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
     ->execute([PE_USER, 'Pedidos', 'pedidos@test.local', 'x']);
foreach ([PE_BIZ => 'Negocio pedidos', PE_OTRO => 'Otro negocio'] as $bid => $nombre) {
    $peDb->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
         ->execute([$bid, $nombre, PE_USER]);
}

/** Crea un pedido con $n piezas idénticas y devuelve su id. */
function peCrear(int $biz, array $header, int $n = 3, float $costo = 100): int
{
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        $items[] = ['name' => 'Jersey ' . $i, 'cost' => $costo];
    }
    return PO::createWithItems($biz, PE_USER, $header, $items)['purchase_order_id'];
}

/** Las piezas de un pedido, leídas crudas de la base. */
function pePiezas(int $poId): array
{
    $s = App\Database::connection()->prepare(
        'SELECT id, cost, shipping_cost, purchase_date, arrival_date, supplier_id, sale_date
           FROM inventory_items WHERE purchase_order_id = ? ORDER BY id'
    );
    $s->execute([$poId]);
    return $s->fetchAll();
}

/** Mensaje de la excepción esperada. */
function peRechaza(callable $fn): string
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('se esperaba un rechazo, y la operación pasó');
}

// ---------------------------------------------------------------
// Leer: las cifras se calculan, no se creen
// ---------------------------------------------------------------

test('las cifras del pedido salen de las piezas, no de item_count', function () use ($peDb) {
    $po = peCrear(PE_BIZ, ['order_number' => 10, 'shipping_total' => 0], 3, 100);

    // Se corrompe el contador denormalizado a propósito. Es exactamente lo que pasa
    // hoy cuando alguien borra una pieza desde Inventario: nadie recalcula la columna.
    $peDb->prepare('UPDATE purchase_orders SET item_count = 99 WHERE id = ?')->execute([$po]);

    $r = PO::find($po, PE_BIZ);
    assertSame(3, $r['piezas'], 'debió contar las piezas reales, no la columna mentirosa');
});

test('neto = recuperado − costo total, y en rojo mientras el pedido no se paga solo', function () use ($peDb) {
    $po = peCrear(PE_BIZ, ['order_number' => 11, 'shipping_total' => 60], 3, 100);
    // 3 piezas × $100 + $60 de envío = $360 de costo.
    $r = PO::find($po, PE_BIZ);
    assertSame(360.0, $r['costo_total']);
    assertSame(-360.0, $r['neto'], 'sin ventas, el neto es todo el costo en negativo');

    $piezas = pePiezas($po);
    InventoryItem::sell((int)$piezas[0]['id'], PE_BIZ, 250, '2026-09-01');

    $r = PO::find($po, PE_BIZ);
    assertSame(250.0, $r['recuperado']);
    assertSame(-110.0, $r['neto'], '250 recuperado − 360 de costo');
    assertSame(1, $r['vendidas']);
    assertSame(2, $r['disponibles']);
});

test('puede_borrarse dice la verdad y evita que la pantalla la deduzca sola', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 12], 2);
    assertTrue(PO::find($po, PE_BIZ)['puede_borrarse'], 'sin ventas debe poder borrarse');

    InventoryItem::sell((int)pePiezas($po)[0]['id'], PE_BIZ, 200, '2026-09-01');
    assertSame(false, PO::find($po, PE_BIZ)['puede_borrarse'], 'con una venta, ya no');
});

test('list() solo devuelve los pedidos del negocio propio', function () {
    peCrear(PE_OTRO, ['order_number' => 500], 1);
    $numeros = array_column(PO::list(PE_BIZ), 'order_number');
    assertTrue(!in_array(500, array_map('intval', $numeros), true), 'no debe verse el pedido del otro negocio');
});

// ---------------------------------------------------------------
// Editar el encabezado
// ---------------------------------------------------------------

test('cambia el número de pedido', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 20], 1);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['order_number' => 21]);
    assertSame(21, (int)PO::find($po, PE_BIZ)['order_number']);
});

test('rechaza un número que ya existe, nombrándolo', function () {
    peCrear(PE_BIZ, ['order_number' => 30], 1);
    $po = peCrear(PE_BIZ, ['order_number' => 31], 1);

    $msg = peRechaza(fn() => PO::updateHeader(PE_BIZ, PE_USER, $po, ['order_number' => 30]));
    assertTrue(str_contains($msg, '#30'), "el mensaje debía nombrar el pedido: {$msg}");
    assertSame(31, (int)PO::find($po, PE_BIZ)['order_number'], 'y no debió cambiar nada');
});

test('guardar el mismo número no se rechaza a sí mismo', function () {
    // Bug fácil de introducir: comparar contra todos los pedidos incluyendo este,
    // y entonces guardar sin tocar el número falla.
    $po = peCrear(PE_BIZ, ['order_number' => 40], 1);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['order_number' => 40, 'notes' => 'ok']);
    assertSame('ok', PO::find($po, PE_BIZ)['notes']);
});

test('rechaza un número que no es un número', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 41], 1);
    assertTrue(str_contains(peRechaza(fn() => PO::updateHeader(PE_BIZ, PE_USER, $po, ['order_number' => 'abc'])), 'mayor que cero'));
});

test('CLAVE: valida TODO antes de escribir nada', function () {
    // Se manda un cambio válido (notas) junto con uno inválido (número repetido). Si
    // las notas quedaran guardadas, el usuario vería un error y a la vez un cambio
    // aplicado a medias, sin saber cuál de los dos ganó.
    peCrear(PE_BIZ, ['order_number' => 50], 1);
    $po = peCrear(PE_BIZ, ['order_number' => 51], 1);

    peRechaza(fn() => PO::updateHeader(PE_BIZ, PE_USER, $po, [
        'order_number' => 50,
        'notes' => 'esto no debe quedar guardado',
    ]));
    assertSame(null, PO::find($po, PE_BIZ)['notes'], 'las notas no debieron escribirse');
});

// ---------------------------------------------------------------
// Envío: se reparte otra vez y la suma tiene que cuadrar
// ---------------------------------------------------------------

test('CLAVE: al cambiar el envío, la suma por pieza cuadra exacto', function () {
    // El caso que ya costó dinero una vez: $1,000 entre 3 con round() daba $333 a cada
    // una y el pedido "costaba" $999. El reparto va en centavos enteros.
    $po = peCrear(PE_BIZ, ['order_number' => 60, 'shipping_total' => 0], 3, 100);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['shipping_total' => 1000]);

    $envios = array_map(fn($p) => (float)$p['shipping_cost'], pePiezas($po));
    assertSame(1000.0, round(array_sum($envios), 2), 'la suma debe ser exactamente el envío');
    assertSame([333.34, 333.33, 333.33], $envios, 'el centavo sobrante va a la primera pieza');
});

test('cambiar el envío REEMPLAZA el reparto anterior, no lo acumula', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 61, 'shipping_total' => 300], 3, 100);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['shipping_total' => 90]);

    $envios = array_map(fn($p) => (float)$p['shipping_cost'], pePiezas($po));
    assertSame([30.0, 30.0, 30.0], $envios, 'debió recalcularse desde cero');
    assertSame(390.0, PO::find($po, PE_BIZ)['costo_total'], '3×100 + 90');
});

test('bajar el envío a cero lo quita de todas las piezas', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 62, 'shipping_total' => 300], 2, 100);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['shipping_total' => 0]);
    assertSame([0.0, 0.0], array_map(fn($p) => (float)$p['shipping_cost'], pePiezas($po)));
});

test('rechaza un envío negativo', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 63, 'shipping_total' => 100], 2);
    assertTrue(str_contains(peRechaza(fn() => PO::updateHeader(PE_BIZ, PE_USER, $po, ['shipping_total' => -5])), 'negativo'));
});

test('no tocar el envío deja el reparto como estaba', function () {
    // Editar solo la fecha no debe redistribuir nada: si lo hiciera, un centavo se
    // movería de pieza en cada guardado.
    $po = peCrear(PE_BIZ, ['order_number' => 64, 'shipping_total' => 10], 3, 100);
    $antes = array_map(fn($p) => (float)$p['shipping_cost'], pePiezas($po));
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['notes' => 'sin tocar envío']);
    assertSame($antes, array_map(fn($p) => (float)$p['shipping_cost'], pePiezas($po)));
});

// ---------------------------------------------------------------
// Propagación a las piezas: la regla más delicada
// ---------------------------------------------------------------

test('CLAVE: corregir la fecha del pedido corrige las piezas que la heredaron', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 70, 'arrival_date' => '2026-08-01'], 3);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['arrival_date' => '2026-08-15']);

    foreach (pePiezas($po) as $p) {
        assertSame('2026-08-15', $p['arrival_date'], 'la pieza debió seguir al encabezado');
    }
});

test('CLAVE: NO pisa la fecha que alguien le puso a una pieza específica', function () use ($peDb) {
    // El escenario real: el pedido llegó en dos envíos y se usó "llegada por lote"
    // para marcar dos piezas con otra fecha. Corregir el encabezado no puede borrar
    // esa corrección más específica.
    $po = peCrear(PE_BIZ, ['order_number' => 71, 'arrival_date' => '2026-08-01'], 3);
    $piezas = pePiezas($po);
    $peDb->prepare('UPDATE inventory_items SET arrival_date = ? WHERE id = ?')
         ->execute(['2026-08-20', $piezas[2]['id']]);

    $r = PO::updateHeader(PE_BIZ, PE_USER, $po, ['arrival_date' => '2026-08-15']);

    $despues = pePiezas($po);
    assertSame('2026-08-15', $despues[0]['arrival_date'], 'la que heredaba, sigue');
    assertSame('2026-08-15', $despues[1]['arrival_date']);
    assertSame('2026-08-20', $despues[2]['arrival_date'], 'la corregida a mano se respeta');
    assertSame(2, $r['piezas_actualizadas'], 'y el informe dice cuántas se tocaron de verdad');
});

test('propaga el proveedor con la misma regla', function () use ($peDb) {
    $po = peCrear(PE_BIZ, ['order_number' => 72, 'supplier' => 'Proveedor A'], 2);
    PO::updateHeader(PE_BIZ, PE_USER, $po, ['supplier' => 'Proveedor B']);

    $s = $peDb->prepare('SELECT id FROM suppliers WHERE business_id = ? AND name = ?');
    $s->execute([PE_BIZ, 'Proveedor B']);
    $idB = (int)$s->fetchColumn();

    foreach (pePiezas($po) as $p) {
        assertSame($idB, (int)$p['supplier_id'], 'las piezas debieron cambiar de proveedor');
    }
    assertSame('Proveedor B', PO::find($po, PE_BIZ)['supplier_name']);
});

test('una pieza vendida también se corrige: la fecha de compra no es la de venta', function () {
    // Deliberado: la propagación NO excluye las vendidas. Corregir la fecha de compra
    // de un pedido es corregir un dato histórico mal capturado, y eso aplica igual a
    // una pieza que ya se vendió. Lo que no se puede es BORRARLA.
    $po = peCrear(PE_BIZ, ['order_number' => 73, 'purchase_date' => '2026-07-01'], 2);
    InventoryItem::sell((int)pePiezas($po)[0]['id'], PE_BIZ, 300, '2026-09-01');

    PO::updateHeader(PE_BIZ, PE_USER, $po, ['purchase_date' => '2026-07-05']);
    foreach (pePiezas($po) as $p) {
        assertSame('2026-07-05', $p['purchase_date']);
    }
});

test('no puede editar un pedido de otro negocio', function () {
    $ajeno = peCrear(PE_OTRO, ['order_number' => 600], 1);
    assertTrue(str_contains(peRechaza(fn() => PO::updateHeader(PE_BIZ, PE_USER, $ajeno, ['notes' => 'hola'])), 'no existe'));
    assertSame(null, PO::find($ajeno, PE_OTRO)['notes'], 'y el pedido ajeno quedó intacto');
});

// ---------------------------------------------------------------
// Borrar
// ---------------------------------------------------------------

test('borra el pedido Y sus piezas', function () use ($peDb) {
    $po = peCrear(PE_BIZ, ['order_number' => 80], 4);
    $r = PO::deleteWithItems(PE_BIZ, $po);

    assertSame(4, $r['piezas_borradas']);
    assertSame(80, $r['order_number']);
    assertSame(null, PO::find($po, PE_BIZ), 'el pedido ya no existe');
    assertSame(0, count(pePiezas($po)), 'ni sus piezas');
});

test('CLAVE: no deja piezas huérfanas en el inventario', function () use ($peDb) {
    // fk_items_po es ON DELETE SET NULL. Si el borrado se apoyara en la llave foránea,
    // las 4 piezas seguirían en el inventario con purchase_order_id en NULL: contadas
    // en el stock, sumando a los costos, y sin pedido al cual pertenecer. Esta prueba
    // cuenta el inventario COMPLETO del negocio, no solo las del pedido.
    $antes = (int)$peDb->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . PE_BIZ)->fetchColumn();
    $po = peCrear(PE_BIZ, ['order_number' => 81], 4);
    PO::deleteWithItems(PE_BIZ, $po);
    $despues = (int)$peDb->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . PE_BIZ)->fetchColumn();

    assertSame($antes, $despues, 'el inventario debió volver exactamente a como estaba');
});

test('CLAVE: se niega a borrar un pedido con ventas, y NADA se borra', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 82], 5);
    InventoryItem::sell((int)pePiezas($po)[0]['id'], PE_BIZ, 400, '2026-09-01');

    $msg = peRechaza(fn() => PO::deleteWithItems(PE_BIZ, $po));
    assertTrue(str_contains($msg, '#82'), 'debe nombrar el pedido');
    assertTrue(str_contains($msg, '1 pieza ya vendida'), "y decir cuántas: {$msg}");

    // Lo que de verdad importa: al negarse, no se llevó ninguna de las otras 4.
    assertSame(5, count(pePiezas($po)), 'las 5 piezas siguen ahí');
    assertTrue(PO::find($po, PE_BIZ) !== null, 'y el pedido también');
});

test('el mensaje pluraliza bien con varias ventas', function () {
    $po = peCrear(PE_BIZ, ['order_number' => 83], 3);
    foreach (array_slice(pePiezas($po), 0, 2) as $p) {
        InventoryItem::sell((int)$p['id'], PE_BIZ, 400, '2026-09-01');
    }
    assertTrue(str_contains(peRechaza(fn() => PO::deleteWithItems(PE_BIZ, $po)), '2 piezas ya vendidas'));
});

test('anular la venta vuelve a permitir el borrado', function () {
    // La salida que el mensaje de error propone tiene que funcionar de verdad.
    $po = peCrear(PE_BIZ, ['order_number' => 84], 2);
    $pieza = (int)pePiezas($po)[0]['id'];
    InventoryItem::sell($pieza, PE_BIZ, 400, '2026-09-01');
    peRechaza(fn() => PO::deleteWithItems(PE_BIZ, $po));

    InventoryItem::voidSale($pieza, PE_BIZ);
    assertSame(2, PO::deleteWithItems(PE_BIZ, $po)['piezas_borradas']);
});

test('CLAVE: no puede borrar un pedido de otro negocio', function () {
    $ajeno = peCrear(PE_OTRO, ['order_number' => 700], 3);
    assertTrue(str_contains(peRechaza(fn() => PO::deleteWithItems(PE_BIZ, $ajeno)), 'no existe'));
    assertSame(3, count(pePiezas($ajeno)), 'las piezas del otro negocio siguen intactas');
});

test('borrar un pedido inexistente se rechaza con mensaje, no con un 500', function () {
    assertTrue(str_contains(peRechaza(fn() => PO::deleteWithItems(PE_BIZ, 999999999)), 'no existe'));
});

$peLimpiar();
