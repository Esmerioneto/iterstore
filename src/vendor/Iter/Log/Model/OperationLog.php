<?php

declare(strict_types=1);

namespace Iter\Log\Model;

use Iter\Admin\Auth;
use Iter\Admin\Model\AdminUser;
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Select;
use Iter\Core\Log;

/**
 * The operation log (LOG-OPERATION-PLAN): who did what in the admin, screen or API,
 * with the fields that changed, before and after. Callers are the services, so both
 * the screen and the API land here. Never breaks the operation: a failure goes to
 * the file log. Off (system/operation_log/enabled) only log_off/log_on are written.
 */
class OperationLog
{
    public const ENABLED = 'system/operation_log/enabled';
    public const RETENTION = 'system/operation_log/retention_days';

    public const ACTIONS = [
        'create' => 'Criou', 'update' => 'Alterou', 'delete' => 'Excluiu', 'config' => 'Configurações',
        'login' => 'Entrou', 'logout' => 'Saiu', 'login_failed' => 'Login recusado', 'login_locked' => 'Bloqueado',
        'log_off' => 'Desligou o log', 'log_on' => 'Religou o log',
    ];
    public const AREAS = ['product' => 'Produto', 'customer' => 'Cliente', 'config' => 'Configurações'];

    /**
     * $before/$after: label => readable value ("Preço" => "49.9"). create = $before empty,
     * delete = $after empty. Only the changed labels are kept; an update without changes writes nothing.
     * Without a logged-in admin (customer, cron, webhook, CLI) nothing is written.
     */
    public static function record(string $action, ?string $entityType, ?int $entityId, ?string $label, array $before = [], array $after = []): void
    {
        $user = self::enabled($action) ? Auth::user() : null;
        if ($user === null) {
            return;
        }
        $changes = self::diff($before, $after);
        if (!$changes && in_array($action, ['update', 'config'], true)) {
            return;
        }
        self::insert($user, (string) self::name($user), $action, $entityType, $entityId, $label, $changes ?: null);
    }

    /** false = record() would write nothing: callers skip building the before/after */
    public static function active(): bool
    {
        return self::enabled('update') && Auth::user() !== null;
    }

    /** login, login_failed, login_locked, logout: $typed is the login typed, kept even when no user has it */
    public static function login(string $action, string $typed, ?AdminUser $user): void
    {
        if (self::enabled($action)) {
            self::insert($user?->getId() ? $user : null, $user?->getId() ? self::name($user) : mb_substr($typed, 0, 255), $action, null, null, null, null);
        }
    }

    /** @return array<string, array{0: ?string, 1: ?string}> label => [before, after], only what changed */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_keys($before + $after) as $key) {
            $old = self::text($before[$key] ?? null);
            $new = self::text($after[$key] ?? null);
            if ($old !== $new) {
                $changes[$key] = [$old, $new];
            }
        }

        return $changes;
    }

    /**
     * The list: filters from, to (AAAA-MM-DD), user (admin_name), action, area (entity_type),
     * q (in the record's label) and entity ("product:12": the product and its variants)
     *
     * @return array{items: array<int, array>, total: int}
     */
    public static function search(array $filters, int $page, int $perPage): array
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from('log_operation', ['COUNT(*)']);
        foreach (['from' => 'created_at >= CAST(? AS DATE)', 'to' => 'created_at < CAST(? AS DATE) + 1'] as $key => $condition) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key] ?? '')) {
                $select->where($condition, $filters[$key]);
            }
        }
        if (($filters['user'] ?? '') !== '') {
            $select->where('admin_name = ?', $filters['user']);
        }
        if (isset(self::ACTIONS[$filters['action'] ?? ''])) {
            $select->where('action = ?', $filters['action']);
        }
        if (isset(self::AREAS[$filters['area'] ?? ''])) {
            $select->where('entity_type = ?', $filters['area']);
        }
        if (($filters['q'] ?? '') !== '') {
            $select->where("entity_label ILIKE ? ESCAPE '\\'", '%' . addcslashes($filters['q'], '%_\\') . '%');
        }
        if (preg_match('/^(\w+):(\d+)$/', $filters['entity'] ?? '', $m)) {
            $select->where('entity_type = ?', $m[1])->where('entity_id IN (?)', self::entityIds($m[1], (int) $m[2]));
        }
        $total = (int) $db->fetchOne($select);
        $select->reset(Select::COLUMNS)->columns(['*'])->order('log_id DESC')->limitPage($page, $perPage);

        return ['items' => array_map(self::decode(...), $db->fetchAll($select)), 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow($db->select()->from('log_operation')->where('log_id = ?', $id));

        return $row ? self::decode($row) : null;
    }

    /** @return string[] who appears in the log, for the filter */
    public static function users(): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchCol($db->select()->from('log_operation', ['admin_name'])->distinct()->order('admin_name'));
    }

    /**
     * The record and what belongs to it: a product brings its current variants
     * (a removed variant's lines are found by the search, by its SKU)
     *
     * @return int[]
     */
    public static function entityIds(string $type, int $id): array
    {
        if ($type !== 'product' || !\isModuleEnabled('iter_catalog')) {
            return [$id];
        }
        $db = Connection::get()->adapter();

        return [$id, ...array_map('intval', $db->fetchCol($db->select()->from('catalog_product', ['product_id'])->where('parent_id = ?', $id)))];
    }

    /** "Preço, Nome, Estoque — CD Rio +2" */
    public static function summary(array $line): string
    {
        $keys = array_keys($line['changes']);

        return implode(', ', array_slice($keys, 0, 3)) . (count($keys) > 3 ? ' +' . (count($keys) - 3) : '');
    }

    /** Deletes the lines older than $days days; 0 keeps them all. @return int how many */
    public static function clean(int $days): int
    {
        return $days < 1 ? 0 : Connection::get()->adapter()->delete('log_operation', ['created_at < ?' => date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
    }

    private static function decode(array $row): array
    {
        return ['changes' => json_decode((string) $row['changes'], true) ?? []] + $row;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'Sim' : 'Não') : $value);

        return $value === null || (string) $value === '' ? null : (string) $value;
    }

    private static function enabled(string $action): bool
    {
        return \isModuleEnabled('iter_log') && (in_array($action, ['log_off', 'log_on'], true) || (string) Config::get(self::ENABLED) !== '0');
    }

    private static function name(AdminUser $user): string
    {
        return trim($user->getData('firstname') . ' ' . $user->getData('lastname')) ?: (string) $user->getData('username');
    }

    private static function insert(?AdminUser $user, string $name, string $action, ?string $entityType, ?int $entityId, ?string $label, ?array $changes): void
    {
        // inside the caller's transaction: a failure here undoes only this line, and a rollback there takes it too
        try {
            Connection::get()->adapter()->savepoint(static fn ($db) => $db->insert('log_operation', [
                'admin_user_id' => $user ? (int) $user->getId() : null,
                'admin_name' => mb_substr($name, 0, 255),
                'source' => str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/core/graphql') ? 'api' : 'admin',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_label' => $label === null ? null : mb_substr($label, 0, 255),
                'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            ]));
        } catch (\Throwable $e) {
            Log::error('Log de operações: não foi possível gravar.', ['action' => $action, 'entity' => $entityType, 'id' => $entityId, 'error' => $e->getMessage()]);
        }
    }
}
