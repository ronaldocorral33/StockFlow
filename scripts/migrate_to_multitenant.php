<?php
/**
 * Migración única: crea un negocio 1:1 para cada usuario existente que aún no tenga uno,
 * lo vincula como Administrador, y hace backfill de business_id en las 5 tablas de datos.
 * Idempotente: se puede volver a correr sin duplicar nada (solo actúa sobre lo que falte).
 *
 * Uso: "C:\xampp\php\php.exe" scripts\migrate_to_multitenant.php
 */

define('APP_BOOTSTRAP', true);
require __DIR__ . '/../src/Support/autoload.php';
$config = require __DIR__ . '/../config/config.php';
define('APP_CONFIG', $config);

use App\Database;

const SCOPED_TABLES = ['attribute_definitions', 'suppliers', 'purchase_orders', 'inventory_items', 'chat_messages'];

function countsForUser(\PDO $pdo, int $userId): array
{
    $counts = [];
    foreach (SCOPED_TABLES as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
        $stmt->execute([$userId]);
        $counts[$table] = (int)$stmt->fetchColumn();
    }
    return $counts;
}

$pdo = Database::connection();

$users = $pdo->query(
    "SELECT u.id, u.name, u.currency_default, u.exchange_rate_default
     FROM users u
     WHERE NOT EXISTS (SELECT 1 FROM business_users bu WHERE bu.user_id = u.id)"
)->fetchAll();

if (!$users) {
    echo "Nada que migrar: todos los usuarios ya tienen negocio.\n";
    exit(0);
}

echo count($users) . " usuario(s) sin negocio encontrados.\n";

foreach ($users as $u) {
    $userId = (int)$u['id'];
    echo "Procesando user_id={$userId} ({$u['name']})...\n";

    $before = countsForUser($pdo, $userId);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO businesses (name, currency_default, exchange_rate_default, owner_user_id)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(["Negocio de {$u['name']}", $u['currency_default'], $u['exchange_rate_default'], $userId]);
        $businessId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO business_users (business_id, user_id, role_id, status, joined_at) VALUES (?, ?, 1, ?, NOW())'
        )->execute([$businessId, $userId, 'active']);

        foreach (SCOPED_TABLES as $table) {
            $pdo->prepare("UPDATE {$table} SET business_id = ? WHERE user_id = ? AND business_id IS NULL")
                ->execute([$businessId, $userId]);
        }

        $after = countsForUser($pdo, $userId);
        if ($before !== $after) {
            throw new \RuntimeException('Los conteos antes/después no coinciden para user_id=' . $userId);
        }

        $nullLeft = 0;
        foreach (SCOPED_TABLES as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ? AND business_id IS NULL");
            $stmt->execute([$userId]);
            $nullLeft += (int)$stmt->fetchColumn();
        }
        if ($nullLeft !== 0) {
            throw new \RuntimeException("Quedaron {$nullLeft} fila(s) con business_id NULL para user_id={$userId}");
        }

        $pdo->commit();
        echo "  OK: business_id={$businessId} creado, conteos verificados.\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "  ABORTADO para user_id={$userId}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Migración completa: " . count($users) . " negocio(s) creado(s).\n";

// Verificación global final: cero filas con business_id NULL en toda la base.
$totalNull = 0;
foreach (SCOPED_TABLES as $table) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE business_id IS NULL")->fetchColumn();
    if ($n > 0) {
        echo "ADVERTENCIA: {$table} todavía tiene {$n} fila(s) con business_id NULL.\n";
    }
    $totalNull += $n;
}
echo $totalNull === 0
    ? "Verificación global: 0 filas con business_id NULL. Listo para sql/003_multitenant_harden.sql.\n"
    : "Verificación global: AÚN HAY {$totalNull} fila(s) sin business_id — no corras 003_multitenant_harden.sql todavía.\n";
