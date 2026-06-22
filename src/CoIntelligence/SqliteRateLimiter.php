<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Fixed-window per-key limiter backed by SQLite via DatabaseInterface.
 *
 * The framework's shipped in-memory limiter is per-request under php-fpm, so it
 * does not limit across requests; this uses the persistent app database so the
 * limit actually holds. The table is a supporting (non-entity) table, created on
 * demand, the framework convention for such tables. The key is hashed before
 * storage, so no raw client identifier is persisted.
 *
 * @api
 */
final class SqliteRateLimiter implements RateLimiterInterface
{
    private const TABLE = 'chat_rate_limit';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly int $maxRequests = 12,
        private readonly int $windowSeconds = 60,
    ) {
        $this->ensureTable();
    }

    public function retryAfter(string $key): ?int
    {
        $client = hash('sha256', $key);
        $now = time();

        $row = null;
        foreach ($this->db->query('SELECT window_start, hits FROM ' . self::TABLE . ' WHERE client = ?', [$client]) as $r) {
            $row = $r;
            break;
        }

        if ($row !== null && ($now - (int) $row['window_start']) < $this->windowSeconds) {
            $hits = (int) $row['hits'] + 1;
            $windowStart = (int) $row['window_start'];
        } else {
            $hits = 1;
            $windowStart = $now;
        }

        if ($hits > $this->maxRequests) {
            return max(1, $this->windowSeconds - ($now - $windowStart));
        }

        $this->db->query(
            'INSERT INTO ' . self::TABLE . ' (client, window_start, hits) VALUES (?, ?, ?)'
            . ' ON CONFLICT(client) DO UPDATE SET window_start = excluded.window_start, hits = excluded.hits',
            [$client, $windowStart, $hits],
        );

        return null;
    }

    private function ensureTable(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        $schema->createTable(self::TABLE, [
            'fields' => [
                'client' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                'window_start' => ['type' => 'int', 'not null' => true],
                'hits' => ['type' => 'int', 'not null' => true],
            ],
            'primary key' => ['client'],
        ]);
    }
}
