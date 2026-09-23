<?php

declare(strict_types=1);

namespace Iter\Admin;

use Iter\Admin\Model\ResourceModel\AdminRole;

/**
 * Resolves whether a role has access to a resource by walking up the
 * (code-defined) resource tree from the requested node until an explicit
 * grant is found in admin_role_rule. No grant anywhere in the chain means
 * denied — granting a parent node implicitly grants every descendant.
 */
class Acl
{
    public static function isAllowed(?int $roleId, string $resourceId): bool
    {
        if ($roleId === null) {
            return false;
        }

        $granted = (new AdminRole())->getGrantedResources($roleId);
        $nodes = AclRegistry::byId();

        $current = $resourceId;
        while ($current !== null) {
            if (in_array($current, $granted, true)) {
                return true;
            }
            $current = $nodes[$current]['parent'] ?? null;
        }

        return false;
    }
}
