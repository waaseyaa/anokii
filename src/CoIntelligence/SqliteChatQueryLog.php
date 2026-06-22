<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Append-only, anonymous Co-Intelligence query log backed by SQLite (table
 * {@see ChatQueryLogSchema::TABLE}).
 *
 * Records timestamp, vantage community, question text, outcome, inferred topic,
 * and cited sources only. No IP, visitor hash, view/session id, account, or
 * anything that links a question to a person. A write failure is swallowed so
 * logging never breaks the user-facing chat response.
 *
 * @api
 */
final class SqliteChatQueryLog implements ChatQueryLogInterface
{
    private const MAX_SOURCES = 10;

    public function __construct(private readonly DatabaseInterface $db) {}

    public function record(string $community, string $question, string $outcome, ?string $topic, array $sources): void
    {
        $uniqueSources = array_values(array_unique(array_filter($sources, static fn(string $s): bool => $s !== '')));
        $sourcesCsv = implode(',', array_slice($uniqueSources, 0, self::MAX_SOURCES));

        try {
            $this->db->query(
                'INSERT INTO ' . ChatQueryLogSchema::TABLE
                . ' (created_at, community, question, outcome, topic, sources) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    gmdate('Y-m-d H:i:s'),
                    substr($community, 0, 32),
                    substr($question, 0, 500),
                    substr($outcome, 0, 16),
                    $topic !== null ? substr($topic, 0, 64) : null,
                    substr($sourcesCsv, 0, 512),
                ],
            );
        } catch (\Throwable) {
            // Logging must never break the chat response.
        }
    }

    /**
     * Recent log rows for the admin review surface. Content-only columns, newest
     * first. Returns an empty list if the table is absent.
     *
     * @return list<array{created_at: string, community: string, question: string, outcome: string, topic: string, sources: string}>
     */
    public function recent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $out = [];
        try {
            foreach ($this->db->query(
                'SELECT created_at, community, question, outcome, topic, sources FROM ' . ChatQueryLogSchema::TABLE
                . ' ORDER BY id DESC LIMIT ' . $limit,
            ) as $row) {
                $out[] = [
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'community' => (string) ($row['community'] ?? ''),
                    'question' => (string) ($row['question'] ?? ''),
                    'outcome' => (string) ($row['outcome'] ?? ''),
                    'topic' => (string) ($row['topic'] ?? ''),
                    'sources' => (string) ($row['sources'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }
}
