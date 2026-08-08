<?php

declare(strict_types=1);

namespace Anokii\Workspace;

use Anokii\Admin\AdminModules;
use Anokii\Admin\AdminShell;
use Waaseyaa\Access\User\UserSessionSnapshot;

/**
 * Shell wiring for the Anokii login-gated workspace.
 *
 * The shared chrome context (user chip, nav_active) comes from {@see Shell}, and
 * the module catalog from the package {@see AdminModules}. The distribution ships
 * a fixed baseline live set (the alpha.11 workspace tools); every other catalog
 * module renders as a disabled "preview" card. Instances re-skin via CSS tokens
 * and may extend the live set / overrides by composing their own shell helper.
 *
 * @api
 */
final class WorkspaceShell
{
    /**
     * The workspace tools the distribution ships live out of the box. Co-Intelligence,
     * Venture*, Rooms, Workspaces, Portal, Vault, and Governance stay preview cards
     * (Co-Intelligence's gated workspace surface lands in a later increment).
     *
     * @var list<string>
     */
    private const LIVE = ['dashboard', 'identity', 'drive', 'documents', 'pages', 'inbox', 'analytics'];

    /**
     * @param UserSessionSnapshot $identity Audited identity of the signed-in
     *        account, from {@see \Anokii\Access\AccountBoundary::identity()}.
     *
     * @return array<string, mixed>
     */
    public static function context(UserSessionSnapshot $identity, int|string $accountId, string $active): array
    {
        $modules = self::modules();

        return AdminShell::context(
            $identity,
            $accountId,
            $active,
            $modules,
            [
                'modules' => $modules,
                'home_path' => '/admin/anokii',
                'logout_path' => '/admin/anokii/logout',
            ],
        );
    }

    /**
     * The workspace module list: the canonical catalog with the baseline live set
     * and the Settings extra the catalog does not carry.
     *
     * @return list<array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}>
     */
    public static function modules(): array
    {
        return AdminModules::resolve(self::LIVE, [], self::extra());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::modules() as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Modules the canonical catalog does not carry (Settings).
     *
     * @return list<array<string, mixed>>
     */
    private static function extra(): array
    {
        return [
            [
                'id' => 'settings', 'label' => 'Settings', 'group' => 'Administration', 'live' => true,
                'href' => '/admin/anokii/settings', 'desc' => '', 'badge' => '', 'tile' => false,
                'icon' => '<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" stroke="currentColor" stroke-width="1.5"/>',
            ],
        ];
    }
}
