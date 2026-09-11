<?php
/**
 * Genera un volcado portátil de la base, SIN los valores de las columnas generadas.
 *
 * POR QUÉ NO BASTA mysqldump
 * inventory_items tiene dos columnas que la base calcula sola:
 *
 *   total_cost = round(cost + shipping_cost)
 *   profit     = round(sale_price - cost - shipping_cost)  (NULL si no se vendió)
 *
 * El mysqldump de MariaDB las incluye en el INSERT, incluso con --complete-insert.
 * MariaDB 10.4 en local acepta esos valores y los ignora; el servidor (MySQL/MariaDB
 * más reciente) los rechaza con el error 3105:
 *
 *   The value specified for generated column 'total_cost' is not allowed
 *
 * No es un dato que se pierda: la base lo vuelve a calcular al insertar la fila. Lo
 * que hay que hacer es no mandárselo.
 *
 * ESTRATEGIA
 *   1. La estructura sale de mysqldump --no-data (mantiene las columnas generadas
 *      con su fórmula, que es justo lo que queremos conservar).
 *   2. Los datos se generan aquí, columna por columna, saltando las generadas. Se
 *      detectan desde information_schema, no con una lista escrita a mano: si mañana
 *      alguien agrega otra columna calculada, esto la salta sola.
 *
 * El resultado no lleva CREATE DATABASE ni USE, para que entre en una base con
 * cualquier nombre — en cPanel se llamará josefr15_stockflow.
 */

$baseLocal = 'control_inventario';
$salida = getenv('SALIDA') ?: 'C:/Users/roni4/Desktop/stockflow_datos.sql';
$mysqldump = 'C:/xampp/mysql/bin/mysqldump.exe';

$pdo = new PDO("mysql:host=127.0.0.1;dbname={$baseLocal};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// --- Qué columnas calcula la base sola ---
$generadas = [];
$q = $pdo->prepare(
    "SELECT table_name, column_name FROM information_schema.columns
      WHERE table_schema = ? AND generation_expression <> ''"
);
$q->execute([$baseLocal]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $generadas[$r['table_name']][] = $r['column_name'];
}

// --- 1. Estructura ---
$tmp = sys_get_temp_dir() . '/estructura.sql';
$cmd = '"' . $mysqldump . '" -u root --default-character-set=utf8mb4 --set-charset '
     . '--no-data --skip-add-locks ' . $baseLocal . ' > "' . $tmp . '" 2>NUL';
exec($cmd, $_, $rc);
if ($rc !== 0 || !is_file($tmp)) {
    exit("FALLO al volcar la estructura\n");
}
$sql = file_get_contents($tmp);
unlink($tmp);

/*
 * Se quitan las líneas de RESTAURACIÓN que mysqldump pone al final.
 *
 * Su cabecera hace FOREIGN_KEY_CHECKS=0 para poder crear las tablas en cualquier
 * orden, y su pie lo vuelve a encender. Como aquí los datos se agregan DESPUÉS de esa
 * estructura, ese pie quedaba en medio: las llaves foráneas se reactivaban justo antes
 * de insertar, y el primer INSERT (attribute_definitions, por orden alfabético)
 * fallaba porque su negocio todavía no existía.
 *
 * Se eliminan y se vuelven a poner al final del archivo completo, ya con los datos
 * dentro. Reactivar las llaves no revalida las filas ya insertadas, así que las
 * restricciones quedan activas y los datos íntegros.
 */
$sql = preg_replace('~^/\*!\d+ SET [A-Z_]+=@OLD_[A-Z_]+ \*/;\r?\n~m', '', $sql);

// --- 2. Datos, tabla por tabla ---
$tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$resumen = [];

foreach ($tablas as $tabla) {
    $saltar = $generadas[$tabla] ?? [];
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `{$tabla}`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!in_array($c['Field'], $saltar, true)) {
            $cols[] = $c['Field'];
        }
    }

    $lista = '`' . implode('`, `', $cols) . '`';
    $filas = $pdo->query("SELECT {$lista} FROM `{$tabla}`")->fetchAll(PDO::FETCH_NUM);
    $resumen[$tabla] = ['filas' => count($filas), 'omitidas' => $saltar];
    if (!$filas) {
        continue;
    }

    $sql .= "\n-- Datos de `{$tabla}`"
          . ($saltar ? " (sin " . implode(', ', $saltar) . ": las calcula la base)" : '') . "\n";

    // En lotes, para no producir una sola sentencia gigantesca que algunos
    // phpMyAdmin cortan por tamaño máximo de paquete.
    foreach (array_chunk($filas, 200) as $lote) {
        $tuplas = [];
        foreach ($lote as $fila) {
            $vals = array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                $fila
            );
            $tuplas[] = '(' . implode(',', $vals) . ')';
        }
        $sql .= "INSERT INTO `{$tabla}` ({$lista}) VALUES\n" . implode(",\n", $tuplas) . ";\n";
    }
}

// Ahora sí, con todos los datos dentro, se devuelve la sesión a como estaba.
$sql .= "\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
      . "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
      . "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
      . "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
      . "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
      . "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n"
      . "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n";

file_put_contents($salida, $sql);

echo "Escrito: {$salida}  (" . round(filesize($salida) / 1024) . " KB)\n\n";
printf("%-42s %8s  %s\n", 'TABLA', 'FILAS', 'COLUMNAS OMITIDAS');
foreach ($resumen as $t => $r) {
    printf("%-42s %8d  %s\n", $t, $r['filas'], $r['omitidas'] ? implode(', ', $r['omitidas']) : '—');
}
