<?php

declare(strict_types=1);

namespace Iter\Inventory\Model;

use Iter\Catalog\Model\ProductService;
use Iter\Core\Db\Connection;
use PDO;

/**
 * Product quantities per source. Salable quantity = sum of the quantities marked
 * "in stock" (status 1) in enabled sources; it's computed on read, never stored.
 * A zero quantity in stock isn't stored either: no row means 0, in stock.
 */
class StockService
{
    /** @return array<int, array{source_id: int, code: string, name: string, is_enabled: int, quantity: string, status: int}> every source, by priority */
    public function getItems(int $productId): array
    {
        $stmt = Connection::get()->connect()->prepare(
            'SELECT s.source_id, s.code, s.name, s.is_enabled, COALESCE(i.quantity, 0) AS quantity, COALESCE(i.status, 1) AS status
             FROM inventory_source s
             LEFT JOIN inventory_source_item i ON i.source_id = s.source_id AND i.product_id = :id
             ORDER BY s.priority, s.source_id'
        );
        $stmt->execute(['id' => $productId]);

        return array_map(static fn ($row) => ['quantity' => self::format($row['quantity'])] + $row, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Sources left out of $items keep what they have. Everything is validated
     * before anything is written.
     *
     * @param array<string, array{quantity?: mixed, status?: mixed}> $items source code => values
     */
    public function save(int $productId, array $items): void
    {
        (new ProductService())->get($productId); // PRODUCT_NOT_FOUND
        $sources = array_column((new Source())->getResource()->getList(), null, 'code');

        $rows = [];
        foreach ($items as $code => $item) {
            $source = $sources[$code] ?? throw new InventoryException('SOURCE_NOT_FOUND', "Origem '{$code}' não encontrada.");
            // pt-BR "1,5"; empty = 0
            $quantity = str_replace(',', '.', trim((string) ($item['quantity'] ?? ''))) ?: '0';
            if (!preg_match('/^\d{1,8}(\.\d{1,4})?$/', $quantity)) {
                throw new InventoryException('QUANTITY_INVALID', "Quantidade inválida em '{$source['name']}': use um número positivo com até 4 casas decimais.");
            }
            $status = (string) ($item['status'] ?? '1');
            if (!in_array($status, ['0', '1'], true)) {
                throw new InventoryException('STATUS_INVALID', "Situação inválida em '{$source['name']}'.");
            }
            $rows[] = ['source_id' => (int) $source['source_id'], 'quantity' => $quantity, 'status' => (int) $status];
        }

        $pdo = Connection::get()->connect();
        $delete = $pdo->prepare('DELETE FROM inventory_source_item WHERE source_id = :source_id AND product_id = :product_id');
        $upsert = $pdo->prepare(
            'INSERT INTO inventory_source_item (source_id, product_id, quantity, status) VALUES (:source_id, :product_id, :quantity, :status)
             ON CONFLICT (source_id, product_id) DO UPDATE SET quantity = EXCLUDED.quantity, status = EXCLUDED.status, updated_at = NOW()'
        );
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                (float) $row['quantity'] === 0.0 && $row['status'] === 1
                    ? $delete->execute(['source_id' => $row['source_id'], 'product_id' => $productId])
                    : $upsert->execute($row + ['product_id' => $productId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * One query for many products.
     *
     * @param int[] $productIds
     * @return array<int, string> product id => salable quantity (every id, 0 when there's none)
     */
    public function getSalable(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) {
            return [];
        }
        $rows = Connection::get()->connect()->query(
            'SELECT i.product_id, SUM(i.quantity)
             FROM inventory_source_item i
             JOIN inventory_source s ON s.source_id = i.source_id AND s.is_enabled = 1
             WHERE i.status = 1 AND i.product_id IN (' . implode(',', $ids) . ')
             GROUP BY i.product_id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map(static fn ($id) => self::format($rows[$id] ?? '0'), array_combine($ids, $ids));
    }

    /** "12.5000" -> "12.5", "3.0000" -> "3" */
    public static function format(string|int|float $quantity): string
    {
        $quantity = (string) $quantity;

        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** "Estoque" column of the product list (Catalog's columns slot): product id => "12,5" */
    public static function salableColumn(array $productIds): array
    {
        return array_map(static fn ($q) => str_replace('.', ',', $q), (new self())->getSalable($productIds));
    }
}
