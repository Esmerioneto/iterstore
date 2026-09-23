<?php

declare(strict_types=1);

namespace Iter\Core\Model;

use Iter\Core\Model\ResourceModel\AbstractDb;

/**
 * Base for every persisted entity. Concrete models only declare the primary
 * key column and wire their resource model in _construct() — load/save/
 * delete are handled generically here by delegating to the resource, which
 * builds SQL from the object's data instead of hand-written per-field SQL.
 */
abstract class AbstractModel extends DataObject
{
    protected string $_idFieldName = 'id';

    protected ?AbstractDb $_resource = null;

    public function __construct(array $data = [])
    {
        parent::__construct($data);
        $this->_construct();
    }

    /**
     * Override to set $this->_resource = new SomeResourceModel();
     */
    protected function _construct(): void
    {
    }

    public function getResource(): AbstractDb
    {
        if ($this->_resource === null) {
            throw new \RuntimeException('Resource model not set for ' . static::class);
        }

        return $this->_resource;
    }

    public function getId()
    {
        return $this->getData($this->_idFieldName);
    }

    public function setId($id): static
    {
        return $this->setData($this->_idFieldName, $id);
    }

    public function load($id): static
    {
        $this->getResource()->load($this, $id);
        return $this;
    }

    public function save(): static
    {
        $this->getResource()->save($this);
        return $this;
    }

    public function delete(): static
    {
        $this->getResource()->delete($this);
        return $this;
    }
}
