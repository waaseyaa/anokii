<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CmsPageLifecycleStructureTest extends TestCase
{
    #[Test]
    public function cmsCreationPublicationAndPublicRenderingRemainConnected(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($root . '/src/Provider/WorkspaceServiceProvider.php');
        $service = (string) file_get_contents($root . '/src/Workspace/Pages/PagesService.php');
        $renderer = (string) file_get_contents($root . '/src/Workspace/Pages/PublishedPageRenderer.php');
        $template = (string) file_get_contents($root . '/templates/anokii/pages.html.twig');

        self::assertStringContainsString("'/admin/anokii/pages/create'", $provider);
        self::assertStringContainsString('new PublishedPageRenderer(', $provider);
        self::assertStringContainsString("'anokii.public.page.' . hash('sha256', \$path)", $provider);
        self::assertStringContainsString('public function createPage(', $service);
        self::assertStringContainsString("->setStatus('draft')", $service);
        self::assertStringContainsString("->setStatus('published')", $service);
        self::assertStringContainsString("getStatus() === 'published'", $renderer);
        self::assertStringContainsString('id="pcreate"', $template);
    }
}
