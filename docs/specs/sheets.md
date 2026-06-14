# Sheets (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## Purpose

Sheets is the structured tabular data surface in a Nation workspace. It gives community staff a familiar grid (rows and typed columns) for the kinds of lists every Nation maintains: membership rolls, program intake registers, asset inventories, contact directories, event sign-ups, and small operational trackers. The grid is the entry point; the data underneath is governed entities, not loose cells.

Where a spreadsheet tool treats a sheet as an opaque blob of cells, Anokii Sheets treats each row as a first-class record that flows through the same entity, classification, access, audit, and revision machinery as every other Anokii surface. A column is a typed field with a label and a classification default. This means a membership column marked nation-restricted is blocked cross-Nation by the same FieldAccessPolicyInterface that protects a Co-Intelligence prompt, and an edit to a row is an audited, revisioned write, not an unattributed cell change.

For v0.1 the surface is deliberately narrow. It is a structured-data table with import and export. Formulas, computed columns, cross-sheet references, pivot tables, and charting are explicitly out of scope for v0.1. The goal is to replace the ad hoc shared spreadsheet (the one emailed around as an attachment, with no access control and no audit trail) with a sovereign, offline-capable, accessible grid that a Nation actually controls.

## Tier applicability

Anokii runs in two tenancy postures. Sheets behaves differently in each, and the difference is about what data is held and what is ever public, not about the grid itself.

**Sovereign single-tenant (FNPI, Intersnipe).** One Nation or organization owns the install. A sheet and all its rows live entirely inside that tenant's storage bucket (per the per-tenant `storage_bucket` convention in `config/tenants/<nation>.yaml`). The full classification range applies: a sheet can hold public rows (a published program directory), community rows (a member-only contact list), and nation-restricted rows (a sensitive intake register). Public-tier rows may be surfaced read-only through a published view; community and nation-restricted rows never leave the authenticated surface. There is no cross-Nation read path to reason about, so the classification tiers act purely as an internal clearance gate.

**Shared-graph multi-tenant (OIATC).** Multiple Nations share one graph, federated through OIATC. Each sheet still belongs to exactly one owning Nation (`owner_nation` on every row, set from the tenant context at create time, never client-supplied). The classification taxonomy in `config/classification.anokii-default.yaml` governs what crosses the Nation boundary:

- `public` rows: Neutral field access, readable by authenticated users across all federated Nations.
- `community` rows: Neutral for members of the owning Nation and federated partner Nations; blocked for unaffiliated parties.
- `nation-restricted` rows: Forbidden cross-Nation via FieldAccessPolicyInterface. The owning Nation controls disclosure (OCAP). A partner Nation querying a shared sheet sees the rows it is cleared for and never sees nation-restricted rows or their field values, even in aggregate counts where a count would leak membership.

In both tiers the default classification for a new sheet and a new column is the most restrictive sensible value (community), so a row never becomes public by omission. Promotion to public is an explicit, audited act.

## User-facing surface

Concrete screens and actions for v0.1:

1. **Sheet list.** A landing view of sheets the user can see in the current tenant, grouped by workspace, each annotated with its classification badge and row count (the count itself respects access; a viewer sees the count of rows they may read).
2. **Grid view.** The sheet open as a table. Rows down, typed columns across. Inline cell editing for fields the user has edit access to; edit-denied cells render as disabled widgets (the `x-access-restricted` convention from framework field-access), never as blank or missing. A per-row classification badge sits in a frozen leading column.
3. **Row detail / drawer.** A single row expanded as a form for longer fields and for setting the row classification. This reuses the framework form descriptor builder so the grid and the drawer agree on field shape, labels, and read-only state.
4. **Add column.** Define a new typed column: key, label, type (string, text, integer, decimal, date, boolean, enumeration, reference), required flag, and a column-default classification. Adding a column is a schema-shaped change recorded in config (see Data model).
5. **Add / edit / delete row.** Standard record actions. Delete is a soft, audited operation; for governed community data the prior revision is retained.
6. **Import.** Paste or upload structured data and map source columns to sheet columns before committing. See the import flow below.
7. **Export.** Download the rows the user may read as CSV (and a structured table form), classification-filtered server-side so an export never contains a field the requester could not view in the grid.

