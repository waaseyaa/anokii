<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\User\Role;
use Waaseyaa\User\User;

/**
 * The common core of an Anokii instance's workspace role and permission model,
 * mapped onto the framework's existing role/permission substrate (no parallel
 * system).
 *
 * Every instance re-derives the same shape: a fixed set of roles, each role a
 * union of permission strings, an admin role that reuses the framework's
 * built-in `administrator` (which short-circuits every permission check), and an
 * apply() that writes a role and its permissions onto a user together. This base
 * captures that shape; the instance fills in {@see roleDefinitions()} with its
 * own roles and permissions.
 *
 * Why apply() stamps permissions: the framework's {@see User::hasPermission()}
 * only special-cases the `administrator` role; it does NOT union a role's
 * permissions. So a non-admin role is applied by writing its concrete permission
 * strings onto the user's flat permissions list, so the role and its permissions
 * always travel together.
 *
 * Framework tie-in: this base implements
 * {@see ProvidesRolesInterface}, so an instance's ServiceProvider that subclasses
 * it (or composes it) is discovered by the framework's role registry and the
 * `user:assign-role` command can resolve these roles and stamp their permissions.
 * This is the clean replacement for each app's hand-rolled assign-role command,
 * which previously existed only because the framework had no role discovery.
 *
 * @api
 */
abstract class AbstractWorkspaceRoles implements ProvidesRolesInterface
{
    /**
     * The framework's built-in all-permissions role. An instance's admin role
     * SHOULD reuse this id so it inherits the administrator short-circuit.
     *
     * @api
     */
    public const string ROLE_ADMINISTRATOR = 'administrator';

    /**
     * The instance's role model: an ordered map of role-id to its definition.
     * Each definition carries a human label, the permission strings the role
     * grants, and an optional weight for ordering (default 0).
     *
     * The instance is the single source of truth here. The base derives
     * everything else (the union of all permissions, the Role value objects for
     * framework discovery, the apply() behavior) from this one declaration.
     *
     * @return array<string, array{label: string, permissions: list<string>, weight?: int}>
     *
     * @api
     */
    abstract protected function roleDefinitions(): array;

    /**
     * Whether a role id belongs to this workspace model.
     *
     * @api
     */
    public function isRole(string $roleId): bool
    {
        return array_key_exists($roleId, $this->roleDefinitions());
    }

    /**
     * The human label for a role id, or the id itself when unknown.
     *
     * @api
     */
    public function label(string $roleId): string
    {
        return $this->roleDefinitions()[$roleId]['label'] ?? $roleId;
    }

    /**
     * Map of role-id to human label, in declaration order. Convenient for the
     * shell user chip ({@see \Anokii\Shell\Shell::roleLabel()}).
     *
     * @return array<string, string>
     *
     * @api
     */
    public function roleLabels(): array
    {
        $labels = [];
        foreach ($this->roleDefinitions() as $id => $def) {
            $labels[$id] = $def['label'];
        }

        return $labels;
    }

    /**
     * The deduplicated union of every permission string any role grants. Useful
     * for the admin role definition and for permission registration.
     *
     * @return list<string>
     *
     * @api
     */
    public function allPermissions(): array
    {
        $permissions = [];
        foreach ($this->roleDefinitions() as $def) {
            foreach ($def['permissions'] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The permission strings a single role grants, or [] for an unknown role.
     *
     * @return list<string>
     *
     * @api
     */
    public function permissionsFor(string $roleId): array
    {
        return $this->roleDefinitions()[$roleId]['permissions'] ?? [];
    }

    /**
     * Apply a workspace role to a user: set the role (replacing any other role
     * from this model, preserving non-workspace roles) and stamp its permission
     * strings. Returns the updated User.
     *
     * IMPORTANT: {@see User::setRoles()} / {@see User::setPermissions()} return a
     * new instance (entities are immutable through setters); they do NOT mutate
     * in place. Callers MUST persist the RETURNED user, not the argument:
     *
     *   $updated = $roles->apply($user, 'editor');
     *   $storage->save($updated);
     *
     * @api
     */
    public function apply(User $user, string $roleId): User
    {
        $defs = $this->roleDefinitions();
        $permissions = $defs[$roleId]['permissions'] ?? [];

        // Drop any role from this model; keep roles owned by other models.
        $kept = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => !array_key_exists($role, $defs),
        ));

        return $user
            ->setRoles(array_values(array_unique([...$kept, $roleId])))
            ->setPermissions($permissions);
    }

    /**
     * Yield the role definitions as framework {@see Role} value objects, so the
     * framework role registry discovers them and `user:assign-role` can stamp
     * their permissions. Implements {@see ProvidesRolesInterface::roles()}.
     *
     * @return iterable<Role>
     *
     * @api
     */
    public function roles(): iterable
    {
        foreach ($this->roleDefinitions() as $id => $def) {
            yield new Role(
                id: $id,
                label: $def['label'],
                permissions: $def['permissions'],
                weight: $def['weight'] ?? 0,
            );
        }
    }
}
