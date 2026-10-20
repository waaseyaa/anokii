<?php

declare(strict_types=1);

namespace Anokii\Auth;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\DatabaseRateLimiter;

/** Durable, hashed login throttling over framework-owned atomic buckets. @api */
final readonly class LoginThrottle
{
    public function __construct(
        private DatabaseRateLimiter $limiter,
        private int $maxAttempts = 5,
        private int $decaySeconds = 15 * 60,
    ) {
        if ($this->maxAttempts < 1 || $this->decaySeconds < 1) {
            throw new \InvalidArgumentException('Login throttle limits must be positive.');
        }
    }

    public function isBlocked(Request $request, string $email): bool
    {
        return $this->limiter->tooManyAttempts($this->emailKey($email), $this->maxAttempts)
            || $this->limiter->tooManyAttempts($this->clientKey($request), $this->maxAttempts * 4);
    }

    public function recordFailure(Request $request, string $email): void
    {
        $this->limiter->hit($this->emailKey($email), $this->decaySeconds);
        $this->limiter->hit($this->clientKey($request), $this->decaySeconds);
    }

    public function recordSuccess(string $email): void
    {
        $this->limiter->clear($this->emailKey($email));
    }

    private function emailKey(string $email): string
    {
        return 'anokii-login-email:' . hash('sha256', strtolower(trim($email)));
    }

    private function clientKey(Request $request): string
    {
        // Symfony consults forwarding headers only for explicitly trusted
        // proxies. Do not read CF/X-Forwarded-For directly here.
        return 'anokii-login-client:' . hash('sha256', (string) ($request->getClientIp() ?? 'unknown'));
    }
}