**Import flow.** Import builds on the framework structured importer (the `waaseyaa/structured-import` F5 primitive). The user pastes a GFM table or uploads a delimited file; the importer matches incoming column headers against each sheet column's label and declared prompt aliases (UTF-8 lowercase plus whitespace collapse, no transliteration). The user sees three buckets before anything is written: matched columns (header to field, with a preview), unmatched columns (offered for manual mapping or skip), and parse errors. Nothing persists until the user confirms the mapping. Every imported row is created through the entity write path, so each one is classified, access-checked, audited, and revisioned exactly as a hand-typed row would be. A row whose classification the importer cannot determine defaults to community and is flagged for review, never silently published.

## Data model

Sheets introduces two registered Waaseyaa entity types plus a config-backed column schema. All persistence goes through the entity system (EntityType, ContentEntityBase, EntityRepository); never raw SQL.

**Entity: `sheet`** (the container / definition).

| Field | Type | Notes |
|---|---|---|
| `id` | integer | Entity id (PK). |
| `uuid` | string | RFC-4122 UUID. |
| `title` | string | Sheet label; entity label key. |
| `description` | text | Optional. |
| `owner_nation` | string | `nation_short` of the owning Nation; set from tenant context, server-authoritative. |
| `classification_label` | classification field | Default classification inherited by new rows (framework classification field type). |
| `column_schema_id` | string | Reference to the config-stored column schema for this sheet. |
| `langcode` | string | Primary language of the sheet (two-axis storage). |

**Entity: `sheet_row`** (one record in a sheet).

| Field | Type | Notes |
|---|---|---|
| `id` | integer | Entity id (PK). |
| `uuid` | string | RFC-4122 UUID. |
| `sheet_uuid` | string | Parent sheet reference. |
| `owner_nation` | string | Denormalized from the parent sheet for cross-Nation filtering; server-set. |
| `classification_label` | classification field | Per-row classification; inherits from the parent `sheet` via the framework LabelInheritanceResolver when not explicitly set. |
| `cells` | bundle-scoped fields | The typed column values, materialized as bundle fields (see below). |
| `langcode` / `vid` | two-axis | Translatable plus revisionable, per framework two-axis storage. |

**Column schema (config, not an entity).** A sheet's columns are a bundle-scoped field definition set, declared per sheet and stored through the config management system (`config:import` / sync store), keyed by `column_schema_id`. Each column entry carries: `key`, `label`, `type`, `required`, `prompt_aliases` (for import matching), and `default_classification`. The framework bundle template / field registry machinery (`BundleTemplate`, `FieldTemplate`, `FieldDefinitionRegistry`) supplies the per-row field shape, so a `sheet_row` is effectively an entity whose bundle is the sheet it belongs to. Adding a column is a config write, captured in `config.audit`, not a code change.

Why two entities plus config rather than a single typed-grid blob: it lets each row carry its own classification and revision history, lets field-level access apply per column, and keeps the column schema reviewable and diffable as configuration. The typed-grid alternative (a single entity holding an opaque cell matrix) is rejected for v0.1 because it cannot express per-row classification or per-column field access, which are charter requirements (DIR-A005).

## Access and classification

Sheets inherits the framework OCAP wiring verbatim and adds no backdoor (DIR-A005). Access is enforced at two levels.

**Entity level (row visibility and mutation).** A `SheetRowAccessPolicy` (implementing `AccessPolicyInterface`) gates view / update / delete / create on each `sheet_row`. It is deny-by-default at the entity level: a row is visible only when a policy grants it. The policy composes:

- Tenancy: in shared-graph mode, a row whose `owner_nation` differs from the requester's Nation is only reachable for `public` and `community` classifications, and `community` only for federated partner Nations.
- Clearance: the framework classification clearance gate (`RoleBasedClearanceChecker`) applies the confidentiality level of the row's label against the account's clearance.

**Field level (column visibility and editability).** A `SheetColumnFieldAccessPolicy` (implementing `FieldAccessPolicyInterface`) maps the three tiers to the charter's field-access semantics, open-by-default at the field level (Neutral passes, Forbidden blocks):

| Tier | Same-Nation read | Cross-Nation read | Field access result |
|---|---|---|---|
| public | yes | yes | Neutral |
| community | yes (members + partners) | partners only | Neutral / Forbidden by affiliation |
| nation-restricted | yes (members) | no | Forbidden |

