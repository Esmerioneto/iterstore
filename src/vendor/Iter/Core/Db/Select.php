<?php

declare(strict_types=1);

namespace Iter\Core\Db;

/**
 * SELECT builder with Magento's API (Magento\Framework\DB\Select):
 *
 *     $db->select()->from(['c' => 'customer_entity'], ['customer_id', 'email'])
 *         ->joinLeft(['g' => 'customer_group'], 'g.customer_group_id = c.group_id', ['group_name' => 'name'])
 *         ->where('c.website_id = ?', $websiteId)
 *         ->order('c.created_at DESC')->limitPage($page, 20);
 *
 * Conditions are code-written SQL; the values after them are bound ("?" → :p1).
 * Columns, GROUP BY and ORDER BY take identifiers (quoted) or an Expr; a
 * string with "(" is taken as an Expr, like Magento ("COUNT(*)").
 */
final class Select
{
    public const COLUMNS = 'columns';
    public const WHERE = 'where';
    public const GROUP = 'group';
    public const HAVING = 'having';
    public const ORDER = 'order';
    public const LIMIT = 'limit';
    public const FOR_UPDATE = 'forUpdate';

    private bool $distinct = false;

    /** @var array<int, string> "FROM ..." then "LEFT JOIN ..." */
    private array $from = [];

    /** @var array<int, string> */
    private array $columns = [];

    /** @var array<int, string> "AND (...)" / "OR (...)" */
    private array $where = [];

    /** @var array<int, string> */
    private array $group = [];

    /** @var array<int, string> */
    private array $having = [];

    /** @var array<int, string> */
    private array $order = [];

    private ?int $limit = null;

    private int $offset = 0;

    private ?string $forUpdate = null;

    /** @var array<string, mixed> */
    private array $bind = [];

    public function __construct(private readonly Adapter $adapter)
    {
    }

    /**
     * @param string|array<string, string> $table 'table' or ['alias' => 'table']
     * @param string|array<int|string, string|Expr> $columns '*', ['col', 'alias' => 'col', 'total' => 'COUNT(*)']; [] = none
     */
    public function from(string|array $table, string|array $columns = '*'): self
    {
        return $this->addTable('FROM', $table, null, $columns);
    }

    public function join(string|array $table, string $condition, string|array $columns = '*'): self
    {
        return $this->addTable('JOIN', $table, $condition, $columns);
    }

    public function joinLeft(string|array $table, string $condition, string|array $columns = '*'): self
    {
        return $this->addTable('LEFT JOIN', $table, $condition, $columns);
    }

    /** more columns; $correlation is the table alias for bare column names */
    public function columns(string|array $columns, ?string $correlation = null): self
    {
        foreach ((array) $columns as $alias => $column) {
            $sql = $this->expression($column, $correlation);
            $this->columns[] = is_string($alias) ? $sql . ' AS ' . $this->adapter->quoteIdentifier($alias) : $sql;
        }

        return $this;
    }

    /** where('a = ?', $v); where('id IN (?)', [1, 2]); where('a IS NULL') — joined with AND */
    public function where(string $condition, mixed $value = null): self
    {
        return $this->addWhere('AND', $condition, $value, func_num_args() > 1);
    }

    public function orWhere(string $condition, mixed $value = null): self
    {
        return $this->addWhere('OR', $condition, $value, func_num_args() > 1);
    }

    public function group(string|array|Expr $spec): self
    {
        foreach (is_array($spec) ? $spec : [$spec] as $column) {
            $this->group[] = $this->expression($column, null);
        }

        return $this;
    }

    public function having(string $condition, mixed $value = null): self
    {
        $this->having[] = '(' . (func_num_args() > 1 ? Adapter::bindInto($condition, $value, $this->bind) : $condition) . ')';

        return $this;
    }

