<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Models\AttributeDefinition;
use App\Models\InventoryItem;

/** Mapeo de columnas de Excel <-> filas de inventory_items, tolerante a los encabezados del app original. */
class ImportExportService
{
    private const FIXED_ALIASES = [
        'name' => ['Nombre', 'Equipo', 'equipo', 'Producto'],
        'variant_label' => ['Jugador', 'Variante', 'Variación'],
        'category' => ['Categoría', 'Categoria', 'Deporte', 'deporte'],
        'subcategory' => ['Subcategoría', 'Subcategoria'],
        'supplier' => ['Proveedor'],
        'cost' => ['Costo (MXN)', 'Costo total (MXN)', 'Costo'],
        'shipping_cost' => ['Envío (MXN)', 'Envio (MXN)', 'Costo de envio'],
        'sale_price' => ['Venta (MXN)', 'Venta'],
        'purchase_date' => ['Fecha de compra'],
        'arrival_date' => ['Fecha de llegada', 'Fecha llegada'],
        'sale_date' => ['Fecha de Venta', 'Fecha de venta'],
    ];

    /** Filas aplanadas para exportar: columnas fijas legibles + una columna por atributo del negocio. */
    public static function exportRows(int $businessId): array
    {
        $attrDefs = AttributeDefinition::listForBusiness($businessId);
        $items = InventoryItem::list($businessId, ['sort' => 'created_at', 'dir' => 'asc']);

        return array_map(function ($item) use ($attrDefs) {
            $row = [
                'Pedido' => $item['order_number'],
                'Proveedor' => $item['supplier_name'],
                'Fecha de compra' => $item['purchase_date'],
                'Fecha de llegada' => $item['arrival_date'],
                'Fecha de venta' => $item['sale_date'],
                'Nombre' => $item['name'],
                'Variante' => $item['variant_label'],
                'Categoría' => $item['category'],
                'Subcategoría' => $item['subcategory'],
            ];
            foreach ($attrDefs as $def) {
                $row[$def['label']] = $item['attributes'][$def['field_key']] ?? null;
            }
            $row['Costo (MXN)'] = $item['cost'];
            $row['Envío (MXN)'] = $item['shipping_cost'];
            $row['Costo total (MXN)'] = $item['total_cost'];
            $row['Venta (MXN)'] = $item['sale_price'];
            $row['Ganancia (MXN)'] = $item['profit'];
            return $row;
        }, $items);
    }

    /**
     * Mapea una fila cruda de Excel (claves = encabezados) a los datos que espera InventoryItem::create().
     * Prioridad: primero intenta hacer match con la ETIQUETA de un atributo definido por el usuario,
     * y solo si no coincide con ninguno, cae a los alias de columnas fijas.
     */
    public static function mapImportRow(array $rawRow, array $attrDefs): ?array
    {
        $normalized = [];
        foreach ($rawRow as $key => $value) {
            $normalized[self::normalizeHeader($key)] = $value;
        }

        $attributes = [];
        $consumedHeaders = [];
        foreach ($attrDefs as $def) {
            $h = self::normalizeHeader($def['label']);
            if (array_key_exists($h, $normalized) && $normalized[$h] !== '' && $normalized[$h] !== null) {
                $attributes[$def['field_key']] = (string)$normalized[$h];
                $consumedHeaders[$h] = true;
            }
        }

        $data = ['attributes' => $attributes];
        foreach (self::FIXED_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $h = self::normalizeHeader($alias);
                if (isset($consumedHeaders[$h])) {
                    continue;
                }
                if (array_key_exists($h, $normalized) && $normalized[$h] !== '' && $normalized[$h] !== null) {
                    $data[$field] = self::coerce($field, $normalized[$h]);
                    break;
                }
            }
        }

        if (empty($data['name'])) {
            return null;
        }
        return $data;
    }

    public static function importRows(int $businessId, int $actorUserId, array $rawRows): array
    {
        $attrDefs = AttributeDefinition::listForBusiness($businessId);
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rawRows as $i => $rawRow) {
            $data = self::mapImportRow($rawRow, $attrDefs);
            if ($data === null) {
                $skipped++;
                continue;
            }
            try {
                InventoryItem::create($businessId, $actorUserId, $data);
                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $i + 1, 'reason' => $e->getMessage()];
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    private static function normalizeHeader(string $h): string
    {
        $h = trim(mb_strtolower($h));
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'];
        return strtr($h, $map);
    }

    private static function coerce(string $field, $value)
    {
        if (in_array($field, ['cost', 'shipping_cost', 'sale_price'], true)) {
            $num = preg_replace('/[^0-9.\-]/', '', (string)$value);
            return $num === '' ? null : (float)$num;
        }
        if (in_array($field, ['purchase_date', 'arrival_date', 'sale_date'], true)) {
            return self::dateOrNull($value);
        }
        return trim((string)$value);
    }

    private static function dateOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
