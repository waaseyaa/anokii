# Forms (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## Purpose

The Forms surface gives a Nation workspace a way to define, publish, and collect
structured intake without writing code. A governance officer builds a form (an
ordered set of fields with labels, help text, validation, and a per-field
classification), shares it, and every response lands as a first-class entity in
the workspace. Typical uses in a Nation context: a membership or status-card
intake, a housing-repair request, a language-program sign-up, a community-survey
round, an event RSVP, or an internal grant-application form.

Forms is deliberately thin. It does not invent a new field engine, a new
validation engine, or a new access engine. It composes the Waaseyaa work-surface
primitives (`BundleTemplate` / `FieldTemplate`, `FormDescriptorBuilder`,
`FieldAutoSaveController`) and the framework field, validation, and access
packages. The Anokii contribution is the opinionated product layer on top: a
builder UI, the submission-as-entity convention, per-field classification wired
to the three Anokii tiers, offline submission queueing, and the translation
pipeline binding for both form chrome and Indigenous-language content.

A form has two halves that this spec keeps distinct. The **definition** is the
schema a builder authors (fields, rules, classification). The **submission** is
the record a respondent produces. Both persist through the entity system, never
through raw SQL. Definitions are governed config-like artifacts; submissions are
governed community data.

## Tier applicability

Anokii runs in two postures. Forms behaves differently in each, and the
difference is mostly about where submissions live and who can read across a
boundary.

**Sovereign single-tenant (FNPI, Intersnipe).** One Nation or organization owns
the whole install. Form definitions and submissions live entirely inside that
Nation's storage bucket (for example `anokii-sagamok-prod`). There is no
cross-Nation read to reason about, so the three tiers collapse to an
internal-sensitivity scale: `public` fields may surface on a public SSR page (an
open RSVP form, a public comment intake), `community` fields are visible to
authenticated Nation members, and `nation-restricted` fields are visible only to
cleared roles inside the Nation. Per-field classification still matters here for
internal least-privilege, but no field policy ever has to block a foreign Nation.

**Shared-graph multi-tenant (OIATC).** Several Nations share one graph, scoped by
the per-tenant config (`config/tenants/<nation>.yaml`). A form definition is
owned by exactly one Nation and is not editable by another. Submissions are
owned by the Nation that received them. Cross-Nation reads are governed by the
Anokii default classification taxonomy: `public`-tier fields are
`Neutral` (readable by any authenticated account across Nations),
`community`-tier fields are `Neutral` for federated partner Nations and blocked
for unaffiliated parties, and `nation-restricted`-tier fields are `Forbidden`
across the Nation boundary via `FieldAccessPolicyInterface`. What is public in
shared-graph mode is therefore the union of `public`-tier definition metadata
(title, description, the fact that a form exists) plus any `public`-tier
submission fields the owning Nation chose to expose. Everything else is held by
the owning Nation. OCAP: the Nation controls disclosure of its own intake.

## User-facing surface

Three roles touch this surface: the **builder** (authors definitions), the
**respondent** (submits), and the **reviewer** (reads and triages submissions).

Builder screens:

- **Form list.** All definitions the account may see, with status (draft,
  published, closed), submission count, and owning Nation (shared-graph only).
- **Form builder.** An ordered field canvas. Add a field, pick a type, set the
  label and help text, mark required, attach validation rules, and assign a
  classification tier per field. Reorder by drag or by keyboard. The builder
  renders from `FormDescriptorBuilder` output so the authoring preview and the
  live form share one descriptor source.
- **Publish and share.** Move a definition draft to published. Produce a share
  link (an SSR route for public forms, an authenticated route otherwise) and a
  close date.

Respondent screens:

- **Fill form.** A single accessible form rendered from the published
  descriptor. Per-field auto-save of in-progress answers (draft submission) via
  the framework per-field endpoint, then a final submit that seals the record.
- **Confirmation.** A receipt with a submission reference the respondent can
  keep, plus an offline-aware state ("saved on this device, will sync").

Reviewer screens:

- **Submission list.** Responses to a form, filtered by status and date, with
  field-level redaction already applied (view-denied fields are simply absent,
  not blanked, per field-access semantics).
