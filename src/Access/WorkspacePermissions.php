<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\User\UserAuthorizationSnapshot;

/**
 * Permission decision over an audited authorization snapshot.
 *
 * Framework ≥ alpha.269 seals User `roles`/`permissions` as Internal; the
 * entity's own `hasPermission()` throws FieldReadDenied outside an audited
 * capability. Gates therefore decide from the
 * {@see \Waaseyaa\Access\User\UserInternalFieldReaderInterface::maintenanceAuthorization()}
 * snapshot instead. Semantics mirror the framework's `User::hasPermission()`:
 * the `administrator` role holds every permission; otherwise the permission
 * string must be stamped on the account.
 *
 * @api
 */
final class WorkspacePermissions
{
    public const string ADMINISTRATOR_ROLE = 'administrator';

    private function __construct() {}

    public static function allows(UserAuthorizationSnapshot $authz, string $permission): bool
    {
        return \in_array(self::ADMINISTRATOR_ROLE, $authz->roles, true)
            || \in_array($permission, $authz->permissions, true);
    }
}