    /** 'col', 'a.col DESC', 'priority, source_id', ['col ASC', new Expr('random()')] */
    public function order(string|array|Expr $spec): self
    {
        $parts = is_string($spec) && !str_contains($spec, '(') ? explode(',', $spec) : (is_array($spec) ? $spec : [$spec]);
        foreach ($parts as $part) {
            if ($part instanceof Expr || str_contains($part, '(')) {
                $this->order[] = (string) $part;
            } elseif (preg_match('/^\s*(\S+)(\s+(?:ASC|DESC))?(\s+NULLS\s+(?:FIRST|LAST))?\s*$/i', $part, $m)) {
                $this->order[] = $this->adapter->quoteIdentifier($m[1]) . strtoupper(($m[2] ?? '') . ($m[3] ?? ''));
            } else {
                throw new \InvalidArgumentException("Ordenação inválida: '{$part}'.");
            }
        }

        return $this;
    }

    public function limit(?int $count, int $offset = 0): self
    {
        $this->limit = $count;
        $this->offset = max(0, $offset);

        return $this;
    }

    /** page from 1 */
    public function limitPage(int $page, int $rowCount): self
    {
        return $this->limit($rowCount, (max(1, $page) - 1) * $rowCount);
    }

    public function distinct(bool $flag = true): self
    {
        $this->distinct = $flag;

        return $this;
    }

    /** FOR UPDATE; with $skipLocked, rows locked by another transaction are skipped (queues) */
    public function forUpdate(bool $skipLocked = false): self
    {
        $this->forUpdate = $skipLocked ? 'FOR UPDATE SKIP LOCKED' : 'FOR UPDATE';

        return $this;
    }

    /** clears one part (self::ORDER, ...), e.g. to count the rows of a paginated select */
    public function reset(string $part): self
    {
        match ($part) {
            self::COLUMNS => $this->columns = [],
            self::WHERE => $this->where = [],
            self::GROUP => $this->group = [],
            self::HAVING => $this->having = [],
            self::ORDER => $this->order = [],
            self::LIMIT => [$this->limit, $this->offset] = [null, 0],
            self::FOR_UPDATE => $this->forUpdate = null,
        };

        return $this;
    }

    /** @return array<string, mixed> the values bound so far */
    public function getBind(): array
    {
        return $this->bind;
    }

    public function __toString(): string
    {
        $where = $this->where ? ' WHERE ' . preg_replace('/^(AND|OR) /', '', implode(' ', $this->where)) : '';

        return 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . ($this->columns ? implode(', ', $this->columns) : '*')
            . ' ' . implode(' ', $this->from)
            . $where
            . ($this->group ? ' GROUP BY ' . implode(', ', $this->group) : '')
            . ($this->having ? ' HAVING ' . implode(' AND ', $this->having) : '')
            . ($this->order ? ' ORDER BY ' . implode(', ', $this->order) : '')
            . ($this->limit !== null ? ' LIMIT ' . $this->limit : '')
            . ($this->offset ? ' OFFSET ' . $this->offset : '')
            . ($this->forUpdate ? ' ' . $this->forUpdate : '');
    }

    private function addTable(string $type, string|array $table, ?string $condition, string|array $columns): self
    {
        $alias = is_array($table) ? (string) key($table) : $table;
        $name = is_array($table) ? (string) current($table) : $table;
        $sql = $type . ' ' . $this->adapter->quoteIdentifier($name) . ($alias !== $name ? ' AS ' . $this->adapter->quoteIdentifier($alias) : '');
        $this->from[] = $condition === null ? $sql : $sql . ' ON ' . $condition;

        return $this->columns($columns, $alias);
    }

    private function addWhere(string $type, string $condition, mixed $value, bool $hasValue): self
    {
        $this->where[] = $type . ' (' . ($hasValue ? Adapter::bindInto($condition, $value, $this->bind) : $condition) . ')';

        return $this;
    }

    private function expression(string|Expr $column, ?string $correlation): string
    {
        if ($column instanceof Expr || str_contains($column, '(')) {
            return (string) $column;
        }

        return $this->adapter->quoteIdentifier($correlation !== null && !str_contains($column, '.') ? $correlation . '.' . $column : $column);
    }
}
