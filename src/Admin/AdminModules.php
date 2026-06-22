<?php

declare(strict_types=1);

namespace Anokii\Admin;

/**
 * The canonical Anokii admin module catalog, shared by every install so the
 * workspace looks like the same product everywhere (only the live/preview split
 * and the branding differ per install).
 *
 * Each module is brand-neutral here: id, label, group, an inline line icon, a
 * blurb, the canonical href under /admin/anokii, whether it shows as a dashboard
 * tile, and a default "live" flag. A distribution preset (e.g. {@see sharedGraph()})
 * decides which modules are live for that tenancy tier; the rest render as
 * disabled "product preview" cards via a coming-soon page, so an install advertises
 * the whole product without implying it holds data it does not.
 *
 * Live modules link to their real route; preview modules link to
 * /admin/anokii/m/{id} (the coming-soon page). Instances mount routes at the
 * hrefs declared here, so the catalog is the single source of truth for paths too.
 *
 * @phpstan-type Module array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}
 *
 * @api
 */
final class AdminModules
{
    /** Graph entity tables the Co-Intelligence module reports counts for. */
    public const array GRAPH_TABLES = ['community', 'place', 'organization', 'service', 'project', 'topic', 'doc_chunk'];

    /**
     * The shared-graph public tier: a public Anokii install over one shared,
     * public graph (no member or internal data). Dashboard, Co-Intelligence, and
     * Analytics are live; every internal-workspace module is a preview card.
     *
     * @return list<array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}>
     *
     * @api
     */
    public static function sharedGraph(): array
    {
        return self::resolve(['dashboard', 'cointelligence', 'analytics']);
    }

    /**
     * Resolve the full catalog for an install, marking the given ids live (and
     * pointing them at their real route) and every other module as a preview card
     * (badge "Preview", linking to its coming-soon page).
     *
     * Per-install presentation is supported without forking the catalog:
     *   - $overrides: map of module id => partial fields to merge (label, desc,
     *     icon, group, href, tile, order). Lets an install relabel/regroup/reorder
     *     the canonical modules for its brand.
     *   - $extra: additional full module rows appended (e.g. an install-specific
     *     Settings entry the canonical catalog does not carry).
     * When both are empty the result is byte-identical to the canonical catalog,
     * so existing consumers (e.g. {@see sharedGraph()}) are unchanged.
     *
     * @param list<string>                        $liveIds
     * @param array<string, array<string, mixed>> $overrides
     * @param list<array<string, mixed>>          $extra
     *
     * @return list<array<string, mixed>>
     *
     * @api
     */
    public static function resolve(array $liveIds, array $overrides = [], array $extra = []): array
    {
        $live = array_fill_keys($liveIds, true);
        $out = [];
        foreach (self::catalog() as $m) {
            $isLive = isset($live[$m['id']]);
            $m['live'] = $isLive;
            $m['href'] = $isLive ? $m['href'] : '/admin/anokii/m/' . $m['id'];
            $m['badge'] = $isLive ? '' : 'Preview';
            if (isset($overrides[$m['id']]) && is_array($overrides[$m['id']])) {
                $m = array_merge($m, $overrides[$m['id']]);
            }
            $out[] = $m;
        }

        foreach ($extra as $m) {
            if (is_array($m) && isset($m['id'])) {
                $out[] = $m + ['group' => '', 'live' => true, 'href' => '', 'desc' => '', 'icon' => '', 'badge' => '', 'tile' => false];
            }
        }

        // Optional explicit ordering: if any row carries an 'order' int, stable-sort
        // by it (rows without 'order' keep their original position, after ordered
        // ones). With no overrides/extra, nothing carries 'order' and the canonical
        // order is preserved unchanged.
        $hasOrder = false;
        foreach ($out as $m) {
            if (isset($m['order'])) {
                $hasOrder = true;
                break;
            }
        }
        if ($hasOrder) {
            $seq = 0;
            foreach ($out as &$row) {
                $row['_seq'] = $seq++;
                $row['_order'] = $row['order'] ?? \PHP_INT_MAX;
            }
            unset($row);
            usort($out, static fn (array $a, array $b): int => ($a['_order'] <=> $b['_order']) ?: ($a['_seq'] <=> $b['_seq']));
            foreach ($out as &$row) {
                unset($row['_seq'], $row['_order']);
            }
            unset($row);
        }

        return $out;
    }

    /** @return array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}|null */
    public static function find(string $id): ?array
    {
        foreach (self::catalog() as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }

        return null;
    }

