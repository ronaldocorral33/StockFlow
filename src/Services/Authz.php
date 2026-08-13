<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Models\Business;

/** Autorización basada en roles. Nunca cachea el rol en sesión: se re-consulta cada request. */
class Authz
{
    private static array $roleCache = [];

    public static function can(string $action, string $resource): bool
    {
        $businessId = $_SESSION['active_business_id'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;
        if ($businessId === null || $userId === null) {
            return false;
        }
        $roleKey = Business::roleKeyFor((int)$businessId, (int)$userId);
        if ($roleKey === null) {
            return false;
        }
        return self::permissionsForRole($roleKey)[$resource . ':' . $action] ?? false;
    }

    /** Guard duro: corta la ejecución (403) si la acción no está permitida. */
    public static function require(string $action, string $resource, bool $isApi = true): void
    {
        if (!self::can($action, $resource)) {
            if ($isApi) {
                json_response(['error' => 'No tienes permiso para hacer esto.'], 403);
            }
            http_response_code(403);
            exit('No tienes permiso para hacer esto.');
        }
    }

    private static function permissionsForRole(string $roleKey): array
    {
        if (!isset(self::$roleCache[$roleKey])) {
            $stmt = Database::connection()->prepare(
                'SELECT p.resource, p.action FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 JOIN roles r ON r.id = rp.role_id
                 WHERE r.role_key = ?'
            );
            $stmt->execute([$roleKey]);
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[$row['resource'] . ':' . $row['action']] = true;
            }
            self::$roleCache[$roleKey] = $map;
        }
        return self::$roleCache[$roleKey];
    }
}
