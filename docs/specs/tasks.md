# Tasks (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## 1. Purpose

Tasks is the lightweight workflow-tracking surface for a Nation workspace. It lets staff, councillors, and program leads assign work, set due dates, track state, and group related items onto per-program boards, without standing up a heavyweight project-management tool. The unit of work is a single assignable task: a title, a description, an owner, a state, and an optional due date, optionally bundled into a board that scopes a program area (housing, education, lands and resources, health, language revitalization).

In a Nation workspace, task tracking is itself governed data. Who is assigned what, and which program a task belongs to, can carry sensitivity. A task that reads "follow up on the boil-water advisory remediation grant" is operationally public; a task that reads "review the membership appeal from [name] before the next council session" is nation-restricted. Tasks therefore inherits the same three-tier classification model as every other Anokii surface, so a board can hold a mix of public, community, and nation-restricted items and each item is shown or withheld per the viewer's clearance and Nation affiliation.

Tasks is deliberately small. It builds on Waaseyaa's generic workflow primitives (state machines), the scheduler (due-date reminders), the notification system (assignment and due alerts), and the entity system (persistence and access). It is not a Gantt planner, a sprint board, or a time tracker. The v0.1 goal is a credible governance to-do list that respects OCAP, works offline, and meets AODA Level AA.

## 2. Tier applicability

Anokii runs in two tiers. Tasks behaves differently in each because the boundary of "who can be assigned" and "whose board is this" changes.

**Sovereign single-tenant (FNPI, Intersnipe).** One Nation or organization owns the whole deployment. All boards, tasks, and assignees live in that Nation's storage bucket (`anokii-{nation_short}-{env}` per the tenant stub). Assignment targets are drawn from the local user/role model only. There is no cross-Nation visibility to reason about, so the classification tiers degrade to an internal sensitivity gradient: `public` means visible to any authenticated member, `community` means visible to staff/members, `nation-restricted` means visible only to holders of the relevant clearance (for example council-confidential work). Nothing in Tasks is exposed outside the tenant unless an operator deliberately publishes a board view through a separate public surface.

**Shared-graph multi-tenant (OIATC).** Several Nations share one deployment and one audit graph, federated through OIATC. Each task and board carries an owning-Nation marker (`owner_nation`) sourced from the active tenant context. The default is strict isolation: a board belongs to exactly one Nation, and members of other Nations cannot see it. Cross-Nation reads are governed by `FieldAccessPolicyInterface` exactly as the classification taxonomy specifies: `public`-tier task fields are Neutral (readable across Nations), `community`-tier fields are Neutral for federated partner Nations but withheld from unaffiliated parties, and `nation-restricted`-tier fields are Forbidden across Nations entirely. A shared board (an OIATC working group that several Nations co-own) is the one explicit exception, and it is modelled as a board whose `owner_nation` is the OIATC steward context with an explicit member roster, never as a side effect of weakened access checks.

What is public in either tier is only what a Nation marks `public`. There is no Anokii-wide global task feed. Aggregation across Nations, where OIATC stewards need a portfolio view, reads exclusively through the access-checked query path and shows only the rows each steward is cleared to see.

## 3. User-facing surface

The surface is delivered as an admin-SPA bundle (Nuxt 3 + Vue 3) on the deep-teal baseline, with an SSR read-only fallback for offline and low-bandwidth access.

- **Boards list.** The landing screen. Shows the boards the current account can see, grouped by program area, with a count of open tasks and overdue tasks per board. A board the viewer cannot access is simply absent (not greyed out, to avoid leaking its existence cross-Nation).
- **Board view.** A column layout, one column per workflow state (To Do, In Progress, Blocked, Done by default). Each card shows title, assignee avatar/initials, due date, and a classification chip. Cards are keyboard-movable between columns (see Accessibility); drag is an enhancement, never the only path.
- **Task detail.** Title, description (governed text), assignee, state, due date, classification label, board, and an activity trail (state changes, reassignments, comments). The activity trail is rendered from audit events, read-only.
- **Create / edit task.** A form with persistent visible labels: title, description, assignee picker (scoped to assignable accounts in the current tenant), due date, classification, board. Inline validation, no placeholder-only fields.
- **My tasks.** A personal cross-board view of everything assigned to the current account, sorted by due date, with overdue items surfaced first.
- **Reassign and transition actions.** Discrete buttons with confirmation for state moves that are governance-significant (for example marking a council-review task Done). Disallowed transitions are not offered.

