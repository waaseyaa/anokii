<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * Records one anonymous Co-Intelligence query for content-gap mining.
 *
 * Strictly OCAP-aligned and anonymous: implementations must store only the
 * question content and its outcome, never an IP, session/visitor id, account, or
 * anything that links a question to a person. This is the "what are people asking
 * that we cannot answer" loop, with no personal data behind it.
 *
 * @api
 */
interface ChatQueryLogInterface
{
    /**
     * @param string       $community vantage community slug (empty for treaty-wide)
     * @param string       $question  the question text (content only)
     * @param string       $outcome   answered | refused | no_match | error | unavailable
     * @param string|null  $topic     inferred topic slug, or null when none matched
     * @param list<string> $sources   cited source URLs (empty when none)
     */
    public function record(string $community, string $question, string $outcome, ?string $topic, array $sources): void;
}
