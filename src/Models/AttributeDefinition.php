<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use PDO;

class AttributeDefinition
{
    public static function listForBusiness(int $businessId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM attribute_definitions WHERE business_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$businessId]);
        return array_map([self::class, 'decorate'], $stmt->fetchAll());
    }

    private static function decorate(array $row): array
    {
        $row['options'] = $row['options'] ? json_decode($row['options'], true) : null;
        $row['is_required'] = (bool)$row['is_required'];
        $row['show_in_table'] = (bool)$row['show_in_table'];
        return $row;
    }

    public static function slugify(string $label): string
    {
        $slug = strtolower(trim($label));
        $slug = preg_replace('/[^a-z0-9]+/u', '_', self::stripAccents($slug));
        return trim($slug, '_') ?: 'campo';
    }

    private static function stripAccents(string $s): string
    {
        $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u'];
        return strtr($s, $map);
    }

    /** @return int ID de la nueva definición. */
    public static function create(int $businessId, int $actorUserId, array $data): int
    {
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('El campo necesita un nombre.');
        }
        $fieldKey = $data['field_key'] ?? self::slugify($label);
        $fieldKey = preg_replace('/[^a-z0-9_]/', '', strtolower($fieldKey));
        $fieldType = in_array($data['field_type'] ?? 'text', ['text', 'number', 'date', 'select'], true)
            ? $data['field_type'] : 'text';
        $options = null;
        if ($fieldType === 'select' && !empty($data['options'])) {
            $opts = is_array($data['options']) ? $data['options'] : array_map('trim', explode(',', (string)$data['options']));
            $options = json_encode(array_values(array_filter($opts, fn($o) => $o !== '')));
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM attribute_definitions WHERE business_id = ?');
        $stmt->execute([$businessId]);
        $nextOrder = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, options, is_required, show_in_table, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $businessId, $actorUserId, $fieldKey, $label, $fieldType, $options,
            !empty($data['is_required']) ? 1 : 0,
            array_key_exists('show_in_table', $data) ? (!empty($data['show_in_table']) ? 1 : 0) : 1,
            $nextOrder,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, int $businessId, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['label', 'field_type', 'is_required', 'show_in_table', 'sort_order'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$col];
            }
        }
        if (array_key_exists('options', $data)) {
            $opts = is_array($data['options']) ? $data['options'] : array_map('trim', explode(',', (string)$data['options']));
            $fields[] = 'options = ?';
            $params[] = json_encode(array_values(array_filter($opts, fn($o) => $o !== '')));
        }
        if (!$fields) {
            return;
        }
        $params[] = $id;
        $params[] = $businessId;
        $sql = 'UPDATE attribute_definitions SET ' . implode(', ', $fields) . ' WHERE id = ? AND business_id = ?';
        Database::connection()->prepare($sql)->execute($params);
    }

    public static function delete(int $id, int $businessId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM attribute_definitions WHERE id = ? AND business_id = ?');
        $stmt->execute([$id, $businessId]);
    }

    public static function reorder(int $businessId, array $orderedIds): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE attribute_definitions SET sort_order = ? WHERE id = ? AND business_id = ?');
        foreach (array_values($orderedIds) as $i => $id) {
            $stmt->execute([$i, (int)$id, $businessId]);
        }
    }

    /** Aplica una plantilla (config/attribute_templates.php) creando sus campos para el negocio. */
    public static function applyTemplate(int $businessId, int $actorUserId, string $templateKey): void
    {
        $templates = require dirname(__DIR__, 2) . '/config/attribute_templates.php';
        if (!isset($templates[$templateKey])) {
            return;
        }
        foreach ($templates[$templateKey]['fields'] as $field) {
            self::create($businessId, $actorUserId, $field);
        }
    }
}
