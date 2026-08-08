<?php

declare(strict_types=1);

namespace Anokii\Tests\Auth;

use Anokii\Auth\LoginThrottle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DBALDatabase;

final class LoginThrottleTest extends TestCase
{
    #[Test]
    public function repeated_failures_block_and_success_clears_only_the_email_bucket(): void
    {
        $throttle = new LoginThrottle(new DatabaseRateLimiter(DBALDatabase::createSqlite(':memory:')), maxAttempts: 2);
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '192.0.2.10']);

        self::assertFalse($throttle->isBlocked($request, 'Member@Example.test'));
        $throttle->recordFailure($request, 'Member@Example.test');
        self::assertFalse($throttle->isBlocked($request, 'member@example.test'));
        $throttle->recordFailure($request, 'member@example.test');
        self::assertTrue($throttle->isBlocked($request, 'member@example.test'));

        $throttle->recordSuccess('MEMBER@example.test');
        self::assertFalse($throttle->isBlocked($request, 'member@example.test'));
    }

    #[Test]
    public function untrusted_forwarding_headers_do_not_rotate_the_client_bucket(): void
    {
        $throttle = new LoginThrottle(new DatabaseRateLimiter(DBALDatabase::createSqlite(':memory:')), maxAttempts: 1);
        $first = Request::create('/login', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ]);
        $second = Request::create('/login', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        $throttle->recordFailure($first, 'first@example.test');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $throttle->recordFailure($first, 'other' . $attempt . '@example.test');
        }

        self::assertTrue($throttle->isBlocked($second, 'fresh@example.test'));
    }
}
