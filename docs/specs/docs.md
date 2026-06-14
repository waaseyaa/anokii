# Docs (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## 1. Purpose

Docs is the collaborative long-form authoring surface in a Nation workspace. It is where governance staff, council members, language keepers, and program coordinators draft and steward the prose that a Nation actually runs on: council resolutions, policy text, community letters, grant narratives, meeting minutes, and program reports. Where the [Form Builder](forms.md) captures structured community data and [Sheets](sheets.md) holds tabular records, Docs holds the connected, paragraph-shaped text that carries intent and context.

Two product commitments shape the whole surface. First, every document is a governed record: it carries a classification, it lives behind the framework's OCAP access pipeline, and every meaningful change is attributed to a real account and kept in revision history. A document is never an opaque blob that bypasses the Nation's disclosure controls. Second, authoring is collaborative but conflict-honest. Multiple editors work the same document, often from different devices and often offline, and the surface resolves their concurrent edits through the framework's optimistic-locking contract rather than silently dropping someone's words.

Docs is built entirely on Waaseyaa primitives. It introduces no new storage engine and no parallel persistence path. It composes the revisionable entity system (revision-system-unified), the `node`-style content entity shape, classification-and-retention for labels, and the access-control field policies. Anokii's contribution is the opinionated product: the screens, the offline draft-then-sync behavior, the accessibility baseline, and the Indigenous-language pathway over that substrate.

## 2. Tier applicability

Docs runs in both deployment tiers. The same entity model and access policies apply in each; what differs is the breadth of the graph a reader can traverse and what, if anything, is public.

**Sovereign single-tenant (FNPI, Intersnipe).** The whole document graph belongs to one Nation or organization. Classification still governs internal visibility (a `nation-restricted` council document is hidden from a `community`-tier program coordinator who lacks clearance), but there is no cross-Nation read surface to defend against. A document marked `public` may be surfaced through the framework's SSR rendering for an external website (the FNPI public-site pattern); `community` and `nation-restricted` documents never leave the authenticated workspace. Retention and hold policies run on the single tenant's schedule.

