<?php

declare(strict_types=1);

namespace Iter\Log\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Log\Model\OperationLog;

class LogView implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'log/view';
    }

    public function getData(): array
    {
        $line = OperationLog::find((int) ($_GET['id'] ?? 0));

        return ['line' => $line, 'link' => self::link($line)];
    }

    /** the record's screen, while it exists */
    private static function link(array $line): ?string
    {
        return match ($line['entity_type']) {
            'product' => \isModuleEnabled('iter_catalog') && (new \Iter\Catalog\Model\Product())->load((int) $line['entity_id'])->getId() ? '/admin/product/edit?id=' . (int) $line['entity_id'] : null,
            'config' => '/admin/config/index' . ($line['entity_id'] ? '?website=' . (int) $line['entity_id'] : ''),
            default => null,
        };
    }
}
