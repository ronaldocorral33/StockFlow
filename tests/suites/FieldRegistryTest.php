<?php
/**
 * Pruebas del registro de campos (Fase 3A).
 *
 * La promesa de esta fase es doble y hay que probar las dos mitades:
 *
 * 1. Que la infraestructura quede lista (canónicos sembrados, metadatos, roles).
 * 2. Que NADA cambie de comportamiento. Ésta es la mitad que suele descuidarse, y es
 *    la que protege el inventario en producción: la siembra mete 14 filas nuevas en
 *    una tabla que ya tenía tres consumidores, y cualquiera de ellos podría haber
 *    empezado a verlas sin darse cuenta.
 */

use App\Models\AttributeDefinition as AD;
use App\Services\ImportExportService as IE;

const FR_BIZ = 1;

// ---------------------------------------------------------------
// La siembra
// ---------------------------------------------------------------

test('todo negocio existente tiene sus campos canónicos sembrados', function () {
    $db = App\Database::connection();
    $sin = $db->query(
        "SELECT b.id FROM businesses b
         WHERE NOT EXISTS (
           SELECT 1 FROM attribute_definitions a
           WHERE a.business_id = b.id AND a.storage = 'column'
         )"
    )->fetchAll();
    assertSame(0, count($sin), 'quedaron negocios sin registro canónico');
});

test('los canónicos incluyen los campos estructurales del inventario', function () {
    $claves = array_column(AD::listCanonical(FR_BIZ), 'field_key');
    foreach (['name', 'cost', 'sale_price', 'sale_date', 'purchase_date'] as $k) {
        assertTrue(in_array($k, $claves, true), "falta el canónico {$k}");
    }
});

test('los campos INTERNOS nunca se registran', function () {
    // Frontera dura: id, business_id, user_id y las llaves foráneas son integridad
    // del sistema, no vocabulario del negocio. Si alguna apareciera en el registro,
    // una futura pantalla de configuración permitiría ocultarla o renombrarla.
    $claves = array_column(AD::listRegistry(FR_BIZ), 'field_key');
    foreach (['id', 'business_id', 'user_id', 'supplier_id', 'purchase_order_id', 'created_at', 'updated_at'] as $interno) {
        assertSame(false, in_array($interno, $claves, true), "el campo interno '{$interno}' NO debe ser configurable");
    }
});

test('category y subcategory se sembraron OCULTAS, no borradas', function () {
    // La instrucción fue explícita: no son destructivas. Que este negocio no las use
    // no prueba que otro vertical no las necesite.
    foreach (['category', 'subcategory'] as $k) {
        $d = null;
        foreach (AD::listCanonical(FR_BIZ) as $c) {
            if ($c['field_key'] === $k) { $d = $c; break; }
        }
        assertTrue($d !== null, "{$k} debe seguir EXISTIENDO en el registro");
        assertSame(false, $d['show_in_table'], "{$k} debe estar oculta");
    }

    // Y la columna física sigue en su lugar.
    $cols = App\Database::connection()->query('SHOW COLUMNS FROM inventory_items')->fetchAll();
    $nombres = array_column($cols, 'Field');
    assertTrue(in_array('category', $nombres, true), 'la columna física no se tocó');
    assertTrue(in_array('subcategory', $nombres, true));
});

test('los campos derivados se marcan como computed', function () {
    // total_cost y profit son columnas generadas: una pantalla de configuración no
    // debe ofrecerlas como editables.
    foreach (AD::listCanonical(FR_BIZ) as $c) {
        if (in_array($c['field_key'], ['total_cost', 'profit'], true)) {
            assertSame('computed', $c['field_type'], "{$c['field_key']} debe ser computed");
        }
    }
});

// ---------------------------------------------------------------
// El rol semántico
// ---------------------------------------------------------------

test('existe exactamente UN campo con rol product_name por negocio', function () {
    $db = App\Database::connection();
    $rows = $db->query(
        "SELECT business_id, COUNT(*) n FROM attribute_definitions
         WHERE semantic_role = 'product_name' GROUP BY business_id HAVING n <> 1"
    )->fetchAll();
    assertSame(0, count($rows), 'algún negocio tiene cero o varios product_name');
});

