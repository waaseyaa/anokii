<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Demo\OperatorDemo;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two fictional scenarios in examples/demo: a communications desk with
 * public and members-only flows, and a needs-review workflow. Browser
 * behaviour is checked by hand (see demo/README.md); these tests pin what the
 * server renders and which primitives each scenario uses.
 */
final class OperatorDemoScenariosTest extends TestCase
{
    private const array PUBLIC_OR_SOCIAL = ['website', 'newsletter', 'social'];

    #[Test]
    public function membersOnlyUpdatesNeverOfferAPublicOrSocialChannel(): void
    {
        $page = self::page('/admin/anokii/desk');

        $members = self::channels($page, 'members');
        self::assertSame(['portal', 'members-email'], $members);
        self::assertSame([], array_intersect($members, self::PUBLIC_OR_SOCIAL));
        self::assertSame(self::PUBLIC_OR_SOCIAL, self::channels($page, 'public'));

        // Both audience groups start hidden, so no channel is offered before
        // an audience is chosen.
        foreach ($page->querySelectorAll('fieldset[data-audience]') as $group) {
            self::assertTrue($group->hasAttribute('hidden'));
        }
        // Each audience has a channel set to fail first, so both flows can show retry.
        $root = $page->querySelector('[data-desk]');
        self::assertInstanceOf(Element::class, $root);
        $failing = explode(' ', (string) $root->getAttribute('data-fail-first'));
        self::assertNotSame([], array_intersect($failing, $members));
        self::assertNotSame([], array_intersect($failing, self::PUBLIC_OR_SOCIAL));
    }

    #[Test]
    public function theDeskRequiresASourceAudienceChannelsAndExplicitApproval(): void
    {
        $page = self::page('/admin/anokii/desk');

        self::assertCount(4, $page->querySelectorAll('[data-step]'));
        self::assertNotNull($page->querySelector('input[type="file"][data-file]'));
        self::assertCount(2, $page->querySelectorAll('input[type="radio"][name="desk-sample"]'));
        self::assertCount(2, $page->querySelectorAll('input[type="radio"][name="desk-audience"]'));
        self::assertNull($page->querySelector('input[name="desk-audience"][checked]'), 'No audience is chosen for the operator.');
        self::assertNotNull($page->querySelector('input[type="checkbox"][data-approve]:not([checked])'));
        // One sample draft per channel, each marked as a sample.
        foreach ([...self::PUBLIC_OR_SOCIAL, 'portal', 'members-email'] as $channel) {
            $draft = $page->querySelector("[data-draft=\"{$channel}\"]");
            self::assertInstanceOf(Element::class, $draft, $channel);
            self::assertStringContainsString('Sample draft: not sent', (string) $draft->textContent);
        }
        $publish = $page->querySelector('[data-publish]');
        self::assertInstanceOf(Element::class, $publish);
        self::assertSame('Publish (simulated)', trim((string) $publish->textContent));
    }

    #[Test]
    public function reviewDecisionsDefaultToLaterAndSendingBackNeedsANote(): void
    {
        $page = self::page('/admin/anokii/review');

        $items = $page->querySelectorAll('[data-item]');
        self::assertCount(3, $items);
        foreach ($items as $item) {
            $id = (string) $item->getAttribute('data-item');
            self::assertSame(['approve', 'send-back', 'later'], array_map(
                static fn(Element $radio): string => (string) $radio->getAttribute('value'),
                iterator_to_array($item->querySelectorAll("input[type=\"radio\"][name=\"review-{$id}\"]")),
            ));
            self::assertNotNull($item->querySelector('input[value="later"][checked]'), $id);
            $legend = $item->querySelector('legend');
            self::assertInstanceOf(Element::class, $legend);
            self::assertStringContainsString((string) $item->getAttribute('data-title'), (string) $legend->textContent);
            $note = $item->querySelector('[data-note]');
            self::assertInstanceOf(Element::class, $note);
            self::assertTrue($note->hasAttribute('hidden'));
            self::assertNotNull($note->querySelector('textarea[aria-required="true"]'));
            self::assertStringContainsString('Sample item', (string) $item->textContent);
        }
    }

    #[Test]
    public function everySharedPrimitiveIsUsedByBothScenarios(): void
    {
        $examples = dirname(__DIR__) . '/examples/demo/assets';
        foreach (['/admin/anokii/desk' => 'desk.js', '/admin/anokii/review' => 'review.js'] as $path => $script) {
            $page = self::page($path);
            self::assertNotNull($page->querySelector('ol.anokii-demo-steps[aria-label] > li[aria-current="step"]'), $path);
            self::assertNotNull($page->querySelector('.anokii-demo-outcomes > ul.anokii-demo-outcome-list[aria-label] + p[role="status"]'), $path);
            self::assertNotNull($page->querySelector('.anokii-demo-sim'), $path);

            $source = (string) file_get_contents($examples . '/' . $script);
            self::assertStringContainsString("import { showOutcomes, showStep } from '/anokii-demo/primitives.js';", $source, $script);
        }

        // The drop zone serves one scenario, so it stays in that host page.
        $primitives = (string) file_get_contents(OperatorDemo::templatesPath() . '/primitives.html.twig')
            . (string) file_get_contents(OperatorDemo::assetsPath() . '/primitives.js');
        self::assertStringNotContainsStringIgnoringCase('drop', $primitives);
    }

    /** @return list<string> */
    private static function channels(HTMLDocument $page, string $audience): array
    {
        return array_values(array_map(
            static fn(Element $box): string => (string) $box->getAttribute('value'),
            iterator_to_array($page->querySelectorAll("fieldset[data-audience=\"{$audience}\"] input[type=\"checkbox\"]")),
        ));
    }

    private static function page(string $path): HTMLDocument
    {
        $demo = OperatorDemo::fromFixtureFile(dirname(__DIR__) . '/examples/demo/fixture.php');

        return HTMLDocument::createFromString((string) $demo->handle(Request::create($path))->getContent(), LIBXML_NOERROR);
    }
}
