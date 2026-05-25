Generated: 2026-05-25T01:00:00Z

# Anokii Distribution Charter

---

## Preamble

Anokii (working name — Anishinaabemowin verb stem meaning approximately "she/he works"; pending verification and approval by a language keeper before public use) is the first opinionated distribution built on the [Waaseyaa](https://github.com/waaseyaa/framework) framework. Anokii delivers a sovereign workspace platform for First Nations governments and communities — OCAP-by-architecture, offline-first, AODA Level AA, and designed from the ground up for Indigenous-language data sovereignty.

Anokii depends on Waaseyaa via Packagist. The Waaseyaa framework is the substrate: entity system, storage engine, field types, access control, REST/GraphQL API, AI pipeline, SSR rendering, and MCP endpoint. Anokii adds opinionated product surfaces, Nation-scoped configuration conventions, and Indigenous-language tooling on top of this substrate.

This distribution is licensed GPL-2.0-or-later, inheriting from and aligned with Waaseyaa's own license trajectory (framework DIR-008). The Waaseyaa framework charter is the upstream governance document for substrate concerns. This Anokii charter governs product-surface concerns.

---

## Distribution Posture

Anokii **consumes** Waaseyaa. It does not modify Waaseyaa from inside the Anokii repository.

The dependency flows one way: Anokii → Waaseyaa (via Packagist). When Anokii implementers identify a generally-useful improvement that belongs in the framework substrate, they upstream it via a separate framework-targeted mission filed against the Waaseyaa repository — never by patching `vendor/` or forking the framework package.

This posture is the consumer side of framework DIR-004 (Framework vs Distribution Architecture). DIR-004 on the framework side codifies that Waaseyaa must not import from Anokii; this charter codifies that Anokii must not modify Waaseyaa from inside its own repository.

Cross-cutting concerns (e.g., a new framework API needed by an Anokii surface) are coordinated by filing a framework mission first, waiting for that mission to merge and cut a release, then bumping the `waaseyaa/full` constraint in Anokii's `composer.json`.

---

## Governance Activation

Anokii operates under **two concurrent governance documents**:

1. **The Waaseyaa framework charter** — governs substrate concerns: entity system design, storage driver contracts, access-control invariants, license trajectory (DIR-008), framework-vs-distribution architecture (DIR-004), two-axis storage (DIR-005), admin-SPA conventions (DIR-007), and extension-point stability gates (DIR-006).

2. **This Anokii distribution charter** — governs product-surface concerns: accessibility baseline (DIR-A001), offline-first substrate (DIR-A002), Indigenous-language pipeline (DIR-A003), license trajectory alignment (DIR-A004), and OCAP product-surface commitments (DIR-A005).

Amendments to either charter are governed by that charter's own amendment process. Some amendments require coordination across both — for example, a license change requires both a framework-charter amendment (per framework DIR-008) AND an Anokii-charter amendment (per DIR-A004 below). The OIATC stewards channel is the Nation-level governance interface for amendments touching sovereign-data or Indigenous-language commitments.

---

## Anokii Project Directives

### DIR-A001 — AODA Level AA is a design constraint, not an optional feature

Every Anokii v0.1 surface MUST meet WCAG 2.1 Level AA and the AODA-specific procurement-legibility requirements. Accessibility is a design constraint codified at the distribution level — it cannot be deferred to a follow-up sprint or scoped as optional.

Enforcement mechanism: axe-core CI gate runs on every PR touching a product surface. Per-component accessibility tests are required in Vitest + Playwright. A surface that ships without an axe-core baseline is a charter violation, not a quality shortcut.

Bypass policy: bypassing the baseline requires a `charter-exception` record with a mandatory removal date and a rationale explaining why the exception is time-bounded. Exceptions without removal dates are not valid.

Specific requirements beyond WCAG 2.1 AA:
- Access-denied messages use live-region announcements — `aria-live="assertive"` for hard denials (server-side OCAP forbidden), `aria-live="polite"` for soft denials (capability-not-granted in this session).
- Co-Intelligence response surfaces use focus management + progressive announcement.
- All form inputs have visible, persistent labels (no placeholder-only patterns).

### DIR-A002 — Offline-first is a design constraint, not an optional feature

Every Anokii v0.1 surface MUST function in offline-degraded mode. A surface that requires connectivity for read-after-write within the user's own classification scope is a charter violation.

Offline substrate: Dexie (IndexedDB) + Workbox (service worker) + an FSM-based sync engine composing on the framework's two-axis revisions model (`RevisionableStorageDriver` + `(entity_id, langcode, vid)` tuple, which maps cleanly to Dexie composite keys per framework DIR-005).

Identity offline: tokens are cached locally with explicit expiry; re-auth-on-reconnect is required; partial-trust offline operation permits reading the user's own classified data offline but NOT other Nations' cached data. Audit log captures offline operations and syncs on reconnect; offline operations carry an `offline_at` timestamp; the server reconciles on sync.

Offline queue strategy for forms: multi-submission-merge is the DEFAULT for governed community data (governance posture — every submission is a record, never overwritten). LWW (last-write-wins) is available as an opt-in `classification-flag` for administrative forms where latest-is-canonical is correct (e.g., a single admin updating a config record).

### DIR-A003 — Indigenous-language translation pipeline is a product layer, not a configuration toggle

The Anokii Indigenous-language pipeline is a first-class product layer. It is not a configuration option, a plugin, or an afterthought — it is part of the distribution's core architecture.

Pipeline shape: extraction tooling → `translation_string` entity (mirrors framework two-axis storage shape per DIR-005) → contributor dashboard → `translation_review` workflow → glossary entity → per-Nation override layer.

Pilot scope: English ↔ Anishinaabemowin (southern + northern Ojibwe dialects). Initial glossary target: 20–30 terms co-authored with a language keeper.

Pilot Nations: Sagamok Anishnawbek First Nation (Russell's home Nation; OIATC already on Waaseyaa) as the first pilot, Sheguiandah First Nation as the second. Final Nation selection is deferred to the language-keeper engagement moment — no Nation is enrolled without their explicit participation.

No Anishinaabemowin text enters the distribution codebase without language-keeper review. The working name "Anokii" is itself pending language-keeper verification before public use.

### DIR-A004 — GPL-2.0-or-later license trajectory aligned with framework DIR-008

Anokii is GPL-2.0-or-later because Waaseyaa is GPL-2.0-or-later. This is not incidental — it is a deliberate alignment with the framework's license trajectory (framework DIR-008) and with the OCAP principle that sovereign data is not encumbered by proprietary tooling.

Relicensing Anokii requires **both** of the following:
1. A framework-charter amendment changing framework DIR-008 (per the framework charter's amendment process).
2. An Anokii-charter amendment changing this directive (per the amendment process below).

No permissive-licensed code may be imported into the Anokii repository without explicit license-compatibility analysis recorded in a follow-up Anokii mission. Initial dependencies are Waaseyaa-only (already GPL-2.0-or-later).

The OIATC stewards channel is the Nation-level governance interface for license-change amendments, mirroring framework DIR-008's OIATC reference.

### DIR-A005 — Product-surface OCAP-by-architecture commitments inherit framework DIR-004

Every Anokii productivity surface inherits the framework's OCAP access-control wiring. Surface code never bypasses or weakens `AccessChecker` / `FieldAccessPolicyInterface`. There are no "admin backdoors" that skip the access pipeline.

Specific commitments:
- Per-record AI access (the gap-matrix A5 flagship, implemented in the Waaseyaa framework as `per-record-ai-access-flagship-*`) extends through Anokii Co-Intelligence Workspaces verbatim. No Anokii-side code weakens per-record AI access grants.
- The unified OCAP audit log spans every Anokii surface. Offline operations are included; the `offline_at` timestamp on each audit record preserves the temporal ordering for reconciliation.
- Nation-scoped classification taxonomies (shipped in `config/classification.anokii-default.yaml`) define the field-access semantics for cross-Nation reads: `Neutral` for public-tier, `Neutral` for community-tier (Nation members), `Forbidden` for nation-restricted-tier (cross-Nation reads blocked by framework FieldAccessPolicyInterface).

---

## Reference Index

| Reference | Location | Relevance to Anokii |
|---|---|---|
| Framework DIR-004 (Framework vs Distribution Architecture) | Framework charter | Distribution posture — Anokii consumes, never modifies |
| Framework DIR-005 (Two-Axis Storage) | `docs/specs/entity-storage-two-axis.md` | Offline sync substrate; translation pipeline entity shape |
| Framework DIR-006 (Extension-Point Gates) | Framework charter | Admin-centre integration contracts |
| Framework DIR-007 (Admin SPA) | `docs/specs/admin-spa.md` | Nuxt 3 + Vue 3 surface conventions |
| Framework DIR-008 (License Trajectory) | Framework charter | GPL-2.0-or-later alignment (DIR-A004) |
| `docs/specs/access-control.md` | Waaseyaa repo | AccessChecker, FieldAccessPolicyInterface, AccessResult semantics |
| `docs/specs/entity-storage-translatable-revisions.md` | Waaseyaa repo | Translation pipeline entity storage shape |
| `docs/specs/admin-spa.md` | Waaseyaa repo | Admin SPA brand token conventions (`--color-primary`) |
| OIATC stewards channel | Nation governance | License-change and language-pipeline amendments |

---

## Amendment Process

1. **Proposal** — author a written amendment proposal identifying: the directive ID(s) affected, the proposed change text, the rationale, and any required cross-charter coordination (e.g., a license change requires a framework-charter amendment in parallel).
2. **Reviewer alignment** — the proposal is reviewed by Russell Jones and, where the amendment touches Nation-level commitments (DIR-A003, DIR-A004, DIR-A005), by OIATC stewards.
3. **Author confirmation** — once reviewers are aligned, the amendment is confirmed by the charter author (Russell Jones) or a designated steward.
4. **Atomic commit** — the charter is edited in a single atomic commit: the directive text is updated AND a new row is appended to the Amendment History table in the same commit.
5. No amendment may be applied without an Amendment History row. Partial amendments (changing directive text without updating the history table) are invalid.

---

## Exception Policy

A `charter-exception` may be filed when a specific implementation cannot immediately meet a directive constraint. Requirements:

- **Scope**: name the directive (e.g., DIR-A001) and the specific surface or component affected.
- **Rationale**: explain why the exception is needed and why it is time-bounded.
- **Removal date**: a concrete date by which the exception will be resolved. Open-ended exceptions are not valid.
- **Tracking**: the exception is recorded in a follow-up Anokii mission with the `charter-exception` label.

Exceptions to DIR-A003 (language pipeline) that involve shipping Anishinaabemowin text without language-keeper review are not permissible under any circumstances.

---

## Amendment History

| Date | Amendment | Authorization |
|---|---|---|
| _(no amendments yet)_ | | |
