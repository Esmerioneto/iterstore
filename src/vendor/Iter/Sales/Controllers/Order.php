<?php

declare(strict_types=1);

namespace Iter\Sales\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Inventory\Model\InventoryException;
use Iter\Sales\Model\OrderService;
use Iter\Sales\Model\SalesException;

/** Order service in the admin (COMMERCE-PLAN §7): list, view and the actions the status and the profile allow */
class Order
{
    public const ADMIN_RESOURCE = 'Iter_Sales::order';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Pedidos'],
        ]);

        echo Layout::renderAdmin('admin_order_index');
    }

    public function View(): void
    {
        try {
            (new OrderService())->get((int) ($_GET['id'] ?? 0));
        } catch (SalesException $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/order/index');
            exit;
        }
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Pedidos', 'url' => '/admin/order/index'],
            ['label' => 'Pedido'],
        ]);

        echo Layout::renderAdmin('admin_order_view');
    }

    public function Confirm(): void
    {
        $this->act('Iter_Sales::order_payment', 'Pagamento confirmado.', static fn (OrderService $s, int $id, ?int $user) => $s->confirmPayment($id, $_POST['comment'] ?? null, $user));
    }

    public function Ship(): void
    {
        $this->act('Iter_Sales::order_ship', 'Entrega registrada. O cliente recebeu o rastreio por e-mail.', static fn (OrderService $s, int $id, ?int $user) => $s->ship(
            $id, (int) ($_POST['source_id'] ?? 0), (string) ($_POST['carrier'] ?? ''), (string) ($_POST['tracking_code'] ?? ''), $user
        ));
    }

    public function Complete(): void
    {
        $this->act('Iter_Sales::order_ship', 'Pedido concluído.', static fn (OrderService $s, int $id, ?int $user) => $s->complete($id, $user));
    }

    public function Cancel(): void
    {
        $this->act('Iter_Sales::order_cancel', 'Pedido cancelado. O estoque reservado voltou para a venda.', static fn (OrderService $s, int $id, ?int $user) => $s->cancel(
            $id, (string) ($_POST['cancel_reason'] ?? ''), $user, (string) ($_POST['comment'] ?? ''), !empty($_POST['notify']), (string) ($_POST['refund_note'] ?? ''), !empty($_POST['refund'])
        ));
    }

    public function Comment(): void
    {
        $this->act('Iter_Sales::order', 'Comentário salvo.', static fn (OrderService $s, int $id, ?int $user) => $s->addComment($id, (string) ($_POST['comment'] ?? ''), !empty($_POST['notify']), $user));
    }

    /** runs one action on $_POST['order_id'] when the profile has $resource, then back to the order */
    private function act(string $resource, string $success, callable $action): void
    {
        $id = (int) ($_POST['order_id'] ?? 0);
        if (!Auth::isAllowed($resource)) {
            Messages::addError('O seu perfil não tem permissão para esta ação.');
        } else {
            try {
                $action(new OrderService(), $id, (int) Auth::user()->getId());
                Messages::addSuccess($success);
            } catch (SalesException | InventoryException $e) {
                Messages::addError($e->getMessage());
            }
        }
        header('Location: /admin/order/view?id=' . $id);
        exit;
    }
}
