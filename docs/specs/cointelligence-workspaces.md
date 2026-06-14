# Co-Intelligence Workspaces (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

## 2. Purpose

A Co-Intelligence Workspace is the place inside a Nation workspace where a person and an AI agent work side by side on governed content. The agent helps draft a record, summarize a long thread of community input, and cross-reference one entity against others the user already has clearance to see. The human stays the author of record; the agent is an assistant whose every action is logged, attributable, and reversible.

The surface is deliberately narrow in v0.1. It is not a general chatbot and not a research tool that roams the open web. It operates inside one tenant boundary, over entities the framework access pipeline already permits the acting user to read, and it never writes anything without an explicit human approval step. The agent proposes; a person disposes. This keeps the surface aligned with charter directive DIR-A005 (OCAP-by-architecture) and the per-record AI access flagship the framework ships.

The reference consumer is the FNPI Co-Intelligence controller (`CoIntelligenceController` in fnpi-waaseyaa), which is the source from which the framework workspace chat surface contract was extracted. Anokii adopts that contract rather than inventing a parallel one, so a Nation distribution and the framework share one transport and one client.

## 3. Tier applicability

Anokii ships in two postures. This surface behaves differently in each, and the difference is about what data the agent can reach and what leaves the tenant.

**Sovereign single-tenant (FNPI, Intersnipe).** The workspace, its conversations, its drafts, and its provenance records all live in one Nation's bucket. There is no cross-Nation graph. The agent's retrieval scope is the single tenant. Public-tier output (for example a draft destined for a public page) is the only material that can ever be published outward, and only after a human approves it. Community-tier and nation-restricted-tier content stays inside the workspace. This is the simpler and the default posture.

**Shared-graph multi-tenant (OIATC).** Several Nations share an OIATC-stewarded graph. The workspace is still scoped to one Nation: a conversation belongs to a tenant, and the agent's retrieval is filtered to entities the acting user can read under that tenant's classification taxonomy. Cross-Nation reads follow the default taxonomy exactly (`config/classification.anokii-default.yaml`): public is Neutral, community is Neutral for federated partner Nations, and nation-restricted is Forbidden across Nation boundaries via the framework `FieldAccessPolicyInterface`. The agent inherits these field policies verbatim. It cannot summarize, cross-reference, or quote a nation-restricted field belonging to another Nation, because the field read is forbidden before the agent ever sees a value.

In both tiers the invariant is the same: the agent's reach is exactly the acting user's reach, never wider. There is no service-account backdoor that reads with elevated clearance on the agent's behalf.

## 4. User-facing surface

The workspace is one screen with a conversation column and a context rail.

- **Conversation.** The user asks the agent to draft, summarize, or cross-reference. Responses stream in via the framework workspace chat surface (SSE). Each assistant turn shows its author label, the model used, and a provenance affordance.
- **Draft proposals.** When the agent proposes a write (a new draft entity or an edit to an existing one), it does not save. It emits a proposal with a token. The user sees a side-by-side preview and an Approve / Reject control. Only the proposer account can decide a proposal (the framework `apply` endpoint enforces this with a 403 otherwise).
- **Approve / Reject.** Approve commits the change through the normal entity pipeline as a new revision authored by the approving user, with the agent recorded as the assisting actor in provenance. Reject discards the proposal and records the rejection. Nothing is written on reject.
- **Context rail.** Shows the entities the agent has pulled into context for the current turn, each with its classification label visible, so the user can see exactly what the agent is reasoning over. Items the user lacks clearance for never appear here because they are filtered upstream.
- **Cross-reference results.** A cross-reference action returns a ranked list of related entities (semantic plus published-relationship context, the framework hybrid search ranking contract). Each result carries its classification label and a score breakdown the user can expand.
- **Consent gate.** Before the first agent turn in a conversation that will touch community-tier or nation-restricted material, the user confirms a consent prompt that names the classification scope of the session. The consent decision is recorded.

Access-denied feedback follows DIR-A001: a hard denial (server-side OCAP forbidden) announces via `aria-live="assertive"`; a soft denial (a capability not granted this session) announces via `aria-live="polite"`.

## 5. Data model

All persistence is through the Waaseyaa entity system (`EntityRepository`, registered `EntityType`, classification field type). No raw SQL. New Anokii entity types introduced by this surface:

**`cointel_workspace`** (workspace container)

| Field | Type | Notes |
|---|---|---|
| `id`, `uuid` | identity | system-assigned |
| `label` | string | workspace title |
| `tenant` | string | `nation_short`, scopes the workspace to one Nation |
| `classification_label` | classification | default ceiling for content created here |
| `created_by` | int | owning account id |

**`cointel_conversation`** (one human/agent thread)

| Field | Type | Notes |
|---|---|---|
| `id`, `uuid` | identity | |
| `workspace` | ref | parent `cointel_workspace` (classification inherits down this parent chain) |
| `label` | string | conversation title (agent may suggest) |
| `consent_scope` | string | highest tier the user consented to this session: `public`, `community`, `nation-restricted` |
| `consent_recorded_at` | datetime | when consent was captured |

