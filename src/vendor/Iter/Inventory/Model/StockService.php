<?php

declare(strict_types=1);

namespace Iter\Inventory\Model;

use Iter\Catalog\Model\ProductService;
use Iter\Catalog\Model\VariantService;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Log\Model\OperationLog;

/**
 * Product quantities per source. Only the sources of the product's websites count:
 * salable quantity = sum of the quantities marked "in stock" (status 1) in their
 * enabled sources, plus the reservations (negative while an order holds them); computed on read, never stored.
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

        $before = OperationLog::active() ? $this->readable($productId) : [];
        Connection::get()->adapter()->transaction(static function ($db) use ($rows, $productId): void {
            foreach ($rows as $row) {
                (float) $row['quantity'] === 0.0 && $row['status'] === 1
                    ? $db->delete('inventory_source_item', ['source_id = ?' => $row['source_id'], 'product_id = ?' => $productId])
                    : $db->insertOnConflict('inventory_source_item', $row + ['product_id' => $productId], ['source_id', 'product_id'], ['quantity', 'status', 'updated_at' => new Expr('NOW()')]);
            }
        });
        if (OperationLog::active()) {
            OperationLog::record('update', 'product', $productId, ProductService::logLabel((new ProductService())->get($productId)), $before, $this->readable($productId));
        }
    }

    /** @return array<string, string> "Estoque — CD Rio" => "7" or "7 (fora de estoque)", for the operation log */
    private function readable(int $productId): array
    {
        $readable = [];
        foreach ($this->getItems($productId) as $item) {
            $readable['Estoque — ' . $item['name']] = $item['quantity'] . ($item['status'] ? '' : ' (fora de estoque)');
        }

        return $readable;
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
     * One query for many products (and one more for configurable ones), plus the reservations.
     * With $websiteId, only that website's sources and reservations (what that store can sell).
     *
     * @param int[] $productIds
     * @return array<int, string> product id => salable quantity (every id, 0 when there's none)
     */
    public function getSalable(array $productIds, ?int $websiteId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) {
            return [];
        }
        $db = Connection::get()->adapter();
        $sum = static function (Select $select, string $quantity) use ($db, $ids): array {
            return $db->fetchPairs((clone $select)->columns(['i.product_id', new Expr("SUM({$quantity})")])->where('i.product_id IN (?)', $ids)->group('i.product_id'))
                + $db->fetchPairs((clone $select)->columns(['p.parent_id', new Expr("SUM({$quantity})")])->where('p.parent_id IN (?)', $ids)->where('p.is_active = 1')->group('p.parent_id'));
        };
        $physical = self::salableSelect();
        $reserved = $db->select()->from(['i' => 'inventory_reservation'], [])->join(['p' => 'catalog_product'], 'p.product_id = i.product_id', []);
        if ($websiteId !== null) {
            $physical->where('s.website_id = ?', $websiteId);
            $reserved->where('i.website_id = ?', $websiteId);
        }
        $physical = $sum($physical, 'i.quantity');
        $reserved = $sum($reserved, 'i.quantity'); // negative while reserved

        return array_map(static fn ($id) => self::format(sprintf('%.4F', (float) ($physical[$id] ?? 0) + (float) ($reserved[$id] ?? 0))), array_combine($ids, $ids));
    }

    /**
     * Reserves for an order: checks the website's salable quantity and inserts -qty, all or nothing.
     * A lock per product (in id order, no deadlock) queues concurrent orders, so the last unit is
     * reserved once; the other gets INSUFFICIENT_STOCK. Joins the caller's transaction.
     *
     * @param array<int, int|float|string> $quantities product id => quantity (simple products or variants)
     */
    public function reserve(int $websiteId, array $quantities, string $reason, ?string $reference = null): void
    {
        ksort($quantities);
        Connection::get()->adapter()->transaction(function ($db) use ($websiteId, $quantities, $reason, $reference): void {
            foreach ($quantities as $productId => $quantity) {
                $db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?), ?)', ['inventory_reservation', (int) $productId]);
                $product = (new ProductService())->get((int) $productId); // PRODUCT_NOT_FOUND
                if ($product->getData('type') === 'configurable') {
                    throw new InventoryException('PRODUCT_IS_CONFIGURABLE', "'{$product->getData('name')}' tem grade: reserve a variação.");
                }
                $salable = (float) $this->getSalable([(int) $productId], $websiteId)[(int) $productId];
                if ((float) $quantity <= 0 || (float) $quantity > $salable) {
                    throw new InventoryException('INSUFFICIENT_STOCK', sprintf("'%s' (%s): só há %s disponível.", $product->getData('name'), $product->getData('sku'), str_replace('.', ',', self::format((string) $salable))));
                }
                $db->insert('inventory_reservation', ['product_id' => (int) $productId, 'website_id' => $websiteId, 'quantity' => -(float) $quantity, 'reason' => $reason, 'reference' => $reference]);
            }
        });
    }

    /**
     * Gives reserved quantities back (canceled order) or clears them once the physical quantity was
     * taken (shipped order): inserts +qty, never checks the balance.
     *
     * @param array<int, int|float|string> $quantities product id => quantity
     */
    public function release(int $websiteId, array $quantities, string $reason, ?string $reference = null): void
    {
        Connection::get()->adapter()->transaction(static function ($db) use ($websiteId, $quantities, $reason, $reference): void {
            foreach ($quantities as $productId => $quantity) {
                $db->insert('inventory_reservation', ['product_id' => (int) $productId, 'website_id' => $websiteId, 'quantity' => (float) $quantity, 'reason' => $reason, 'reference' => $reference]);
            }
        });
    }

    /**
     * A shipment: takes the physical quantities out of one source and gives the order's reservation
     * back (the stock left with the parcel), all or nothing.
     *
     * @param array<int, int|float|string> $quantities product id => quantity
     * @throws InventoryException INSUFFICIENT_STOCK when the source hasn't that much of a product
     */
    public function deduct(int $sourceId, int $websiteId, array $quantities, string $reference): void
    {
        ksort($quantities);
        Connection::get()->adapter()->transaction(function ($db) use ($sourceId, $websiteId, $quantities, $reference): void {
            foreach ($quantities as $productId => $quantity) {
                $db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?), ?)', ['inventory_reservation', (int) $productId]);
                $taken = $db->update('inventory_source_item', ['quantity' => new Expr('quantity - ' . (float) $quantity), 'updated_at' => new Expr('NOW()')],
                    ['source_id = ?' => $sourceId, 'product_id = ?' => (int) $productId, 'quantity >= ?' => (float) $quantity]);
                if (!$taken) {
                    $name = $db->fetchOne($db->select()->from('catalog_product', ['name'])->where('product_id = ?', (int) $productId));
                    throw new InventoryException('INSUFFICIENT_STOCK', "'{$name}': a origem não tem " . str_replace('.', ',', self::format((string) $quantity)) . '.');
                }
            }
            $this->release($websiteId, $quantities, 'shipped', $reference);
        });
    }

    /**
     * The website's enabled sources for a shipment, the one with the most of these products first
     * (the suggested one); 'covers' says whether it has every quantity.
     *
     * @param array<int, int|float|string> $quantities product id => quantity
     * @return array<int, array{source_id: int, code: string, name: string, covers: bool}>
     */
    public function shipSources(int $websiteId, array $quantities): array
    {
        $db = Connection::get()->adapter();
        $sources = $db->fetchAll($db->select()->from('inventory_source', ['source_id', 'code', 'name'])
            ->where('website_id = ?', $websiteId)->where('is_enabled = 1')->order('priority, source_id'));
        $stock = [];
        if ($quantities) {
            foreach ($db->fetchAll($db->select()->from('inventory_source_item', ['source_id', 'product_id', 'quantity'])
                ->where('product_id IN (?)', array_map('intval', array_keys($quantities)))) as $row) {
                $stock[(int) $row['source_id']][(int) $row['product_id']] = (float) $row['quantity'];
            }
        }
        foreach ($sources as &$source) {
            $have = $stock[(int) $source['source_id']] ?? [];
            $source['source_id'] = (int) $source['source_id'];
            $source['covers'] = !array_filter(array_keys($quantities), static fn ($id) => ($have[$id] ?? 0) < (float) $quantities[$id]);
            $source['total'] = array_sum($have);
        }
        unset($source);
        usort($sources, static fn ($a, $b) => [$b['covers'], $b['total']] <=> [$a['covers'], $a['total']]);

        return $sources;
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
        $mine = '(i.product_id = main_table.product_id OR (p.parent_id = main_table.product_id AND p.is_active = 1))';
        $reserved = Connection::get()->adapter()->select()->from(['i' => 'inventory_reservation'], [new Expr('COALESCE(SUM(i.quantity), 0)')])
            ->join(['p' => 'catalog_product'], 'p.product_id = i.product_id', [])->where($mine);

        return '((' . self::salableSelect()->columns([new Expr('COALESCE(SUM(i.quantity), 0)')])->where($mine) . ") + ({$reserved}))";
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
