<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Customer\Model\ResourceModel\Customer\Collection;
use Iter\Core\Db\Expr;

class Customer extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('customer_entity', 'customer_id');
    }

    /**
     * @param array{customer_type?: string, group_id?: string, website_id?: string, is_active?: string, q?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int} rows + group_name, website_name
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $collection = (new Collection())->setOrder('customer_id')->setPageSize($perPage)->setCurPage($page);
        if (in_array($filters['customer_type'] ?? '', ['pf', 'pj'], true)) {
            $collection->addFieldToFilter('customer_type', $filters['customer_type']);
        }
        foreach (['group_id', 'website_id'] as $key) {
            if (ctype_digit($filters[$key] ?? '')) {
                $collection->addFieldToFilter($key, (int) $filters[$key]);
            }
        }
        if (in_array($filters['is_active'] ?? '', ['0', '1'], true)) {
            $collection->addFieldToFilter('is_active', (int) $filters['is_active']);
        }
        if (($filters['q'] ?? '') !== '') {
            $q = ['ilike' => '%' . addcslashes($filters['q'], '%_\\') . '%'];
            $fields = [new Expr("main_table.firstname || ' ' || main_table.lastname"), 'company_name', 'trade_name', 'email'];
            $conditions = [$q, $q, $q, $q];
            // a typed CPF/CNPJ (with or without the mask) matches the stored digits
            $digits = preg_replace('/\D/', '', $filters['q']);
            if ($digits !== '') {
                $fields[] = 'taxvat';
                $conditions[] = ['like' => '%' . $digits . '%'];
            }
            $collection->addFieldToFilter($fields, $conditions);
        }

        return ['items' => $collection->getData(), 'total' => $collection->getSize()];
    }

    /** $column is email (compared ignoring case) or taxvat */
    public function exists(string $column, string $value, int $websiteId, ?int $exceptId = null): bool
    {
        $compare = $column === 'email' ? 'LOWER(email) = LOWER(:value)' : 'taxvat = :value';
        $stmt = $this->getConnection()->prepare(
            "SELECT 1 FROM customer_entity WHERE {$compare} AND website_id = :website_id AND customer_id <> :id"
        );
        $stmt->execute(['value' => $value, 'website_id' => $websiteId, 'id' => $exceptId ?? 0]);

        return (bool) $stmt->fetchColumn();
    }
}
