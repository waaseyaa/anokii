# Offline-First Baseline (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

This document defines the cross-cutting offline substrate every Anokii v0.1 surface
sits on top of, plus the contract those surfaces must meet. It is a baseline (a
shared foundation and a conformance contract), not a single screen. Where a sibling
surface needs offline behavior, it references this document rather than re-deriving
it.

Per charter DIR-A002, offline-first is a design constraint, not an optional feature.
A surface that requires connectivity for read-after-write within the user's own
classification scope is a charter violation. This baseline exists so that meeting
DIR-A002 is the default path, not extra work each surface re-invents.

## Purpose

Nation workspaces are used in places where connectivity is intermittent, metered, or
absent: band offices with one shared uplink, community halls during events, homes on
the edge of coverage, field visits with no signal at all. A governance tool that goes
blank when the connection drops is not usable in those conditions, and worse, it
quietly pushes people toward keeping records in spreadsheets and paper that sit
outside the OCAP audit trail entirely.

The offline-first baseline keeps the workspace functional in offline-degraded mode.
A member can open the records they are entitled to see, read them, and compose new
writes (a form submission, a field edit, a note) while disconnected. Those writes are
queued locally and reconciled with the server on reconnect. The substrate is three
cooperating pieces: a local cache (Dexie over IndexedDB) holding the entities and
revisions the user is entitled to, a service worker (Workbox) that serves the app
shell and intercepts requests, and a finite-state sync engine that drains the write
queue and resolves conflicts against the framework's revision and optimistic-locking
model.

The hard line this baseline draws: offline is a degraded mode of the SAME governed
system, never a parallel ungoverned one. Classification still applies to what is
cached. OCAP still applies to what syncs. Every offline action carries an `offline_at`
timestamp so the server can reconcile temporal ordering and write a faithful audit
record on reconnect. Offline never becomes a backdoor around the access pipeline.

## Tier applicability

Anokii ships in two deployment shapes. The offline cache holds different data in each,
but the contract (offline read, queued writes, conflict handling) is identical.

### Sovereign single-tenant (FNPI, Intersnipe)

One Nation or organization owns the whole instance. The local cache may hold any
entity and revision the signed-in member is entitled to under that Nation's
classification taxonomy, including `community` and `nation-restricted` records, because
all cached data belongs to the one owning Nation. There is no cross-Nation surface to
leak across. The cache is scoped to the authenticated member's clearance, not to the
Nation boundary (the Nation boundary and the instance boundary are the same here).

### Shared-graph multi-tenant (OIATC)

Several Nations share one instance over a federated graph. The offline cache is
partitioned per Nation. Per charter DIR-A002, partial-trust offline operation permits
caching the user's OWN classified data offline but NOT other Nations' cached data:

- `public`-tier records (default field access `Neutral` across all Nations) may be
  cached for any authenticated user.
- `community`-tier records of the user's own Nation may be cached for that user;
  cross-Nation `community` reads are resolved live (federated partner reads are a
  server-side decision, not a cache-it-everywhere rule).
- `nation-restricted`-tier records are cached ONLY for members of the owning Nation.
  Cross-Nation reads are `Forbidden` via `FieldAccessPolicyInterface`, so they are
  never written to another Nation's local store in the first place.

The cache key namespace is therefore `(nation_short, entity_type, entity_id, langcode)`
so eviction, clearing on sign-out, and the partial-trust boundary are all enforceable
at the IndexedDB layer, not just in the UI.

## User-facing surface

This baseline is mostly invisible substrate, but it does present a small, consistent
set of shared affordances that every surface above inherits:

- **Connection indicator.** A persistent, non-modal status region (online, offline,
  syncing, sync-error) in the workspace chrome. It is a live region (see Accessibility)
  so the state change is announced, not just colored.
- **Queued-writes tray.** A list of pending offline writes: what entity, what field or
  form, when it was composed (`offline_at`), and current state (queued, syncing,
  conflicted, done). The user can inspect a queued write and, for a conflicted one,
  open the conflict resolution view.
