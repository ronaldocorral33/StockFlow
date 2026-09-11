<?php
/**
 * Reparación de etiquetas con acentos rotos — sql/009_reparar_acentos_etiquetas.sql
 *
 * POR QUÉ SE PRUEBA UNA MIGRACIÓN DE DATOS
 * Un UPDATE de reparación es el tipo de cambio más fácil de escribir mal y más caro
 * de descubrir tarde: si la condición es demasiado amplia, pisa etiquetas que el
 * usuario eligió a mano, y no hay forma de saber cuáles eran. Las tres propiedades
 * que lo hacen seguro no son evidentes leyendo el SQL, así que se afirman aquí:
 *
 *   1. Repara la etiqueta rota.
 *   2. NO toca una etiqueta que el usuario renombró.
 *   3. Es idempotente: la segunda ejecución no actualiza ninguna fila.
 *
 * Se ejecuta el ARCHIVO REAL, no una copia de sus UPDATE. Una copia probaría que mi
 * transcripción está bien, no que la migración lo está.
 *
 * NOTA: al correr el archivo real, la reparación se aplica a TODA la base, no solo al
 * negocio de prueba. Es intencional y seguro — el UPDATE solo toca filas cuyos bytes
 * son exactamente los del daño, y ya nadie las quiere así.
 */

const ENC_BIZ = 900977;
const ENC_USER = 900877;

// Bytes de las tres etiquetas, en sus dos versiones. Se escriben en HEX por la misma
// razón que en la migración: así este archivo prueba bytes, no la interpretación que
// PHP o la conexión hagan de un literal con acento.
const ENC_CATEGORIA_ROTA  = '43617465676F72E2949CC2A161';        // Categor├¡a
const ENC_CATEGORIA_BUENA = '43617465676F72C3AD61';              // Categoría
const ENC_ENVIO_ROTO      = '456E76E2949CC2A16F';                // Env├¡o

$encDb = App\Database::connection();

$encLimpiar = function () use ($encDb) {
    $encDb->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([ENC_BIZ]);
    $encDb->prepare('DELETE FROM businesses WHERE id = ?')->execute([ENC_BIZ]);
    $encDb->prepare('DELETE FROM users WHERE id = ?')->execute([ENC_USER]);
};
assertIdDePrueba(ENC_BIZ);
assertIdDePrueba(ENC_USER);
$encLimpiar();

$encDb->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
      ->execute([ENC_USER, 'Acentos', 'acentos@test.local', 'x']);
$encDb->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
      ->execute([ENC_BIZ, 'Negocio con acentos rotos', ENC_USER]);

/** Inserta un campo con una etiqueta de bytes exactos. */
$encSembrar = function (string $key, string $hex) use ($encDb) {
    $encDb->prepare(
        'INSERT INTO attribute_definitions (business_id, user_id, field_key, label, storage)
         VALUES (?, ?, ?, CONVERT(UNHEX(?) USING utf8mb4), ?)'
    )->execute([ENC_BIZ, ENC_USER, $key, $hex, 'column']);
};

/** Los bytes que hay HOY en la etiqueta de un campo. */
$encHex = function (string $key) use ($encDb): string {
    $s = $encDb->prepare('SELECT HEX(label) FROM attribute_definitions WHERE business_id = ? AND field_key = ?');
    $s->execute([ENC_BIZ, $key]);
    return (string)$s->fetchColumn();
};

/**
 * Ejecuta sql/009 y devuelve cuántas filas cambió EN TOTAL.
 *
 * El conteo es lo que permite afirmar la idempotencia con un número en vez de
 * "se ve igual": la segunda corrida debe reportar cero.
 */
$encCorrerMigracion = function () use ($encDb): int {
    $sql = file_get_contents(dirname(__DIR__, 2) . '/sql/009_reparar_acentos_etiquetas.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);          // comentarios
    $sql = preg_replace('/^\s*USE\s+\w+\s*;/mi', '', $sql); // la conexión ya está en su base
    $filas = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $filas += $encDb->exec($stmt);
    }
    return $filas;
};

// ---------------------------------------------------------------

test('repara la etiqueta rota y deja los bytes correctos de "í"', function () use ($encSembrar, $encHex, $encCorrerMigracion) {
    $encSembrar('category', ENC_CATEGORIA_ROTA);
    assertSame(ENC_CATEGORIA_ROTA, $encHex('category'), 'la siembra debía quedar rota');

    $encCorrerMigracion();

    // C3AD son los bytes UTF-8 de "í". Si en su lugar quedara E2949CC2A1, seguiría
    // doblemente codificada aunque en pantalla se viera "parecido".
    assertSame(ENC_CATEGORIA_BUENA, $encHex('category'), 'debió quedar reparada');
});

test('NO toca una etiqueta que el usuario renombró', function () use ($encSembrar, $encHex, $encCorrerMigracion, $encDb) {
    // El caso que una condición amplia (LIKE '%├%') destruiría sin avisar: alguien
    // llamó "Envío" de otra forma, y esa decisión suya debe sobrevivir la reparación.
    $encDb->prepare(
        'INSERT INTO attribute_definitions (business_id, user_id, field_key, label, storage) VALUES (?,?,?,?,?)'
    )->execute([ENC_BIZ, ENC_USER, 'shipping_cost', 'Paquetería', 'column']);
    $antes = $encHex('shipping_cost');

    $encCorrerMigracion();

    assertSame($antes, $encHex('shipping_cost'), 'la etiqueta elegida por el usuario debía quedar intacta');
});

test('NO toca una etiqueta que ya estaba bien', function () use ($encSembrar, $encHex, $encCorrerMigracion) {
    $encSembrar('subcategory', '53756263617465676F72C3AD61');   // Subcategoría, correcta
    $antes = $encHex('subcategory');

    $encCorrerMigracion();

    assertSame($antes, $encHex('subcategory'));
});

test('CLAVE: es idempotente — la segunda corrida no actualiza ninguna fila', function () use ($encCorrerMigracion) {
    // Correr una migración dos veces es normal (se re-aplica el paquete completo).
    // Si la condición no fuera el valor roto exacto, la segunda pasada volvería a
    // "reparar" filas ya correctas, y eso es justo lo que aquí se descarta con un
    // número, no con una inspección visual.
    $encCorrerMigracion();
    assertSame(0, $encCorrerMigracion(), 'la segunda ejecución debía tocar cero filas');
});

$encLimpiar();
