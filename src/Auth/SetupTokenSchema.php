<?php

declare(strict_types=1);

namespace Anokii\Auth;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Table for one-time "set your password" invite tokens, used by the workspace
 * admin tier ({@see \Anokii\Dashboard\WorkspaceLoginController} +
 * {@see \Anokii\Admin\InviteHandler}).
 *
 * Only the SHA-256 hash of a token is stored; the plaintext is shown once at
 * mint time (printed as the invite link) and never persisted. This lets an
 * account holder set their own initial password without a password ever
 * appearing in code or chat. Plain DatabaseInterface (a non-entity index table).
 *
 * @api
 */
final class SetupTokenSchema
{
    public const TABLE = 'anokii_password_token';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        $schema->createTable(self::TABLE, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'token_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                'used_at' => ['type' => 'varchar', 'length' => 19],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_token_hash' => ['token_hash'],
                'idx_token_email' => ['email'],
            ],
        ]);
    }
}
