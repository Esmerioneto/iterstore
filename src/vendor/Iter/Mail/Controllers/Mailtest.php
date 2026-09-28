<?php

declare(strict_types=1);

namespace Iter\Mail\Controllers;

use Iter\Admin\Auth;
use Iter\Core\Config;
use Iter\Core\Messages;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

/** "Enviar e-mail de teste" in Configurações: sends right away to the logged admin, with the chosen website's settings */
class Mailtest
{
    public const ADMIN_RESOURCE = 'Iter_Config::config';

    public function Send(): void
    {
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $websiteId = $websiteId && Config::websiteExists($websiteId) ? $websiteId : null;
        $user = Auth::user();
        $service = new MailService();
        try {
            $id = $service->send('mail_test', (string) $user->getData('email'), [
                'name' => $user->getData('firstname') ?: $user->getData('username'),
            ], ['website_id' => $websiteId], now: true);
            $message = $id ? $service->find($id) : null;
            if ($message === null) {
                Messages::addError('Limite de e-mails de teste por hora atingido. Tente mais tarde.');
            } elseif ((int) $message['status'] === MailService::SENT) {
                Messages::addSuccess('E-mail de teste enviado para ' . $user->getData('email') . '.');
            } else {
                $service->cancel($id); // a test isn't retried by the worker
                Messages::addError('O e-mail de teste não foi enviado: ' . ($message['last_error'] ?: 'envio desativado nas configurações.'));
            }
        } catch (MailException $e) {
            Messages::addError($e->getMessage());
        }

        header('Location: /admin/config/index' . ($websiteId ? '?website=' . $websiteId : ''));
        exit;
    }
}
