<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Services\PricingService;

class PurchaseOrder
{
    /** Debe llamarse dentro de una transacción ya abierta — usa FOR UPDATE para serializar
     *  la generación de números de pedido entre empleados concurrentes del mismo negocio. */
    private static function nextOrderNumberLocked(\PDO $pdo, int $businessId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(order_number), 0) + 1 FROM purchase_orders WHERE business_id = ? FOR UPDATE');
        $stmt->execute([$businessId]);
        return (int)$stmt->fetchColumn();
    }

    public static function nextOrderNumber(int $businessId): int
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(MAX(order_number), 0) + 1 FROM purchase_orders WHERE business_id = ?');
        $stmt->execute([$businessId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Reparte el envío del pedido entre las piezas SIN perder ni inventar centavos.
     *
     * El reparto anterior era `round(total / n)` para todas las piezas: con $1,000
     * entre 3 daba $333 a cada una y el pedido "costaba" $999. Un peso por pedido
     * parece poco, pero es un error de contabilidad — la suma de los costos por pieza
     * debe ser exactamente lo que se pagó.
     *
     * Se trabaja en CENTAVOS enteros para no arrastrar el error de los flotantes, y el
     * residuo se reparte de a un centavo entre las primeras piezas. Así todas quedan
     * a lo sumo a un centavo de distancia y la suma cuadra exacto.
     *
     * @return float[] monto por pieza, en el mismo orden que $items
     */
    private static function splitShipping(float $totalMxn, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $centavos = (int)round($totalMxn * 100);
        $base = intdiv($centavos, $count);
        $resto = $centavos - ($base * $count);

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            // Las primeras $resto piezas llevan un centavo extra.
            $out[] = ($base + ($i < $resto ? 1 : 0)) / 100;
        }
        return $out;
    }
    /**
     * Devuelve el id del pedido con ese número, creándolo si no existe.
     *
     * Existe para la importación: en un Excel el pedido viene como un NÚMERO ("#12"),
     * no como el id interno de la base. Sin esta traducción, el número de pedido de un
     * archivo importado no tenía a dónde llegar y se perdía — que es exactamente por lo
     * que 739 piezas quedaron huérfanas de pedido.
     *
     * Es idempotente a propósito: importar 50 filas del pedido #12 crea UN pedido, no 50.
     */
    public static function resolveOrCreate(int $businessId, int $actorUserId, $orderNumber, array $header = []): ?int
    {
        if ($orderNumber === null || $orderNumber === '') {
            return null;
        }
        $number = (int)preg_replace('/[^0-9]/', '', (string)$orderNumber);
        if ($number <= 0) {
            return null;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM purchase_orders WHERE business_id = ? AND order_number = ?');
        $stmt->execute([$businessId, $number]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }

        $supplierId = Supplier::resolveOrCreate($businessId, $actorUserId, $header['supplier'] ?? null);
        $ins = $pdo->prepare(
            'INSERT INTO purchase_orders
             (business_id, user_id, order_number, supplier_id, purchase_date, arrival_date, item_count)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $ins->execute([
            $businessId, $actorUserId, $number, $supplierId,
            ($header['purchase_date'] ?? null) ?: null,
            ($header['arrival_date'] ?? null) ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Recalcula item_count desde las piezas realmente vinculadas. La columna es un
     *  contador denormalizado: si nadie lo recalcula tras importar, miente. */
    public static function refreshItemCount(int $businessId, int $purchaseOrderId): void
    {
        Database::connection()
            ->prepare(
                'UPDATE purchase_orders po
                 SET item_count = (SELECT COUNT(*) FROM inventory_items i WHERE i.purchase_order_id = po.id)
                 WHERE po.id = ? AND po.business_id = ?'
            )
            ->execute([$purchaseOrderId, $businessId]);
    }
    /**
     * Crea un pedido de compra (lote) junto con sus piezas de inventario, en una transacción.
     * $header: order_number?, supplier?, purchase_date?, arrival_date?, currency, exchange_rate, shipping_total
     * $items: [{name, variant_label?, category?, subcategory?, cost?, sale_price?, attributes?}, ...]
     * @return array{purchase_order_id:int, item_ids:int[]}
     */
    public static function createWithItems(int $businessId, int $actorUserId, array $header, array $items): array
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('El pedido necesita al menos un producto.');
        }

        $currency = ($header['currency'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN';
        $exchangeRate = (float)($header['exchange_rate'] ?? 1);
        $factor = $currency === 'USD' ? $exchangeRate : 1.0;
        $shippingTotalOriginal = (float)($header['shipping_total'] ?? 0);
        $shippingTotalMxn = PricingService::toMxn($shippingTotalOriginal, $currency, $exchangeRate);
        // Las filas sin nombre se descartan más abajo. Si el envío se dividiera entre
        // TODAS las filas recibidas, una fila vacía se llevaría su parte y el reparto
        // no sumaría el envío del pedido.
        $items = array_values(array_filter(
            $items,
            fn($i) => trim((string)($i['name'] ?? '')) !== ''
        ));
        $count = count($items);
        $shippingPerUnit = self::splitShipping($shippingTotalMxn, $count);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $supplierId = Supplier::resolveOrCreate($businessId, $actorUserId, $header['supplier'] ?? null);
            $orderNumber = !empty($header['order_number'])
                ? (int)$header['order_number']
                : self::nextOrderNumberLocked($pdo, $businessId);

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders
                 (business_id, user_id, order_number, supplier_id, purchase_date, arrival_date, currency, exchange_rate,
                  shipping_total_original, shipping_total_mxn, item_count, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $businessId, $actorUserId, $orderNumber, $supplierId,
                ($header['purchase_date'] ?? null) ?: null, ($header['arrival_date'] ?? null) ?: null,
                $currency, $exchangeRate, $shippingTotalOriginal, $shippingTotalMxn, $count,
                // La columna existía desde el esquema original pero nadie la escribía.
                ($header['notes'] ?? null) ?: null,
            ]);
            $poId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO inventory_items
                 (business_id, user_id, purchase_order_id, supplier_id, name, variant_label, category, subcategory,
                  attributes, cost, shipping_cost, sale_price, purchase_date, arrival_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $itemIds = [];
            foreach ($items as $idx => $item) {
                $name = trim((string)($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $cost = isset($item['cost']) && $item['cost'] !== '' ? round(((float)$item['cost']) * $factor) : null;
                $salePrice = isset($item['sale_price']) && $item['sale_price'] !== '' ? round(((float)$item['sale_price']) * $factor) : null;
                $attributes = !empty($item['attributes']) ? json_encode($item['attributes']) : null;

                $itemStmt->execute([
                    $businessId, $actorUserId, $poId, $supplierId,
                    $name, $item['variant_label'] ?? null, $item['category'] ?? null, $item['subcategory'] ?? null,
                    $attributes, $cost, $shippingPerUnit[$idx] ?? 0, $salePrice,
                    ($header['purchase_date'] ?? null) ?: null, ($header['arrival_date'] ?? null) ?: null,
                ]);
                $itemIds[] = (int)$pdo->lastInsertId();
            }

            if (empty($itemIds)) {
                throw new \InvalidArgumentException('Ningún producto tenía nombre válido.');
            }

            $pdo->commit();
            return ['purchase_order_id' => $poId, 'item_ids' => $itemIds];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ===============================================================
    // LEER, EDITAR Y BORRAR UN PEDIDO YA REGISTRADO
    //
    // Hasta aquí, un pedido solo se podía CREAR. Nació como efecto secundario de la
    // captura: createWithItems() lo insertaba y nadie lo volvía a mirar. Eso dejaba
    // sin salida el error más común — "registré el pedido 45 y me equivoqué" — porque
    // la única forma de deshacerlo era borrar las piezas una por una en Inventario,
    // y el pedido sobrevivía como fantasma en el filtro y en la gráfica.
    // ===============================================================

    /**
     * Cifras de un pedido, calculadas SIEMPRE desde sus piezas.
     *
     * POR QUÉ NO SE USA item_count
     * Esa columna es un contador denormalizado que solo se actualiza si alguien llama
     * a refreshItemCount(). Si una pieza se borra desde Inventario, nadie lo llama y
     * la columna miente. Un COUNT() sobre las piezas no puede desincronizarse, y a
     * esta escala su costo es irrelevante frente a mostrar un número falso.
     *
     * El envío se suma de las PIEZAS, no de shipping_total_mxn del encabezado, por la
     * misma razón: es lo que de verdad está cargado a los costos.
     */
    private const AGREGADOS = "
        (SELECT COUNT(*) FROM inventory_items i WHERE i.purchase_order_id = po.id) AS piezas,
        (SELECT COUNT(*) FROM inventory_items i WHERE i.purchase_order_id = po.id AND i.sale_date IS NOT NULL) AS vendidas,
        (SELECT COALESCE(SUM(i.cost), 0) + COALESCE(SUM(i.shipping_cost), 0)
           FROM inventory_items i WHERE i.purchase_order_id = po.id) AS costo_total,
        (SELECT COALESCE(SUM(i.sale_price), 0)
           FROM inventory_items i WHERE i.purchase_order_id = po.id AND i.sale_date IS NOT NULL) AS recuperado";

    /** Convierte los agregados a números y agrega el neto, que es una resta y no un dato. */
    private static function decorar(array $row): array
    {
        $row['piezas'] = (int)$row['piezas'];
        $row['vendidas'] = (int)$row['vendidas'];
        $row['disponibles'] = $row['piezas'] - $row['vendidas'];
        $row['costo_total'] = (float)$row['costo_total'];
        $row['recuperado'] = (float)$row['recuperado'];
        $row['neto'] = round($row['recuperado'] - $row['costo_total'], 2);
        // Con esto la pantalla no tiene que volver a deducir la regla de borrado.
        $row['puede_borrarse'] = $row['vendidas'] === 0;
        return $row;
    }

    /** @param array{q?:string} $filters */
    public static function list(int $businessId, array $filters = []): array
    {
        $sql = 'SELECT po.*, s.name AS supplier_name,' . self::AGREGADOS . '
                  FROM purchase_orders po
                  LEFT JOIN suppliers s ON s.id = po.supplier_id
                 WHERE po.business_id = ?';
        $params = [$businessId];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            // Se busca por número o por proveedor: son las dos formas en que alguien
            // se refiere a un pedido de memoria.
            $sql .= ' AND (CAST(po.order_number AS CHAR) LIKE ? OR s.name LIKE ?)';
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }
        $sql .= ' ORDER BY po.order_number DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'decorar'], $stmt->fetchAll());
    }

    public static function find(int $id, int $businessId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT po.*, s.name AS supplier_name,' . self::AGREGADOS . '
               FROM purchase_orders po
               LEFT JOIN suppliers s ON s.id = po.supplier_id
              WHERE po.id = ? AND po.business_id = ?'
        );
        $stmt->execute([$id, $businessId]);
        $row = $stmt->fetch();
        return $row ? self::decorar($row) : null;
    }

    /**
     * Edita el ENCABEZADO de un pedido y propaga a sus piezas lo que les corresponde.
     *
     * QUÉ NO SE PUEDE EDITAR, Y POR QUÉ
     * La moneda y el tipo de cambio quedan fijos. El costo de cada pieza se guardó ya
     * convertido a pesos y REDONDEADO en el momento de la captura; el valor original
     * en dólares no está almacenado en ningún lado. Cambiar el tipo de cambio
     * obligaría a reconstruirlo dividiendo, y el redondeo hace que esa división no
     * devuelva el número que se capturó. Es preferible un dato honesto y fijo a uno
     * recalculado y falso.
     *
     * CÓMO SE PROPAGAN LAS FECHAS Y EL PROVEEDOR
     * Cada pieza guarda su propia copia de la fecha de compra, la de llegada y el
     * proveedor. Solo se actualizan las piezas que TODAVÍA tienen el valor viejo del
     * encabezado. Es la única regla que respeta las dos intenciones posibles:
     *
     *   · La pieza nunca se tocó individualmente → heredó el valor del encabezado y
     *     debe seguir heredándolo. Si no, corregir la fecha del pedido no cambiaría
     *     nada visible y quedarían dos verdades sobre el mismo hecho.
     *   · Alguien ya le puso otra fecha a esa pieza (por ejemplo con la llegada por
     *     lote, porque llegó en un segundo envío) → esa decisión es más específica
     *     que el encabezado y no se pisa.
     */
    public static function updateHeader(int $businessId, int $actorUserId, int $id, array $data): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? AND business_id = ? FOR UPDATE');
            $stmt->execute([$id, $businessId]);
            $antes = $stmt->fetch();
            if (!$antes) {
                throw new \InvalidArgumentException('Ese pedido no existe en este negocio.');
            }

            $sets = [];
            $params = [];

            // --- Número de pedido ---
            if (array_key_exists('order_number', $data)) {
                $num = (int)preg_replace('/[^0-9]/', '', (string)$data['order_number']);
                if ($num <= 0) {
                    throw new \InvalidArgumentException('El número de pedido debe ser mayor que cero.');
                }
                if ($num !== (int)$antes['order_number']) {
                    $dup = $pdo->prepare(
                        'SELECT id FROM purchase_orders WHERE business_id = ? AND order_number = ? AND id <> ?'
                    );
                    $dup->execute([$businessId, $num, $id]);
                    if ($dup->fetchColumn() !== false) {
                        // Se revisa antes de escribir para dar un mensaje con el número
                        // en vez de dejar salir la violación de la clave única.
                        throw new \InvalidArgumentException("Ya existe el pedido #{$num} en este negocio.");
                    }
                    $sets[] = 'order_number = ?';
                    $params[] = $num;
                }
            }

            // --- Proveedor ---
            $proveedorNuevo = null;
            if (array_key_exists('supplier', $data)) {
                $proveedorNuevo = Supplier::resolveOrCreate($businessId, $actorUserId, (string)$data['supplier']);
                $sets[] = 'supplier_id = ?';
                $params[] = $proveedorNuevo;
            }

            // --- Fechas y notas ---
            foreach (['purchase_date', 'arrival_date'] as $campo) {
                if (array_key_exists($campo, $data)) {
                    $sets[] = "{$campo} = ?";
                    $params[] = ($data[$campo] ?? '') !== '' ? $data[$campo] : null;
                }
            }
            if (array_key_exists('notes', $data)) {
                $sets[] = 'notes = ?';
                $params[] = trim((string)$data['notes']) !== '' ? trim((string)$data['notes']) : null;
            }

            // --- Envío: se reparte otra vez entre las piezas ---
            $repartir = array_key_exists('shipping_total', $data);
            $mxn = 0.0;
            if ($repartir) {
                $original = (float)$data['shipping_total'];
                if ($original < 0) {
                    throw new \InvalidArgumentException('El envío no puede ser negativo.');
                }
                $mxn = PricingService::toMxn($original, $antes['currency'], (float)$antes['exchange_rate']);
                $sets[] = 'shipping_total_original = ?';
                $params[] = $original;
                $sets[] = 'shipping_total_mxn = ?';
                $params[] = $mxn;
            }

            if ($sets) {
                $params[] = $id;
                $params[] = $businessId;
                $pdo->prepare('UPDATE purchase_orders SET ' . implode(', ', $sets)
                    . ' WHERE id = ? AND business_id = ?')->execute($params);
            }

            // --- Propagación a las piezas ---
            $piezasTocadas = 0;

            foreach (['purchase_date', 'arrival_date'] as $campo) {
                if (!array_key_exists($campo, $data)) {
                    continue;
                }
                $valor = ($data[$campo] ?? '') !== '' ? $data[$campo] : null;
                if ($valor === $antes[$campo]) {
                    continue;
                }
                // El operador <=> de MariaDB compara tratando NULL como un valor más,
                // así que una pieza con la fecha vacía también cuenta como "todavía
                // tiene la vieja" cuando el encabezado tampoco la tenía.
                $up = $pdo->prepare(
                    "UPDATE inventory_items SET {$campo} = ?
                      WHERE purchase_order_id = ? AND business_id = ? AND {$campo} <=> ?"
                );
                $up->execute([$valor, $id, $businessId, $antes[$campo]]);
                $piezasTocadas += $up->rowCount();
            }

            if (array_key_exists('supplier', $data) && $proveedorNuevo !== (int)$antes['supplier_id']) {
                $up = $pdo->prepare(
                    'UPDATE inventory_items SET supplier_id = ?
                      WHERE purchase_order_id = ? AND business_id = ? AND supplier_id <=> ?'
                );
                $up->execute([$proveedorNuevo, $id, $businessId, $antes['supplier_id']]);
                $piezasTocadas += $up->rowCount();
            }

            // --- Reparto del envío ---
            // Va al final y en orden estable (por id) para que dos ejecuciones con el
            // mismo total den exactamente el mismo reparto, centavo por centavo.
            $repartidas = 0;
            if ($repartir) {
                $ids = $pdo->prepare(
                    'SELECT id FROM inventory_items WHERE purchase_order_id = ? AND business_id = ? ORDER BY id'
                );
                $ids->execute([$id, $businessId]);
                $itemIds = $ids->fetchAll(\PDO::FETCH_COLUMN);

                $porPieza = self::splitShipping($mxn, count($itemIds));
                $setEnvio = $pdo->prepare('UPDATE inventory_items SET shipping_cost = ? WHERE id = ? AND business_id = ?');
                foreach ($itemIds as $i => $itemId) {
                    $setEnvio->execute([$porPieza[$i], $itemId, $businessId]);
                }
                $repartidas = count($itemIds);
            }

            $pdo->commit();
            return [
                'piezas_actualizadas' => $piezasTocadas,
                'envio_repartido_en' => $repartidas,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Agrega una pieza a un pedido que ya existe.
     *
     * No se usa InventoryItem::create() porque una pieza agregada desde aquí debe
     * heredar el proveedor y las fechas del pedido, y porque el envío completo se
     * tiene que volver a repartir entre todas las piezas en la misma transacción.
     */
    public static function addItem(int $businessId, int $actorUserId, int $id, array $item): array
    {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El producto necesita un nombre.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $orderStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? AND business_id = ? FOR UPDATE');
            $orderStmt->execute([$id, $businessId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new \InvalidArgumentException('Ese pedido no existe en este negocio.');
            }

            $factor = $order['currency'] === 'USD' ? (float)$order['exchange_rate'] : 1.0;
            $number = static function ($value) use ($factor): ?float {
                if ($value === null || $value === '') return null;
                if (!is_numeric($value)) throw new \InvalidArgumentException('Los precios deben ser números válidos.');
                return round((float)$value * $factor);
            };
            $attributes = !empty($item['attributes']) && is_array($item['attributes']) ? json_encode($item['attributes']) : null;
            $insert = $pdo->prepare(
                'INSERT INTO inventory_items
                 (business_id, user_id, purchase_order_id, supplier_id, name, variant_label, category, subcategory,
                  attributes, cost, shipping_cost, sale_price, purchase_date, arrival_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            );
            $insert->execute([
                $businessId, $actorUserId, $id, $order['supplier_id'], $name,
                ($item['variant_label'] ?? '') ?: null, ($item['category'] ?? '') ?: null,
                ($item['subcategory'] ?? '') ?: null, $attributes, $number($item['cost'] ?? null),
                $number($item['sale_price'] ?? null), $order['purchase_date'], $order['arrival_date'],
            ]);
            $newItemId = (int)$pdo->lastInsertId();

            self::redistributeShippingLocked($pdo, $businessId, $id, (float)$order['shipping_total_mxn']);
            self::refreshItemCountLocked($pdo, $businessId, $id);
            $pdo->commit();
            return ['item_id' => $newItemId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Agrega varias piezas atómicamente desde la captura completa de Entradas. */
    public static function appendItems(int $businessId, int $actorUserId, int $id, array $items): array
    {
        $items = array_values($items);
        if (!$items) throw new \InvalidArgumentException('Agrega al menos un producto al pedido.');
        // Validar todo antes de insertar la primera pieza: un precio inválido no debe
        // dejar medio grupo registrado.
        foreach ($items as $item) {
            if (trim((string)($item['name'] ?? '')) === '') throw new \InvalidArgumentException('Cada producto necesita un nombre.');
            foreach (['cost', 'sale_price'] as $field) {
                if (isset($item[$field]) && $item[$field] !== '' && !is_numeric($item[$field])) {
                    throw new \InvalidArgumentException('Los precios deben ser números válidos.');
                }
            }
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $orderStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? AND business_id = ? FOR UPDATE');
            $orderStmt->execute([$id, $businessId]);
            $order = $orderStmt->fetch();
            if (!$order) throw new \InvalidArgumentException('Ese pedido no existe en este negocio.');

            $factor = $order['currency'] === 'USD' ? (float)$order['exchange_rate'] : 1.0;
            $insert = $pdo->prepare(
                'INSERT INTO inventory_items
                 (business_id, user_id, purchase_order_id, supplier_id, name, variant_label, category, subcategory,
                  attributes, cost, shipping_cost, sale_price, purchase_date, arrival_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            );
            $ids = [];
            foreach ($items as $item) {
                $toMxn = static fn($v): ?float => ($v === null || $v === '') ? null : round((float)$v * $factor);
                $attrs = !empty($item['attributes']) && is_array($item['attributes']) ? json_encode($item['attributes']) : null;
                $insert->execute([$businessId, $actorUserId, $id, $order['supplier_id'], trim((string)$item['name']),
                    ($item['variant_label'] ?? '') ?: null, ($item['category'] ?? '') ?: null, ($item['subcategory'] ?? '') ?: null,
                    $attrs, $toMxn($item['cost'] ?? null), $toMxn($item['sale_price'] ?? null),
                    $order['purchase_date'], $order['arrival_date']]);
                $ids[] = (int)$pdo->lastInsertId();
            }
            self::redistributeShippingLocked($pdo, $businessId, $id, (float)$order['shipping_total_mxn']);
            self::refreshItemCountLocked($pdo, $businessId, $id);
            $pdo->commit();
            return ['item_ids' => $ids];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Quita una pieza no vendida de un pedido, conservando siempre un pedido válido. */
    public static function removeItem(int $businessId, int $orderId, int $itemId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $orderStmt = $pdo->prepare('SELECT shipping_total_mxn FROM purchase_orders WHERE id = ? AND business_id = ? FOR UPDATE');
            $orderStmt->execute([$orderId, $businessId]);
            $order = $orderStmt->fetch();
            if (!$order) throw new \InvalidArgumentException('Ese pedido no existe en este negocio.');

            $itemStmt = $pdo->prepare('SELECT sale_date FROM inventory_items WHERE id = ? AND purchase_order_id = ? AND business_id = ? FOR UPDATE');
            $itemStmt->execute([$itemId, $orderId, $businessId]);
            $item = $itemStmt->fetch();
            if (!$item) throw new \InvalidArgumentException('Ese producto no pertenece a este pedido.');
            if ($item['sale_date'] !== null) throw new \InvalidArgumentException('No se puede quitar un producto que ya tiene una venta registrada.');

            $count = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE purchase_order_id = ? AND business_id = ?');
            $count->execute([$orderId, $businessId]);
            if ((int)$count->fetchColumn() <= 1) {
                throw new \InvalidArgumentException('No puedes dejar el pedido sin productos. Si fue un error completo, borra el pedido.');
            }
            $pdo->prepare('DELETE FROM inventory_items WHERE id = ? AND purchase_order_id = ? AND business_id = ?')
                ->execute([$itemId, $orderId, $businessId]);
            self::redistributeShippingLocked($pdo, $businessId, $orderId, (float)$order['shipping_total_mxn']);
            self::refreshItemCountLocked($pdo, $businessId, $orderId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Reparte el envío entre las piezas actuales; el llamador ya tiene el pedido bloqueado. */
    private static function redistributeShippingLocked(\PDO $pdo, int $businessId, int $orderId, float $shippingMxn): void
    {
        $ids = $pdo->prepare('SELECT id FROM inventory_items WHERE purchase_order_id = ? AND business_id = ? ORDER BY id');
        $ids->execute([$orderId, $businessId]);
        $itemIds = $ids->fetchAll(\PDO::FETCH_COLUMN);
        $parts = self::splitShipping($shippingMxn, count($itemIds));
        $set = $pdo->prepare('UPDATE inventory_items SET shipping_cost = ? WHERE id = ? AND business_id = ?');
        foreach ($itemIds as $i => $itemId) $set->execute([$parts[$i], $itemId, $businessId]);
    }

    private static function refreshItemCountLocked(\PDO $pdo, int $businessId, int $orderId): void
    {
        $pdo->prepare(
            'UPDATE purchase_orders po SET item_count = (SELECT COUNT(*) FROM inventory_items i WHERE i.purchase_order_id = po.id)
             WHERE po.id = ? AND po.business_id = ?'
        )->execute([$orderId, $businessId]);
    }

    /**
     * Borra un pedido Y sus piezas, o se niega explicando por qué.
     *
     * SE NIEGA SI YA VENDISTE ALGO DE ESE PEDIDO
     * Borrar una pieza vendida no borra un error de captura: borra una venta que sí
     * ocurrió. Las ganancias del mes cambiarían solas y el histórico quedaría
     * mintiendo, sin forma de reconstruirlo. En ese caso queda editar el encabezado,
     * o anular la venta primero si de verdad nunca ocurrió.
     *
     * SE BORRAN LAS PIEZAS EXPLÍCITAMENTE, NO POR LA LLAVE FORÁNEA
     * fk_items_po es ON DELETE SET NULL: un DELETE del pedido a secas dejaría las
     * piezas en el inventario con purchase_order_id en NULL — presentes, contadas en
     * el stock, y sin ningún pedido al que pertenecer. Es exactamente la clase de
     * huérfano que ya costó 739 piezas en la importación. El DELETE de las piezas va
     * primero y a propósito, dentro de la misma transacción.
     */
    public static function deleteWithItems(int $businessId, int $id): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT order_number FROM purchase_orders WHERE id = ? AND business_id = ? FOR UPDATE'
            );
            $stmt->execute([$id, $businessId]);
            $numero = $stmt->fetchColumn();
            if ($numero === false) {
                throw new \InvalidArgumentException('Ese pedido no existe en este negocio.');
            }

            $c = $pdo->prepare(
                'SELECT COUNT(*) FROM inventory_items
                  WHERE purchase_order_id = ? AND business_id = ? AND sale_date IS NOT NULL'
            );
            $c->execute([$id, $businessId]);
            $vendidas = (int)$c->fetchColumn();
            if ($vendidas > 0) {
                $pieza = $vendidas === 1 ? 'pieza ya vendida' : 'piezas ya vendidas';
                throw new \InvalidArgumentException(
                    "El pedido #{$numero} tiene {$vendidas} {$pieza}. Borrarlo destruiría esas ventas: "
                    . 'anula la venta primero, o edita el pedido en vez de borrarlo.'
                );
            }

            $delItems = $pdo->prepare('DELETE FROM inventory_items WHERE purchase_order_id = ? AND business_id = ?');
            $delItems->execute([$id, $businessId]);
            $borradas = $delItems->rowCount();

            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ? AND business_id = ?')->execute([$id, $businessId]);

            $pdo->commit();
            return ['order_number' => (int)$numero, 'piezas_borradas' => $borradas];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
