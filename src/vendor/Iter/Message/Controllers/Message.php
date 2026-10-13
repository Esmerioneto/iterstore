<?php

declare(strict_types=1);

namespace Iter\Message\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Db\Connection;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;
use Iter\Message\Model\MessageService;

final class Message
{
    public const ADMIN_RESOURCE = 'Iter_Message::manage';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Clientes'], ['label' => 'Mensagens']]);
        echo Layout::renderAdmin('admin_message_index');
    }

    /** opening the conversation reads the customer's messages (the screen's refresh too: the admin is looking at it) */
    public function View(): void
    {
        $id = (int) ($_GET['customer_id'] ?? 0);
        if (!Connection::get()->adapter()->fetchOne('SELECT 1 FROM customer_entity WHERE customer_id = :id', ['id' => $id])) {
            Messages::addError('Cliente não encontrado.');
            header('Location: /admin/message/index');
            exit;
        }
        (new MessageService())->markRead($id, 'admin');
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Mensagens', 'url' => '/admin/message/index'], ['label' => 'Conversa']]);
        echo Layout::renderAdmin('admin_message_view');
    }

    public function Send(): void
    {
        $id = (int) ($_POST['customer_id'] ?? 0);
        try {
            (new MessageService())->send($id, 'admin', (string) ($_POST['body'] ?? ''), (int) Auth::user()->getId());
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage());
            $_SESSION['iter_old_input'] = ['body' => (string) ($_POST['body'] ?? '')]; // a long text isn't lost
        }
        header('Location: /admin/message/view?customer_id=' . $id);
        exit;
    }
}
