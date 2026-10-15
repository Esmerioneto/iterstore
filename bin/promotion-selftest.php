<?php

declare(strict_types=1);

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Db\Connection;
use Iter\Promotion\Model\PromotionService;
use Iter\Promotion\View\PromotionUses;
use Iter\Sales\Model\CartService;

function promoAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FALHOU: {$message}\n"); exit(1); }
    echo "OK: {$message}\n";
}

$db = Connection::get()->connect();
$websiteId = (int) $db->query('SELECT website_id FROM store_website ORDER BY website_id LIMIT 1')->fetchColumn();
$productId = (int) $db->query('SELECT product_id FROM catalog_product ORDER BY product_id LIMIT 1')->fetchColumn();
if (!$websiteId || !$productId) { fwrite(STDERR, "É necessário ter ao menos um website e produto para executar o teste de promoção.\n"); exit(2); }

$service = new PromotionService();
promoAssert(PromotionService::normalizeCode(' jardim_10 ') === 'JARDIM_10', 'normalização de cupom remove espaços e usa maiúsculas');
try { PromotionService::normalizeCode('CUPOM COM ESPAÇO'); promoAssert(false, 'cupom inválido recusado'); }
catch (\Iter\Sales\Model\SalesException) { promoAssert(true, 'cupom inválido recusado'); }

