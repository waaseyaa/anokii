# Admin Centre (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## Purpose

The Admin Centre is the operator surface of an Anokii workspace. It is where a
Nation's administrators manage the people, roles, classification labels, and
enabled modules that shape every other surface ([Governed Drive](governed-drive.md),
[Form Builder](form-builder.md), [Tasks](tasks.md), [Data Rooms](data-rooms.md),
[Governed Docs](governed-docs.md), [Governed Sheets](governed-sheets.md), and
[Co-Intelligence Workspaces](co-intelligence-workspaces.md)). If the rest of
Anokii is the work, the Admin Centre is the place where a Nation decides who may
do that work and under what governance.

The Admin Centre does not invent its own permission model. It is a control panel
over framework primitives: accounts and sessions (the Waaseyaa `user` package),
roles and permissions (the `ProvidesRolesInterface` capability plus the
`user:assign-role` command), classification labels (the framework
classification engine, seeded by `config/classification.anokii-default.yaml`),
and module enablement (config entities applied through the config sync store).
Every administrative action flows through the same `AccessChecker` and
`FieldAccessPolicyInterface` pipeline that governs ordinary content, so there is
no admin backdoor (Anokii DIR-A005).

A second responsibility, unique to this surface, is the tenancy-mode switch. An
Anokii deployment runs in one of two postures (sovereign single-tenant or
shared-graph multi-tenant), and the Admin Centre is where that posture is read,
displayed, and (where permitted) changed. This makes the Admin Centre the one
surface that must understand both tiers explicitly rather than inheriting tier
behavior from configuration.

## Tier applicability

The Admin Centre is the operator surface for the both-tiers config, so its
behavior is the most tier-aware of any Anokii surface.

**Sovereign single-tenant (FNPI, Intersnipe).** One Nation owns the whole
deployment. The Admin Centre administers exactly one tenant config (for example
`config/tenants/sagamok.yaml`). All accounts, roles, and classification labels
belong to that Nation. Nothing crosses a Nation boundary because there is no
other Nation in the graph. The tenancy-mode indicator reads `sovereign` and the
mode switch is, by default, locked (changing posture is a redeploy-class
decision, not a runtime toggle). Account directories, role assignments, and
label vocabularies are entirely private to the Nation; the only public-tier data
is whatever the Nation has deliberately classified `public`.

**Shared-graph multi-tenant (OIATC).** Several Nations share one Anokii graph
under OIATC stewardship. The Admin Centre is scoped to the operator's own
Nation: an administrator sees and manages their Nation's accounts, roles, and
label overrides, never another Nation's. Cross-Nation reads obey the
classification taxonomy verbatim (public Neutral, community Neutral for
federated partners, nation-restricted Forbidden). The tenancy-mode indicator
reads `shared-graph` and names the steward channel (OIATC). A steward-level
operator may hold additional cross-tenant visibility for membership and audit
routing, but that visibility is granted by role, enforced by the framework
access pipeline, and audited like any other access. No Admin Centre screen ever
renders another Nation's nation-restricted data, regardless of operator role.

In both tiers the only data published beyond the owning Nation is data the
Nation has classified `public`. Role catalogues, account lists, and label
vocabularies are community-tier or nation-restricted by default.

## User-facing surface

The Admin Centre is a section of the framework Nuxt admin SPA (DIR-007),
grouped under a "Governance" and "Administration" navigation cluster. The v0.1
screens:

1. **Accounts.** A list of the Nation's user accounts with status (active,
   pending verification, disabled), assigned roles, and last-seen. Actions:
   invite an account, disable or re-enable, trigger a password reset, resend
   email verification. Account creation defers to the framework auth
   registration policy (`admin`, `open`, or `invite`).
2. **Role assignment.** For a selected account, show the roles it currently
   holds and the registered roles available to assign. Assigning or removing a
   role calls the framework `user:assign-role` command behind a JSON:API action,
   which stamps (or recomputes) the account's flat permissions. The screen
   reads the available role catalogue from the `RoleRepository` so it can never
   offer a role that is not registered.
3. **Classification labels.** A manager for the Nation's classification
   vocabulary: view the three default tiers (public, community,
   nation-restricted), see each tier's default field access (Neutral, Neutral,
   Forbidden), and edit Nation-specific label metadata where the Nation chooses
   to extend or relabel. Label edits are config changes, applied through the
   config sync store, not raw writes.
