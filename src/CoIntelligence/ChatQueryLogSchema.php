<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Anokii\Support\Values;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Test fixture for the anonymous Co-Intelligence query-log table.
 *
 * Production schema and legacy-redaction ownership lives in versioned app
 * migrations. This helper remains for isolated in-memory tests.
 *
 * SOVEREIGNTY NOTE (OCAP): there is no IP, visitor/session id, account, or raw
 * question. The legacy question column is retained for schema compatibility and
 * contains only `[redacted]`.
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
            $this->redactLegacyQuestions();
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

    private function redactLegacyQuestions(): void
    {
        foreach ($this->db->select(self::TABLE)->condition('question', '[redacted]', '<>')->execute() as $row) {
            $values = Values::map($row);
            if (($values['question'] ?? null) === '[redacted]') {
                continue;
            }
            $this->db->update(self::TABLE)
                ->fields(['question' => '[redacted]'])
                ->condition('id', Values::int($values['id'] ?? null))
                ->execute();
        }
    }
}
