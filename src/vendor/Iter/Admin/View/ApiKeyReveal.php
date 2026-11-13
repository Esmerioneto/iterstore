<?php

declare(strict_types=1);

namespace Iter\Admin\View;

use Iter\Core\View\ViewComponentInterface;

/** Sistema → Chaves da API → "Ver chave": the password form, then the key (set by the controller) */
class ApiKeyReveal implements ViewComponentInterface
{
    /** the api_key row */
    public static ?array $key = null;

    /** the key itself, after the password check, for this response only */
    public static ?string $plain = null;

    public function getTemplate(): string
    {
        return 'admin/apikey-reveal';
    }

    public function getData(): array
    {
        return ['key' => self::$key, 'plain' => self::$plain];
    }
}