**`cointel_message`** (a turn, revisionable)

| Field | Type | Notes |
|---|---|---|
| `id`, `uuid` | identity | |
| `conversation` | ref | parent `cointel_conversation` |
| `role` | string | `user` or `assistant` |
| `author` | string | display label |
| `body` | text | content / markdown |
| `model` | string | provider model id, blank for user turns |
| `provenance` | json | see provenance block below |

**`cointel_proposal`** (a pending agent write, the approve/reject unit)

| Field | Type | Notes |
|---|---|---|
| `id`, `uuid` | identity | |
| `conversation` | ref | parent |
| `target_entity_type`, `target_entity_id` | string | the entity the write would land on, blank for a create |
| `expected_revision_id` | int | the revision the agent read, for optimistic locking at approve time |
| `proposed_values` | json | the diff the agent wants to apply |
| `decision` | string | `pending`, `approved`, `rejected` |
| `decided_by` | int | approving / rejecting account id |

The `provenance` JSON on each assistant message and proposal records: the model id, the ordered list of source entity references the agent retrieved (type, id, classification label at read time), the tool calls made, and a `consent_scope` snapshot. This composes the framework authoring-assist explainability block (`primary_cue`, `supporting_cues`, `inference_edges_used`, `validation_signals`) where the agent's suggestion came from validated context.

Optimistic locking uses the framework stock tool contract: the agent records `revision_id` at draft time and states it via `expected_revision_id` at approve time. A `revision_conflict` at approve time is surfaced to the user as "the underlying record changed, re-review" rather than silently overwriting a competing writer.

## 6. Access and classification

OCAP is absolute on this surface. Three rules, in order.

1. **The agent acts as the user.** Every agent run carries an `AccountInterface` (the framework `AgentContext`). Retrieval, reads, and the eventual approved write all pass the standard `AccessChecker` and `FieldAccessPolicyInterface`. There is no elevated agent identity. The agent's effective reach equals the user's reach, by construction.
2. **The three tiers gate field reads before the agent sees values.** Per the default taxonomy: public and community resolve Neutral (community is Neutral only for the owning Nation and federated partners), nation-restricted resolves Forbidden across Nation boundaries. The agent receives cast-aware field maps that have already had forbidden fields stripped by the field-access policy. A nation-restricted field from another Nation is not redacted after the fact; it is never loaded into agent context at all.
3. **Classification ceiling on agent output.** A proposal cannot raise the classification of derived content above the consent scope of its conversation, and cannot publish to public tier without a human approval. Inheritance flows down the workspace to conversation to message parent chain (framework `LabelInheritanceResolver`), so a conversation inside a nation-restricted workspace defaults its drafts to nation-restricted unless a human explicitly downgrades and approves.

**Consent gates.** Consent is recorded per conversation (`consent_scope`, `consent_recorded_at`) and is a precondition for the agent touching anything above public tier. Consent is scoped, time-stamped, and revocable: revoking consent halts further agent turns in that conversation. Consent never widens access beyond what the access pipeline already allows; it is an additional gate on top of OCAP, never a substitute for it.

**Audit expectations.** Every agent execution, dry run, tool call, proposal, approval, and rejection writes to the unified OCAP audit log (`waaseyaa/audit`, append-only). Each record names the acting account, the agent id, the action, the outcome, and the entities touched. Offline turns carry an `offline_at` timestamp and reconcile into the same log on sync (DIR-A005). The audit log is the evidence that the agent never crossed a tier it lacked clearance for.

## 7. Offline-first behavior

Per DIR-A002, the surface functions in offline-degraded mode. The offline substrate is Dexie (IndexedDB) plus a Workbox service worker plus the FSM sync engine over the framework two-axis revisions model, with the `(entity_id, langcode, vid)` tuple mapping to Dexie composite keys.

What works offline:

- **Read your own conversations and drafts.** Conversations, messages, and proposals within the user's own classification scope are cached and readable offline. Other Nations' cached data is not readable offline (partial-trust rule).
- **Compose and queue.** The user can write turns and the agent can produce cached-context drafts offline if a local model is configured; without a provider, the agent turn queues and runs on reconnect.
- **Queue proposals, defer approval.** A proposal can be queued offline. Approval that commits a governed write reconciles on reconnect, where the `expected_revision_id` check runs against the live head, so an offline approval that has gone stale surfaces a conflict rather than clobbering newer data.

Sync strategy follows the charter default: governed community content uses multi-submission-merge (every turn and every proposal is a record, never overwritten). The LWW opt-in is reserved for administrative config records, not conversation content. Tokens are cached with explicit expiry and re-auth is required on reconnect. Offline operations carry `offline_at`; the server reconciles temporal ordering on sync.

## 8. Accessibility

AODA Level AA (DIR-A001) specifics for this surface:

