<?php

declare(strict_types=1);

namespace Iter\Inventory\Model;

use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Store\Model\Website;

/**
 * Stock sources. The code and the website are set on creation and never change;
 * a source only holds stock of products in its website. The default source is
 * always there and enabled. Disabling a source takes it out of the
 * salable quantity but keeps its quantities.
 */
class SourceService
{
    public function create(array $data): Source
    {
        $code = trim((string) ($data['code'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
            throw new InventoryException('SOURCE_CODE_INVALID', 'Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 64).');
        }
        $source = new Source();
        if ($source->getResource()->codeExists($code)) {
            throw new InventoryException('SOURCE_CODE_DUPLICATE', "Já existe uma origem com o código '{$code}'.");
        }

        $websiteId = (int) ($data['website_id'] ?? 0);
        if ($websiteId <= 0 || !(new Website())->load($websiteId)->getId()) {
            throw new InventoryException('WEBSITE_INVALID', 'Selecione um website.');
        }

        return $source->addData(['code' => $code, 'website_id' => $websiteId] + $this->fields($data))->save();
    }

    /** the code and the website in $data are ignored: they're immutable */
    public function update(int $id, array $data): Source
    {
        $source = $this->get($id);
        $fields = $this->fields($data);
        if ($source->getData('code') === Source::DEFAULT_CODE && !$fields['is_enabled']) {
            throw new InventoryException('SOURCE_DEFAULT_LOCKED', 'A origem padrão não pode ser desativada.');
        }

        return $source->addData($fields)->save();
    }

    public function delete(int $id): void
    {
        $source = $this->get($id);
        if ($source->getData('code') === Source::DEFAULT_CODE) {
            throw new InventoryException('SOURCE_DEFAULT_LOCKED', 'A origem padrão não pode ser removida.');
        }

        Connection::get()->adapter()->transaction(static function ($db) use ($id, $source): void {
            // FOR UPDATE: a stock save racing this delete waits, then fails on the FK
            if ($db->fetchOne($db->select()->from('inventory_source_item', [new Expr('1')])->where('source_id = ?', $id)->where('quantity > 0')->forUpdate())) {
                throw new InventoryException('SOURCE_HAS_STOCK', "A origem '{$source->getData('name')}' tem estoque. Zere as quantidades ou desative a origem.");
            }
            // what's left are zero rows marked "out of stock"
            $db->delete('inventory_source_item', ['source_id = ?' => $id]);
            $db->delete('inventory_source', ['source_id = ?' => $id]);
        });
    }

    public function get(int $id): Source
    {
        $source = (new Source())->load($id);
        if (!$source->getId()) {
            throw new InventoryException('SOURCE_NOT_FOUND', 'Origem não encontrada.');
        }

        return $source;
    }

    private function fields(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InventoryException('SOURCE_NAME_REQUIRED', 'Nome é obrigatório (máx. 255 caracteres).');
        }
        $country = strtoupper(trim((string) ($data['country_code'] ?? '')) ?: 'BR');
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InventoryException('SOURCE_COUNTRY_INVALID', 'País deve ser o código de 2 letras (ex.: BR).');
        }
        $optional = static fn (string $key, int $max) => mb_substr(trim((string) ($data[$key] ?? '')), 0, $max) ?: null;

        return [
            'name' => $name,
            'is_enabled' => empty($data['is_enabled']) ? 0 : 1,
            'description' => $optional('description', 65535),
            'postcode' => $optional('postcode', 16),
            'city' => $optional('city', 128),
            'region' => $optional('region', 128),
            'country_code' => $country,
            'priority' => max(-2147483648, min(2147483647, (int) ($data['priority'] ?? 0))),
        ];
    }
}
