<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Compatibility adapter over the framework's atomic persistent limiter.
 *
 * @api
 */
final class SqliteRateLimiter implements RateLimiterInterface
{
    private readonly DatabaseRateLimiter $limiter;

    public function __construct(
        DatabaseInterface $db,
        private readonly int $maxRequests = 12,
        private readonly int $windowSeconds = 60,
    ) {
        $this->limiter = new DatabaseRateLimiter($db);
    }

    public function retryAfter(string $key): ?int
    {
        $client = 'anokii-chat:' . hash('sha256', $key);
        if ($this->limiter->tooManyAttempts($client, $this->maxRequests)) {
            return $this->windowSeconds;
        }
        $this->limiter->hit($client, $this->windowSeconds);

        return null;
    }
}