- **Conflict resolution view.** When a queued write loses an optimistic-locking race
  (the server's head moved while the user was offline), this view shows the user's
  pending value beside the current server value and offers re-read-and-reapply. It
  never silently discards either side.
- **Stale-read banner.** When a surface is showing data served from the local cache
  rather than a fresh server read, a quiet banner states "showing your offline copy,
  last synced HH:MM" so the user is never misled about freshness.
- **Re-auth-on-reconnect prompt.** When a cached identity token has expired, reconnect
  surfaces a re-authentication prompt before any queued write is allowed to sync.

Surfaces do not build their own versions of these. They consume the shared components
so the offline experience is uniform across Data Rooms, the Co-Intelligence Workspace,
forms, and every other surface.

## Data model

The offline substrate persists through the Waaseyaa entity system on the server side
and mirrors the framework's storage shapes locally. It never writes raw SQL, and the
local cache never becomes a second source of truth: the server entity store is
canonical, the cache is a derived, evictable projection.

### Server-side entities (introduced by this baseline)

- **`sync_queue_entry`** (`id`, `uuid`, `nation_short`, `account_uid`, `entity_type_id`,
  `entity_id`, `langcode`, `operation` (one of `field_update`, `entity_create`,
  `form_submit`), `payload` (`_data` JSON blob), `expected_revision_id` (nullable int),
  `offline_at` (ISO-8601, when the write was composed offline), `synced_at` (nullable),
  `state` (`queued`, `applied`, `conflicted`, `rejected`)). Registered via
  `EntityTypeManager`, persisted via `EntityRepository`. This is the server-visible
  record of an offline write's lifecycle, distinct from the client IndexedDB queue
  (which is the transport). It exists so an offline write is auditable as a first-class
  object, not just a log line.
- **`sync_session`** (`id`, `uuid`, `nation_short`, `account_uid`, `device_label`,
  `started_at`, `completed_at`, `entries_applied`, `entries_conflicted`,
  `entries_rejected`). One row per reconcile-on-reconnect run; the parent of the
  `sync_queue_entry` rows it drained.

### Reused framework primitives (not re-introduced here)

- The two-axis revision model: the base row plus `<entity>_revision`, and for
  translatable entities the per-language `<entity>__translation__revision` keyed
  `(entity_id, langcode, revision_id)`. This tuple maps directly onto a Dexie composite
  key, which is why the offline cache can represent revisions faithfully.
- `SaveContext::withExpectedRevisionId(int $n)` is the optimistic-locking seam every
  queued write rides. A `sync_queue_entry` carries the `expected_revision_id` the client
  read while offline; the sync engine threads it into `SaveContext` on replay.
- `audit_event` rows carry the `actor_uid` (three-state: account id, anonymous `0`, or
  SQL NULL) and the `offline_at` attribute for offline-composed actions.

### Local cache (Dexie / IndexedDB) object stores

- `entities` keyed `[nation_short+entity_type+entity_id+langcode]`, value = the cached
  entity projection plus its `revision_id` and `classification_label`.
- `revisions` keyed `[nation_short+entity_type+entity_id+langcode+revision_id]` for
  offline revision history reads.
- `outbox` keyed by client-generated `uuid`, the pending writes mirrored to
  `sync_queue_entry` on sync.
- `meta` holding `last_synced_at` per `(nation_short, entity_type)` and the cached
  token expiry.

## Access and classification

OCAP and the three tiers apply to the cache exactly as they apply to the server. The
cache is a projection of what the access pipeline already allowed; it is never a place
where a less-restrictive copy lives.

- **What may be cached** is decided server-side by `FieldAccessPolicyInterface` before
  any row reaches the client. A field that is `Forbidden` for this account is stripped
  on the server and never enters IndexedDB. The classification tiers map straight
  through: `public` and `community` are `Neutral` (cacheable subject to the tier rules
  above), `nation-restricted` is `Forbidden` on cross-Nation reads (never cached for
  non-members). The Anokii default taxonomy in `config/classification.anokii-default.yaml`
  is the source of these semantics; the baseline does not redefine them.
- **Hold labels still hold offline.** A `hold-*` classification forbids read access for
  any account lacking `legal-hold-bypass`, and that forbiddance is applied at cache-fill
  time, so held data is not smuggled into the offline store. A label that becomes a hold
  while a record is already cached is reconciled on next sync (the record is evicted from
  the cache when the server reports it now forbidden).
- **Writes re-check on replay.** A queued write is NOT trusted because it was authored
  by an entitled user offline. On replay the server runs the full access pipeline again
  (`AccessChecker` entity-level, `FieldAccessPolicyInterface` field-level). If
  entitlement changed while the user was offline (role revoked, label raised), the write
  is rejected, the `sync_queue_entry` moves to `rejected`, and the user is told. There is
  no offline backdoor around the access pipeline (charter DIR-A005).
- **Audit expectations.** Every offline operation produces an `audit_event` on
  reconcile, carrying the `offline_at` timestamp so the unified OCAP audit log preserves
  temporal ordering. Cache fills (reads served offline) are recorded per the audit log's
  read-event policy; queued writes are recorded as their underlying entity-lifecycle
  events with the offline marker. The audit log is append-only
  (`AppendOnlyAuditDatabase`); offline reconciliation never rewrites history, it appends
  the reconstructed sequence.

## Offline-first behavior

This section is the contract. Every surface above MUST meet it; this baseline provides
the machinery so each surface does not re-implement it.

### Offline read

A surface MUST be able to render any record in the user's classification scope from the
local cache while disconnected. The service worker (Workbox) serves the app shell and
static assets cache-first; entity reads fall back to the Dexie `entities` store when the
network is unavailable. A cache-served read shows the stale-read banner with its
`last_synced_at`. Read-after-write within the user's own scope works offline: a value
the user just queued reads back from the `outbox` overlaid on the cached entity, so the
UI reflects the user's intent immediately.

### Queued write

A write composed offline is appended to the Dexie `outbox` and mirrored to a
`sync_queue_entry` (state `queued`) with its `offline_at` timestamp and, where the
surface read a revision, its `expected_revision_id`. Writes never block on connectivity.
Two queue strategies, per charter DIR-A002:

- **Multi-submission-merge (DEFAULT for governed community data).** Every submission is
  a record, never overwritten. For form-style and community-record surfaces, two offline
  submissions of "the same" thing produce two governed records; the system never silently
  collapses one onto the other. This is the governance posture default.
- **LWW / last-write-wins (opt-in `classification-flag`).** Available only where
  latest-is-canonical is genuinely correct, for example a single administrator updating a
  config record. LWW is opt-in per surface and per record class, never the global default.

### Sync state machine (FSM)

The sync engine is a finite-state machine, one instance per `(nation_short, account)`:

```
  idle ──reconnect──> authenticating ──token ok──> draining
   ^                       │                          │
   │                  token expired                   │ (per entry)
   │                       v                          v
   └──── all drained ── conflict-review <── conflict  apply ──ok──> next
                            │                              │
                       resolved/abandoned           rejected (access)
```

- `idle` while offline or with an empty outbox.
- `authenticating` on reconnect: re-auth-on-reconnect is mandatory (DIR-A002). A cached
  token with expired explicit expiry forces re-auth before any drain.
- `draining` replays `outbox` entries oldest-`offline_at`-first, each threaded through
  `SaveContext` with its `expected_revision_id`.
- `apply` calls `EntityRepository::save()` (or `saveTranslation()` for the translation
  axis). Success advances the `sync_queue_entry` to `applied` and clears the outbox row.
- `conflict-review` is entered when a replay returns the framework's `revision_conflict`
  (the server head moved past the queued `expected_revision_id`). This is exactly the
  optimistic-locking contract: the surface translates, never re-implements, the
  conflict. The entry moves to `conflicted` and the conflict resolution view is offered.
- `rejected` when the access pipeline now forbids the write (entitlement changed offline).

### Conflict handling

Conflict handling is anchored to the framework's revision and optimistic-locking model,
not invented here:

- The queued `expected_revision_id` IS the version token. On replay, the framework's
  two-stage check (fail-fast pre-check, then the guarded pointer-claim UPDATE inside the
  save transaction) decides the race. Exactly one writer wins per stated expectation.
- A losing write surfaces the framework's `revision_conflict` payload (the same shape the
  REST layer maps to HTTP 409 `REVISION_CONFLICT`). The sync engine does NOT auto-retry a
  conflict (auto-retry would clobber the winner). It moves to conflict-review and asks the
  user to re-read the current head and reapply their change.
