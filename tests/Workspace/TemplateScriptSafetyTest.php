<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\SsrServiceProvider;

final class TemplateScriptSafetyTest extends TestCase
{
    #[Test]
    public function page_block_json_cannot_terminate_its_script_element(): void
    {
        $twig = SsrServiceProvider::createTwigEnvironment(dirname(__DIR__, 2));
        $payload = '</script><script>globalThis.compromised=true</script>';

        $html = $twig->render('anokii/pages.html.twig', [
            'editing' => true,
            'page' => [
                'id' => 'home',
                'path' => '/',
                'title' => 'Home',
                'meta_description' => '',
                'meta_robots' => '',
                'head_styles' => '',
                'blocks' => [['type' => 'text', 'body' => $payload]],
                'draft_rev' => 1,
                'published_rev' => 1,
            ],
            'modules' => [],
            'nav_active' => 'pages',
            'user_label' => 'Editor',
            'user_role' => 'Editor',
            'user_initials' => 'ED',
        ]);

        self::assertStringNotContainsString($payload, $html);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $html);
        self::assertSame(1, substr_count($html, 'globalThis.compromised=true'));
    }

    #[Test]
    public function every_raw_json_script_embedding_uses_hex_escaping(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/\|json_encode\|raw/',
                $source,
                $file->getPathname() . ' embeds JSON without script-safe hex escaping.',
            );
        }
    }

    #[Test]
    public function drive_icon_map_is_available_to_its_script_block(): void
    {
        $twig = SsrServiceProvider::createTwigEnvironment(dirname(__DIR__, 2));

        $html = $twig->render('anokii/drive.html.twig', [
            'files' => [],
            'folders' => [],
            'modules' => [],
            'nav' => [],
            'nav_active' => 'drive',
            'user_label' => 'Editor',
            'user_role' => 'Editor',
            'user_initials' => 'ED',
        ]);

        self::assertStringNotContainsString('var ICONS = null;', $html);
        self::assertMatchesRegularExpression('/var ICONS = \{.*"gen":/s', $html);
    }

    #[Test]
    public function identity_output_does_not_delegate_untrusted_html_to_a_host_renderer(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/anokii/identity.html.twig');

        self::assertStringNotContainsString('window.AnokiiMd', $source);
        self::assertStringContainsString("raw+=(raw?'\\n\\n':'')+d.error", $source);
        self::assertStringContainsString("el.textContent='Last edited by '", $source);
        self::assertStringNotContainsString("el.innerHTML='Last edited by '", $source);
    }

    #[Test]
    public function failed_document_upload_can_retry_the_same_file(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/anokii/documents.html.twig');

        self::assertStringContainsString("xhr.onload=function(){ fileInput.value=''", $source);
        self::assertStringContainsString("xhr.onerror=function(){ fileInput.value=''", $source);
    }
}
