<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Admin\Auth;
use Iter\Catalog\Model\ProductFormLayout;
use Iter\Eav\Model\AttributeException;

/** "Organizar tela" on the product form: each admin saves their own layout (fetch, JSON reply) */
class Productlayout
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::product_layout';

    public function Save(): void
    {
        try {
            (new ProductFormLayout())->save((int) Auth::user()->getId(), json_decode((string) ($_POST['layout'] ?? ''), true));
            $this->reply(200, 'Layout salvo.');
        } catch (AttributeException $e) {
            $this->reply(422, $e->getMessage());
        }
    }

    public function Reset(): void
    {
        (new ProductFormLayout())->reset((int) Auth::user()->getId());
        $this->reply(200, 'Layout padrão restaurado.');
    }

    private function reply(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
    }
}
