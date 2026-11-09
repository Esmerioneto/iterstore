<?php

declare(strict_types=1);

namespace Iter\Wishlist\Model;

use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Eav\Model\AttributeException;

/**
 * WISHLIST-PLAN: the customer's favourites. Only parent products (a variant is refused), at most LIMIT.
 * A product that went inactive or left the customer's website stays stored but out of the list (and of the
 * count), and comes back if it returns.
 */
final class WishlistService
{
    public const LIMIT = 200;

    /** @return array{items: list<array{product: array, added_at: string}>, total: int} the catalog_product rows, newest first */
    public function items(int $customerId, int $page = 1, int $pageSize = 20): array
    {
        $select = $this->visible($customerId, [new Expr('COUNT(*)')]);
        $total = (int) $this->db()->fetchOne($select);
        $rows = $this->db()->fetchAll($select->reset(Select::COLUMNS)->columns(['p.*', new Expr('w.created_at AS wishlist_added_at')])
            ->order('w.created_at DESC')->order('w.product_id DESC')->limitPage(max(1, $page), min(100, max(1, $pageSize))));

        return ['items' => array_map(static function (array $row): array {
            $added = $row['wishlist_added_at'];
            unset($row['wishlist_added_at']);

            return ['product' => $row, 'added_at' => (string) $added];
        }, $rows), 'total' => $total];
    }

    /**
     * Idempotent: a product already there stays as it was (same date).
     *
     * @throws AttributeException PRODUCT_NOT_FOUND (also inactive or of another website), PRODUCT_IS_VARIANT, WISHLIST_LIMIT
     */
    public function add(int $customerId, int $productId): void
    {
        $db = $this->db();
        $product = $db->fetchRow('SELECT p.parent_id FROM catalog_product p
            JOIN catalog_product_website pw ON pw.product_id = p.product_id
            JOIN customer_entity c ON c.website_id = pw.website_id AND c.customer_id = ?
            WHERE p.product_id = ? AND p.is_active = 1', [$customerId, $productId]);
        if (!$product) {
            throw new AttributeException('PRODUCT_NOT_FOUND', 'Produto não encontrado.');
        }
        if ($product['parent_id'] !== null) {
            throw new AttributeException('PRODUCT_IS_VARIANT', 'Favorite o produto, não a variação.');
        }
        $there = (bool) $db->fetchOne('SELECT 1 FROM customer_wishlist_item WHERE customer_id = ? AND product_id = ?', [$customerId, $productId]);
        // ponytail: count then insert, not locked; two adds at once may reach LIMIT + 1, harmless
        if (!$there && (int) $db->fetchOne($this->visible($customerId, [new Expr('COUNT(*)')])) >= self::LIMIT) {
            throw new AttributeException('WISHLIST_LIMIT', 'A lista de favoritos tem no máximo ' . self::LIMIT . ' produtos. Remova algum para incluir outro.');
        }
        $db->insertOnConflict('customer_wishlist_item', ['customer_id' => $customerId, 'product_id' => $productId], []);
    }

    /**
     * The admin's read-only list (under the customer's form): every stored item, newest first, with
     * "visible" = what the customer sees now (the product active and in the customer's website)
     *
     * @return list<array{product_id: int, sku: string, name: string, created_at: string, visible: bool}>
     */
    public function adminList(int $customerId): array
    {
        return array_map(static fn (array $r) => ['visible' => (bool) $r['visible']] + $r, $this->db()->fetchAll(
            'SELECT w.product_id, p.sku, p.name, w.created_at,
                    (p.is_active = 1 AND EXISTS (SELECT 1 FROM catalog_product_website pw WHERE pw.product_id = p.product_id AND pw.website_id = c.website_id)) AS visible
             FROM customer_wishlist_item w JOIN catalog_product p ON p.product_id = w.product_id JOIN customer_entity c ON c.customer_id = w.customer_id
             WHERE w.customer_id = ? ORDER BY w.created_at DESC, w.product_id DESC',
            [$customerId]
        ));
    }

    /** nothing to do when it isn't there: the end state is the same */
    public function remove(int $customerId, int $productId): void
    {
        $this->db()->delete('customer_wishlist_item', ['customer_id = ?' => $customerId, 'product_id = ?' => $productId]);
    }

    /** the stored items the customer sees: the product active, a parent, in the customer's website */
    private function visible(int $customerId, array $columns): Select
    {
        return $this->db()->select()->from(['w' => 'customer_wishlist_item'], $columns)
            ->join(['p' => 'catalog_product'], 'p.product_id = w.product_id AND p.is_active = 1 AND p.parent_id IS NULL', [])
            ->join(['c' => 'customer_entity'], 'c.customer_id = w.customer_id', [])
            ->where('w.customer_id = ?', $customerId)
            ->where('EXISTS (SELECT 1 FROM catalog_product_website pw WHERE pw.product_id = w.product_id AND pw.website_id = c.website_id)');
    }

    private function db(): Adapter
    {
        return Connection::get()->adapter();
    }
}
