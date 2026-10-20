<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Catalog\Model\CostImport;
use Iter\Core\Messages;
use Iter\Core\View\Layout;

/** Catálogo → Produtos → Importar custos: the cost of many products from a .csv or .xlsx */
class Productcost
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::cost';

    /** errors shown at most; the rest is summed up */
    private const SHOWN_ERRORS = 20;

    /** the last import's errors, in the session until the page shows them */
    public const ERRORS = 'iter_cost_import_errors';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Produtos', 'url' => '/admin/product/index'],
            ['label' => 'Importar custos'],
        ]);

        echo Layout::renderAdmin('admin_productcost_index');
    }

    /** the model file: every SKU that takes a cost, with its cost now */
    public function Template(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="custos_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['SKU', 'Nome', 'Custo'], ';', '"', '');
        foreach ((new CostImport())->template() as $row) {
            fputcsv($out, $row, ';', '"', '');
        }
        fclose($out);
        exit;
    }

    public function Import(): void
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            Messages::addError('Escolha o arquivo .csv ou .xlsx.');
            header('Location: /admin/productcost/index');
            exit;
        }
        $import = new CostImport();
        $result = $import->run($file['tmp_name'], (string) $file['name']);
        if ($result === null) {
            Messages::addError('Nenhum custo foi salvo: corrija o arquivo e envie de novo.');
            // the lines go on the page (CostImportForm shows them once), not one toast each
            $_SESSION[self::ERRORS] = array_slice($import->errors, 0, self::SHOWN_ERRORS);
            if (count($import->errors) > self::SHOWN_ERRORS) {
                $_SESSION[self::ERRORS][] = '… e mais ' . (count($import->errors) - self::SHOWN_ERRORS) . ' linha(s) com problema.';
            }
        } else {
            Messages::addSuccess("Custos importados: {$result['changed']} alterado(s), {$result['same']} já estava(m) igual(is)" . ($result['empty'] ? ", {$result['empty']} linha(s) sem custo, mantida(s)." : '.'));
        }
        header('Location: /admin/productcost/index');
        exit;
    }
}