Every mutating action routes through the framework access pipeline before it is applied. A denied action produces an accessible live-region announcement rather than a silent no-op.

## 4. Data model

All persistence is through the Waaseyaa entity system (entity types, `EntityRepository`, `DatabaseInterface`). No raw SQL. Entities are translatable and revisionable where noted, composing on the two-axis `(entity_id, langcode, vid)` storage model so that edits are versioned and offline edits reconcile cleanly.

### Entity: `anokii_task_board`

Groups tasks for a program area. Translatable (board names and descriptions are translation targets per DIR-A003).

| Field | Type | Notes |
|---|---|---|
| `id` | identifier | machine id |
| `uuid` | uuid | stable cross-device key for offline sync |
| `label` | string | board name (translatable) |
| `description` | text | governed text (translatable) |
| `program_area` | string | enum-like token (housing, education, lands, health, language, governance, other) |
| `owner_nation` | string | owning-Nation short code; OIATC steward context for shared boards |
| `classification_label` | classification | per-record label (framework classification field type); board default for new tasks |
| `member_roster` | reference[] | accounts/roles allowed on a shared board; empty for single-Nation boards |
| `created_at` | datetime_immutable | |
| `updated_at` | datetime_immutable | |

### Entity: `anokii_task`

The unit of work. Translatable and revisionable.

| Field | Type | Notes |
|---|---|---|
| `id` | identifier | machine id |
| `uuid` | uuid | stable cross-device key for offline sync |
| `label` | string | task title (translatable) |
| `description` | text | governed text (translatable) |
| `board_id` | reference | parent `anokii_task_board` (delegated access, see below) |
| `assignee_id` | reference | account assigned; nullable (unassigned) |
| `state_id` | string | current workflow state id (see workflow below) |
| `due_at` | datetime_immutable | nullable; drives scheduler reminders |
| `classification_label` | classification | per-record label; inherits from board when unset |
| `owner_nation` | string | owning-Nation short code, copied from board at create |
| `priority` | string | low / normal / high (display + sort only) |
| `offline_at` | datetime_immutable | set when the task was created or last mutated offline; cleared on server reconcile |
| `created_at` | datetime_immutable | |
| `updated_at` | datetime_immutable | |

### Workflow (not a new entity)

Task state is modelled with the framework `waaseyaa/workflows` primitives, not a bespoke status column. A `Workflow` config entity `anokii_task_flow` composes four `WorkflowState` nodes (`todo`, `in_progress`, `blocked`, `done`) and the `WorkflowTransition` edges between them. `ContentModerator` drives a task between states and rejects illegal transitions; the SPA renders only the transitions `getAvailableTransitions()` returns. Nations may ship an override workflow config (for example adding a `review` state) without a code change, the same way the framework editorial preset is overridable.

### Comments / activity

Lightweight comments reuse the framework note/engagement primitive rather than introducing a new entity, keeping the surface thin. The authoritative activity trail (state changes, reassignments, classification changes) is the OCAP audit log, queried read-only; Tasks does not maintain a parallel history table.

## 5. Access and classification

Tasks adds no new access machinery. It wires the framework primitives.

**Entity-level access.** Each task type registers an `AccessPolicyInterface`. View/update/delete on `anokii_task` is delegated to the parent board via the parent-delegated policy pattern (a task is visible if and only if its board is visible to the account), so board membership and Nation ownership are the single gate. Orphaned tasks (missing board) resolve to neutral, which denies under entity-level `isAllowed()` semantics, so a dangling task never becomes publicly readable.

**Field-level access and the three tiers.** Both task types also implement `FieldAccessPolicyInterface`, and the shipped `ClassificationFieldAccessPolicy` applies the Anokii default taxonomy:

- `public` label, `default_field_access: Neutral`. Fields readable across Nations.
- `community` label, `default_field_access: Neutral`. Readable for the owning Nation and federated partner Nations; withheld from unaffiliated accounts.
- `nation-restricted` label, `default_field_access: Forbidden`. Cross-Nation reads blocked entirely; only the owning Nation's cleared accounts read these fields.

