# Changelog

All notable changes to Anokii will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

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
