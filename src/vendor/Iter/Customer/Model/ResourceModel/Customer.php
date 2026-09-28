<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Customer\Model\ResourceModel\Customer\Collection;

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
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('customer_entity', [new Expr('1')])
            ->where($column === 'email' ? 'LOWER(email) = LOWER(?)' : 'taxvat = ?', $value)
            ->where('website_id = ?', $websiteId)
            ->where('customer_id <> ?', $exceptId ?? 0)
            ->limit(1));
    }
}
