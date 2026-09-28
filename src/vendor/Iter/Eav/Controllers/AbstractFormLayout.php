<?php

declare(strict_types=1);

namespace Iter\Eav\Controllers;

use Iter\Admin\Auth;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\FormLayout;

/**
 * "Organizar tela" of an entity form: each admin saves their own layout (fetch, JSON reply).
 * Each module routes a subclass with its own ADMIN_RESOURCE and ENTITY_TYPE
 * (Catalog's Productlayout, Customer's Customerlayout), so the permission stays per entity.
 */
abstract class AbstractFormLayout
{
    protected const ENTITY_TYPE = '';

    public function Save(): void
    {
        try {
            (new FormLayout(static::ENTITY_TYPE))->save((int) Auth::user()->getId(), json_decode((string) ($_POST['layout'] ?? ''), true));
            $this->reply(200, 'Layout salvo.');
        } catch (AttributeException $e) {
            $this->reply(422, $e->getMessage());
        }
    }

    public function Reset(): void
    {
        (new FormLayout(static::ENTITY_TYPE))->reset((int) Auth::user()->getId());
        $this->reply(200, 'Layout padrão restaurado.');
    }

    private function reply(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
    }
}
