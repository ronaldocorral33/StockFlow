<?php
/**
 * Pruebas de la capa semántica.
 *
 * Dos cosas distintas que probar:
 *
 * 1. Que traduce correctamente el esquema REAL (dimensiones fijas, atributos dinámicos,
 *    ejemplos tomados de los datos).
 * 2. Que NO está acoplada a jerseys. Ésa es la prueba que protege la promesa de
 *    modularidad: si alguien mete vocabulario de un giro en el código, debe fallar.
 */

use App\Services\Agent\SchemaSemantics as SS;

const SS_BIZ = 1; // el negocio real, con datos auditados

SS::flush();

// ---------------------------------------------------------------
// Dimensiones fijas
// ---------------------------------------------------------------

test('registra las dimensiones fijas con etiquetas genéricas', function () {
    $c = SS::forBusiness(SS_BIZ);
    foreach (['name', 'variant_label', 'category', 'subcategory', 'supplier'] as $k) {
        assertTrue(isset($c['dimensions'][$k]), "falta la dimensión {$k}");
    }
    assertSame('Producto', $c['dimensions']['name']['label']);
    assertSame('Variante', $c['dimensions']['variant_label']['label']);
});

test('name se resuelve a una columna, no a JSON', function () {
    $d = SS::dimension(SS_BIZ, 'name');
    assertSame('column', $d['source']);
    assertSame('i.name', SS::sqlExpression($d));
});

// ---------------------------------------------------------------
// Dimensiones dinámicas: la parte configurable
// ---------------------------------------------------------------

test('lee las dimensiones dinámicas de attribute_definitions', function () {
    $c = SS::forBusiness(SS_BIZ);
    // Este negocio definió talla, liga, etc. La prueba no exige NINGUNA en particular:
    // exige que existan dimensiones cuyo origen sea el JSON de atributos.
    $dinamicas = array_filter($c['dimensions'], fn($d) => $d['source'] === 'attributes');
    assertTrue(count($dinamicas) > 0, 'debe haber al menos una dimensión dinámica');
    foreach ($dinamicas as $d) {
        assertTrue(!empty($d['json_key']), 'una dimensión dinámica necesita su clave JSON');
        assertTrue(!empty($d['label']), 'y su etiqueta legible');
    }
});

test('una dimensión dinámica se resuelve a JSON_EXTRACT sobre attributes', function () {
    $c = SS::forBusiness(SS_BIZ);
    $d = null;
    foreach ($c['dimensions'] as $dim) {
        if ($dim['source'] === 'attributes') { $d = $dim; break; }
    }
    $expr = SS::sqlExpression($d);
    assertTrue(str_contains($expr, 'JSON_EXTRACT'), 'debe extraer del JSON');
    assertTrue(str_contains($expr, 'i.attributes'), 'de la columna attributes');
    assertTrue(str_contains($expr, $d['json_key']), 'con la clave definida por el negocio');
});

test('la clave JSON se sanea aunque venga de la base', function () {
    // Defensa en profundidad: si algún día attribute_definitions se alimentara desde
    // fuera, una clave con comillas no debe poder romper el SQL.
    $expr = SS::sqlExpression([
        'source' => 'attributes',
        'json_key' => "x'; DROP TABLE users; --",
    ]);

    // Una sola aserción cubre toda la propiedad de seguridad: si la clave únicamente
    // puede contener [a-zA-Z0-9_], entonces NINGÚN carácter capaz de cerrar el literal
    // (comilla, punto y coma, paréntesis, barra) puede estar ahí. Verificarlos uno por
    // uno sería redundante.
    //
    // Nota: la palabra "DROP" sobrevive como letras ("xDROPTABLEusers"), y está bien:
    // sin una comilla que cierre la cadena es un identificador inerte, no SQL.
    $patron = '/^JSON_UNQUOTE\(JSON_EXTRACT\(i\.attributes, ' . "'" . '\$\."([a-zA-Z0-9_]+)"' . "'" . '\)\)$/';
    $ok = preg_match($patron, $expr, $m);

    assertTrue($ok === 1, "la expresión debe quedar bien formada y con clave limpia, obtenida: {$expr}");
    assertSame('xDROPTABLEusers', $m[1], 'los caracteres peligrosos se eliminan; las letras quedan inertes');
});
// ---------------------------------------------------------------
// Ejemplos reales: el mecanismo que enseña la semántica
// ---------------------------------------------------------------

