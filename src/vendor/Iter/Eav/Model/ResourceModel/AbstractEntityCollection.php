<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractCollection;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

/**
 * A collection of an EAV entity (DB-PLAN phase 5), as Magento's Eav\Model\Entity\Collection\AbstractCollection:
 *
 *     $products = (new ProductCollection())
 *         ->addAttributeToSelect(['price', 'weight'])     // or '*'
 *         ->addAttributeToFilter('price', ['gteq' => 10])  // addFieldToFilter's conditions
 *         ->addAttributeToSort('price', 'ASC')
 *         ->setPageSize(20);
 *     $products->getData(); // rows + 'attributes' => code => value, as ValueService::load()
 *
 * Selected values come in one query for the whole page, after it is read (no JOIN per attribute,
 * which would multiply the rows of list attributes). Filters and sorting are subqueries on the
 * attribute's value table.
 */
abstract class AbstractEntityCollection extends AbstractCollection
{
    /** @var ?string[] codes to load; ['*'] = all; null = none */
    private ?array $selected = null;

    private ?array $loaded = null;

    /** @var array<string, array<string, array>> entity type => its attributes by code, read once per process */
    private static array $attributes = [];

    /** Attribute's save and delete call it, so a new attribute is seen at once */
    public static function clearAttributeCache(): void
    {
        self::$attributes = [];
    }

    /** the entity type's code (eav_entity_type.code): 'product', 'customer'... */
    abstract protected function entityType(): string;

    /** the entity id column of the main table */
    protected function entityIdField(): string
    {
        return 'main_table.' . $this->resource->getIdFieldName();
    }

    /** @param string|string[] $codes '*' for all */
    public function addAttributeToSelect(string|array $codes): static
    {
        $codes = (array) $codes;
        $this->selected = in_array('*', $codes, true) || in_array('*', $this->selected ?? [], true)
            ? ['*'] : array_values(array_unique([...$this->selected ?? [], ...$codes]));
        $this->loaded = null;

        return $this;
    }

    /**
     * $condition: addFieldToFilter's (a value, or ['gteq' => 10], ['in' => [...]], ['null' => true]...).
     * 'null' means "no value"; the others need a value. On a list attribute (Múltipla seleção),
     * 'eq' and 'in' mean "has one of".
     */
    public function addAttributeToFilter(string $code, mixed $condition): static
    {
        $attribute = $this->getAttribute($code);
        $exists = 'SELECT 1 FROM eav_value_' . $attribute['storage_type'] . ' v WHERE v.attribute_id = ' . (int) $attribute['attribute_id']
            . ' AND v.entity_id = ' . $this->entityIdField();
        if (is_array($condition) && isset($condition['null']) && count($condition) === 1) {
            $this->select->where("NOT EXISTS ({$exists})");

            return $this;
        }
        if (is_array($condition) && isset($condition['notnull']) && count($condition) === 1) {
            $this->select->where("EXISTS ({$exists})");

            return $this;
        }
        if ((InputTypeRegistry::has($attribute['input_type']) ? InputTypeRegistry::get($attribute['input_type'])['multiple'] : false)) {
            $values = is_array($condition) ? ($condition['in'] ?? (array) ($condition['eq'] ?? throw new \InvalidArgumentException("Em '{$code}' (lista), use eq ou in.")))
                : [$condition];
            $in = implode(', ', array_map(fn ($v) => $this->select->bindValue((string) $v), $values ?: ['']));
            $this->select->where("EXISTS ({$exists} AND jsonb_exists_any(v.value::jsonb, ARRAY[{$in}]::text[]))");

            return $this;
        }
        $this->select->where("EXISTS ({$exists} AND " . $this->condition(new Expr('v.value'), $condition) . ')');

        return $this;
    }

    /** entities without a value go last either way */
    public function addAttributeToSort(string $code, string $direction = 'ASC'): static
    {
        $this->select->order(new Expr('(' . $this->valueSql($code) . ') ' . (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC') . ' NULLS LAST'));

        return $this;
    }

    /**
     * The attribute's value as a scalar subquery: by default main_table's entity; $entity is the SQL that
     * follows "v.entity_id" (e.g. "IN (SELECT ...)") and $select what is read (e.g. "MIN(v.value)").
     */
    public function valueSql(string $code, ?string $entity = null, string $select = 'v.value'): string
    {
        $attribute = $this->getAttribute($code);

        return "SELECT {$select} FROM eav_value_" . $attribute['storage_type'] . ' v WHERE v.attribute_id = ' . (int) $attribute['attribute_id']
            . ' AND v.entity_id ' . ($entity ?? '= ' . $this->entityIdField());
    }

    /** the page's rows, each with 'attributes' (code => value) when addAttributeToSelect() was called */
    public function getData(): array
    {
        $rows = parent::getData();
        if ($this->selected === null || !$rows) {
            return $rows;
        }
        if ($this->loaded === null) {
            $id = $this->resource->getIdFieldName();
            $values = (new ValueService())->loadMany($this->entityType(), array_column($rows, $id), $this->selected === ['*'] ? null : $this->selected);
            $this->loaded = array_map(static fn (array $row) => $row + ['attributes' => $values[(int) $row[$id]] ?? []], $rows);
        }

        return $this->loaded;
    }

    /**
     * @return array the eav_attribute row of the entity type's attribute
     * @throws AttributeException ATTRIBUTE_NOT_FOUND
     */
    public function getAttribute(string $code): array
    {
        $type = $this->entityType();
        if (!isset(self::$attributes[$type])) {
            $resource = new Attribute();
            self::$attributes[$type] = array_column($resource->getByEntityType((int) $resource->getEntityTypes()[$type]['entity_type_id']), null, 'code');
        }

        return self::$attributes[$type][$code] ?? throw new AttributeException('ATTRIBUTE_NOT_FOUND', "Atributo '{$code}' não encontrado.");
    }
}
