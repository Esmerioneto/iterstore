<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Core\Model\AbstractModel;
use Iter\Core\Model\ResourceModel\AbstractDb;
use Iter\Customer\Model\ResourceModel\Customer\Collection;

class Customer extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('customer_entity', 'customer_id');
    }

    /** reads customer_flat: the columns plus the registration fields (EAV) under their old names */
    public function load(AbstractModel $object, $value, ?string $field = null): void
    {
        $db = $this->getConnection();
        $row = $db->fetchRow($db->select()->from('customer_flat')->where($db->quoteIdentifier($field ?? 'customer_id') . ' = ?', $value));
        $object->unsetData();
        if ($row !== false) {
            $object->addData($row);
        }
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

    public function findIdByEmail(string $email, int $websiteId): ?int
    {
        $id = $this->getConnection()->fetchOne($this->uniqueSelect('email', $email, $websiteId));

        return $id === false || $id === null ? null : (int) $id;
    }

    /** @return array{total: int, recent: int, inactive: int} recent = created in the last 7 days */
    public function getSummary(): array
    {
        $db = $this->getConnection();

        return array_map('intval', $db->fetchRow($db->select()->from('customer_entity', [])->columns([
            'total' => 'COUNT(*)',
            'recent' => "COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days')",
            'inactive' => 'COUNT(*) FILTER (WHERE is_active = 0)',
        ])));
    }

    /** $code is email or taxvat (digits), looked up by the unique key of the website */
    public function exists(string $code, string $value, int $websiteId, ?int $exceptId = null): bool
    {
        return (bool) $this->getConnection()->fetchOne($this->uniqueSelect($code, $value, $websiteId)->where('u.entity_id <> ?', $exceptId ?? 0)->limit(1));
    }

    /** the indexed lookup in eav_unique_value (attribute, website, value in lowercase) */
    private function uniqueSelect(string $code, string $value, int $websiteId): Select
    {
        return $this->getConnection()->select()->from(['u' => 'eav_unique_value'], ['entity_id'])
            ->join(['a' => 'eav_attribute'], 'a.attribute_id = u.attribute_id', [])
            ->join(['et' => 'eav_entity_type'], "et.entity_type_id = a.entity_type_id AND et.code = 'customer'", [])
            ->where('a.code = ?', $code)->where('u.scope_id = ?', $websiteId)->where('u.value_key = ?', mb_strtolower(trim($value)));
    }
}
