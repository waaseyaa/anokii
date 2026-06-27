# Anokii Workspace Extraction & Unification — Spec

Status: DRAFT for build approval. No code changed yet.
Companion to [anokii-product-architecture.md](anokii-product-architecture.md) (which this supersedes
on the tier question — see §3).

Decision inputs (Russell, 2026-06-27), all incorporated below:
- Shared-graph **tier** is retired; surfaces are chosen by **modules**, not tier.
- Public chat ships as a **default module** of anokii (available out of the box). rhtcircle keeps it
  enabled; other instances toggle it on.
- **oiatc does NOT depend on public chat.** Its graph chat is currently **broken** (chat was moved to
  rhtcircle and cleanup was never finished). oiatc is being repositioned as a **showcase site**;
  its migration **retires** the broken chat. The showcase rebuild is a **separate downstream task**,
  noted here, not built here.
- Analytics: **one canonical schema (Option A)**; **no history migration** — reset is fine.
- Auth baseline: **multi-member workspace** (`WorkspaceLoginController` + roles).
- Framework floor for the new anokii: **alpha.250**, proven on NorthOps first (**alpha.249** fallback).
- alpha.11 ships the **workspace (5 tools + RAG + generic analytics)**; the **stateful gated
  Co-Intelligence chat is deferred to alpha.12**.
- **NorthOps proof gates the alpha.11 tag** — order is extract → tests → prove on NorthOps → tag
  alpha.11 → fnpi → oiatc → rhtcircle → docs.
- **fnpi has no live users**; do NOT add complexity to preserve its gated Co-Intelligence during
  migration. Gated Co-Intelligence may be **temporarily unavailable on fnpi between alpha.11 and
  alpha.12**, restored when it is extracted at alpha.12. fnpi's Ventures, content, and branding stay.
- **DB-backup guardrail (hard)** — see §Guardrails.

---

## Guardrails (hard — apply to every step below)

1. **Spec-first.** No code changes until the §11 build sequence is approved.
2. **DB backup before any mutation.** A fresh database backup is taken for an instance **before
   touching it**, and **again immediately before every deploy**. No schema change, `db:init`,
   migration, or deploy runs without a fresh backup taken first. Mechanism per instance:
   `sqlite3 storage/waaseyaa.sqlite "VACUUM INTO 'storage/db-backups/waaseyaa-<UTC-timestamp>.sqlite'"`
   (the Pi deploy already takes a `VACUUM INTO …pretx-*.bak` pre-migration snapshot; this guardrail
   additionally requires a manual backup before *local* schema work). The backup path is recorded in
   the step's notes so rollback is one copy-back.
3. **NorthOps is the proving ground.** The workspace must run green on NorthOps before any live
   consumer is touched.
4. **Live consumers migrated LAST, one at a time** — each on its own branch, fresh DB backup, parity
   check, own verify, own deploy, each reversible via its `*_REF` pin. Never batched.

---

## 0. Goal

Make the **rich login-gated workspace the single out-of-box baseline** of `waaseyaa/anokii`, driven by
**per-instance module toggles** (not a tenancy-tier split), so NorthOps, fnpi, oiatc, and rhtcircle all
run one correct anokii and no instance re-implements the workspace.

---

## 1. State of each consumer (recon-confirmed, with the oiatc correction)

| Consumer | anokii | framework | Runs today |
|---|---|---|---|
| fnpi (fnprocure.ca) | ^alpha.9 | alpha.247 | Sovereign workspace, **hand-coded in the app** (Identity/Documents/Drive/Pages/Inbox/Co-Intel + app-only Ventures/Analytics) |
| oiatc (oiatc.ca) | ^alpha.9 | alpha.247 | **Graph chat BROKEN** (moved to rhtcircle, cleanup unfinished). Thin gated admin + analytics. Being repositioned as a **showcase** site (separate task). |
| rhtcircle | ^alpha.10 | alpha.249 | **Live public-chat consumer**: `/api/chat` launcher + thin gated admin (counts + question log + contact) |
| northops (new) | alpha.9 (pinned) | alpha.247 | Paused, clean skeleton — the proving ground |

