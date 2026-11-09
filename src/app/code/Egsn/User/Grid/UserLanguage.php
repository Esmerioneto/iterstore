<?php

declare(strict_types=1);

namespace Egsn\User\Grid;

use Iter\Admin\Model\AdminUser;
use Iter\Admin\View\GridColumnSource;

/**
 * "Idioma" in the admin user grid (etc/grid_columns.json): admin_user.interface_locale of the page's
 * users, with the label of the user form (AdminUser::interfaceLocales()); an unlisted code shows as is
 */
class UserLanguage implements GridColumnSource
{
    public function values(array $rows): array
    {
        $labels = AdminUser::interfaceLocales();
        $db = (new AdminUser())->getResource()->getConnection();
        $locales = $db->fetchPairs($db->select()->from('admin_user', ['user_id', 'interface_locale'])
            ->where('user_id IN (?)', array_column($rows, 'user_id')));

        return array_map(static fn (string $locale) => $labels[$locale] ?? $locale, $locales);
    }
}
