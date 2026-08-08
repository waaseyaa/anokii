<?php

declare(strict_types=1);

namespace Anokii\CoIntelligence;

use Anokii\Support\Values;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Append-only, anonymous Co-Intelligence query log backed by SQLite (table
 * {@see ChatQueryLogSchema::TABLE}).
 *
 * Records timestamp, vantage community, outcome, inferred topic, and cited
 * sources only. The legacy `question` column receives a fixed redaction marker;
 * raw prompts can contain PII even when the assistant never solicits it.
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
                    '[redacted]',
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
     * Recent log rows for the admin review surface. Non-prompt columns, newest
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
                $values = Values::map($row);
                $out[] = [
                    'created_at' => Values::str($values['created_at'] ?? null),
                    'community' => Values::str($values['community'] ?? null),
                    'question' => Values::str($values['question'] ?? null),
                    'outcome' => Values::str($values['outcome'] ?? null),
                    'topic' => Values::str($values['topic'] ?? null),
                    'sources' => Values::str($values['sources'] ?? null),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }
}
