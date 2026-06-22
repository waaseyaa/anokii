<?php

declare(strict_types=1);

namespace Anokii\Tests\CoIntelligence;

use Anokii\CoIntelligence\GraphScorer;
use Anokii\CoIntelligence\PrefixScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two keyword scorers behind {@see \Anokii\CoIntelligence\GraphRetriever}.
 *
 * {@see GraphScorer} is the default and must keep its whole-token behaviour
 * (the graph installs, rhtcircle/oiatc, depend on it byte-for-byte).
 * {@see PrefixScorer} is the sovereign opt-in and must keep its word-prefix
 * behaviour (the FNPI workspace depends on it byte-for-byte). The two differ on
 * purpose; this pins both so neither drifts.
 */
#[CoversClass(GraphScorer::class)]
#[CoversClass(PrefixScorer::class)]
final class ScorerTest extends TestCase
{
    #[Test]
    public function graphScorerMatchesWholeTokensOnly(): void
    {
        $s = new GraphScorer();
        // "art" is a whole token in the text => one body hit, damped 1+ln(1)=1.0.
        self::assertSame(1.0, $s->score(['art'], '', '', 'the art show'));
        // "art" is NOT a whole token in "artist" => no match.
        self::assertSame(0.0, $s->score(['art'], '', '', 'the artist show'));
    }

    #[Test]
    public function prefixScorerMatchesWordPrefixes(): void
    {
        $s = new PrefixScorer();
        // "art" is a word prefix of "artist" => one body hit, damped 1.0.
        self::assertSame(1.0, $s->score(['art'], '', '', 'the artist show'));
        // ...and matches every word starting "art" (artist, artisan) => two hits.
        self::assertSame(1.0 + log(2.0), $s->score(['art'], '', '', 'the artist and the artisan'));
        // A mid-word occurrence is not a prefix: "part" does not contain " art".
        self::assertSame(0.0, $s->score(['art'], '', '', 'apart and departed'));
    }

    #[Test]
    public function bothApplyTitleHeadingTextWeights(): void
    {
        // One clean hit in each of title/heading/text, weighted 3/2/1. The two
        // scorers damp differently and so diverge here (this is intentional):
        //  - GraphScorer damps the SUMMED weighted hits once: 1 + ln(3+2+1).
        //  - PrefixScorer damps per field then sums: 3*1 + 2*1 + 1*1 = 6.
        self::assertSame(1.0 + log(6.0), new GraphScorer()->score(['solar'], 'solar', 'solar', 'solar'));
        self::assertSame(6.0, new PrefixScorer()->score(['solar'], 'solar', 'solar', 'solar'));
    }

    #[Test]
    public function graphScorerTokenizerDropsShortAndStopwords(): void
    {
        $s = new GraphScorer();
        // Drops stopwords (the, of) and keeps 2+ char tokens (min length 2).
        self::assertSame(['treaty', 'settlement'], $s->terms('the treaty of settlement'));
        self::assertSame(['ok'], $s->terms('a I ok'));
    }

    #[Test]
    public function prefixScorerTokenizerDropsShortAndStopwords(): void
    {
        $s = new PrefixScorer();
        // Drops its own stopwords and tokens shorter than 3 chars; distinct terms.
        self::assertSame(['treaty', 'settlement'], $s->terms('the treaty of settlement'));
        self::assertSame([], $s->terms('a I ok')); // "ok" is 2 chars => dropped
        self::assertSame(['art'], $s->terms('art art art')); // de-duplicated
    }

    #[Test]
    public function emptyQueryYieldsNoTerms(): void
    {
        self::assertSame([], new GraphScorer()->terms('!!! ... ???'));
        self::assertSame([], new PrefixScorer()->terms('!!! ... ???'));
    }
}