- Disjoint-field merge is preserved: where the server's optimistic-locking path already
  merges non-overlapping field edits, a queued write touching different fields than the
  winning concurrent write applies cleanly without a user-visible conflict.
- Two-axis caveat (inherited from the framework): the optimistic-locking guard is
  currently single-axis. Translatable entities do not yet support an `expected_revision_id`
  expectation, so for two-axis surfaces the baseline falls back to multi-submission-merge
  (governance default) and flags any administrative-LWW request as unsupported until the
  framework's langcode-scoped guard lands. See Open questions.

## Accessibility

This baseline meets AODA Level AA per charter DIR-A001; specifics for the shared offline
affordances follow. Full requirements live in the AODA baseline (see
[AODA Accessibility Baseline](aoda-accessibility-baseline.md)); this surface only adds
its offline-specific obligations.

- **Connection indicator** is an `aria-live` region. Transition to offline or sync-error
  is announced `polite` (a soft, expected state change), while a hard sync-rejection (an
  access denial on replay) is announced `assertive`, consistent with the DIR-A001 rule
  that hard denials use `aria-live="assertive"`. State is never conveyed by color alone:
  each state has a text label and a distinct icon shape.
- **Queued-writes tray** is keyboard navigable, each entry a focusable item with an
  accessible name combining the entity label, the operation, and the relative
  `offline_at` time. State changes (queued to syncing to done) are announced politely.
