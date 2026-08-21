<?php

declare(strict_types=1);

namespace Anokii\Config;

/**
 * Resolves the Framework auth token HMAC secret without defeating its fallback.
 *
 * Framework {@see \Waaseyaa\Auth\AuthServiceProvider} uses
 * `auth.token_secret ?? app_secret`. An empty string is a present value, so
 * Anokii must omit the key when AUTH_TOKEN_SECRET is unset. Production still
 * requires an operator-owned secret: Anokii does not copy WAASEYAA_APP_SECRET
 * into config (key-custody rule).
 *
 * @api
 */
final class AuthTokenSecret
{
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

        if (strtolower($secret) === 'change-me') {
            throw new \RuntimeException(
                'AUTH_TOKEN_SECRET must be a real operator-owned secret; the placeholder "change-me" is rejected.',
            );
        }

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
            'AUTH_TOKEN_SECRET must be set to a non-empty secret in production-equivalent environments. '
            . 'Do not reuse WAASEYAA_APP_SECRET in config; omit auth.token_secret only when the Framework '
            . 'config app_secret fallback is intentionally in use.',
        );
    }

    public static function requiresConfiguredSecret(string $environment): bool
    {
        return !in_array(strtolower($environment), ['local', 'dev', 'development', 'testing'], true);
    }
}
