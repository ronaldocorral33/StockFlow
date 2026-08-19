<?php
/**
 * Pruebas de SqlGuard — la pieza de seguridad más crítica del proyecto.
 *
 * Cada bloque documenta POR QUÉ existe la regla, no solo qué hace. Si alguien
 * en el futuro "simplifica" el validador y rompe una de estas, la prueba explica
 * qué ataque vuelve a ser posible.
 */

use App\Services\SqlGuard;

const T = ['inventory_items', 'purchase_orders', 'suppliers'];

/** Atajo: una consulta legítima mínima. */
function okSql(string $extra = ''): string
{
    return 'SELECT COUNT(*) FROM inventory_items WHERE business_id = {{BUSINESS_ID}}' . $extra;
}

// ---------------------------------------------------------------
// 1. El camino feliz: consultas legítimas NO deben romperse
// ---------------------------------------------------------------

test('acepta una consulta legítima con filtro de negocio', function () {
    $r = SqlGuard::validate(okSql(), T);
    assertTrue($r['valid'], $r['reason'] ?? '');
});

test('acepta JOIN entre tablas permitidas', function () {
    $sql = 'SELECT i.name, s.name FROM inventory_items i JOIN suppliers s ON s.id = i.supplier_id '
         . 'WHERE i.business_id = {{BUSINESS_ID}}';
    assertTrue(SqlGuard::validate($sql, T)['valid']);
});

test('acepta el token opcional de usuario junto al de negocio', function () {
    $sql = 'SELECT * FROM inventory_items WHERE business_id = {{BUSINESS_ID}} AND user_id = {{USER_ID}}';
    assertTrue(SqlGuard::validate($sql, T)['valid']);
});

test('acepta SQL envuelto en un bloque de código markdown', function () {
    $r = SqlGuard::validate("```sql\n" . okSql() . "\n```", T);
    assertTrue($r['valid'], $r['reason'] ?? '');
});

// ---------------------------------------------------------------
// 2. Aislamiento entre negocios — el bug real que ya ocurrió una vez
// ---------------------------------------------------------------