- **Submission detail.** One response rendered read-mostly, honoring per-field
  view access and showing the classification badge on each field.

## Data model

Forms introduces two entity types and reuses the framework field registry for
the dynamic per-form fields. All persistence is through the entity system
(`EntityRepository` over the storage driver); no raw SQL.

**`anokii_form` (the definition).** A revisionable entity so that edits to a
published form keep an auditable history.

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | system | entity keys |
| `title` | string | form name; translatable |
| `description` | text | builder-authored intro; translatable |
| `status` | string | `draft`, `published`, or `closed` |
| `owner_nation` | string | `nation_short` from the tenant config; immutable after create |
| `default_classification` | string | tier applied to a field when the builder leaves it unset |
| `closes_at` | datetime | optional close date |
| `field_schema` | json | ordered list of field descriptors (see below) |

Each entry in `field_schema` is a descriptor with: `key`, `type` (a framework
field type, for example `string`, `text`, `email`, `integer`, `date`,
`boolean`, `choice`), `label`, `help`, `required`, `validation` (a list of rule
ids and parameters resolved against the framework validation package), and
`classification` (one of `public`, `community`, `nation-restricted`). The
descriptor list is the same shape `FormDescriptorBuilder` consumes, so the
builder, the live form, and the submission renderer all read one schema.

**`anokii_form_submission` (the response).** A content entity owned by the
receiving Nation. Stored append-style: a submission is a record, not a mutable
draft of the canonical answer (see Offline-first below for why this matters).

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | system | entity keys |
| `form_uuid` | string | the `anokii_form` this answers |
| `owner_nation` | string | the Nation that received the submission |
| `status` | string | `draft` (in progress), `submitted`, `withdrawn` |
| `submitted_at` | datetime | seal timestamp |
| `offline_at` | datetime | set when captured offline; null otherwise |
| `values` | json | answer map keyed by field `key` |
| `classification_label` | inherited | resolved per the classification engine |

The per-field classification on a submission is derived from the form's
`field_schema`: each answer carries the tier its field declared, so the field
policy can make per-field view decisions on the stored `values` map. A submission
inherits an entity-level classification label from its form via the framework
`LabelInheritanceResolver` (default to the form's `default_classification`, with
explicit per-field tiers governing field reads).

For dynamic per-form fields that need real field definitions (rather than a JSON
blob) a future increment may compile each published form into a bundle via the
`BundleTemplate` / `FieldTemplate` compiler, giving every form its own bundle on
`anokii_form_submission`. The v0.1 sketch keeps answers in a typed `values` map
to avoid a schema-migration per form; the compiled-bundle path is an open
question below.

## Access and classification

Forms inherits the framework OCAP wiring verbatim and adds no backdoor. Entity
access is deny-by-default (`isAllowed()`); field access is open-by-default
(`!isForbidden()`). Two policy layers apply.

**Entity-level (form and submission visibility).** An `AnokiiFormAccessPolicy`
(implementing `AccessPolicyInterface`) governs who may view, edit, and create
forms and submissions. Builders need a create/edit grant on `anokii_form`;
respondents need create on `anokii_form_submission` for a published, open form;
reviewers need view on submissions for forms their Nation owns. In shared-graph
mode the policy scopes every decision to `owner_nation` so one Nation never edits
another's definition or reads another's submissions at the entity level.

**Field-level (per-tier redaction).** The framework
`ClassificationFieldAccessPolicy` (registered cross-cutting for entity type `*`)
already enforces the tier semantics this surface needs, so Anokii does not
reimplement them. A field tagged `nation-restricted` resolves to a
`nation-restricted` classification label, which is `Forbidden` for a
cross-Nation account via `FieldAccessPolicyInterface`; a view-denied field is
omitted from the serialized response rather than blanked. `community`-tier
fields are `Neutral` (accessible) for members and federated partners;
`public`-tier fields are `Neutral` for everyone authenticated. The Anokii
contribution is the mapping table that turns a form field's tier into the
framework classification label, plus the builder UI that lets an author set it.

