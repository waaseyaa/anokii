<?php

declare(strict_types=1);

namespace Anokii\Tests\CoIntelligence;

use Anokii\CoIntelligence\ChatQueryLogSchema;
use Anokii\CoIntelligence\SqliteChatQueryLog;
use Anokii\CoIntelligence\SqliteRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;

final class PrivacyBoundaryTest extends TestCase
{
    #[Test]
    public function raw_questions_are_never_persisted_and_legacy_rows_are_redacted(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $schema = new ChatQueryLogSchema($db);
        $schema->ensure();
        $db->insert(ChatQueryLogSchema::TABLE)->values([
            'created_at' => '2026-08-08 12:00:00',
            'community' => 'example',
            'question' => 'My name is Alice and my health card is 1234',
            'outcome' => 'no_match',
        ])->execute();

        $schema->ensure();
        new SqliteChatQueryLog($db)->record(
            'example',
            'Please call me at 555-0100',
            'answered',
            'health',
            ['https://example.test/service'],
        );

        $rows = iterator_to_array($db->select(ChatQueryLogSchema::TABLE)->execute());
        self::assertCount(2, $rows);
        self::assertSame(['[redacted]', '[redacted]'], array_column($rows, 'question'));
        self::assertStringNotContainsString('Alice', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('555-0100', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function chat_rate_limit_uses_the_framework_atomic_persistent_bucket(): void
    {
        $limiter = new SqliteRateLimiter(DBALDatabase::createSqlite(':memory:'), maxRequests: 2, windowSeconds: 60);

        self::assertNull($limiter->retryAfter('192.0.2.1'));
        self::assertNull($limiter->retryAfter('192.0.2.1'));
        self::assertSame(60, $limiter->retryAfter('192.0.2.1'));
    }
}
