<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use PDO;

class AttributeDefinition
{
    /** Campos CANÓNICOS: su valor vive en una columna de inventory_items. */
    public const STORAGE_COLUMN = 'column';
    /** Campos PERSONALIZADOS: su valor vive en inventory_items.attributes (JSON). */
    public const STORAGE_JSON = 'json';

    /**
     * Único rol semántico con consumidor real hoy: marca cuál campo identifica al
     * producto. No se agregan más hasta que algo los use.
     */
    public const ROLE_PRODUCT_NAME = 'product_name';
    public const ROLES = [self::ROLE_PRODUCT_NAME];

    /**
     * Campos personalizados del negocio (los que viven en el JSON).
     *
     * CONSERVA a propósito el significado que tenía antes de la Fase 3A. La tabla
     * ahora también guarda los campos canónicos, pero este método sigue devolviendo
     * solo los del JSON, así que sus consumidores —el importador y
     * api/attributes.php— siguen viendo exactamente lo mismo que antes.
     *
     * Si devolviera todo, el importador intentaría meter "Producto" o "Costo" dentro
     * del JSON, y la tabla de inventario dibujaría columnas duplicadas.
     */
    public static function listForBusiness(int $businessId): array
    {
        return self::query($businessId, self::STORAGE_JSON);
    }

    /** Registro COMPLETO: canónicos + personalizados. Lo consumirán las fases 3B y 3C. */
    public static function listRegistry(int $businessId): array
    {
        return self::query($businessId, null);
    }

    /** Solo los campos canónicos. */
    public static function listCanonical(int $businessId): array
    {
        return self::query($businessId, self::STORAGE_COLUMN);
    }

