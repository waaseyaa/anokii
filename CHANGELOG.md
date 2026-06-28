# Changelog

All notable changes to Anokii will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [0.1.0-alpha.11] - 2026-06-27

The login-gated workspace is now the out-of-box distribution baseline. Previously every
consuming app re-implemented it; the generic workspace now ships in the package and an instance
gets it by registering one provider. The sovereign/shared-graph tenancy-tier split is retired in
favour of module-driven surfaces, and the lean standalone `/admin/anokii` graph-counts admin is
removed. Proven on a fresh instance (NorthOps) on the `waaseyaa/full ^0.1.0-alpha.250` floor
before tagging. Design: `docs/specs/anokii-workspace-extraction.md`.

### Added

- `Anokii\Provider\WorkspaceServiceProvider`: mounts the login-gated workspace at
  `/admin/anokii/*` — the shell (dashboard, settings, login/logout/one-time set-password via the
  shared `WorkspaceLoginController`) and the baseline tools: Identity, Documents, Drive, Pages,
  Inbox, and the canonical first-party Analytics. Also mounts the public cookieless analytics
  collector at `POST /api/collect`. Routes register `allowAll()` and each controller enforces the
  session via `DashboardGate`/`Auth`; route priority 100 beats the admin SPA catch-all.
- `Anokii\Access\WorkspaceRoles`: the canonical administrator/editor/viewer role model (subclass
  of `AbstractWorkspaceRoles`) plus a `handler()` factory composing the six baseline entity access
  policies.
- Six entity types and their access policies, extracted generic from the fnpi app:
  `identity_pillar` (translatable, two-axis), `document`, `document_note`, `drive_asset`, `page`,
  `contact_submission` (`Anokii\Entity\*`, `Anokii\Access\*`).
- The tool services and controllers under `Anokii\Workspace\*` (Identity/Documents/Drive/Pages/
  Inbox/Analytics) and their Twig templates under `templates/anokii/*`, all rendering through the
  shared `_shell.html.twig`.
- `Anokii\Workspace\WorkspaceShell`: drives the dashboard nav and tiles from the shared
  `AdminModules` catalog.

### Changed

- Framework floor raised to `waaseyaa/full ^0.1.0-alpha.250`.
- `CoIntelligenceServiceProvider`: the public graph-chat surface (`POST /api/chat`) now mounts
  purely on the `public-graph-chat` module being enabled; the sovereign/shared-graph tenancy-tier
  branching is removed (surfaces are module-driven).

### Removed

- `Anokii\Controller\AnokiiAdminController` (the lean standalone `/admin/anokii` graph-counts
  admin) — superseded by the login-gated workspace dashboard.

### Deferred

- The stateful gated Co-Intelligence chat surface (conversations + confirm-before-apply proposals)
  remains app-provided in fnpi until it is extracted in alpha.12.

## [0.1.0-alpha.3] - 2026-06-22

Entity validation fix found during the rhtcircle adoption. The graph entities'
optional fields are now explicitly `required: false`.

### Fixed

- Graph entity optional fields (`Place.lat/lng/travel_note`, `Community.located_at/region`, `Organization.source_url`, `Service.provided_by/located_at/has_topic/source_url`, `Project.relates_to/located_at/has_topic/source_url`, `Topic.keywords`, `DocChunk.heading/entity_type/entity_id`) now declare `required: false`. Without it, a non-nullable typed property registered through `EntityType::fromClass()` is inferred as required (`$attribute->required ?? !$isNullable`), so a legitimately empty value (a doc_chunk intro with no heading, a place with no coordinates yet, a province-wide service with no place) failed `NotBlank` at save. Required fields (`name`, `slug`; `DocChunk.chunk_key/source_url/title/text`) are unchanged.

## [0.1.0-alpha.2] - 2026-06-22

Consolidates the Co-Intelligence engine and the public graph-chat surface into the package, so all three consuming installs (oiatc, fnpi, rhtcircle) draw one engine instead of each carrying its own copy. Previously the engine lived only in the app repos (the freshest version in fnpi-waaseyaa, the public geography-graph version in oiatc-waaseyaa). Design: `docs/specs/anokii-product-architecture.md`. Framework floor unchanged (`waaseyaa/full ^0.1.0-alpha.209`, which ships the `Waaseyaa\AI\Agent\Provider\*` primitives this engine binds to). Apps adopt in Phase B against a parity checklist; this release does not modify any app.

