<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * The keyword-scoring strategy behind {@see GraphRetriever}: how a query is
 * tokenized into terms, and how a candidate chunk is scored against them.
 *
 * The retriever owns the graph scope resolution, the ranking, and the relevance
 * gate; the scorer owns only term extraction and per-chunk keyword scoring. That
 * split lets a single-vantage sovereign install swap in a different keyword
 * model (e.g. {@see PrefixScorer}'s word-prefix matching) without touching the
 * retriever, while graph installs keep the default {@see GraphScorer}.
 *
 * @api
 */
interface ScorerInterface
{
    /**
     * Tokenize a query into the terms to score against. The list MAY contain
     * repeats; the scorer is responsible for any de-duplication when it scores.
     * An empty list means the query carried no signal and the retriever returns
     * nothing.
     *
     * @return list<string>
     */
    public function terms(string $query): array;

    /**
     * Keyword score for one chunk against the query terms. Higher is more
     * relevant; 0.0 means no overlap, and the retriever drops the chunk before
     * scope resolution.
     *
     * @param list<string> $terms
     */
    public function score(array $terms, string $title, string $heading, string $text): float;
}
