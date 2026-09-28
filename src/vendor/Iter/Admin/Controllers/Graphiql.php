<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Core\View\ComponentResolver;

/**
 * GraphiQL against /core/graphql/index. Same origin, so the admin session
 * cookie goes along and mutations run with the logged-in user's ACL.
 */
class Graphiql
{
    public const ADMIN_RESOURCE = 'Iter_Admin::graphiql';

    public function Index(): void
    {
        // ponytail: full-page tool, outside the admin layout (GraphiQL needs the whole viewport)
        require (new ComponentResolver())->resolveTemplate('admin/graphiql');
    }
}
