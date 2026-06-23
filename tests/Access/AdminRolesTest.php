<?php

declare(strict_types=1);

namespace Anokii\Tests\Access;

use Anokii\Access\AdminRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared single-admin role model: admin reuses the framework `administrator`
 * role, the access permission is configurable per install, and both roles grant
 * it. Pins the contract the gate + login + create-admin command depend on.
 */
#[CoversClass(AdminRoles::class)]
final class AdminRolesTest extends TestCase
{
    #[Test]
    public function admin_reuses_the_framework_administrator_role(): void
    {
        self::assertSame('administrator', AdminRoles::ROLE_ADMIN);
    }

    #[Test]
    public function the_access_permission_defaults_and_is_configurable(): void
    {
        self::assertSame('access anokii admin', new AdminRoles()->accessPermission());
        self::assertSame('access oiatc admin', new AdminRoles('access oiatc admin')->accessPermission());
    }

    #[Test]
    public function both_roles_grant_the_configured_permission(): void
    {
        $roles = new AdminRoles('access oiatc admin');
        self::assertSame(['access oiatc admin'], $roles->permissionsFor(AdminRoles::ROLE_ADMIN));
        self::assertSame(['access oiatc admin'], $roles->permissionsFor(AdminRoles::ROLE_OPERATOR));
        self::assertTrue($roles->isRole(AdminRoles::ROLE_ADMIN));
        self::assertTrue($roles->isRole(AdminRoles::ROLE_OPERATOR));
    }

    #[Test]
    public function roles_are_discoverable_as_framework_role_value_objects(): void
    {
        $ids = [];
        foreach (new AdminRoles()->roles() as $role) {
            $ids[] = $role->id;
        }
        self::assertSame(['administrator', AdminRoles::ROLE_OPERATOR], $ids);
    }
}