4. **Modules.** A list of Anokii surfaces with an enable toggle and a separate
   preview toggle. Enable makes a surface live for the Nation; preview exposes a
   surface to operators and a named preview cohort without making it generally
   available. Each row shows the surface's classification posture and whether it
   carries offline support.
5. **Tenancy.** A read-first panel showing the current tenancy mode
   (`sovereign` or `shared-graph`), the resolved tenant config (Nation name,
   language, dialect, OIATC membership, storage bucket, theme), and, where the
   operator's role and deployment posture allow it, the mode switch with a
   confirmation and consequences summary.

Every destructive or governance-changing action (disable account, remove role,
relabel a classification tier, disable a module, switch tenancy mode) presents a
confirmation dialog that states the consequence in plain language before it
proceeds.

## Data model

The Admin Centre introduces a small number of Anokii config entities and reuses
framework entities. All persistence goes through the entity system and the
config sync store; no screen issues raw SQL.

**Reused framework entities and primitives.**

- `user` (framework `user` package) holds accounts. The Admin Centre reads and
  updates the `roles` and `permissions` value arrays through the
  `user:assign-role` path, never by direct field writes.
- `Role` value objects, contributed via `ProvidesRolesInterface::roles()` and
  collected into `RoleRepository`. Anokii ships its own provider implementing
  `ProvidesRolesInterface` to register the Nation-governance role set
  (for example `nation-admin`, `nation-steward`, `language-keeper`,
  `governance-viewer`, `member`), each carrying its permission strings.
- `ClassificationLabelDefinition` (framework classification engine) is the label
  vocabulary entity. Anokii seeds the three default tiers from
  `config/classification.anokii-default.yaml`; the labels screen edits these as
  config entities.
- `audit_event` (framework `audit` package) is the append-only OCAP log the
  Admin Centre reads for its activity views. It is a log table, not an entity.

**Anokii config entities introduced by this surface (design sketch).**

- Entity id `anokii_module_state`. Key fields: `module_id` (string, the surface
  identifier such as `data-rooms`), `enabled` (bool), `preview` (bool),
  `preview_cohort` (list of role ids or account ids), `updated_at` (datetime).
  One record per surface per Nation. Persisted as a config entity so module
  enablement is exportable and reviewable through `config:export` and
  `config:import`.
- Entity id `anokii_tenancy_state`. Key fields: `mode`
  (`sovereign` or `shared-graph`), `tenant_short` (string, matching the tenant
  config `nation_short`), `steward_channel` (string, the OIATC channel
  identifier when shared-graph), `mode_locked` (bool), `switched_at`
  (datetime). One record per deployment. The tenancy panel reads this; the
  switch updates it under access control.

Tenant identity itself (Nation name, language, dialect, OIATC membership,
storage bucket, theme) is configuration sourced from
`config/tenants/<nation>.yaml`, not an entity. The Admin Centre reads it through
the config read API and renders it read-only; editing tenant identity is a
config-file and redeploy operation, deliberately outside the runtime surface.

## Access and classification

The Admin Centre inherits the framework OCAP wiring and never weakens it
(Anokii DIR-A005).

**Route and entity access.** Every Admin Centre route carries a framework route
option. Read screens require an administrative role via `_role` (for example
`_role: nation-admin,nation-steward`); mutating actions additionally require the
matching permission via `_permission`. The role assignment action is gated so
that only an operator who may administer accounts can invoke `user:assign-role`;
the command resolves the target role from the `RoleRepository`, so an operator
cannot assign an unregistered or out-of-tier role. Config entity writes
(`anokii_module_state`, `anokii_tenancy_state`) are governed by an
`AccessPolicyInterface` registered for those types and combined through the
standard `EntityAccessHandler` deny-by-default pipeline.

**The three tiers.** Admin Centre data is classified by purpose, not blanket
nation-restricted. Module state and the role catalogue are community-tier (a
Nation member may see what surfaces exist and what roles are defined). Account
contact detail and the tenancy steward channel are nation-restricted in
shared-graph mode, so the `ClassificationFieldAccessPolicy` returns Forbidden on
cross-Nation reads (the FieldAccessPolicyInterface contract: Neutral accessible,
Forbidden blocked). The tier seeds come straight from
`config/classification.anokii-default.yaml`: public Neutral, community Neutral,
nation-restricted Forbidden. A Nation may relabel through the classification
labels screen, but the cross-Nation Forbidden semantics of its restricted tier
are not weakened by any Admin Centre action.

