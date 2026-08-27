<?php
/**
 * Pruebas de la configuración de campos (pantalla "Campos y vistas").
 *
 * Lo que más importa probar no es que guarde, sino que se NIEGUE a guardar lo que
 * rompería el sistema: archivar un campo base, ocultar el identificador del producto,
 * editar un campo calculado, o dejar el negocio con dos campos principales.
 *
 * Y sobre todo: que archivar NO pierda los valores ya capturados.
 */

use App\Models\AttributeDefinition as AD;
use App\Models\InventoryItem;

const FC_BIZ = 931;
const FC_USER = 831;

$db = App\Database::connection();

$fcLimpiar = function () use ($db) {
    $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([FC_BIZ]);
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([FC_BIZ]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([FC_BIZ]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([FC_USER]);
};
$fcLimpiar();

$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([FC_USER, 'Config', 'config-campos@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([FC_BIZ, 'Negocio configurable', FC_USER]);
AD::seedCanonical(FC_BIZ, FC_USER);

/** Un campo por su clave. */
function fcCampo(string $key): ?array
{
    foreach (AD::listRegistry(FC_BIZ, true) as $f) {
        if ($f['field_key'] === $key) { return $f; }
    }
    return null;
}

// ---------------------------------------------------------------
// Crear y editar campos personalizados
// ---------------------------------------------------------------

test('crea un campo personalizado con su tipo y opciones', function () {
    $id = AD::create(FC_BIZ, FC_USER, [
        'label' => 'Material', 'field_type' => 'select', 'options' => 'Oro, Plata, Acero',
    ]);
    $f = AD::find($id, FC_BIZ);
    assertSame('Material', $f['label']);
    assertSame('material', $f['field_key'], 'la clave se deriva de la etiqueta');
    assertSame('select', $f['field_type']);
    assertSame(['Oro', 'Plata', 'Acero'], $f['options']);
    assertSame(AD::STORAGE_JSON, $f['storage'], 'lo que crea el usuario es personalizado');
    assertSame(false, $f['is_canonical']);
});

test('renombrar NO cambia la clave interna', function () {
    // La clave es la llave bajo la que se guardan los valores en el JSON: si cambiara
    // al renombrar, todos los valores capturados quedarían huérfanos.
    $f = fcCampo('material');
    AD::configure((int)$f['id'], FC_BIZ, ['label' => 'Material principal']);
    $d = AD::find((int)$f['id'], FC_BIZ);
    assertSame('Material principal', $d['label']);
    assertSame('material', $d['field_key'], 'la clave debe seguir siendo estable');
});

test('una etiqueta vacía se rechaza', function () {
    $f = fcCampo('material');
    $rechazado = false;
    try { AD::configure((int)$f['id'], FC_BIZ, ['label' => '   ']); }
    catch (\InvalidArgumentException $e) { $rechazado = true; }
    assertTrue($rechazado);
});

test('un tipo inválido se rechaza', function () {
    $f = fcCampo('material');
    $rechazado = false;
    try { AD::configure((int)$f['id'], FC_BIZ, ['field_type' => 'json_arbitrario']); }
    catch (\InvalidArgumentException $e) { $rechazado = true; }
    assertTrue($rechazado, 'solo se aceptan los tipos de USER_TYPES');
});

test('el tipo de un campo BASE no se puede cambiar', function () {
    // Lo impone la columna real de la base, no el usuario.
    $cost = fcCampo('cost');
    AD::configure((int)$cost['id'], FC_BIZ, ['field_type' => 'text']);
    assertSame('number', AD::find((int)$cost['id'], FC_BIZ)['field_type']);
});

// ---------------------------------------------------------------
// Visibilidad y orden, independientes por pantalla
// ---------------------------------------------------------------

test('las CUATRO pantallas tienen visibilidad independiente', function () {
    $f = fcCampo('material');
    $id = (int)$f['id'];

    foreach (array_keys(AD::CONTEXTS) as $ctx) {
        AD::setVisibility($id, FC_BIZ, $ctx, false);
    }
    AD::setVisibility($id, FC_BIZ, 'sales', true);

    $d = AD::find($id, FC_BIZ);
    assertTrue($d['visible_in_sales'], 'solo Salidas debía quedar visible');
    assertSame(false, $d['show_in_table']);
    assertSame(false, $d['visible_in_entries']);
    assertSame(false, $d['visible_in_export']);
});

test('el orden es independiente por pantalla', function () {
    $canonicos = array_values(array_filter(AD::listRegistry(FC_BIZ), fn($f) => $f['is_canonical']));
    $ids = array_column($canonicos, 'id');

    // Se guarda el orden de Salidas ANTES, para comprobar que no se mueve.
    $salesAntes = [];
    foreach ($canonicos as $f) { $salesAntes[$f['id']] = (int)$f['sort_order_sales']; }

    AD::reorderContext(FC_BIZ, 'inventory', array_reverse($ids));

    $porId = [];
    foreach (AD::listRegistry(FC_BIZ) as $f) { $porId[$f['id']] = $f; }

    // Inventario SÍ cambió: el último de la lista original quedó primero.
    assertSame(1, (int)$porId[end($ids)]['sort_order'], 'reordenar Inventario debe surtir efecto');

    // Salidas NO cambió, campo por campo.
    foreach ($salesAntes as $id => $orden) {
        assertSame($orden, (int)$porId[$id]['sort_order_sales'],
            "el orden de Salidas del campo {$porId[$id]['field_key']} no debía moverse");
    }
});

test('una pantalla desconocida se rechaza', function () {
    $f = fcCampo('material');
    foreach (['reportes', 'admin', ''] as $mala) {
        $rechazado = false;
        try { AD::setVisibility((int)$f['id'], FC_BIZ, $mala, true); }
        catch (\InvalidArgumentException $e) { $rechazado = true; }
        assertTrue($rechazado, "debía rechazar la pantalla '{$mala}'");
    }
});

test('forContext devuelve solo lo visible, ya ordenado', function () {
    $ent = AD::forContext(FC_BIZ, 'entries');
    foreach ($ent as $f) {
        assertTrue($f['visible_in_entries'], 'no debe colarse un campo oculto');
        assertSame(false, $f['archived'], 'ni uno archivado');
    }
});

// ---------------------------------------------------------------
// El campo principal
// ---------------------------------------------------------------

test('hay exactamente UN campo principal, y cambiarlo libera el anterior', function () use ($db) {
    $anterior = AD::productNameField(FC_BIZ);
    assertSame('name', $anterior['field_key'], 'el sembrado es name');

    $material = fcCampo('material');
    AD::setPrincipal((int)$material['id'], FC_BIZ);

    $n = (int)$db->query(
        "SELECT COUNT(*) FROM attribute_definitions
         WHERE business_id = " . FC_BIZ . " AND semantic_role = 'product_name'"
    )->fetchColumn();
    assertSame(1, $n, 'nunca deben coexistir dos');
    assertSame('material', AD::productNameField(FC_BIZ)['field_key']);

    AD::setPrincipal((int)$anterior['id'], FC_BIZ);
    assertSame('name', AD::productNameField(FC_BIZ)['field_key']);
});

test('un campo CALCULADO no puede ser el principal', function () {
    $profit = fcCampo('profit');
    $rechazado = false;
    try { AD::setPrincipal((int)$profit['id'], FC_BIZ); }
    catch (\InvalidArgumentException $e) { $rechazado = true; }
    assertTrue($rechazado, 'un campo que la base calcula no identifica al producto');
});

test('el campo principal NO se puede ocultar', function () {
    // Una tabla de inventario sin el identificador del producto no se puede leer.
    $p = AD::productNameField(FC_BIZ);
    $rechazado = false;
    try { AD::setVisibility((int)$p['id'], FC_BIZ, 'inventory', false); }
    catch (\InvalidArgumentException $e) { $rechazado = true; }
    assertTrue($rechazado);
    assertTrue(AD::find((int)$p['id'], FC_BIZ)['show_in_table'], 'sigue visible');
});

// ---------------------------------------------------------------
// Archivado: la parte que protege los datos
// ---------------------------------------------------------------

test('CLAVE: archivar NO pierde los valores capturados', function () {
    // Es la razón de archivar en vez de borrar. Se capturan piezas con el campo, se
    // archiva, y al reactivarlo los valores tienen que seguir ahí.
    $material = fcCampo('material');
    InventoryItem::create(FC_BIZ, FC_USER, ['name' => 'Anillo', 'cost' => 100, 'attributes' => ['material' => 'Oro']]);
    InventoryItem::create(FC_BIZ, FC_USER, ['name' => 'Collar', 'cost' => 200, 'attributes' => ['material' => 'Plata']]);

    assertSame(2, AD::valueCount((int)$material['id'], FC_BIZ), 'dos piezas tienen valor');

    AD::archive((int)$material['id'], FC_BIZ);
    assertTrue(AD::find((int)$material['id'], FC_BIZ)['archived']);

    // Desaparece de las pantallas...
    $claves = array_column(AD::listRegistry(FC_BIZ), 'field_key');
    assertSame(false, in_array('material', $claves, true));

    // ...pero los valores siguen intactos en cada pieza.
    foreach (InventoryItem::list(FC_BIZ) as $p) {
        assertTrue(!empty($p['attributes']['material']), 'el valor no debió borrarse');
    }

    AD::restore((int)$material['id'], FC_BIZ);
    assertSame(false, AD::find((int)$material['id'], FC_BIZ)['archived']);
    assertSame(2, AD::valueCount((int)$material['id'], FC_BIZ), 'y vuelven a contarse');
});

test('un campo BASE no se puede archivar', function () {
    foreach (['cost', 'sale_price', 'sale_date', 'name'] as $clave) {
        $f = fcCampo($clave);
        $rechazado = false;
        try { AD::archive((int)$f['id'], FC_BIZ); }
        catch (\InvalidArgumentException $e) { $rechazado = true; }
        assertTrue($rechazado, "no debía poder archivarse '{$clave}'");
        assertSame(false, AD::find((int)$f['id'], FC_BIZ)['archived']);
    }
});

test('el campo principal no se puede archivar', function () {
    $material = fcCampo('material');
    AD::setPrincipal((int)$material['id'], FC_BIZ);

    $rechazado = false;
    try { AD::archive((int)$material['id'], FC_BIZ); }
    catch (\InvalidArgumentException $e) { $rechazado = true; }
    assertTrue($rechazado);

    // Se restaura el principal original para no afectar pruebas siguientes.
    AD::setPrincipal((int)fcCampo('name')['id'], FC_BIZ);
});

test('los campos calculados no son editables', function () {
    foreach (AD::listRegistry(FC_BIZ) as $f) {
        if ($f['field_type'] === 'computed') {
            assertSame(false, $f['is_editable'], "{$f['field_key']} no debe ser editable");
        }
    }
});

// ---------------------------------------------------------------
// Aislamiento
// ---------------------------------------------------------------

test('AISLAMIENTO: no se configura un campo de otro negocio', function () {
    $ajeno = null;
    foreach (AD::listRegistry(1) as $f) {
        if ($f['field_key'] === 'name') { $ajeno = $f; break; }
    }
    $etiquetaAntes = $ajeno['label'];

    // Se intenta con el business_id equivocado: el WHERE debe impedirlo.
    try { AD::configure((int)$ajeno['id'], FC_BIZ, ['label' => 'INVASOR']); }
    catch (\InvalidArgumentException $e) { /* también es válido que lo rechace */ }

    $db = App\Database::connection();
    $stmt = $db->prepare('SELECT label FROM attribute_definitions WHERE id = ?');
    $stmt->execute([$ajeno['id']]);
    assertSame($etiquetaAntes, $stmt->fetchColumn(), 'el campo del negocio 1 no debió cambiar');
});

$fcLimpiar();
