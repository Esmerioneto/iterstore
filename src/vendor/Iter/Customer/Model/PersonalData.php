<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Model\ValueService;
use Iter\Lgpd\Model\PersonalDataInterface;

/**
 * LGPD for the registration (Iter_Lgpd calls it, see etc/lgpd.json). Erasing keeps the row, so the orders still
 * point to a customer: every field and address goes, the name becomes "Cliente excluído", the e-mail one that
 * can't receive mail, the password unusable, and the account inactive with anonymized_at set.
 */
final class PersonalData implements PersonalDataInterface
{
    public const FIRSTNAME = 'Cliente';
    public const LASTNAME = 'excluído';

    /** unique per customer (the e-mail is unique per website) and in a domain that never receives mail (RFC 2606) */
    public static function anonymousEmail(int $customerId): string
    {
        return "excluido-{$customerId}@anonimo.invalid";
    }

    public function export(?int $customerId, string $email): array
    {
        if ($customerId === null) {
            return [];
        }
        $created = (string) Connection::get()->adapter()->fetchOne('SELECT created_at FROM customer_entity WHERE customer_id = :id', ['id' => $customerId]);

        return ['Cadastrado em' => substr($created, 0, 16)] + (new CustomerService())->snapshot($customerId);
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
        $db = Connection::get()->adapter();
        $customer = (new CustomerService())->get($customerId);
        $values = new ValueService();
        $type = (string) ($values->load(Customer::ENTITY_TYPE, $customerId)['customer_type'] ?? '') ?: 'pf';

        (new AddressService())->save($customerId, []);
        // every value goes (the consent trigger records the opt-in leaving), then only what the account needs
        $values->deleteEntity(Customer::ENTITY_TYPE, $customerId);
        $values->write($customerId, $values->validate(Customer::ENTITY_TYPE, [
            'email' => self::anonymousEmail($customerId),
            'firstname' => self::FIRSTNAME,
            'lastname' => self::LASTNAME,
            'customer_type' => $type,
        ], $customerId), (int) $customer->getData('website_id'));
        $db->update('customer_entity', [
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'is_active' => 0,
            'confirmation_hash' => null,
            'anonymized_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['customer_id = ?' => $customerId]);
        $db->delete('customer_api_token', ['customer_id = ?' => $customerId]);
        $db->delete('customer_password_reset', ['customer_id = ?' => $customerId]);
    }
}
