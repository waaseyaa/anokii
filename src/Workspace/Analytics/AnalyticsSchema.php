<?php

declare(strict_types=1);

namespace Anokii\Workspace\Analytics;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Test fixture for the append-only analytics event table.
 *
 * Production schema ownership lives in the versioned app migrations. This
 * helper remains for isolated unit tests that use an in-memory database.
 *
 * This is the canonical first-party analytics schema for the Anokii
 * distribution: cookieless, sovereign, stored in the instance's own database.
 *
 * @api
 */
final class AnalyticsSchema
{
    public const TABLE = 'analytics_event';

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
                'event_type' => ['type' => 'varchar', 'length' => 20, 'not null' => true],
                'path' => ['type' => 'varchar', 'length' => 255],
                'referrer_host' => ['type' => 'varchar', 'length' => 255],
                'view_id' => ['type' => 'varchar', 'length' => 64],
                'visitor_hash' => ['type' => 'varchar', 'length' => 64],
                'device' => ['type' => 'varchar', 'length' => 20],
                'scroll_pct' => ['type' => 'int'],
                'dwell_ms' => ['type' => 'int'],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_ae_created' => ['created_at'],
                'idx_ae_type' => ['event_type'],
                'idx_ae_view' => ['view_id'],
                'idx_ae_visitor' => ['visitor_hash', 'created_at'],
            ],
        ]);
    }
}
