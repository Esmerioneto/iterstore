<?php

declare(strict_types=1);

namespace Iter\Inventory\Model;

use Iter\Catalog\Model\ProductService;
use Iter\Catalog\Model\VariantService;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;

/**
 * Product quantities per source. Only the sources of the product's websites count:
 * salable quantity = sum of the quantities marked "in stock" (status 1) in their
 * enabled sources; it's computed on read, never stored.
 * A zero quantity in stock isn't stored either: no row means 0, in stock.
 * A configurable product has no stock of its own: its salable quantity is its active variants' sum.
 */
class StockService
{
    /** @return array<int, array{source_id: int, code: string, name: string, is_enabled: int, quantity: string, status: int}> the sources of the product's websites, by priority */
    public function getItems(int $productId): array
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from(['s' => 'inventory_source'], ['source_id', 'code', 'name', 'is_enabled']);
        $select->joinLeft(['i' => 'inventory_source_item'], 'i.source_id = s.source_id AND i.product_id = ' . $select->bindValue($productId), [
            'quantity' => 'COALESCE(i.quantity, 0)',
            'status' => 'COALESCE(i.status, 1)',
        ])->where('s.website_id IN (SELECT website_id FROM catalog_product_website WHERE product_id = ?)', $productId)
            ->order('s.priority, s.source_id');

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
        if ((new ProductService())->get($productId)->getData('type') === 'configurable') { // get(): PRODUCT_NOT_FOUND
            throw new InventoryException('PRODUCT_IS_CONFIGURABLE', 'O estoque de um produto com grade é lançado nas variações.');
        }
        $sources = array_column((new Source())->getResource()->getList(), null, 'code');
        $websiteIds = (new ProductService())->getWebsiteIds($productId);

        $rows = [];
        foreach ($items as $code => $item) {
            $source = $sources[$code] ?? throw new InventoryException('SOURCE_NOT_FOUND', "Origem '{$code}' não encontrada.");
            if (!in_array((int) $source['website_id'], $websiteIds, true)) {
                throw new InventoryException('SOURCE_WEBSITE_MISMATCH', "A origem '{$source['name']}' é de um website em que o produto não está.");
            }
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
     * The "Estoque por grade" panel: one source, a quantity per variant; each keeps its status
     *
     * @param array<int, mixed> $quantities variant id => quantity
     */
    public function saveGrid(int $parentId, string $sourceCode, array $quantities): void
    {
        $variantIds = array_column((new VariantService())->getVariants($parentId), 'product_id');
        Connection::get()->adapter()->transaction(function () use ($variantIds, $sourceCode, $quantities): void {
            foreach ($quantities as $variantId => $quantity) {
                if (!in_array((int) $variantId, $variantIds, true)) {
                    throw new InventoryException('PRODUCT_NOT_FOUND', 'A variação não é deste produto.');
                }
                $status = array_column($this->getItems((int) $variantId), 'status', 'code')[$sourceCode] ?? 1;
                $this->save((int) $variantId, [$sourceCode => ['quantity' => $quantity, 'status' => $status]]);
            }
        });
    }

    /**
     * One query for many products (and one more for configurable ones).
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
        $salable = static fn () => self::salableSelect();
        $rows = $db->fetchPairs($salable()->columns(['i.product_id', new Expr('SUM(i.quantity)')])->where('i.product_id IN (?)', $ids)->group('i.product_id'))
            + $db->fetchPairs($salable()->columns(['p.parent_id', new Expr('SUM(i.quantity)')])->where('p.parent_id IN (?)', $ids)->where('p.is_active = 1')->group('p.parent_id'));

        return array_map(static fn ($id) => self::format($rows[$id] ?? '0'), array_combine($ids, $ids));
    }

    /** in-stock quantities of enabled sources in the product's websites; p = the item's product */
    private static function salableSelect(): Select
    {
        return Connection::get()->adapter()->select()
            ->from(['i' => 'inventory_source_item'], [])
            ->join(['s' => 'inventory_source'], 's.source_id = i.source_id AND s.is_enabled = 1', [])
            ->join(['pw' => 'catalog_product_website'], 'pw.product_id = i.product_id AND pw.website_id = s.website_id', [])
            ->join(['p' => 'catalog_product'], 'p.product_id = i.product_id', [])
            ->where('i.status = 1');
    }

    /** "Estoque" column sort (Catalog's columns slot): the salable quantity of main_table.product_id, as SQL */
    public static function salableSort(): string
    {
        return (string) self::salableSelect()->columns([new Expr('COALESCE(SUM(i.quantity), 0)')])
            ->where('(i.product_id = main_table.product_id OR (p.parent_id = main_table.product_id AND p.is_active = 1))');
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
