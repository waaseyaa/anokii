<?php

declare(strict_types=1);

namespace Anokii\Admin;

use Anokii\Shell\Shell;
use Waaseyaa\User\User;

/**
 * Builds the render context for an Anokii admin page: the shared shell chrome
 * (user chip, nav_active) from {@see Shell}, plus the nav list and the dashboard
 * tile lists derived from a resolved {@see AdminModules} set, and the per-install
 * branding/theme passthrough.
 *
 * One source of truth for the sidebar nav and the dashboard grid across every
 * install: the instance supplies only the resolved module list (which decides the
 * live/preview split), the active id, and its brand/theme, never its own copy of
 * the nav-building logic.
 *
 * @phpstan-import-type Module from AdminModules
 *
 * @api
 */
final class AdminShell
{
    /**
     * Assemble the context for an admin page rendered through anokii/_shell.html.twig.
     *
     * @param User                                                                                                       $user    Signed-in account.
     * @param string                                                                                                     $active  Active module/nav id.
     * @param list<array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}> $modules Resolved module set (see AdminModules::resolve()).
     * @param array<string, mixed>                                                                                       $opts    Branding/theme + page extras: brand_title, brand_tag, theme_href, home_path, logout_path, page_title, plus any page-specific context.
     * @param array<string, string>                                                                                      $roleLabels Optional role-id to label map for the user chip.
     *
     * @return array<string, mixed>
     *
     * @api
     */
    public static function context(
        User $user,
        string $active,
        array $modules,
        array $opts = [],
        array $roleLabels = [],
    ): array {
        $nav = [];
        $live = [];
        $preview = [];
        foreach ($modules as $m) {
            $nav[] = [
                'id' => $m['id'],
                'label' => $m['label'],
                'href' => $m['href'],
                'group' => $m['group'],
                'icon' => $m['icon'],
                'badge' => $m['badge'],
            ];
            if (($m['tile'] ?? false) !== true) {
                continue;
            }
            $card = [
                'label' => $m['label'],
                'href' => $m['href'],
                'desc' => $m['desc'],
                'icon' => $m['icon'],
                'badge' => $m['badge'],
            ];
            if ($m['live'] === true) {
                $live[] = $card;
            } else {
                $preview[] = $card;
            }
        }

        $defaults = [
            'brand_title' => 'Anokii',
            'brand_tag' => 'Workspace',
            'theme_href' => '',
            'home_path' => '/admin/anokii',
            'logout_path' => '#',
        ];

        // Precedence (PHP "+" keeps the left key): the derived nav/cards win, then
        // the instance opts, then the defaults fill anything missing.
        $extra = [
            'nav' => $nav,
            'live_cards' => $live,
            'preview_cards' => $preview,
        ] + $opts + $defaults;

        return Shell::context($user, $active, $extra, $roleLabels);
    }
}
