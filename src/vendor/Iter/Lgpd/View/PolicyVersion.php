<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Lgpd\Model\PolicyService;

/** one published version, read-only: the text the customers who accepted it saw */
final class PolicyVersion implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/policy-view';
    }

    public function getData(): array
    {
        return ['policy' => (new PolicyService())->get((int) ($_GET['id'] ?? 0))];
    }
}
