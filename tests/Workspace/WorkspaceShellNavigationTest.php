<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use Anokii\Workspace\WorkspaceShell;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Access\User\UserSessionSnapshot;

final class WorkspaceShellNavigationTest extends TestCase
{
    public function testRealShellRendersNavigationCurrentPageAndLogout(): void
    {
        $root = dirname(__DIR__, 2);
        $context = WorkspaceShell::context(
            new UserSessionSnapshot('Local reviewer', 'review@example.test', ['administrator']),
            1,
            'identity',
        );
        $twig = new Environment(new FilesystemLoader($root . '/templates'));

        $html = $twig->render('anokii/home.html.twig', $context);

        self::assertStringContainsString('aria-label="Workspace"', $html);
        self::assertStringContainsString('href="/admin/anokii/identity" aria-current="page"', $html);
        self::assertStringContainsString('href="/admin/anokii/logout"', $html);
        self::assertStringContainsString('href="/admin/anokii/settings"', $html);
        self::assertStringContainsString('aria-controls="anokii-workspace-nav"', $html);
        self::assertStringContainsString("event.key === 'Escape'", $html);
    }
}
