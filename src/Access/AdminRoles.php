<?php

declare(strict_types=1);

namespace Anokii\Access;

/**
 * The canonical single-admin role model for the public dashboard tier: the
 * framework `administrator` role (which short-circuits every permission check),
 * plus a dashboards-only operator role, both granting one configurable access
 * permission. This is the default an install gets; the permission string is
 * passed in so each install can name its own (e.g. "access rht admin",
 * "access oiatc admin") while sharing this one implementation.
 *
 * The gate ({@see \Anokii\Dashboard\DashboardGate::requirePermission()}) checks
 * that permission: the administrator account passes by short-circuit; an operator
 * passes because {@see AbstractWorkspaceRoles::apply()} (and `user:assign-role`)
 * stamps the permission string onto the account.
 *
 * @api
 */
final class AdminRoles extends AbstractWorkspaceRoles
{
    /** Reuses the framework all-permissions role for the single admin account. */
    public const string ROLE_ADMIN = self::ROLE_ADMINISTRATOR;

    /** Dashboards-only role (no full administrator power). */
    public const string ROLE_OPERATOR = 'anokii-operator';

    /** Default access permission when an install does not name its own. */
    public const string DEFAULT_PERMISSION = 'access anokii admin';

    public function __construct(
        private readonly string $accessPermission = self::DEFAULT_PERMISSION,
    ) {}

    /** The permission the dashboards require; the gate and login controller use this. */
    public function accessPermission(): string
    {
        return $this->accessPermission;
    }

    /**
     * @return array<string, array{label: string, permissions: list<string>, weight?: int}>
     */
    protected function roleDefinitions(): array
    {
        return [
            self::ROLE_ADMIN => [
                'label' => 'Administrator',
                'permissions' => [$this->accessPermission],
                'weight' => 0,
            ],
            self::ROLE_OPERATOR => [
                'label' => 'Operator (dashboards only)',
                'permissions' => [$this->accessPermission],
                'weight' => 10,
            ],
        ];
    }
}
