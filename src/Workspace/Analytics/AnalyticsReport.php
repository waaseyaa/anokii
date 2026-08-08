<?php

declare(strict_types=1);

namespace Anokii\Workspace\Analytics;

use Anokii\Support\Values;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Aggregates raw analytics events into dashboard-ready summaries.
 *
 * Uses raw SELECT queries (GROUP BY / aggregates) via DatabaseInterface::query,
 * which returns associative rows. All counts are cast explicitly because the
 * SQLite driver may return numeric columns as strings.
 *
 * Reports the standard, generally-applicable sections: totals (pageviews,
 * unique visitors), per-page views/visitors/engagement, external referrers,
 * and device split.
 *
 * @api
 */
final class AnalyticsReport
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @return array{
     *   totals: array{views:int, visitors:int},
     *   pages: list<array{path:string, views:int, visitors:int, avg_scroll:float, avg_dwell_ms:float}>,
     *   referrers: list<array{host:string, count:int}>,
     *   devices: list<array{device:string, count:int}>,
     *   from:string, to:string
     * }
     *
     * @api
     */
    public function summary(string $fromDate, string $toDate): array
    {
        $from = $fromDate . ' 00:00:00';
        $to = $toDate . ' 23:59:59';
        $table = AnalyticsSchema::TABLE;

        $totalsRow = $this->one(
            'SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors'
            . " FROM {$table} WHERE event_type = 'pageview' AND created_at BETWEEN ? AND ?",
            [$from, $to],
        );
        $totals = [
            'views' => Values::int($totalsRow['views'] ?? null),
            'visitors' => Values::int($totalsRow['visitors'] ?? null),
        ];

        $pages = [];
        $rows = $this->db->query(
            'SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors'
            . " FROM {$table} WHERE event_type = 'pageview' AND created_at BETWEEN ? AND ?"
            . ' GROUP BY path ORDER BY views DESC',
            [$from, $to],
        );
        foreach ($rows as $row) {
            $r = Values::map($row);
            $path = Values::str($r['path'] ?? null);
            $pages[$path] = [
                'path' => $path,
                'views' => Values::int($r['views'] ?? null),
                'visitors' => Values::int($r['visitors'] ?? null),
                'avg_scroll' => 0.0,
                'avg_dwell_ms' => 0.0,
            ];
        }

        // Engagement rows carry no path; join them to their pageview via view_id.
        $engagement = $this->db->query(
            'SELECT p.path AS path, AVG(e.scroll_pct) AS avg_scroll, AVG(e.dwell_ms) AS avg_dwell'
            . " FROM {$table} e JOIN {$table} p ON p.view_id = e.view_id AND p.event_type = 'pageview'"
            . " WHERE e.event_type = 'engagement' AND e.created_at BETWEEN ? AND ?"
            . ' GROUP BY p.path',
            [$from, $to],
        );
        foreach ($engagement as $row) {
            $r = Values::map($row);
            $path = Values::str($r['path'] ?? null);
            if (isset($pages[$path])) {
                $pages[$path]['avg_scroll'] = round(Values::float($r['avg_scroll'] ?? null), 1);
                $pages[$path]['avg_dwell_ms'] = round(Values::float($r['avg_dwell'] ?? null), 0);
            }
        }

        $referrers = [];
        $rows = $this->db->query(
            "SELECT referrer_host AS host, COUNT(*) AS count FROM {$table}"
            . " WHERE event_type = 'pageview' AND referrer_host IS NOT NULL AND created_at BETWEEN ? AND ?"
            . ' GROUP BY referrer_host ORDER BY count DESC LIMIT 20',
            [$from, $to],
        );
        foreach ($rows as $row) {
            $r = Values::map($row);
            $referrers[] = ['host' => Values::str($r['host'] ?? null), 'count' => Values::int($r['count'] ?? null)];
        }

        $devices = [];
        $rows = $this->db->query(
            "SELECT COALESCE(device, 'unknown') AS device, COUNT(*) AS count FROM {$table}"
            . " WHERE event_type = 'pageview' AND created_at BETWEEN ? AND ?"
            . ' GROUP BY device ORDER BY count DESC',
            [$from, $to],
        );
        foreach ($rows as $row) {
            $r = Values::map($row);
            $devices[] = ['device' => Values::str($r['device'] ?? null), 'count' => Values::int($r['count'] ?? null)];
        }

        return [
            'totals' => $totals,
            'pages' => array_values($pages),
            'referrers' => $referrers,
            'devices' => $devices,
            'from' => $fromDate,
            'to' => $toDate,
        ];
    }

    /**
     * All-time pageview count for a single path (for public per-page social proof).
     *
     * @api
     */
    public function viewsForPath(string $path): int
    {
        $row = $this->one(
            'SELECT COUNT(*) AS views FROM ' . AnalyticsSchema::TABLE
            . " WHERE event_type = 'pageview' AND path = ?",
            [$path],
        );

        return Values::int($row['views'] ?? null);
    }

    /**
     * @param list<mixed> $args
     *
     * @return array<string,mixed>
     */
    private function one(string $sql, array $args): array
    {
        foreach ($this->db->query($sql, $args) as $row) {
            return Values::map($row);
        }

        return [];
    }
}