**Already in the distribution (the bones):** `Dashboard\DashboardGate`, `Shell\Shell` +
`templates/anokii/_shell.html.twig` + `_dashboard_grid.html.twig`, `Support\Auth`,
`Access\AbstractWorkspaceRoles`, `Access\AdminRoles`, `Access\AbstractEntityAccessPolicy`,
`Seed\AbstractSeeder`, `Auth\SetupTokenRepository/Schema`,
`Admin\{AdminShell,AdminModules,AdminTemplates,AdminData,CreateAdminHandler,InviteHandler}`,
`Dashboard\WorkspaceLoginController` + `Dashboard\AdminLoginController` (concrete but **not wired by any provider**),
the Co-Intelligence engine, and the graph entities.

**Missing from the distribution (lives only in fnpi, the donor):** the concrete workspace **tool
controllers**, their **entity types**, **access policies**, **tool templates**, and a **provider
that mounts the workspace routes**.

---

## 2. Target architecture

```
waaseyaa/waaseyaa skeleton  (public/index.php, bin, .env, config, App\ providers)
        + require waaseyaa/anokii        (the distribution — now ships the workspace)
        + register providers:
              Anokii\Provider\WorkspaceServiceProvider      (NEW — mounts the gated workspace)
              Anokii\Provider\CoIntelligenceServiceProvider (existing — chat engine; public chat is now a default module)
        + config/anokii.yaml -> modules.enabled chooses which surfaces appear
        + App\ provides ONLY app-specific: branding, content/seed, and bespoke tools
```

**Surface selection is module-driven (tier split deleted). Public chat ships default-available:**

| Module id | Surface | NorthOps | fnpi | oiatc | rhtcircle |
|---|---|---|---|---|---|
| `dashboard` | workspace home | ✓ | ✓ | ✓ | ✓ |
| `identity` | Identity pillars | ✓ | ✓ | – | – |
| `documents` | Documents + notes | ✓ | ✓ | – | – |
| `drive` | Governed Drive | ✓ | ✓ | – | – |
| `pages` | Pages editor | ✓ | ✓ | optional | optional |
| `inbox` | Contact submissions | ✓ | ✓ | ✓ | ✓ |
| `analytics` | First-party analytics (canonical) | ✓ | ✓ | ✓ | ✓ |
| `cointelligence` | Gated RAG chat (workspace) | optional | ✓ | – | – |
| `public-graph-chat` | Public `/api/chat` + lenses (**default module, off unless enabled**) | – | – | **retired** | ✓ |

App-only surfaces stay in the app and register themselves (fnpi `Ventures`/`Venture` tracker).
oiatc's showcase content/design is **out of scope** for this extraction (see §8.3).

---

## 3. Tier retirement (supersedes architecture spec §4.1)

- **RETIRE** the `tenancy_mode: sovereign | shared-graph` *tier split* and the standalone lean
  `Anokii\Controller\AnokiiAdminController` (graph-counts page).
- **REPLACE** with one workspace baseline; surfaces chosen by `modules.enabled` (mechanism already
  exists: `AdminModules::resolve()`).
- **PRESERVE** public graph-chat (`PublicChatController`, `POST /api/chat`) as a **reusable default
  module** (`public-graph-chat`) shipped with anokii and toggled per instance. rhtcircle keeps it on.
- `DistributionConfig` is reduced to module/posture config; the `TenancyMode` enum is removed or
  demoted to a data-residency label (§10).

---

## 4. File-by-file extraction (donor: fnpi → destination: anokii `src/`)

