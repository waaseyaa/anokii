# Data Rooms (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## Purpose

A Data Room is a secure, access-bounded container for the most sensitive material a Nation holds: treaty negotiation files, land-claim evidence bundles, litigation holds, health or membership records under review, sacred or ceremonial documentation, and any other corpus where disclosure is a sovereign decision rather than a default. Where the broader Anokii workspace is open-by-default at the field level, a Data Room inverts the posture: nothing inside is visible until membership and clearance both grant it, and every read leaves a record.

Data Rooms exist because some governance work cannot happen in the open. A council preparing for a negotiation needs a place to assemble documents that is gated to the negotiating team, watermarked on the way out, and fully auditable after the fact, so that if a leak is alleged the Nation can answer "who saw this, when, and what did they take" from the OCAP audit log rather than from memory. The room is the unit of trust: a member is admitted to a room (not to a document), and that admission is itself a recorded, revocable governance act.

This surface leans hard on three framework substrates and adds almost no new access logic of its own. Membership and clearance ride the existing `AccessChecker`, `AccessPolicyInterface`, and `ClassificationFieldAccessPolicy`. Auditing rides the append-only OCAP audit log. Document storage and watermarked exports ride the `MediaVersion` content-addressed store. Anokii contributes the room as an entity, the membership model, the watermark-and-export workflow, and the AODA, offline, and Indigenous-language behavior on top.

## Tier applicability

Data Rooms behave the same way in both deployment tiers at the access-decision layer, because the OCAP enforcement is the framework's, not the tier's. What differs is what data is held and how room boundaries map onto Nation boundaries.

**Sovereign single-tenant (FNPI, Intersnipe).** The whole installation belongs to one Nation or one organization. Every room lives behind that Nation's own clearance ladder. Cross-Nation reads are not a concern because there is no other Nation in the graph, so the `nation-restricted` tier collapses to "members of this Nation with sufficient clearance." Rooms can hold the Nation's most sensitive corpus without any federation question arising. The only boundary that matters is room membership plus classification clearance.

**Shared-graph multi-tenant (OIATC).** Several Nations share one installation and one entity graph. Here a Data Room is always owned by exactly one Nation (`nation_short`, carried verbatim from the tenant config such as `config/tenants/sagamok.yaml`), and the `nation-restricted` classification tier does real work: a member of Nation A can never read a Data Room owned by Nation B, even if some federated partner relationship exists at the `community` tier, because nation-restricted material returns `Forbidden` from `FieldAccessPolicyInterface` on every cross-Nation read. Nothing about a room owned by another Nation is public. A user who is not a member of the owning Nation does not see the room exist, does not see its title, and cannot enumerate its documents. The shared graph never becomes a side channel: room boundaries are enforced at the same place individual classification is.

What is public in either tier: nothing inside a room. A room may optionally publish a non-sensitive existence notice (for example "the Land Claim 2026 room exists and is administered by the Lands office") so that members know to request access, but that notice is a separate `public`-tier entity authored deliberately, never an automatic leak of room contents.

## User-facing surface

Concrete screens and actions, framed as a Nuxt 3 admin SPA surface (framework DIR-007 conventions).

1. **Rooms index.** A list of the Data Rooms the current account may see. Membership-gated: a room the account is not admitted to does not appear. Each row shows room title, owning Nation (multi-tenant only), member count, document count, and the account's own role in the room.
2. **Room overview.** Title, purpose, owning Nation, classification floor (the minimum classification every document in the room inherits), current hold status, and the member roster (visible to room stewards, summarized for ordinary members).
3. **Document list.** The documents held in the room, each showing filename, classification label, latest version, who last touched it, and whether it is under a retention hold. Documents the account's clearance does not reach are not listed.
4. **Document viewer.** In-room reading of a document. The viewer reads the latest `MediaVersion` blob through the access pipeline; a `media.version.read` audit event fires on open. No raw blob URL is ever exposed to the browser.
5. **Request export.** A deliberate, friction-bearing action. The user states a reason; the system produces a watermarked rendering of the document (member name, account id, room, timestamp, and a per-export nonce burned into the page), records an `entity.export` audit event, and delivers the watermarked artifact. Plain (un-watermarked) export is a separate stewards-only capability.
6. **Manage membership (stewards).** Admit a member, set their room role, or revoke. Every admission and revocation is a recorded governance act with its own audit trail.
7. **Place / lift hold (stewards plus legal-hold-bypass).** Apply a `hold-legal`, `hold-research`, or `hold-ethics-review` classification to the room or to a single document. Held material stays present but is blocked at read for everyone lacking the bypass permission, including admins.
8. **Audit view (stewards).** A room-scoped slice of the OCAP audit log: who opened, exported, was admitted, or was denied, ordered newest first.

