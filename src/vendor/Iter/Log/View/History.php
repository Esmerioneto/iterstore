<?php

declare(strict_types=1);

namespace Iter\Log\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Log\Model\OperationLog;

/**
 * "Histórico de alterações" under the product and the customer forms: the record's last operations
 * (a product's include its variants'); only when editing. The record comes from the page: /admin/<type>/...?id=
 */
class History implements ViewComponentInterface
{
    public const LIMIT = 10;

    /** the form's controller => the log's entity_type */
    private const TYPES = ['product' => 'product', 'customer' => 'customer'];

    public function getTemplate(): string
    {
        return 'log/history';
    }

    public function getData(): array
    {
        $id = (int) ($_GET['id'] ?? 0);
        $type = self::TYPES[explode('/', (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))[2] ?? ''] ?? null;
        if (!$id || $type === null || !Auth::isAllowed('Iter_Log::view')) {
            return ['lines' => null];
        }
        $entity = $type . ':' . $id;
        $result = OperationLog::search(['entity' => $entity], 1, self::LIMIT);

        return ['lines' => $result['items'], 'total' => $result['total'], 'entity' => $entity, 'own_id' => $id];
    }
}
