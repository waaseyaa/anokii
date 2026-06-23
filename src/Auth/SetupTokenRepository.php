<?php

declare(strict_types=1);

namespace Anokii\Auth;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Mint, look up, and consume one-time set-password tokens for the workspace
 * admin tier. Only token hashes are stored (see {@see SetupTokenSchema}).
 *
 * @api
 */
final class SetupTokenRepository
{
    public function __construct(private readonly DatabaseInterface $db) {}

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
        $this->db->query(
            'DELETE FROM ' . SetupTokenSchema::TABLE . ' WHERE email = ? AND used_at IS NULL',
            [$email],
        );
        $token = bin2hex(random_bytes(32));
        $this->db->query(
            'INSERT INTO ' . SetupTokenSchema::TABLE . ' (email, token_hash, created_at, used_at) VALUES (?, ?, ?, NULL)',
            [$email, self::hash($token), gmdate('Y-m-d H:i:s')],
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
            'SELECT email FROM ' . SetupTokenSchema::TABLE . ' WHERE token_hash = ? AND used_at IS NULL',
            [self::hash($token)],
        ) as $row) {
            return (string) ($row['email'] ?? '');
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
        $this->db->query(
            'UPDATE ' . SetupTokenSchema::TABLE . ' SET used_at = ? WHERE token_hash = ? AND used_at IS NULL',
            [gmdate('Y-m-d H:i:s'), self::hash($token)],
        );

        return $email;
    }
}