Open-by-default holds: a field is editable/readable unless a policy returns Forbidden. This means a partner-Nation steward can see the title of a `community` task but the `description` of a `nation-restricted` task is withheld, and the row may collapse to a redacted placeholder rather than vanish, so the board still reconciles structurally.

**OCAP.** OCAP is enforced by architecture, not by Tasks-side checks. There is no admin backdoor that assigns or transitions a task outside the access pipeline. Per-record AI access (the A5 flagship) extends verbatim: a Co-Intelligence query that wants to summarize a board only sees the tasks the requesting account is cleared for, because the same access-checked query path feeds it.

**Audit expectations.** Every create, assignment change, state transition, classification change, and delete writes an append-only OCAP audit event (actor, task uuid, action, outcome, timestamp). Offline mutations carry the `offline_at` timestamp into the audit record so temporal ordering survives reconciliation. Hold-labelled tasks (a `hold-*` classification) are never deleted by retention; they are blocked at read time and preserved, consistent with the classification-and-retention engine.

## 6. Offline-first behavior

Tasks is fully usable offline within the account's own classification scope, per DIR-A002. The offline substrate is Dexie (IndexedDB) plus a Workbox service worker plus the FSM sync engine, composing on the framework two-axis revisions model; the `(uuid, langcode, vid)` tuple maps to Dexie composite keys.

**Works offline:** reading any board and task the account had access to at last sync; creating tasks; editing title/description/due date; reassigning; moving a task between states (the `Workflow` transition table is cached, so illegal transitions are still rejected client-side); viewing My Tasks. Each offline mutation is stamped `offline_at` and queued.

**Sync strategy.** Per DIR-A002, governed community data defaults to multi-submission-merge: concurrent offline edits to the same task become distinct revisions on reconnect (every change is a record, nothing is silently overwritten), and a conflict surfaces for human resolution rather than dropping a write. Last-write-wins is available only as an opt-in `classification-flag` for administrative-style boards where latest-is-canonical is genuinely correct (for example a single coordinator maintaining a config-like checklist). State transitions reconcile by replaying through `ContentModerator` server-side, so an offline move that became illegal in the interim is rejected on sync and surfaced, not force-applied.

**Identity and scope offline.** Tokens are cached with explicit expiry and re-auth is required on reconnect. Partial-trust offline operation permits reading the user's own classified data offline but never another Nation's cached data; in the OIATC tier, a device only holds the tasks of Nations the account is affiliated with. The audit log captures offline operations and flushes them on reconnect.

## 7. Accessibility

Tasks meets WCAG 2.1 Level AA and the AODA-specific requirements from DIR-A001. The board surface is the highest-risk area because column/drag interaction is easy to build inaccessibly, so it gets explicit treatment.

- **Board columns are a keyboard-first structure.** Drag-and-drop is a progressive enhancement layered over keyboard move actions (move to next/previous column, move to a chosen state via a menu). A keyboard-only user can fully triage a board. Each card is a focusable element with an accessible name combining title, state, assignee, and due date.
- **Access-denied messaging uses live regions.** A hard server-side OCAP denial announces via `aria-live="assertive"`; a soft capability-not-granted-in-this-session denial announces via `aria-live="polite"`. Moving a task into a state the account cannot perform produces an announced, not silent, refusal.
- **Forms use visible, persistent labels.** Title, assignee, due date, classification, and board pickers all have real labels, never placeholder-only patterns. Validation errors are programmatically associated with their inputs.
- **Color is never the only signal.** Classification chips, priority, and overdue status pair color with text and shape so the surface is legible to color-blind users and in high-contrast mode.
- **Co-Intelligence affordances** (a board summary requested from a workspace agent) use focus management and progressive announcement of streamed output, per DIR-A001.

Enforcement is the distribution axe-core CI gate plus per-component Vitest and Playwright accessibility tests; a Tasks component without an axe-core baseline is a charter violation, not a backlog item.

## 8. Indigenous-language and translation

Two distinct layers carry Anishinaabemowin, governed by DIR-A003.

**UI chrome.** Every static string in the Tasks SPA (column headers, button labels, the words "assignee", "due date", "overdue", state names, classification names) is extracted into the `translation_string` pipeline. Strings flow extraction tooling to `translation_string` entity to contributor dashboard to `translation_review` workflow to the glossary, with a per-Nation override layer so Sagamok (southern Ojibwe) and a future northern-dialect Nation can differ. No Anishinaabemowin string ships to a user without language-keeper review. State and program-area names are good early glossary candidates and should be co-authored with a language keeper as part of the initial 20 to 30 term target, not machine-translated.

