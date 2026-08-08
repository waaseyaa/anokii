<?php

declare(strict_types=1);

namespace Anokii\Workspace\Analytics;

use Anokii\Support\Values;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Auth\DatabaseRateLimiter;

/** Bounded, rate-limited HTTP boundary for the public cookieless beacon. @api */
final readonly class AnalyticsEndpoint
{
    private const MAX_BODY_BYTES = 2048;

    public function __construct(
        private ?AnalyticsCollector $collector,
        private DatabaseRateLimiter $limiter,
        private int $maxRequests = 120,
        private int $windowSeconds = 60,
    ) {
        if ($this->maxRequests < 1 || $this->windowSeconds < 1) {
            throw new \InvalidArgumentException('Analytics endpoint limits must be positive.');
        }
    }

    public function handle(Request $request): Response
    {
        if ($this->collector === null) {
            return new Response('', 503, ['Retry-After' => '300']);
        }
        $key = 'anokii-analytics:' . hash('sha256', (string) ($request->getClientIp() ?? 'unknown'));
        if ($this->limiter->tooManyAttempts($key, $this->maxRequests)) {
            return new Response('', 429, ['Retry-After' => (string) $this->windowSeconds]);
        }
        $this->limiter->hit($key, $this->windowSeconds);

        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES || $request->getContentTypeFormat() !== 'json') {
            return new Response('', 204);
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $this->collector->record(Values::map($data), $request->getClientIp(), $request->headers->get('User-Agent'));
        }

        return new Response('', 204);
    }
}