**Audit expectations.** Every submission write fires the framework
`entity.write` audit event; reads of a submission via the API fire `entity.read`
through the API request listener; a denied cross-Nation field read is captured
as `access.denied`. Changing a form's `default_classification` or a field tier
fires `classification.change` through the classification subscriber. The audit
log is append-only (`AppendOnlyAuditDatabase`) and the actor is the acting
account, so a reviewer reading a submission is attributed to the reviewer, not to
the respondent. Offline-captured submissions carry `offline_at` and the audit
row for the deferred write reconciles on sync.

## Offline-first behavior

Forms must work in offline-degraded mode (DIR-A002). A respondent on an
intermittent connection (a community event, a home visit, a band-office desk with
flaky uplink) can open a published form they have already loaded, fill it, and
submit. The submission is written to the local Dexie store keyed on the
`(entity_id, langcode, vid)` tuple that the framework two-axis revisions model
exposes, and the service worker (Workbox) serves the form shell offline. The
FSM-based sync engine flushes queued submissions on reconnect, stamping
`offline_at` with the local capture time so the server can reconcile temporal
order.

The queue strategy follows the charter default: **multi-submission-merge** for
governed community data. Every queued submission is its own record and is never
overwritten by a later one; two people filling the same form on the same shared
device produce two submissions, not one clobbered answer. The administrative
exception (LWW, last-write-wins) is available only as an opt-in
`classification-flag` on a form whose intent is a single admin updating a config
record (for example an internal settings form), and is off by default. Read
scope offline is the user's own classification scope: a respondent may read back
their own queued submission offline, but no other Nation's cached data is
readable offline.