**Shared-graph multi-tenant (OIATC).** Many Nations share one deployment and one entity graph. Each document is owned by exactly one Nation (carried on the document's `owner_nation` field, seeded from the per-tenant `nation_short` in the tenant config). Cross-Nation reads are decided by the classification field policy against the Anokii default taxonomy: `public` documents are readable by authenticated members of any Nation; `community` documents are readable by the owning Nation and federated partner Nations; `nation-restricted` documents are `Forbidden` to every account outside the owning Nation, enforced by `FieldAccessPolicyInterface` and never by surface code. No document body, title, or revision metadata for another Nation's `nation-restricted` document is ever returned, cached, or synced to a non-member device.

In both tiers the persisted shape is identical. The tier is a deployment fact, not a fork in the data model, so a Nation can migrate from a shared OIATC graph to a sovereign single-tenant deployment without rewriting its documents.

## 3. User-facing surface

Concrete screens and actions for v0.1:

- **Document list.** A filterable index of documents the account may view, grouped by classification tier with a visible label chip on each row. Filters: tier, owner Nation (multi-tenant only), status (draft / published), and last-edited. Create action opens a new draft.
- **Editor.** A long-form rich-text editor (block-structured: headings, paragraphs, lists, quotes, tables, and inline references to other workspace entities). The editor shows the current classification, the published-versus-draft state, and a live presence indicator of other editors in the document. Saving is explicit (a Save action) with an autosave-to-local-draft safety net; explicit Save is what creates a framework revision.
- **Revision history.** A reverse-chronological list of revisions with editor attribution (who, when, and an optional revision log message), a side-by-side diff between any two revisions, and a Restore action that creates a new revision rather than mutating history.
- **Classification control.** An author with the right permission sets or changes the document's classification label. Changing a label is itself audited.
- **Publish / unpublish.** Moves the published-revision pointer to the current revision (publish) or clears it (unpublish). Publishing a `public` document in a sovereign deployment is what makes it eligible for SSR rendering on the external site.
- **Conflict banner.** When a Save is rejected because another editor moved the document's head revision, the editor surfaces a non-destructive conflict banner offering Review-and-merge rather than overwriting.
- **Offline indicator.** A persistent status showing whether the document is synced, has local-only pending changes, or is read-only because it is another Nation's cached data (which is never editable offline).

## 4. Data model

Docs introduces one primary Anokii entity and reuses framework entities. All persistence goes through `EntityRepository` and the entity system; no surface code issues raw SQL.

**Entity `anokii_doc`** (revisionable; single-axis by default, translatable only where a Nation enables it, see Section 9).

| Field | Type | Notes |
|---|---|---|
| `id` | id | Entity id. |
| `uuid` | uuid | Stable cross-deployment identifier. |
| `title` | string | Label field. Carries a classification field policy. |
| `body` | text (block JSON) | The document content as structured blocks. |
| `summary` | text | Optional short abstract for the list view and SSR. |
| `owner_nation` | string | `nation_short` of the owning Nation; set at create, immutable. |
| `classification_label` | classification label | From classification-and-retention; drives field access. |
| `status` | string | `draft` or `published` (mirrors the published-revision pointer). |
| `doc_type` | string | Editorial category (resolution, policy, minutes, letter, report). |

The revision metadata block is provided by the framework on every revision row and is not redefined here: `revision_created`, `revision_log`, and `revision_author` (the acting account uid, nullable, soft FK so history survives user deletion). Docs reads these back through `RevisionMetadata` for the history screen rather than maintaining its own author column.

Reused framework and Anokii entities:

- **`node`** is the conceptual model Docs follows (a revisionable content entity with a published-revision pointer). `anokii_doc` is its Anokii-opinionated sibling, not a replacement.
- **Attachments** ride the work-surface `Attachment` entity with `ParentDelegatedAccessPolicy`, so files attached to a document inherit the document's access decision and orphaned attachments deny by default.
- **`anokii_doc_comment`** (optional, v0.1 stretch) is a lightweight child entity for inline editorial comments, parented to `anokii_doc` and access-delegated to it. Comments are draft-by-nature and are not part of the published body.

Classification labels are data, not code: the Anokii default taxonomy (`public`, `community`, `nation-restricted`) maps onto the framework's classification label vocabulary so a Nation can extend or override its labels through `config:import` without a code change.

## 5. Access and classification

Docs inherits the framework OCAP pipeline verbatim and adds no backdoor. Access is decided in two layers, both owned by the framework.

**Entity-level access.** A registered `AccessPolicyInterface` for `anokii_doc` decides view / update / delete, using deny-unless-granted semantics (`isAllowed()`). In multi-tenant mode the policy reads `owner_nation` and the requesting account's Nation membership; cross-Nation update and delete are never granted. Anonymous and unauthenticated requests are denied except for `public` documents surfaced through SSR, which are served read-only through the published-revision pointer.

**Field-level access.** The cross-cutting `ClassificationFieldAccessPolicy` (registered for `'*'`) applies to `anokii_doc` like any other entity. Open-by-default holds: a field is accessible unless the policy returns `Forbidden`. The three Anokii tiers resolve as the charter specifies: `public` is `Neutral`, `community` is `Neutral` for owning and federated-partner members, and `nation-restricted` is `Forbidden` for any cross-Nation read. Hold labels (`hold-legal`, `hold-research`, `hold-ethics-review`) short-circuit ahead of clearance: a held document is present but blocked at read time for any account without the `legal-hold-bypass` permission, and held documents are never purged or redacted by retention jobs.

**Revision access.** Per-revision view and restore compose through the framework's `RevisionPolicyComposition`, so a role can be granted history visibility independently of edit rights, and (where a document is translatable) per-language history visibility can differ.

**Permissions.** Docs leans on plain string roles (the framework has no central role catalogue). The sketch uses four capability strings, mapped to roles per Nation through config rather than hardcoded: `docs.view` (read within clearance), `docs.edit` (create and revise a draft), `docs.classify` (set or change a document's label), and `docs.publish` (move the published-revision pointer). `docs.classify` and `docs.publish` are governance acts and default to the `editor` and `nation-steward` roles; `docs.view` and `docs.edit` are broader. None of these grants bypass the classification field policy or the hold short-circuit, and none implies `legal-hold-bypass`.

**Audit expectations.** Every governed action writes to the unified OCAP audit log: document create, each revision-creating Save (carrying `revision_author`), publish and unpublish (the `revision.publish` / `revision.revert` pointer-move events), classification-label changes (`classification.change`), access-denied reads, and deletes. Offline operations carry an `offline_at` timestamp and reconcile into the audit log on sync, preserving temporal ordering. The audit log is append-only; Docs never edits or deletes audit rows.

## 6. Offline-first behavior

Docs functions in offline-degraded mode, per the offline-first baseline (see [Offline-first baseline](offline-first.md)). It does not require connectivity for read-after-write within the user's own classification scope.

**What works offline.** Reading any document already synced to the device within the account's clearance; creating a new draft; editing a draft; queuing a classification change and a publish request. The editor's block content is held locally so an author can keep writing through a connectivity gap.

**Local store and sync.** The offline substrate is Dexie (IndexedDB) plus a Workbox service worker and the FSM-based sync engine. Documents map onto the framework's revisions model: the `(entity_id, langcode, revision_id)` tuple from the two-axis revisions design maps directly to a Dexie composite key, so local revisions carry the same identity they will have on the server. A queued Save replays on reconnect as an `EntityRepository::save()` with `SaveContext::withExpectedRevisionId(n)`, stating the revision the author was editing from.

A document's local sync state moves through a small set of FSM states that drive the offline indicator: `synced` (local matches the server head), `dirty` (local edits not yet sent), `syncing` (a replay is in flight), `conflict` (the server rejected the replay with `RevisionConflictException` and the author must review-and-merge), and `read_only` (another Nation's cached data, never editable here). Re-auth on reconnect is required before any queued Save replays, because the access decision must be made against a live token, not a stale cached one.

**Conflict resolution.** Docs uses the framework's optimistic-locking contract, not last-write-wins. On reconnect, if a competing writer has moved the head, the guarded pointer claim fails and the server throws `RevisionConflictException` carrying the current head. The surface maps that to the conflict banner and a Review-and-merge flow; the author's offline words are never silently discarded. This is the governed-data default (every submission is a record), distinct from the LWW opt-in the baseline reserves for single-admin config records, which does not apply to collaborative documents.

**Cross-Nation safety offline.** Partial-trust offline operation permits reading the user's own classified data offline but never another Nation's cached data. A document owned by a different Nation that the account can read online is held read-only offline and is never editable or re-syncable from that device.

## 7. Accessibility

Docs meets WCAG 2.1 Level AA and the AODA procurement-legibility requirements, per the AODA baseline (see [AODA baseline](accessibility.md)). Accessibility is a design constraint here, not a follow-up.

Surface-specific commitments:

- **Editor semantics.** The block editor exposes a correct heading outline and list semantics to assistive technology; structural blocks are real landmarks and headings, not styled `div` elements. Keyboard operation covers every authoring action, including block insertion, reordering, and the toolbar, with a documented shortcut set and a visible focus ring throughout.
- **Access-denied announcements.** Hard denials (server-side OCAP `Forbidden`, for example opening a `nation-restricted` link the account cannot read) announce through `aria-live="assertive"`. Soft denials (a capability not granted in this session) announce through `aria-live="polite"`.
- **Conflict and sync status.** The conflict banner and the offline indicator are live regions so a screen-reader user learns a Save was rejected or that changes are pending without polling the visual layout.
- **Diff legibility.** The revision diff does not rely on color alone; added and removed text carry text and symbol cues as well as color, meeting non-color-only contrast guidance.
- **Labels.** All controls (classification selector, publish toggle, comment fields) have visible, persistent labels; no placeholder-only inputs.

An axe-core baseline runs in CI for the Docs surface, with per-component Vitest and Playwright accessibility tests. Shipping without that baseline is a charter violation, not a quality shortcut.

## 8. Indigenous-language and translation

Docs participates in the Indigenous-language pipeline (DIR-A003) on two distinct planes, and both honor the language-keeper gate.

**Surface UI.** Every string in the Docs interface (button labels, status text, the conflict banner, accessibility announcements) flows through the `translation_string` pipeline: extraction tooling pulls the English source strings, the contributor dashboard collects Anishinaabemowin candidates, and the `translation_review` workflow gates them through the glossary and a per-Nation override layer. No Anishinaabemowin UI text ships without language-keeper review.

**Document content.** A Nation may enable translatable documents, which turns `anokii_doc` into a two-axis entity (revisionable and translatable). Each language then keeps its own independent per-language revision history in the `<entity>__translation__revision` table, with independent sequencing, so editing the English text does not bump the Anishinaabemowin revision count and vice versa. A southern-Ojibwe translation of a council resolution is a true peer with its own base row and its own revision sequence, not an overlay on the English row. The pilot pairing is English and Anishinaabemowin (southern and northern Ojibwe dialects), aligned with the Sagamok tenant config (`oji`, `southern-ojibwe`).

**Hard gate.** No Anishinaabemowin text, whether a UI string or document body content shipped as a fixture or seed, enters the distribution without language-keeper review. This gate has no time-bounded exception; the charter forbids one.

## 9. Framework primitives used

- `waaseyaa/entity` and `waaseyaa/entity-storage`: `EntityRepository`, `SaveContext`, the `node`-style content entity shape (entity-system).
- Revision system (revision-system-unified): revisionable single axis, the optional translation axis (`<entity>__translation__revision`), `revision_author` provenance, `RevisionMetadata`, and the optimistic-locking `withExpectedRevisionId` / `RevisionConflictException` contract.
- `waaseyaa/field` classification-and-retention: `ClassificationLabelDefinition`, `ClassificationFieldAccessPolicy`, clearance, hold semantics, retention jobs.
- `waaseyaa/access`: `AccessPolicyInterface`, `FieldAccessPolicyInterface`, `RevisionPolicyComposition`.
- `waaseyaa/audit`: the append-only OCAP audit log (ocap-audit-log) and the `revision.publish` / `revision.revert` / `classification.change` event kinds.
- Work surface (work-surface): `Attachment` and `ParentDelegatedAccessPolicy` for document attachments; `FormDescriptorBuilder` for classification and metadata sub-forms.
- `waaseyaa/ssr`: published, `public`-tier rendering for the sovereign external-site pattern.
- Offline substrate (Dexie, Workbox, FSM sync) composing the two-axis revisions tuple as a Dexie composite key, per Anokii DIR-A002.
- `translation_string` and `translation_review` pipeline (DIR-A003) for UI and content localization.

## 10. Open questions

- **Body format.** Block-JSON gives structure and accessibility, but a long-form merge over block JSON is harder than a line-based text merge. Does v0.1 ship merge as block-level (accept-theirs / accept-mine per block) and defer character-level merge, or adopt a CRDT-style body that the framework's revision model would then wrap rather than own? The optimistic-locking contract gives a clean conflict signal either way; the open question is the merge UX, not the detection.
- **Comments scope.** Is `anokii_doc_comment` in v0.1 or deferred? Inline comments add a second access surface (a comment on a `nation-restricted` paragraph) that must inherit the parent's classification cleanly.
- **Federated-partner read in multi-tenant.** The charter says `community` is readable by federated partner Nations, but the partner-federation relationship is not yet modeled in the tenant config. Where does the partner list live, and who in OIATC stewards changes to it?
- **Translatable-by-default.** Should `anokii_doc` be translatable for every Nation from the start (paying the two-axis cost everywhere) or opt-in per Nation as drafted here? The pilot Nations want it; sovereign single-language deployments may not.
- **Presence and live cursors.** The v0.1 presence indicator is coarse (who is in the document). True live co-editing (shared cursors, sub-second sync) would need a real-time transport. Is that in scope for v0.1, or does explicit-Save-plus-conflict-banner carry the first release?
- **SSR cache invalidation.** When a `public` document is unpublished or reclassified to `community`, the SSR cache and any CDN copy must drop it promptly. What is the invalidation path, and what is the acceptable window?
