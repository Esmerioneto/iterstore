<?php

declare(strict_types=1);
namespace Iter\Promotion\Controllers;
use Iter\Admin\Breadcrumb;
use Iter\Admin\Auth;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Promotion\Model\PromotionService;
use Throwable;
final class Promotion
{
    public const ADMIN_RESOURCE = 'Iter_Promotion::manage';
    public function Index(): void { Breadcrumb::set([['label'=>'Dashboard','url'=>'/admin/index'],['label'=>'Vendas'],['label'=>'Promoções e cupons']]); echo Layout::renderAdmin('admin_promotion_index'); }
    public function Create(): void { Breadcrumb::set([['label'=>'Dashboard','url'=>'/admin/index'],['label'=>'Promoções','url'=>'/admin/promotion/index'],['label'=>'Nova']]); echo Layout::renderAdmin('admin_promotion_form'); }
    public function Uses(): void
    {
        if (!Auth::isAllowed('Iter_Promotion::usages')) { http_response_code(403); echo 'Acesso negado.'; return; }
        $id=(int)($_GET['id']??0);
        if (!$this->exists($id)) { Messages::addError('Promoção não encontrada.'); header('Location: /admin/promotion/index'); exit; }
        Breadcrumb::set([['label'=>'Dashboard','url'=>'/admin/index'],['label'=>'Promoções','url'=>'/admin/promotion/index'],['label'=>'Usos']]);
        echo Layout::renderAdmin('admin_promotion_uses');
    }
    public function Edit(): void
    {
        $id=(int)($_GET['id']??0);
        if (!$this->exists($id)) { Messages::addError('Promoção não encontrada.'); header('Location: /admin/promotion/index'); exit; }
        Breadcrumb::set([['label'=>'Dashboard','url'=>'/admin/index'],['label'=>'Promoções','url'=>'/admin/promotion/index'],['label'=>'Editar']]); echo Layout::renderAdmin('admin_promotion_form');
    }
    public function Save(): void
    {
        $id=(int)($_POST['promotion_id']??0) ?: null;
        $startRaw=trim((string)($_POST['starts_at']??'')); $endRaw=trim((string)($_POST['ends_at']??''));
        $invalidDate=($startRaw!=='' && strtotime($startRaw)===false) || ($endRaw!=='' && strtotime($endRaw)===false);
        $data=['name'=>trim((string)($_POST['name']??'')),'description'=>trim((string)($_POST['description']??'')) ?: null,
            'is_active'=>empty($_POST['is_active'])?0:1,'application_type'=>(string)($_POST['application_type']??'automatic'),
            'discount_type'=>(string)($_POST['discount_type']??'percent'),'discount_amount'=>(float)($_POST['discount_amount']??0),
            'minimum_subtotal'=>($_POST['minimum_subtotal']??'')===''?null:(float)$_POST['minimum_subtotal'],
            'maximum_subtotal'=>($_POST['maximum_subtotal']??'')===''?null:(float)$_POST['maximum_subtotal'],
            'starts_at'=>$startRaw===''?null:date('Y-m-d H:i:s',strtotime($startRaw)),
            'ends_at'=>$endRaw===''?null:date('Y-m-d H:i:s',strtotime($endRaw)),
            'is_accumulative'=>empty($_POST['is_accumulative'])?0:1,'priority'=>(int)($_POST['priority']??0),
            'applies_to_sale_price'=>empty($_POST['applies_to_sale_price'])?0:1];
        $couponMode=(string)($_POST['coupon_usage_mode']??'unlimited');
        $websiteIds=$this->ids($_POST['website_ids']??[]); $productIds=$this->ids($_POST['product_ids']??'');
        $categoryIds=$this->ids($_POST['category_ids']??''); $setIds=$this->ids($_POST['attribute_set_ids']??'');
        $redirect=$id?'/admin/promotion/edit?id='.$id:'/admin/promotion/create';
        if ($data['name']==='' || !in_array($data['application_type'],['automatic','coupon'],true) || !in_array($data['discount_type'],['fixed','percent'],true)
            || $invalidDate || $data['discount_amount']<=0 || ($data['discount_type']==='percent' && $data['discount_amount']>100) || !$websiteIds || !in_array($couponMode,['unlimited','once_total','once_per_customer'],true)
            || ($data['minimum_subtotal']!==null && $data['minimum_subtotal']<0) || ($data['maximum_subtotal']!==null && $data['maximum_subtotal']<0)
            || ($data['minimum_subtotal']!==null && $data['maximum_subtotal']!==null && $data['maximum_subtotal']<$data['minimum_subtotal'])
            || ($data['starts_at'] && $data['ends_at'] && strtotime($data['ends_at'])<strtotime($data['starts_at']))) {
            Messages::addError('Revise nome, websites, desconto, subtotais e período da promoção.'); $_SESSION['iter_old_input']=$_POST; header('Location: '.$redirect); exit;
        }
        try {
            Connection::get()->adapter()->transaction(function (Adapter $db) use (&$id, $data, $websiteIds, $productIds, $categoryIds, $setIds, $couponMode): void {
                if ($id) {
                    if (!$this->exists($id)) throw new \RuntimeException('Promoção não encontrada.');
                    $db->update('promotion', $data, ['promotion_id = ?' => $id]);
                } else {
                    $id = (int) $db->insert('promotion', $data)['promotion_id'];
                }
                foreach (['promotion_website'=>['website_id',$websiteIds], 'promotion_product'=>['product_id',$productIds], 'promotion_category'=>['category_id',$categoryIds], 'promotion_attribute_set'=>['attribute_set_id',$setIds]] as $table=>[$column,$ids]) {
                    $db->delete($table, ['promotion_id = ?' => $id]);
                    $db->insertMultiple($table, array_map(static fn ($value) => ['promotion_id' => $id, $column => $value], $ids)); // $ids are unique
                }
                $codes=preg_split('/[\\r\\n,;]+/',(string)($_POST['coupon_codes']??''),-1,PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($codes as $raw) {
                    $code=PromotionService::normalizeCode($raw);
                    $owner=$db->fetchOne($db->select()->from('promotion_coupon', ['promotion_id'])->where('code = ?', $code));
                    if ($owner !== false && (int)$owner !== $id) throw new \RuntimeException("O cupom {$code} já pertence a outra promoção.");
                    if ($owner === false) $db->insert('promotion_coupon', ['promotion_id'=>$id,'code'=>$code]);
                }
                $db->update('promotion_coupon', ['usage_limit' => $couponMode === 'once_total' ? 1 : null, 'usage_per_customer' => $couponMode === 'once_per_customer' ? 1 : null], ['promotion_id = ?' => $id]);
                $count=(int)$db->fetchOne($db->select()->from('promotion_coupon', [new Expr('COUNT(*)')])->where('promotion_id = ?', $id));
                if ($data['application_type']==='coupon' && $count===0) throw new \RuntimeException('Uma promoção por cupom precisa ter ao menos um código de cupom.');
            });
            Messages::addSuccess('Promoção salva com sucesso.'); header('Location: /admin/promotion/index'); exit;
        } catch (Throwable $e) {
            Messages::addError('Não foi possível salvar a promoção: '.$e->getMessage()); $_SESSION['iter_old_input']=$_POST; header('Location: '.$redirect); exit;
        }
    }
    public function Delete(): void
    {
        $id=(int)($_POST['promotion_id']??0);
        try { Connection::get()->adapter()->delete('promotion', ['promotion_id = ?' => $id]); Messages::addSuccess('Promoção removida.'); }
        catch (Throwable) { Messages::addError('Não é possível remover uma promoção que já tenha usos registrados. Desative-a em vez disso.'); }
        header('Location: /admin/promotion/index'); exit;
    }
    private function exists(int $id): bool
    {
        $db=Connection::get()->adapter();
        return $id > 0 && (bool)$db->fetchOne($db->select()->from('promotion', [new Expr('1')])->where('promotion_id = ?', $id));
    }
    private function ids(mixed $raw): array
    {
        $values=is_array($raw)?$raw:(preg_split('/[^0-9]+/',(string)$raw,-1,PREG_SPLIT_NO_EMPTY)?:[]);
        return array_values(array_unique(array_filter(array_map('intval',$values),static fn($id)=>$id>0)));
    }
}
