<?php

declare(strict_types=1);

namespace Iter\Tryon\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;
use Iter\Tryon\Model\TryonService;

/** Clientes → Provador virtual (TRYON-PLAN §10): every generation, its cost and errors */
class Tryon
{
    public const ADMIN_RESOURCE = 'Iter_Tryon::manage';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Clientes', 'url' => '/admin/customer/index'], ['label' => 'Provador virtual']]);

        echo Layout::renderAdmin('admin_tryon_index');
    }

    /** the ready image is a customer's photo: opening it goes to the operation log, under the customer */
    public function Image(): void
    {
        $row = (new TryonService())->find((int) ($_GET['id'] ?? 0));
        if ($row === null || !TryonService::imageOpen($row)) {
            Messages::addError('A imagem desta prova não está mais disponível.');
            header('Location: /admin/tryon/index');
            exit;
        }
        OperationLog::record('tryon_image', 'customer', (int) $row['customer_id'], 'Prova #' . (int) $row['tryon_id'] . ' do provador virtual');
        header('Location: ' . $row['image_url']);
        exit;
    }
}
