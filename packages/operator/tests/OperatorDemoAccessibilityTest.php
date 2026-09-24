<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Demo\OperatorDemo;
use Anokii\Operator\Template\OperatorTemplates;
use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Focused WCAG 2.1 AA checks for demo pages and the shared primitives, run
 * without a JavaScript toolchain. They cover what server-rendered markup and
 * fixed colours can prove; keyboard, focus, and responsive behaviour are
 * checked by hand in a browser (see demo/README.md).
 */
final class OperatorDemoAccessibilityTest extends TestCase
{
    /** What the demo marker may declare: its height is the padding shorthand, the line box and the bottom border. */
    private const array MARKER_PROPERTIES = [
        'position', 'top', 'z-index', 'margin', 'padding', 'background', 'color',
        'border-bottom', 'font-size', 'font-weight', 'line-height',
    ];

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        foreach (['/admin/anokii', '/admin/anokii/desk', '/admin/anokii/review', '/admin/anokii/records'] as $path) {
            yield $path => [$path];
        }
    }

    #[Test]
    #[DataProvider('pages')]
    public function headingsFormOneOutlineWithoutSkippedLevels(string $path): void
    {
        $page = self::page($path);

        self::assertSame('en', $page->documentElement?->getAttribute('lang'));
        self::assertCount(1, $page->querySelectorAll('h1'), $path);
        $previous = 0;
        foreach ($page->querySelectorAll('h1, h2, h3, h4, h5, h6') as $heading) {
            $level = (int) substr($heading->tagName, 1);
            self::assertLessThanOrEqual($previous + 1, $level, "{$path}: {$heading->tagName} \"" . trim((string) $heading->textContent) . '" skips a level');
            $previous = $level;
        }
    }

    #[Test]
    #[DataProvider('pages')]
    public function everyControlHasALabelAndGroupsHaveALegend(string $path): void
    {
        $page = self::page($path);

        // A placeholder never counts as a label (DIR-A001).
        foreach ($page->querySelectorAll('input:not([type="hidden"]), select, textarea') as $control) {
            $id = (string) $control->getAttribute('id');
            $labelled = $control->closest('label') !== null
                || ($id !== '' && $page->querySelector('label[for="' . $id . '"]') !== null)
                || $control->hasAttribute('aria-label')
                || $control->hasAttribute('aria-labelledby');
            self::assertTrue($labelled, "{$path}: a {$control->tagName} has no label");
        }

        $groups = [];
        foreach ($page->querySelectorAll('input[type="radio"], input[type="checkbox"][name]') as $choice) {
            $groups[(string) $choice->getAttribute('name')][] = $choice;
        }
        foreach ($groups as $name => $choices) {
            if (count($choices) < 2 && $choices[0]->getAttribute('type') !== 'radio') {
                continue;
            }
            foreach ($choices as $choice) {
                $fieldset = $choice->closest('fieldset');
                self::assertInstanceOf(Element::class, $fieldset, "{$path}: group {$name} is not in a fieldset");
                // A fieldset's legend must be its first child.
                $legend = $fieldset->firstElementChild;
                self::assertSame('LEGEND', $legend?->tagName, "{$path}: group {$name} has no legend");
                self::assertNotSame('', trim((string) $legend->textContent));
            }
        }
    }

    #[Test]
    #[DataProvider('pages')]
    public function buttonsLinksAndImagesAreNamedAndNothingForcesTabOrder(string $path): void
    {
        $page = self::page($path);

        foreach ($page->querySelectorAll('button') as $button) {
            self::assertContains($button->getAttribute('type'), ['button', 'submit', 'reset'], $path);
            self::assertTrue(trim((string) $button->textContent) !== '' || $button->hasAttribute('aria-label'), "{$path}: a button has no name");
        }
        foreach ($page->querySelectorAll('a[href]') as $link) {
            self::assertNotSame('', trim((string) $link->textContent), "{$path}: a link has no text");
        }
        foreach ($page->querySelectorAll('img') as $image) {
            self::assertTrue($image->hasAttribute('alt'), "{$path}: an image has no alt text");
        }
        foreach ($page->querySelectorAll('[tabindex]') as $element) {
            self::assertLessThanOrEqual(0, (int) $element->getAttribute('tabindex'), "{$path}: positive tabindex");
        }
    }

    #[Test]
    #[DataProvider('pages')]
    public function idsAreUniqueAndEveryReferenceResolves(string $path): void
    {
        $page = self::page($path);

        $ids = array_map(static fn(Element $element): string => (string) $element->getAttribute('id'), iterator_to_array($page->querySelectorAll('[id]')));
        self::assertSame(array_values(array_unique($ids)), $ids, "{$path}: duplicate ids");
        foreach (['for', 'aria-labelledby', 'aria-describedby', 'aria-controls'] as $attribute) {
            foreach ($page->querySelectorAll("[{$attribute}]") as $element) {
                foreach (preg_split('/\s+/', trim((string) $element->getAttribute($attribute))) ?: [] as $reference) {
                    self::assertContains($reference, $ids, "{$path}: {$attribute}=\"{$reference}\" points nowhere");
                }
            }
        }
    }

    #[Test]
    public function scenarioPagesAnnounceStateChanges(): void
    {
        foreach (['/admin/anokii/desk', '/admin/anokii/review'] as $path) {
            $page = self::page($path);
            // Every step has an alert for its validation errors, and results
            // are summarised in a polite status region.
            foreach ($page->querySelectorAll('[data-step]') as $step) {
                if ($step->querySelector('.anokii-demo-outcomes') === null) {
                    self::assertNotNull($step->querySelector('[role="alert"]'), $path);
                }
            }
            self::assertNotNull($page->querySelector('.anokii-demo-outcomes [role="status"]'), $path);
            // Hidden steps stay out of the tab order and the accessibility tree.
            self::assertGreaterThan(0, count($page->querySelectorAll('[data-step][hidden]')), $path);
        }
    }

    #[Test]
    #[DataProvider('pages')]
    public function scrollsStopBelowTheStickyDemoMarker(string $path): void
    {
        $page = self::page($path);

        $markers = $page->querySelectorAll('[data-anokii-demo-flag]');
        self::assertCount(1, $markers, $path);
        self::assertNotNull($page->querySelector('main#anokii-main-content > .anokii-demo-flag[data-anokii-demo-flag]'), "{$path}: the marker heads the main region");

        // The marker and the reserved space are styled only in the overlay's
        // inline CSS, written in its compact form (no space before "{", px
        // lengths, a unitless line-height, width first in border-bottom). A
        // change to that form fails here loudly rather than passing wrongly.
        $css = implode("\n", array_map(static fn(Element $style): string => (string) $style->textContent, iterator_to_array($page->querySelectorAll('style'))));
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/\.anokii-demo-flag\{([^}]*)\}/', $css, $rules);
        self::assertNotSame([], $rules[1], $path);
        // Every rule that styles the marker is one the test reads. The only
        // other mention allowed is the single use of the measured-height
        // custom property, which the CSS reads but never sets.
        self::assertSame(1, substr_count($css, '--anokii-demo-flag-height'), "{$path}: the CSS only reads the measured height, once");
        self::assertSame(count($rules[1]), substr_count($css, 'anokii-demo-flag') - 1, "{$path}: every marker rule is in the compact form the test reads");
        $base = self::declarations($rules[1][0]);
        self::assertSame('sticky', $base['position'] ?? null, "{$path}: the marker stays sticky");
        self::assertSame('0', $base['top'] ?? null, "{$path}: the marker sticks to the top");
        self::assertSame(1, preg_match_all('/scroll-padding/', $css), "{$path}: one scroll-padding declaration, which nothing later overrides");

        // A scroll that brings something to the top, such as a fragment link or
        // scrollIntoView({block: 'start'}), stops at the reserved space: the
        // marker's measured height (--anokii-demo-flag-height) plus a small
        // gap. The reservation is a top-level rule, so it applies at every
        // width.
        $topLevel = (string) preg_replace('/@[a-z-]+[^{;]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '', $css);
        if (preg_match('/(?<![-\w])html\{scroll-padding-top:calc\(var\(--anokii-demo-flag-height,\s*([\d.]+)px\)\s*\+\s*([\d.]+)px\)\}/', $topLevel, $reservation) !== 1) {
            self::fail("{$path}: the scroll padding is the measured marker height plus a gap");
        }
        $fallback = (float) $reservation[1];
        $gap = (float) $reservation[2];
        self::assertGreaterThanOrEqual(4.0, $gap, "{$path}: the gap keeps content clear of the marker's edge");
        self::assertLessThanOrEqual(12.0, $gap, "{$path}: the gap stays small");

        // The script straight after the marker sets that height from the
        // marker's rendered box when it first renders, before any fragment
        // scroll, and again whenever the box changes size, as it does when the
        // text wraps or is resized.
        $flag = $markers->item(0);
        self::assertInstanceOf(Element::class, $flag);
        $script = $flag->nextElementSibling;
        self::assertSame('SCRIPT', $script?->tagName, "{$path}: the measuring script follows the marker");
        // A classic inline script runs as soon as it is parsed; a src, a type
        // (module, or anything the browser does not run) or async/defer would
        // move or skip that.
        self::assertSame([], array_map(static fn(Attr $attribute): string => $attribute->name, iterator_to_array($script->attributes)), "{$path}: the measuring script is a plain inline script");
        // The whole script is pinned, so nothing can skip the measurement.
        self::assertMatchesRegularExpression(
            '/^\s*\(\(\) => \{\s*(?:\/\/[^\n]*\n\s*)*const flag = document\.querySelector\(\'\[data-anokii-demo-flag\]\'\);\s*if \(!\(flag instanceof HTMLElement\)\) return;\s*const reserve = \(\) => \{\s*document\.documentElement\.style\.setProperty\(\'--anokii-demo-flag-height\', `\$\{Math\.ceil\(flag\.getBoundingClientRect\(\)\.height\)\}px`\);\s*\};\s*reserve\(\);\s*if \(typeof ResizeObserver === \'function\'\) new ResizeObserver\(reserve\)\.observe\(flag, \{ box: \'border-box\' \}\);\s*\}\)\(\);\s*$/',
            (string) $script->textContent,
            "{$path}: the script measures the marker's border box at once and on every resize",
        );

        // Without the script, the fallback stands in for the one-line marker
        // at default text size, worked out from the base rule and each
        // narrower-width override of it.
        foreach ($rules[1] as $rule) {
            // The height below comes from the padding shorthand, the line box
            // and the bottom border, so a marker rule may declare only these.
            $declared = array_keys(array_filter(self::declarations($rule), static fn(string $value): bool => $value !== ''));
            self::assertSame([], array_values(array_diff($declared, self::MARKER_PROPERTIES)), "{$path}: the marker declares only properties whose effect on its height the test knows");
            $marker = [...$base, ...self::declarations($rule)];
            // At every width the marker stays stuck to the top, and its
            // lengths are px (unit stripped) or a unitless line-height, the
            // only forms the height below understands.
            self::assertSame('sticky', $marker['position'] ?? null, "{$path}: the marker stays sticky at every width");
            self::assertSame('0', $marker['top'] ?? null, "{$path}: the marker sticks to the top at every width");
            self::assertMatchesRegularExpression('/^[\d.]+(?:\s+[\d.]+){0,3}$/', $marker['padding'] ?? '', "{$path}: the marker's padding is in px");
            self::assertMatchesRegularExpression('/^[\d.]+$/', $marker['font-size'] ?? '', "{$path}: the marker's font size is in px");
            self::assertMatchesRegularExpression('/^[\d.]+$/', $marker['line-height'] ?? '', "{$path}: the marker's line height is unitless");
            $padding = preg_split('/\s+/', trim($marker['padding'] ?? '0')) ?: ['0'];
            $vertical = (float) $padding[0] + (float) ($padding[2] ?? $padding[0]);
            $height = $vertical + (float) ($marker['font-size'] ?? 0) * (float) ($marker['line-height'] ?? 0) + (float) ($marker['border-bottom'] ?? 0);
            self::assertGreaterThan(30.0, $height, "{$path}: the marker's height is worked out from its own rule");
            self::assertGreaterThanOrEqual($height, $fallback, "{$path}: the fallback covers the {$height}px one-line marker");
            self::assertLessThanOrEqual($height + 1.0, $fallback, "{$path}: the fallback is the one-line marker's height");
        }
    }

    #[Test]
    public function productionTemplatesAndDemoStylesheetsCarryNoMarkerOrScrollPadding(): void
    {
        $examples = glob(dirname(__DIR__) . '/examples/demo/assets/*.css') ?: [];
        self::assertContains('theme.css', array_map('basename', $examples));
        self::assertContains('scenarios.css', array_map('basename', $examples));
        $files = [dirname(__DIR__) . '/demo/assets/primitives.css', ...$examples];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OperatorTemplates::path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        self::assertGreaterThan(3, count($files));
        // The production shell reserves nothing, and neither the demo's shared
        // stylesheet nor the example's stylesheets can resize the marker or
        // change the reserved space behind the overlay's back.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('scroll-padding', $source, basename($file));
            self::assertStringNotContainsString('anokii-demo-flag', $source, basename($file));
        }
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function colourPairs(): iterable
    {
        $demo = dirname(__DIR__) . '/demo';
        $primitives = (string) file_get_contents($demo . '/assets/primitives.css');
        $overlay = (string) file_get_contents($demo . '/templates/overlay/shell.html.twig');
        $scenarios = (string) file_get_contents(dirname(__DIR__) . '/examples/demo/assets/scenarios.css');
        $theme = (string) file_get_contents(dirname(__DIR__) . '/examples/demo/assets/theme.css');
        $token = static fn(string $name): string => self::match($theme, '/--anokii-shell-' . preg_quote($name, '/') . ':(#(?:[0-9a-f]{6}|[0-9a-f]{3})(?![0-9a-f]))/i');
        $rule = static fn(string $css, string $selector, string $property): string => self::match(
            $css,
            '/' . preg_quote($selector, '/') . '\{[^}]*?(?<![-a-z])' . preg_quote($property, '/') . ':(#(?:[0-9a-f]{6}|[0-9a-f]{3})(?![0-9a-f]))/i',
        );
        $white = '#ffffff';

        yield 'demo marker text' => [$rule($overlay, '.anokii-demo-flag', 'color'), $rule($overlay, '.anokii-demo-flag', 'background'), 4.5];
        yield 'simulation label text' => [$rule($primitives, '.anokii-demo-sim', 'color'), $rule($primitives, '.anokii-demo-sim', 'background'), 4.5];
        yield 'simulation label border' => [self::match($primitives, '/\.anokii-demo-sim\{[^}]*border:1px dashed (#(?:[0-9a-f]{6}|[0-9a-f]{3})(?![0-9a-f]))/i'), $rule($primitives, '.anokii-demo-sim', 'background'), 3.0];
        yield 'done outcome' => [$rule($primitives, '[data-state="done"] .anokii-demo-outcome-state', 'color'), $rule($primitives, '[data-state="done"] .anokii-demo-outcome-state', 'background'), 4.5];
        yield 'failed outcome' => [$rule($primitives, '[data-state="failed"] .anokii-demo-outcome-state', 'color'), $rule($primitives, '[data-state="failed"] .anokii-demo-outcome-state', 'background'), 4.5];
        yield 'retry button' => [$rule($primitives, '.anokii-demo-retry', 'color'), $rule($primitives, '.anokii-demo-retry', 'background'), 4.5];
        yield 'primitive focus ring' => [self::match($primitives, '/:focus-visible\{outline:3px solid (#(?:[0-9a-f]{6}|[0-9a-f]{3})(?![0-9a-f]))/i'), $white, 3.0];
        yield 'scenario error text' => [$rule($scenarios, '.scenario-error', 'color'), $white, 4.5];
        yield 'scenario focus ring' => [self::match($scenarios, '/\.scenario :focus-visible\{outline:3px solid (#(?:[0-9a-f]{6}|[0-9a-f]{3})(?![0-9a-f]))/i'), $white, 3.0];
        yield 'example theme body text' => [$token('ink'), $token('bg'), 4.5];
        yield 'example theme muted text on the page' => [$token('muted'), $token('bg'), 4.5];
        yield 'example theme muted text on cards' => [$token('muted'), $white, 4.5];
        yield 'example theme sidebar text' => [$token('side-muted'), $token('side-bg'), 4.5];
        yield 'example theme accent buttons and current step' => [$token('accent-ink'), $token('accent'), 4.5];
        yield 'example theme accent on its soft tint' => [$token('accent'), $token('accent-soft'), 4.5];
        yield 'example theme accent border on cards' => [$token('accent'), $white, 3.0];
    }

    #[Test]
    #[DataProvider('colourPairs')]
    public function fixedColoursMeetContrastMinimums(string $foreground, string $background, float $minimum): void
    {
        self::assertGreaterThanOrEqual($minimum, self::contrast($foreground, $background), "{$foreground} on {$background}");
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
            throw new \LogicException("No colour matches {$pattern}.");
        }

        $hex = strtolower(ltrim($match[1], '#'));

        return '#' . (strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
    }

    /** @return array<string, string> property => value, lengths without their px unit */
    private static function declarations(string $rule): array
    {
        $declarations = [];
        foreach (explode(';', $rule) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $value = trim($value);
            if (trim($property) === 'border-bottom') {
                $value = self::number($value, '/^([\d.]+)px\b/');
            }
            $declarations[trim($property)] = trim((string) preg_replace('/(\d)px\b/', '$1', $value));
        }

        return $declarations;
    }

    private static function number(string $source, string $pattern): string
    {
        if (preg_match($pattern, $source, $match) !== 1) {
            throw new \LogicException("Nothing matches {$pattern}.");
        }

        return $match[1];
    }

    private static function page(string $path): HTMLDocument
    {
        $demo = OperatorDemo::fromFixtureFile(dirname(__DIR__) . '/examples/demo/fixture.php');
        $response = $demo->handle(Request::create($path));
        self::assertSame(200, $response->getStatusCode(), $path);

        return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    }
}