A view-denied column is omitted from the JSON:API response and from any export (the serializer drops it); an edit-denied column renders as a disabled widget via `x-access-restricted`. Because the framework classification field access policy already returns Forbidden or Neutral and is registered cross-cutting, a misconfigured Sheets column fails safe (an unknown label reads Neutral, never silently locking everyone out, but a nation-restricted label is Forbidden cross-Nation by construction).

**Audit expectations.** Every Sheets action lands in the unified OCAP audit log (`waaseyaa/audit`), append-only:

- Row create / edit / delete: `entity.write`, `entity.delete`.
- Row or sheet classification change: `classification.change`.
- Export: `entity.export` (records the requesting actor and the row scope).
- Denied reads (cross-Nation nation-restricted attempts): `access.denied`.
- Import: one `entity.write` per committed row, so a bulk import is fully reconstructible.

Offline writes carry an `offline_at` timestamp and reconcile on sync (see below); the audit row preserves temporal ordering on reconciliation.

## Offline-first behavior

Sheets must function offline-degraded (DIR-A002). It composes on the Anokii offline substrate (Dexie over IndexedDB, a Workbox service worker, and the FSM-based sync engine) which in turn rides the framework two-axis revisions model (`RevisionableStorageDriver` plus the `(entity_id, langcode, vid)` tuple, which maps cleanly to a Dexie composite key).

**Works offline:**

- Open and read any sheet the user has already synced, within their own classification scope. A member can read their Nation's cached community and nation-restricted rows offline; cached rows belonging to other Nations are never readable offline (partial-trust rule).
- Add rows, edit cells, and set row classification offline. These queue as pending revisions.
- Export the locally-cached, access-filtered rows.

**Requires connectivity:** defining a brand-new column schema (a config write), promoting a row to `public` (an explicit disclosure act that should not happen unattended), and import of a server-side file upload.

**Sync strategy.** Per DIR-A002 the default for governed community data is multi-submission-merge: every offline row write is a record and is never overwritten on reconnect; conflicting edits to the same row produce sibling revisions for a human to reconcile, never a silent last-writer-wins clobber. Administrative-config-style sheets (a single steward maintaining a settings list) may opt a sheet into last-write-wins via a classification flag, but that is opt-in, never the default for member data. On reconnect the sync engine replays the queue, the server re-runs access and classification checks (an offline edit to a now-forbidden field is rejected and surfaced, not applied), tokens are re-validated (re-auth-on-reconnect), and each replayed write writes its audit row with the original `offline_at`.

## Accessibility

Sheets meets WCAG 2.1 Level AA and the AODA-specific requirements (DIR-A001). A data grid is one of the harder patterns to get right, so the specifics matter.

- **Grid semantics.** The table uses a proper ARIA grid pattern (`role="grid"`, `rowheader` for the leading row identifier, `columnheader` for column labels) with full keyboard navigation: arrow keys move the active cell, Enter / F2 enters edit mode, Escape cancels, Home / End and Page Up / Down move across and down. Tab order does not trap.
- **Labels.** Every column header is a visible, persistent label. Edit widgets in the row drawer use visible persistent labels, never placeholder-only (charter requirement). Required columns are marked both visually and via `aria-required`.
- **Access-denied announcements.** A hard denial (server-side OCAP forbidden, for example attempting to read a nation-restricted column cross-Nation) is announced via `aria-live="assertive"`. A soft denial (a capability not granted in this session, for example edit access withheld) is announced via `aria-live="polite"`. Disabled cells expose `aria-disabled` and an accessible explanation, so a screen-reader user understands a cell is read-only by policy rather than empty.
- **Color and state.** Classification badges never rely on color alone; each badge carries a text label and an icon. Focus is always visible. Contrast meets AA for the deep-teal Anokii palette.
- **Offline state.** Pending-sync and conflict states are conveyed in text and to assistive tech, not by color or icon alone.
- **Enforcement.** Per DIR-A001 the surface ships with an axe-core CI baseline and per-component Vitest plus Playwright accessibility tests. A Sheets component without an axe-core baseline is a charter violation, not a follow-up.

## Indigenous-language and translation

Sheets participates in the Anokii Indigenous-language pipeline (DIR-A003), which is a product layer, not a toggle. Two distinct streams apply.

