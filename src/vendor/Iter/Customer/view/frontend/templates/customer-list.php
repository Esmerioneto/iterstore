<?php
use Iter\Customer\Model\CustomerService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$select = static function (string $name, string $all, array $options) use ($filters, $e): void { ?>
        <select name="<?= $name ?>">
            <option value=""><?= $e($all) ?></option>
    <?php foreach ($options as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters[$name] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php };
?>
<section class="customer-list">
    <div class="admin-page-head">
        <h2>Clientes</h2>
        <a class="admin-button admin-button-primary" href="/admin/customer/create">+ Novo cliente</a>
    </div>
    <form method="get" action="/admin/customer/index" class="admin-filters">
<?php
$select('customer_type', 'PF e PJ', ['pf' => 'Pessoa física', 'pj' => 'Pessoa jurídica']);
$select('group_id', 'Todos os grupos', $groups);
if (count($websites) > 1) {
    $select('website_id', 'Todos os websites', $websites);
}
$select('is_active', 'Ativos e inativos', ['1' => 'Só ativos', '0' => 'Só inativos']);
?>
        <input type="search" name="q" placeholder="Nome, razão social, e-mail ou CPF/CNPJ" value="<?= $e($filters['q']) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('id', 'ID', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('type', 'Tipo', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Nome', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('email', 'E-mail', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('taxvat', 'CPF/CNPJ', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('group', 'Grupo', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('website', 'Website', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('is_active', 'Status', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('created_at', 'Cadastro', $sort, $dir) ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (empty($customers)): ?>
            <tr><td colspan="10" class="admin-empty">Nenhum cliente encontrado.</td></tr>
<?php else: ?>
    <?php foreach ($customers as $customer): ?>
            <tr>
                <td><?= (int) $customer['customer_id'] ?></td>
                <td><?= strtoupper($e($customer['customer_type'])) ?></td>
                <td><?= $e($customer['customer_type'] === 'pj' ? $customer['company_name'] : $customer['firstname'] . ' ' . $customer['lastname']) ?></td>
                <td><?= $e($customer['email']) ?></td>
                <td><?= $e(CustomerService::formatTaxvat($customer['taxvat'])) ?></td>
                <td><?= $e($customer['group_name']) ?></td>
                <td><?= $e($customer['website_name']) ?></td>
                <td<?= $customer['is_active'] ? '' : ' class="customer-inactive"' ?>><span class="admin-status admin-status-<?= $customer['is_active'] ? 'on' : 'off' ?>"><?= $customer['is_active'] ? 'Ativo' : 'Inativo' ?></span></td>
                <td><?= $e(date('d/m/Y', strtotime((string) $customer['created_at']))) ?></td>
                <td class="admin-row-action">
                    <a href="/admin/customer/edit?id=<?= (int) $customer['customer_id'] ?>">Editar</a>
                    <form method="post" action="/admin/customer/delete" data-confirm="Remover este cliente?">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                        <button type="submit">Remover</button>
                    </form>
                </td>
            </tr>
    <?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
