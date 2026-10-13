<?php

declare(strict_types=1);

namespace Iter\Message\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\Customer;
use Iter\Message\Model\MessageService;
use Iter\Store\Model\Website;

/** Caixa de entrada: one customer per row; "Nova conversa" finds any customer (?find=) */
final class MessageList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'message/list';
    }

    public function getData(): array
    {
        $filters = [
            'unread_only' => !empty($_GET['unread_only']),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'website_id' => (int) ($_GET['website_id'] ?? 0) ?: null,
        ];
        $service = new MessageService();
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->inbox($filters, $page, $limit));
        $find = trim((string) ($_GET['find'] ?? ''));

        return [
            'conversations' => $result['items'],
            'filters' => $filters,
            'pager' => $pager,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'find' => $find,
            // ponytail: the first 10 matches; a pager here when stores search by something vaguer than name/e-mail/CPF
            'found' => $find === '' ? [] : (new Customer())->getResource()->search(['q' => $find], 1, 10)['items'],
        ];
    }
}
