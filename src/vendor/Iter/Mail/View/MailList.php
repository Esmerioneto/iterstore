<?php

declare(strict_types=1);

namespace Iter\Mail\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Mail\Model\MailService;

class MailList implements ViewComponentInterface
{
    private const PER_PAGE = 50;

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
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $service = new MailService();
        $result = $service->search($filters, $page, self::PER_PAGE);

        return [
            'messages' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'total' => $result['total'],
            'total_pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'stalled' => $service->stalledCount(),
        ];
    }
}
