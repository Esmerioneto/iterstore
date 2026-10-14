<?php

declare(strict_types=1);

namespace Iter\Log\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Log\Model\OperationLog;

class LogList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'log/list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['from', 'to', 'user', 'action', 'area', 'q', 'entity'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => OperationLog::search($filters, $page, $limit));

        return ['lines' => $result['items'], 'total' => $result['total'], 'filters' => $filters, 'pager' => $pager, 'users' => OperationLog::users()];
    }
}
