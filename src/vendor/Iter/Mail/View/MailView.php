<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Mail\Model\MailService;

class MailView implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'mail/view';
    }

    public function getData(): array
    {
        $message = (new MailService())->find((int) ($_GET['id'] ?? 0));

        return ['message' => $message, 'recipients' => json_decode($message['recipients'], true)];
    }
}
