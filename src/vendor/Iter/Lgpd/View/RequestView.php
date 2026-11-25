<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Lgpd\Model\PersonalDataService;
use Iter\Lgpd\Model\RequestService;

/** one request: who, what, until when, and the actions that answer it */
final class RequestView implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/request-view';
    }

    public function getData(): array
    {
        $request = (new RequestService())->get((int) ($_GET['id'] ?? 0));
        $customerId = $request['customer_id'] !== null ? (int) $request['customer_id'] : null;
        $canErase = $request['status'] === 'open' && $request['type'] === 'erase' && $customerId !== null && !$request['anonymized_at'];

        return [
            'request' => $request,
            'protocol' => RequestService::protocol((int) $request['request_id']),
            'late' => $request['status'] === 'open' && strtotime((string) $request['due_at']) < time(),
            'canErase' => $canErase && Auth::isAllowed('Iter_Lgpd::erase'),
            'eraseNeedsPermission' => $canErase && !Auth::isAllowed('Iter_Lgpd::erase'),
            'blockers' => $canErase ? (new PersonalDataService())->blockers($customerId) : [],
        ];
    }
}
