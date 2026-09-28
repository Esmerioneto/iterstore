<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class Attribute extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('eav_attribute', 'attribute_id');
    }

    /**
     * @param array{entity_type?: string, input_type?: string, is_system?: string, q?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if (($filters['entity_type'] ?? '') !== '') {
            $where[] = 'et.code = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (($filters['input_type'] ?? '') !== '') {
            $where[] = 'a.input_type = :input_type';
            $params['input_type'] = $filters['input_type'];
        }
        if (in_array($filters['is_system'] ?? '', ['0', '1'], true)) {
            $where[] = 'a.is_system = :is_system';
            $params['is_system'] = (int) $filters['is_system'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(a.code ILIKE :q OR a.label ILIKE :q)';
            $params['q'] = '%' . addcslashes($filters['q'], '%_\\') . '%';
        }
        $from = 'FROM eav_attribute a JOIN eav_entity_type et ON et.entity_type_id = a.entity_type_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $count = $this->getConnection()->prepare("SELECT COUNT(*) {$from}");
        $count->execute($params);

        $stmt = $this->getConnection()->prepare(
            "SELECT a.*, et.code AS entity_type, et.label AS entity_type_label {$from}
             ORDER BY et.code, a.sort_order, a.code
             LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, array{entity_type_id: int, label: string}> code => row */
    public function getEntityTypes(): array
    {
        $rows = $this->getConnection()
            ->query('SELECT code, entity_type_id, label FROM eav_entity_type ORDER BY label')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, null, 'code');
    }

    public function codeExists(int $entityTypeId, string $code): bool
    {
        $stmt = $this->getConnection()->prepare(
            'SELECT 1 FROM eav_attribute WHERE entity_type_id = :entity_type_id AND code = :code'
        );
        $stmt->execute(['entity_type_id' => $entityTypeId, 'code' => $code]);

        return (bool) $stmt->fetchColumn();
    }
}
