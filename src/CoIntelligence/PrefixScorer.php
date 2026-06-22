<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * Word-prefix keyword scorer for single-vantage sovereign installs (e.g. the
 * FNPI workspace), ported verbatim from that workspace's own retriever so the
 * package {@see GraphRetriever} returns byte-identical passages once the install
 * opts in with this scorer (in flat mode, with its own relevance gate).
 *
 * It differs from the default {@see GraphScorer} in three ways the sovereign
 * corpus relies on:
 *   1. a term matches as a WORD PREFIX (" art" matches "artist") via
 *      substr_count, not as a whole token;
 *   2. tokens shorter than 3 characters are dropped (not 2);
 *   3. it carries its own stopword list.
 * The field weights (title x3, heading x2, text x1) and the logarithmic damping
 * match the default, so only term matching itself changes.
 *
 * @api
 */
final class PrefixScorer implements ScorerInterface
{
    /** @var list<string> Common words that carry no retrieval signal. */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'for', 'is', 'are',
        'was', 'were', 'be', 'been', 'with', 'as', 'by', 'at', 'from', 'that', 'this',
        'it', 'its', 'we', 'our', 'you', 'your', 'i', 'me', 'my', 'they', 'them', 'their',
        'what', 'which', 'who', 'how', 'when', 'where', 'why', 'do', 'does', 'did', 'can',
        'could', 'would', 'should', 'will', 'about', 'into', 'over', 'than', 'then', 'so',
        'if', 'not', 'no', 'yes', 'us', 'has', 'have', 'had', 'all', 'any', 'each', 'more',
    ];

    public function terms(string $query): array
    {
        return array_keys($this->tokenize($query));
    }

    public function score(array $terms, string $title, string $heading, string $text): float
    {
        $title = ' ' . $this->lower($title) . ' ';
        $heading = ' ' . $this->lower($heading) . ' ';
        $text = ' ' . $this->lower($text) . ' ';

        $score = 0.0;
        foreach (array_unique($terms) as $term) {
            $needle = ' ' . $term;
            $inTitle = substr_count($title, $needle);
            $inHeading = substr_count($heading, $needle);
            $inText = substr_count($text, $needle);
            if ($inTitle === 0 && $inHeading === 0 && $inText === 0) {
                continue;
            }
            // Logarithmic damping so a term repeated many times does not dominate.
            $score += 3.0 * $this->damp($inTitle)
                + 2.0 * $this->damp($inHeading)
                + 1.0 * $this->damp($inText);
        }

        return $score;
    }

    private function damp(int $count): float
    {
        return $count > 0 ? 1.0 + log((float) $count) : 0.0;
    }

    /**
     * @return array<string, int> distinct query terms => 1
     */
    private function tokenize(string $query): array
    {
        $query = $this->lower($query);
        $words = preg_split('/[^a-z0-9]+/', $query) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $terms[$word] = 1;
        }

        return $terms;
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value);
    }
}
