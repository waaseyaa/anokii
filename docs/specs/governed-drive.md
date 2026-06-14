# Governed Drive (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## 1. Purpose

Governed Drive is the file-storage surface of an Anokii Nation workspace. It gives
community staff, governance leads, and program coordinators a familiar place to
upload documents, organize them into a folder tree, keep prior versions of a file,
and share specific files outside the workspace, all without leaving the OCAP
guarantees that the rest of Anokii enforces. Where a consumer-grade drive treats a
share link as a convenience, Governed Drive treats it as a disclosure decision that
the Nation owns and can audit.

Every file in the Drive carries a classification tier (public, community, or
nation-restricted) inherited from its folder unless explicitly overridden. That tier
is not cosmetic: it bounds who can read the file, whether a share link can be minted
at all, and how long the blob is retained. The Drive is the place where the abstract
classification taxonomy in `config/classification.anokii-default.yaml` becomes a
concrete, day-to-day workflow. A coordinator dragging a funding agreement into the
"Nation Restricted" folder is exercising OCAP control, not just filing a document.

The Drive is deliberately distribution-generic. It does not encode any one Nation's
program structure. It ships an empty root and a small set of conventional starter
folders that a tenant bootstrap can seed, and it leans entirely on framework
primitives (media, attachment, classification/retention, the OCAP audit log) so that
the governance behavior is identical whether the deployment is a single sovereign
Nation or a shared OIATC graph.

## 2. Tier applicability

Anokii runs in two deployment shapes. Governed Drive behaves differently in each, but
the difference is in scope and federation, never in whether governance applies.

### Sovereign single-tenant (FNPI, Intersnipe)

One Nation (or one umbrella organization) owns the whole Drive. The folder tree, the
files, and every share grant belong to that Nation. There is no cross-Nation read
path to reason about, so the practical effect of classification is internal: it
separates publicly publishable material (a posted notice, a logo) from member-only
working material (community) from sovereign material that must never leave a defined
circle (nation-restricted). Public-tier files may be surfaced to an anonymous,
SSR-rendered public site; community and nation-restricted files never are. Share
links in this mode disclose to named external recipients on the Nation's own terms.

### Shared-graph multi-tenant (OIATC)

Multiple Nations share one Anokii graph. Each file is owned by exactly one Nation
(its `owner_nation`), and the classification tier governs what other Nations on the
graph can see. A `public` file is readable graph-wide. A `community` file is readable
by members of the owning Nation and by federated partner Nations, but not by
unaffiliated tenants. A `nation-restricted` file is `Forbidden` to every account that
is not a member of the owning Nation, enforced at the field level by the framework's
`FieldAccessPolicyInterface`, exactly as the charter's DIR-A005 mandates. No graph
operator, OIATC steward, or admin role bypasses this; there is no cross-Nation
"super-read." What is held in the graph for a given Nation is the Nation's data; what
is public is only the public tier.

## 3. User-facing surface

The Drive is an admin-SPA surface (Nuxt 3 + Vue 3, per framework DIR-007). The concrete
screens and actions for v0.1:

- **Browse.** A two-pane view: a folder tree on the left, file list on the right. Each
  row shows name, size, classification tier (as a labeled badge, not color alone), the
  last-modified time, and a version count. The breadcrumb above the list reflects the
  current path.
- **Upload.** Drag-and-drop or a file picker. On upload the user confirms (or accepts
  the inherited) classification tier. Upload works offline and queues (see section 7).
- **New folder.** Create a child folder; it inherits the parent's tier by default and
  the creator may set a more restrictive tier.
- **Move / rename.** Reparenting a file or folder re-evaluates inherited classification
  on next write (the framework does not eagerly cascade; see section 6).
- **File detail.** A panel showing metadata, the version history (each version with its
  size, hash short-prefix, uploader, and timestamp), and the active share grants.
- **Upload new version.** Adds an immutable version to the same file; the prior version
  remains retrievable from the history.
- **Restore version.** Promotes a prior version to current. This is a forward action
  (a new pointer move), never a destructive rewrite.
- **Share.** Opens the share dialog. The user picks a recipient (an account on the graph)
  or requests an external link, sets an expiry, and optionally narrows the grant. The
  dialog disables link creation for nation-restricted files (see section 6).
- **Manage shares.** List, and revoke, the grants on a file.

Hard denials (a forbidden read) and soft denials (a capability not granted this
session) are announced through live regions per DIR-A001; see section 8.

## 4. Data model

