<?php

declare(strict_types=1);

namespace Anokii\Config;

use Waaseyaa\Auth\Security\AuthTokenSecret as FrameworkAuthTokenSecret;

/**
 * Applies Anokii's independent-custody policy on top of Framework classification.
 *
 * Framework {@see FrameworkAuthTokenSecret} validates explicit secrets and
 * classifies absent/empty/whitespace configuration as derived custody.
 * Current Framework derives a purpose-specific HMAC key in that mode; it
 * does not use the application-master bytes as the token key.
 *
 * Anokii-specific policy:
 * - production, staging, and unknown environments require valid explicit
 *   custody and refuse derived custody from WAASEYAA_APP_SECRET;
 * - every placeholder Anokii ships or documents is rejected even when it
 *   would pass Framework's generic strength checks.
 *
 * Invalid explicit input fails in every environment and never becomes an
 * ephemeral or derived key.
 *
 * @api
 */
final class AuthTokenSecret
{
    /**
     * Documented Anokii placeholders that are long enough to pass generic
     * Framework strength checks.
     *
     * @var list<string>
     */
    private const array ANOKII_PLACEHOLDERS = [
        'replace-with-at-least-32-random-bytes',
        'replace-with-a-separate-32-byte-random-secret',
        'replace-with-canonical-base64-of-32-random-bytes',
        'base64:replace-with-canonical-base64-of-32-random-bytes',
        'replace-with-the-instance-community-slug',
    ];

    /**
     * @param false|string $value `getenv('AUTH_TOKEN_SECRET')` — false when unset
     */
    public static function fromRaw(false|string $value, string $environment = 'unspecified'): ?string
    {
        $configured = $value === false ? null : $value;

        if (FrameworkAuthTokenSecret::usesDerivedCustody($configured, $environment)) {
            return null;
        }

        $secret = FrameworkAuthTokenSecret::resolve($configured, null, $environment);
        self::assertNotAnokiiPlaceholder($secret);

        return $secret;
    }

    /**
     * @param array<string, mixed> $auth
     * @return array<string, mixed>
     */
    public static function withAuthConfig(array $auth, ?string $tokenSecret): array
    {
        if ($tokenSecret !== null) {
            $auth['token_secret'] = $tokenSecret;
        }

        return $auth;
    }

    public static function assertConfiguredForEnvironment(?string $tokenSecret, string $environment): void
    {
        if ($tokenSecret !== null) {
            return;
        }

        if (!self::requiresConfiguredSecret($environment)) {
            return;
        }

        throw new \RuntimeException(
            'AUTH_TOKEN_SECRET must be an independent operator-owned secret in production-equivalent environments. '
            . 'Anokii does not copy WAASEYAA_APP_SECRET into config and refuses Framework derived-custody mode '
            . 'when serving production, staging, or unknown environments.',
        );
    }

    public static function requiresConfiguredSecret(string $environment): bool
    {
        return !in_array(strtolower($environment), ['local', 'dev', 'development', 'testing'], true);
    }

    private static function assertNotAnokiiPlaceholder(#[\SensitiveParameter] string $secret): void
    {
        if (in_array(strtolower($secret), self::ANOKII_PLACEHOLDERS, true)) {
            throw new \RuntimeException(
                'AUTH_TOKEN_SECRET is a shipped or documented Anokii placeholder and cannot be used as an HMAC key.',
            );
        }
    }
}
