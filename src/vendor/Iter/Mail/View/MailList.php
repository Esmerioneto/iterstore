<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Mail\Model\MailService;

class MailList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'mail/list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['status', 'q', 'from', 'to'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $service = new MailService();
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->search($filters, $page, $limit));

        return [
            'messages' => $result['items'],
            'filters' => $filters,
            'pager' => $pager,
            'total' => $result['total'],
            'stalled' => $service->stalledCount(),
        ];
    }
}
