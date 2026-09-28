<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

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
        $where = [];
        $params = [];
        if (in_array($filters['customer_type'] ?? '', ['pf', 'pj'], true)) {
            $where[] = 'c.customer_type = :customer_type';
            $params['customer_type'] = $filters['customer_type'];
        }
        foreach (['group_id', 'website_id'] as $key) {
            if (ctype_digit($filters[$key] ?? '')) {
                $where[] = "c.{$key} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        if (in_array($filters['is_active'] ?? '', ['0', '1'], true)) {
            $where[] = 'c.is_active = :is_active';
            $params['is_active'] = (int) $filters['is_active'];
        }
        if (($filters['q'] ?? '') !== '') {
            $q = [];
            $params['q'] = '%' . addcslashes($filters['q'], '%_\\') . '%';
            // a typed CPF/CNPJ (with or without the mask) matches the stored digits
            $digits = preg_replace('/\D/', '', $filters['q']);
            if ($digits !== '') {
                $q[] = 'c.taxvat LIKE :digits';
                $params['digits'] = '%' . $digits . '%';
            }
            $where[] = "(c.firstname || ' ' || c.lastname ILIKE :q OR c.company_name ILIKE :q OR c.trade_name ILIKE :q OR c.email ILIKE :q"
                . ($q ? ' OR ' . implode(' OR ', $q) : '') . ')';
        }
        $from = 'FROM customer_entity c
                 JOIN customer_group g ON g.customer_group_id = c.group_id
                 JOIN store_website w ON w.website_id = c.website_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $count = $this->getConnection()->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);

        $stmt = $this->getConnection()->prepare(
            "SELECT c.*, g.name AS group_name, w.name AS website_name {$from}
             ORDER BY c.customer_id DESC
             LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
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
