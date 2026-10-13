<?php

declare(strict_types=1);
namespace Iter\Promotion\View;
use Iter\Core\Db\Connection;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;
final class PromotionForm implements ViewComponentInterface
{
    public function getTemplate(): string { return 'promotion/form'; }
    public function getData(): array
    {
        $id = (int) ($_GET['id'] ?? 0); $db = Connection::get()->adapter();
        $listIds = static function(string $table, string $column) use ($db,$id): array { return $id ? array_map('intval',$db->fetchCol($db->select()->from($table, [$column])->where('promotion_id = ?', $id))) : []; };
        $promotion = $id ? ($db->fetchRow($db->select()->from('promotion')->where('promotion_id = ?', $id)) ?: null) : null;
        $old = $_SESSION['iter_old_input'] ?? []; unset($_SESSION['iter_old_input']);
        $websites = (new Website())->getList();
        $selected = $listIds('promotion_website','website_id');
        $coupons=[]; $couponMode='unlimited'; if ($id) { $couponRows=$db->fetchAll($db->select()->from('promotion_coupon', ['code','usage_limit','usage_per_customer'])->where('promotion_id = ?', $id)->order('code')); $coupons=array_column($couponRows,'code'); if ($couponRows && $couponRows[0]['usage_limit'] !== null && (int)$couponRows[0]['usage_limit']===1) $couponMode='once_total'; elseif ($couponRows && $couponRows[0]['usage_per_customer'] !== null && (int)$couponRows[0]['usage_per_customer']===1) $couponMode='once_per_customer'; }
        // POST values may be strings (text inputs) or arrays (multi-selects).
        $csv = static function ($value): string {
            if (is_array($value)) return implode(',', array_map('strval', $value));
            return is_string($value) || is_numeric($value) ? (string) $value : '';
        };
        $intList = static function ($value): array {
            if (is_string($value)) $value = $value === '' ? [] : explode(',', $value);
            if (!is_array($value)) return [];
            return array_values(array_filter(array_map('intval', $value), static fn (int $value): bool => $value > 0));
        };
        return ['id'=>$id, 'values'=>array_merge(['name'=>'','description'=>'','is_active'=>1,'application_type'=>'automatic','discount_type'=>'percent','discount_amount'=>'10','minimum_subtotal'=>'','maximum_subtotal'=>'','starts_at'=>'','ends_at'=>'','is_accumulative'=>0,'priority'=>0,'applies_to_sale_price'=>0],$promotion ?: [],$old),
            'websites'=>$websites, 'selected_websites'=>$intList($old['website_ids'] ?? $selected),
            'product_ids'=>$csv($old['product_ids'] ?? $listIds('promotion_product','product_id')),
            'category_ids'=>$csv($old['category_ids'] ?? $listIds('promotion_category','category_id')),
            'attribute_set_ids'=>$csv($old['attribute_set_ids'] ?? $listIds('promotion_attribute_set','attribute_set_id')),
            'existing_coupons'=>$coupons, 'coupon_usage_mode'=>$old['coupon_usage_mode'] ?? $couponMode];
    }
}