## Data model

All persistence goes through the framework entity system (`EntityRepository`, `SqlStorageDriver`), never raw SQL. Anokii introduces three entity types and reuses the framework's `MediaVersion` and audit substrates.

**`data_room`** (new Anokii entity, content entity, revisionable so governance changes to a room are themselves versioned).

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | identity | standard entity keys |
| `title` | string | room name; translatable (see Indigenous-language section) |
| `purpose` | text | why the room exists; translatable |
| `nation_short` | string | owning Nation, from tenant config; the cross-Nation boundary key |
| `classification_floor` | classification label | minimum label inherited by every document; drives `ClassificationFieldAccessPolicy` |
| `existence_notice_ref` | entity reference (nullable) | optional `public`-tier notice entity; null means the room is fully unlisted |

**`data_room_membership`** (new Anokii entity; one row per (room, account); the admission record).

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | identity | standard entity keys |
| `room_uuid` | entity reference | the room |
| `account_uid` | int | the admitted account |
| `room_role` | string | `steward`, `contributor`, or `viewer` |
| `admitted_by` | int | account that admitted this member |
| `admitted_at` | datetime | admission timestamp |
| `revoked_at` | datetime (nullable) | set on revocation; rows are never deleted, so the roster history is preserved |

**`data_room_document`** (new Anokii entity; the in-room handle to stored content).

| Field | Type | Notes |
|---|---|---|
| `id` / `uuid` | identity | standard entity keys |
| `room_uuid` | entity reference | parent room; access delegates to the room |
| `filename` | string | display name |
| `media_uuid` | entity reference | the framework `media` entity whose `MediaVersion` lineage holds the bytes |
| `classification_label` | classification label | explicit label; if unset, inherits the room `classification_floor` via `LabelInheritanceResolver` |

**Reused framework substrates:**

- `MediaVersion` (`waaseyaa/media`) holds the actual bytes as append-only content-addressed blobs (`blob_uri`, `sha256`), giving an immutable, auditable version lineage per document. Anokii never stores document bytes in its own tables.
- `audit_event` (`waaseyaa/audit`) is the append-only OCAP log. Data Rooms write and read it but never define a new audit table.
- `RetentionPolicy` and the `hold-*` classification labels (`waaseyaa/field`) provide holds and retention without Anokii reimplementing them.

Membership and document rows are governed entities with identity and lifecycle, so they are real entities. They are not join tables, which is why they go through `EntityRepository` rather than `DatabaseInterface` directly.

## Access and classification

Data Rooms add one access policy and otherwise compose framework primitives. There are no admin backdoors (charter DIR-A005): every read, export, and membership change goes through the access pipeline.

**Room membership gate (`DataRoomAccessPolicy`, new).** A `#[PolicyAttribute]` policy registered for `data_room`, `data_room_document`, and `data_room_membership`. For any operation on a room or its children it resolves the owning room, looks up a non-revoked `data_room_membership` for the acting account, and returns:

