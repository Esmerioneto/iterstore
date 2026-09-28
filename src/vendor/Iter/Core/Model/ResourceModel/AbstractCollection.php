<?php

declare(strict_types=1);

namespace Iter\Core\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Core\Model\AbstractModel;

/**
 * A list of models read with one Select, with filter, order and page.
 * Mirrors Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection:
 *
 *     $collection = (new CustomerCollection())
 *         ->addFieldToFilter('website_id', 3)
 *         ->addFieldToFilter(['email', 'firstname'], [['ilike' => '%ana%'], ['ilike' => '%ana%']]) // OR
 *         ->setOrder('created_at')->setPageSize(20)->setCurPage(2);
 *     $collection->getSize();  // rows without the page
 *     $collection->getItems(); // Customer[]
 *     $collection->getData();  // the rows as arrays
 *
 * The main table is aliased "main_table", as in Magento; subclasses add their
 * joins in _initSelect().
 *
 * @template T of AbstractModel
 * @implements \IteratorAggregate<int, T>
 */
abstract class AbstractCollection implements \IteratorAggregate, \Countable
{
    /** @var class-string<T> */
    private string $modelClass;

    protected AbstractDb $resource;

    protected Select $select;

    private int $pageSize = 0;

    private int $curPage = 1;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $data = null;

    private ?int $size = null;

    public function __construct()
    {
        $this->_construct();
        $this->select = $this->resource->getConnection()->select()->from(['main_table' => $this->resource->getMainTable()]);
        $this->_initSelect();
    }

    /**
     * Override and call $this->init(Model::class, ResourceModel::class);
     */
    abstract protected function _construct(): void;

    /** @param class-string<T> $modelClass */
    protected function init(string $modelClass, string $resourceClass): void
    {
        $this->modelClass = $modelClass;
        $this->resource = new $resourceClass();
    }

    /** override to add joins and columns */
    protected function _initSelect(): void
    {
    }

    /** for what addFieldToFilter doesn't cover (a subquery, a raw condition) */
    public function getSelect(): Select
    {
        return $this->select;
    }

    /**
     * $field: 'col' (of main_table), 'alias.col' or an Expr. $condition: a value (=), or
     * ['eq'|'neq'|'like'|'nlike'|'ilike'|'in'|'nin'|'gt'|'gteq'|'lt'|'lteq'|'from'|'to' => value,
     * 'null' => true, 'notnull' => true]; several keys are joined with AND.
     * A list of fields with a list of conditions is joined with OR.
     */
    public function addFieldToFilter(string|array|Expr $field, mixed $condition = null): static
    {
        if (is_array($field)) {
            $parts = [];
            foreach ($field as $i => $one) {
                $parts[] = '(' . $this->condition($one, $condition[$i] ?? null) . ')';
            }
            $this->select->where(implode(' OR ', $parts));
        } else {
            $this->select->where($this->condition($field, $condition));
        }

        return $this;
    }

    public function setOrder(string|Expr $field, string $direction = 'DESC'): static
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $this->select->order($field instanceof Expr ? new Expr("{$field} {$direction}") : $this->field($field, false) . ' ' . $direction);

        return $this;
    }

    /** 0 = no page, every row */
    public function setPageSize(int $size): static
    {
        $this->pageSize = max(0, $size);

        return $this;
    }

    /** from 1 */
    public function setCurPage(int $page): static
    {
        $this->curPage = max(1, $page);

        return $this;
    }

    /** rows matching the filters, ignoring the page */
    public function getSize(): int
    {
        if ($this->size === null) {
            $count = (clone $this->select)->reset(Select::ORDER)->reset(Select::LIMIT);
            $this->size = (int) $this->resource->getConnection()->fetchOne("SELECT COUNT(*) FROM ({$count}) AS count_table", $count->getBind());
        }

        return $this->size;
    }

    /** @return array<int, array<string, mixed>> the rows of the current page */
    public function getData(): array
    {
        if ($this->data === null) {
            $select = clone $this->select;
            if ($this->pageSize) {
                $select->limitPage($this->curPage, $this->pageSize);
            }
            $this->data = $this->resource->getConnection()->fetchAll($select);
        }

        return $this->data;
    }

    /** @return array<int, T> */
    public function getItems(): array
    {
        return array_map(fn (array $row) => new ($this->modelClass)($row), $this->getData());
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getItems());
    }

    public function count(): int
    {
        return count($this->getData());
    }

    private function condition(string|Expr $field, mixed $condition): string
    {
        $sql = $this->field($field, true);
        if (!is_array($condition)) {
            return $sql . ' = ' . $this->select->bindValue($condition);
        }
        $parts = [];
        foreach ($condition as $type => $value) {
            $parts[] = $sql . match ($type) {
                'eq' => ' = ' . $this->select->bindValue($value),
                'neq' => ' <> ' . $this->select->bindValue($value),
                'like' => ' LIKE ' . $this->select->bindValue($value),
                'nlike' => ' NOT LIKE ' . $this->select->bindValue($value),
                'ilike' => ' ILIKE ' . $this->select->bindValue($value),
                'in' => ' IN (' . $this->list($value) . ')',
                'nin' => ' NOT IN (' . $this->list($value) . ')',
                'gt' => ' > ' . $this->select->bindValue($value),
                'gteq', 'from' => ' >= ' . $this->select->bindValue($value),
                'lt' => ' < ' . $this->select->bindValue($value),
                'lteq', 'to' => ' <= ' . $this->select->bindValue($value),
                'null' => ' IS NULL',
                'notnull' => ' IS NOT NULL',
                default => throw new \InvalidArgumentException("Condição desconhecida: '{$type}'."),
            };
        }

        return implode(' AND ', $parts);
    }

    private function list(array $values): string
    {
        return implode(', ', array_map($this->select->bindValue(...), $values ?: [null]));
    }

    /** 'col' → main_table.col; $quote = false for Select::order(), which quotes itself */
    private function field(string|Expr $field, bool $quote): string
    {
        if ($field instanceof Expr) {
            return '(' . $field . ')';
        }
        $field = str_contains($field, '.') ? $field : 'main_table.' . $field;

        return $quote ? $this->resource->getConnection()->quoteIdentifier($field) : $field;
    }
}
