<?php

declare(strict_types=1);

namespace Anokii\Tests\Access;

use Anokii\Access\AdminRoles;
use Anokii\Access\WorkspacePermissions;
use Anokii\Tests\Support\FakeInternalFieldReader;
use Anokii\Tests\Support\StubUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\User\UserAuthorizationSnapshot;

/**
 * Framework ≥ alpha.269 seals User `roles`/`permissions` as Internal — entity
 * `getRoles()`/`hasPermission()` throw FieldReadDenied outside an audited
 * capability. Role application and permission gating must therefore go through
 * the audited {@see \Waaseyaa\Access\User\UserInternalFieldReaderInterface}
 * (`maintenanceAuthorization()` snapshot), mirroring the framework's own
 * `user:assign-role` handler. These tests pin the audited paths.
 */
#[CoversClass(AdminRoles::class)]
#[CoversClass(WorkspacePermissions::class)]
final class AuditedRoleApplicationTest extends TestCase
{
    #[Test]
    public function apply_merges_roles_from_the_audited_snapshot_not_the_sealed_entity(): void
    {
        $roles = new AdminRoles();
        // The snapshot carries a foreign (non-workspace) role that must be
        // preserved, plus a stale workspace role that must be replaced.
        $reader = new FakeInternalFieldReader(new UserAuthorizationSnapshot(
            roles: ['community_member', AdminRoles::ROLE_OPERATOR],
            permissions: [],
        ));

        $user = new StubUser(['name' => 'x', 'mail' => 'x@example.test', 'status' => 1]);
        $updated = $roles->apply($user, AdminRoles::ROLE_ADMIN, $reader);

        self::assertSame(['community_member', AdminRoles::ROLE_ADMIN], $updated->get('roles'));
        self::assertSame($roles->permissionsFor(AdminRoles::ROLE_ADMIN), $updated->get('permissions'));
    }

    #[Test]
    public function permission_gate_grants_via_snapshot_permission(): void
    {
        $authz = new UserAuthorizationSnapshot(roles: ['operator'], permissions: ['access anokii admin']);

        self::assertTrue(WorkspacePermissions::allows($authz, 'access anokii admin'));
        self::assertFalse(WorkspacePermissions::allows($authz, 'administer site'));
    }

    #[Test]
    public function permission_gate_grants_everything_to_the_administrator_role(): void
    {
        $authz = new UserAuthorizationSnapshot(roles: ['administrator'], permissions: []);

        self::assertTrue(WorkspacePermissions::allows($authz, 'access anokii admin'));
        self::assertTrue(WorkspacePermissions::allows($authz, 'anything at all'));
    }

    #[Test]
    public function permission_gate_denies_with_no_matching_authority(): void
    {
        $authz = new UserAuthorizationSnapshot(roles: [], permissions: []);

        self::assertFalse(WorkspacePermissions::allows($authz, 'access anokii admin'));
    }
}