**User content.** Task titles and descriptions are translatable entity fields (the `langcode` axis), so a task can be authored in English and carry an Anishinaabemowin translation as a distinct revision. Authored content is community/Nation data and is not pushed through the keeper-gated glossary review (that gate governs distribution UI text, not a member's own task notes); it is simply stored per language and shown according to the viewer's language preference with fallback. The language-keeper gate is absolute for shipped UI text and the working term "Anokii" itself; it does not police members' own writing.

## 9. Framework primitives used

- `waaseyaa/workflows` (`Workflow`, `WorkflowState`, `WorkflowTransition`, `ContentModerator`) for task state machine and legal-transition enforcement.
- `waaseyaa/scheduler` (`Schedule`, `ScheduledTask`, `ScheduleRunner`) for due-date scanning and reminder dispatch.
- `waaseyaa/notification` (`NotificationDispatcher`, `NotifiableInterface`, mail + database channels) for assignment and due/overdue alerts.
- `waaseyaa/entity` + `waaseyaa/entity-storage` (entity types, `EntityRepository`, two-axis `(entity_id, langcode, vid)` revisions) for persistence and offline reconciliation. See framework `docs/specs/entity-system.md` and `docs/specs/revision-system-unified.md`.
- `waaseyaa/access` (`AccessChecker`, `AccessPolicyInterface`, `FieldAccessPolicyInterface`, parent-delegated policy) for OCAP enforcement. See framework `docs/specs/access-control.md` and `docs/specs/field-access.md`.
- `waaseyaa/field` classification engine (`ClassificationLabelFieldType`, `ClassificationFieldAccessPolicy`, retention jobs) for the three-tier labels and hold semantics. See framework `docs/specs/classification-and-retention.md`.
- `waaseyaa/audit` (append-only OCAP audit log) for the activity trail and offline reconciliation ordering. See framework `docs/specs/ocap-audit-log.md`.
- `waaseyaa/api` + `waaseyaa/routing` work-surface primitives (per-field auto-save, deep-link routes, form descriptor builder) for the edit experience. See framework `docs/specs/work-surface.md`.
- `waaseyaa/user` for the assignable-account roster and role model.
- Anokii offline substrate (Dexie + Workbox + FSM sync) and the Anokii default classification taxonomy (`config/classification.anokii-default.yaml`).

Sibling surfaces: [Data Rooms](data-rooms.md) for per-record sensitive workspaces a task may reference, [Governed Docs](governed-docs.md) for documents a task links to, [Form Builder](form-builder.md) for intake that may spawn tasks, and the [Admin Centre](admin-centre.md) for board provisioning and tenant management.

## 10. Open questions

- **Cross-board task references in the OIATC tier.** A task may need to reference work owned by a partner Nation (a shared remediation effort). How is a controlled cross-Nation link modelled without leaking the linked task's classified fields? Likely a reference that resolves only to a `public`-tier projection.
- **Shared-board membership source of truth.** Is `member_roster` on `anokii_task_board` authoritative, or should shared boards derive membership from an OIATC group entity so roster changes are governed centrally rather than per board?
- **Reminder cadence and channel.** Scheduler can scan `due_at`, but the reminder policy (lead time, repeat, escalation to a supervisor) is undecided, and the notification channel mix (mail vs in-app database vs future push) needs a per-Nation default.
- **Recurring tasks.** Out of v0.1 scope, but a recurring governance task (monthly council prep) is a likely early ask. Decide whether recurrence is a scheduler-generated series of `anokii_task` rows or a template entity.
- **Assignee beyond a single account.** v0.1 assigns to one account. Group/role assignment (assign to "Lands Department") may be needed; defer until the role model interaction is settled.
- **Workflow override governance.** If a Nation customizes `anokii_task_flow` (adds a `review` state), how do offline clients that cached the old transition table reconcile a transition into a state they do not know about? Needs a versioned-workflow handshake on sync.
- **Retention defaults for completed tasks.** Should `done` tasks age out under a default retention rule, and does a `nation-restricted` completed task follow the same window as a `public` one? Coordinate with the classification-and-retention engine before shipping a default.
