<?php

declare(strict_types=1);

namespace Anokii\Admin;

use Anokii\CoIntelligence\SqliteChatQueryLog;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Read-only data for the Co-Intelligence admin module: the graph entity counts
 * and the no-PII recent-questions log. Package-owned so every install shows the
 * same Co-Intelligence health view from the same source.
 *
 * @api
 */
final class AdminData
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Count rows per graph entity table. A table that does not exist yet (graph
     * not seeded) reports -1 so the UI can show "not seeded".
     *
     * @return array<string, int>
     *
     * @api
     */
    public function graphCounts(): array
    {
        $counts = [];
        foreach (AdminModules::GRAPH_TABLES as $table) {
            $counts[$table] = $this->count($table);
        }

        return $counts;
    }

    /**
     * The most recent no-PII chat questions (question text, vantage, outcome,
     * inferred topic only).
     *
     * @return list<array{created_at: string, community: string, question: string, outcome: string, topic: string, sources: string}>
     *
     * @api
     */
    public function recentQuestions(int $limit = 200): array
    {
        return new SqliteChatQueryLog($this->db)->recent($limit);
    }

    private function count(string $table): int
    {
        try {
            foreach ($this->db->query('SELECT COUNT(*) AS c FROM ' . $table) as $row) {
                return (int) ($row['c'] ?? 0);
            }
        } catch (\Throwable) {
            return -1;
        }

        return 0;
    }
}
