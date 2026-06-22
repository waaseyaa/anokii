<?php

declare(strict_types=1);

namespace Anokii\Controller;

use Anokii\CoIntelligence\ChatQueryLogSchema;
use Anokii\CoIntelligence\SqliteChatQueryLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Database\DatabaseInterface;

/**
 * The lean Anokii admin surface for the public graph-chat tier (shared-graph).
 *
 * Three jobs: show the graph at a glance (entity counts), review the no-PII chat
 * log to find gaps, and surface the corpus/graph re-index commands. Entity CRUD
 * is handled by the framework admin SPA at /admin; this surface is the
 * Anokii-specific glue (corpus health and the content-gap loop).
 *
 * Registered at /admin/anokii and gated in production by the host basic_auth on
 * /admin/* (the same gate as /admin/analytics). It renders a self-contained
 * document so it does not depend on how the SSR Twig loader resolves package
 * template paths; a later increment can move this to a package Twig template
 * once that namespace is wired in every consumer.
 *
 * @api
 */
final class AnokiiAdminController
{
    private const ENTITY_TABLES = ['community', 'place', 'organization', 'service', 'project', 'topic', 'doc_chunk'];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly SqliteChatQueryLog $log,
    ) {}

    public function index(Request $request): Response
    {
        $counts = [];
        foreach (self::ENTITY_TABLES as $table) {
            $counts[$table] = $this->count($table);
        }
        $rows = $this->log->recent(200);

        return new Response($this->render($counts, $rows), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function count(string $table): int
    {
        try {
            foreach ($this->db->query('SELECT COUNT(*) AS c FROM ' . $table) as $row) {
                return (int) ($row['c'] ?? 0);
            }
        } catch (\Throwable) {
            return -1; // table absent: graph not seeded yet
        }

        return 0;
    }

    /**
     * @param array<string, int> $counts
     * @param list<array{created_at: string, community: string, question: string, outcome: string, topic: string, sources: string}> $rows
     */
    private function render(array $counts, array $rows): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $countCells = '';
        foreach ($counts as $table => $n) {
            $value = $n < 0 ? 'not seeded' : (string) $n;
            $countCells .= '<div class="stat"><p class="stat__label">' . $e($table) . '</p><div class="stat__value">' . $e($value) . '</div></div>';
        }

        $logRows = '';
        foreach ($rows as $r) {
            $logRows .= '<tr>'
                . '<td class="mono">' . $e($r['created_at']) . '</td>'
                . '<td>' . $e($r['community'] !== '' ? $r['community'] : 'treaty-wide') . '</td>'
                . '<td>' . $e($r['question']) . '</td>'
                . '<td class="mono">' . $e($r['outcome']) . '</td>'
                . '<td>' . $e($r['topic'] !== '' ? $r['topic'] : 'none') . '</td>'
                . '</tr>';
        }
        if ($logRows === '') {
            $logRows = '<tr><td class="empty" colspan="5">No questions logged yet.</td></tr>';
        }

        $table = ChatQueryLogSchema::TABLE;

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Anokii admin</title>
            <style>
              :root { --bg:#fbfaff; --surface:#fff; --ink:#221d33; --ink-2:#4a4361; --ink-3:#6f6688; --rule:#e4def2; --accent:#4f2fb0; --magenta:#c41d8f; --mono:ui-monospace,"JetBrains Mono",monospace; --sans:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
              * { box-sizing:border-box; }
              body { margin:0; background:var(--bg); color:var(--ink); font-family:var(--sans); font-size:15px; line-height:1.6; }
              .wrap { max-width:1000px; margin:0 auto; padding:32px 24px 72px; }
              h1 { font-size:22px; margin:0 0 4px; }
              h2 { font-size:12px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--magenta); margin:36px 0 14px; }
              .muted { color:var(--ink-3); font-size:13.5px; }
              .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:12px; }
              .stat { background:var(--surface); border:1px solid var(--rule); border-radius:10px; padding:14px 16px; }
              .stat__label { font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); margin:0 0 8px; }
              .stat__value { font-family:var(--mono); font-size:24px; font-weight:600; }
              .card { background:var(--surface); border:1px solid var(--rule); border-left:4px solid var(--accent); border-radius:0 10px 10px 0; padding:16px 20px; margin:16px 0; }
              code { font-family:var(--mono); background:#f4f1fb; padding:1px 6px; border-radius:4px; }
              table { width:100%; border-collapse:collapse; font-size:13.5px; }
              thead th { text-align:left; font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); padding:0 10px 8px; border-bottom:1px solid var(--rule); }
              tbody td { padding:9px 10px; border-bottom:1px solid #ece7f7; color:var(--ink-2); vertical-align:top; }
              td.mono { font-family:var(--mono); font-size:12.5px; color:var(--ink); white-space:nowrap; }
              td.empty { text-align:center; color:var(--ink-3); font-style:italic; padding:20px; }
              .scroll { overflow-x:auto; }
            </style>
            </head>
            <body>
            <main class="wrap">
              <h1>Anokii admin</h1>
              <p class="muted">Co-Intelligence corpus health and the no-PII content-gap log. Entity editing is in the main admin at <code>/admin</code>.</p>

              <h2>The graph</h2>
              <div class="grid">{$countCells}</div>

              <div class="card">
                <p style="margin:0 0 6px"><strong>Re-index after content changes.</strong></p>
                <p class="muted" style="margin:0">Seed or refresh the graph and corpus from the install's content commands (idempotent), then the chat answers from the updated pages. The exact command names are install-specific; see the app's console commands.</p>
              </div>

              <h2>Recent questions (no personal data)</h2>
              <p class="muted">Question text, vantage, outcome, and inferred topic only. No IP, no identity, no session. Stored in <code>{$table}</code>. Use refusals and no-match rows to find corpus gaps.</p>
              <div class="scroll">
                <table>
                  <thead><tr><th>When (UTC)</th><th>Vantage</th><th>Question</th><th>Outcome</th><th>Topic</th></tr></thead>
                  <tbody>{$logRows}</tbody>
                </table>
              </div>
            </main>
            </body>
            </html>
            HTML;
    }
}
