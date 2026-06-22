<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * One retrieved passage: a doc_chunk plus the score the retriever gave it and,
 * for the graph retriever, the relationship/location of its source entity to the
 * vantage community (e.g. "Sagamok", "Espanola (region)", "Massey Solar (shared
 * project)"). Plain value object so retrieval can be tested and so the answer
 * layer has a stable shape regardless of which retriever produced it.
 *
 * @api
 */
final readonly class Passage
{
    public function __construct(
        public string $sourceUrl,
        public string $title,
        public string $heading,
        public string $text,
        public float $score,
        public string $relationship = '',
    ) {}
}
