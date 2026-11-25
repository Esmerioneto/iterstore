<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Admin\Auth;
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Log;
use Iter\Customer\Model\CustomerAuth;
use Iter\Log\Model\OperationLog;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

/**
 * The data subject's rights over what the enabled modules keep (LGPD-PLAN §4 and §5): the copy (art. 18, II) and
 * the erase (art. 18, VI). Each module answers for its own tables through its handler (Inventory::handlers()).
 */
final class PersonalDataService
{
    public const MAIL_ERASED = 'lgpd_erase_done';

    /**
     * Everything the store keeps about the subject, one key per module that has something. $customerId null:
     * a visitor, by the e-mail of their orders.
     */
    public function export(?int $customerId, string $email): array
    {
        $data = [];
        foreach (Inventory::handlers() as $label => $handler) {
            $part = $handler->export($customerId, $email);
            if ($part) {
                $data[$label] = $part;
            }
        }

        return [
            'Titular' => $email,
            'Gerado em' => date('d/m/Y H:i'),
            'Sobre esta cópia' => 'Dados pessoais que a loja guarda sobre você, por assunto (LGPD, art. 18, II). Quem recebe esses dados e por quanto tempo estão na política de privacidade.',
        ] + $data;
    }

    /** why the customer can't be erased now; empty when nothing stops it @return list<string> */
    public function blockers(int $customerId): array
    {
        $out = [];
        foreach (Inventory::handlers() as $handler) {
            array_push($out, ...$handler->blockers($customerId));
        }

        return $out;
    }

    /**
     * Erases the customer in one transaction: the confirmation e-mail is queued to the address as it was, every
     * module erases its part, and the request is recorded as answered ($requestId: the open one the admin is
     * executing; null: a new one, from $origin). Returns the request id.
     *
     * @throws LgpdException LGPD_ALREADY_ERASED, LGPD_ERASE_BLOCKED (an order still running…)
     */
    public function erase(int $customerId, string $origin, ?int $requestId = null, string $note = ''): int
    {
        $db = Connection::get()->adapter();
        $subject = $db->fetchRow('SELECT c.customer_id, c.website_id, c.anonymized_at, f.email, f.firstname FROM customer_entity c
            JOIN customer_flat f ON f.customer_id = c.customer_id WHERE c.customer_id = :id', ['id' => $customerId])
            ?: throw new LgpdException('CUSTOMER_NOT_FOUND', 'Cliente não encontrado.');
        if ($subject['anonymized_at']) {
            throw new LgpdException('LGPD_ALREADY_ERASED', 'Os dados deste cliente já foram excluídos.');
        }
        if ($blockers = $this->blockers($customerId)) {
            throw new LgpdException('LGPD_ERASE_BLOCKED', 'Ainda não dá para excluir a conta. ' . implode(' ', $blockers));
        }
        $email = (string) $subject['email'];
        $adminId = Auth::user()?->getId();
        $requests = new RequestService();

        $id = $db->transaction(function () use ($customerId, $origin, $requestId, $note, $subject, $email, $adminId, $requests): int {
            $this->mail($subject);
            foreach (Inventory::handlers() as $handler) {
                $handler->erase($customerId, $email);
            }
            if ($requestId !== null) {
                $requests->close($requestId, 'done', $note, $adminId !== null ? (int) $adminId : null);

                return $requestId;
            }

            return $requests->create(['website_id' => (int) $subject['website_id'], 'customer_id' => $customerId, 'email' => $email, 'type' => 'erase',
                'origin' => $origin, 'status' => 'done', 'note' => $note, 'admin_user_id' => $adminId !== null ? (int) $adminId : null]);
        });
        // the log keeps only the id: the data it would show is what was just erased
        OperationLog::record('lgpd_erase', 'customer', $customerId, 'Cliente #' . $customerId, [], ['Solicitação' => RequestService::protocol($id)]);

        return $id;
    }

    /** Configurações → LGPD → Prazos de guarda, as purge() receives them (0 = never) @return array<string, int> */
    public static function retention(): array
    {
        $out = [];
        foreach (['order_years', 'newsletter_pending_days', 'consent_years', 'request_years'] as $key) {
            $out[$key] = max(0, (int) Config::get('lgpd/retention/' . $key));
        }

        return $out;
    }

    /**
     * The retention routine (bin/lgpd-clean.php): each enabled module's purge(), each in its own transaction (one
     * failing doesn't undo the others). Returns activity => records anonymized or deleted.
     *
     * @return array<string, int>
     */
    public function purge(): array
    {
        $retention = self::retention();
        $out = [];
        foreach (Inventory::handlers() as $label => $handler) {
            if (method_exists($handler, 'purge')) {
                $out[$label] = (int) Connection::get()->adapter()->transaction(static fn () => $handler->purge($retention));
            }
        }

        return $out;
    }

    /** the customer's own erase (deleteMe): the password again, then erase(); the token stops working */
    public function eraseMe(string $currentPassword): int
    {
        $customer = CustomerAuth::customer() ?? throw new LgpdException('UNAUTHENTICATED', 'Faça login como cliente.');
        CustomerAuth::verifyPassword($customer, $currentPassword);

        return $this->erase((int) $customer->getId(), self::origin());
    }

    /** "web" when the call names the store web (X-Store-Url), "app" otherwise */
    public static function origin(): string
    {
        return trim((string) ($_SERVER['HTTP_X_STORE_URL'] ?? '')) !== '' ? 'web' : 'app';
    }

    /** queued inside the erase's transaction: it leaves only if the erase does */
    private function mail(array $subject): void
    {
        try {
            (new MailService())->send(self::MAIL_ERASED, (string) $subject['email'], ['name' => (string) $subject['firstname']], ['website_id' => (int) $subject['website_id']]);
        } catch (MailException $e) {
            Log::error('LGPD: e-mail de confirmação da exclusão não enfileirado: ' . $e->getMessage());
        }
    }
}
