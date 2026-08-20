<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

class Business
{
    /** Negocios activos a los que pertenece un usuario, con su rol en cada uno. */
    public static function listForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT b.id, b.name, r.role_key, r.label AS role_label
             FROM business_users bu
             JOIN businesses b ON b.id = bu.business_id AND b.deleted_at IS NULL
             JOIN roles r ON r.id = bu.role_id
             WHERE bu.user_id = ? AND bu.status = ?
             ORDER BY b.name'
        );
        $stmt->execute([$userId, 'active']);
        return $stmt->fetchAll();
    }

    public static function isMember(int $businessId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM business_users WHERE business_id = ? AND user_id = ? AND status = 'active'"
        );
        $stmt->execute([$businessId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function roleKeyFor(int $businessId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.role_key FROM business_users bu
             JOIN roles r ON r.id = bu.role_id
             WHERE bu.business_id = ? AND bu.user_id = ? AND bu.status = 'active'"
        );
        $stmt->execute([$businessId, $userId]);
        $role = $stmt->fetchColumn();
        return $role !== false ? $role : null;
    }

    public static function find(int $businessId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM businesses WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$businessId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Crea un negocio nuevo y vincula a $ownerUserId como Administrador (role_id=1). Usado en registro/onboarding. */
    public static function createWithOwner(string $name, int $ownerUserId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO businesses (name, owner_user_id) VALUES (?, ?)');
            $stmt->execute([$name, $ownerUserId]);
            $businessId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO business_users (business_id, user_id, role_id, status, joined_at) VALUES (?, ?, 1, 'active', NOW())"
            )->execute([$businessId, $ownerUserId]);

            // El negocio nace con su registro de campos canónicos, igual que los
            // existentes lo recibieron en sql/006. Dentro de la misma transacción: un
            // negocio a medio configurar sería peor que no crearlo.
            AttributeDefinition::seedCanonical($businessId, $ownerUserId);

            $pdo->commit();
            return $businessId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateSettings(int $businessId, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'currency_default', 'exchange_rate_default', 'business_type_id'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$col];
            }
        }
        if (!$fields) {
            return;
        }
        $params[] = $businessId;
        Database::connection()->prepare(
            'UPDATE businesses SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($params);
    }
}
