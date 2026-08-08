# Anokii

**Anokii** (working name — Anishinaabemowin verb stem meaning "she/he works"; pending language-keeper verification before public use) is the first opinionated distribution built on the [Waaseyaa](https://github.com/waaseyaa/framework) framework.

Anokii is an alpha-stage workspace distribution being designed with First Nations data sovereignty, accessibility, and local ownership as product requirements. Those outcomes require deployment-specific governance and verification; this repository does not claim that installing the software alone establishes OCAP compliance, AODA conformance, or an offline-capable service.

---

## What Anokii is

The current repository contains an authenticated server-rendered workspace baseline (Identity, Documents, Drive, Pages, Inbox, and cookieless first-party Analytics) plus an opt-in public graph-chat experiment. The following broader product surfaces are roadmap items, not shipped guarantees:

- **Governed Drive** — Nation-scoped file storage with OCAP classification
- **Form Builder** — Governed community data capture with offline queue
- **Tasks** — Governance-aware task tracking
- **Data Rooms** — Sensitive-data workspaces with per-record access control
- **Governed Docs** — Collaborative document authoring with conflict resolution
- **Governed Sheets** — Tabular data with classification-aware field access
- **Co-Intelligence Workspaces** — Per-record AI access (OCAP A5 flagship)
- **Admin Centre** — Distribution administration and Nation tenant management

Current security posture:

- Workspace routes require authenticated accounts and immutable authorization principals.
- Write actions use server-side permission checks; sealed account fields are read through audited, purpose-scoped framework boundaries.
- Uploads are content-sniffed, size-bounded, and served with restrictive download headers.
- Raw public-chat questions are not retained or written to application logs.

Important limitations:

- Sovereign mode now stamps and scopes Anokii-owned persistent entities to the configured active community and classifies their fields. That is a technical isolation boundary, not by itself proof of an installation's governance, OCAP compliance, or operating practices.
- Offline operation, formal AODA/WCAG conformance, email-change verification, and the roadmap surfaces below remain unshipped until their acceptance gates exist and pass.

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

**Alpha — active local hardening.** Product code exists, but no release or production deployment should be made from an unverified branch. The local release gate is PHPUnit, PHPStan at max level, PHP-CS-Fixer, locked dependency audit, packaged-form provider boot, and browser/accessibility verification.

Brand palette: Deep Teal (`#0d4f4f → #0f766e → #14b8a6`) — differentiated from Drupal blue, Laravel red, Django/Nuxt green, Strapi purple. Visible once the admin overlay lands.

---

## Install

> **Not yet published to Packagist.** The following command will work once the first release tag is cut.

```bash
composer create-project waaseyaa/anokii my-anokii-site
```

In the meantime, clone this repo directly and run `composer install`.

Copy `.env.example` to `.env` and replace its placeholders. In particular,
`WAASEYAA_APP_SECRET` must be `base64:` followed by canonical base64 for exactly
32 random bytes (generate one with
`php -r 'echo "base64:" . base64_encode(random_bytes(32)) . PHP_EOL;'`),
`ANOKII_COMMUNITY_ID` must identify this installation's community,
`ANOKII_PRIVACY_SECRET` must be at least 32 random bytes or the analytics ingest
endpoint stays unavailable, and `TRUSTED_PROXIES` must name only proxies you
operate so Waaseyaa/Symfony can resolve client addresses without trusting forged
forwarding headers.

Before a production-equivalent boot, build the database and its checksum-bound
field-access artifact against the exact installed lock:

```bash
APP_ENV=local php vendor/bin/waaseyaa db:init --sync-schema
APP_ENV=production composer readiness:field-access
APP_ENV=production php vendor/bin/waaseyaa list >/dev/null
```

The final command must succeed. `.waaseyaa/field-access-classification.json` is
reviewed source; `.waaseyaa/field-access-preflight.json` is generated for the
exact lock and schema and must not be copied forward after dependencies or the
entity model change.

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
