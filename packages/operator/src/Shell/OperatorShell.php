<?php

declare(strict_types=1);

namespace Anokii\Operator\Shell;

use Anokii\Operator\Module\OperatorModule;

/** Builds the stable, host-brandable context consumed by the operator shell. */
final class OperatorShell
{
    /**
     * @param list<OperatorModule> $modules
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    public static function context(
        array $modules,
        string $activeModule,
        string $userLabel,
        string $roleLabel,
        array $page = [],
    ): array {
        $nav = [];
        $tiles = [];
        foreach ($modules as $module) {
            $item = $module->toArray();
            $item['active'] = $module->id === $activeModule;
            $nav[] = $item;
            if ($module->dashboardTile) {
                $tiles[] = $item;
            }
        }

        $defaults = [
            'brand_title' => 'Anokii',
            'brand_tag' => 'Operator workspace',
            'brand_logo_src' => '',
            'brand_logo_alt' => '',
            'theme_href' => '',
            'home_path' => '/admin/anokii',
            'logout_path' => '/logout',
            'page_title' => 'Workspace',
            'page_eyebrow' => 'Daily work',
            'page_intro' => 'Choose the work you need to do.',
            'nav_active' => $activeModule,
            'nav' => $nav,
            'tiles' => $tiles,
            'user_label' => trim($userLabel) !== '' ? trim($userLabel) : 'Signed-in operator',
            'user_role' => trim($roleLabel) !== '' ? trim($roleLabel) : 'Operator',
            'user_initials' => self::initials($userLabel),
        ];
        $context = array_replace($defaults, $page);
        // Host presentation may vary, but the authorized module result cannot
        // be replaced by arbitrary page context.
        $context['nav'] = $nav;
        $context['tiles'] = $tiles;
        $context['nav_active'] = $activeModule;

        return $context;
    }

    private static function initials(string $label): string
    {
        $parts = preg_split('/\s+/', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'OP';
        }
        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[array_key_last($parts)], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
