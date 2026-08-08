<?php

declare(strict_types=1);

namespace Anokii\Tests\Auth;

use Anokii\Auth\SetupTokenRepository;
use Anokii\Auth\SetupTokenSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;

/**
 * One-time set-password tokens for the workspace admin tier: mint issues a fresh
 * single-use token, a valid token resolves to its email, consuming it (or
 * re-minting) invalidates it. Only hashes are stored.
 */
#[CoversClass(SetupTokenRepository::class)]
#[CoversClass(SetupTokenSchema::class)]
final class SetupTokenRepositoryTest extends TestCase
{
    private function repo(?\Closure $now = null): SetupTokenRepository
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new SetupTokenSchema($db)->ensure();

        return new SetupTokenRepository($db, now: $now);
    }

    #[Test]
    public function a_minted_token_resolves_to_its_email_then_is_single_use(): void
    {
        $repo = $this->repo();
        $token = $repo->mint('admin@example.test');

        self::assertNotSame('', $token);
        self::assertSame('admin@example.test', $repo->emailForToken($token));

        self::assertSame('admin@example.test', $repo->consume($token));
        self::assertNull($repo->emailForToken($token), 'A consumed token is no longer valid.');
        self::assertNull($repo->consume($token), 'A consumed token cannot be consumed again.');
    }

    #[Test]
    public function re_minting_invalidates_the_prior_unused_token(): void
    {
        $repo = $this->repo();
        $first = $repo->mint('a@example.test');
        $second = $repo->mint('a@example.test');

        self::assertNull($repo->emailForToken($first), 'Re-minting invalidates the prior token.');
        self::assertSame('a@example.test', $repo->emailForToken($second));
    }

    #[Test]
    public function re_minting_invalidates_legacy_mixed_case_email_rows(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new SetupTokenSchema($db)->ensure();
        $legacy = 'legacy-token';
        $db->insert(SetupTokenSchema::TABLE)->values([
            'email' => 'Alice@Example.test',
            'token_hash' => hash('sha256', $legacy),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'used_at' => null,
        ])->execute();
        $repo = new SetupTokenRepository($db);

        $repo->mint('alice@example.test');

        self::assertNull($repo->emailForToken($legacy));
    }

    #[Test]
    public function unknown_and_empty_tokens_resolve_to_null(): void
    {
        $repo = $this->repo();
        self::assertNull($repo->emailForToken(''));
        self::assertNull($repo->emailForToken('not-a-real-token'));
    }

    #[Test]
    public function token_expires_after_seventy_two_hours(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC'));
        $current = $issuedAt;
        $repo = $this->repo(static function () use (&$current): \DateTimeImmutable {
            return $current;
        });
        $token = $repo->mint('admin@example.test');

        $current = $issuedAt->modify('+72 hours +1 second');

        self::assertNull($repo->emailForToken($token));
        self::assertNull($repo->consume($token));
    }

    #[Test]
    public function consumption_is_compare_and_set_single_use(): void
    {
        $now = new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC'));
        $repo = $this->repo(static fn(): \DateTimeImmutable => $now);
        $token = $repo->mint('admin@example.test');

        self::assertSame('admin@example.test', $repo->consume($token));
        self::assertNull($repo->consume($token));
    }
}
