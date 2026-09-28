<?php

declare(strict_types=1);

namespace Iter\Mail\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

/** History of the e-mails (mail_queue): list, view, resend, cancel */
class Mail
{
    public const ADMIN_RESOURCE = 'Iter_Mail::log';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'E-mails enviados'],
        ]);

        echo Layout::renderAdmin('admin_mail_index');
    }

    public function View(): void
    {
        if (!(new MailService())->find((int) ($_GET['id'] ?? 0))) {
            Messages::addError('E-mail não encontrado.');
            header('Location: /admin/mail/index');
            exit;
        }
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'E-mails enviados', 'url' => '/admin/mail/index'],
            ['label' => 'E-mail'],
        ]);

        echo Layout::renderAdmin('admin_mail_view');
    }

    public function Resend(): void
    {
        try {
            $id = (new MailService())->resend((int) ($_POST['message_id'] ?? 0));
            Messages::addSuccess('Uma cópia do e-mail entrou na fila de envio.');
            header('Location: /admin/mail/view?id=' . $id);
        } catch (MailException $e) {
            Messages::addError($e->getMessage());
            header('Location: /admin/mail/index');
        }
        exit;
    }

    public function Cancel(): void
    {
        $id = (int) ($_POST['message_id'] ?? 0);
        (new MailService())->cancel($id)
            ? Messages::addSuccess('Envio cancelado.')
            : Messages::addError('Só um e-mail pendente pode ser cancelado.');
        header('Location: /admin/mail/view?id=' . $id);
        exit;
    }
}
