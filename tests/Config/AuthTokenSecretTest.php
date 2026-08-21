<?php

declare(strict_types=1);

namespace Anokii\Tests\Config;

use Anokii\Config\AuthTokenSecret;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthTokenSecret::class)]
final class AuthTokenSecretTest extends TestCase
{
    #[Test]
    public function unset_secret_is_omitted_so_framework_app_secret_fallback_can_apply(): void
    {
        $secret = AuthTokenSecret::fromRaw(false);
        $auth = AuthTokenSecret::withAuthConfig(['dev_fallback_account' => false], $secret);

        self::assertNull($secret);
        self::assertArrayNotHasKey('token_secret', $auth);
    }

    #[Test]
    public function empty_secret_is_omitted_rather_than_supplied_as_an_empty_string(): void
    {
        $secret = AuthTokenSecret::fromRaw('');
        $auth = AuthTokenSecret::withAuthConfig([], $secret);

        self::assertNull($secret);
        self::assertArrayNotHasKey('token_secret', $auth);
    }

    #[Test]
    public function placeholder_change_me_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_SECRET');

        AuthTokenSecret::fromRaw('change-me');
    }

    #[Test]
    public function short_secret_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        AuthTokenSecret::fromRaw('too-short');
    }

    #[Test]
    public function valid_secret_is_copied_into_auth_config(): void
    {
        $raw = str_repeat('a', 64);
        $secret = AuthTokenSecret::fromRaw($raw);
        $auth = AuthTokenSecret::withAuthConfig(['dev_fallback_account' => false], $secret);

        self::assertSame($raw, $secret);
        self::assertSame($raw, $auth['token_secret']);
    }

    #[Test]
    #[DataProvider('productionEnvironments')]
    public function production_equivalent_environments_require_a_configured_secret(string $environment): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_SECRET');

        AuthTokenSecret::assertConfiguredForEnvironment(null, $environment);
    }

    /** @return iterable<string, array{string}> */
    public static function productionEnvironments(): iterable
    {
        yield 'production' => ['production'];
        yield 'staging' => ['staging'];
        yield 'unrecognised' => ['preview'];
    }

    #[Test]
    #[DataProvider('developmentEnvironments')]
    public function development_environments_may_omit_the_secret(string $environment): void
    {
        AuthTokenSecret::assertConfiguredForEnvironment(null, $environment);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function developmentEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'dev' => ['dev'];
        yield 'development' => ['development'];
        yield 'testing' => ['testing'];
    }

    #[Test]
    public function valid_secret_satisfies_production_readiness(): void
    {
        AuthTokenSecret::assertConfiguredForEnvironment(str_repeat('b', 64), 'production');

        $this->addToAssertionCount(1);
    }
}
