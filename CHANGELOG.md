# Changelog

All notable changes to Anokii will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Security

- Reject Anokii's shipped `AUTH_TOKEN_SECRET` placeholder, leave `.env.example`
  empty, and require an independently owned secret in production, staging, and
  unknown environments. Invalid configured input never becomes a derived or
  ephemeral key, and Framework derived-custody from `WAASEYAA_APP_SECRET` is
  refused for production-like serving (#20). Generic validation and custody
  classification consume `Waaseyaa\Auth\Security\AuthTokenSecret` from published
  Framework `v0.1.0-alpha.297` (tag commit
  `e30775e12b2a45b0d915873e241dc04b0da34bca`; packaged
  `AuthTokenSecret.php` sha256
  `09ba22faeb54afded5aba8beeb2692feac3fada57ed829712a787725916374d8`).
  Fresh installs use `install:init` so CFG-02 genesis is bound before
  production preflight.

- Require and document a separate `AUTH_TOKEN_SECRET` for production HTTP boot.
  An empty `auth.token_secret` no longer bypasses Framework's `app_secret`
  fallback, placeholders are rejected, and production readiness fails closed
  when the secret is missing (#17).

### Fixed

- Select FrankenPHP worker mode only through the explicit
  `WAASEYAA_FRANKENPHP_WORKER=1` process marker, refuse marker/API disagreement,
  and propagate real worker-loop failures instead of retrying them through the
  classic request path (#33).

- Make the canonical PHPUnit configuration own a finite 1G test-process memory
  ceiling, so direct discovery, focused tests, Composer, and hosted Quality no
  longer inherit a host PHP CLI default such as 128M. Production and FrankenPHP
  runtime limits are unchanged (#19).

- Teach `public/index.php` the Framework classic-FrankenPHP fallback so
  `frankenphp_handle_request()` existing outside worker mode cannot 500 every
  request, pin the CLI/HTTP entity-type and provider roster used by field-access
  fingerprints, and add a FrankenPHP worker gate that regenerates the preflight
  on a fully `db:init --sync-schema` database (waaseyaa/framework#2478).

### Changed

- `anokii-operator` drops its one-off host preview router and theme from
  `examples/`, now that the generic demo entry point replaces them.
  `module-preview.html.twig` reads the shell tokens
  `--anokii-shell-display-font` and `--anokii-shell-accent-soft` instead of
  host-specific ones, operator tests use fictional names, and package archives
  leave out `tests/` and `examples/` (#38).

- Govern `anokii-operator` as a development-main split projection and document
  the manual, exact-main alpha release procedure without granting automated tag
  or Packagist authority.

### Added

- `anokii-operator` demo scenarios and shared primitives (#38). The fictional
  example now has a communications desk (public and members-only flows;
  members-only never offers a public or social channel) and a needs-review
  workflow. Both use the three primitives the package now ships, each used by
  both scenarios: a simulation label, a step indicator and a per-target outcome
  list with simulated failure and retry. They are Twig macros plus
  `/anokii-demo/primitives.css` and `/anokii-demo/primitives.js`. Demo
  scenario pages are recorded as development tooling rather than DIR-A001
  product surfaces, and still meet WCAG 2.1 AA through focused markup and
  contrast tests plus documented manual keyboard and responsive checks. Host
  pages that import macros outside a block now fail with a clear error, instead
  of the demo README wrongly saying such imports work.

- `anokii-operator` demo support: `demo/router.php` runs under PHP's built-in
  server and renders the real operator dashboard and module templates from a
  host-supplied fixture file (brand, theme, sample operator, modules, pages and
  assets). It needs no kernel, database or sign-in, and the package's code
  makes no network calls. Fixtures, host templates and their CSS and JavaScript
  are trusted host code. Host module pages contribute only page blocks, so
  their Twig can't replace the nav, the user chip (shown as a sample, with no
  sign-out) or a persistent "Demo: nothing is saved or sent" marker. Responses
  set `connect-src 'none'`, so the browser can't open connections, and the demo
  is not reachable from production routing. See
  `packages/operator/demo/README.md` (#38).

- Add the Framework-owned, exact-commit development-runtime launcher for the
  supported WSL profile, with verified source caching and checkout-bound
  runtime identity (#28).

- `waaseyaa/anokii-core` and `waaseyaa/anokii-identity` source packages, plus an explicit read-only Identity host surface for existing Waaseyaa applications. The surface uses framework route authentication, immutable request principals, entity access checks, community-scoped storage, and package discovery without mounting Anokii's standalone login, public pages, or other workspace tools (#8).
- A production-readiness gate that creates a fresh schema, writes the checksum-bound field-access preflight, and proves guarded production boot in CI. The reviewed schema-derived classifications live in `.waaseyaa/field-access-classification.json`; the generated preflight remains a build artifact.
- CMS page creation, draft, publish, history, and rollback workflows, with public routes derived from published page entities and reserved `/admin` and `/api` paths rejected.
- A permission-gated Identity pillar creation flow for fresh workspaces, with tenant/classification metadata, an initial revision, dynamic section rendering, status editing, and history.
- Versioned migrations for support tables, privacy redaction, and classification seeds, plus complete automated coverage for authorization, privacy, routing, templates, storage, and production boundaries.

### Changed

- The composable operator package now participates in the root PHPUnit,
  PHPStan, and style gates, declares its direct multibyte runtime requirement,
  and is installed as a minimal external consumer in package-boundary CI.
- Root Anokii now consumes the same canonical Pillar entity, policy, and service shipped by `anokii-identity`; the duplicate embedded entity/policy experiment and the separate installation-mode axis were removed (#8).
- The Waaseyaa dependency floor is now alpha.290, including the framework-owned application secret and community isolation contracts; packaged-runtime coverage and operator setup now declare both requirements explicitly.
- All Anokii-owned persistent entities now declare field-read classifications and, in sovereign mode, use the active community as their storage boundary. Graph content intended for public retrieval is explicitly Public; tenant and classification metadata is Protected.
- Drive, Documents, Identity, Pages, Inbox, Analytics, settings, and the shared shell now use the framework's audited account/profile boundaries and canonical community context.
- The workspace shell provides an accessible collapsed mobile navigation with accurate state, Escape handling, and focus return.

### Fixed

- Resolve the read-only Identity host controller through Waaseyaa's supported
  HTTP `GateInterface` authorization seam instead of requiring an unbound
  lower-level access-handler service, and consume the audited immutable
  principal emitted by the real HTTP middleware boundary (#12).
- Align Identity pillar revision logs with the package's documented internal
  field-access contract so host activation preflight is conflict-free (#10).
- Fail closed on cross-community writes and reads, missing active community configuration, invalid upload metadata, unsafe CMS paths, open redirects, login brute force, and silent persistence failures.
- Preserve UUIDs at construction, initialize required tenancy/classification metadata, make Drive uploads and Documents versioning work on fresh storage, and render PDF sources inline without duplicating bytes.
- Remove unconditional browser errors from Drive and Identity, allow failed same-file uploads to be retried, and provide complete password-form autocomplete semantics.
- Keep provider boot free of schema writes, move support schema ownership to migrations, make fresh database initialization idempotent, and restore production field-read activation.

### Security

- Public chat no longer stores raw questions; analytics stores only daily rotating pseudonymous visitor hashes and rejects oversized, malformed, bot, or rate-limited beacons.
- Workspace authorization uses immutable principals and entity policies, sensitive contact data is Protected, uploads are content-sniffed and size-bounded, and operational failures are logged without exposing validation internals to users.

## [0.1.0-alpha.13] - 2026-07-27

Framework ≥ alpha.269 sealed User `roles`/`permissions`/`pass`/`mail` as Internal
(fail-closed field reads, waaseyaa #2064): the entity's own `getRoles()`,
`hasPermission()`, and `checkPassword()` now throw `FieldReadDenied` outside an
audited capability, and `AuthManager` requires the audited
`UserInternalFieldReaderInterface` at construction. Anokii alpha.12 (verified at
alpha.266) crashed at login (`Too few arguments to AuthManager::__construct()`)
and in `app:create-admin` (`Field user.roles requires an explicit audited
capability`) on newer frameworks. This release migrates every internal-field
touchpoint to the audited authority, mirroring the framework's own
`user:assign-role` handler.

### Changed (breaking within the workspace surface)

- **`Support\Auth::login()` takes the audited reader** (`UserInternalFieldReaderInterface`, new required 4th parameter) and constructs `AuthManager` with it; credential verification runs through the framework's capability-scoped snapshot. `Auth::logout()` keeps the framework's exact session teardown via a loud null-object reader (logout never reads internals).
- **`AbstractWorkspaceRoles::apply()` takes the audited reader** (new required 3rd parameter) and merges the CURRENT roles from `maintenanceAuthorization()` instead of the sealed entity; parameter/return loosened to `EntityInterface` (the framework handler's own shape).
- **`AdminLoginController` / `WorkspaceLoginController` constructors take the reader**; the post-login permission gate decides via the new `Access\WorkspacePermissions::allows()` (snapshot semantics mirroring `User::hasPermission()`, `administrator` holds everything).
- **`DashboardGate` constructor gains an optional reader**; `requirePermission()` decides from the snapshot and throws a loud `LogicException` when mounted without one (misconfiguration must not present as a silent 403).
- **`WorkspaceController` password change** verifies the current password via `AuthManager::authenticate()` (audited) instead of the sealed `User::checkPassword()`.
- **`CreateAdminHandler` constructor takes the reader** and threads it to role application.
- **Framework floor raised to `waaseyaa/full ^0.1.0-alpha.276`** — the audited reader surface and `AuthManager` signature require it; alpha.276 also carries the FTS5 schema-introspection boot fix (waaseyaa #2056) that unblocked the portfolio from alpha.250.

### Added

- `Access\WorkspacePermissions` — pure snapshot-based permission decision.
- `Shell::roleLabel()` degrades to the neutral "Member" chip label when the sealed entity cannot answer (cosmetic surface; a follow-up may source the chip from the request principal).
- Test doubles for the audited surface (`FakeInternalFieldReader`, `StubUser`) and coverage for audited role application + snapshot permission gating.

## [0.1.0-alpha.12] - 2026-07-27

Login was broken on every consumer running framework ≥ alpha.254: Anokii's auth
paths still called the deleted `getStorage('user')->loadByKey()` storage seam, and a
blanket `catch (\Throwable)` in `Auth` converted that infrastructure fault into a
false "wrong email or password". Password resets appeared to succeed but accounts
could never be loaded at login. (waaseyaa/anokii#4, PR #5)

### Fixed

- **`Auth::currentUser()` / `Auth::userByEmail()` migrated to the EntityRepository API** (`getRepository('user')` + `find()`/`findBy()`), replacing the legacy `getStorage()`/`loadByKey()` calls the framework removed at alpha.254. All six call sites migrated, including the admin `CreateAdminHandler`/`InviteHandler` commands, `WorkspaceLoginController`, `AbstractSeeder`, and `WorkspaceController`.
- **Auth no longer swallows infrastructure faults.** The blanket `catch (\Throwable) { return null; }` around user lookup is removed: a missing or deleted account still resolves to `null` without throwing, but a genuine storage fault now propagates and is logged instead of presenting to visitors as a failed login.

### Added

- Runnable PHPUnit setup (`phpunit/phpunit` dev dependency + `phpunit.xml.dist`) — the existing suite (42 tests, 90 assertions) previously had no way to run.

### Notes

- Framework constraint stays at `waaseyaa/full ^0.1.0-alpha.250` (caret): anokii is a library and an exact pin would make it uninstallable for consumers still on older floors. The caret resolves cleanly against current framework releases (verified green at alpha.275).

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