    /**
     * The brand-neutral module catalog with canonical hrefs (live targets). The
     * resolve()/sharedGraph() presets flip live/preview and redirect preview hrefs
     * to the coming-soon page.
     *
     * @return list<array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}>
     */
    private static function catalog(): array
    {
        return [
            self::m('dashboard', 'Dashboard', 'Workspace', '/admin/anokii', '', false,
                '<path d="M4 13h7V4H4v9Zm0 7h7v-5H4v5Zm9 0h7v-9h-7v9Zm0-16v5h7V4h-7Z" fill="currentColor"/>'),
            self::m('cointelligence', 'Co-Intelligence', 'Workspace', '/admin/anokii/cointelligence',
                'The public graph and corpus health, and the no-PII question log.', true,
                '<path d="M5 5h14v10H8l-3 3V5Z" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linejoin="round"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="12" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/>'),
            self::m('identity', 'Identity', 'Workspace', '/admin/anokii/identity',
                'Define who you are, pillar by pillar.', true,
                '<circle cx="12" cy="9" r="3.2" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M5 20c1.2-3.6 4-5.4 7-5.4s5.8 1.8 7 5.4" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round"/>'),
            self::m('drive', 'Drive', 'Workspace', '/admin/anokii/drive',
                'Sovereign file storage, scoped to you.', true,
                '<path d="M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z" stroke="currentColor" stroke-width="1.7" fill="none"/>'),
            self::m('documents', 'Documents', 'Workspace', '/admin/anokii/documents',
                'Preview, version, and discuss documents in one place.', true,
                '<path d="M7 3h7l4 4v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linejoin="round"/><path d="M14 3v4h4" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linejoin="round"/><path d="M9 13h6M9 16h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'),
            self::m('pages', 'Pages', 'Workspace', '/admin/anokii/pages',
                'Edit and publish the public website, with full revision history.', true,
                '<rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M4 9h16" stroke="currentColor" stroke-width="1.7"/><path d="M8 13h8M8 16h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'),
            self::m('inbox', 'Inbox', 'Workspace', '/admin/anokii/inbox',
                'Submissions from the public contact form.', true,
                '<path d="M4 6h16v12H4V6Z" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linejoin="round"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'),
            self::m('venture', 'Venture Tracker', 'Workspace', '/admin/anokii/venture',
                'A live working board across ventures.', true,
                '<path d="M4 19h16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><rect x="5" y="11" width="3.4" height="6" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/><rect x="10.3" y="7" width="3.4" height="10" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/><rect x="15.6" y="13" width="3.4" height="4" rx="1" stroke="currentColor" stroke-width="1.6" fill="none"/>'),
            self::m('ventures', 'Venture Numbers', 'Workspace', '/admin/anokii/ventures',
                'The revenue model, lane by lane: scenarios, assumptions, gating facts.', true,
                '<path d="M4 19h16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="m5 14 4-4 3 3 6-6" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 7h4v4" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'),
            self::m('rooms', 'Data Rooms', 'Workspace', '/admin/anokii/rooms',
                'Secure, time-bound spaces with full audit trails.', true,
                '<rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M4 9h16" stroke="currentColor" stroke-width="1.7"/><circle cx="15" cy="14" r="2" stroke="currentColor" stroke-width="1.6" fill="none"/>'),
            self::m('workspaces', 'Workspaces', 'Workspace', '/admin/anokii/workspaces',
                "Run projects without them living in someone's inbox.", true,
                '<rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7" fill="none"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7" fill="none"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7" fill="none"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7" fill="none"/>'),
            self::m('portal', 'Portal', 'Workspace', '/admin/anokii/portal',
                'Run the public website and member portal from one place.', true,
                '<circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M4 12h16M12 4c2.4 2.6 2.4 13.4 0 16M12 4c-2.4 2.6-2.4 13.4 0 16" stroke="currentColor" stroke-width="1.4" fill="none"/>'),
            self::m('analytics', 'Analytics', 'Insight', '/admin/anokii/analytics',
                'First-party, cookie-less site analytics, in your own database.', true,
                '<path d="M5 20V10M12 20V4M19 20v-7" stroke="currentColor" stroke-width="1.9" fill="none" stroke-linecap="round"/>'),
            self::m('vault', 'Vault', 'Administration', '/admin/anokii/vault',
                'Credentials and confidential records, locked down.', true,
                '<rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" fill="none"/>'),
            self::m('governance', 'Governance', 'Administration', '/admin/anokii/governance',
                'See who has access and where data lives.', true,
                '<path d="M12 4 4 7v5c0 4 3.5 7 8 8 4.5-1 8-4 8-8V7l-8-3Z" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linejoin="round"/>'),
        ];
    }

    /**
     * @return array{id:string,label:string,group:string,live:bool,href:string,desc:string,icon:string,badge:string,tile:bool}
     */
    private static function m(string $id, string $label, string $group, string $href, string $desc, bool $tile, string $icon): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'group' => $group,
            'live' => false,
            'href' => $href,
            'desc' => $desc,
            'icon' => $icon,
            'badge' => '',
            'tile' => $tile,
        ];
    }
}
