<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

class Supplier
{
    public static function listForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM suppliers WHERE user_id = ? ORDER BY name ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Busca un proveedor por nombre (case-insensitive) o lo crea si no existe. Devuelve su id, o null si $name está vacío. */
    public static function resolveOrCreate(int $userId, ?string $name): ?int
    {
        $name = trim((string)$name);
        if ($name === '') {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE user_id = ? AND LOWER(name) = LOWER(?)');
        $stmt->execute([$userId, $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }
        $stmt = $pdo->prepare('INSERT INTO suppliers (user_id, name) VALUES (?, ?)');
        $stmt->execute([$userId, $name]);
        return (int)$pdo->lastInsertId();
    }
}
