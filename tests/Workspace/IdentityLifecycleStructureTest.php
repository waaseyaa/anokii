<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityLifecycleStructureTest extends TestCase
{
    #[Test]
    public function fresh_workspace_can_create_and_render_a_permission_gated_pillar(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($root . '/src/Provider/WorkspaceServiceProvider.php');
        $controller = (string) file_get_contents($root . '/src/Workspace/Controller/IdentityController.php');
        $template = (string) file_get_contents($root . '/templates/anokii/identity.html.twig');

        self::assertStringContainsString("'/admin/anokii/identity/create'", $provider);
        self::assertStringContainsString("'create', \$this->accounts->principal(\$user)", $controller);
        self::assertStringContainsString("'Pillar created'", $controller);
        self::assertStringContainsString("ucwords(str_replace(['-', '_'], ' ', \$key))", $controller);
        self::assertStringContainsString('id="pillarCreateForm"', $template);
        self::assertStringContainsString("fetch('/admin/anokii/identity/create'", $template);
    }
}
