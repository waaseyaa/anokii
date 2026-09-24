<?php

declare(strict_types=1);

namespace Anokii\Operator\Demo;

use Anokii\Operator\Http\OperatorResponsePolicy;
use Anokii\Operator\Module\OperatorModule;
use Anokii\Operator\Shell\OperatorShell;
use Anokii\Operator\Template\OperatorTemplates;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders a fixture-driven operator demo through the real @anokii_operator
 * templates. It has no kernel, database, auth, or network access, and every
 * response is private and blocked from making outbound connections.
 *
 * Only demo/router.php (PHP's built-in server) and tests construct this; no
 * production route or service provider does.
 */
final class OperatorDemo
{
    public const string HOME_PATH = '/admin/anokii';

    public const string CONTENT_SECURITY_POLICY = "default-src 'none'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'none'; "
        . "form-action 'none'; frame-ancestors 'none'; base-uri 'none'";

    /** Blocks a host demo page may contribute. */
    public const array PAGE_BLOCKS = ['title', 'head_extra', 'shell_styles', 'content', 'page_scripts'];

    /** Shell chrome that only the package renders in a demo. */
    public const array CHROME_BLOCKS = ['brand', 'nav', 'userchip', 'sidebar_footer', 'topbar', 'main_footer'];

    private readonly Environment $twig;

    public function __construct(private readonly DemoFixture $fixture)
    {
        $loader = new FilesystemLoader();
        if ($fixture->templatesPath !== null) {
            $loader->addPath($fixture->templatesPath);
        }
        $this->twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html', 'cache' => false]);
        OperatorTemplates::register($this->twig);
        // The demo shell shadows @anokii_operator/shell.html.twig for this
        // process only and extends the real one, so the unmodified dashboard
        // and module templates render with the demo marker and inert chrome.
        $loader->prependPath(self::templatesPath() . '/overlay', 'anokii_operator');
        $loader->addPath(OperatorTemplates::path(), 'anokii_operator_base');
        $loader->addPath(self::templatesPath(), 'anokii_operator_demo');
    }

    public static function fromFixtureFile(string $file): self
    {
        return new self(DemoFixture::load($file));
    }

    public static function templatesPath(): string
    {
        return dirname(__DIR__, 2) . '/demo/templates';
    }

    public function handle(Request $request): Response
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return self::secure(new Response('This demo does not accept changes.', Response::HTTP_METHOD_NOT_ALLOWED, [
                'Allow' => 'GET, HEAD',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]));
        }

        // Match the raw request path exactly. Under php -S, a URL that names a
        // file in the document root becomes SCRIPT_NAME, and getPathInfo()
        // would then report "/" for it.
        $path = explode('?', $request->getRequestUri(), 2)[0];
        if ($path === '/') {
            return self::secure(new RedirectResponse(self::HOME_PATH));
        }
        if (isset($this->fixture->assets[$path])) {
            return self::secure(new Response((string) file_get_contents($this->fixture->assets[$path]), Response::HTTP_OK, [
                'Content-Type' => DemoFixture::ASSET_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))],
            ]));
        }
        if ($path === self::HOME_PATH) {
            return self::html($this->twig->render(
                '@anokii_operator/dashboard.html.twig',
                $this->context('', ['page_intro' => $this->fixture->dashboardIntro]),
            ));
        }
        foreach ($this->fixture->modules as $module) {
            if ($module->href === $path) {
                return self::html($this->renderModule($module));
            }
        }

        return self::secure(new Response('Not found in this demo.', Response::HTTP_NOT_FOUND, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]));
    }

    private function renderModule(OperatorModule $module): string
    {
        $page = $this->fixture->pages[$module->id] ?? ['template' => null, 'title' => null, 'eyebrow' => null, 'intro' => null, 'context' => []];
        $context = $this->context($module->id, [
            'page_title' => $page['title'] ?? $module->label,
            'page_eyebrow' => $page['eyebrow'] ?? $module->group,
            'page_intro' => $page['intro'] ?? $module->description,
            ...$page['context'],
        ]);
        if ($page['template'] === null) {
            return $this->twig->render('@anokii_operator/module-preview.html.twig', [
                'primary_title' => 'Sample work',
                'primary_intro' => 'Fixture content for this demo. Nothing here is real.',
                'preview_rows' => [],
                'quick_actions' => [],
                ...$context,
            ]);
        }

        return $this->renderHostPage($page['template'], $context);
    }

    /**
     * Renders a host page one allowed block at a time, then frames those blocks
     * in the shell. Top-level template code never runs, so a page cannot swap
     * the brand, nav, user chip, or demo marker, even through `{% set %}`.
     *
     * @param array<string, mixed> $context
     */
    private function renderHostPage(string $template, array $context): string
    {
        $page = $this->twig->load($template);
        $shell = $this->twig->load('@anokii_operator/shell.html.twig');
        foreach (self::CHROME_BLOCKS as $block) {
            if ($page->hasBlock($block, $context) && $page->renderBlock($block, $context) !== $shell->renderBlock($block, $context)) {
                throw new \LogicException(sprintf(
                    'Demo page %s overrides the shell block "%s". Demo chrome comes only from the operator package; put page markup in %s.',
                    $template,
                    $block,
                    implode(', ', self::PAGE_BLOCKS),
                ));
            }
        }
        $blocks = [];
        foreach (self::PAGE_BLOCKS as $block) {
            $blocks[$block] = $page->hasBlock($block, $context) ? $page->renderBlock($block, $context) : '';
        }

        return $this->twig->render('@anokii_operator_demo/page.html.twig', [...$context, 'page_blocks' => $blocks]);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function context(string $activeModule, array $page): array
    {
        return OperatorShell::context($this->fixture->modules, $activeModule, $this->fixture->operatorName, $this->fixture->operatorRole, [
            'brand_title' => $this->fixture->brandTitle,
            'brand_tag' => $this->fixture->brandTag,
            'brand_logo_src' => $this->fixture->brandLogoSrc,
            'brand_logo_alt' => $this->fixture->brandLogoAlt,
            'theme_href' => $this->fixture->themeHref,
            'home_path' => self::HOME_PATH,
            'logout_path' => '',
            ...$page,
        ]);
    }

    private static function html(string $html): Response
    {
        return self::secure(new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']));
    }

    private static function secure(Response $response): Response
    {
        OperatorResponsePolicy::apply($response);
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