Governed Drive introduces two Anokii-owned entity types and reuses three framework
primitives. All persistence goes through the entity system and `EntityRepository`;
the surface never issues raw SQL.

### `drive_folder` (Anokii entity)

The tree node. One row per folder.

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | identity | standard entity keys |
| `name` | string | folder display name |
| `parent_uuid` | string (nullable) | null = Drive root; resolves the tree |
| `owner_nation` | string | `nation_short` of the owning Nation |
| `classification_label` | classification field | the tier; drives inheritance to children and files |
| `created_at` / `updated_at` | datetime | lifecycle |

`parent_uuid` is the `ClassificationParentResolverInterface` anchor: a folder inherits
its parent folder's label unless it sets its own.

### `drive_file` (Anokii entity, hybrid storage)

The governed file record. This is the "hybrid storage" idea: `drive_file` is a thin
governance-and-metadata entity that points at the framework media layer for the actual
bytes. It holds no blob itself.

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | identity | standard entity keys |
| `name` | string | file display name |
| `folder_uuid` | string | parent `drive_folder`; classification parent |
| `owner_nation` | string | `nation_short` of the owning Nation |
| `media_uuid` | string | the framework `media` entity that owns the versioned blobs |
| `classification_label` | classification field | inherited from `folder_uuid` unless overridden |
| `current_version_vid` | int | which `MediaVersion` is the active download |
| `created_at` / `updated_at` | datetime | lifecycle |

### Reused framework primitives

- **`media`** (Layer 2) is the parent of the versioned blobs. `drive_file.media_uuid`
  references it.
- **`MediaVersion`** (the versioned-blob-media abstraction, DIR-005) is the immutable
  per-version blob pointer: each row carries a content-addressed `blob_uri` plus a
  `sha256`, with a monotonic `vid` scoped to the parent media UUID. Uploading a new
  version appends a `MediaVersion`; identical bytes deduplicate by hash. Restore moves
  `current_version_vid`; it never deletes a version. The CAS lineage stays append-only
  and auditable, which is exactly the property the Drive needs.
- **`drive_share`** (Anokii entity) is the share grant, modeled on the framework's
  `genealogy_share` grant-document pattern rather than a generic relationship edge.

### `drive_share` (Anokii entity)

An authorization-bearing grant document, one row per active or historical share.

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | identity | standard entity keys |
| `drive_file_uuid` | string | the file being shared (required) |
| `grantee_uid` | int (nullable) | recipient account, for in-graph shares |
| `link_token` | string (nullable) | opaque token for an external link share |
| `tier_at_grant` | string | the file's classification tier when minted (frozen for audit) |
| `expires_at` | datetime (nullable) | lifecycle; empty plus empty `revoked_at` = active |
| `revoked_at` | datetime (nullable) | revocation timestamp |
| `created_by` | int | the granting account |
| `created_at` | datetime | mint time |

A grant is either to an in-graph account (`grantee_uid`) or an external link
(`link_token`), never both. `tier_at_grant` is frozen so that a later
reclassification of the file is visible in audit against what the grant was issued
for.

## 5. Access and classification

Governed Drive does not implement its own access logic. It composes the framework's
classification engine and OCAP wiring, per charter DIR-A005 (surface code never
bypasses `AccessChecker` / `FieldAccessPolicyInterface`).

### Classification and inheritance

`drive_folder` and `drive_file` both carry a `classification_label` field backed by the
framework's classification field type. A `ClassificationParentResolverInterface` for
each type resolves the parent: a folder's parent is its `parent_uuid` folder; a file's
parent is its `folder_uuid`. On every save the framework's
`EntityLifecycleSubscriber` resolves the effective label, records
`classification_inherited_from` when inherited, and writes a `classification.change`
audit event on any effective-label change. Cascade is re-evaluate-on-next-write, so
moving a folder relabels its descendants as they are next touched, not eagerly.

### Three tiers to field access

The Anokii default taxonomy maps the three tiers to field-access semantics:

- **public** to `Neutral` (readable graph-wide).
- **community** to `Neutral` for members and federated partner Nations.
- **nation-restricted** to `Forbidden` for any account outside the owning Nation,
  enforced by the framework's `ClassificationFieldAccessPolicy` (a
  `FieldAccessPolicyInterface` implementation). Open-by-default field semantics mean a
  file with no restrictive label stays readable; only a forbidding label restricts.

The cross-Nation read block in shared-graph mode is therefore the framework's job, not
the surface's. The Drive simply persists the right label.

