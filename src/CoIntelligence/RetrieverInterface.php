<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * Returns the passages most relevant to a question, asked from the vantage of a
 * given community, best first.
 *
 * Keyword scoring over the relational graph backs this today
 * ({@see GraphRetriever}); a vector index over the same doc_chunk rows can
 * implement this interface later without the chat layer changing.
 *
 * @api
 */
interface RetrieverInterface
{
    /**
     * @param string $community vantage community slug (e.g. "sagamok"); empty or
     *                          unknown means treaty-wide / no vantage, which keeps
     *                          general content answerable.
     *
     * @return list<Passage> up to $k passages, highest score first
     */
    public function retrieve(string $query, string $community, int $k = 6): array;
}
