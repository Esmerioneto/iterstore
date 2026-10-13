<?php

declare(strict_types=1);
namespace Iter\Promotion\View;
use Iter\Core\Db\Connection;
use Iter\Core\View\ViewComponentInterface;
final class PromotionUses implements ViewComponentInterface
{
    public function getTemplate(): string { return 'promotion/uses'; }
    public function getData(): array
    {
        $id=(int)($_GET['id']??0); $db=Connection::get()->adapter();
        $promotion=$db->fetchRow('SELECT promotion_id,name FROM promotion WHERE promotion_id=:id', ['id'=>$id]) ?: null;
        if (!$promotion) return ['promotion'=>null,'uses'=>[]];
        $uses=$db->fetchAll("SELECT d.discount_id AS usage_id,d.order_id,o.customer_id,d.discount_amount,COALESCE(u.status,'applied') AS status,o.created_at,d.coupon_code AS code,o.increment_id,o.email,o.firstname,o.lastname,o.status AS order_status
            FROM sales_order_discount d JOIN sales_order o ON o.order_id=d.order_id
            LEFT JOIN promotion_coupon c ON c.code=d.coupon_code
            LEFT JOIN promotion_coupon_usage u ON u.coupon_id=c.coupon_id AND u.order_id=o.order_id
            WHERE d.promotion_id=:id ORDER BY o.created_at DESC,d.discount_id DESC", ['id'=>$id]);
        return ['promotion'=>$promotion,'uses'=>$uses];
    }
}
