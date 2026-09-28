<?php

declare(strict_types=1);

namespace Iter\Catalog\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class Product extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('catalog_product', 'product_id');
    }

    /**
     * @param array{attribute_set_id?: string, is_active?: string, q?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int} rows + set_label, price
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if (($filters['attribute_set_id'] ?? '') !== '') {
            $where[] = 'p.attribute_set_id = :attribute_set_id';
            $params['attribute_set_id'] = (int) $filters['attribute_set_id'];
        }
        if (in_array($filters['is_active'] ?? '', ['0', '1'], true)) {
            $where[] = 'p.is_active = :is_active';
            $params['is_active'] = (int) $filters['is_active'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.sku ILIKE :q OR p.name ILIKE :q)';
            $params['q'] = '%' . addcslashes($filters['q'], '%_\\') . '%';
        }
        $from = 'FROM catalog_product p JOIN eav_attribute_set s ON s.attribute_set_id = p.attribute_set_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $count = $this->getConnection()->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);

        // price is the system EAV attribute created along with the table
        $stmt = $this->getConnection()->prepare(
            "SELECT p.*, s.label AS set_label,
                    (SELECT v.value FROM eav_value_decimal v JOIN eav_attribute pa ON pa.attribute_id = v.attribute_id
                     WHERE pa.entity_type_id = s.entity_type_id AND pa.code = 'price' AND v.entity_id = p.product_id) AS price
             {$from}
             ORDER BY p.name, p.product_id
             LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
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
