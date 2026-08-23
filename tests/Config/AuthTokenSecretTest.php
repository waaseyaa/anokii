<?php

declare(strict_types=1);

namespace Anokii\Tests\Config;

use Anokii\Config\AuthTokenSecret;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthTokenSecret::class)]
final class AuthTokenSecretTest extends TestCase
{
    private const string SHIPPED_PLACEHOLDER = 'replace-with-at-least-32-random-bytes';

    #[Test]
    public function unset_secret_is_omitted_rather_than_copied_from_the_application_master(): void
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
    public function whitespace_only_secret_is_omitted_rather_than_used_as_the_hmac_key(): void
    {
        $secret = AuthTokenSecret::fromRaw(" \t\n");
        $auth = AuthTokenSecret::withAuthConfig([], $secret);

        self::assertNull($secret);
        self::assertArrayNotHasKey('token_secret', $auth);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changeMeVariants(): iterable
    {
        yield 'lowercase hyphen' => ['change-me'];
        yield 'uppercase hyphen' => ['CHANGE-ME'];
        yield 'mixed case hyphen' => ['Change-Me'];
        yield 'no separator' => ['changeme'];
        yield 'spaces' => ['change me'];
        yield 'underscore' => ['change_me'];
        yield 'padded' => ['  CHANGE-ME  '];
    }

    #[Test]
    #[DataProvider('changeMeVariants')]
    public function change_me_case_variants_are_rejected(string $value): void
    {
        try {
            AuthTokenSecret::fromRaw($value);
            self::fail('A change-me variant was accepted as AUTH_TOKEN_SECRET.');
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    #[Test]
    public function short_secret_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        AuthTokenSecret::fromRaw('too-short');
    }

    #[Test]
    public function one_character_under_the_minimum_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        AuthTokenSecret::fromRaw(str_repeat('a', 31));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shippedPlaceholders(): iterable
    {
        yield 'auth token example' => ['replace-with-at-least-32-random-bytes'];
        yield 'auth token example uppercase' => ['REPLACE-WITH-AT-LEAST-32-RANDOM-BYTES'];
        yield 'privacy example' => ['replace-with-a-separate-32-byte-random-secret'];
        yield 'app secret inner example' => ['replace-with-canonical-base64-of-32-random-bytes'];
        yield 'app secret documented form' => ['base64:replace-with-canonical-base64-of-32-random-bytes'];
        yield 'community slug example' => ['replace-with-the-instance-community-slug'];
    }

    #[Test]
    #[DataProvider('shippedPlaceholders')]
    public function shipped_and_documented_placeholders_are_rejected(string $value): void
    {
        try {
            AuthTokenSecret::fromRaw($value);
            self::fail('A shipped Anokii placeholder was accepted as AUTH_TOKEN_SECRET.');
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('AUTH_TOKEN_SECRET', $exception->getMessage());
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    #[Test]
    public function valid_secret_is_copied_into_auth_config(): void
    {
        $raw = 'anokii-explicit-token-secret-0001';
        $secret = AuthTokenSecret::fromRaw('  ' . $raw . "\n");
        $auth = AuthTokenSecret::withAuthConfig(['dev_fallback_account' => false], $secret);

        self::assertIsString($secret);
        self::assertTrue(hash_equals($raw, $secret), 'trimmed explicit secret was not retained');
        self::assertArrayHasKey('token_secret', $auth);
        self::assertIsString($auth['token_secret']);
        self::assertTrue(
            hash_equals($raw, $auth['token_secret']),
            'auth.token_secret did not keep the operator-owned explicit secret',
        );
        self::assertFalse($auth['dev_fallback_account']);
    }

    #[Test]
    #[DataProvider('productionEnvironments')]
    public function production_equivalent_environments_require_a_configured_secret(string $environment): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_SECRET');

        AuthTokenSecret::assertConfiguredForEnvironment(null, $environment);
    }

    #[Test]
    #[DataProvider('productionEnvironments')]
    public function production_refusal_does_not_invite_derived_or_app_secret_custody(string $environment): void
    {
        try {
            AuthTokenSecret::assertConfiguredForEnvironment(null, $environment);
            self::fail('Production-equivalent environments must refuse absent independent custody.');
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('independent', $exception->getMessage());
            self::assertStringNotContainsString('app_secret fallback', $exception->getMessage());
            self::assertStringNotContainsString('raw app_secret', $exception->getMessage());
        }
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
        AuthTokenSecret::assertConfiguredForEnvironment('anokii-explicit-token-secret-0002', 'production');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function generic_validation_is_delegated_to_framework_auth_token_secret(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Config/AuthTokenSecret.php');

        self::assertStringContainsString('Waaseyaa\\Auth\\Security\\AuthTokenSecret', $source);
        self::assertStringContainsString('usesDerivedCustody', $source);
        self::assertStringContainsString('FrameworkAuthTokenSecret::resolve', $source);
        self::assertStringNotContainsString('MINIMUM_EXPLICIT_LENGTH', $source);
    }

    #[Test]
    public function env_example_leaves_auth_token_secret_empty(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');

        self::assertMatchesRegularExpression('/^AUTH_TOKEN_SECRET=$/m', $example);
        self::assertStringNotContainsString('AUTH_TOKEN_SECRET=' . self::SHIPPED_PLACEHOLDER, $example);
        self::assertStringContainsString('bin2hex(random_bytes(32))', $example);
        self::assertStringNotContainsString('app_secret fallback', $example);
        self::assertStringNotContainsString('raw app_secret', $example);
    }

    #[Test]
    public function source_does_not_claim_framework_falls_back_to_raw_app_secret_bytes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Config/AuthTokenSecret.php');
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertStringNotContainsString('auth.token_secret ?? app_secret', $source);
        self::assertStringNotContainsString('raw app_secret', $source);
        self::assertStringNotContainsString('config app_secret fallback', $source);
        self::assertStringNotContainsString('app_secret fallback', $readme);
    }

    #[Test]
    public function composition_path_rejects_unset_secret_in_production(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_TOKEN_SECRET');

        $this->composeConfig([
            'APP_ENV' => 'production',
            'AUTH_TOKEN_SECRET' => false,
        ]);
    }

    #[Test]
    public function composition_path_rejects_empty_and_whitespace_in_production(): void
    {
        foreach (['', '   ', "\t\n"] as $value) {
            try {
                $this->composeConfig([
                    'APP_ENV' => 'production',
                    'AUTH_TOKEN_SECRET' => $value,
                ]);
                self::fail('Empty or whitespace AUTH_TOKEN_SECRET was accepted in production.');
            } catch (AssertionFailedError $exception) {
                throw $exception;
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('AUTH_TOKEN_SECRET', $exception->getMessage());
            }
        }
    }

    #[Test]
    #[DataProvider('productionEnvironments')]
    public function composition_path_refuses_production_like_environments_without_independent_custody(string $environment): void
    {
        $this->expectException(\RuntimeException::class);

        $this->composeConfig([
            'APP_ENV' => $environment,
            'AUTH_TOKEN_SECRET' => false,
            'WAASEYAA_APP_SECRET' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]);
    }

    #[Test]
    #[DataProvider('developmentEnvironments')]
    public function composition_path_omits_token_secret_in_development_when_unset(string $environment): void
    {
        $config = $this->composeConfig([
            'APP_ENV' => $environment,
            'AUTH_TOKEN_SECRET' => false,
        ]);

        self::assertSame($environment, $config['environment']);
        self::assertIsArray($config['auth']);
        self::assertArrayNotHasKey('token_secret', $config['auth']);
        self::assertArrayHasKey('dev_fallback_account', $config['auth']);
    }

    #[Test]
    public function composition_path_rejects_the_shipped_placeholder(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->composeConfig([
            'APP_ENV' => 'production',
            'AUTH_TOKEN_SECRET' => self::SHIPPED_PLACEHOLDER,
        ]);
    }

    #[Test]
    public function composition_path_keeps_a_valid_explicit_secret_and_does_not_echo_it(): void
    {
        $secret = 'anokii-explicit-token-secret-' . bin2hex(random_bytes(8));

        try {
            $config = $this->composeConfig([
                'APP_ENV' => 'production',
                'AUTH_TOKEN_SECRET' => $secret,
                'WAASEYAA_DEV_FALLBACK_ACCOUNT' => '0',
            ]);
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            throw $exception;
        }

        self::assertIsArray($config['auth']);
        self::assertArrayHasKey('token_secret', $config['auth']);
        self::assertIsString($config['auth']['token_secret']);
        self::assertTrue(
            hash_equals($secret, $config['auth']['token_secret']),
            'config/waaseyaa.php did not retain the operator-owned explicit secret',
        );
        self::assertFalse($config['auth']['dev_fallback_account']);
        self::assertSame('production', $config['environment']);
    }

    #[Test]
    public function invalid_configured_input_never_silently_becomes_a_derived_key(): void
    {
        $appSecret = 'base64:' . base64_encode(random_bytes(32));

        try {
            $this->composeConfig([
                'APP_ENV' => 'local',
                'AUTH_TOKEN_SECRET' => self::SHIPPED_PLACEHOLDER,
                'WAASEYAA_APP_SECRET' => $appSecret,
            ]);
            self::fail('Invalid AUTH_TOKEN_SECRET was accepted in development.');
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($appSecret, $exception->getMessage());
            self::assertStringNotContainsString(self::SHIPPED_PLACEHOLDER, $exception->getMessage());
        }
    }

    /**
     * @param array<string, false|string> $env
     * @return array<string, mixed>
     */
    private function composeConfig(array $env): array
    {
        $previous = [];
        foreach ($env as $name => $value) {
            $previous[$name] = getenv($name);
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        try {
            /** @var array<string, mixed> $config */
            $config = require dirname(__DIR__, 2) . '/config/waaseyaa.php';

            return $config;
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
