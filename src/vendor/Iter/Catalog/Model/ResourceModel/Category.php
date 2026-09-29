<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractDb;

class Category extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('catalog_category', 'category_id');
    }

    /**
     * The whole tree in display order (each category right after its parent, siblings
     * by position then name), with its depth and number of products linked to it.
     *
     * @return array<int, array<string, mixed>> rows + level, products
     */
    public function getTree(): array
    {
        // ponytail: recursive CTE stays SQL, Select has no WITH
        return $this->getConnection()->fetchAll(
            "WITH RECURSIVE tree AS (
                SELECT c.*, 0 AS level, ARRAY[LPAD(c.position::text, 11, '0') || c.name || c.category_id] AS sort
                FROM catalog_category c WHERE c.parent_id IS NULL
                UNION ALL
                SELECT c.*, t.level + 1, t.sort || (LPAD(c.position::text, 11, '0') || c.name || c.category_id)
                FROM catalog_category c JOIN tree t ON c.parent_id = t.category_id
            )
            SELECT t.category_id, t.website_id, t.parent_id, t.name, t.url_key, t.is_active, t.position, t.created_at, t.updated_at, t.level,
                   (SELECT COUNT(*) FROM catalog_category_product cp WHERE cp.category_id = t.category_id) AS products
            FROM tree t ORDER BY t.sort"
        );
    }

    /** @return int[] the category and everything under it */
    public function getSubtreeIds(int $id): array
    {
        return array_map('intval', $this->getConnection()->fetchCol(
            'WITH RECURSIVE sub AS (
                SELECT category_id FROM catalog_category WHERE category_id = :id
                UNION ALL
                SELECT c.category_id FROM catalog_category c JOIN sub ON c.parent_id = sub.category_id
            ) SELECT category_id FROM sub',
            ['id' => $id]
        ));
    }

    public function hasChildren(int $id): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('catalog_category', [new Expr('1')])->where('parent_id = ?', $id)->limit(1));
    }

    public function hasProducts(int $id): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('catalog_category_product', [new Expr('1')])->where('category_id = ?', $id)->limit(1));
    }

    /** the URL key is unique per website */
    public function findIdByUrlKey(string $urlKey, int $websiteId): ?int
    {
        $db = $this->getConnection();
        $id = $db->fetchOne($db->select()->from('catalog_category', ['category_id'])
            ->where('url_key = ?', strtolower(trim($urlKey)))
            ->where('website_id = ?', $websiteId));

        return $id === false ? null : (int) $id;
    }

    public function urlKeyExists(string $urlKey, int $websiteId, ?int $exceptId = null): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('catalog_category', [new Expr('1')])
            ->where('url_key = ?', $urlKey)
            ->where('website_id = ?', $websiteId)
            ->where('category_id <> ?', $exceptId ?? 0));
    }

    /** @return int[] categories of the product */
    public function getProductCategoryIds(int $productId): array
    {
        $db = $this->getConnection();

        return array_map('intval', $db->fetchCol($db->select()->from('catalog_category_product', ['category_id'])
            ->where('product_id = ?', $productId)->order('category_id')));
    }

    /** @param int[] $categoryIds replaces the product's categories */
    public function setProductCategories(int $productId, array $categoryIds): void
    {
        $this->getConnection()->transaction(static function ($db) use ($productId, $categoryIds): void {
            $db->delete('catalog_category_product', ['product_id = ?' => $productId]);
            $db->insertMultiple('catalog_category_product', array_map(
                static fn ($categoryId) => ['category_id' => $categoryId, 'product_id' => $productId],
                array_values(array_unique($categoryIds))
            ));
        });
    }
}
