<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * Records one anonymous Co-Intelligence query for content-gap mining.
 *
 * Strictly OCAP-aligned and anonymous: implementations may use the question to
 * derive non-identifying classifications but must never persist or log the raw
 * prompt, an IP, session/visitor id, account, or anything linking it to a person.
 *
 * @api
 */
interface ChatQueryLogInterface
{
    /**
     * @param string       $community vantage community slug (empty for treaty-wide)
     * @param string       $question  transient input; implementations must not persist it
     * @param string       $outcome   answered | refused | no_match | error | unavailable
     * @param string|null  $topic     inferred topic slug, or null when none matched
     * @param list<string> $sources   cited source URLs (empty when none)
     */
    public function record(string $community, string $question, string $outcome, ?string $topic, array $sources): void;
}
