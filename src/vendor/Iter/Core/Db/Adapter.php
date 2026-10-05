<?php

declare(strict_types=1);

namespace Iter\Core\Db;

use PDO;
use PDOStatement;

/**
 * Object access to the database, with Magento's adapter API
 * (Magento\Framework\DB\Adapter\Pdo\Mysql) made for PostgreSQL.
 * Values are always bound, never written into the SQL; table and column
 * names go through quoteIdentifier(). Transactions nest: only the outermost
 * commit reaches the database, and an inner rollBack dooms the outer one.
 *
 * prepare()/query()/exec() pass through to PDO so the SQL still written by
 * hand keeps working while it's moved to Select (DB-PLAN.md).
 */
final class Adapter
{
    private int $transactionLevel = 0;

    private bool $rolledBack = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function select(): Select
    {
        return new Select($this);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(Select|string $sql, array $bind = []): array
    {
        return $this->run($sql, $bind)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|false the first row */
    public function fetchRow(Select|string $sql, array $bind = []): array|false
    {
        return $this->run($sql, $bind)->fetch(PDO::FETCH_ASSOC);
    }

    /** @return mixed the first column of the first row, false when there's none */
    public function fetchOne(Select|string $sql, array $bind = []): mixed
    {
        return $this->run($sql, $bind)->fetchColumn();
    }

    /** @return array<int, mixed> the first column of every row */
    public function fetchCol(Select|string $sql, array $bind = []): array
    {
        return $this->run($sql, $bind)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<int|string, mixed> first column => second column */
    public function fetchPairs(Select|string $sql, array $bind = []): array
    {
        return $this->run($sql, $bind)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param array<string, mixed> $data column => value (an Expr is written as is)
     * @return array<string, mixed> the inserted row, as the database stored it (RETURNING *)
     */
    public function insert(string $table, array $data): array
    {
        $bind = [];
        $sql = sprintf('INSERT INTO %s %s RETURNING *', $this->quoteIdentifier($table), $this->values([$data], $bind));

        return $this->run($sql, $bind)->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * One INSERT for many rows; the columns come from the first row.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertMultiple(string $table, array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $bind = [];

        return $this->run(sprintf('INSERT INTO %s %s', $this->quoteIdentifier($table), $this->values($rows, $bind)), $bind)->rowCount();
    }

    /**
     * INSERT ... ON CONFLICT: Magento's insertOnDuplicate for PostgreSQL.
     *
     * @param string[] $conflict the unique key's columns
     * @param ?array<int|string, mixed> $update null = every other column takes the new value;
     *        'col' = takes the new value, 'col' => value/Expr = gets that; [] = DO NOTHING
     */
    public function insertOnConflict(string $table, array $data, array $conflict, ?array $update = null): int
    {
        $bind = [];
        $update ??= array_values(array_diff(array_keys($data), $conflict));
        $set = [];
        foreach ($update as $key => $value) {
            $set[] = is_int($key)
                ? sprintf('%1$s = EXCLUDED.%1$s', $this->quoteIdentifier($value))
                : $this->quoteIdentifier($key) . ' = ' . self::bindValue($value, $bind);
        }
        $sql = sprintf(
            'INSERT INTO %s %s ON CONFLICT (%s) DO %s',
            $this->quoteIdentifier($table),
            $this->values([$data], $bind),
            implode(', ', array_map($this->quoteIdentifier(...), $conflict)),
            $set ? 'UPDATE SET ' . implode(', ', $set) : 'NOTHING'
        );

        return $this->run($sql, $bind)->rowCount();
    }

    /**
     * @param array<string, mixed> $data column => value
     * @param array<string, mixed>|string $where ['col = ?' => value, ...] joined with AND
     * @return int affected rows
     */
    public function update(string $table, array $data, array|string $where = []): int
    {
        $bind = [];
        $set = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = ' . self::bindValue($value, $bind);
        }
        $sql = sprintf('UPDATE %s SET %s%s', $this->quoteIdentifier($table), implode(', ', $set), $this->where($where, $bind));

        return $this->run($sql, $bind)->rowCount();
    }

    /**
     * @param array<string, mixed>|string $where as in update(); empty deletes every row
     * @return int affected rows
     */
    public function delete(string $table, array|string $where = []): int
    {
        $bind = [];

        return $this->run('DELETE FROM ' . $this->quoteIdentifier($table) . $this->where($where, $bind), $bind)->rowCount();
    }

    /** "table" or "alias.column" → "table" / "alias"."column"; '*' stays; anything else is refused */
    public function quoteIdentifier(string $name): string
    {
        return implode('.', array_map(static function (string $part) use ($name): string {
            if ($part === '*') {
                return $part;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new \InvalidArgumentException("Nome de tabela ou coluna inválido: '{$name}'.");
            }

            return '"' . $part . '"';
        }, explode('.', $name)));
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
            $this->rolledBack = false;
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('Nenhuma transação aberta.');
        }
        if (--$this->transactionLevel > 0) {
            return;
        }
        // level is already 0: a failing COMMIT still ends the transaction
        if ($this->rolledBack) {
            $this->pdo->rollBack();
            throw new \LogicException('Uma transação interna foi desfeita; a transação inteira foi desfeita.');
        }
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('Nenhuma transação aberta.');
        }
        if (--$this->transactionLevel > 0) {
            $this->rolledBack = true;
            return;
        }
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Runs $callback in a transaction: commit when it returns, rollBack and rethrow when it throws.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
        $this->commit();

        return $result;
    }

    // --- raw SQL, kept while the hand-written queries move to Select ---------------

    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function query(string $sql): PDOStatement
    {
        return $this->pdo->query($sql);
    }

    public function exec(string $sql): int|false
    {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo->lastInsertId($name);
    }

    // --- internals ----------------------------------------------------------------

    /**
     * Replaces each "?" in $condition with a bound placeholder; an array value
     * becomes a list ("id IN (?)" → "id IN (:p1, :p2)"). Shared with Select.
     */
    public static function bindInto(string $condition, mixed $value, array &$bind): string
    {
        // ponytail: a literal "?" inside a quoted string in $condition would be replaced too; none in use
        return preg_replace_callback('/\?/', static function () use ($value, &$bind): string {
            if (!is_array($value)) {
                return self::bindValue($value, $bind);
            }
            $placeholders = [];
            foreach ($value ?: [null] as $v) {
                $placeholders[] = self::bindValue($v, $bind);
            }

            return implode(', ', $placeholders);
        }, $condition);
    }

    /** a new placeholder for $value (an Expr is written as is) */
    public static function bindValue(mixed $value, array &$bind): string
    {
        if ($value instanceof Expr) {
            return (string) $value;
        }
        $name = 'p' . (count($bind) + 1);
        $bind[$name] = is_bool($value) ? (int) $value : $value;

        return ':' . $name;
    }

    /** statements run in this process; the selftests and DB-PLAN's benchmark read it to catch N+1 */
    public static int $queryCount = 0;

    private function run(Select|string $sql, array $bind): PDOStatement
    {
        self::$queryCount++;
        if ($sql instanceof Select) {
            $bind = $sql->getBind() + $bind;
        }
        $stmt = $this->pdo->prepare((string) $sql);
        $stmt->execute($bind);

        return $stmt;
    }

    /** "(col, ...) VALUES (...), (...)" */
    private function values(array $rows, array &$bind): string
    {
        $columns = array_keys(reset($rows));
        $values = [];
        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $placeholders[] = self::bindValue($row[$column] ?? null, $bind);
            }
            $values[] = '(' . implode(', ', $placeholders) . ')';
        }

        return '(' . implode(', ', array_map($this->quoteIdentifier(...), $columns)) . ') VALUES ' . implode(', ', $values);
    }

    private function where(array|string $where, array &$bind): string
    {
        $parts = [];
        foreach ((array) $where as $condition => $value) {
            $parts[] = '(' . (is_int($condition) ? $value : self::bindInto($condition, $value, $bind)) . ')';
        }

        return $parts ? ' WHERE ' . implode(' AND ', $parts) : '';
    }
}
