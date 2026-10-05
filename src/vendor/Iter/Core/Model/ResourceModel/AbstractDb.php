<?php

declare(strict_types=1);

namespace Iter\Core\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use Iter\Core\Db\Adapter;
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

    /** @var array<string, array<int, string>> table => column names, read once per request */
    private static array $columns = [];

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

    public function getMainTable(): string
    {
        return $this->mainTable;
    }

    public function getIdFieldName(): string
    {
        return $this->idFieldName;
    }

    public function getConnection(): Adapter
    {
        return Connection::get()->adapter();
    }

    public function load(AbstractModel $object, $value, ?string $field = null): void
    {
        $adapter = $this->getConnection();
        $row = $adapter->fetchRow(
            $adapter->select()->from($this->mainTable)->where($adapter->quoteIdentifier($field ?? $this->idFieldName) . ' = ?', $value)
        );

        $object->unsetData();
        if ($row !== false) {
            $object->addData($row);
        }
    }

    /** the row and whatever afterSave() writes are one transaction */
    public function save(AbstractModel $object): void
    {
        $this->getConnection()->transaction(function () use ($object): void {
            if ($object->getId()) {
                $this->performUpdate($object);
            } else {
                $this->performInsert($object);
            }

            $this->afterSave($object);
        });
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
        $adapter = $this->getConnection();
        $adapter->delete($this->mainTable, [$adapter->quoteIdentifier($this->idFieldName) . ' = ?' => $object->getId()]);
        $object->unsetData();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(?string $orderBy = null): array
    {
        $adapter = $this->getConnection();

        return $adapter->fetchAll($adapter->select()->from($this->mainTable)->order($orderBy ?? $this->idFieldName));
    }

    private function performInsert(AbstractModel $object): void
    {
        $data = $this->filterColumns($object->getData());
        unset($data[$this->idFieldName]);

        // merge (not replace): virtual, non-column fields like a role's
        // "resources" must survive into afterSave()
        $object->addData($this->getConnection()->insert($this->mainTable, $data));
    }

    private function performUpdate(AbstractModel $object): void
    {
        $adapter = $this->getConnection();
        $data = $this->filterColumns($object->getData());
        $id = $data[$this->idFieldName];
        unset($data[$this->idFieldName]);
        $where = [$adapter->quoteIdentifier($this->idFieldName) . ' = ?' => $id];

        if ($data) {
            $adapter->update($this->mainTable, $data, $where);
        }
        // read back what triggers/defaults set (updated_at)
        $row = $adapter->fetchRow($adapter->select()->from($this->mainTable)->where(key($where), $id));
        if ($row !== false) {
            $object->addData($row);
        }
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
        $adapter = $this->getConnection();

        return self::$columns[$this->mainTable] ??= $adapter->fetchCol(
            $adapter->select()->from('information_schema.columns', ['column_name'])
                ->where('table_schema = current_schema()')
                ->where('table_name = ?', $this->mainTable)
        );
    }
}
