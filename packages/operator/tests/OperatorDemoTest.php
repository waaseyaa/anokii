<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Demo\DemoFixture;
use Anokii\Operator\Demo\OperatorDemo;
use Anokii\Operator\Module\OperatorModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OperatorDemoTest extends TestCase
{
    private const string MARKER = 'Demo: nothing is saved or sent';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function dashboardRendersThroughTheRealShellWithDemoChrome(): void
    {
        $html = self::html(self::example(), '/admin/anokii');

        self::assertStringContainsString('Welcome, Sample Operator', $html);
        self::assertStringContainsString('<title>Workspace | Example Nation</title>', $html);
        self::assertStringContainsString('href="/demo-assets/theme.css"', $html);
        self::assertStringContainsString('src="/demo-assets/logo.svg"', $html);
        self::assertStringContainsString('class="anokii-grid-card" href="/admin/anokii/desk"', $html);
        self::assertStringContainsString('class="anokii-grid-card" href="/admin/anokii/review"', $html);
        self::assertStringContainsString('class="anokii-grid-card" href="/admin/anokii/records"', $html);
        // The real shell's layout and mobile nav, not a copy of them.
        self::assertStringContainsString('Open workspace navigation', $html);
        self::assertStringContainsString('grid-template-rows:auto minmax(0,1fr)', $html);
        self::assertDemoChrome($html);
    }

    #[Test]
    public function modulesWithoutAHostTemplateUseThePackageModulePage(): void
    {
        $html = self::html(self::example(), '/admin/anokii/records');

        self::assertStringContainsString('<title>Records | Example Nation</title>', $html);
        self::assertStringContainsString('Recent records', $html);
        self::assertStringContainsString('Sample meeting notes', $html);
        self::assertStringContainsString('Find a record', $html);
        self::assertStringContainsString('href="/admin/anokii/records" aria-current="page"', $html);
        self::assertDemoChrome($html);
    }

    #[Test]
    public function hostPagesContributeTheirOwnMarkupStylesAndScript(): void
    {
        $html = self::html(self::example(), '/admin/anokii/desk');

        self::assertStringContainsString('<title>Communications desk | Example Nation</title>', $html);
        self::assertStringContainsString('1. Choose a source', $html);
        // The package's primitive styles load first, then the page's own.
        self::assertMatchesRegularExpression('#href="/anokii-demo/primitives\.css">\s*<link rel="stylesheet" href="/demo-assets/scenarios\.css">#', $html);
        self::assertStringContainsString('<script type="module" src="/demo-assets/desk.js"></script>', $html);
        self::assertStringContainsString('href="/admin/anokii/desk" aria-current="page"', $html);
        self::assertStringContainsString('href="/admin/anokii/review"', $html);
        self::assertStringContainsString('href="/admin/anokii/records"', $html);
        self::assertStringContainsString('Open workspace navigation', $html);
        self::assertDemoChrome($html);
    }

    #[Test]
    #[DataProvider('chromeBlocks')]
    public function hostPagesCannotReplaceDemoChrome(string $block): void
    {
        $demo = $this->demoWithPage("{% extends '@anokii_operator/shell.html.twig' %}"
            . "{% block {$block} %}<a href=\"/logout\">Sign out</a>{% endblock %}{% block content %}Body{% endblock %}");

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("\"{$block}\"");
        $demo->handle(Request::create('/admin/anokii/desk'));
    }

    /** @return iterable<string, array{string}> */
    public static function chromeBlocks(): iterable
    {
        foreach (OperatorDemo::CHROME_BLOCKS as $block) {
            yield $block => [$block];
        }
    }

    #[Test]
    #[DataProvider('topLevelImports')]
    public function hostPagesMustImportMacrosInsideTheirBlocks(string $import): void
    {
        $demo = $this->demoWithPage("{% extends '@anokii_operator/shell.html.twig' %}{$import}"
            . '{% block content %}Body{% endblock %}');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('imports macros outside a block');
        $demo->handle(Request::create('/admin/anokii/desk'));
    }

    /** @return iterable<string, array{string}> */
    public static function topLevelImports(): iterable
    {
        yield 'import' => ["{% import '@anokii_operator_demo/primitives.html.twig' as demo %}"];
        yield 'from' => ["{% from '@anokii_operator_demo/primitives.html.twig' import sim %}"];
    }

    #[Test]
    public function importsInAnExtendedHostLayoutAreRejectedToo(): void
    {
        $demo = $this->demoWithPage(
            "{% extends 'layout.html.twig' %}{% block content %}Body{% endblock %}",
            ['layout.html.twig' => "{% extends '@anokii_operator/shell.html.twig' %}{% import '@anokii_operator_demo/primitives.html.twig' as demo %}"],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('imports macros outside a block (in layout.html.twig)');
        $demo->handle(Request::create('/admin/anokii/desk'));
    }

    #[Test]
    public function macrosImportedInsideABlockRender(): void
    {
        $demo = $this->demoWithPage("{% extends '@anokii_operator/shell.html.twig' %}"
            . "{% block content %}{% import '@anokii_operator_demo/primitives.html.twig' as demo %}{{ demo.sim('Sample row') }}{% endblock %}");

        self::assertStringContainsString('<span class="anokii-demo-sim">Sample row</span>', self::html($demo, '/admin/anokii/desk'));
    }

    #[Test]
    public function hostPageTopLevelCodeCannotRewriteTheShellContext(): void
    {
        $demo = $this->demoWithPage("{% extends '@anokii_operator/shell.html.twig' %}"
            . "{% set nav = [] %}{% set user_label = 'Somebody Else' %}{% block content %}Body for {{ user_label }}{% endblock %}");

        $html = self::html($demo, '/admin/anokii/desk');

        self::assertStringContainsString('Body for Sample Operator', $html);
        self::assertStringContainsString('href="/admin/anokii/desk" aria-current="page"', $html);
        self::assertStringNotContainsString('Somebody Else', $html);
        self::assertDemoChrome($html);
    }

    #[Test]
    public function everyResponseIsPrivateAndBlocksOutboundConnections(): void
    {
        $demo = self::example();
        $requests = [
            Request::create('/admin/anokii'),
            Request::create('/admin/anokii/desk'),
            Request::create('/admin/anokii/review'),
            Request::create('/admin/anokii/records'),
            Request::create('/demo-assets/theme.css'),
            Request::create('/anokii-demo/primitives.js'),
            Request::create('/'),
            Request::create('/missing'),
            Request::create('/admin/anokii/desk', 'POST'),
        ];

        foreach ($requests as $request) {
            $response = $demo->handle($request);
            $label = $request->getMethod() . ' ' . $request->getRequestUri();
            self::assertSame(OperatorDemo::CONTENT_SECURITY_POLICY, $response->headers->get('Content-Security-Policy'), $label);
            self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'), $label);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $label);
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $label);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $label);
        }
        self::assertStringContainsString("connect-src 'none'", OperatorDemo::CONTENT_SECURITY_POLICY);
        self::assertStringContainsString("form-action 'none'", OperatorDemo::CONTENT_SECURITY_POLICY);
    }

    #[Test]
    public function onlyTheDashboardModuleRoutesAndDeclaredAssetsAreServed(): void
    {
        $demo = self::example();

        $redirect = $demo->handle(Request::create('/'));
        self::assertSame(Response::HTTP_FOUND, $redirect->getStatusCode());
        self::assertSame('/admin/anokii', $redirect->headers->get('Location'));

        $theme = $demo->handle(Request::create('/demo-assets/theme.css?v=1'));
        self::assertSame(Response::HTTP_OK, $theme->getStatusCode());
        self::assertSame('text/css; charset=UTF-8', $theme->headers->get('Content-Type'));
        self::assertStringEqualsFile(dirname(__DIR__) . '/examples/demo/assets/theme.css', (string) $theme->getContent());
        self::assertSame('image/svg+xml', $demo->handle(Request::create('/demo-assets/logo.svg'))->headers->get('Content-Type'));
        foreach (['primitives.css' => 'text/css; charset=UTF-8', 'primitives.js' => 'text/javascript; charset=UTF-8'] as $file => $type) {
            $asset = $demo->handle(Request::create('/anokii-demo/' . $file));
            self::assertSame(Response::HTTP_OK, $asset->getStatusCode(), $file);
            self::assertSame($type, $asset->headers->get('Content-Type'), $file);
            self::assertStringEqualsFile(dirname(__DIR__) . '/demo/assets/' . $file, (string) $asset->getContent());
        }

        foreach (['/composer.json', '/fixture.php', '/demo-assets/', '/demo-assets/../fixture.php', '/templates/updates.html.twig', '/admin/anokii/', '/admin/anokii/missing', '/anokii-demo/', '/anokii-demo/primitives.html.twig', '/anokii-demo/../README.md'] as $path) {
            self::assertSame(Response::HTTP_NOT_FOUND, $demo->handle(Request::create($path))->getStatusCode(), $path);
        }

        $post = $demo->handle(Request::create('/admin/anokii/desk', 'POST'));
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $post->getStatusCode());
        self::assertSame('GET, HEAD', $post->headers->get('Allow'));
    }

    /** @param array<string, mixed> $patch */
    #[Test]
    #[DataProvider('invalidFixtures')]
    public function invalidFixturesAreRejectedBeforeRendering(array $patch, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DemoFixture::fromArray([...self::validFixture(), ...$patch], dirname(__DIR__) . '/examples/demo');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidFixtures(): iterable
    {
        $module = static fn(string $id, string $href): OperatorModule => new OperatorModule($id, ucfirst($id), 'Daily work', $href, 'Sample module.');

        yield 'unknown key' => [['colour' => 'teal'], 'unknown keys: colour'];
        yield 'missing brand title' => [['brand' => ['tag' => 'Workspace']], 'brand.title'];
        yield 'missing operator' => [['operator' => ['name' => 'Sample Operator']], 'operator.role'];
        yield 'module arrays' => [['modules' => [['id' => 'desk']]], 'list of OperatorModule values'];
        yield 'no modules' => [['modules' => []], 'list of OperatorModule values'];
        yield 'duplicate module ids' => [['modules' => [$module('desk', '/admin/anokii/desk'), $module('desk', '/admin/anokii/other')]], 'Duplicate'];
        yield 'module on the dashboard route' => [['modules' => [$module('home', '/admin/anokii')]], 'below /admin/anokii/'];
        yield 'module route with a query' => [['modules' => [$module('desk', '/admin/anokii/desk?tab=1')]], 'no query or fragment'];
        yield 'shared module route' => [['modules' => [$module('desk', '/admin/anokii/desk'), $module('other', '/admin/anokii/desk')]], 'share the route'];
        yield 'page without module' => [['pages' => ['ghost' => []]], 'no matching module'];
        yield 'page context replaces the nav' => [['pages' => ['desk' => ['context' => ['nav' => []]]]], 'shell keys: nav'];
        yield 'page context replaces the operator' => [['pages' => ['desk' => ['context' => ['user_label' => 'Somebody Else']]]], 'shell keys: user_label'];
        yield 'template outside the templates directory' => [['pages' => ['desk' => ['template' => '../fixture.php']]], "fixture's templates directory"];
        yield 'namespaced package template' => [['pages' => ['desk' => ['template' => '@anokii_operator/dashboard.html.twig']]], "fixture's templates directory"];
        yield 'template without a templates directory' => [['templates' => null, 'pages' => ['desk' => ['template' => 'desk.html.twig']]], 'no templates directory'];
        yield 'remote theme' => [['theme_href' => 'https://example.invalid/theme.css'], 'theme_href'];
        yield 'undeclared logo' => [['brand' => ['title' => 'Example Nation', 'logo_src' => '/logo.png']], 'brand.logo_src'];
        yield 'asset under the operator routes' => [['assets' => ['/admin/anokii/theme.css' => 'assets/theme.css']], 'outside /admin/anokii'];
        yield 'asset under the package demo path' => [['assets' => ['/anokii-demo/theme.css' => 'assets/theme.css']], 'reserved for the package'];
        yield 'asset path traversal' => [['assets' => ['/demo-assets/../theme.css' => 'assets/theme.css']], 'plain local path'];
        yield 'asset with a script type' => [['assets' => ['/fixture.php' => 'fixture.php']], 'unsupported type'];
        yield 'missing asset file' => [['assets' => ['/demo-assets/missing.css' => 'assets/missing.css']], 'file not found'];
    }

    #[Test]
    public function aMissingFixtureFileIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Demo fixture file not found');

        OperatorDemo::fromFixtureFile(dirname(__DIR__) . '/examples/demo/missing.php');
    }

    private static function assertDemoChrome(string $html): void
    {
        self::assertSame(1, substr_count($html, self::MARKER));
        self::assertStringContainsString('Sample identity. Sign-out is off.', $html);
        self::assertStringNotContainsString('class="anokii-signout"', $html);
        self::assertStringNotContainsString('/logout', $html);
    }

    private static function example(): OperatorDemo
    {
        return OperatorDemo::fromFixtureFile(dirname(__DIR__) . '/examples/demo/fixture.php');
    }

    private static function html(OperatorDemo $demo, string $path): string
    {
        $response = $demo->handle(Request::create($path));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'), $path);

        return (string) $response->getContent();
    }

    /** @return array<string, mixed> */
    private static function validFixture(): array
    {
        return [
            'brand' => ['title' => 'Example Nation', 'logo_src' => '/demo-assets/logo.svg'],
            'theme_href' => '/demo-assets/theme.css',
            'operator' => ['name' => 'Sample Operator', 'role' => 'Communications'],
            'modules' => [new OperatorModule('desk', 'Desk', 'Daily work', '/admin/anokii/desk', 'Sample desk.')],
            'pages' => ['desk' => ['template' => 'desk.html.twig']],
            'templates' => 'templates',
            'assets' => ['/demo-assets/theme.css' => 'assets/theme.css', '/demo-assets/logo.svg' => 'assets/logo.svg'],
        ];
    }

    /** @param array<string, string> $partials other host templates, by name */
    private function demoWithPage(string $template, array $partials = []): OperatorDemo
    {
        $dir = sys_get_temp_dir() . '/anokii-operator-demo-' . bin2hex(random_bytes(6));
        mkdir($dir . '/templates', 0o700, true);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . '/templates/desk.html.twig', $template);
        foreach ($partials as $name => $source) {
            file_put_contents($dir . '/templates/' . $name, $source);
        }

        return new OperatorDemo(DemoFixture::fromArray([
            'brand' => ['title' => 'Example Nation'],
            'operator' => ['name' => 'Sample Operator', 'role' => 'Communications'],
            'modules' => [new OperatorModule('desk', 'Desk', 'Daily work', '/admin/anokii/desk', 'Sample desk.')],
            'pages' => ['desk' => ['template' => 'desk.html.twig']],
            'templates' => 'templates',
        ], $dir));
    }
}
