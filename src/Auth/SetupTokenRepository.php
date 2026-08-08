<?php

declare(strict_types=1);

namespace Anokii\Auth;

use Anokii\Support\Values;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Mint, look up, and consume one-time set-password tokens for the workspace
 * admin tier. Only token hashes are stored (see {@see SetupTokenSchema}).
 *
 * @api
 */
final class SetupTokenRepository
{
    private const int DEFAULT_TTL_SECONDS = 72 * 60 * 60;

    private readonly \Closure $now;

    /** @param ?\Closure():\DateTimeImmutable $now */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?\Closure $now = null,
    ) {
        if ($this->ttlSeconds < 1) {
            throw new \InvalidArgumentException('Setup-token TTL must be positive.');
        }
        $this->now = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a fresh token for an email, invalidating any prior unused ones.
     * Returns the plaintext token (shown once, to build the invite link).
     */
    public function mint(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('A setup token requires an email address.');
        }
        $this->db->query(
            'DELETE FROM ' . SetupTokenSchema::TABLE . ' WHERE LOWER(email) = ? AND used_at IS NULL',
            [$email],
        );
        $token = bin2hex(random_bytes(32));
        $this->db->query(
            'INSERT INTO ' . SetupTokenSchema::TABLE . ' (email, token_hash, created_at, used_at) VALUES (?, ?, ?, NULL)',
            [$email, self::hash($token), $this->timestamp($this->currentTime())],
        );

        return $token;
    }

    /**
     * Return the email a valid (unused) token belongs to, or null.
     */
    public function emailForToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        foreach ($this->db->query(
            'SELECT email FROM ' . SetupTokenSchema::TABLE
            . ' WHERE token_hash = ? AND used_at IS NULL AND created_at >= ?',
            [self::hash($token), $this->oldestValidTimestamp()],
        ) as $row) {
            return Values::str(Values::map($row)['email'] ?? null);
        }

        return null;
    }

    /**
     * Mark a token used. Returns the email, or null if the token was invalid.
     */
    public function consume(string $token): ?string
    {
        $email = $this->emailForToken($token);
        if ($email === null) {
            return null;
        }
        $affected = $this->db->update(SetupTokenSchema::TABLE)
            ->fields(['used_at' => $this->timestamp($this->currentTime())])
            ->condition('token_hash', self::hash($token))
            ->condition('used_at', null, 'IS NULL')
            ->condition('created_at', $this->oldestValidTimestamp(), '>=')
            ->execute();

        return $affected === 1 ? $email : null;
    }

    private function currentTime(): \DateTimeImmutable
    {
        return ($this->now)()->setTimezone(new \DateTimeZone('UTC'));
    }

    private function oldestValidTimestamp(): string
    {
        return $this->timestamp($this->currentTime()->modify('-' . $this->ttlSeconds . ' seconds'));
    }

    private function timestamp(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s');
    }
}
