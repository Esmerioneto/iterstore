<?php

declare(strict_types=1);

namespace Iter\Inventory\Model;

use Iter\Catalog\Model\ProductService;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;

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
        $db = Connection::get()->adapter();
        $select = $db->select()->from(['s' => 'inventory_source'], ['source_id', 'code', 'name', 'is_enabled']);
        $select->joinLeft(['i' => 'inventory_source_item'], 'i.source_id = s.source_id AND i.product_id = ' . $select->bindValue($productId), [
            'quantity' => 'COALESCE(i.quantity, 0)',
            'status' => 'COALESCE(i.status, 1)',
        ])->order('s.priority, s.source_id');

        return array_map(static fn ($row) => ['quantity' => self::format($row['quantity'])] + $row, $db->fetchAll($select));
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

        Connection::get()->adapter()->transaction(static function ($db) use ($rows, $productId): void {
            foreach ($rows as $row) {
                (float) $row['quantity'] === 0.0 && $row['status'] === 1
                    ? $db->delete('inventory_source_item', ['source_id = ?' => $row['source_id'], 'product_id = ?' => $productId])
                    : $db->insertOnConflict('inventory_source_item', $row + ['product_id' => $productId], ['source_id', 'product_id'], ['quantity', 'status', 'updated_at' => new Expr('NOW()')]);
            }
        });
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
        $db = Connection::get()->adapter();
        $rows = $db->fetchPairs($db->select()
            ->from(['i' => 'inventory_source_item'], ['product_id', 'SUM(i.quantity)'])
            ->join(['s' => 'inventory_source'], 's.source_id = i.source_id AND s.is_enabled = 1', [])
            ->where('i.status = 1')
            ->where('i.product_id IN (?)', $ids)
            ->group('i.product_id'));

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