**Surface UI (chrome).** Every label, button, header, empty-state message, error string, and accessibility announcement in the Sheets interface is an extractable UI string that flows through the pipeline: extraction tooling collects it into the `translation_string` entity (which mirrors the framework two-axis storage shape), a contributor proposes an Anishinaabemowin rendering, and it passes the `translation_review` workflow before it can ship. No Anishinaabemowin string reaches a user without language-keeper review. The pilot target is English to Anishinaabemowin (southern and northern Ojibwe), matching the Sagamok tenant stub (`oji`, `southern-ojibwe`).

**Sheet content (data).** A `sheet_row` is translatable (it carries `langcode` on the two-axis storage), so a row can hold a value in English and a reviewed value in Anishinaabemowin as distinct language variants of the same record, not as a second row. Free-text content typed by a user is the Nation's own data and is governed, not auto-translated; where a Nation wants a translated variant of a community-facing value, that variant is authored and reviewed through the same `translation_review` gate and glossary, never machine-published into the codebase or to the public tier without language-keeper sign-off. Column labels that are Nation-specific terms draw on the per-Nation glossary override layer, so the same column can read with a Nation's preferred term.

## Framework primitives used

- `waaseyaa/entity`, `waaseyaa/entity-storage` (EntityType, ContentEntityBase, EntityRepository; two-axis revisionable plus translatable storage via `RevisionableStorageDriver`).
- `waaseyaa/field` (bundle templates, `FieldDefinitionRegistry`, form descriptor builder, the classification field type and `LabelInheritanceResolver`).
- `waaseyaa/structured-import` (the F5 `GfmTableImporter` and prompt-alias matching, for the import flow).
- `waaseyaa/access` (`AccessPolicyInterface`, `FieldAccessPolicyInterface`, `EntityAccessHandler`, the open-by-default field semantics and `x-access-restricted` annotation).
- Classification and retention engine (clearance gate, hold semantics, retention rules) per `docs/specs/classification-and-retention.md`.
- `waaseyaa/audit` (the unified append-only OCAP audit log; `entity.write`, `entity.delete`, `entity.export`, `classification.change`, `access.denied`).
- `waaseyaa/config` (the column schema as synced configuration, captured in `config.audit`).
- `waaseyaa/api` (JSON:API serializer / schema presenter for the grid, classification-filtered CSV export).
- `waaseyaa/admin` SPA conventions (Nuxt 3 plus Vue 3, the deep-teal token palette) for the grid and drawer.
- Anokii offline substrate (Dexie plus Workbox plus FSM sync engine, DIR-A002) and the Anokii translation pipeline (`translation_string`, `translation_review`, glossary, DIR-A003).
- The framework single-entity Work Surface primitives (F2 bundle templates, F5 structured importer, F6 form descriptor builder) per `docs/specs/work-surface.md`.

## Open questions

1. **Column schema as config vs entity.** Storing the per-sheet column schema in config (diffable, reviewable) is clean for a few sheets but may not scale to a Nation creating many ad hoc sheets at runtime; a `sheet_column` entity may be the better home. Decision deferred pending a count of expected sheets per tenant.
2. **Bundle explosion.** Treating each sheet as its own bundle gives clean per-column field access but could produce many bundles. Need to confirm the field registry and manifest compiler are comfortable with dozens to hundreds of dynamically-defined bundles per tenant.
3. **Cross-Nation aggregate leakage.** A row count or a column sum can leak nation-restricted membership even when individual rows are hidden. v0.1 filters counts to the requester's readable scope, but any future aggregate or summary feature needs an explicit non-leakage review.
4. **Reference columns.** A `reference` column type (a cell pointing at another entity, for example a member record) raises cross-surface access questions: the referenced entity may be more restricted than the sheet. v0.1 may ship reference columns as display-only labels and defer live linking.
5. **Import classification.** When imported data carries no classification signal, defaulting every row to community is safe but may bury rows that should be public; a per-import default plus a post-import review queue is the likely answer, to be specified.
6. **Conflict reconciliation UI.** Multi-submission-merge produces sibling revisions on conflict; the human-facing reconciliation surface (which sibling wins, who decides) is unspecified and likely shared with other Anokii surfaces rather than built per surface.
7. **Export format breadth.** v0.1 commits to CSV and a structured table form. Whether an offline export can be re-imported losslessly (round-trip with classification preserved) needs a format decision.
8. **Retention on rows.** How the classification-and-retention purge / redact jobs apply to `sheet_row` (a redacted cell vs a purged row) needs a per-column retention story, especially for intake registers with statutory retention windows.
