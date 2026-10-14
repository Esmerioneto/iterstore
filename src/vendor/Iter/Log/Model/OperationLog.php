<?php

declare(strict_types=1);

namespace Iter\Log\Model;

use Iter\Admin\Auth;
use Iter\Admin\Model\AdminUser;
use Iter\Core\Config;
use Iter\Core\Db\Connection;
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
