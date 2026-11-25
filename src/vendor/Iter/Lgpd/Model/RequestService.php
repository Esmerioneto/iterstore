<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Customer\Model\Customer;

/** LGPD → Solicitações (LGPD-PLAN §6): the data subjects' requests and what became of each */
final class RequestService
{
    public const TYPES = ['access' => 'Saber o que a loja tem', 'export' => 'Cópia dos dados', 'correction' => 'Correção', 'erase' => 'Exclusão'];
    public const ORIGINS = ['app' => 'App', 'web' => 'Loja web', 'admin' => 'Admin'];
    public const STATUS_LABELS = ['open' => 'Aberta', 'done' => 'Atendida', 'refused' => 'Recusada'];
    /** the answer the law asks for a full copy (art. 19, II) */
    public const DUE_DAYS = 15;
    /** the list's sortable columns: key => SQL (whitelist; the request only picks a key) */
    public const SORTS = ['id' => 'r.request_id', 'email' => 'r.email', 'type' => 'r.type', 'origin' => 'r.origin', 'date' => 'r.request_id', 'due' => 'r.due_at', 'status' => 'r.status'];

    /**
     * A new request. The customer is the one with the e-mail in the website (a visitor when none); $data may bring
     * customer_id to skip the search. status "done" records a request answered on the spot (the app's copy, an erase).
     *
     * @param array{website_id: int, email: string, type: string, origin: string, note?: string, customer_id?: int, status?: string, admin_user_id?: int} $data
     */
    public function create(array $data): int
    {
        $email = mb_strtolower(trim((string) $data['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LgpdException('EMAIL_INVALID', 'Informe o e-mail do titular.');
        }
        if (!isset(self::TYPES[$data['type']], self::ORIGINS[$data['origin']])) {
            throw new LgpdException('INVALID_ARGUMENT', 'Tipo ou origem da solicitação inválidos.');
        }
        $websiteId = (int) $data['website_id'];
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : ((int) (new Customer())->getResource()->findIdByEmail($email, $websiteId) ?: null);
        $done = ($data['status'] ?? 'open') === 'done';

        return (int) Connection::get()->adapter()->insert('lgpd_request', [
            'website_id' => $websiteId,
            'customer_id' => $customerId,
            'email' => $email,
            'type' => $data['type'],
            'origin' => $data['origin'],
            'status' => $done ? 'done' : 'open',
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'admin_user_id' => $data['admin_user_id'] ?? null,
            'done_at' => $done ? date('Y-m-d H:i:s') : null,
        ])['request_id'];
    }

    /** answers ("done") or refuses an open request; the note says what was done or why not */
    public function close(int $id, string $status, string $note, ?int $adminUserId): void
    {
        $request = $this->get($id);
        if ($request['status'] !== 'open') {
            throw new LgpdException('LGPD_REQUEST_CLOSED', 'Esta solicitação já foi encerrada.');
        }
        if (!in_array($status, ['done', 'refused'], true)) {
            throw new LgpdException('INVALID_ARGUMENT', 'Situação inválida.');
        }
        if ($status === 'refused' && trim($note) === '') {
            throw new LgpdException('LGPD_NOTE_REQUIRED', 'Diga por que a solicitação foi recusada: o titular tem direito ao motivo (art. 18, § 4º).');
        }
        $old = trim((string) $request['note']);
        Connection::get()->adapter()->update('lgpd_request', [
            'status' => $status,
            'note' => trim($old . ($old !== '' && trim($note) !== '' ? "\n" : '') . trim($note)) ?: null,
            'admin_user_id' => $adminUserId,
            'done_at' => date('Y-m-d H:i:s'),
        ], ['request_id = ?' => $id]);
    }

    /** @return array<string, mixed> with website and customer names */
    public function get(int $id): array
    {
        $row = Connection::get()->adapter()->fetchRow($this->select()->where('r.request_id = ?', $id));

        return $row ?: throw new LgpdException('LGPD_REQUEST_NOT_FOUND', 'Solicitação não encontrada.');
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(array $filters, int $page, int $limit, string $sort = 'id', string $dir = 'desc'): array
    {
        $db = Connection::get()->adapter();
        $select = $this->select();
        foreach (['status' => 'r.status = ?', 'type' => 'r.type = ?', 'website_id' => 'r.website_id = ?'] as $key => $condition) {
            if (($filters[$key] ?? '') !== '') {
                $select->where($condition, $filters[$key]);
            }
        }
        if (!empty($filters['late'])) {
            $select->where("r.status = 'open'")->where('r.due_at < NOW()');
        }
        $q = (string) preg_replace('/^lgpd-0*(?=\d)/i', '', trim((string) ($filters['q'] ?? ''))); // "LGPD-000042" finds 42
        if ($q !== '') {
            ctype_digit($q) ? $select->where('r.request_id = ?', (int) $q) : $select->where('r.email ILIKE ?', '%' . addcslashes(mb_strtolower($q), '%_\\') . '%');
        }
        $total = (int) $db->fetchOne((clone $select)->reset('columns')->reset('order')->columns([new Expr('COUNT(*)')]));
        $select->order((self::SORTS[$sort] ?? self::SORTS['id']) . ($dir === 'asc' ? ' ASC' : ' DESC'))->limit($limit, ($page - 1) * $limit);

        return ['items' => $db->fetchAll($select), 'total' => $total];
    }

    /** open requests, for the menu badge */
    public static function openCount(): int
    {
        return (int) Connection::get()->adapter()->fetchOne("SELECT COUNT(*) FROM lgpd_request WHERE status = 'open'");
    }

    /** "LGPD-000042" */
    public static function protocol(int $id): string
    {
        return sprintf('LGPD-%06d', $id);
    }

    private function select(): \Iter\Core\Db\Select
    {
        return Connection::get()->adapter()->select()
            ->from(['r' => 'lgpd_request'])
            ->joinLeft(['w' => 'store_website'], 'w.website_id = r.website_id', ['website' => 'name'])
            ->joinLeft(['c' => 'customer_entity'], 'c.customer_id = r.customer_id', ['anonymized_at'])
            ->joinLeft(['u' => 'admin_user'], 'u.user_id = r.admin_user_id', ['admin_name' => new Expr("NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), '')")]);
    }
}
