<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

class Supplier
{
    public static function listForBusiness(int $businessId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM suppliers WHERE business_id = ? ORDER BY name ASC');
        $stmt->execute([$businessId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca un proveedor por nombre (case-insensitive) dentro del negocio, o lo crea si no existe.
     * $actorUserId es quién lo capturó (auditoría), $businessId es el dueño real del dato.
     */
    public static function resolveOrCreate(int $businessId, int $actorUserId, ?string $name): ?int
    {
        $name = trim((string)$name);
        if ($name === '') {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE business_id = ? AND LOWER(name) = LOWER(?)');
        $stmt->execute([$businessId, $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }
        $stmt = $pdo->prepare('INSERT INTO suppliers (business_id, user_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$businessId, $actorUserId, $name]);
        return (int)$pdo->lastInsertId();
    }
}
