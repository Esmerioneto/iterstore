<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Eav\Model\AttributeException;
use Iter\Mail\Model\MailService;

/**
 * Storefront login through the API (the storefront is an external app): a Bearer
 * token like the admin's, in customer_api_token (sha256 only). A customer token
 * is never an admin one, so it doesn't pass Schema::guard.
 */
class CustomerAuth
{
    private static ?Customer $customer = null;
    private static ?string $token = null;

    /**
     * Sign-up from the storefront: general group, default attribute set. With
     * customer/account/confirm_email on, the account waits for confirm() and the
     * e-mail with the link is queued.
     *
     * @param array $data as CustomerService::create() (EAV values in 'attributes'), without the group
     * @return array{customer: Customer, confirmation_required: bool}
     */
    public static function register(array $data, string $password): array
    {
        self::checkPassword($password);
        $db = Connection::get()->adapter();
        $websiteId = (int) ($data['website_id'] ?? 0);
        $confirm = (bool) Config::get('customer/account/confirm_email', $websiteId);
        // ponytail: sign-ups go to the "general" group; a config field when a store wants another
        $groupId = (int) $db->fetchOne($db->select()->from('customer_group', ['customer_group_id'])->order([new Expr("code = 'general' DESC"), 'customer_group_id'])->limit(1));
        $token = $confirm ? bin2hex(random_bytes(32)) : null;

        $customer = $db->transaction(static function () use ($data, $groupId, $password, $token): Customer {
            $customer = (new CustomerService())->create(['group_id' => $groupId, 'is_active' => 1] + $data, $data['attributes'] ?? []);
            $customer->addData(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'confirmation_hash' => $token === null ? null : hash('sha256', $token)])->save();

            return $customer;
        });
        if ($token !== null) {
            $link = (string) Config::get('customer/account/confirm_url', $websiteId);
            (new MailService())->send('customer_confirm_email', (string) $customer->getData('email'), [
                'name' => $customer->getData('firstname'),
                'link' => str_contains($link, '{token}') ? str_replace('{token}', $token, $link) : $token,
            ], ['website_id' => $websiteId]);
        }

        return ['customer' => $customer, 'confirmation_required' => $token !== null];
    }

    /** the confirmed customer, null for an unknown or used token */
    public static function confirm(string $token): ?Customer
    {
        $customer = new Customer();
        $customer->getResource()->load($customer, hash('sha256', $token), 'confirmation_hash');
        if (!$customer->getId()) {
            return null;
        }

        return $customer->addData(['confirmation_hash' => null])->save();
    }

    /**
     * @throws AttributeException INVALID_CREDENTIALS (unknown, wrong password or inactive, one message),
     *                            EMAIL_NOT_CONFIRMED (right password, confirmation pending)
     */
    public static function checkCredentials(string $email, string $password, int $websiteId): Customer
    {
        $id = (new Customer())->getResource()->findIdByEmail($email, $websiteId);
        $customer = (new Customer())->load((int) $id);
        $hash = (string) $customer->getData('password_hash');
        if (!$customer->getId() || $hash === '' || !password_verify($password, $hash) || !$customer->getData('is_active')) {
            throw new AttributeException('INVALID_CREDENTIALS', 'E-mail ou senha inválidos.');
        }
        if ($customer->getData('confirmation_hash') !== null) {
            throw new AttributeException('EMAIL_NOT_CONFIRMED', 'Confirme o seu e-mail pelo link que enviamos antes de entrar.');
        }

        return $customer;
    }

    /** @return array{token: string, expires_at: string} */
    public static function issueToken(Customer $customer): array
    {
        $db = Connection::get()->adapter();
        $db->delete('customer_api_token', ['expires_at < ?' => date('Y-m-d H:i:s')]);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (int) Config::get('customer/security/api_token_hours') * 3600);
        $db->insert('customer_api_token', ['customer_id' => (int) $customer->getId(), 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => $expiresAt]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** false when the token is unknown, expired, revoked, or the customer inactive */
    public static function useToken(string $token): bool
    {
        $db = Connection::get()->adapter();
        $id = $db->fetchOne($db->select()->from('customer_api_token', ['customer_id'])
            ->where('token_hash = ?', hash('sha256', $token))
            ->where('expires_at > ?', date('Y-m-d H:i:s')));
        $customer = (new Customer())->load((int) $id);
        if (!$customer->getId() || !$customer->getData('is_active')) {
            return false;
        }
        self::$customer = $customer;
        self::$token = $token;

        return true;
    }

    /** logout: the token in use stops working */
    public static function revokeToken(): bool
    {
        if (self::$token === null) {
            return false;
        }
        Connection::get()->adapter()->delete('customer_api_token', ['token_hash = ?' => hash('sha256', self::$token)]);
        self::$token = null;
        self::$customer = null;

        return true;
    }

    /** the token's customer; null without a customer token */
    public static function customer(): ?Customer
    {
        return self::$customer;
    }

    public static function checkPassword(string $password): void
    {
        $min = (int) Config::get('customer/security/password_min_length');
        if (mb_strlen($password) < $min || strlen($password) > 72) { // bcrypt reads 72 bytes
            throw new AttributeException('PASSWORD_INVALID', "A senha deve ter de {$min} a 72 caracteres.");
        }
    }
}
