<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Model\AttributeException;
use PDO;

/**
 * Each admin's order of the product form blocks (base, categories, group:<name>,
 * module children such as stock), their width, and the order of the fields
 * inside each group, and the number of grid columns (0 = as many as fit). One layout for every product: blocks a product doesn't
 * have are skipped, blocks missing from the layout go last in the default order.
 *
 * @phpstan-type Layout array{blocks: array<int, array{id: string, wide: bool}>, fields: array<string, string[]>, columns: int}
 */
class ProductFormLayout
{
    public const MAX_COLUMNS = 6;
    private const EMPTY = ['blocks' => [], 'fields' => [], 'columns' => 0];

    /** @return Layout */
    public function get(?int $userId): array
    {
        if (!$userId) {
            return self::EMPTY;
        }
        $stmt = $this->pdo()->prepare('SELECT layout FROM catalog_product_form_layout WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $json = $stmt->fetchColumn();

        return $json === false ? self::EMPTY : (json_decode((string) $json, true) + self::EMPTY);
    }

    /**
     * Saves what the page sent. Blocks and groups the page didn't have (another
     * set's groups) keep their saved place: appended after the sent ones.
     *
     * @param mixed $layout decoded JSON from the page
     */
    public function save(int $userId, mixed $layout): void
    {
        $new = self::validate($layout);
        $old = $this->get($userId);
        $sent = array_column($new['blocks'], 'id');
        foreach ($old['blocks'] as $block) {
            if (!in_array($block['id'], $sent, true)) {
                $new['blocks'][] = $block;
            }
        }
        $new['fields'] += $old['fields'];
        $new['columns'] ??= $old['columns'];

        $this->pdo()->prepare(
            'INSERT INTO catalog_product_form_layout (user_id, layout) VALUES (:id, :layout)
             ON CONFLICT (user_id) DO UPDATE SET layout = EXCLUDED.layout'
        )->execute(['id' => $userId, 'layout' => json_encode($new, JSON_UNESCAPED_UNICODE)]);
    }

    public function reset(int $userId): void
    {
        $this->pdo()->prepare('DELETE FROM catalog_product_form_layout WHERE user_id = :id')->execute(['id' => $userId]);
    }

    /**
     * @param Layout $layout
     * @param array<string, array> $blocks id => block, in the default order
     * @return array<string, array> the same blocks in the user's order, each with 'wide'
     */
    public static function apply(array $layout, array $blocks): array
    {
        $ordered = [];
        foreach ($layout['blocks'] as $saved) {
            if (isset($blocks[$saved['id']])) {
                $ordered[$saved['id']] = $blocks[$saved['id']] + ['wide' => $saved['wide']];
            }
        }
        foreach ($blocks as $id => $block) {
            $ordered[$id] ??= $block + ['wide' => false];
        }

        return $ordered;
    }

    /**
     * @param Layout $layout
     * @param array<int, array{name: string}> $fields a group's fields, in the set's order
     * @return array<int, array{name: string}> the saved codes first, the others after in the set's order
     */
    public static function sortFields(array $layout, string $blockId, array $fields): array
    {
        $rank = array_flip($layout['fields'][$blockId] ?? []);
        $keyed = array_values($fields);
        uksort($keyed, static fn ($a, $b) => [$rank[$keyed[$a]['name']] ?? PHP_INT_MAX, $a] <=> [$rank[$keyed[$b]['name']] ?? PHP_INT_MAX, $b]);

        return array_values($keyed);
    }

    /** @return array{blocks: array, fields: array, columns?: int} columns only when sent */
    private static function validate(mixed $layout): array
    {
        $invalid = new AttributeException('LAYOUT_INVALID', 'Layout inválido.');
        if (!is_array($layout) || !is_array($layout['blocks'] ?? null) || !is_array($layout['fields'] ?? []) || count($layout['blocks']) > 200) {
            throw $invalid;
        }
        $isId = static fn ($id) => is_string($id) && preg_match('/^(group:.{0,100}|[a-z][a-z0-9_]{0,63})$/su', $id);
        $blocks = [];
        foreach ($layout['blocks'] as $block) {
            if (!is_array($block) || !$isId($block['id'] ?? null) || !is_bool($block['wide'] ?? false)) {
                throw $invalid;
            }
            $blocks[$block['id']] = ['id' => $block['id'], 'wide' => (bool) ($block['wide'] ?? false)];
        }
        $fields = [];
        foreach ($layout['fields'] ?? [] as $id => $codes) {
            if (!$isId($id) || !is_array($codes) || count($codes) > 500) {
                throw $invalid;
            }
            foreach ($codes as $code) {
                if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,99}$/', $code)) {
                    throw $invalid;
                }
            }
            $fields[$id] = array_values(array_unique($codes));
        }

        $valid = ['blocks' => array_values($blocks), 'fields' => $fields];
        if (array_key_exists('columns', $layout)) {
            if (!is_int($layout['columns']) || $layout['columns'] < 0 || $layout['columns'] > self::MAX_COLUMNS) {
                throw $invalid;
            }
            $valid['columns'] = $layout['columns'];
        }

        return $valid;
    }

    private function pdo(): PDO
    {
        return Connection::get()->connect();
    }
}