### Share grants and the tier ceiling

A `drive_share` cannot exceed the file's tier ceiling:

- **public** files may be shared by in-graph grant or external link.
- **community** files may be shared by in-graph grant to a member or federated partner
  account; external links require an explicit governance capability (a granted
  permission), not the default author role.
- **nation-restricted** files may never be shared by external link. The share dialog
  disables link minting for them, and the server independently refuses
  (`drive_share` creation returns Forbidden) so a forged client request cannot succeed.
  In-graph grants to fellow members are still allowed because they do not leave the
  Nation's circle.

### Hold semantics

A `hold-*` classification label (legal, research, ethics-review) forbids read for
anyone lacking `legal-hold-bypass`, even an admin, and short-circuits clearance. Held
Drive files are never purged or redacted; they remain present and blocked at read.
This is the framework hold rule applied unchanged.

### Audit expectations

Every meaningful Drive action lands in the unified OCAP audit log (append-only):

- Upload / new version: `media.version.created` (and `media.version.dedup_hit` when the
  blob deduplicates by hash).
- Download / version read: `media.version.read`.
- Reclassification: `classification.change`.
- Forbidden read or forbidden share attempt: `access.denied`.
- Restore (current-pointer move): a `revision.publish` / `revision.revert`-shaped
  pointer event where applicable.

Offline Drive operations carry an `offline_at` timestamp and reconcile on sync, so the
audit trail preserves temporal ordering (DIR-A002, DIR-A005). Share creation,
acceptance, and revocation emit domain events on the foundation event bus; for a Nation
that mandates compliance-grade retention these route into the immutable audit log.

## 6. Offline-first behavior

Governed Drive must function offline per DIR-A002. The offline substrate is the
charter's: Dexie (IndexedDB) for local state, Workbox for the service worker, and the
FSM sync engine composing on the framework's two-axis revisions model.

What works offline:

- **Browse** the folder tree and file metadata that was cached while online, scoped to
  the user's own classification reach. Partial-trust offline operation permits reading
  the user's own classified data offline but never another Nation's cached data.
- **Open** a cached version's bytes if that version's blob was pinned for offline use.
- **Upload** a new file or a new version. The bytes and metadata are written to the
  local Dexie store and the operation is queued.

How it syncs:

- The `MediaVersion` CAS model maps cleanly onto offline queuing: a queued upload is a
  pending append. On reconnect the engine computes the `sha256`, and if the blob already
  exists server-side the version deduplicates (a `media.version.dedup_hit`) rather than
  re-uploading.
- For governed community data the default queue strategy is multi-submission-merge:
  every queued upload becomes a distinct version, never silently overwritten. This is
  the governance-correct default. The last-write-wins strategy is available only as an
  opt-in classification flag for administrative-config-style files where latest-is-
  canonical is genuinely correct.
- Classification labels set offline are provisional; the server re-resolves inheritance
  on sync and the effective label that lands is the framework's, with its
  `classification.change` audit event written at reconciliation time.

A file the user lacks clearance for is not cached offline in the first place, so there
is no offline path to read past a tier the user could not read online.

## 7. Accessibility

Governed Drive meets WCAG 2.1 Level AA and the AODA procurement-legibility
requirements per DIR-A001. Accessibility is a design constraint here, gated by axe-core
CI and per-component Vitest plus Playwright tests; a Drive screen without an axe-core
baseline is a charter violation.

Surface-specific commitments:

- **Classification badges convey tier by text and shape, not color alone.** The tier
  label ("Public", "Community", "Nation Restricted") is always present as text.
- **The folder tree is a keyboard-navigable tree widget** with correct `role="tree"`,
  `role="treeitem"`, `aria-expanded`, and `aria-level`, fully operable without a mouse.
- **The file list is a semantic table** with header cells, sortable by keyboard, with a
  visible focus indicator on every interactive row control.
- **Drag-and-drop upload has a keyboard-and-pointer-equivalent file picker.** No action
  is drag-only.
- **Access-denied messages use live regions.** Hard denials (a forbidden read or a
  forbidden share) announce via `aria-live="assertive"`; soft denials (a capability not
  granted this session, such as external-link minting without the permission) announce
  via `aria-live="polite"`.
- **The share dialog has visible, persistent labels** on every input (recipient,
  expiry, scope); no placeholder-only fields.
- **Upload and sync progress is announced** with a progress role and polite updates, so
  a non-visual user knows when a queued offline upload has reconciled.

## 8. Indigenous-language and translation