test('productNameField devuelve el campo marcado', function () {
    $d = AD::productNameField(FR_BIZ);
    assertTrue($d !== null);
    assertSame('name', $d['field_key']);
    assertSame(AD::ROLE_PRODUCT_NAME, $d['semantic_role']);
});

test('assignRole no permite DOS campos con el mismo rol', function () {
    $db = App\Database::connection();
    $canon = AD::listCanonical(FR_BIZ);
    $porClave = [];
    foreach ($canon as $c) { $porClave[$c['field_key']] = $c; }

    $original = $porClave['name']['id'];
    $otro = $porClave['variant_label']['id'];

    // Se le da el rol a otro campo: el anterior debe perderlo automáticamente.
    AD::assignRole($otro, FR_BIZ, AD::ROLE_PRODUCT_NAME);
    $n = (int)$db->query(
        "SELECT COUNT(*) FROM attribute_definitions
         WHERE business_id = " . FR_BIZ . " AND semantic_role = 'product_name'"
    )->fetchColumn();
    assertSame(1, $n, 'nunca deben coexistir dos');
    assertSame('variant_label', AD::productNameField(FR_BIZ)['field_key']);

    // Se restaura.
    AD::assignRole($original, FR_BIZ, AD::ROLE_PRODUCT_NAME);
    assertSame('name', AD::productNameField(FR_BIZ)['field_key']);
});

test('assignRole rechaza un rol que no existe', function () {
    $id = AD::listCanonical(FR_BIZ)[0]['id'];
    $rechazado = false;
    try {
        AD::assignRole($id, FR_BIZ, 'marca');   // no está en ROLES todavía
    } catch (\InvalidArgumentException $e) {
        $rechazado = true;
    }
    assertTrue($rechazado, 'solo product_name tiene consumidor hoy');
});

// ---------------------------------------------------------------
// COMPATIBILIDAD: nada debe haber cambiado
// ---------------------------------------------------------------

test('CLAVE: listForBusiness sigue devolviendo SOLO campos personalizados', function () {
    // Si devolviera los canónicos, el importador metería "Producto" y "Costo" dentro
    // del JSON, y la tabla de inventario dibujaría columnas duplicadas.
    $defs = AD::listForBusiness(FR_BIZ);
    assertTrue(count($defs) > 0, 'este negocio tiene campos personalizados');
    foreach ($defs as $d) {
        assertSame(AD::STORAGE_JSON, $d['storage'], "'{$d['field_key']}' no debería venir aquí");
        assertSame(false, $d['is_canonical']);
    }
});

test('el registro completo es la suma de los dos grupos', function () {
    $todos = count(AD::listRegistry(FR_BIZ));
    $json = count(AD::listForBusiness(FR_BIZ));
    $col = count(AD::listCanonical(FR_BIZ));
    assertSame($todos, $json + $col);
});

test('el importador sigue mapeando solo a atributos personalizados', function () {
    // Con la etiqueta "Producto" ahora registrada, el peligro real era que el
    // importador la tratara como atributo y escribiera el nombre dentro del JSON.
    $fila = [
        'Nombre' => 'Prueba Registro',
        'Costo (MXN)' => '100',
        'Talla' => 'M',
        'Producto' => 'NO DEBE ACABAR EN EL JSON',
    ];
    $d = IE::mapImportRow($fila, AD::listForBusiness(FR_BIZ));

    assertSame('Prueba Registro', $d['name'], 'el nombre va a su columna');
    assertSame(100.0, $d['cost']);
    foreach (($d['attributes'] ?? []) as $k => $v) {
        assertSame(false, str_contains((string)$v, 'NO DEBE ACABAR'), 'una etiqueta canónica se colgó del JSON');
    }
});

