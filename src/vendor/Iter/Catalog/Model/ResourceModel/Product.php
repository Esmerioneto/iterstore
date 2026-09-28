<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Catalog\Model\ResourceModel\Product\Collection;

class Product extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('catalog_product', 'product_id');
    }

    /**
     * @param array{attribute_set_id?: string, is_active?: string, q?: string, category_id?: string} $filters category_id includes its subcategories
     * @return array{items: array<int, array<string, mixed>>, total: int} rows + set_label, price
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $collection = (new Collection())->setOrder('name', 'ASC')->setOrder('product_id', 'ASC')->setPageSize($perPage)->setCurPage($page);
        if (($filters['attribute_set_id'] ?? '') !== '') {
            $collection->addFieldToFilter('attribute_set_id', (int) $filters['attribute_set_id']);
        }
        if (in_array($filters['is_active'] ?? '', ['0', '1'], true)) {
            $collection->addFieldToFilter('is_active', (int) $filters['is_active']);
        }
        if (($filters['category_id'] ?? '') !== '') {
            $collection->addCategoryFilter((int) $filters['category_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $q = ['ilike' => '%' . addcslashes($filters['q'], '%_\\') . '%'];
            $collection->addFieldToFilter(['sku', 'name'], [$q, $q]);
        }

        return ['items' => $collection->getData(), 'total' => $collection->getSize()];
    }

    /** SKUs are unique ignoring case, so the lookup ignores it too */
    public function findIdBySku(string $sku): ?int
    {
        $stmt = $this->getConnection()->prepare('SELECT product_id FROM catalog_product WHERE LOWER(sku) = LOWER(:sku)');
        $stmt->execute(['sku' => trim($sku)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT 1 FROM catalog_product WHERE LOWER(sku) = LOWER(:sku) AND product_id <> :id'
        );
        $stmt->execute(['sku' => $sku, 'id' => $exceptId ?? 0]);

        return (bool) $stmt->fetchColumn();
    }
}