test('trae valores de ejemplo REALES para la dimensión de producto', function () {
    $d = SS::dimension(SS_BIZ, 'name');
    assertTrue(count($d['examples']) > 0, 'sin ejemplos, el modelo no puede deducir el significado');
    // Los ejemplos deben ser valores que de verdad estén en la base.
    $pdo = App\Database::chatbotReadOnly();
    foreach ($d['examples'] as $ej) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE business_id = ? AND name = ?');
        $stmt->execute([SS_BIZ, $ej]);
        assertTrue((int)$stmt->fetchColumn() > 0, "el ejemplo '{$ej}' no existe en los datos");
    }
});

test('los ejemplos nunca incluyen NULL ni cadenas vacías', function () {
    foreach (SS::forBusiness(SS_BIZ)['dimensions'] as $d) {
        foreach ($d['examples'] as $ej) {
            assertTrue(trim($ej) !== '', 'un ejemplo vacío no enseña nada');
        }
    }
});

// ---------------------------------------------------------------
// Detección de dimensión vacía: el freno contra "Sin equipo"
// ---------------------------------------------------------------

test('CLAVE: category se marca INSERVIBLE porque está vacía', function () {
    // Éste es el bug reportado: el modelo filtró por category='Jersey' y no había nada.
    // La capa semántica debe decir explícitamente que esa dimensión no sirve.
    $d = SS::dimension(SS_BIZ, 'category');
    assertSame(0.0, $d['fill_rate'], 'las 738 piezas tienen category NULL');
    assertSame(false, $d['usable']);
});

test('la dimensión de producto sí es utilizable', function () {
    $d = SS::dimension(SS_BIZ, 'name');
    assertTrue($d['fill_rate'] > 0.9, 'name está poblada');
    assertTrue($d['usable']);
});

test('dimensionKeys(onlyUsable) excluye las vacías', function () {
    $todas = SS::dimensionKeys(SS_BIZ);
    $utiles = SS::dimensionKeys(SS_BIZ, true);
    assertTrue(in_array('category', $todas, true), 'la dimensión existe...');
    assertSame(false, in_array('category', $utiles, true), '...pero no se ofrece como opción');
});

// ---------------------------------------------------------------
// El bloque del prompt
// ---------------------------------------------------------------

test('el prompt nombra las dimensiones útiles con sus ejemplos', function () {
    $p = SS::promptBlock(SS_BIZ);
    assertTrue(str_contains($p, 'name'), 'debe listar la clave');
    assertTrue(str_contains($p, 'Producto'), 'y su etiqueta');
    assertTrue(str_contains($p, 'ejemplos:'), 'y ejemplos reales');
});

test('el prompt AVISA de las dimensiones sin datos', function () {
    // Decirle qué NO usar es lo que evita que vuelva a filtrar por category.
    $p = SS::promptBlock(SS_BIZ);
    assertTrue(str_contains($p, 'SIN DATOS'), 'debe advertir explícitamente');
    assertTrue(str_contains($p, 'category'), 'y nombrar la dimensión vacía');
});

test('el prompt explica que una fila es una pieza física', function () {
    // Sin esto el modelo podría buscar una columna de cantidad que no existe.
    $p = SS::promptBlock(SS_BIZ);
    assertTrue(str_contains($p, 'COUNT(*)'));
    assertTrue(str_contains($p, 'UNA pieza física'));
});