test('un campo personalizado nuevo conserva su orden dentro de su grupo', function () {
    // Los canónicos ocupan 1..14. Si create() los contara, un campo nuevo saltaría a
    // 15 y se movería de lugar en la interfaz del usuario.
    $db = App\Database::connection();
    $maxJson = (int)$db->query(
        "SELECT COALESCE(MAX(sort_order), -1) FROM attribute_definitions
         WHERE business_id = " . FR_BIZ . " AND storage = 'json'"
    )->fetchColumn();

    $id = AD::create(FR_BIZ, 1, ['label' => 'Campo Temporal Prueba']);
    $creado = null;
    foreach (AD::listForBusiness(FR_BIZ) as $d) {
        if ((int)$d['id'] === $id) { $creado = $d; break; }
    }
    assertTrue($creado !== null);
    assertSame($maxJson + 1, (int)$creado['sort_order'], 'debe seguir la numeración de su grupo');
    assertSame(AD::STORAGE_JSON, $creado['storage'], 'lo que crea el usuario es siempre personalizado');

    AD::delete($id, FR_BIZ);
});

// ---------------------------------------------------------------
// Protección de los canónicos
// ---------------------------------------------------------------

test('un campo canónico NO se puede borrar', function () {
    $canon = AD::listCanonical(FR_BIZ);
    $antes = count($canon);
    $id = null;
    foreach ($canon as $c) {
        if ($c['field_key'] === 'cost') { $id = (int)$c['id']; break; }
    }
    assertTrue($id !== null);

    AD::delete($id, FR_BIZ);   // no debe lanzar, pero tampoco borrar
    assertSame($antes, count(AD::listCanonical(FR_BIZ)), 'el canónico sigue ahí');
});

test('reorder no puede desordenar los canónicos', function () {
    $canon = AD::listCanonical(FR_BIZ);
    $ordenAntes = array_column($canon, 'sort_order', 'field_key');

    // Se intenta renumerar pasando ids canónicos, como haría una petición maliciosa
    // o un bug del frontend.
    AD::reorder(FR_BIZ, array_column($canon, 'id'));

    $ordenDespues = array_column(AD::listCanonical(FR_BIZ), 'sort_order', 'field_key');
    assertSame($ordenAntes, $ordenDespues, 'el orden canónico no debe alterarse');
});

// ---------------------------------------------------------------
// Aislamiento y siembra de negocios nuevos
// ---------------------------------------------------------------

test('un negocio NUEVO nace con su registro canónico', function () {
    $db = App\Database::connection();
    $limpiar = function () use ($db) {
        $db->prepare('DELETE FROM attribute_definitions WHERE business_id IN (SELECT id FROM businesses WHERE owner_user_id = ?)')->execute([808]);
        $db->prepare('DELETE FROM business_users WHERE user_id = ?')->execute([808]);
        $db->prepare('DELETE FROM businesses WHERE owner_user_id = ?')->execute([808]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([808]);
    };
    $limpiar();
    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
       ->execute([808, 'Nuevo', 'nuevo-registro@test.local', 'x']);

    $bid = App\Models\Business::createWithOwner('Negocio Recién Creado', 808);

    $canon = AD::listCanonical($bid);
    assertSame(14, count($canon), 'debe nacer con los 14 canónicos');
    assertSame(0, count(AD::listForBusiness($bid)), 'y sin campos personalizados');
    assertSame('name', AD::productNameField($bid)['field_key']);

    $limpiar();
});

test('AISLAMIENTO: el registro de un negocio no trae campos de otro', function () {
    $propios = AD::listRegistry(FR_BIZ);
    $db = App\Database::connection();
    $stmt = $db->prepare('SELECT business_id FROM attribute_definitions WHERE id = ?');
    foreach ($propios as $d) {
        $stmt->execute([$d['id']]);
        assertSame(FR_BIZ, (int)$stmt->fetchColumn(), 'se colaron definiciones de otro negocio');
    }
});

test('seedCanonical es idempotente', function () {
    $antes = count(AD::listCanonical(FR_BIZ));
    AD::seedCanonical(FR_BIZ, 1);
    AD::seedCanonical(FR_BIZ, 1);
    assertSame($antes, count(AD::listCanonical(FR_BIZ)), 'no debe duplicar nada');
});