test('REGRESIÓN: rechaza "OR business_id = 1" (el bug de aislamiento real)', function () {
    // Este es el ataque que SÍ pasó la validación en una versión anterior.
    // Si esta prueba falla, un negocio puede leer datos de otro.
    $sql = 'SELECT * FROM inventory_items WHERE business_id = {{BUSINESS_ID}} OR business_id = 1';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('rechaza comparar business_id contra un número literal', function () {
    $sql = 'SELECT * FROM inventory_items WHERE business_id = 7';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('rechaza negar el filtro de negocio', function () {
    $sql = 'SELECT * FROM inventory_items WHERE business_id != {{BUSINESS_ID}}';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('rechaza business_id IN (...)', function () {
    $sql = 'SELECT * FROM inventory_items WHERE business_id IN (1,2,3)';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('rechaza una consulta SIN filtro de negocio', function () {
    assertSame(false, SqlGuard::validate('SELECT * FROM inventory_items', T)['valid']);
});

test('rechaza mencionar user_id sin su token', function () {
    $sql = 'SELECT * FROM inventory_items WHERE business_id = {{BUSINESS_ID}} AND user_id = 5';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

// ---------------------------------------------------------------
// 3. Solo lectura — nada que modifique datos
// ---------------------------------------------------------------

test('rechaza todo verbo de escritura o DDL', function () {
    foreach (['INSERT INTO', 'UPDATE', 'DELETE FROM', 'DROP TABLE', 'ALTER TABLE',
              'TRUNCATE', 'CREATE TABLE', 'GRANT', 'REVOKE'] as $verb) {
        $sql = 'SELECT * FROM inventory_items WHERE business_id = {{BUSINESS_ID}} ' . $verb . ' x';
        assertSame(false, SqlGuard::validate($sql, T)['valid'], "debió rechazar: $verb");
    }
});

test('rechaza consultas que no empiezan con SELECT', function () {
    assertSame(false, SqlGuard::validate('UPDATE inventory_items SET cost = 0', T)['valid']);
});

test('rechaza un SELECT entre paréntesis al inicio (evita envolver un UNION)', function () {
    $sql = '(SELECT * FROM inventory_items WHERE business_id = {{BUSINESS_ID}})';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

// ---------------------------------------------------------------
// 4. Una sola sentencia — sin apilar ni comentar
// ---------------------------------------------------------------

test('rechaza sentencias apiladas con punto y coma', function () {
    $sql = okSql() . '; DROP TABLE users';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('permite un punto y coma final suelto', function () {
    // Terminar la sentencia es normal; lo peligroso es apilar OTRA sentencia después.
    assertTrue(SqlGuard::validate(okSql() . ';', T)['valid']);
});

test('rechaza los tres estilos de comentario SQL', function () {
    foreach (['-- oculto', '# oculto', '/* oculto */'] as $comment) {
        assertSame(false, SqlGuard::validate(okSql() . ' ' . $comment, T)['valid'], "debió rechazar: $comment");
    }
});

// ---------------------------------------------------------------
// 5. Lista blanca de tablas — el modelo no puede salirse del corral
// ---------------------------------------------------------------

test('rechaza leer la tabla users (donde viven las contraseñas)', function () {
    $sql = 'SELECT email, password_hash FROM users WHERE business_id = {{BUSINESS_ID}}';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('rechaza UNION aunque las tablas sean permitidas', function () {
    $sql = okSql() . ' UNION SELECT 1 FROM suppliers';
    assertSame(false, SqlGuard::validate($sql, T)['valid']);
});

test('rechaza information_schema y las tablas internas de mysql', function () {
    foreach (['information_schema.tables', 'mysql.user'] as $t) {
        $sql = 'SELECT * FROM ' . $t . ' WHERE business_id = {{BUSINESS_ID}}';
        assertSame(false, SqlGuard::validate($sql, T)['valid'], "debió rechazar: $t");
    }
});

test('rechaza funciones de lectura de archivos y de temporización', function () {
    foreach (['LOAD_FILE("/etc/passwd")', 'SLEEP(10)', 'BENCHMARK(1000000,MD5(1))'] as $fn) {
        $sql = 'SELECT ' . $fn . ' FROM inventory_items WHERE business_id = {{BUSINESS_ID}}';
        assertSame(false, SqlGuard::validate($sql, T)['valid'], "debió rechazar: $fn");
    }
});

test('rechaza una consulta sin tabla identificable', function () {
    assertSame(false, SqlGuard::validate('SELECT 1 WHERE business_id = {{BUSINESS_ID}}', T)['valid']);
});

// ---------------------------------------------------------------
// 6. Control de volumen — protege costo y tiempo de respuesta
// ---------------------------------------------------------------

test('agrega LIMIT 200 cuando la consulta no trae uno', function () {
    $r = SqlGuard::validate(okSql(), T);
    assertTrue(str_contains($r['sql'], 'LIMIT 200'));
});

test('recorta un LIMIT excesivo al tope de 500', function () {
    $r = SqlGuard::validate(okSql(' LIMIT 99999'), T);
    assertTrue(str_contains($r['sql'], 'LIMIT 500'));
    assertSame(false, str_contains($r['sql'], '99999'));
});

test('respeta un LIMIT pequeño que ya venía en la consulta', function () {
    $r = SqlGuard::validate(okSql(' LIMIT 10'), T);
    assertTrue(str_contains($r['sql'], 'LIMIT 10'));
});

// ---------------------------------------------------------------
// 7. La sustitución del ID real — el paso que hace efectivo el aislamiento
// ---------------------------------------------------------------

test('scopeToBusiness sustituye el token por el entero real', function () {
    $r = SqlGuard::validate(okSql(), T);
    $scoped = SqlGuard::scopeToBusiness($r['sql'], 42);
    assertTrue(str_contains($scoped, 'business_id = 42'));
    assertSame(false, str_contains($scoped, '{{BUSINESS_ID}}'), 'no debe quedar ningún token sin sustituir');
});

test('scopeToUser no hace nada si el modelo no usó el token', function () {
    $r = SqlGuard::validate(okSql(), T);
    $scoped = SqlGuard::scopeToUser($r['sql'], 7);
    assertSame($r['sql'], $scoped);
});

test('el SQL final nunca conserva un token sin sustituir', function () {
    $sql = 'SELECT * FROM inventory_items WHERE business_id = {{BUSINESS_ID}} AND user_id = {{USER_ID}}';
    $r = SqlGuard::validate($sql, T);
    $final = SqlGuard::scopeToUser(SqlGuard::scopeToBusiness($r['sql'], 3), 9);
    assertSame(false, str_contains($final, '{{'), 'quedó un token sin sustituir: ' . $final);
});
