<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();

        if (!$schema->hasTable('anokii_password_token')) {
            $connection->executeStatement(
                'CREATE TABLE anokii_password_token (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    token_hash VARCHAR(64) NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    used_at VARCHAR(19) DEFAULT NULL
                )',
            );
            $connection->executeStatement('CREATE INDEX idx_token_hash ON anokii_password_token (token_hash)');
            $connection->executeStatement('CREATE INDEX idx_token_email ON anokii_password_token (email)');
        }

        if (!$schema->hasTable('analytics_event')) {
            $connection->executeStatement(
                'CREATE TABLE analytics_event (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    event_type VARCHAR(20) NOT NULL,
                    path VARCHAR(255) DEFAULT NULL,
                    referrer_host VARCHAR(255) DEFAULT NULL,
                    view_id VARCHAR(64) DEFAULT NULL,
                    visitor_hash VARCHAR(64) DEFAULT NULL,
                    device VARCHAR(20) DEFAULT NULL,
                    scroll_pct INTEGER DEFAULT NULL,
                    dwell_ms INTEGER DEFAULT NULL,
                    created_at VARCHAR(19) NOT NULL
                )',
            );
            $connection->executeStatement('CREATE INDEX idx_ae_created ON analytics_event (created_at)');
            $connection->executeStatement('CREATE INDEX idx_ae_type ON analytics_event (event_type)');
            $connection->executeStatement('CREATE INDEX idx_ae_view ON analytics_event (view_id)');
            $connection->executeStatement('CREATE INDEX idx_ae_visitor ON analytics_event (visitor_hash, created_at)');
        }

        if (!$schema->hasTable('chat_query_log')) {
            $connection->executeStatement(
                'CREATE TABLE chat_query_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    community VARCHAR(32) NOT NULL,
                    question VARCHAR(512) NOT NULL,
                    outcome VARCHAR(16) NOT NULL,
                    topic VARCHAR(64) DEFAULT NULL,
                    sources VARCHAR(512) DEFAULT NULL
                )',
            );
            $connection->executeStatement('CREATE INDEX idx_cq_created ON chat_query_log (created_at)');
            $connection->executeStatement('CREATE INDEX idx_cq_community ON chat_query_log (community, created_at)');
            $connection->executeStatement('CREATE INDEX idx_cq_outcome ON chat_query_log (outcome)');
            $connection->executeStatement('CREATE INDEX idx_cq_topic ON chat_query_log (topic)');
        }
    }
};
