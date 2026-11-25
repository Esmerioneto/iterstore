<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Config;

/**
 * The cookie categories the store web's banner offers (LGPD-PLAN §8). The codes are fixed (the banner and
 * Consent Mode map them); the texts come from Configurações → LGPD, per website.
 */
final class CookieCategories
{
    /** code => [label, required]: the necessary ones are always on (the store doesn't work without them) */
    public const CATEGORIES = [
        'necessary' => ['Necessários', true],
        'statistics' => ['Estatística', false],
        'marketing' => ['Marketing', false],
    ];

    /** @return list<array{code: string, label: string, description: string, required: bool}> */
    public static function all(?int $websiteId): array
    {
        $out = [];
        foreach (self::CATEGORIES as $code => [$label, $required]) {
            $out[] = ['code' => $code, 'label' => $label, 'description' => (string) Config::get("lgpd/cookies/{$code}", $websiteId), 'required' => $required];
        }

        return $out;
    }
}
