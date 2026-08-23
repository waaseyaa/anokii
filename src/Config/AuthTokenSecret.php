<?php

declare(strict_types=1);

namespace Anokii\Config;

/**
 * Classifies AUTH_TOKEN_SECRET for Anokii's independent-custody policy.
 *
 * Unset, empty, and whitespace-only values omit `auth.token_secret` so
 * Framework can apply its own absent-key behavior. Current Framework
 * derives a purpose-specific HMAC key from application-secret custody;
 * it does not use the application-master bytes as the token key. Anokii
 * still requires a valid explicit secret in production, staging, and
 * unknown environments and never copies WAASEYAA_APP_SECRET into config.
 *
 * Invalid explicit input — short values, case variants of change-me, and
 * every placeholder Anokii ships or documents — fails in every
 * environment. It never becomes an ephemeral or derived key.
 *
 * @api
 */
final class AuthTokenSecret
{
    public const int MINIMUM_EXPLICIT_LENGTH = 32;

    /**
     * Documented Anokii placeholders that are long enough to pass generic
     * strength checks. Framework does not reject these strings.
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
    public static function fromRaw(false|string $value): ?string
    {
        if ($value === false) {
            return null;
        }

        $secret = trim($value);
        if ($secret === '') {
            return null;
        }

        self::assertStrongExplicit($secret);

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

    private static function assertStrongExplicit(#[\SensitiveParameter] string $secret): void
    {
        $folded = strtolower(str_replace(['-', '_', ' '], '', $secret));
        if ($folded === 'changeme') {
            throw self::invalidExplicitException();
        }

        if (strlen($secret) < self::MINIMUM_EXPLICIT_LENGTH) {
            throw self::invalidExplicitException();
        }

        if (in_array(strtolower($secret), self::ANOKII_PLACEHOLDERS, true)) {
            throw new \RuntimeException(
                'AUTH_TOKEN_SECRET is a shipped or documented Anokii placeholder and cannot be used as an HMAC key.',
            );
        }
    }

    private static function invalidExplicitException(): \RuntimeException
    {
        return new \RuntimeException(
            'AUTH_TOKEN_SECRET is invalid and is refused in every environment. '
            . 'Provide a trimmed operator-owned secret of at least '
            . self::MINIMUM_EXPLICIT_LENGTH
            . ' characters that is not a published placeholder.',
        );
    }
}
