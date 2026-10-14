<?php

declare(strict_types=1);

namespace Iter\Log\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Log\Model\OperationLog;

/** "Histórico de alterações" under the product form: its last operations (and its variants'); only when editing */
class History implements ViewComponentInterface
{
    public const LIMIT = 10;

    public function getTemplate(): string
    {
        return 'log/history';
    }

    public function getData(): array
    {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id || !Auth::isAllowed('Iter_Log::view')) {
            return ['lines' => null];
        }
        $entity = 'product:' . $id;
        $result = OperationLog::search(['entity' => $entity], 1, self::LIMIT);

        return ['lines' => $result['items'], 'total' => $result['total'], 'entity' => $entity, 'own_id' => $id];
    }
}
