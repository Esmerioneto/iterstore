<?php

declare(strict_types=1);

namespace Iter\Core\Model;

/**
 * Generic key/value container with Magento-style magic accessors
 * (getUsername() / setUsername($v) map to the "username" data key).
 */
class DataObject
{
    /** @var array<string, mixed> */
    protected array $_data = [];

    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    public function getData(?string $key = null)
    {
        if ($key === null) {
            return $this->_data;
        }

        return $this->_data[$key] ?? null;
    }

    public function setData(string $key, $value): static
    {
        $this->_data[$key] = $value;
        return $this;
    }

    public function addData(array $data): static
    {
        $this->_data = array_merge($this->_data, $data);
        return $this;
    }

    public function hasData(string $key): bool
    {
        return array_key_exists($key, $this->_data);
    }

    public function unsetData(?string $key = null): static
    {
        if ($key === null) {
            $this->_data = [];
        } else {
            unset($this->_data[$key]);
        }

        return $this;
    }

    public function __call(string $method, array $args)
    {
        $prefix = substr($method, 0, 3);
        $key = $this->underscore(substr($method, 3));

        if ($prefix === 'get') {
            return $this->getData($key);
        }

        if ($prefix === 'set') {
            return $this->setData($key, $args[0] ?? null);
        }

        if ($prefix === 'has') {
            return $this->hasData($key);
        }

        throw new \BadMethodCallException('Method ' . static::class . '::' . $method . ' does not exist');
    }

    private function underscore(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
