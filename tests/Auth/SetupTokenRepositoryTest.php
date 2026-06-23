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
    private function repo(): SetupTokenRepository
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new SetupTokenSchema($db)->ensure();

        return new SetupTokenRepository($db);
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
    public function unknown_and_empty_tokens_resolve_to_null(): void
    {
        $repo = $this->repo();
        self::assertNull($repo->emailForToken(''));
        self::assertNull($repo->emailForToken('not-a-real-token'));
    }
}
