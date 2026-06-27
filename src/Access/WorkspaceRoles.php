<?php

declare(strict_types=1);

namespace Anokii\Access;

use Waaseyaa\Access\EntityAccessHandler;

/**
 * The canonical Anokii workspace role and permission model, shipped by the
 * distribution so every instance gets the same multi-member workspace baseline
 * (administrator / editor / viewer) without re-deriving it.
 *
 * Subclasses {@see AbstractWorkspaceRoles}: this class declares only the roles
 * and their permissions in {@see roleDefinitions()}; the base derives apply()
 * (returns the updated User), the framework Role value objects for discovery
 * (ProvidesRolesInterface), the permission union, label map, and accessors.
 *
 * Three roles:
 *   - Admin  -> the framework built-in `administrator` role (short-circuits every
 *               permission check); holds all workspace permissions.
 *   - Editor -> edit/publish across the workspace tools, no destructive ops.
 *   - Viewer -> the coarse agent-tool capabilities only (read-only to content).
 *
 * Instances that need extra roles or permissions (for example a domain-specific
 * permission-gated section) subclass this and extend {@see roleDefinitions()},
 * or compose their own {@see EntityAccessHandler} alongside {@see handler()}.
 *
 * @api
 */
class WorkspaceRoles extends AbstractWorkspaceRoles
{
    // Roles. Admin reuses the framework's all-permissions role.
    public const string ROLE_ADMIN = self::ROLE_ADMINISTRATOR;
    public const string ROLE_EDITOR = 'editor';
    public const string ROLE_VIEWER = 'viewer';

    // Permissions, one edit + one administer per entity-native tool, plus the
    // Pages publish op and the Inbox manage op.
    public const string EDIT_IDENTITY = 'edit identity';
    public const string ADMINISTER_IDENTITY = 'administer identity';
    public const string EDIT_DOCUMENTS = 'edit documents';
    public const string ADMINISTER_DOCUMENTS = 'administer documents';
    public const string EDIT_DRIVE = 'edit drive';
    public const string ADMINISTER_DRIVE = 'administer drive';
    public const string EDIT_PAGES = 'edit pages';
    public const string PUBLISH_PAGES = 'publish pages';
    public const string ADMINISTER_PAGES = 'administer pages';
    public const string MANAGE_INBOX = 'manage inbox';

    /**
     * Coarse capabilities the framework's entity agent tools require before they
     * run. Granted to every workspace role so the per-entity AccessPolicy is the
     * single decisive gate (a Viewer passes the capability check but the policy
     * refuses every write; an Editor is refused deletes). Identical outcome to
     * the UI controllers.
     *
     * @var list<string>
     */
    public const array AGENT_TOOL_CAPABILITIES = [
        'tool.entity.read',
        'tool.entity.list',
        'tool.entity.search',
        'tool.entity.create',
        'tool.entity.update',
        'tool.entity.delete',
    ];

    /**
     * @return array<string, array{label: string, permissions: list<string>, weight?: int}>
     */
    protected function roleDefinitions(): array
    {
        return [
            self::ROLE_ADMIN => [
                'label' => 'Admin',
                'permissions' => self::adminPermissions(),
                'weight' => 0,
            ],
            self::ROLE_EDITOR => [
                'label' => 'Editor',
                'permissions' => [
                    self::EDIT_IDENTITY,
                    self::EDIT_DOCUMENTS,
                    self::EDIT_DRIVE,
                    self::EDIT_PAGES,
                    self::PUBLISH_PAGES,
                    self::MANAGE_INBOX,
                    ...self::AGENT_TOOL_CAPABILITIES,
                ],
                'weight' => 10,
            ],
            self::ROLE_VIEWER => [
                'label' => 'Viewer',
                'permissions' => [...self::AGENT_TOOL_CAPABILITIES],
                'weight' => 20,
            ],
        ];
    }

    /**
     * Every workspace permission, granted to the admin role. Listed explicitly
     * (not derived from the union) so the admin definition does not depend on the
     * base allPermissions() reading roleDefinitions() while it is being built.
     *
     * @return list<string>
     */
    private static function adminPermissions(): array
    {
        return [
            self::EDIT_IDENTITY,
            self::ADMINISTER_IDENTITY,
            self::EDIT_DOCUMENTS,
            self::ADMINISTER_DOCUMENTS,
            self::EDIT_DRIVE,
            self::ADMINISTER_DRIVE,
            self::EDIT_PAGES,
            self::PUBLISH_PAGES,
            self::ADMINISTER_PAGES,
            self::MANAGE_INBOX,
            ...self::AGENT_TOOL_CAPABILITIES,
        ];
    }

    /**
     * The single construction point for the workspace access handler: the six
     * entity policies the baseline ships. Reused by the UI controllers, the agent
     * tools, and tests so there is one source of truth. Instances that add tools
     * compose their own handler with these plus their own policies.
     *
     * @api
     */
    public static function handler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new IdentityPillarAccessPolicy(),
            new DocumentAccessPolicy(),
            new DocumentNoteAccessPolicy(),
            new DriveFileAccessPolicy(),
            new PageAccessPolicy(),
            new ContactSubmissionAccessPolicy(),
        ]);
    }
}