### Added

- The canonical Co-Intelligence engine (`Anokii\CoIntelligence\`): `Passage`, `RetrieverInterface` + `GraphRetriever` (the geography-and-relationship-aware keyword scorer; a single-vantage sovereign install reduces to flat keyword retrieval with no code change), `TopicVocabulary`, `ChatPromptBuilder` (grounded, cited, clear-refusal contract, server-side em/en-dash sanitization) driven by a new `ChatVoice` value object so the install's identity and refusal text are configuration, not hardcoded, `ChatQueryLogInterface` + `SqliteChatQueryLog` + `ChatQueryLogSchema` (the no-PII content-gap log: question, vantage, outcome, topic, and cited source URLs only, owned by the package so it does not depend on any app analytics schema), and `RateLimiterInterface` + `SqliteRateLimiter`.
- The package-canonical relational graph entity model (`Anokii\Entity\`): `GraphEntityBase`, `Community`, `Place`, `Organization`, `Service`, `Project`, `Topic`, and `DocChunk`, declared with `#[ContentEntityType]` / `#[ContentEntityKeys]` / `#[Field]` attributes. doc_chunk is an entity (not a raw table) so retrieval uses the graph and geography and so classification and revisions apply uniformly.
- The public graph-chat surface: `Anokii\Controller\PublicChatController` (stateless SSE `POST /api/chat`, vantage-aware, grounded and cited, deterministic refusal, rate-limited, no-PII log), with the vantage list, default vantage, and model id supplied per install.
- The lean admin surface: `Anokii\Controller\AnokiiAdminController` at `/admin/anokii` (graph entity counts and the no-PII chat-log review for the content-gap loop), gated in production by the host basic_auth on `/admin/*`.
- The config-driven provider `Anokii\Provider\CoIntelligenceServiceProvider`: registers the graph entity types from their attributes (a package's entities are not auto-discovered from an app's `src/`), rebinds the LLM provider to `AnthropicProvider` from the server-side key (framework `NullLlmProvider` stays the default when no key is set; the provider is never forked), and, in the `shared-graph` tier (or when the `public-graph-chat` module is enabled), mounts `POST /api/chat` and `GET /admin/anokii` (the latter at route priority 100 so it beats the framework admin SPA catch-all). Agent writes stay OFF: the public surface is read-only grounded RAG.
- Module vocabulary extended in `config/anokii.yaml.example`: `public-graph-chat` and `anokii-admin` (shared-graph surfaces) and `cointelligence-workspace` (the canonical singular id for the sovereign gated chat, reconciling the WP04 draft name `cointelligence-workspaces`). A new optional `chat:` block configures the default vantage, the selectable vantages, and the `ChatVoice` (intro, refusal, per-vantage refusals).
- `docs/specs/anokii-product-architecture.md`: the one-engine / two-surface-family architecture, the package-vs-app split, the config-driven surface selection, the per-app config matrix, and the graph entity model.

### Notes

- Deferred to a later increment (tracked by the Phase B parity checklists): the public `/anokii` shell + vantage-lens templates and the site-wide launcher markup (apps mount the launcher into their own shells today), the sovereign workspace stateful chat controller (conversations and confirm-before-apply proposals, which remain app-provided in fnpi until extracted), and the install-specific ingest and seed command bodies (each app declares its own content sources).

## [0.1.0-alpha.1] - 2026-06-14

First tagged release of the Anokii distribution. Instances can now pin a version instead of tracking `dev-main`.

### Added

- Shared shell bases (`src/`) that every instance previously re-derived, now subclassable instead of copy-pasted: `Anokii\Support\Auth` (session auth helper), `Anokii\Shell\Shell` with `templates/anokii/_shell.html.twig` and `_dashboard_grid.html.twig` (token-driven shell chrome and dashboard grid, brand supplied per instance via CSS vars and slots), `Anokii\Dashboard\DashboardGate` (public-open / dashboard-login gated split with app-owned login redirect for pages and 401 for JSON), `Anokii\Access\AbstractWorkspaceRoles` (role and access model implementing the framework `ProvidesRolesInterface` so `user:assign-role` discovers an app's roles, replacing the per-app role-command hacks), `Anokii\Access\AbstractEntityAccessPolicy` (per-entity access policy with open-by-default field access), and `Anokii\Seed\AbstractSeeder` (idempotent seeder base). Correctness fix over the source instances: `apply()` returns the updated `User` because Waaseyaa `User` setters are immutable. Documented in `docs/specs/shared-shell.md`.
- Framework floor raised to `waaseyaa/full ^0.1.0-alpha.209` (from the alpha.188 scaffold pin). That release ships the `ProvidesRolesInterface` capability and the first-class `user:assign-role` command that `AbstractWorkspaceRoles` builds on.
- Distribution config switch (WP04): `config/anokii.yaml.example` selects between the two tenancy tiers (`sovereign` single owned-and-hosted Anokii per Nation, and `shared-graph` one install serving many communities as vantage views over a shared public graph), carries a safe-by-default `data_residency` posture block (ownership, default_classification, cross_tenant_reads), and gates the WP04 surfaces via `modules.enabled` / `modules.preview`.
- First Anokii product code: typed resolver `Anokii\Config\DistributionConfig` (`src/Config/DistributionConfig.php`) plus the `Anokii\Config\TenancyMode` enum (`Sovereign`, `SharedGraph`), exposing `tenancyMode()`, `dataResidency()`, `moduleEnabled()`, `modulePreview()` with most-protective defaults (missing mode = sovereign, unknown module = disabled, sovereign never reads cross-tenant). PSR-4 autoload `Anokii\` -> `src/` and `Anokii\Tests\` -> `tests/` wired in `composer.json`; `symfony/yaml` declared.
- Unit test `tests/Config/DistributionConfigTest.php` asserting the safe-by-default resolution rules and both tiers (runs once a `vendor/` is present).
- WP04 draft specs: `docs/specs/distribution-config.md` documenting the switch, the two tiers, the data-residency postures, the module map, and worked Sagamok (sovereign) and OIATC-style (shared-graph) examples.
- `config/tenants/sagamok.yaml.example` extended with per-tenant tier settings (`tenancy_mode: sovereign` plus the matching `data_residency` block) consistent with the new switch.
- Initial repo scaffold: `composer.json` (requires `waaseyaa/full ^0.1.0-alpha.188`), `LICENSE.txt` (GPL-2.0-or-later), `README.md` (~3.5 KB distribution overview), `.gitignore`.
- `spec-kitty init` -- `.kittify/` project scaffold with Claude Code agent configuration.
- `.kittify/charter/charter.md` -- Anokii distribution charter codifying DIR-A001 (AODA Level AA), DIR-A002 (offline-first), DIR-A003 (Indigenous-language pipeline), DIR-A004 (GPL-2.0-or-later trajectory), DIR-A005 (OCAP product-surface commitments).
- `deploy.php` -- Deployer overlay inheriting from `vendor/waaseyaa/deployer/recipes/waaseyaa.php`. Adds Nation-scoped storage bucket naming, classification policy seed task (`anokii:seed:classification`), and Nation tenant bootstrap task (`anokii:tenant:bootstrap`).
- `assets/theme/anokii-tokens.css` -- Branded UX baseline: deep-teal palette (`#0d4f4f`, `#0f766e`, `#14b8a6`) as CSS custom properties with `--color-primary` alias.
- `config/classification.anokii-default.yaml` -- Default three-tier classification taxonomy seed (public / community / nation-restricted) with FieldAccessPolicyInterface-aligned field-access semantics.
- `config/tenants/sagamok.yaml.example` -- Example Nation tenant config stub for Sagamok Anishnawbek First Nation.

Mission: [anokii-distribution-scaffold-01KSEFT7](https://github.com/waaseyaa/framework/tree/main/kitty-specs/anokii-distribution-scaffold-01KSEFT7)