- `Forbidden` when no live membership exists (deny-by-default at the entity level, which is the framework's `isAllowed()` semantics).
- `Allowed` when a live membership exists and the requested operation is within the member's `room_role` (viewers read, contributors add documents, stewards manage membership and holds).
- For documents, the policy delegates the parent lookup the same way `ParentDelegatedAccessPolicy` does for attachments, so a document inherits its room's membership decision.

**Classification layered on top.** Membership admits a member to the room; classification then decides which documents within it that member's clearance reaches. The room's `classification_floor` is inherited by every document via `LabelInheritanceResolver` on save, and `ClassificationFieldAccessPolicy` runs its two ordered rules:

1. **Hold override.** A `hold-*` label on the room or document forbids read for anyone without `legal-hold-bypass`, even a room steward and even an admin. Held material is never deleted or redacted; it stays present and is blocked at read, preserving the legal, research, or ethics trail.
2. **Clearance gate.** An account whose clearance (via `RoleBasedClearanceChecker`) is below the document's confidentiality level is forbidden. This is how `nation-restricted` documents stay inside the owning Nation: cross-Nation reads resolve to `Forbidden` per the Anokii default taxonomy (`config/classification.anokii-default.yaml`).

**Cross-Nation boundary (multi-tenant).** Because `nation-restricted` maps to `Forbidden` for cross-Nation reads, a room owned by Nation A is invisible and unreadable to Nation B's members at every layer: the rooms index (query-layer access checking, `SqlEntityQuery::setAccount`), the document list, and the viewer. The shared graph cannot be used to enumerate another Nation's rooms.

**Audit expectations.** Data Rooms are an audit-heavy surface by design; the OCAP audit log is the evidentiary spine.

| Action | Audit event kind | Notes |
|---|---|---|
| Open a document in the viewer | `media.version.read` | fired by the media version read path |
| Export (watermarked or plain) | `entity.export` | attributes carry room uuid, document uuid, watermark nonce, stated reason |
| Read denied (no membership or low clearance) | `access.denied` | the deny is recorded, not just the allow |
| Admit or revoke a member | `entity.write` | on the `data_room_membership` row; actor is the acting steward |
| Place or lift a hold | `classification.change` / `retention.hold` | classification label change is logged |
| Room created or governance-edited | `entity.write` plus `revision.publish` | room is revisionable |

The `actor_uid` three-state column attributes each event to the real acting account (session, never the document owner). Offline reads and exports carry an `offline_at` timestamp and reconcile on sync (see Offline-first). The audit log is append-only; the `AppendOnlyAuditDatabase` decorator guarantees no room steward can rewrite the history of who saw what.

## Offline-first behavior

Data Rooms honor charter DIR-A002: a member can work in a room while offline, within their own clearance scope, and the room never requires connectivity for read-after-write of material the member is already cleared to see.

**Works offline:**

- Reading documents the member has already opened (and whose blobs the service worker cached) while online. The cached blob is keyed by `sha256`, so the immutable content-addressed identity carries straight into Dexie composite keys.
- Adding a document or annotation as a contributor: queued locally and synced on reconnect. Governed room data uses the multi-submission-merge default (every submission is a record, never overwritten), so two contributors working offline never clobber each other.
- The room roster and the member's own role, cached at last sync.

**Does not work offline (by design):**

- Reading a document the member never opened online. Blobs are not pre-fetched wholesale; pulling another Nation's or another clearance tier's bytes onto the device is exactly what partial-trust offline operation forbids. Only the member's own cleared, already-seen material is cached.
- Plain (un-watermarked) export, and any membership change, which require a live, re-authenticated session.

**Sync discipline.** Offline operations carry an `offline_at` timestamp; the server reconciles ordering on reconnect and the OCAP audit log records the offline read or queued write with that timestamp so the temporal trail survives the offline gap. Re-auth-on-reconnect is required before any queued write is accepted. Tokens are cached locally with explicit expiry; an expired token blocks further offline reads of restricted material until the member reconnects and re-authenticates.

## Accessibility

This surface meets WCAG 2.1 Level AA and the AODA procurement-legibility requirements (charter DIR-A001). Specifics that matter for Data Rooms:

- **Access-denied announcements.** A hard denial (server-side OCAP `Forbidden`, for example a non-member or a hold block) announces via `aria-live="assertive"`. A soft denial (a capability not granted in this session, for example an offline session whose token expired) announces via `aria-live="polite"`. The two are distinct so a screen-reader user can tell "you are not permitted" from "reconnect and try again."
- **Hold and classification state is never color-only.** A held or nation-restricted document carries a text label and an icon with a text alternative, not just a colored badge, so the most consequential state on the surface is legible without color perception.
- **Watermark legibility.** The watermark burned into an export is visual; the export workflow also records the same provenance (member, room, timestamp, nonce) as machine-readable metadata and as on-page text, so the export's provenance is available to assistive technology, not only to sighted readers.
- **Persistent labels.** The export-reason field and the membership-management forms use visible, persistent labels, never placeholder-only inputs.
- **Focus management.** Opening the document viewer or the export dialog moves focus to the dialog and returns it to the triggering control on close; the audit view announces row counts progressively as filters apply.
- **Enforcement.** An axe-core CI gate runs on every PR touching this surface, with per-component Vitest plus Playwright accessibility tests. Shipping without an axe-core baseline is a charter violation, not a quality shortcut.

## Indigenous-language and translation

Data Rooms participate in the Anokii Indigenous-language pipeline (charter DIR-A003) for their UI chrome, and treat document content with care.

**UI chrome.** Every label, button, denial message, and watermark phrase on this surface is an extracted `translation_string` (the entity mirrors framework two-axis storage). The pipeline runs extraction tooling to the `translation_string` entity, through the contributor dashboard and `translation_review` workflow, to the glossary and the per-Nation override layer. Terms specific to this surface ("data room," "steward," "hold," "export") are strong glossary candidates and are co-authored with a language keeper before any Anishinaabemowin rendering ships. No Anishinaabemowin text enters the codebase without language-keeper review; this is absolute and admits no charter exception.

**Per-Nation override.** Because the room title and purpose are translatable entity fields, a Nation can present a room in its own language and dialect (for example `oji` / `southern-ojibwe` for Sagamok, carried from the tenant config). The override layer lets one Nation's term for "steward" differ from another's without forking the surface.

**Document content.** Documents held in a room are member-authored sovereign material, not framework UI, so they are out of scope for the translation_string pipeline. They are stored as-is. If a room holds material in an Indigenous language, that material is the Nation's to manage; the surface does not auto-translate it, and any future translation of room content would itself be a recorded, language-keeper-gated act, never an automatic one.

## Framework primitives used

- `waaseyaa/access`: `AccessChecker`, `AccessPolicyInterface`, `EntityAccessHandler`, deny-by-default entity semantics, the parent-delegated policy pattern (see `docs/specs/access-control.md`).
- `waaseyaa/field` (classification and retention): `ClassificationFieldAccessPolicy` (hold override then clearance gate), `LabelInheritanceResolver`, `RoleBasedClearanceChecker`, `RetentionPolicy`, the `hold-*` labels and `legal-hold-bypass` permission (see `docs/specs/classification-and-retention.md`).
- `waaseyaa/audit`: the append-only OCAP `audit_event` log, `AuditEventKind` (`media.version.read`, `entity.export`, `access.denied`, `classification.change`, `entity.write`), `actor_uid` three-state attribution, `AppendOnlyAuditDatabase` (see `docs/specs/ocap-audit-log.md`).
- `waaseyaa/media`: `MediaVersion` content-addressed blob store (`blob_uri`, `sha256`, append-only `vid` lineage) for document bytes and the read-event audit hook (see `docs/specs/entity-storage-two-axis.md`).
- `waaseyaa/entity` and `waaseyaa/entity-storage`: entity registration, `EntityRepository`, revisionable storage, query-layer access checking (`SqlEntityQuery::setAccount`).
- Work-surface primitives (`waaseyaa/routing`, `waaseyaa/api`, `waaseyaa/field`): deep-link routes, form descriptors, and the parent-delegated access pattern (see `docs/specs/work-surface.md`).
- Anokii offline substrate (charter DIR-A002): Dexie, Workbox, and the FSM sync engine composing on the framework two-axis revisions model.
- Anokii classification taxonomy: `config/classification.anokii-default.yaml` (public / community / nation-restricted) and per-tenant config such as `config/tenants/sagamok.yaml`.

## Open questions

- **Watermark rendering pipeline.** Server-side rendering of watermarked PDFs and images is not a framework primitive today. Does Anokii ship its own renderer, or is watermark-and-render a framework mission to upstream into `waaseyaa/media` so other distributions benefit? Filing a framework mission is the charter-correct path if the capability is general.
- **Plain export as a distinct capability.** Plain (un-watermarked) export is drafted as stewards-only, but is it a `room_role` power or a separate permission (for example `data-room-plain-export`)? A separate permission composes better with the existing permission handler and keeps the room role purely about membership scope.
- **Existence notices and discovery.** When (if ever) should a room's existence be discoverable to non-members so they know to request access? The current sketch makes this an explicit, manually authored `public`-tier notice. Whether stewards want even that much disclosure is a Nation governance decision, not a framework default.
- **Membership as relationship vs entity.** `data_room_membership` is drafted as a standalone entity. The framework `waaseyaa/relationship` and `waaseyaa/groups` packages model memberships too; should a Data Room be a specialized `group` rather than a bespoke entity, reusing group membership and access wiring? This needs a closer read of the groups package before committing.
- **Hold scope granularity.** Holds can apply to a whole room or a single document. Whether a hold on the room should hard-cascade to documents (eager) or be re-evaluated per document on read (the framework's lazy inheritance default) affects steward expectations during a litigation hold and needs a decision.
- **Export rate and volume controls.** Watermarking deters casual leaks but does not stop a member from exporting an entire room one document at a time. Is a per-member export budget or a steward alert on bulk export in scope for v0.1, or a later hardening pass?
- **Offline blob cache limits.** Partial-trust offline caching is keyed to already-opened documents, but device storage is finite. The eviction policy for cached room blobs (and whether eviction itself is an audited event) is unresolved.
