<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Creates the anonymous Co-Intelligence query-log table on demand.
 *
 * The framework has no migration CLI, so the table is ensured at boot, guarded
 * by tableExists() (the established Waaseyaa convention for supporting,
 * non-entity tables). The package owns this table so the no-PII log does not
 * depend on any app's analytics schema.
 *
 * SOVEREIGNTY NOTE (OCAP): every column is content-only. There is deliberately
 * no IP, no visitor hash, no view/session id, and no account, nothing that links
 * a question to a person.
 *
 * @api
 */
final class ChatQueryLogSchema
{
    public const TABLE = 'chat_query_log';

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
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                'community' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
                'question' => ['type' => 'varchar', 'length' => 512, 'not null' => true],
                'outcome' => ['type' => 'varchar', 'length' => 16, 'not null' => true],
                'topic' => ['type' => 'varchar', 'length' => 64],
                'sources' => ['type' => 'varchar', 'length' => 512],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_cq_created' => ['created_at'],
                'idx_cq_community' => ['community', 'created_at'],
                'idx_cq_outcome' => ['outcome'],
                'idx_cq_topic' => ['topic'],
            ],
        ]);
    }
}
