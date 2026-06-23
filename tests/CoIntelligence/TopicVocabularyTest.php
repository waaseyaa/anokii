<?php

declare(strict_types=1);

namespace Anokii\Tests\CoIntelligence;

use Anokii\CoIntelligence\TopicVocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared topic vocabulary behind {@see \Anokii\CoIntelligence\GraphRetriever}
 * and {@see \Anokii\Controller\PublicChatController}.
 *
 * The commerce and everyday-life topics were appended after the social-service
 * topics so a keyword tie resolves to the earlier-declared topic: the existing
 * inference must be unchanged, and a new topic wins only when its own keywords
 * give it strictly more distinct hits. This pins both halves so neither drifts.
 */
#[CoversClass(TopicVocabulary::class)]
final class TopicVocabularyTest extends TestCase
{
    #[Test]
    public function theSixCommerceTopicsAreDefined(): void
    {
        $all = new TopicVocabulary()->all();
        foreach (['groceries-and-food', 'banking', 'dining', 'retail-and-hardware', 'everyday-services', 'government-services'] as $slug) {
            self::assertArrayHasKey($slug, $all, $slug);
            self::assertNotSame('', $all[$slug]['name']);
            self::assertNotEmpty($all[$slug]['keywords']);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inferenceCases(): iterable
    {
        // New commerce/everyday topics win for the corridor questions.
        yield 'bakery -> groceries' => ['is there a bakery in massey', 'groceries-and-food'];
        yield 'grocery store -> groceries' => ['grocery store near massey', 'groceries-and-food'];
        yield 'bank -> banking' => ['where is the nearest bank', 'banking'];
        yield 'atm -> banking' => ['is there an atm in town', 'banking'];
        yield 'health card -> government' => ['where do i renew my health card', 'government-services'];
        yield 'service canada -> government' => ['nearest service canada office', 'government-services'];
        yield 'restaurant -> dining' => ['is there a restaurant or cafe', 'dining'];
        yield 'hardware -> retail' => ['where is the hardware store', 'retail-and-hardware'];
        yield 'pharmacy -> everyday' => ['is there a pharmacy or post office', 'everyday-services'];

        // Existing inference is unchanged (regression).
        yield 'housing unchanged' => ['i need housing help', 'housing'];
        yield 'mental health unchanged' => ['mental health crisis support', 'mental-health-addictions'];
        yield 'food bank unchanged' => ['food bank in massey', 'food-security'];
        yield 'bare groceries stays food-security' => ['where can i buy groceries', 'food-security'];
        yield 'income support unchanged' => ['ontario works income support', 'income-support'];
        yield 'membership unchanged' => ['status card membership', 'membership-status'];
        yield 'finance keeps banking tie' => ['per capita deposit banking', 'finance'];
        yield 'energy unchanged' => ['massey solar project', 'energy-solar'];
    }

    #[Test]
    #[DataProvider('inferenceCases')]
    public function inferResolvesToTheExpectedTopic(string $question, string $expected): void
    {
        self::assertSame($expected, new TopicVocabulary()->infer($question));
    }
}
