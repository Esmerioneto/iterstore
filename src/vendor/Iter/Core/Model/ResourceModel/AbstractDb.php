<?php

declare(strict_types=1);

namespace Iter\Core\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use PDO;
use Iter\Core\Db\Connection;

/**
 * Generic table gateway: load/save/delete build their SQL from the table
 * name, primary key and the model's own data array, so entities never need
 * to spell out "INSERT INTO ... (col1, col2, ...) VALUES (...)" by hand.
 * Mirrors Magento\Framework\Model\ResourceModel\Db\AbstractDb.
 */
abstract class AbstractDb
{
    protected string $mainTable;

    protected string $idFieldName;

    /** @var array<int, string>|null column names of $mainTable, cached per instance */
    private ?array $columns = null;

    public function __construct()
    {
        $this->_construct();
    }

    /**
     * Override and call $this->init('table_name', 'id_column');
     */
    abstract protected function _construct(): void;

    protected function init(string $table, string $idFieldName): void
    {
        $this->mainTable = $table;
        $this->idFieldName = $idFieldName;
    }

    protected function getConnection(): PDO
    {
        return Connection::get()->connect();
    }

    public function load(AbstractModel $object, $value, ?string $field = null): void
    {
        $field ??= $this->idFieldName;

        $stmt = $this->getConnection()->prepare(
            sprintf('SELECT * FROM %s WHERE %s = :value', $this->mainTable, $field)
        );
        $stmt->execute(['value' => $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $object->unsetData();
        if ($row !== false) {
            $object->addData($row);
        }
    }

    public function save(AbstractModel $object): void
    {
        if ($object->getId()) {
            $this->performUpdate($object);
        } else {
            $this->performInsert($object);
        }

        $this->afterSave($object);
    }

    /**
     * Hook for subclasses that need to persist related rows after the main
     * row is saved (e.g. a role's granted resources in a child table).
     */
    protected function afterSave(AbstractModel $object): void
    {
    }

    public function delete(AbstractModel $object): void
    {
        $stmt = $this->getConnection()->prepare(
            sprintf('DELETE FROM %s WHERE %s = :id', $this->mainTable, $this->idFieldName)
        );
        $stmt->execute(['id' => $object->getId()]);
        $object->unsetData();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(?string $orderBy = null): array
    {
        $stmt = $this->getConnection()->query(
            sprintf('SELECT * FROM %s ORDER BY %s', $this->mainTable, $orderBy ?? $this->idFieldName)
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function performInsert(AbstractModel $object): void
    {
        $data = $this->filterColumns($object->getData());
        unset($data[$this->idFieldName]);

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING *',
            $this->mainTable,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c) => ":$c", $columns))
        );

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($data);

        // merge (not replace): virtual, non-column fields like a role's
        // "resources" must survive into afterSave()
        $object->addData($stmt->fetch(PDO::FETCH_ASSOC));
    }

    private function performUpdate(AbstractModel $object): void
    {
        $data = $this->filterColumns($object->getData());
        $id = $data[$this->idFieldName];
        unset($data[$this->idFieldName]);

        $set = implode(', ', array_map(static fn (string $c) => "$c = :$c", array_keys($data)));

        // ponytail: bound as :iter_pk (not :id) so a real "id"-named column
        // in $data can never collide with the WHERE placeholder.
        $sql = sprintf('UPDATE %s SET %s WHERE %s = :iter_pk RETURNING *', $this->mainTable, $set, $this->idFieldName);
        $data['iter_pk'] = $id;

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($data);

        $object->addData($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Keeps only keys that are real columns of $mainTable, so incidental
     * form/UI fields on the model (e.g. "password_confirm", "resources")
     * never leak into the generated SQL.
     */
    private function filterColumns(array $data): array
    {
        return array_intersect_key($data, array_flip($this->getColumns()));
    }

    /**
     * @return array<int, string>
     */
    private function getColumns(): array
    {
        if ($this->columns === null) {
            $stmt = $this->getConnection()->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
            );
            $stmt->execute(['table' => $this->mainTable]);
            $this->columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $this->columns;
    }
}
