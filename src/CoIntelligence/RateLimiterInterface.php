<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

/**
 * A per-key request limiter for the public chat endpoint.
 *
 * @api
 */
interface RateLimiterInterface
{
    /**
     * Register a hit for the key and return the number of seconds the caller
     * must wait when the limit is exceeded, or null when the request may proceed.
     */
    public function retryAfter(string $key): ?int;
}