**Audit expectations.** Administrative actions are high-value audit targets.
Role assignment, account disable or re-enable, classification label changes,
module enable or preview toggles, and tenancy-mode switches each emit an OCAP
audit event. Classification changes ride the framework's existing
`classification.change` kind; entity writes to the Anokii config entities ride
`entity.write`; denied attempts ride `access.denied`. The audit log is
append-only (the `AppendOnlyAuditDatabase` decorator enforces this at the
substrate), so the Admin Centre's activity views are reads over an immutable
record. Each event carries the authoritative `actor_uid` resolved from the
acting-account context, never a guessed or zero-defaulted actor.

## Offline-first behavior

The Admin Centre honors the offline-first baseline (Anokii DIR-A002): see
[Offline-first baseline](offline-first-baseline.md) for the substrate contract
(Dexie plus Workbox plus the FSM sync engine over the framework two-axis
revisions model).

What works offline:

- **Read of cached admin state.** The account list, role catalogue, module
  state, classification vocabulary, and tenancy panel are all readable offline
  from the operator's last sync, within the operator's own classification scope.
  No Admin Centre read of the operator's own Nation data requires connectivity
  (the read-after-write-offline charter line).
- **Queued administrative writes.** Role assignment, module toggles, and label
  edits performed offline are queued with an `offline_at` timestamp and applied
  on reconnect. Because these are administrative records where latest-is-canonical
  is correct, they use the LWW (last-write-wins) opt-in queue strategy rather
  than the multi-submission-merge default reserved for governed community data.
  This LWW choice is recorded as a classification flag on the queued operation,
  per DIR-A002.

What does not work offline:

- **Account invitation and password reset** depend on mail delivery and the auth
  token pipeline; they are deferred until reconnect and the operator is told so.
- **Tenancy-mode switch** is connectivity-required by design. Switching posture
  reconfigures cross-Nation visibility for the whole graph, so it must reconcile
  against the live server and is never queued offline.

On reconnect, queued operations sync through the FSM engine, re-auth is
required (tokens are cached with explicit expiry), and the audit log absorbs the
offline operations with their original `offline_at` timestamps so temporal
ordering is preserved for reconciliation.

## Accessibility

The Admin Centre meets WCAG 2.1 Level AA and the AODA procurement-legibility
requirements (Anokii DIR-A001); see [Accessibility baseline](accessibility-baseline.md)
for the cross-surface contract and the axe-core CI gate. Surface-specific points:

- **Access-denied messaging.** When an operator attempts an action the access
  pipeline forbids, the denial is announced via a live region. Hard denials
  (server-side OCAP Forbidden, for example an attempt to read another Nation's
  restricted data) use `aria-live="assertive"`; soft denials
  (a capability not granted in this session) use `aria-live="polite"`. This
  matters on the Admin Centre more than anywhere, because operators routinely
  bump the edges of what their role permits.
- **Forms.** Every input on the accounts, role, label, module, and tenancy
  screens has a visible, persistent label. No placeholder-only fields.
- **Confirmation dialogs.** Each destructive confirmation moves focus to the
  dialog, traps focus while open, states the consequence in text (not by color
  or icon alone), and returns focus to the triggering control on close.
- **Status and toggles.** Module enable and preview toggles expose state to
  assistive technology (not color-only), and the tenancy-mode indicator is
  announced as text.
- **Keyboard.** Every action, including the role-assignment picker and the
  module toggles, is fully operable by keyboard with a visible focus indicator.

Per-component accessibility tests (Vitest plus Playwright) and an axe-core
baseline are required before this surface ships; a surface without the baseline
is a charter violation, not a quality shortcut.

## Indigenous-language and translation

The Admin Centre's UI strings and its operator-authored label copy both pass
through the Anokii translation pipeline (Anokii DIR-A003), which is a product
layer, not a toggle.

