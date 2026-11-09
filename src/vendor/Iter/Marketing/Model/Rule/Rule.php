<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

use Iter\Core\Config;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Marketing\Model\Unsubscribe;

/**
 * One case of MARKETING-PLAN §3. Settings in marketing/<code>/*, per website: active, mode (review/auto) and
 * the rule's own. A candidate is who the rule picks now; MarketingService filters consent and the per-customer
 * limit, creates the suggestion and, at sending time, asks the rule again (stillValid).
 */
abstract class Rule
{
    /** the paid orders, as Report::PAID (Iter_Report is optional) */
    protected const PAID = "('processing', 'shipped', 'complete')";

    abstract public function code(): string;

    abstract public function label(): string;

    /** @return list<array{customer_id: ?int, email: string, reason_key: string, vars: array<string, string>}> */
    abstract public function candidates(int $websiteId): array;

    /** the reason still holds (the cart wasn't bought, no order since…) */
    public function stillValid(array $suggestion): bool
    {
        return true;
    }

    /** the e-mail template: marketing_<code>; $suggestion = the one being sent (a rule may pick per person) */
    public function template(?array $suggestion = null): string
    {
        return 'marketing_' . $this->code();
    }

    /** the customer's consent is enough; the abandoned cart may skip it (Configurações → Marketing) */
    public function evenWithoutOptin(int $websiteId): bool
    {
        return false;
    }

    public function setting(string $key, int $websiteId): mixed
    {
        return Config::get('marketing/' . $this->code() . '/' . $key, $websiteId);
    }

    protected function db(): Adapter
    {
        return Connection::get()->adapter();
    }

    /** "JOIN … oi": only customers c who checked "Aceita receber ofertas por e-mail", active */
    protected static function optedIn(): string
    {
        return 'JOIN eav_value_boolean oi ON oi.entity_id = c.customer_id AND oi.value = 1 AND oi.attribute_id = ' . Unsubscribe::optinAttributeId();
    }

    /** the store's address in the saved vars: resolved by vars() when previewed or sent, so a suggestion follows a later address change */
    public const SHOP = '{shop}';

    protected static function shopUrl(int $websiteId): string
    {
        return self::SHOP;
    }

    /**
     * A suggestion's saved vars with the store's address of now (Config::storefrontUrl, Configurações → Geral →
     * Endereços da loja web). Older suggestions saved Endereço do site itself: that prefix is swapped too.
     */
    public static function vars(string $json, int $websiteId): array
    {
        $shop = Config::storefrontUrl($websiteId);
        $old = Config::baseUrl($websiteId);
        // ponytail: only links to the shop live in these vars, so a plain prefix swap is enough
        return array_map(static fn ($v) => is_string($v) ? str_replace($old !== '' ? [self::SHOP, $old] : [self::SHOP], $shop, $v) : $v,
            (array) json_decode($json, true));
    }

    /** a candidate of a registered customer, with the vars every e-mail has */
    protected static function customer(array $row, string $reason, int $websiteId, array $vars = []): array
    {
        return [
            'customer_id' => (int) $row['customer_id'],
            'email' => (string) $row['email'],
            'reason_key' => $reason,
            'vars' => $vars + ['name' => trim((string) $row['firstname']), 'link' => self::shopUrl($websiteId)],
        ];
    }
}
