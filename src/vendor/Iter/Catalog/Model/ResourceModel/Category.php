<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

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
        return $this->getConnection()->query(
            "WITH RECURSIVE tree AS (
                SELECT c.*, 0 AS level, ARRAY[LPAD(c.position::text, 11, '0') || c.name || c.category_id] AS sort
                FROM catalog_category c WHERE c.parent_id IS NULL
                UNION ALL
                SELECT c.*, t.level + 1, t.sort || (LPAD(c.position::text, 11, '0') || c.name || c.category_id)
                FROM catalog_category c JOIN tree t ON c.parent_id = t.category_id
            )
            SELECT t.category_id, t.parent_id, t.name, t.url_key, t.is_active, t.position, t.created_at, t.updated_at, t.level,
                   (SELECT COUNT(*) FROM catalog_category_product cp WHERE cp.category_id = t.category_id) AS products
            FROM tree t ORDER BY t.sort"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return int[] the category and everything under it */
    public function getSubtreeIds(int $id): array
    {
        $stmt = $this->getConnection()->prepare(
            'WITH RECURSIVE sub AS (
                SELECT category_id FROM catalog_category WHERE category_id = :id
                UNION ALL
                SELECT c.category_id FROM catalog_category c JOIN sub ON c.parent_id = sub.category_id
            ) SELECT category_id FROM sub'
        );
        $stmt->execute(['id' => $id]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function hasChildren(int $id): bool
    {
        $stmt = $this->getConnection()->prepare('SELECT 1 FROM catalog_category WHERE parent_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function findIdByUrlKey(string $urlKey): ?int
    {
        $stmt = $this->getConnection()->prepare('SELECT category_id FROM catalog_category WHERE url_key = :url_key');
        $stmt->execute(['url_key' => strtolower(trim($urlKey))]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function urlKeyExists(string $urlKey, ?int $exceptId = null): bool
    {
        $stmt = $this->getConnection()->prepare('SELECT 1 FROM catalog_category WHERE url_key = :url_key AND category_id <> :id');
        $stmt->execute(['url_key' => $urlKey, 'id' => $exceptId ?? 0]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return int[] categories of the product */
    public function getProductCategoryIds(int $productId): array
    {
        $stmt = $this->getConnection()->prepare('SELECT category_id FROM catalog_category_product WHERE product_id = :id ORDER BY category_id');
        $stmt->execute(['id' => $productId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param int[] $categoryIds replaces the product's categories */
    public function setProductCategories(int $productId, array $categoryIds): void
    {
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM catalog_category_product WHERE product_id = :id')->execute(['id' => $productId]);
            $insert = $pdo->prepare('INSERT INTO catalog_category_product (category_id, product_id) VALUES (:category_id, :product_id)');
            foreach (array_unique($categoryIds) as $categoryId) {
                $insert->execute(['category_id' => $categoryId, 'product_id' => $productId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
