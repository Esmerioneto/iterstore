<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Config;
use Iter\Core\Db\Connection;

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

    /**
     * Records a browser's choice (proof, LGPD-PLAN §8). The required categories always go in. $policyVersion is the
     * version shown with the notice: null (none published yet) or one of the website's published versions.
     *
     * @param list<string> $categories
     * @throws LgpdException INVALID_INPUT
     */
    public static function saveConsent(int $websiteId, string $visitorId, array $categories, ?int $policyVersion, ?int $customerId, string $ip): void
    {
        $db = Connection::get()->adapter();
        if (!Config::websiteExists($websiteId)) {
            throw new LgpdException('INVALID_INPUT', 'Website desconhecido.');
        }
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $visitorId)) {
            throw new LgpdException('INVALID_INPUT', 'visitorId: de 16 a 64 letras, números ou hífens.');
        }
        if ($unknown = array_diff($categories, array_keys(self::CATEGORIES))) {
            throw new LgpdException('INVALID_INPUT', 'Categoria desconhecida: ' . implode(', ', $unknown) . '.');
        }
        if ($policyVersion !== null && !$db->fetchOne('SELECT 1 FROM lgpd_policy WHERE website_id = :w AND version = :v', ['w' => $websiteId, 'v' => $policyVersion])) {
            throw new LgpdException('INVALID_INPUT', 'Versão da política desconhecida.');
        }
        $chosen = array_keys(array_filter(self::CATEGORIES, static fn ($c, $code) => $c[1] || in_array($code, $categories, true), ARRAY_FILTER_USE_BOTH));
        $db->insert('lgpd_cookie_consent', ['website_id' => $websiteId, 'visitor_id' => $visitorId, 'customer_id' => $customerId,
            'categories' => implode(',', $chosen), 'policy_version' => $policyVersion, 'ip' => PolicyService::truncatedIp($ip)]);
    }
}