$db->beginTransaction();
try {
    $insert = $db->prepare("INSERT INTO promotion (name,is_active,application_type,discount_type,discount_amount,is_accumulative,priority,applies_to_sale_price) VALUES (:name,1,:application,:type,:amount,:accum,:priority,0) RETURNING promotion_id");
    $create = static function(string $name, string $application, string $type, float $amount, int $accum, int $priority) use ($insert, $db, $websiteId): int {
        $insert->execute(['name'=>$name,'application'=>$application,'type'=>$type,'amount'=>$amount,'accum'=>$accum,'priority'=>$priority]);
        $id=(int)$insert->fetchColumn();
        $db->prepare('INSERT INTO promotion_website (promotion_id,website_id) VALUES (:id,:website)')->execute(['id'=>$id,'website'=>$websiteId]);
        return $id;
    };
    $create('Selftest 10%', 'automatic', 'percent', 10, 1, 10);
    $create('Selftest R$ 5', 'automatic', 'fixed', 5, 1, 20);
    $couponPromotion = $create('Selftest cupom 20%', 'coupon', 'percent', 20, 0, 30);
    $db->prepare('INSERT INTO promotion_coupon (promotion_id,code) VALUES (:id,:code)')->execute(['id'=>$couponPromotion,'code'=>'PROMOSELFTEST']);
    $guestToken=(new CartService())->create($websiteId);
    $quoteIdQuery=$db->prepare('SELECT quote_id FROM sales_quote WHERE token_hash=:hash'); $quoteIdQuery->execute(['hash'=>hash('sha256',$guestToken)]); $quoteId=(int)$quoteIdQuery->fetchColumn();
    $service->applyCoupon($quoteId,' promoselftest ');
    $storedCode=$db->prepare('SELECT coupon_code FROM sales_quote WHERE quote_id=:id'); $storedCode->execute(['id'=>$quoteId]);
    promoAssert($storedCode->fetchColumn()==='PROMOSELFTEST', 'aplicar cupom normaliza e grava o código no carrinho');
    $service->removeCoupon($quoteId); $storedCode->execute(['id'=>$quoteId]); $removedCode=$storedCode->fetchColumn();
    promoAssert($removedCode===false || $removedCode===null, 'remover cupom limpa o código do carrinho');
    $quote=['website_id'=>$websiteId,'customer_id'=>null,'coupon_code'=>null];
    $items=[['product_id'=>$productId,'sku'=>'PROMO-SELFTEST','name'=>'Produto de teste','quantity'=>1.0,'price'=>100.0,'regular_price'=>null,'row_total'=>100.0]];
    $automatic=$service->calculate($quote,$items,100.0);
    promoAssert(abs($automatic['discount_amount']-15.0)<0.001, 'promoções acumulativas somam percentagem e valor fixo');
    promoAssert(abs($automatic['items'][0]['discount_amount']-15.0)<0.001 && abs($automatic['items'][0]['row_total_after_discount']-85.0)<0.001, 'desconto é alocado ao item e calcula o total líquido');
    $withCoupon=$service->calculate(array_replace($quote,['coupon_code'=>'PROMOSELFTEST']),$items,100.0);
    promoAssert(abs($withCoupon['discount_amount']-20.0)<0.001 && $withCoupon['coupon_code']==='PROMOSELFTEST', 'cupom com maior benefício vence o cenário automático');
    $cappedPromotion=$create('Selftest teto', 'automatic', 'fixed', 500, 1, 40);
    $capped=$service->calculate($quote,$items,100.0);
    promoAssert($capped['discount_amount'] <= 100.0 && $capped['items'][0]['discount_amount'] <= $items[0]['row_total'], 'desconto nunca ultrapassa o valor dos itens');
    $_GET['id']=$cappedPromotion; $usagePage=(new PromotionUses())->getData();
    promoAssert((int)($usagePage['promotion']['promotion_id'] ?? 0)===$cappedPromotion && $usagePage['uses']===[], 'consulta administrativa de usos aceita uma promoção sem resgates');

    // which items a promotion takes: category, attribute set, subtotal range, items already on sale. All rolled back.
    $db->exec('UPDATE promotion SET is_active = 0'); // the store's own campaigns and the ones above stay out of the way
    $catalog = $db->query("SELECT p.product_id, p.attribute_set_id, MIN(cp.category_id) AS category_id FROM catalog_product p
        JOIN catalog_category_product cp ON cp.product_id = p.product_id WHERE p.parent_id IS NULL GROUP BY p.product_id ORDER BY p.product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $otherProduct = (int) $db->query('SELECT product_id FROM catalog_product WHERE product_id <> ' . (int) $catalog['product_id'] . ' ORDER BY product_id LIMIT 1')->fetchColumn();
    $otherCategory = (int) $db->query('SELECT category_id FROM catalog_category WHERE category_id NOT IN (SELECT category_id FROM catalog_category_product WHERE product_id = ' . (int) $catalog['product_id'] . ') ORDER BY category_id LIMIT 1')->fetchColumn();
    $otherSet = (int) $db->query('SELECT attribute_set_id FROM catalog_product WHERE attribute_set_id <> ' . (int) $catalog['attribute_set_id'] . ' LIMIT 1')->fetchColumn();
    if (!$catalog || !$otherProduct || !$otherCategory || !$otherSet) { fwrite(STDERR, "É necessário um produto com categoria, outra categoria e outro conjunto de atributos.\n"); exit(2); }
    $line = static fn (int $productId, float $price, ?float $regular = null) => ['product_id' => $productId, 'sku' => 'S' . $productId, 'name' => 'Teste', 'quantity' => 1.0, 'price' => $price, 'regular_price' => $regular, 'row_total' => $price];
    $rule = static function (array $set, array $links, array $lines) use ($db, $create, $service, $websiteId): float {
        $id = $create('Selftest regra', 'automatic', 'percent', 10, 0, 1);
        if ($set) $db->prepare('UPDATE promotion SET ' . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($set))) . ' WHERE promotion_id = :id')->execute($set + ['id' => $id]);
        foreach ($links as $table => [$column, $value]) $db->prepare("INSERT INTO {$table} (promotion_id, {$column}) VALUES (:id, :value)")->execute(['id' => $id, 'value' => $value]);
        $result = $service->calculate(['website_id' => $websiteId, 'customer_id' => null, 'coupon_code' => null], $lines, array_sum(array_column($lines, 'row_total')));
        $db->prepare('UPDATE promotion SET is_active = 0 WHERE promotion_id = :id')->execute(['id' => $id]);
        return $result['discount_amount'];
    };
    $mine = $line((int) $catalog['product_id'], 100.0);
    $other = $line($otherProduct, 50.0);
    promoAssert([10.0, 0.0] === [$rule([], ['promotion_category' => ['category_id', (int) $catalog['category_id']]], [$mine, $other]), $rule([], ['promotion_category' => ['category_id', $otherCategory]], [$mine])],
        'categoria: só o item da categoria escolhida tem desconto; produto fora dela não tem');
    promoAssert([10.0, 0.0] === [$rule([], ['promotion_attribute_set' => ['attribute_set_id', (int) $catalog['attribute_set_id']]], [$mine]),
        $rule([], ['promotion_attribute_set' => ['attribute_set_id', $otherSet]], [$mine])],
        'conjunto de atributos: vale só para produtos do conjunto escolhido');
    promoAssert([0.0, 10.0, 10.0, 0.0] === [
        $rule(['minimum_subtotal' => 100.01], [], [$mine]), $rule(['minimum_subtotal' => 100], [], [$mine]),
        $rule(['maximum_subtotal' => 100], [], [$mine]), $rule(['maximum_subtotal' => 99.99], [], [$mine]),
    ], 'subtotal mínimo e máximo: R$ 100 vale com mínimo 100 e máximo 100; não vale com mínimo 100,01 nem com máximo 99,99');
    $variant = $db->query('SELECT v.product_id, v.parent_id FROM catalog_product v WHERE v.parent_id IS NOT NULL ORDER BY v.product_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$variant) { fwrite(STDERR, "É necessário um produto com grade (com variações) para o teste.\n"); exit(2); }
    $parentCategory = (int) $db->query('SELECT MIN(category_id) FROM catalog_category_product WHERE product_id = ' . (int) $variant['parent_id'])->fetchColumn();
    $variantLine = $line((int) $variant['product_id'], 100.0);
    promoAssert([10.0, $parentCategory ? 10.0 : 0.0] === [$rule([], ['promotion_product' => ['product_id', (int) $variant['parent_id']]], [$variantLine]),
        $parentCategory ? $rule([], ['promotion_category' => ['category_id', $parentCategory]], [$variantLine]) : 0.0],
        'variação: a promoção do produto pai e a da categoria do pai valem para as variações dele');
    $onSale = $line((int) $catalog['product_id'], 80.0, 100.0);
    promoAssert([0.0, 8.0] === [$rule([], [], [$onSale]), $rule(['applies_to_sale_price' => 1], [], [$onSale])],
        'item já em promoção (preço abaixo do cheio): sem desconto, a não ser que a regra permita; o desconto é sobre o preço promocional');
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