- **UI chrome.** All Admin Centre labels, buttons, confirmation text, and
  access-denied messages are extracted as `translation_string` records
  (the pipeline's two-axis storage shape) so they can be presented in
  Anishinaabemowin alongside English. The pilot scope is English to
  Anishinaabemowin (southern and northern Ojibwe), matching the Sagamok and
  Sheguiandah pilots.
- **Operator-authored content.** When a Nation relabels a classification tier or
  names a role in the labels and roles screens, that human-authored text is
  routed into the `translation_review` workflow rather than shown publicly in
  Anishinaabemowin straight away.
- **Language-keeper gate.** No Anishinaabemowin text enters a Nation's live
  Admin Centre without language-keeper review. The `language-keeper` role
  registered by Anokii's `ProvidesRolesInterface` provider is the surface-side
  counterpart to that gate: only a language keeper approves a translation for
  publication, and the gate is absolute (no charter exception may bypass it).
- **Per-Nation overrides.** A Nation's glossary and per-Nation override layer
  take precedence over the shared pilot glossary, so a tier or role term a
  Nation has localized is rendered with the Nation's own approved wording.

## Framework primitives used

- `waaseyaa/user` provides the `user` entity, `Role` value object,
  `RoleRepository`, and `user:assign-role` command (account, role, and
  permission management).
- `waaseyaa/foundation` provides the `ProvidesRolesInterface` capability (Anokii
  registers its Nation-governance roles through this), the config read API, and
  kernel services.
- `waaseyaa/access` provides `AccessChecker`, `AccessPolicyInterface`,
  `FieldAccessPolicyInterface`, `EntityAccessHandler`, route options
  (`_role`, `_permission`, `_authenticated`), and the acting-account context.
- `waaseyaa/field` (classification engine) provides
  `ClassificationLabelDefinition`, `ClassificationFieldAccessPolicy`, and the
  clearance and label registry; see framework
  `docs/specs/classification-and-retention.md`.
- `waaseyaa/config` provides the config sync store and `config:export` /
  `config:import` for the `anokii_module_state` and `anokii_tenancy_state`
  config entities; see framework `docs/specs/config-management.md`.
- `waaseyaa/audit` provides the OCAP audit log substrate (`audit_event`,
  append-only enforcement, `classification.change` / `entity.write` /
  `access.denied` kinds); see framework `docs/specs/ocap-audit-log.md`.
- `waaseyaa/entity` and `waaseyaa/entity-storage` provide entity registration
  and the two-axis revisions model the offline sync engine composes on.
- `waaseyaa/admin` (admin SPA, DIR-007) and `waaseyaa/api` provide the Nuxt
  surface host and the JSON:API actions backing it; see framework
  `docs/specs/admin-spa.md` and `docs/specs/work-surface.md`.
- Anokii translation pipeline (`translation_string`, `translation_review`,
  glossary, per-Nation override) for DIR-A003.

## Open questions

- **Tenancy-mode switch authority.** Should switching from sovereign to
  shared-graph (or back) ever be a runtime action, or always a redeploy gated by
  `mode_locked`? The draft locks it by default and treats the runtime switch as
  steward-only and connectivity-required, but the exact role and approval flow
  are unresolved.
- **Anokii role catalogue.** The proposed roles (`nation-admin`,
  `nation-steward`, `language-keeper`, `governance-viewer`, `member`) and their
  permission strings are a sketch. The final set, and how it relates to the
  framework classification clearance roles (`admin`, `nation-steward`, `editor`,
  `viewer`), needs to be settled so clearance levels and Admin Centre roles do
  not drift.
- **Config-entity vs framework state for module enablement.** Module state is
  modeled here as an Anokii config entity. If the framework grows a first-class
  module or extension-enablement registry, this should defer to it rather than
  carry a parallel store. Worth a framework-mission check before implementation.
- **Cross-tenant steward visibility scope.** In shared-graph mode, exactly what
  an OIATC steward may see across Nations (membership and audit routing only,
  per the tenant stub) needs a precise field-level definition so the
  field-access policy can encode it without overreach.
- **Offline LWW boundary for label edits.** Treating offline classification
  label edits as LWW administrative records is convenient, but a label change
  has governance weight. It is open whether label edits should instead be
  connectivity-required like the tenancy switch.
- **Account provenance across re-invites.** When a disabled account is later
  re-invited, how account history and audit continuity are preserved (same
  `uid` versus new) is not yet specified.
