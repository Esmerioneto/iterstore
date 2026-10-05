<?php

declare(strict_types=1);

namespace Iter\Shipping\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Sales\Model\SalesException;
use Iter\Shipping\Model\ShippingService;

/** The table rate of a website (Vendas → Faixas de frete); the same as the API's saveShippingTableRates */
class Shippingrate
{
    public const ADMIN_RESOURCE = 'Iter_Shipping::rates';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Faixas de frete'],
        ]);

        echo Layout::renderAdmin('admin_shippingrate_index');
    }

    public function Save(): void
    {
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        // blank rows (the one "+ Adicionar faixa" copies, or one cleared) are left out
        $rows = array_filter(array_map(static fn ($r) => array_map(static fn ($v) => trim((string) $v), (array) $r), (array) ($_POST['rates'] ?? [])),
            static fn (array $r) => implode('', $r) !== '');
        $rows = array_map(static fn (array $r) => [
            'postcode_from' => $r['postcode_from'] ?? '',
            'postcode_to' => $r['postcode_to'] ?? '',
            'weight_to' => ($r['weight_to'] ?? '') === '' ? null : str_replace(',', '.', $r['weight_to']),
            'price' => ($r['price'] ?? '') === '' ? -1 : str_replace(',', '.', $r['price']),
            'days' => $r['days'] ?? 0,
        ], $rows);
        try {
            (new ShippingService())->saveTableRates($websiteId, $rows);
            Messages::addSuccess('Faixas de frete salvas.');
        } catch (SalesException $e) {
            Messages::addError($e->getMessage());
        }
        header('Location: /admin/shippingrate/index?website_id=' . $websiteId);
        exit;
    }
}