// ---------------------------------------------------------------
// MODULARIDAD: la prueba que protege la promesa
// ---------------------------------------------------------------

test('MODULARIDAD: el código fuente no contiene vocabulario de ningún giro', function () {
    // Si alguien mete "jersey", "equipo" o "talla" en el código de la capa semántica o
    // de las herramientas analíticas, esta prueba falla. Ese vocabulario debe vivir
    // SOLO en la base de datos (attribute_definitions) y en los datos.
    $archivos = [
        'src/Services/Agent/SchemaSemantics.php',
        'src/Services/Agent/DateResolver.php',
        'src/Services/Tools/Analytics.php',
    ];
    $prohibidas = ['jersey', 'equipo', 'talla', 'liga', 'temporada', 'playera', 'camiseta'];
    $raiz = dirname(__DIR__, 2) . '/';

    foreach ($archivos as $rel) {
        $ruta = $raiz . $rel;
        if (!is_file($ruta)) { continue; }
        $contenido = mb_strtolower(file_get_contents($ruta));
        foreach ($prohibidas as $palabra) {
            // Se permite dentro de comentarios que EXPLIQUEN el caso real; lo que no se
            // permite es en código. Se distingue quitando las líneas de comentario.
            $codigo = preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', $contenido);
            assertSame(
                false,
                str_contains($codigo, $palabra),
                "'{$palabra}' aparece en el CÓDIGO de {$rel}: eso acopla StockFlow a un giro"
            );
        }
    }
});

test('MODULARIDAD: una dimensión nueva funciona por configuración, sin código', function () {
    // Se registra "marca" como atributo de un negocio de prueba y debe aparecer como
    // dimensión válida, con su expresión SQL, sin que exista marca_mas_vendida().
    $db = App\Database::connection();
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([900905]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([900905]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([900805]);

    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
       ->execute([900805, 'Modularidad', 'modularidad@test.local', 'x']);
    $db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
       ->execute([900905, 'Refaccionaria de prueba', 900805]);
    $db->prepare('INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, sort_order) VALUES (?,?,?,?,?,?)')
       ->execute([900905, 900805, 'marca', 'Marca', 'text', 1]);

    SS::flush(900905);
    $d = SS::dimension(900905, 'marca');
    assertTrue($d !== null, '"marca" debe existir como dimensión sin escribir código');
    assertSame('Marca', $d['label']);
    assertSame('attributes', $d['source']);
    assertTrue(str_contains(SS::sqlExpression($d), '"marca"'));

    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([900905]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([900905]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([900805]);
    SS::flush(900905);
});

test('AISLAMIENTO: el catálogo de un negocio no trae dimensiones de otro', function () {
    $db = App\Database::connection();
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([900906]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([900906]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([900806]);
    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
       ->execute([900806, 'Aislado', 'aislado@test.local', 'x']);
    $db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
       ->execute([900906, 'Papelería de prueba', 900806]);

    SS::flush(900906);
    $dims = SS::forBusiness(900906)['dimensions'];
    $dinamicas = array_filter($dims, fn($d) => $d['source'] === 'attributes');
    assertSame(0, count($dinamicas), 'un negocio sin atributos propios no hereda los de otro');
    assertSame(0, SS::forBusiness(900906)['totals']['pieces'], 'ni sus piezas');

    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([900906]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([900806]);
    SS::flush(900906);
});

test('el catálogo se memoiza: el tool loop no reconsulta en cada paso', function () {
    SS::flush(SS_BIZ);
    $t0 = microtime(true);
    SS::forBusiness(SS_BIZ);
    $primera = microtime(true) - $t0;

    $t1 = microtime(true);
    for ($i = 0; $i < 50; $i++) { SS::forBusiness(SS_BIZ); }
    $cincuenta = microtime(true) - $t1;

    assertTrue($cincuenta < $primera, '50 lecturas cacheadas deben costar menos que una construcción');
});
