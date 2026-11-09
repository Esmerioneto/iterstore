<?php

declare(strict_types=1);

namespace Iter\Marketing\Model;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Customer\Model\Customer;

/**
 * "Não quero mais receber" (MARKETING-PLAN §5): a signed link per e-mail and website, with no expiry.
 * A customer loses the consent (the trigger records it); a visitor goes to marketing_unsubscribe.
 */
final class Unsubscribe
{
    public static function url(string $email, int $websiteId): string
    {
        return Config::baseUrl($websiteId) . '/marketing/unsubscribe?t=' . self::token($email, $websiteId);
    }

    public static function token(string $email, int $websiteId): string
    {
        $payload = rtrim(strtr(base64_encode($websiteId . ':' . mb_strtolower(trim($email))), '+/', '-_'), '=');

        return $payload . '.' . substr(Crypt::mac('marketing_unsubscribe:' . $payload), 0, 32);
    }

    /** @return array{email: string, website_id: int}|null null when forged or malformed */
    public static function parse(string $token): ?array
    {
        [$payload, $mac] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || !hash_equals(substr(Crypt::mac('marketing_unsubscribe:' . $payload), 0, 32), $mac)) {
            return null;
        }
        [$websiteId, $email] = array_pad(explode(':', (string) base64_decode(strtr($payload, '-_', '+/'), true), 2), 2, '');

        return ctype_digit($websiteId) && $email !== '' ? ['email' => $email, 'website_id' => (int) $websiteId] : null;
    }

    public static function apply(string $email, int $websiteId): void
    {
        $db = Connection::get()->adapter();
        SubscriberService::unsubscribe($email, $websiteId); // the newsletter's subscriber of the e-mail, customer or not
        $customerId = (new Customer())->getResource()->findIdByEmail($email, $websiteId);
        if ($customerId !== null) {
            // unchecked = no value, as the EAV form saves an unchecked box
            $db->delete('eav_value_boolean', ['attribute_id = ?' => self::optinAttributeId(), 'entity_id = ?' => $customerId]);
            return;
        }
        if (!$db->fetchOne($db->select()->from('store_website', [new Expr('1')])->where('website_id = ?', $websiteId))) {
            return; // a link of a website removed since: nothing left to send from
        }
        $db->insertOnConflict('marketing_unsubscribe', ['website_id' => $websiteId, 'email' => mb_strtolower($email)], ['website_id', 'email']);
    }

    /**
     * The customer opted in. With $evenWithoutOptin (Configurações → Marketing → carrinho abandonado sem
     * consentimento), also the customer who never answered and the visitor; never who asked to leave
     * (unchecked the box, by the admin or the link) or a visitor who unsubscribed. A visitor who confirmed the
     * newsletter (SubscriberService) opted in.
     */
    public static function canReceive(?int $customerId, string $email, int $websiteId, bool $evenWithoutOptin = false): bool
    {
        $db = Connection::get()->adapter();
        if ($customerId !== null) {
            if ($db->fetchOne($db->select()->from('eav_value_boolean', ['value'])
                ->where('attribute_id = ?', self::optinAttributeId())->where('entity_id = ?', $customerId))) {
                return true;
            }
            $last = $db->fetchOne($db->select()->from('marketing_consent', ['optin'])
                ->where('customer_id = ?', $customerId)->order('consent_id DESC')->limit(1));

            return $evenWithoutOptin && $last === false;
        }

        if (SubscriberService::isConfirmed($email, $websiteId)) {
            return true;
        }

        return $evenWithoutOptin && !$db->fetchOne($db->select()->from('marketing_unsubscribe', [new Expr('1')])
            ->where('website_id = ?', $websiteId)->where('email = ?', mb_strtolower($email)));
    }

    /** the SQL id of the consent attribute, for the rules' queries */
    public static function optinAttributeId(): int
    {
        static $id = null;
        $db = Connection::get()->adapter();

        return $id ??= (int) $db->fetchOne($db->select()->from(['a' => 'eav_attribute'], ['attribute_id'])
            ->join(['t' => 'eav_entity_type'], 't.entity_type_id = a.entity_type_id', [])
            ->where('t.code = ?', Customer::ENTITY_TYPE)->where('a.code = ?', 'marketing_optin'));
    }
}
