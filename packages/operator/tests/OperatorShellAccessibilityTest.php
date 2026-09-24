<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Demo\OperatorDemo;
use Anokii\Operator\Module\OperatorModule;
use Anokii\Operator\Shell\OperatorShell;
use Anokii\Operator\Template\OperatorTemplates;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * DIR-A001 requirements of the production operator shell: a skip link as the
 * first focusable element, and decorative icons hidden from assistive
 * technology (#41), and a mobile menu whose Escape stays out of the page's way
 * (#43). The Escape test models which focusable elements the pinned listeners
 * can hear; keyboard behaviour itself is checked by hand in a browser.
 */
final class OperatorShellAccessibilityTest extends TestCase
{
    private const string ICON = '<path d="M4 7h16" stroke="currentColor"/>';

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'dashboard' => [self::render('@anokii_operator/dashboard.html.twig', '')];
        yield 'module page' => [self::render('@anokii_operator/module-preview.html.twig', 'website', [
            'primary_title' => 'Recent work',
            'primary_intro' => 'Sample rows.',
            'preview_rows' => [],
            'quick_actions' => [],
        ])];
        $demo = OperatorDemo::fromFixtureFile(dirname(__DIR__) . '/examples/demo/fixture.php');
        yield 'demo page' => [(string) $demo->handle(Request::create('/admin/anokii/desk'))->getContent()];
        yield 'host page with a sidebar footer' => [self::render('host.html.twig', 'website', [], <<<'TWIG'
            {% extends '@anokii_operator/shell.html.twig' %}
            {% block sidebar_footer %}<div class="host-footer"><button type="button">Help</button></div>{% endblock %}
            {% block content %}<p>Host page.</p><button type="button">Save</button>{% endblock %}
            TWIG)];
    }

    #[Test]
    #[DataProvider('pages')]
    public function theFirstFocusableElementSkipsToTheMainRegion(string $html): void
    {
        $page = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $focusable = null;
        foreach ($page->querySelectorAll('a[href], button, input, select, textarea, [tabindex]') as $element) {
            if ($element->getAttribute('tabindex') !== '-1') {
                $focusable = $element;
                break;
            }
        }
        self::assertInstanceOf(Element::class, $focusable);
        self::assertSame('anokii-skiplink', $focusable->getAttribute('class'));
        self::assertSame('#anokii-main-content', $focusable->getAttribute('href'));
        self::assertSame('Skip to main content', trim((string) $focusable->textContent));

        $targets = $page->querySelectorAll('[id="anokii-main-content"]');
        self::assertCount(1, $targets);
        $target = $targets->item(0);
        self::assertInstanceOf(Element::class, $target);
        self::assertSame('MAIN', $target->tagName);
        // Main becomes focusable only for a skip, so an ordinary click in main
        // never focuses it.
        self::assertFalse($target->hasAttribute('tabindex'));
        self::assertStringContainsString("main.setAttribute('tabindex', '-1');", $html);
        self::assertStringContainsString("main.addEventListener('blur', () => main.removeAttribute('tabindex'), { once: true });", $html);
    }

    #[Test]
    #[DataProvider('pages')]
    public function decorativeIconsAreHiddenAndNamesStayTheVisibleLabels(string $html): void
    {
        $page = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $icons = $page->querySelectorAll('.anokii-nav svg, .anokii-grid svg, .anokii-navtoggle svg');
        self::assertGreaterThan(0, count($icons));
        foreach ($icons as $icon) {
            self::assertSame('true', $icon->getAttribute('aria-hidden'));
        }
        foreach ($page->querySelectorAll('.anokii-nav svg, .anokii-grid svg') as $icon) {
            self::assertSame('false', $icon->getAttribute('focusable'));
        }

        $links = $page->querySelectorAll('.anokii-navlink');
        self::assertGreaterThan(0, count($links));
        foreach ($links as $link) {
            $label = $link->querySelector('span');
            self::assertInstanceOf(Element::class, $label);
            self::assertNotSame('', trim((string) $label->textContent));
            self::assertSame(trim((string) $label->textContent), self::exposedText($link));
        }
        // A tile's name starts with its visible label, then its description.
        foreach ($page->querySelectorAll('.anokii-grid-card') as $tile) {
            $label = trim((string) $tile->querySelector('b')?->textContent);
            if ($label === '') {
                self::fail('A dashboard tile has no visible label.');
            }
            self::assertStringStartsWith($label, self::exposedText($tile));
        }
    }

    #[Test]
    #[DataProvider('pages')]
    public function escapeClosesTheMenuOnlyFromItsButtonOrNavigation(string $html): void
    {
        $page = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        // The shell's own inline script, not a page's scripts, is what this pins.
        $scripts = array_values(array_filter(
            array_map(static fn(Element $script): string => (string) $script->textContent, iterator_to_array($page->querySelectorAll('script:not([src])'))),
            static fn(string $script): bool => str_contains($script, '.anokii-navtoggle'),
        ));
        self::assertCount(1, $scripts);
        $script = $scripts[0];

        // It listens for keys on exactly two elements, the menu button and the
        // menu navigation, and closes the open menu on an unhandled Escape.
        self::assertStringContainsString("const side = document.querySelector('.anokii-side');", $script);
        self::assertStringContainsString("const toggle = document.querySelector('.anokii-navtoggle');", $script);
        self::assertStringContainsString("const nav = side.querySelector('.anokii-nav');", $script);
        self::assertSame(2, preg_match_all('/addEventListener\(\s*[\'"`]key(?:down|up|press)/', $script));
        preg_match_all('/([\w.?]+)\.addEventListener\(\s*\'keydown\',\s*(\w+)\s*\)/', $script, $listeners, PREG_SET_ORDER);
        self::assertSame(
            [['toggle', 'closeOnEscape'], ['nav?', 'closeOnEscape']],
            array_map(static fn(array $listener): array => [$listener[1], $listener[2]], $listeners),
        );
        self::assertMatchesRegularExpression(
            "/const closeOnEscape = event => \\{\\s*if \\(event\\.defaultPrevented \\|\\| event\\.isComposing\\) return;\\s*if \\(event\\.key === 'Escape' && side\\.classList\\.contains\\('nav-open'\\)\\) \\{\\s*close\\(\\);\\s*toggle\\.focus\\(\\);/",
            $script,
        );

        // What that means for each focusable element on the page: a keydown
        // reaches those listeners only from the menu button or from inside the
        // menu navigation. The brand link, the user chip, the sidebar footer
        // and the page keep Escape and focus.
        $toggle = $page->querySelector('.anokii-side button.anokii-navtoggle');
        $nav = $page->querySelector('.anokii-side nav.anokii-nav');
        self::assertInstanceOf(Element::class, $toggle);
        self::assertInstanceOf(Element::class, $nav);
        $closesFrom = static fn(Element $element): bool => $element->isSameNode($toggle) || $nav->contains($element);

        self::assertTrue($closesFrom($toggle), 'the menu button');
        $links = $nav->querySelectorAll('a[href]');
        self::assertGreaterThan(0, count($links));
        foreach ($links as $link) {
            self::assertTrue($closesFrom($link), 'a menu link');
        }
        $others = $page->querySelectorAll('.anokii-brand, .anokii-userchip a[href], .anokii-userchip button, .host-footer button, main a[href], main button, main input, main textarea, main select');
        self::assertGreaterThan(0, count($others));
        foreach ($others as $element) {
            self::assertFalse($closesFrom($element), $element->tagName . '.' . $element->getAttribute('class') . ' keeps its Escape');
        }
    }

    #[Test]
    public function theSkipLinkStaysLegibleOnDarkAndLightChrome(): void
    {
        $shell = (string) file_get_contents(OperatorTemplates::path() . '/shell.html.twig');
        $rule = self::match($shell, '/\.anokii-skiplink\{([^}]*)\}/');
        $text = self::match($rule, '/(?<![-a-z])color:(#[0-9a-f]{6})/i');
        $fill = self::match($rule, '/background:(#[0-9a-f]{6})/i');
        $border = self::match($rule, '/border:2px solid (#[0-9a-f]{6})/i');
        self::assertMatchesRegularExpression('/\.anokii-skiplink:not\(:focus\)\{[^}]*clip:rect\(0 0 0 0\)/', $shell, 'The skip link is hidden until focused.');

        self::assertGreaterThanOrEqual(4.5, self::contrast($text, $fill));
        // It sits over the sidebar. Whatever the theme, its fill or its border
        // must stand out from that chrome by at least 3:1.
        $chrome = [
            'default sidebar' => self::match($shell, '/--anokii-shell-side-bg: var\(--color-side-bg, (#[0-9a-f]{6})\)/'),
            'example theme sidebar' => self::match(
                (string) file_get_contents(dirname(__DIR__) . '/examples/demo/assets/theme.css'),
                '/--anokii-shell-side-bg:(#[0-9a-f]{6})/',
            ),
            'light sidebar' => '#f4f3f0',
        ];
        foreach ($chrome as $name => $background) {
            self::assertGreaterThanOrEqual(3.0, max(self::contrast($fill, $background), self::contrast($border, $background)), $name);
        }
    }

    /**
     * @param array<string, mixed> $page
     * @param ?string $host a host template, registered as $template, that extends the shell
     */
    private static function render(string $template, string $active, array $page = [], ?string $host = null): string
    {
        $modules = [
            new OperatorModule('website', 'Website', 'Daily work', '/admin/anokii/website', 'Manage the public website.', self::ICON),
            new OperatorModule('members', 'Members', 'Daily work', '/admin/anokii/members', 'Manage members portal access.', self::ICON),
        ];
        $loader = $host === null ? new FilesystemLoader() : new ChainLoader([new ArrayLoader([$template => $host])]);
        $twig = new Environment($loader, ['strict_variables' => true]);
        OperatorTemplates::register($twig);

        return $twig->render($template, OperatorShell::context($modules, $active, 'Sample Operator', 'Communications', [
            'brand_title' => 'Example Nation',
            ...$page,
        ]));
    }

    /** Text an element exposes to assistive technology, skipping aria-hidden subtrees. */
    private static function exposedText(Element $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                $text .= $child->getAttribute('aria-hidden') === 'true' ? '' : ' ' . self::exposedText($child);
            } else {
                $text .= (string) $child->textContent;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        [$light, $dark] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    private static function match(string $source, string $pattern): string
    {
        if (preg_match($pattern, $source, $match) !== 1) {
            throw new \LogicException("Nothing matches {$pattern}.");
        }

        return $match[1];
    }
}