Per DIR-A003 the Indigenous-language pipeline is a product layer, not a toggle, and it
applies to Governed Drive on two distinct planes.

**Interface chrome.** Every piece of Drive UI copy (button labels, column headers, the
tier names, dialog text, the access-denied messages, sync-status strings) is a
`translation_string` entity, extracted by the pipeline's extraction tooling rather than
hardcoded. Anishinaabemowin renderings flow through the `translation_review` workflow
and the glossary entity, with a per-Nation override layer so Sagamok's southern-Ojibwe
("oji" / `southern-ojibwe`, per its tenant stub) can differ from another Nation's
dialect. No Anishinaabemowin string ships to a Drive screen without language-keeper
review; this gate is absolute and not subject to a charter exception.

**File and folder content.** Folder names and file display names are user-authored
content, not interface chrome, so they are not auto-translated. Where a Nation chooses
to maintain a folder or file name in both English and Anishinaabemowin, the name field
participates in the framework's translatable-storage axis (the same two-axis shape the
pipeline mirrors), so a localized name is a first-class translation, not a second
record. Governed Drive does not itself attempt machine translation of file contents in
v0.1; document-body translation is out of scope here and would be a separate surface
decision under the same language-keeper gate.

The glossary terms that matter to this surface (the words chosen for "share", "folder",
"restricted", "version") are co-authored with a language keeper as part of the pilot's
20 to 30 term initial glossary, not invented by implementers.

## 9. Framework primitives used

- `waaseyaa/media` and the versioned-blob-media abstraction (`MediaVersion`, DIR-005)
  for content-addressed, deduplicated, append-only blob versions.
- `waaseyaa/attachment` parent-delegated access pattern as the model for how
  `drive_file` defers access decisions to its governing parent.
- `waaseyaa/field` classification and retention engine: `classification_label` field
  type, `ClassificationParentResolverInterface`, `LabelInheritanceResolver`,
  `ClassificationFieldAccessPolicy`, hold semantics, and `RetentionPolicy`.
- `waaseyaa/access`: `AccessPolicyInterface` and `FieldAccessPolicyInterface`
  (open-by-default field semantics, deny-by-default entity semantics) via
  `EntityAccessHandler` and `AccessChecker`.
- `waaseyaa/audit`: the append-only OCAP log, `media.version.*`,
  `classification.change`, and `access.denied` event kinds, with `offline_at`
  reconciliation.
- `waaseyaa/entity` and `waaseyaa/entity-storage`: entity types, `EntityRepository`,
  the two-axis (translatable) storage shape for localizable names.
- `waaseyaa/admin` (admin SPA, DIR-007) for the Nuxt 3 / Vue 3 surface and the existing
  media version browser composables as a starting point.
- Specs referenced: `work-surface.md`, `classification-and-retention.md`,
  `field-access.md`, `ocap-audit-log.md`, `entity-storage-two-axis.md`,
  `revision-system-unified.md`, `genealogy-share.md`.

## 10. Open questions

- **Folder-tree depth and move cost.** Re-evaluate-on-next-write avoids eager cascade,
  but a deep move can leave many descendants with a stale effective label until they are
  touched. Do we need a bounded background re-resolution sweep, or is lazy resolution
  plus an audit-visible "pending relabel" badge sufficient for v0.1?
- **External link recipients and identity.** An external `link_token` share names no
  account. How do we record disclosure responsibility (a required recipient label? an
  email captured at mint?) so the audit trail is meaningful when the recipient is not on
  the graph?
- **Federated partner definition.** Community-tier cross-Nation reads depend on "federated
  partner Nations." Where does that partnership list live, who edits it, and how is it
  itself classified? This likely belongs in tenant config, not the Drive.
- **Retention versus version lineage.** Retention purge deletes age-eligible entities,
  but the CAS lineage is append-only by design. How do purge and the immutable
  `MediaVersion` chain reconcile, especially for a held file that retention would
  otherwise touch?
- **Offline blob pinning policy.** Which versions get pinned for offline reading, and how
  much local quota does a Nation workspace get before the service worker must evict?
- **Quota and abuse bounds.** Per-Nation storage quotas, max file size, and upload-rate
  limits are unspecified. These interact with the shared-graph mode (one Nation must not
  exhaust shared storage) and need a governance owner.
- **Relationship to sibling surfaces.** Where a shared file also needs a structured,
  audited disclosure context, does Governed Drive hand off to [Data Rooms](data-rooms.md),
  or does it own that case directly? The boundary needs a decision.