- **Streaming responses use focus management and progressive announcement.** As an assistant turn streams in, an `aria-live="polite"` region announces progress without stealing focus; on completion, focus moves to the proposal action when a proposal is present so a keyboard or screen-reader user reaches Approve / Reject without hunting.
- **Access-denied announcements.** Hard OCAP denials announce `aria-live="assertive"`; soft capability-not-granted denials announce `aria-live="polite"`.
- **Proposal preview is a labeled, navigable diff.** The side-by-side draft preview exposes each change as a list item with a visible label, not color alone; added and removed text carry text markers, not just styling.
- **All inputs have visible, persistent labels.** The composer, the consent prompt, and the cross-reference query field use real labels, never placeholder-only patterns.
- **Context rail items are reachable and described.** Each retrieved entity in the rail is a focusable element whose accessible name includes its classification label.
- **Enforcement.** An axe-core CI baseline runs on every PR touching this surface; per-component tests live in Vitest and Playwright. Shipping without a baseline is a charter violation, not a shortcut.

## 9. Indigenous-language and translation

DIR-A003 governs both the surface chrome and the agent's handling of Indigenous-language content.

- **UI strings.** Every label, prompt, consent string, and announcement on this surface is extracted into the `translation_string` pipeline (the entity mirrors the framework two-axis storage shape). The English source is authored here; the Anishinaabemowin rendering flows through the `translation_review` workflow and the glossary entity, with the per-Nation override layer applying the Nation's preferred terms. The pilot dialect for Sagamok is southern Ojibwe (`oji`, `southern-ojibwe`) per the tenant stub.
- **Language-keeper gate is absolute.** No Anishinaabemowin text reaches a user from this surface without language-keeper review. This applies to UI chrome and, critically, to any agent-generated text in an Indigenous language. The agent must not invent, autocomplete, or machine-translate Anishinaabemowin into a published surface. Agent-suggested Indigenous-language content is a draft that enters `translation_review` and waits for a keeper, never an auto-published string.
- **Cross-reference respects language.** Retrieval is language-aware (the framework vector search supports `langcode` with fallback). A cross-reference can surface a record's Anishinaabemowin field where the user has clearance, but the agent presents it as the keeper-reviewed source value, not a re-translation of its own.

## 10. Framework primitives used

- `waaseyaa/ai-agent` (`AgentInterface`, `AgentContext`, `AgentExecutor`, provider tool loop, in-context audit)
- `waaseyaa/ai-schema` (`SchemaRegistry`, MCP tool definitions and `McpToolExecutor`, cast-aware payloads)
- `waaseyaa/ai-vector` (semantic retrieval, language-aware search, hybrid ranking contract)
- `waaseyaa/ai-pipeline` (embedding pipeline for context retrieval)
- `waaseyaa/mcp` (`McpServerCard`, `/.well-known/mcp.json`) and `docs/specs/mcp-endpoint.md`
- `waaseyaa/workspace` workspace chat surface (`docs/specs/workspace-chat-surface.md`) and the FNPI `CoIntelligenceController` reference
- Authoring-assist contract (`docs/specs/authoring-assist-contract.md`) for the explainability block
- Stock entity tools with `EntityKeyGuard` and optimistic locking (`expected_revision_id`, `revision_conflict`)
- `waaseyaa/entity` and `waaseyaa/entity-storage` two-axis revisions (`docs/specs/revision-system-unified.md`)
- `waaseyaa/access` (`AccessChecker`, `AccessPolicyInterface`, `FieldAccessPolicyInterface`)
- `waaseyaa/field` classification and retention (`docs/specs/classification-and-retention.md`, `LabelInheritanceResolver`)
- `waaseyaa/audit` unified OCAP audit log (`docs/specs/ocap-audit-log.md`)

## 11. Open questions

- **Consent granularity.** Is per-conversation consent scope enough, or do nation-restricted sessions need per-turn re-consent? Steward input needed (OIATC stewards channel).
- **Local model story for offline drafting.** Which on-device or local-network model backs offline agent turns, and does running a model on cached community data offline need its own consent flag separate from the read consent?
- **Provenance retention.** How long do provenance records and rejected proposals persist? They are audit-relevant but may themselves carry classified context. Needs a `RetentionPolicy` mapping that does not purge the OCAP trail.
- **Cross-tenant cross-reference in shared-graph mode.** When a federated partner's community-tier entity is in scope, should it surface in cross-reference results by default, or only on an explicit opt-in per conversation? This is a governance call, not purely a technical one.
- **Agent identity in provenance vs audit.** Provenance records the agent as assisting actor; the audit log records the human account. Confirm both views stay consistent when an approval is replayed from an offline queue.
- **Indigenous-language draft handling.** Exact `translation_review` wiring for agent-suggested Anishinaabemowin drafts: do they enter the queue as a distinct contributor type so a keeper can see "machine-suggested, unreviewed" at a glance?
- **Provider boundary.** The pilot uses the framework `AnthropicProvider`. Confirm that no conversation content above public tier leaves the tenant boundary to a third-party API without an explicit, recorded per-Nation data-handling decision.