- **Conflict resolution view** uses focus management: opening it moves focus to a heading
  that names the conflict, the two values are presented as a labeled comparison (not a
  color-only diff), and the reapply and discard actions are clearly labeled buttons, not
  icon-only controls.
- **Stale-read banner** is perceivable without color (icon plus text) and does not trap
  focus or interrupt the reading flow.
- No placeholder-only labels anywhere in these components (DIR-A001): every control has a
  visible, persistent label. The axe-core CI gate and per-component Vitest plus Playwright
  tests required by DIR-A001 apply to all baseline components.

## Indigenous-language and translation

All UI copy this baseline introduces (connection states, tray labels, conflict view,
stale-read banner, re-auth prompt) flows through the DIR-A003 translation pipeline. No
string is hard-coded in a component.

- Every user-facing string is a `translation_string` entity key, extracted by the
  pipeline's extraction tooling, not a literal in the Vue source. Because the
  `translation_string` entity mirrors the framework two-axis storage shape, the offline
  cache can hold the active locale's UI strings so the workspace renders in
  Anishinaabemowin (or any enrolled language) even while disconnected.
- No Anishinaabemowin text enters the codebase without language-keeper review (DIR-A003,
  absolute). The English source strings for these components are authored here; their
  translations pass through the `translation_review` workflow and the language-keeper gate
  before shipping. The working name "Anokii" itself remains pending language-keeper
  verification.
- Content (the cached entities, not just the chrome) is translatable on the two-axis
  revision model: a member who works in Anishinaabemowin offline reads and queues edits
  against the `oji` langcode peer row, with independent per-language revision sequencing
  preserved through the sync engine (editing the `oji` translation offline does not bump
  the English revision count on replay).
- `offline_at` and sync-state timestamps are localized for display through the
  framework's i18n layer; the stored values stay ISO-8601 / UTC for faithful audit
  reconciliation.

