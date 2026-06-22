<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * The default Anokii keyword scorer: whole-token weighted term frequency
 * (title x3, heading x2, text x1) with logarithmic damping, over a min-2
 * tokenizer and the graph stopword list.
 *
 * This is the scoring {@see GraphRetriever} has always used, extracted verbatim
 * into the pluggable {@see ScorerInterface} seam so it stays the default and the
 * graph installs (rhtcircle, oiatc) remain byte-identical. A term matches only a
 * whole token: "art" does not match "artist". Contrast {@see PrefixScorer}.
 *
 * @api
 */
final class GraphScorer implements ScorerInterface
{
    private const TITLE_WEIGHT = 3;
    private const HEADING_WEIGHT = 2;
    private const TEXT_WEIGHT = 1;

    /** @var list<string> */
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'does', 'for', 'from',
        'how', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'per', 'so', 'the', 'to',
        'we', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'you', 'your', 'about',
        'get', 'got', 'this', 'that', 'there', 'their', 'them', 'they', 'will', 'would', 'should',
        'if', 'but', 'not', 'no', 'yes', 'any', 'all', 'some', 'our', 'us', 'am',
    ];

    public function terms(string $query): array
    {
        return $this->tokenize($query);
    }

    public function score(array $terms, string $title, string $heading, string $text): float
    {
        $titleTf = $this->termCounts($this->tokenize($title));
        $headingTf = $this->termCounts($this->tokenize($heading));
        $textTf = $this->termCounts($this->tokenize($text));

        $score = 0.0;
        foreach (array_unique($terms) as $term) {
            $hits = ($titleTf[$term] ?? 0) * self::TITLE_WEIGHT
                + ($headingTf[$term] ?? 0) * self::HEADING_WEIGHT
                + ($textTf[$term] ?? 0) * self::TEXT_WEIGHT;
            if ($hits > 0) {
                $score += 1.0 + log((float) $hits);
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
        $tokens = [];
        foreach (explode(' ', $text) as $token) {
            if (strlen($token) < 2 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<string, int>
     */
    private function termCounts(array $tokens): array
    {
        $counts = [];
        foreach ($tokens as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }
}
