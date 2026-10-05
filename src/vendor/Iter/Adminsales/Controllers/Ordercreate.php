<?php

declare(strict_types=1);

namespace Iter\Adminsales\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Adminsales\Model\AdminCartService;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;
use Iter\Inventory\Model\InventoryException;
use Iter\Sales\Model\CartService;
use Iter\Sales\Model\SalesException;

/**
 * Vendas → Novo pedido (ADMINSALES-PLAN §3): one page per cart being built; each action is a form that
 * changes the cart (Sales' CartService) and comes back to it.
 */
class Ordercreate
{
    public const ADMIN_RESOURCE = 'Iter_Adminsales::create';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Novo pedido'],
        ]);

        echo Layout::renderAdmin('admin_ordercreate_index');
    }

    public function Edit(): void
    {
        try {
            (new AdminCartService())->get((int) ($_GET['id'] ?? 0));
        } catch (SalesException $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/ordercreate/index');
            exit;
        }
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Novo pedido', 'url' => '/admin/ordercreate/index'],
            ['label' => 'Em montagem'],
        ]);

        echo Layout::renderAdmin('admin_ordercreate_edit');
    }

    /** a new cart for a customer (customer_id) or a guest (none) */
    public function Start(): void
    {
        try {
            $customerId = (int) ($_POST['customer_id'] ?? 0) ?: null;
            $service = new AdminCartService();
            $quoteId = $service->resolve($service->create((int) ($_POST['website_id'] ?? 0), $customerId, $this->userId()));
            header('Location: /admin/ordercreate/edit?id=' . $quoteId);
        } catch (SalesException $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/ordercreate/index');
        }
        exit;
    }

    public function Guest(): void
    {
        $this->change(static fn (CartService $c, int $q) => $c->setGuestInfo($q, (string) ($_POST['email'] ?? ''), (string) ($_POST['firstname'] ?? ''),
            (string) ($_POST['lastname'] ?? ''), (string) ($_POST['taxvat'] ?? '')), 'Dados do cliente salvos.');
    }

    public function Additem(): void
    {
        $this->change(static fn (CartService $c, int $q) => $c->addItem($q, (string) ($_POST['sku'] ?? ''), (string) ($_POST['quantity'] ?? '1')), 'Item adicionado.');
    }

    /** quantity 0 removes */
    public function Updateitem(): void
    {
        $this->change(static fn (CartService $c, int $q) => $c->updateItem($q, (int) ($_POST['item_id'] ?? 0), (string) ($_POST['quantity'] ?? '0')), 'Item atualizado.');
    }

    /** type shipping|billing: a saved address (customer_address_id), the typed one (address[...]), or billing = same as delivery (same=1) */
    public function Address(): void
    {
        $type = ($_POST['type'] ?? '') === 'billing' ? 'billing' : 'shipping';
        $savedId = (int) ($_POST['customer_address_id'] ?? 0) ?: null;
        $address = $savedId !== null || ($type === 'billing' && !empty($_POST['same'])) ? null : (array) ($_POST['address'] ?? []);
        $this->change(static fn (CartService $c, int $q) => $c->setAddress($q, $type, $address, $savedId), $type === 'shipping' ? 'Endereço de entrega salvo.' : 'Endereço de cobrança salvo.');
    }

    public function Shipping(): void
    {
        $this->change(static fn (CartService $c, int $q) => $c->setShippingMethod($q, (string) ($_POST['code'] ?? '')), 'Frete escolhido.');
    }

    public function Payment(): void
    {
        $this->change(static fn (CartService $c, int $q) => $c->setPaymentMethod($q, (string) ($_POST['code'] ?? '')), 'Pagamento escolhido.');
    }

    public function Place(): void
    {
        $quoteId = (int) ($_POST['quote_id'] ?? 0);
        $paymentReceived = !empty($_POST['payment_received']);
        if ($paymentReceived && !Auth::isAllowed('Iter_Sales::order_payment')) {
            Messages::addError('O seu perfil não pode confirmar pagamentos: desmarque "pagamento recebido".');
            header('Location: /admin/ordercreate/edit?id=' . $quoteId);
            exit;
        }
        try {
            $service = new AdminCartService();
            $service->get($quoteId);
            $orderId = $service->place($quoteId, $this->userId(), $paymentReceived, !empty($_POST['notify']), (string) ($_POST['comment'] ?? ''));
            Messages::addSuccess('Pedido criado.');
            header('Location: ' . (Auth::isAllowed('Iter_Sales::order') ? '/admin/order/view?id=' . $orderId : '/admin/ordercreate/index'));
        } catch (SalesException | InventoryException $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/ordercreate/edit?id=' . $quoteId);
        }
        exit;
    }

    public function Discard(): void
    {
        (new AdminCartService())->discard((int) ($_POST['quote_id'] ?? 0));
        Messages::addSuccess('Pedido em montagem descartado.');
        header('Location: /admin/ordercreate/index');
        exit;
    }

    /** runs $change on $_POST['quote_id'] (an admin's open cart), then back to it with $success or the error */
    private function change(callable $change, string $success): void
    {
        $quoteId = (int) ($_POST['quote_id'] ?? 0);
        try {
            (new AdminCartService())->get($quoteId);
            $change(new CartService(), $quoteId);
            Messages::addSuccess($success);
        } catch (SalesException | InventoryException | AttributeException $e) {
            Messages::addError($e->getMessage());
        }
        header('Location: /admin/ordercreate/edit?id=' . $quoteId);
        exit;
    }

    private function userId(): int
    {
        return (int) Auth::user()->getId();
    }
}
