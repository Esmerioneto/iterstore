<?php

declare(strict_types=1);

namespace Iter\Tryon\Grid;

use Iter\Admin\View\GridMassAction;
use Iter\Catalog\Model\Product;
use Iter\Core\Db\Connection;
use Iter\Eav\Model\ValueService;
use Iter\Log\Model\OperationLog;
use Iter\Tryon\Model\TryonService;

/**
 * Catálogo → Produtos, checked products: "Provador virtual: ligar/desligar" (value on/off) and
 * "Tipo de peça no provador" (value tops, bottoms, one-pieces). Saved on the parent, as the variants inherit it.
 */
final class TryonMassAction implements GridMassAction
{
    private const CATEGORY_LABELS = ['tops' => 'Parte de cima', 'bottoms' => 'Parte de baixo', 'one-pieces' => 'Peça inteira'];

    public function apply(array $ids, string $value): array
    {
        [$code, $new, $field, $shown, $message] = match (true) {
            $value === 'on' => [TryonService::ATTRIBUTE, '1', 'Provador virtual', 'Sim', 'Provador virtual ligado'],
            $value === 'off' => [TryonService::ATTRIBUTE, '0', 'Provador virtual', 'Não', 'Provador virtual desligado'],
            isset(self::CATEGORY_LABELS[$value]) => [TryonService::CATEGORY_ATTRIBUTE, $value, 'Tipo de peça no provador', self::CATEGORY_LABELS[$value], 'Tipo de peça "' . self::CATEGORY_LABELS[$value] . '"'],
            default => throw new \InvalidArgumentException("Ação do provador desconhecida: {$value}"),
        };
        $db = Connection::get()->adapter();
        $owners = $ids ? $db->fetchAll('SELECT DISTINCT o.product_id, o.sku, o.name FROM catalog_product p JOIN catalog_product o ON o.product_id = COALESCE(p.parent_id, p.product_id)
            WHERE p.product_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY o.product_id') : [];
        $values = new ValueService();
        $current = $values->loadMany(Product::ENTITY_TYPE, array_column($owners, 'product_id'), [$code]);
        $result = ['changed' => 0, 'same' => 0, 'message' => $message];
        $db->transaction(function () use ($owners, $current, $values, $code, $new, $field, $shown, &$result): void {
            foreach ($owners as $owner) {
                $id = (int) $owner['product_id'];
                $before = $current[$id][$code] ?? null;
                if ($code === TryonService::ATTRIBUTE ? (bool) $before === ($new === '1') : (string) $before === $new) {
                    $result['same']++;
                    continue;
                }
                $values->save(Product::ENTITY_TYPE, $id, [$code => $code === TryonService::ATTRIBUTE ? $new === '1' : $new]);
                OperationLog::active() && OperationLog::record('update', 'product', $id, $owner['sku'] . ' — ' . $owner['name'],
                    [$field => $code === TryonService::ATTRIBUTE ? ($before ? 'Sim' : 'Não') : (self::CATEGORY_LABELS[(string) $before] ?? '')], [$field => $shown]);
                $result['changed']++;
            }
        });

        return $result;
    }
}
