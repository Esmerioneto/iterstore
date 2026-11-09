<?php

declare(strict_types=1);

namespace Iter\Marketing\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Marketing\Model\MarketingService;

/** One suggestion: who, why, and the e-mail as it would go out now */
final class SuggestionView implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'marketing/view';
    }

    public function getData(): array
    {
        $service = new MarketingService();
        $row = (array) $service->find((int) ($_GET['id'] ?? 0));

        return [
            'suggestion' => $row,
            'rule' => (MarketingService::rules()[$row['rule']] ?? null)?->label() ?? $row['rule'],
            'reason' => MarketingService::reasonLabel($row),
            'preview' => $service->preview($row),
        ];
    }
}
