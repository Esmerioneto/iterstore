<?php

declare(strict_types=1);

namespace Iter\Marketing\Model;

use Iter\Core\Config;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Log;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;
use Iter\Marketing\Model\Rule\AbandonedCart;
use Iter\Marketing\Model\Rule\Lapsed;
use Iter\Marketing\Model\Rule\NeverBought;
use Iter\Marketing\Model\Rule\Overdue;
use Iter\Marketing\Model\Rule\RecurringNews;
use Iter\Marketing\Model\Rule\Rule;
use Iter\Store\Model\Website;

/**
 * MARKETING-PLAN §2: for each website, the cycle (bin/marketing.php)
 *  1. expires the suggestions no one decided in time;
 *  2. asks each rule that is on who fits now, keeps who may receive (consent) and wasn't mailed in the last
 *     N days, and creates the suggestion: "pending" (aguardar aprovação) or "approved" (automático);
 *  3. inside the sending hours, queues the approved ones, checking consent, limit and the reason again.
 * The unique (reason_key, e-mail) makes it one e-mail per reason, even if two cycles run.
 */
final class MarketingService
{
    public const STATUS_LABELS = [
        'pending' => 'Aguardando aprovação', 'approved' => 'Aprovada, aguardando o horário', 'sent' => 'Enviada',
        'discarded' => 'Descartada', 'expired' => 'Expirada', 'skipped' => 'Não enviada',
    ];

    /** the list's sortable columns: key => SQL (whitelist; the request only picks a key) */
    public const SORTS = ['date' => 's.suggestion_id', 'email' => 's.email', 'rule' => 's.rule', 'status' => 's.status', 'sent' => 's.sent_at'];

    /** "Mensagens"-style counter of the menu: suggestions waiting for someone */
    public static function pendingCount(): int
    {
        return (int) Connection::get()->adapter()->fetchOne("SELECT COUNT(*) FROM marketing_suggestion WHERE status = 'pending'");
    }