    private static function query(int $businessId, ?string $storage): array
    {
        $sql = 'SELECT * FROM attribute_definitions WHERE business_id = ?';
        $params = [$businessId];
        if ($storage !== null) {
            $sql .= ' AND storage = ?';
            $params[] = $storage;
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'decorate'], $stmt->fetchAll());
    }

    private static function decorate(array $row): array
    {
        $row['options'] = $row['options'] ? json_decode($row['options'], true) : null;
        $row['is_required'] = (bool)$row['is_required'];
        $row['show_in_table'] = (bool)$row['show_in_table'];
        // Metadatos de la Fase 3A. Se exponen ya para que 3B y 3C no tengan que tocar
        // este método, pero todavía nadie los lee.
        $row['filterable'] = (bool)($row['filterable'] ?? 1);
        $row['analytics_enabled'] = (bool)($row['analytics_enabled'] ?? 1);
        $row['is_canonical'] = ($row['storage'] ?? self::STORAGE_JSON) === self::STORAGE_COLUMN;
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
        // BUG PREEXISTENTE: la comprobación usaba ?? pero la asignación no, así que
        // crear un campo sin field_type leía una clave inexistente y guardaba NULL.
        // 'computed' NO se admite aquí a propósito: los campos derivados los siembra la
        // migración, no el usuario.
        $fieldType = $data['field_type'] ?? 'text';
        if (!in_array($fieldType, ['text', 'number', 'date', 'select'], true)) {
            $fieldType = 'text';
        }
        $options = null;
        if ($fieldType === 'select' && !empty($data['options'])) {
            $opts = is_array($data['options']) ? $data['options'] : array_map('trim', explode(',', (string)$data['options']));
            $options = json_encode(array_values(array_filter($opts, fn($o) => $o !== '')));
        }

        $pdo = Database::connection();
        // El orden se calcula dentro del GRUPO de campos personalizados: los canónicos
        // ocupan 1..14 y viven en su propio grupo, así que incluirlos aquí haría que un
        // campo nuevo saltara a 15 y se moviera de lugar en la interfaz.
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM attribute_definitions
             WHERE business_id = ? AND storage = ?'
        );
        $stmt->execute([$businessId, self::STORAGE_JSON]);
        $nextOrder = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, storage, options, is_required, show_in_table, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            // Un campo creado desde la interfaz SIEMPRE es personalizado: los canónicos
            // solo los siembra la migración, nunca el usuario.
            $businessId, $actorUserId, $fieldKey, $label, $fieldType, self::STORAGE_JSON, $options,
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

    /**
     * Borra un campo PERSONALIZADO.
     *
     * Los canónicos no se pueden borrar: su valor vive en una columna del esquema y
     * el sistema depende de ellos (sale_date define el estado, cost alimenta los
     * cálculos). Un negocio puede ocultarlos o renombrarlos, no eliminarlos.
     * El filtro va en el WHERE y no en una comprobación previa para que sea atómico.
     */
    public static function delete(int $id, int $businessId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM attribute_definitions WHERE id = ? AND business_id = ? AND storage = ?'
        );
        $stmt->execute([$id, $businessId, self::STORAGE_JSON]);
    }

    /**
     * Reordena campos PERSONALIZADOS.
     *
     * Acotado a storage='json' por la misma razón que create() calcula su orden ahí:
     * los dos grupos tienen numeración independiente, y renumerar desde 0 sobre ids
     * mezclados dejaría a los canónicos con órdenes duplicados.
     */
    public static function reorder(int $businessId, array $orderedIds): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE attribute_definitions SET sort_order = ?
             WHERE id = ? AND business_id = ? AND storage = ?'
        );
        foreach (array_values($orderedIds) as $i => $id) {
            $stmt->execute([$i, (int)$id, $businessId, self::STORAGE_JSON]);
        }
    }

    /**
     * Siembra los campos canónicos de un negocio recién creado.
     *
     * Reproduce en PHP lo que hizo sql/006 para los negocios existentes, para que un
     * negocio nuevo no nazca sin registro. Es idempotente (INSERT IGNORE + la clave
     * única business_id/field_key), así que llamarla dos veces no duplica nada.
     */
    public static function seedCanonical(int $businessId, int $ownerUserId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO attribute_definitions
                (business_id, user_id, field_key, label, field_type, storage, semantic_role,
                 is_required, show_in_table, filterable, analytics_enabled, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (self::CANONICAL_SEED as $i => $c) {
            $stmt->execute([
                $businessId, $ownerUserId, $c[0], $c[1], $c[2], self::STORAGE_COLUMN, $c[3],
                $c[4], $c[5], $c[6], $c[7], $i + 1,
            ]);
        }
    }

    /**
     * Definición de los campos canónicos. Espejo de sql/006_field_registry.sql.
     *
     * Deliberadamente NO incluye los campos internos (id, business_id, user_id,
     * supplier_id, purchase_order_id, created_at, updated_at): ésos son integridad
     * del sistema, no vocabulario del negocio, y nadie debe poder ocultarlos ni
     * renombrarlos. Registrar solo lo que el negocio puede configurar es lo que hace
     * que esa frontera sea imposible de cruzar por accidente.
     *
     * [field_key, label, field_type, semantic_role, required, visible, filterable, analytics]
     */
    private const CANONICAL_SEED = [
        ['name',          'Producto',          'text',     self::ROLE_PRODUCT_NAME, 1, 1, 1, 1],
        ['variant_label', 'Variante',          'text',     null,                    0, 0, 1, 1],
        ['category',      'Categoría',         'text',     null,                    0, 0, 1, 1],
        ['subcategory',   'Subcategoría',      'text',     null,                    0, 0, 1, 1],
        ['supplier',      'Proveedor',         'text',     null,                    0, 0, 1, 1],
        ['order_number',  'Pedido',            'text',     null,                    0, 1, 1, 1],
        ['cost',          'Costo',             'number',   null,                    0, 1, 0, 0],
        ['shipping_cost', 'Envío',             'number',   null,                    0, 1, 0, 0],
        ['total_cost',    'Costo total',       'computed', null,                    0, 1, 0, 0],
        ['sale_price',    'Venta',             'number',   null,                    0, 1, 0, 0],
        ['profit',        'Ganancia',          'computed', null,                    0, 1, 0, 0],
        ['purchase_date', 'Fecha de compra',   'date',     null,                    0, 0, 1, 0],
        ['arrival_date',  'Fecha de llegada',  'date',     null,                    0, 0, 1, 0],
        ['sale_date',     'Fecha de venta',    'date',     null,                    0, 0, 1, 0],
    ];

    /**
     * El campo marcado como product_name, o null.
     *
     * Lo consumirá la Fase 3C: hoy SchemaSemantics asume que el producto es 'name'
     * y Analytics::sinStock usa ese valor por omisión. Con esto deja de ser un
     * supuesto del código y pasa a ser configuración del negocio.
     */
    public static function productNameField(int $businessId): ?array
    {
        foreach (self::listRegistry($businessId) as $d) {
            if (($d['semantic_role'] ?? null) === self::ROLE_PRODUCT_NAME) {
                return $d;
            }
        }
        return null;
    }

    /**
     * Asigna un rol semántico, garantizando que no haya dos campos con el mismo.
     *
     * La unicidad se impone aquí y no con un índice en la base: un índice único sobre
     * una columna nullable admite varios NULL en MariaDB, así que habría necesitado
     * una columna auxiliar o un índice funcional — complejidad de migración sin
     * ganancia real, siendo éste el único punto de escritura del rol.
     */
    public static function assignRole(int $id, int $businessId, ?string $role): void
    {
        if ($role !== null && !in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Rol semántico no reconocido: ' . $role);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Se libera el rol de quien lo tuviera: así nunca hay dos, ni por una
            // condición de carrera entre dos empleados configurando a la vez.
            if ($role !== null) {
                $pdo->prepare(
                    'UPDATE attribute_definitions SET semantic_role = NULL
                     WHERE business_id = ? AND semantic_role = ? AND id <> ?'
                )->execute([$businessId, $role, $id]);
            }
            $pdo->prepare(
                'UPDATE attribute_definitions SET semantic_role = ? WHERE id = ? AND business_id = ?'
            )->execute([$role, $id, $businessId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
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