What works offline: opening an already-loaded published form, filling it,
per-field draft auto-save into Dexie, sealing a submission, and reading back the
respondent's own submissions. What requires connectivity: publishing a new
definition, editing a form schema, and any cross-Nation reviewer read (those are
not in the respondent's offline scope). See the offline-first baseline for the
shared sync-engine contract.

## Accessibility

Forms is the surface where AODA Level AA (DIR-A001) is most load-bearing, because
forms are exactly where keyboard and screen-reader users get stranded. Every
form rendered by this surface meets WCAG 2.1 AA, and the builder enforces it so
an author cannot ship an inaccessible form.

Specifics for this surface:

- **Visible, persistent labels on every input.** No placeholder-only fields. The
  builder requires a label before a field can be saved; an empty label is a
  validation error in the builder, not a silent default.
- **Programmatic field association.** Each input is associated with its label,
  help text, and error message via `for` / `aria-describedby` so assistive tech
  announces the full context.
- **Error summary and inline errors.** On a failed submit, focus moves to an
  error summary region, each error links to its field, and each field carries an
  inline message. Validation messages use a live region so a screen reader hears
  them.
- **Access-denied announcements.** A hard server-side OCAP denial (a respondent
  trying to submit to a closed or cross-Nation form) announces via
  `aria-live="assertive"`; a soft capability-not-granted denial (a field hidden
  this session) announces via `aria-live="polite"`.
- **Keyboard-operable builder.** Field reordering, type selection, and tier
  assignment are all reachable and operable by keyboard, not drag-only.
- **No color-only meaning.** Classification tiers show a text badge, not just a
  color.

Enforcement rides the distribution baseline: the axe-core CI gate runs on the
builder and the rendered form, and per-component Vitest plus Playwright tests are
required. A form surface that ships without an axe-core baseline is a charter
violation, not a quality shortcut. See the AODA baseline for the shared gate
wiring.

## Indigenous-language and translation

Forms participates in the Anokii Indigenous-language pipeline (DIR-A003) on both
its chrome and its content, and no Anishinaabemowin text reaches a respondent
without language-keeper review.

**Form chrome (UI strings).** Builder labels, buttons, validation message
templates, and confirmation copy are extracted as `translation_string` entities
(the same two-axis storage shape the framework uses, per DIR-005). They flow
through the pipeline: extraction tooling produces the strings, the contributor
dashboard collects translations, the `translation_review` workflow gates them,
and the glossary plus the per-Nation override layer resolve the displayed text.
A Nation set to `oji` / `southern-ojibwe` (Sagamok's tenant config) sees
reviewed Anishinaabemowin chrome where it exists and falls back to English where
it does not.

**Form content (author-supplied text).** A form's `title`, `description`, and
per-field `label` / `help` are translatable fields on `anokii_form`. A builder
authoring in English can request a translation into the Nation's language, which
enters the same `translation_review` workflow rather than publishing directly.
Submission answer values are respondent-supplied free text and are not
auto-translated; they stay in the language the respondent wrote them.

**The language-keeper gate is absolute.** A translated label or chrome string
stays in `draft` review state and is never served to a respondent until a
language keeper approves it. There is no builder override, no admin bypass, and
no charter exception that permits shipping unreviewed Anishinaabemowin. The
working term set for form chrome is part of the 20 to 30 term initial glossary
co-authored with a language keeper.

## Framework primitives used

- `waaseyaa/field`: `BundleTemplate` / `FieldTemplate` (F2), `FormDescriptorBuilder`
  and `FormFieldDescriptor` (F6); see [work-surface](https://github.com/waaseyaa/framework/blob/main/docs/specs/work-surface.md).
- `waaseyaa/api`: `FieldAutoSaveController` (F3) for per-field draft auto-save;
  `ResourceSerializer` and `SchemaPresenter` for field-access-aware output.
- `waaseyaa/access`: `AccessPolicyInterface` and `FieldAccessPolicyInterface`,
  `EntityAccessHandler`; field-access spec.
- `waaseyaa/field` classification engine: `ClassificationFieldAccessPolicy`,
  `ClassificationLabelDefinition`, `LabelInheritanceResolver`,
  `RoleBasedClearanceChecker`; classification-and-retention spec.
- `waaseyaa/validation`: declarative field validation rules.
- `waaseyaa/entity` and `waaseyaa/entity-storage`: entity types, two-axis
  revisionable plus translatable storage, `EntityRepository` persistence.
- `waaseyaa/audit`: `entity.write`, `entity.read`, `access.denied`,
  `classification.change` events; append-only OCAP log.
- `waaseyaa/structured-import`: `GfmTableImporter` (F5), an optional bulk-intake
  path for tabular submission import.
- `waaseyaa/ssr`: public form rendering for `public`-tier share links.
- Anokii offline substrate: Dexie plus Workbox plus the FSM sync engine over the
  framework two-axis revisions model (DIR-A002).
- Anokii translation pipeline: `translation_string`, contributor dashboard,
  `translation_review`, glossary, per-Nation override (DIR-A003).

## Open questions

- **JSON `values` map versus compiled per-form bundle.** The v0.1 sketch stores
  answers in a typed `values` map to avoid a schema migration per published form.
  A compiled-bundle path (one `BundleTemplate` per form on
  `anokii_form_submission`) gives real field definitions, native validation, and
  native field-access at the cost of a migration per form. Which becomes the
  default, and is there a clean upgrade from map to bundle?
- **Definition versus submission classification.** A form definition is
  config-like (governed, low-churn) while a submission is community data
  (append-only). Should definitions live under config sync (CMI) or stay
  ordinary revisionable entities? The two have different retention and review
  expectations.
- **Cross-Nation form sharing.** In shared-graph mode, can one Nation publish a
  form that another Nation's members may fill, and if so, which Nation owns the
  resulting submissions? The default sketch says the receiving Nation owns them,
  but a federated survey may want shared ownership.
- **Retention of withdrawn and draft submissions.** Draft submissions captured
  offline that never seal, and submissions a respondent withdraws, need a
  retention rule. Does the classification retention engine purge them on age, and
  what is the default window?
- **Per-field validation parity offline.** Server-side validation is canonical,
  but offline submission needs a client-side mirror of the rule set. How much of
  the framework validation rule catalog can run in the browser without drift?
- **File and attachment fields.** A repair-request or grant form often needs an
  upload. The framework `Attachment` primitive (F4) and the versioned-blob media
  layer cover storage, but the offline queueing of large binaries and their
  per-tier classification need their own design pass.
- **Language coverage fallback UX.** When a form's chrome is reviewed in
  Anishinaabemowin but an author's `description` is not yet reviewed, the form
  mixes languages. Is a partial-translation banner the right respondent-facing
  signal, and does the language keeper sign off on the mixed state?