    /**
     * Filters: status, rule, website_id, q (e-mail or name, part of it), from/to (Y-m-d, creation)
     *
     * @return array{items: array, total: int}
     */
    public function search(array $filters, int $page, int $perPage, string $sort = 'date', string $dir = 'desc'): array
    {
        $db = $this->db();
        $select = $db->select()->from(['s' => 'marketing_suggestion'], [new Expr('COUNT(*)')])
            ->joinLeft(['c' => 'customer_flat'], 'c.customer_id = s.customer_id', [])
            ->joinLeft(['w' => 'store_website'], 'w.website_id = s.website_id', []);
        if (isset(self::STATUS_LABELS[$filters['status'] ?? ''])) {
            $select->where('s.status = ?', $filters['status']);
        }
        if (isset(self::rules()[$filters['rule'] ?? ''])) {
            $select->where('s.rule = ?', $filters['rule']);
        }
        if ((int) ($filters['website_id'] ?? 0) > 0) {
            $select->where('s.website_id = ?', (int) $filters['website_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%';
            $select->where("(lower(s.email) LIKE ? OR lower(CONCAT_WS(' ', c.firstname, c.lastname)) LIKE ?)", $like); // each ? gets $like; backslash is LIKE's default escape (an ESCAPE '\\' would confuse PDO's placeholder parser)
        }
        foreach (['from' => 's.created_at >= CAST(? AS DATE)', 'to' => 's.created_at < CAST(? AS DATE) + 1'] as $key => $condition) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key] ?? '')) {
                $select->where($condition, $filters[$key]);
            }
        }
        $total = (int) $db->fetchOne($select);
        $select->reset(\Iter\Core\Db\Select::COLUMNS)
            ->columns(['s.*', new Expr("NULLIF(TRIM(CONCAT_WS(' ', c.firstname, c.lastname)), '') AS customer_name"), new Expr('w.name AS website')])
            ->order((self::SORTS[$sort] ?? 's.suggestion_id') . ($dir === 'asc' ? ' ASC' : ' DESC'))->order('s.suggestion_id DESC')->limitPage($page, $perPage);

        return ['items' => $db->fetchAll($select), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->fetchRow('SELECT s.*, NULLIF(TRIM(CONCAT_WS(\' \', c.firstname, c.lastname)), \'\') AS customer_name, w.name AS website
            FROM marketing_suggestion s LEFT JOIN customer_flat c ON c.customer_id = s.customer_id LEFT JOIN store_website w ON w.website_id = s.website_id
            WHERE s.suggestion_id = ?', [$id]);

        return $row ?: null;
    }

    /** "Carrinho #12, 2º lembrete", "Última compra: pedido #000123"… */
    public static function reasonLabel(array $row): string
    {
        [$kind, $id, $step] = array_pad(explode(':', (string) $row['reason_key']), 3, '');

        return match ($kind) {
            'quote' => "Carrinho #{$id}, {$step}º lembrete",
            'last_order' => 'Última compra: pedido ' . (Connection::get()->adapter()->fetchOne('SELECT increment_id FROM sales_order WHERE order_id = ?', [(int) $id]) ?: "#{$id}"),
            'customer' => 'Cadastro sem compra',
            'news' => 'Novidades de ' . date('d/m/Y', strtotime($id)),
            default => (string) $row['reason_key'],
        };
    }

    /** the e-mail as it would go out now: subject and HTML (the admin's preview) */
    public function preview(array $row): array
    {
        $rule = self::rules()[$row['rule']] ?? null;
        if ($rule === null) {
            return ['subject' => '', 'html' => ''];
        }
        $websiteId = (int) $row['website_id'];
        $mail = \Iter\Mail\Model\Template::render(\Iter\Mail\Model\Template::get($rule->template($row)), Rule::vars($row['vars'], $websiteId) + [
            'unsubscribe_url' => Unsubscribe::url($row['email'], $websiteId),
            'store_name' => (string) Config::get('mail/from_name', $websiteId),
            'logo' => '',
        ]);

        return ['subject' => $mail['subject'], 'html' => $mail['html']];
    }

    /**
     * Marketing → Sugestões: "send" approves the pending ones and, inside the sending hours, queues them now
     * (the cycle sends the rest when the hours open); "discard" drops them. Only pending ones change.
     *
     * @param int[] $ids
     * @return array{changed: int, sent: int, skipped: int, waiting: int}
     */
    public function decide(array $ids, string $action, int $adminUserId, ?int $hour = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $result = ['changed' => 0, 'sent' => 0, 'skipped' => 0, 'waiting' => 0];
        if (!$ids || !in_array($action, ['send', 'discard'], true)) {
            return $result;
        }
        $rows = $this->db()->fetchAll($this->db()->select()->from('marketing_suggestion', ['suggestion_id', 'website_id'])
            ->where('suggestion_id IN (?)', $ids)->where("status = 'pending'"));
        if (!$rows) {
            return $result;
        }
        $result['changed'] = $this->db()->update('marketing_suggestion', [
            'status' => $action === 'send' ? 'approved' : 'discarded',
            'admin_user_id' => $adminUserId,
            'decided_at' => new Expr('NOW()'),
        ], ['suggestion_id IN (?)' => array_column($rows, 'suggestion_id'), "status = 'pending'"]);
        if ($action === 'send') {
            $hour ??= (int) date('G');
            $byWebsite = [];
            foreach ($rows as $row) {
                $byWebsite[(int) $row['website_id']][] = (int) $row['suggestion_id'];
            }
            foreach ($byWebsite as $websiteId => $websiteIds) {
                if (!self::inWindow($websiteId, $hour)) {
                    $result['waiting'] += count($websiteIds);
                    continue;
                }
                foreach ($this->send($websiteId, $websiteIds) as $key => $count) {
                    $result[$key] += $count;
                }
            }
        }

        return $result;
    }

    /**
     * Marketing → Histórico's tiles, per rule: e-mails sent in the period and how many of those people paid an
     * order within 7 days after (the return without a coupon; registered customers only: the order is by customer_id)
     *
     * @return array<string, array{label: string, sent: int, customers: int, returned: int}>
     */
    public function stats(array $filters): array
    {
        $bind = [];
        $where = ["s.status = 'sent'"];
        if ((int) ($filters['website_id'] ?? 0) > 0) {
            $where[] = 's.website_id = :website';
            $bind['website'] = (int) $filters['website_id'];
        }
        foreach (['from' => 's.sent_at >= CAST(:from AS DATE)', 'to' => 's.sent_at < CAST(:to AS DATE) + 1'] as $key => $condition) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key] ?? '')) {
                $where[] = $condition;
                $bind[$key] = $filters[$key];
            }
        }
        $rows = $this->db()->fetchAll('SELECT s.rule, COUNT(*) AS sent, COUNT(s.customer_id) AS customers,
                COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM sales_order o WHERE o.customer_id = s.customer_id
                    AND o.status IN (\'processing\', \'shipped\', \'complete\') AND o.created_at >= s.sent_at AND o.created_at < s.sent_at + INTERVAL \'7 days\')) AS returned
            FROM marketing_suggestion s WHERE ' . implode(' AND ', $where) . ' GROUP BY s.rule', $bind);
        $byRule = array_column($rows, null, 'rule');
        $stats = [];
        foreach (self::rules() as $code => $rule) {
            $stats[$code] = ['label' => $rule->label(), 'sent' => (int) ($byRule[$code]['sent'] ?? 0),
                'customers' => (int) ($byRule[$code]['customers'] ?? 0), 'returned' => (int) ($byRule[$code]['returned'] ?? 0)];
        }

        return $stats;
    }

    /** @return array<string, Rule> code => rule */
    public static function rules(): array
    {
        static $rules = null;
        if ($rules === null) {
            foreach ([new AbandonedCart(), new Lapsed(), new Overdue(), new NeverBought(), new RecurringNews()] as $rule) {
                $rules[$rule->code()] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param int|null $hour the local hour (tests); null = now
     * @return array{created: int, sent: int, skipped: int, expired: int}
     */
    public function run(?int $hour = null): array
    {
        $hour ??= (int) date('G');
        $totals = ['created' => 0, 'sent' => 0, 'skipped' => 0, 'expired' => 0];
        foreach (array_column((new Website())->getList(), 'website_id') as $websiteId) {
            $websiteId = (int) $websiteId;
            $totals['expired'] += $this->expire($websiteId);
            $totals['created'] += $this->collect($websiteId);
            if (self::inWindow($websiteId, $hour)) {
                foreach ($this->send($websiteId) as $key => $count) {
                    $totals[$key] += $count;
                }
            }
        }

        return $totals;
    }

    public static function inWindow(int $websiteId, int $hour): bool
    {
        return $hour >= (int) Config::get('marketing/general/hour_from', $websiteId) && $hour < (int) Config::get('marketing/general/hour_to', $websiteId);
    }

    /** suggestions not decided (pending) or not sent (approved) within the configured days */
    private function expire(int $websiteId): int
    {
        return $this->db()->update('marketing_suggestion', ['status' => 'expired', 'decided_at' => new Expr('NOW()')], [
            'website_id = ?' => $websiteId,
            "status IN ('pending', 'approved')",
            'created_at < NOW() - make_interval(days => ?)' => (int) Config::get('marketing/general/expire_days', $websiteId),
        ]);
    }

    private function collect(int $websiteId): int
    {
        $created = 0;
        foreach (self::rules() as $rule) {
            if (!$rule->setting('active', $websiteId)) {
                continue;
            }
            $status = $rule->setting('mode', $websiteId) === 'auto' ? 'approved' : 'pending';
            foreach ($rule->candidates($websiteId) as $candidate) {
                if (!Unsubscribe::canReceive($candidate['customer_id'], $candidate['email'], $websiteId, $rule->evenWithoutOptin($websiteId))
                    || $this->limited($candidate['email'], $websiteId, true)) {
                    continue; // not consumed: the reason may still go out later
                }
                $created += $this->db()->insertOnConflict('marketing_suggestion', [
                    'website_id' => $websiteId,
                    'customer_id' => $candidate['customer_id'],
                    'email' => mb_strtolower($candidate['email']),
                    'rule' => $rule->code(),
                    'reason_key' => $candidate['reason_key'],
                    'vars' => json_encode($candidate['vars'], JSON_UNESCAPED_UNICODE),
                    'status' => $status,
                ], []);
            }
        }

        return $created;
    }

    /**
     * @param int[]|null $ids only these (just approved in the admin); null = all of the website
     * @return array{sent: int, skipped: int}
     */
    private function send(int $websiteId, ?array $ids = null): array
    {
        $counts = ['sent' => 0, 'skipped' => 0];
        $select = $this->db()->select()->from('marketing_suggestion')->where('website_id = ?', $websiteId)->where("status = 'approved'")->order('suggestion_id');
        $ids !== null && $select->where('suggestion_id IN (?)', $ids ?: [0]);
        $rows = $this->db()->fetchAll($select);
        foreach ($rows as $row) {
            $note = $this->sendOne($row, $websiteId);
            $counts[$note === null ? 'sent' : 'skipped']++;
        }

        return $counts;
    }

    /** @return string|null why it was skipped; null = queued */
    private function sendOne(array $row, int $websiteId): ?string
    {
        $rule = self::rules()[$row['rule']] ?? null;
        $customerId = $row['customer_id'] === null ? null : (int) $row['customer_id'];
        $note = match (true) {
            $rule === null => 'Regra desconhecida.',
            !Unsubscribe::canReceive($customerId, $row['email'], $websiteId, $rule->evenWithoutOptin($websiteId)) => 'Sem consentimento na hora do envio.',
            $this->limited($row['email'], $websiteId) => 'Recebeu outro e-mail de marketing há menos de ' . (int) Config::get('marketing/general/days_between', $websiteId) . ' dias.',
            !$rule->stillValid($row) => 'O motivo deixou de valer (ex.: o carrinho virou pedido, comprou de novo).',
            default => null,
        };
        $mailId = 0;
        if ($note === null) {
            $url = Unsubscribe::url($row['email'], $websiteId);
            try {
                $mailId = (new MailService())->send($rule->template($row), $row['email'], Rule::vars($row['vars'], $websiteId) + ['unsubscribe_url' => $url], [
                    'website_id' => $websiteId,
                    // Gmail/Yahoo: one-click unsubscribe (RFC 8058) posts "List-Unsubscribe=One-Click" to this URL
                    'headers' => ['List-Unsubscribe' => '<' . str_replace('/unsubscribe?', '/unsubscribepost?', $url) . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
                ]);
                $note = $mailId ? null : 'Limite de e-mails por hora do destinatário (Configurações → E-mail).';
            } catch (MailException $e) {
                Log::error("Marketing: sugestão {$row['suggestion_id']} não enfileirada: " . $e->getMessage());
                $note = 'E-mail não enfileirado: ' . $e->getMessage();
            }
        }
        $this->db()->update('marketing_suggestion', $note === null
            ? ['status' => 'sent', 'mail_id' => $mailId, 'sent_at' => new Expr('NOW()'), 'note' => null]
            : ['status' => 'skipped', 'note' => mb_substr($note, 0, 255), 'decided_at' => new Expr('COALESCE(decided_at, NOW())')], ['suggestion_id = ?' => $row['suggestion_id']]);

        return $note;
    }

    /**
     * A marketing e-mail went to this address in the last N days (0 = no limit). With $inFlight, also one
     * waiting (pending or approved): one at a time per person, so a second rule waits instead of being skipped.
     */
    private function limited(string $email, int $websiteId, bool $inFlight = false): bool
    {
        $days = (int) Config::get('marketing/general/days_between', $websiteId);
        $select = $this->db()->select()->from('marketing_suggestion', [new Expr('1')])->where('lower(email) = ?', mb_strtolower($email));
        $conditions = array_filter([
            $days > 0 ? 'sent_at > NOW() - make_interval(days => ' . $days . ')' : null,
            $inFlight ? "status IN ('pending', 'approved')" : null,
        ]);

        return $conditions && (bool) $this->db()->fetchOne($select->where(implode(' OR ', $conditions)));
    }

    /**
     * Configurações → Marketing checked as a whole (config_groups.json "validator")
     *
     * @param callable(string): mixed $get the value each path will have after saving
     * @return list<string>
     */
    public static function validateConfig(callable $get): array
    {
        $errors = [];
        if ((int) $get('marketing/general/hour_from') >= (int) $get('marketing/general/hour_to')) {
            $errors[] = 'Marketing: o horário de envio precisa começar antes de terminar (ex.: das 9 às 20 h).';
        }
        $second = (int) $get('marketing/abandoned_cart/hours_second');
        if ($second > 0 && $second <= (int) $get('marketing/abandoned_cart/hours_first')) {
            $errors[] = 'Marketing: o 2º lembrete do carrinho precisa vir depois do 1º (ou 0, sem 2º lembrete).';
        }

        return $errors;
    }

    private function db(): Adapter
    {
        return Connection::get()->adapter();
    }
}
