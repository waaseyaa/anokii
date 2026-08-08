<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use Anokii\Workspace\Analytics\AnalyticsCollector;
use Anokii\Workspace\Analytics\AnalyticsEndpoint;
use Anokii\Workspace\Analytics\AnalyticsSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DBALDatabase;

final class AnalyticsPrivacyTest extends TestCase
{
    #[Test]
    public function weak_privacy_secrets_fail_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnalyticsCollector(DBALDatabase::createSqlite(':memory:'), 'too-short');
    }

    #[Test]
    public function missing_privacy_configuration_disables_only_the_ingest_endpoint(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $endpoint = new AnalyticsEndpoint(null, new DatabaseRateLimiter($db));

        self::assertSame(503, $endpoint->handle(Request::create('/api/collect', 'POST'))->getStatusCode());
    }

    #[Test]
    public function endpoint_rejects_oversized_or_non_json_beacons_without_storing_them(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new AnalyticsSchema($db)->ensure();
        $endpoint = new AnalyticsEndpoint(
            new AnalyticsCollector($db, str_repeat('s', 32)),
            new DatabaseRateLimiter($db),
        );

        $oversized = Request::create('/api/collect', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '192.0.2.1',
        ], json_encode(['t' => 'pageview', 'v' => 'v', 'p' => '/' . str_repeat('x', 3000)], JSON_THROW_ON_ERROR));
        $plain = Request::create('/api/collect', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'REMOTE_ADDR' => '192.0.2.1',
        ], json_encode(['t' => 'pageview', 'v' => 'v', 'p' => '/'], JSON_THROW_ON_ERROR));

        self::assertSame(204, $endpoint->handle($oversized)->getStatusCode());
        self::assertSame(204, $endpoint->handle($plain)->getStatusCode());
        self::assertSame(0, iterator_count($db->select(AnalyticsSchema::TABLE)->execute()));
    }

    #[Test]
    public function endpoint_rate_limits_by_symfony_resolved_client_address(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new AnalyticsSchema($db)->ensure();
        $endpoint = new AnalyticsEndpoint(
            new AnalyticsCollector($db, str_repeat('s', 32)),
            new DatabaseRateLimiter($db),
            maxRequests: 1,
        );
        $request = Request::create('/api/collect', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '192.0.2.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ], json_encode(['t' => 'pageview', 'v' => 'v', 'p' => '/'], JSON_THROW_ON_ERROR));

        self::assertSame(204, $endpoint->handle($request)->getStatusCode());
        self::assertSame(429, $endpoint->handle($request)->getStatusCode());
    }
}
