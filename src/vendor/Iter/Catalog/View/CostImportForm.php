<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Controllers\Productcost;
use Iter\Catalog\Model\CostImport;
use Iter\Core\View\ViewComponentInterface;

class CostImportForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/cost-import';
    }

    public function getData(): array
    {
        $errors = $_SESSION[Productcost::ERRORS] ?? [];
        unset($_SESSION[Productcost::ERRORS]);

        return ['max_lines' => CostImport::MAX_LINES, 'errors' => $errors];
    }
}
