<?php

declare(strict_types=1);

namespace Iter\Tryon\Model;

use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\PersonalDataInterface;

/**
 * LGPD for the try-on (Iter_Lgpd calls it, see etc/lgpd.json). The customer's photo is never kept; the copy lists
 * the generations, and erase deletes them with the generated images still on disk.
 */
final class PersonalData implements PersonalDataInterface
{
    public function export(?int $customerId, string $email): array
    {
        if ($customerId === null) {
            return [];
        }

        return array_map(static fn ($t) => substr((string) $t['created_at'], 0, 16) . ' — ' . ($t['product'] ?? 'produto removido') . ' — ' . $t['status'],
            Connection::get()->adapter()->fetchAll('SELECT t.created_at, t.status, p.name AS product FROM tryon_request t LEFT JOIN catalog_product p ON p.product_id = t.product_id
                WHERE t.customer_id = :c ORDER BY t.tryon_id', ['c' => $customerId]));
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
        $db = Connection::get()->adapter();
        foreach ($db->fetchCol('SELECT image_url FROM tryon_request WHERE customer_id = :c AND image_url IS NOT NULL', ['c' => $customerId]) as $url) {
            $file = TryonService::imageDir() . basename((string) parse_url((string) $url, PHP_URL_PATH));
            is_file($file) && @unlink($file);
        }
        $db->delete('tryon_request', ['customer_id = ?' => $customerId]);
    }
}