Donor paths under `C:\Users\jones\Local Sites\fnpi-waaseyaa\`. Each move **genericizes**: strip FNPI
branding/content, replace app constants with config, keep mechanics.

### 4.1 New provider (the missing piece)
- **NEW** `Anokii\Provider\WorkspaceServiceProvider`
  - Models on fnpi `src/Provider/AnokiiServiceProvider.php` (the ~35-route wiring), minus FNPI specifics.
  - `register()`: register the workspace entity types + access policies (4.2); bind tool services.
  - `routes()`: mount login (`WorkspaceLoginController`), logout, set-password, dashboard home,
    settings, and **each enabled tool's routes**, gated through `DashboardGate`. Route priority 100
    (beats the admin SPA catch-all), matching fnpi. Each surface gated by
    `DistributionConfig::moduleEnabled()`.

### 4.2 Tools (controllers + entities + policies + templates)

| Tool | Donor controller | Donor entity(ies) | Donor policy | Donor template(s) | Genericization notes |
|---|---|---|---|---|---|
| Dashboard/Settings | `AnokiiController.php` | – | – | `anokii/home.html.twig`, `settings.html.twig` | uses `DashboardGate`+`AnokiiShell`; FNPI nav overrides → config |
| Identity | `IdentityController.php` | `Entity/Pillar.php` (`identity_pillar`, translatable) | `IdentityPillarAccessPolicy.php` | `anokii/identity.html.twig` | section taxonomy (`IdentitySeed::sections()`) → per-app seed/config |
| Documents | `DocumentsController.php` | `Entity/Document.php`, `Entity/DocumentNote.php` | `DocumentAccessPolicy.php`, `DocumentNoteAccessPolicy.php` | `anokii/documents.html.twig`, `document.html.twig` | Gotenberg conversion optional via env (`GOTENBERG_URL`) |
| Drive | `DriveController.php` | `Entity/DriveFile.php` (`drive_asset`) | `DriveFileAccessPolicy.php` | `anokii/drive.html.twig` | storage volume path from config |
| Pages | `PagesController.php` | `Entity/Page.php` (`page`) | `PageAccessPolicy.php` (+`publish` op) | `anokii/pages.html.twig` | page *content* stays app seed (`PageSeedData.php`) |
| Inbox | `ContactInboxController.php` | `Entity/ContactSubmission.php` | `ContactSubmissionAccessPolicy.php` | `anokii/inbox.html.twig` | public submit endpoint stays app-side (form on the marketing site) |
| Co-Intelligence (gated) | `CoIntelligenceController.php` + app `CoIntelligence/ChatPromptBuilder.php` | uses `doc_chunk` (already in pkg) | – | `anokii/cointelligence.html.twig` | engine in pkg; **stateful gated controller deferred to alpha.12** (§6). Prompt *voice* → `ChatVoice` config |
| **Analytics (generic)** | `AnokiiAnalyticsController.php` | analytics tables (non-entity) | – | `anokii/analytics.html.twig` | canonical schema, see 4.4 |

### 4.3 Templates
- Package already ships `templates/anokii/_shell.html.twig` (brand-neutral, CSS-var driven).
- Move the **generic tool templates** from fnpi `templates/anokii/*` into the package, each extending
  `@anokiipkg/_shell.html.twig` directly (today they extend fnpi's `_fnpi_base.html.twig`).
- The app keeps only a thin brand layer (its own `_base` setting CSS vars + nav + logo + login brand).
  **This is the theming seam** — exactly what NorthOps/fnpi differ on.

### 4.4 Analytics — canonical schema (Option A, confirmed; no history migration)
- Define a **canonical first-party analytics schema + collector + dashboard** in anokii (cookieless,
  sovereign SQLite, modeled on fnpi's). One schema, one dashboard, everywhere.
- **No migration of old analytics data.** Each consumer **resets** analytics on adoption — a fresh,
  empty canonical store is acceptable. (Backup guardrail still applies to the whole DB before the
  schema change, but old analytics rows are intentionally not carried forward.)

### 4.5 Retire
- **DELETE** `Anokii\Controller\AnokiiAdminController` (lean graph-counts admin) — superseded by the
  workspace `dashboard` + (later) `cointelligence` admin surfaces.
- **REMOVE** `tenancy_mode` tier branching from `CoIntelligenceServiceProvider::routes()`; gate
  `/api/chat` purely on `modules.enabled: [public-graph-chat]`.

### 4.6 Stays app-specific (NOT extracted)
- fnpi `Venture*` (lanes/gating-facts/snapshots), `VentureController` tracker, their policies/
  templates/seed — bespoke revenue domain.
- All per-app **content/seed** (`PageSeedData`, `IdentitySeed` sections, ingest sources).
- All per-app **branding** (logos, color tokens, fonts, login brand, nav labels).
- **oiatc's showcase content/design** — separate downstream task (§8.3).

---

## 5. Auth model (confirmed: multi-member baseline)
- The workspace baseline ships **`WorkspaceLoginController` + `AbstractWorkspaceRoles`**
  (administrator/editor/viewer; multi-member, invite-link + set-password).
- Single-admin installs just create one admin account — the multi-member flow degrades gracefully to
  one user. `AdminLoginController`/`AdminRoles` remain available but are not the default.

---

## 6. Release plan (anokii)
1. Branch in the anokii repo; land the extraction; cut **`waaseyaa/anokii v0.1.0-alpha.11`** =
   workspace baseline (Dashboard, Identity, Documents, Drive, Pages, Inbox, generic Analytics) + RAG
   engine + public-chat default module; tier retired; lean admin deleted.
2. **Framework floor:** target **`waaseyaa/full ^0.1.0-alpha.250`**; **verify on NorthOps before
   tagging**. If alpha.250 breaks, fall back to **alpha.249** (rhtcircle already runs it) and file the
   alpha.250 bump separately.
3. Tag → Packagist index.
4. CHANGELOG entry documenting the workspace landing + tier retirement (breaking) + lean-admin removal.
5. **alpha.12 (follow-up):** extract the **stateful gated Co-Intelligence chat** (conversations +
   confirm-before-apply proposals) from fnpi — the heaviest, still-app-only piece.

---

## 7. Proving ground: NorthOps (no live consumer touched until this is green)
1. **Backup:** N/A on first run (fresh DB), but take a baseline snapshot once seeded.
2. Point NorthOps at the new anokii (path repo or alpha.11), pin framework to the chosen floor.
3. Register `WorkspaceServiceProvider` + `CoIntelligenceServiceProvider`.
4. `config/anokii.yaml`: enable `dashboard, identity, documents, drive, pages, inbox, analytics`
   (corporate sovereign — no public chat).
5. `db:init` (materializes the extracted entity schemas), seed classification + an admin via
   `CreateAdminHandler` (`user:create` / `user:assign-role`), run via `php -S` or `composer run dev`.
6. Verify: public site loads; `/admin/anokii` login → workspace with the tools; admin login works;
   analytics records a hit.
7. Green = this is the canonical "fresh anokii instance" recipe (replaces the misleading
   `create-project waaseyaa/anokii`).

---

## 8. Consumer migration (live sites — sequenced, each: branch → backup → migrate → parity → verify → backup → deploy)

### 8.1 fnpi (donor; highest overlap)
- Branch. **Backup DB.** Replace app workspace wiring + tool controllers/entities/templates with the
  distribution's; keep `Venture*`, content seed, branding. Net: fnpi gets *smaller*.
- Entity tables already match the donor → **no schema migration expected**; confirm table/field names
  are byte-identical after genericization. Analytics resets (4.4).
- **Co-Intelligence:** fnpi has no live users, so do **not** add migration complexity to keep gated
  Co-Intelligence running. It may be **temporarily unavailable between alpha.11 and alpha.12** and is
  restored when the stateful gated chat is extracted at alpha.12. (The RAG engine and `doc_chunk`
  corpus remain; only the gated workspace chat surface lapses.)
- Parity check vs the live workspace (workspace tools + Ventures + content + branding; gated chat
  expected absent). Verify. **Backup again.** Deploy via `FNPI_REF` bump.

### 8.2 oiatc (retire broken chat; adopt workspace admin)
- Branch. **Backup DB.** Bump to alpha.11 + chosen framework floor.
- **Retire** the broken graph chat (do not preserve `/api/chat` here); drop the deleted lean-admin
  dependency; adopt the workspace `dashboard` + `analytics` + `inbox` modules (analytics resets).
- Public chat module stays **off** for oiatc.
- Parity check (admin + analytics load; no chat). Verify. **Backup again.** Deploy via `OIATC_REF` bump.
- **Out of scope (separate downstream task):** oiatc's repositioning as a *showcase* site — its new
  content and design that point to / showcase anokii implementations. Note only; not built here.

### 8.3 rhtcircle (the live public-chat consumer — protect it)
- Branch. **Backup DB.** Bump alpha.10→alpha.11 and framework 249→ chosen floor.
- Enable `public-graph-chat` module (its existing surface) + workspace admin + analytics (reset).
- Verify the homepage chat launcher (`public/js/rht-anokii-chat.js`) still hits `/api/chat` and the
  gated admin loads. Verify. **Backup again.** Deploy via `RHTCIRCLE_REF` bump.

---

## 9. Risks & rollback
- **rhtcircle is the one live public-chat site** — `/api/chat` must stay behaviorally identical (only
  its gating moves from tier to module). Smoke `/api/chat` before/after.
- **oiatc chat is already broken** — retiring it is cleanup, not a regression.
- **Breaking change to the distribution** (tier retirement, lean-admin deletion). Only oiatc/rhtcircle
  referenced those, and both are migrated in this effort. Cut as a flagged alpha.11.
- **alpha.250 unproven with anokii** — verify on NorthOps first; alpha.249 is the proven fallback.
- **Rollback:** every consumer stays pinned to its current anokii (alpha.9/alpha.10) and keeps its
  pre-deploy DB backup until its migration is verified; revert = restore the previous `*_REF` and, if a
  schema change shipped, copy back the backup `.sqlite`. New anokii alpha.11 is additive on Packagist
  (old tags remain).

---

## 10. Resolved decisions (all previously-open questions)
1. Public chat → **default module**, preserved for rhtcircle, retired for oiatc. ✓
2. Analytics → **canonical schema (A), no history**, reset on adoption. ✓
3. Auth → **multi-member workspace baseline**. ✓
4. Framework floor → **alpha.250**, NorthOps-proven, alpha.249 fallback. ✓
5. Co-Intelligence → workspace + RAG in **alpha.11**; **stateful gated chat deferred to alpha.12**. ✓
6. `TenancyMode` enum: remove vs keep as data-residency label — minor; decide during build (default:
   keep as an inert residency label to avoid churning `DistributionConfig` consumers).

---

## 11. Build sequence (paste-back target; execute only after approval)

1. **anokii branch** (`feat/workspace-extraction`): add `Anokii\Provider\WorkspaceServiceProvider`;
   move the 6 shipping tools (Dashboard/Settings, Identity, Documents, Drive, Pages, Inbox) —
   controllers + entities (`page`, `document`, `document_note`, `drive_asset`, `identity_pillar`,
   `contact_submission`) + access policies + tool templates — into `src/` and `templates/anokii/`;
   add the **canonical Analytics** schema/collector/dashboard; **delete** `AnokiiAdminController`;
   demote the `tenancy_mode` tier to `modules.enabled` and make `public-graph-chat` a default module.
2. **Tests in anokii**: per-tool integration tests + the `DashboardGate` auth split; a config test for
   module enable/disable; analytics collector test. Genericization parity vs fnpi's behavior.
3. **Prove on NorthOps** (§7): wire providers, enable the 7 workspace modules, `db:init`, seed +
   admin, run, verify public + `/admin/anokii` workspace + admin login + analytics hit. Baseline DB
   snapshot once seeded. **This gates the tag — PAUSE and report here before tagging or touching any
   live site.**
4. **alpha.11 tag** on framework floor **alpha.250**, now that the NorthOps proof is green (tag
   reflects the proven floor; drop to alpha.249 if alpha.250 failed on NorthOps).
5. **Migrate fnpi** (§8.1): branch → **DB backup** → swap app workspace for the distribution's (keep
   Ventures/content/branding; gated Co-Intelligence may lapse until alpha.12) → parity check →
   verify → **DB backup** → `FNPI_REF` bump deploy.
6. **Migrate oiatc** (§8.2): branch → **DB backup** → bump anokii/framework → **retire broken chat** →
   adopt workspace admin + analytics (reset) → parity check → verify → **DB backup** → `OIATC_REF`
   bump deploy. (Showcase rebuild deferred to its own task.)
7. **Migrate rhtcircle** (§8.3): branch → **DB backup** → bump anokii/framework → keep
   `public-graph-chat` enabled + workspace admin + analytics (reset) → verify `/api/chat` + admin →
   **DB backup** → `RHTCIRCLE_REF` bump deploy.
8. **Docs**: update [anokii-product-architecture.md](anokii-product-architecture.md) to one-baseline +
   modules (remove tier language); update the anokii README "create an instance" recipe (skeleton +
   require anokii, not `create-project waaseyaa/anokii`).
