# Changelog

All notable changes to Anokii will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- Initial repo scaffold: `composer.json` (requires `waaseyaa/full ^0.1.0-alpha.188`), `LICENSE.txt` (GPL-2.0-or-later), `README.md` (~3.5 KB distribution overview), `.gitignore`.
- `spec-kitty init` — `.kittify/` project scaffold with Claude Code agent configuration.
- `.kittify/charter/charter.md` — Anokii distribution charter codifying DIR-A001 (AODA Level AA), DIR-A002 (offline-first), DIR-A003 (Indigenous-language pipeline), DIR-A004 (GPL-2.0-or-later trajectory), DIR-A005 (OCAP product-surface commitments).
- `deploy.php` — Deployer overlay inheriting from `vendor/waaseyaa/deployer/recipes/waaseyaa.php`. Adds Nation-scoped storage bucket naming, classification policy seed task (`anokii:seed:classification`), and Nation tenant bootstrap task (`anokii:tenant:bootstrap`).
- `assets/theme/anokii-tokens.css` — Branded UX baseline: deep-teal palette (`#0d4f4f`, `#0f766e`, `#14b8a6`) as CSS custom properties with `--color-primary` alias.
- `config/classification.anokii-default.yaml` — Default three-tier classification taxonomy seed (public / community / nation-restricted) with FieldAccessPolicyInterface-aligned field-access semantics.
- `config/tenants/sagamok.yaml.example` — Example Nation tenant config stub for Sagamok Anishnawbek First Nation.

Mission: [anokii-distribution-scaffold-01KSEFT7](https://github.com/waaseyaa/framework/tree/main/kitty-specs/anokii-distribution-scaffold-01KSEFT7)