## Framework primitives used

- `waaseyaa/entity` and `waaseyaa/entity-storage`: entity system, `EntityTypeManager`,
  `EntityRepository`, `SaveContext` (`withExpectedRevisionId`, `withLangcode`,
  `withTranslations`, `withActorUid`); `docs/specs/entity-system.md`.
- Revision system (unified, with optional translation axis): `<entity>_revision`,
  `<entity>__translation__revision` `(entity_id, langcode, revision_id)`, optimistic
  locking and `RevisionConflictException`; `docs/specs/revision-system-unified.md`
  (live canonical), `docs/specs/entity-storage-two-axis.md` (superseded, historical).
- `waaseyaa/access`: `AccessChecker`, `FieldAccessPolicyInterface`, `AccessResult`
  (Neutral / Forbidden field semantics); `docs/specs/access-control.md`,
  `docs/specs/field-access.md`.
- `waaseyaa/field` classification engine: `ClassificationFieldAccessPolicy`, hold
  labels, clearance gate; `docs/specs/classification-and-retention.md`.
- `waaseyaa/audit`: `audit_event`, `AppendOnlyAuditDatabase`, `actor_uid` three-state
  actor, `offline_at` attribute; `docs/specs/ocap-audit-log.md`.
- `waaseyaa/api`: `FieldAutoSaveController` (`PUT /api/{entityType}/{id}/field/{key}`)
  as the online replay target for field writes, 409 `REVISION_CONFLICT` mapping;
  `docs/specs/work-surface.md`, `docs/specs/api-layer.md`.
- `waaseyaa/admin`: Nuxt 3 plus Vue 3 surface conventions, `--color-primary` brand
  token, where the shared offline components live; `docs/specs/admin-spa.md`.
- `waaseyaa/i18n` and the Anokii `translation_string` pipeline (DIR-A003) for all UI copy
  and translatable content.
- Dexie (IndexedDB) and Workbox (service worker) as the client-side substrate named in
  charter DIR-A002 (third-party, GPL-compatible review required per DIR-A004 before
  vendoring).

## Open questions

- **Two-axis optimistic locking.** The framework's `expected_revision_id` guard is
  single-axis only; translatable entities reject a stated expectation today. Until the
  framework's langcode-scoped guard (the documented lift path) lands, two-axis surfaces
  fall back to multi-submission-merge. Should Anokii file a framework mission to prioritize
  the langcode-scoped guard, or is merge-by-default acceptable for v0.1?
- **Cache eviction policy.** What is the budget and eviction strategy for IndexedDB under
  storage pressure (LRU per `(nation_short, entity_type)`, a hard per-Nation quota, or
  user-configurable)? Eviction must never drop an unsynced `outbox` entry.
- **Cross-Nation community caching.** The baseline resolves cross-Nation `community` reads
  live rather than caching them. Is there a federated-partner case where a partner Nation's
  `community` data SHOULD be cached offline, and if so, who consents to that on the owning
  Nation's behalf (OCAP)?
- **Token expiry window.** DIR-A002 requires explicit token expiry and re-auth-on-reconnect.
  What is the offline grace window before a cached token is considered too stale to permit
  any offline read at all (versus permitting read but forcing re-auth before write)?
- **Sync ordering across devices.** A member offline on two devices can queue conflicting
  writes independently. The per-`(nation, account)` FSM reconciles per device; the
  cross-device race resolves through the same optimistic-locking head. Does the
  queued-writes tray need to surface "another device of yours also has a pending write"?
- **`offline_at` trust.** The client supplies `offline_at` from device time, which can be
  wrong or adversarial. Does the server clamp or sanity-check it against `synced_at`, and
  how is a clearly-skewed device clock surfaced without rejecting legitimate offline work?
- **Held-record eviction signal.** When a cached record becomes `hold-*` server-side, the
  baseline evicts it on next sync. Is a more proactive signal needed (a push on reconnect)
  so held data does not linger in a long-offline cache longer than a Nation finds acceptable?
