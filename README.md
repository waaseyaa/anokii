# Anokii

**Anokii** (working name — Anishinaabemowin verb stem meaning "she/he works"; pending language-keeper verification before public use) is the first opinionated distribution built on the [Waaseyaa](https://github.com/waaseyaa/framework) framework.

Anokii is a sovereign workspace platform for First Nations — OCAP-by-architecture, offline-first, AODA Level AA, and designed from the ground up for Indigenous-language data sovereignty.

---

## What Anokii is

Anokii delivers a productivity surface cluster for First Nations governance:

- **Governed Drive** — Nation-scoped file storage with OCAP classification
- **Form Builder** — Governed community data capture with offline queue
- **Tasks** — Governance-aware task tracking
- **Data Rooms** — Sensitive-data workspaces with per-record access control
- **Governed Docs** — Collaborative document authoring with conflict resolution
- **Governed Sheets** — Tabular data with classification-aware field access
- **Co-Intelligence Workspaces** — Per-record AI access (OCAP A5 flagship)
- **Admin Centre** — Distribution administration and Nation tenant management

Every surface is:
- **AODA Level AA** — accessibility is a design constraint, not an optional feature
- **Offline-first** — functions in offline-degraded mode via Dexie + Workbox + FSM sync
- **OCAP-by-architecture** — access control flows from the Waaseyaa framework's `AccessChecker` / `FieldAccessPolicyInterface` wiring; no surface bypasses it

---

## Framework vs distribution

Anokii is a **distribution** — it consumes Waaseyaa via Packagist and adds:

- Opinionated entity types and classification taxonomies for First Nations governance
- Deployer recipes and Nation tenant configuration conventions
- Product-surface UI bundles (Nuxt 3 + Vue 3) with the deep-teal brand baseline
- Indigenous-language translation pipeline (English ↔ Anishinaabemowin, piloting with Sagamok Anishnawbek First Nation then Sheguiandah)

Waaseyaa is the substrate — entity system, storage, access control, API, AI pipeline, SSR, MCP endpoint. Anokii **never** modifies Waaseyaa from inside this repo. Generally useful improvements are upstreamed as framework-targeted missions filed against the Waaseyaa repo.

**Framework charter:** [waaseyaa/.kittify/charter/charter.md](https://github.com/waaseyaa/framework/blob/main/.kittify/charter/charter.md)

**Anokii charter:** `.kittify/charter/charter.md` in this repo (added in Wave 1 scaffold).

---

## How we got here

This repo was scaffolded by mission `anokii-distribution-scaffold-01KSEFT7` (Wave 1, parallel to M-A5 in the Waaseyaa framework roadmap). The mission spec lives at `kitty-specs/anokii-distribution-scaffold-01KSEFT7/spec.md` in the Waaseyaa repo.

Wave 1 scope: repo scaffold + composer.json + Anokii charter + deployer recipe baseline + ten artifact draft specs (8 v0.1 surfaces + 2 cross-cutting).

---

## Status

**Alpha — repo scaffold only.** No product code has landed yet. The v0.1 surface missions are being filed against this repo. Watch this space.

Brand palette: Deep Teal (`#0d4f4f → #0f766e → #14b8a6`) — differentiated from Drupal blue, Laravel red, Django/Nuxt green, Strapi purple. Visible once the admin overlay lands.

---

## Install

> **Not yet published to Packagist.** The following command will work once the first release tag is cut.

```bash
composer create-project anokii/anokii my-anokii-site
```

In the meantime, clone this repo directly and run `composer install`.

---

## License

GPL-2.0-or-later. See `LICENSE.txt`.

Anokii is GPL-2.0-or-later because Waaseyaa is GPL-2.0-or-later (framework DIR-008). Relicensing requires both a framework-charter amendment and an Anokii-charter amendment (Anokii DIR-A004).

---

## Working name

"Anokii" is an Anishinaabemowin verb stem meaning approximately "she/he works" or "she/he is working." This working name is pending verification and approval by a language keeper before it is used publicly. The Anishinaabemowin language is spoken by the Anishinaabe peoples, including the Ojibwe Nations of the Great Lakes region.

Pilot Nations: Sagamok Anishnawbek First Nation (Russell's home Nation; OIATC already on Waaseyaa) and Sheguiandah First Nation. Final Nation selection for the language pipeline pilot is deferred to the language-keeper engagement moment.

---

## How to contribute

Issues and contributions will open once the v0.1 surfaces begin landing. In the meantime:

- Framework contributions: [waaseyaa/framework](https://github.com/waaseyaa/framework)
- Anokii surface missions: tracked via Spec Kitty in this repo (`.kittify/`)
- Nation partnerships and language-keeper engagement: contact Russell Jones via the OIATC stewards channel
