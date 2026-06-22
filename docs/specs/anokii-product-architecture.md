# Anokii product architecture: one engine, two surface families

Status: DRAFT for review (consolidation design). Built on the Waaseyaa
framework. This spec defines the canonical Anokii product so all three consuming
apps (fnpi-waaseyaa, oiatc-waaseyaa, rhtcircle) draw the Co-Intelligence chat,
the relational graph, and the workspace/admin shell from the package instead of
each carrying its own copy.

No em dashes (charter convention). Plain language. Anokii references framework
primitives by name and never reimplements or modifies the framework from inside
this repository (charter and framework DIR-004).

## 1. Why this exists

Today the real code is split across app repos:

- **fnpi-waaseyaa** has the freshest Co-Intelligence engine as a gated
  `/admin/anokii` workspace: `App\CoIntelligence\{Retriever, ChatPromptBuilder,
  ChatSchema, DocChunkRepository, ConversationRepository, KnowledgeChunker,
  Passage}`, `App\Controller\CoIntelligenceController`, and `app:ingest-knowledge`.
  doc_chunk is a raw `anokii_doc_chunk` table; the chat is single-vantage and
  stateful (conversations, optional confirm-before-apply proposals, off by
  default).
- **oiatc-waaseyaa** has the public geography-graph chat: the relational entity
  graph (`src/Entity/{Community, Place, Organization, Service, Project, Topic,
  DocChunk}` over a `GraphEntityBase`), the public `/anokii` shell with
  per-community vantage lenses, the SSE `POST /api/chat`, a `ChatPromptBuilder`,
  the no-PII `SqliteChatQueryLog`, and `app:ingest-docs` / `app:seed-graph`.
- **The framework** provides the LLM plumbing
  (`Waaseyaa\AI\Agent\Provider\{ProviderInterface, AnthropicProvider,
  NullLlmProvider, MessageRequest, StreamingProviderInterface}`), the entity and
  access pipelines, and SSR.

Both app chats share one scoring shape, one `Passage` value object, one
grounded-and-cited prompt contract, one doc_chunk concept, and one provider.
They differ only in surface (gated workspace vs public vantage chat) and in
whether doc_chunk is a framework entity or a raw table. That shared core is the
product. This spec makes it package-canonical.

## 2. The canonical module and surface set

Anokii is one engine with two surface families. The engine is never user-facing
on its own; a surface mounts it.

### 2.1 The Co-Intelligence engine (shared, headless)

One engine underneath every chat. Package-canonical components, namespace
`Anokii\CoIntelligence\`:

- **Graph entity model** (section 5): `Community, Place, Organization, Service,
  Project, Topic, doc_chunk` as first-class framework entities.
- **Retriever**: keyword retrieval over doc_chunk, geography-and-relationship
  aware. The canonical retriever is oiatc's graph-aware scorer; a single-vantage
  install (one community, no region) reduces to fnpi's flat keyword behavior with
  no code change. Returns ranked `Passage` objects. A vector index can implement
  the same `retrieve()` shape later without touching the chat.
- **ChatPromptBuilder**: the grounded, cited, clear-refusal system and user
  prompts, plus server-side dash sanitization, plus the per-vantage
  no-answer text.
- **Provider binding**: the chat calls the framework `ProviderInterface`. The
  package ships a small helper that rebinds it to `AnthropicProvider` from the
  server-side key and leaves `NullLlmProvider` in place when no key is set (the
  controller then reports "not configured" rather than erroring). The package
  does not fork the provider.
- **Ingest**: a base ingest command that chunks published pages (and other
  declared sources) into doc_chunk, each chunk linked to its source entity and
  page URL. Apps declare their sources; the chunking and upsert are canonical.
- **No-PII query log**: records only the vantage, question text, outcome
  (`answered | refused | no_match | unavailable | error`), inferred topic, and
  the cited source URLs. No IP, no visitor id, no account, nothing that links a
  question to a person.

Locked engine decisions (carried from the app implementations, unchanged):
keyword retrieval for the MVP, Claude Sonnet on the server-side key, grounded and
cited generation with a clear refusal, SSE streaming, agent writes OFF by default
(read-only grounded RAG).

### 2.2 Public graph-chat surface (shared-graph tier)

The public, open, vantage-aware chat. The model is oiatc.ca `/anokii` and the
rhtcircle nav backbone. Components:

- **Public chat endpoint**: stateless SSE `POST /api/chat`, `allowAll()`. Takes a
  question plus an optional `vantage community`; defaults to the install's
  configured default vantage. Grounded and cited, deterministic refusal when no
  passage supports an answer, rate-limited per client, writes the no-PII query
  log. No conversation state is persisted.
- **Public shell and vantage lenses**: `/anokii` (the instance home and the
  community switcher) and `/anokii/<community>` (the vantage view). Built on the
  package `Shell`.
- **Site-wide launcher**: a persistent ask box the consuming app drops into its
  own templates (home hero plus a site-wide launcher), with suggested prompts
  driven by Topic and the current section. The launcher markup and client are
  package assets; where the app places them is app-specific.

### 2.3 Anokii admin surface (shared-graph tier)

The lean admin for a public install, gated. The model is rhtcircle's
`/admin/anokii`. Built on the package `DashboardGate` plus, in production, the
host basic_auth on `/admin/*`. Components:

- **Entities**: manage `Community, Place, Organization, Service, Project, Topic`
  and their relationships.
- **Corpus**: manage doc_chunk and their source-entity and URL links; trigger a
  re-index when content changes.
- **Chat-log review**: read the no-PII chat log (question text and chunks used
  only) to find gaps and improve the corpus. This is the "what are people asking
  that we cannot answer" loop.

### 2.4 Gated workspace surface (sovereign tier)

The authenticated `/admin/anokii/*` workspace. The model is fnprocure.ca.
Components, each a workspace tool built on `DashboardGate` plus `Shell`:

- **cointelligence-workspace**: the stateful gated chat (conversations, optional
  confirm-before-apply proposals; proposals OFF unless explicitly enabled). Uses
  the same engine (2.1) as the public surface.
- **identity, pages, governed-drive, documents**: the canonical workspace tools.
- Preview surfaces from the existing WP04 vocabulary (`forms, tasks, data-rooms,
  docs, sheets`) stay preview until promoted.

App-only tools (for example fnpi's `ventures`, a procurement revenue model) stay
in the app. They are not canonical product surfaces; the package does not absorb
content-specific tools.

## 3. What belongs in the package vs the app

### In the package (canonical, versioned at the anokii.1 cadence)

- The Co-Intelligence engine (2.1): graph entity model, doc_chunk, Retriever,
  ChatPromptBuilder, provider-binding helper, base ingest command, no-PII query
  log.
- The three surfaces' controllers and base templates (2.2, 2.3, 2.4) on the
  existing `Shell` and `DashboardGate`.
- Reusable service providers that register the surfaces and routes, gated by
  `DistributionConfig` (section 4), with the route-priority pattern (section 4.3).
- The existing distribution scaffold already shipped in alpha.1:
  `DistributionConfig`, `TenancyMode`, `Shell`, `DashboardGate`, `AbstractSeeder`,
  `AbstractEntityAccessPolicy`, `AbstractWorkspaceRoles`, `Support\Auth`, the
  classification taxonomy, the shell templates, and the theme tokens.

### Stays in the app (host-specific)

- **The graph data and its seed.** Each app seeds its own communities, places,
  organizations, services, projects, and topics, and declares its own doc_chunk
  sources. oiatc seeds Sagamok and Massey; rhtcircle seeds the 21 RHT nations and
  the RHT resources, safety, treaty, and Massey content; fnpi seeds its single
  tenant. The package ships the seeder base and the entity types, not the rows.
- **Branding overlay.** Each app maps its own `public/css/site.css` tokens onto
  the Anokii theme tokens. The package ships neutral tokens; the app themes them.
- **Public marketing pages and the host nav.** The app owns its own pages and
  decides where the launcher and the `/anokii` entry sit.
- **Environment and limits.** `ANTHROPIC_API_KEY`, the monthly cost cap, and the
  per-session rate limit are host config. The app registers the provider rebind
  using the package helper.
- **`config/anokii.yaml`.** Each app carries its own distribution switch
  (section 4).
- **The host gate.** The basic_auth on `/admin/*` lives in the host (Caddy in
  waaseyaa-infra). The package enforces the session gate in `DashboardGate`; the
  host adds the network gate.

## 4. Surfaces are config-driven

One package serves all three apps. `DistributionConfig` (`config/anokii.yaml`)
selects the posture and the surfaces; the providers read it and register only
what is enabled.

### 4.1 Tenancy mode selects the surface family

- `tenancy_mode: sovereign` selects the **gated workspace** family (2.4).
  `/admin/anokii` is the authenticated workspace. There is no public chat unless
  a public surface is also enabled. This is fnpi.
- `tenancy_mode: shared-graph` selects the **public graph-chat** family (2.2)
  plus the **admin** surface (2.3). `/anokii` is the public vantage chat;
  `/admin/anokii` is the lean admin. This is oiatc and rhtcircle.

Both tiers inherit the framework `CommunityScope` isolation and
`FieldAccessPolicyInterface` exactly as the existing distribution-config spec
describes. The tier chooses posture and which surfaces wire, never a weakening of
access control. Safe-by-default stands: an absent or unknown `tenancy_mode`
resolves to `sovereign`.

### 4.2 The module vocabulary, extended

The existing `modules` enabled/preview lists gain the canonical surfaces. New
module ids:

| Module id | Surface | Tier |
|---|---|---|
| `public-graph-chat` | the public vantage chat (2.2) | shared-graph |
| `anokii-admin` | the lean entities/corpus/log admin (2.3) | shared-graph |
| `cointelligence-workspace` | the gated stateful chat (2.4) | sovereign |
| `identity`, `pages`, `governed-drive`, `documents` | workspace tools (2.4) | sovereign |
| `forms`, `tasks`, `data-rooms`, `docs`, `sheets` | existing WP04 preview surfaces | either |

`moduleEnabled()` / `modulePreview()` resolution is unchanged: unknown is off,
both-lists is preview. (Note: the existing example config uses the design name
`cointelligence-workspaces`; the canonical id is singular `cointelligence-
workspace` to match the live fnpi tool. The example config updates with this
release.)

### 4.3 Routing and the priority pattern

- Public routes (`/anokii`, `/anokii/<community>`, `POST /api/chat`) register
  `allowAll()`; the public chat enforces rate limiting and the scope fence, not a
  login.
- Admin and workspace routes register under `/admin/anokii/*` at
  `priority(100)` so they beat the framework admin SPA GET catch-all at
  `/admin/{path}` (priority 0). This is exactly fnpi's existing pattern
  (`AnokiiServiceProvider::ROUTE_PRIORITY = 100`); the package adopts it so every
  consumer gets it for free.
- In sovereign mode `/anokii/<rest>` 301-redirects to `/admin/anokii/<rest>`
  (fnpi's current legacy behavior, preserved). In shared-graph mode `/anokii` is
  the public home and is not redirected.

## 5. The graph entity model (package-canonical)

The relational graph is first-class framework entities, the oiatc
`GraphEntityBase` approach promoted into `Anokii\Entity\`. All entities are
sourced and public-tier in the shared-graph posture; no member data. doc_chunk is
an entity, not a raw table, so retrieval uses the graph and geography rather than
a flat string match, and so OCAP classification and revisions apply uniformly.

| Entity | Key fields | Relationships |
|---|---|---|
| `community` | name, centroid (lat/long), curated region (list of places), vantage slug | located_at Place; has_region [Place] |
| `place` | name, lat/long | proximity to Place (curated or computed) |
| `organization` | name, kind, contact, source url | located_at Place |
| `service` | name, description, contact, source url | provided_by Organization; located_at Place; has_topic Topic |
| `project` | name, description, source url | relates_to [Community]; located_at Place |
| `topic` | name, slug | drives scoping and suggested prompts |
| `doc_chunk` | title, heading, text, source_url, source_entity_type, source_entity_id | links to its source entity |

Retrieval scoping for a question asked from community C: infer topic by keyword,
build the candidate set (entities related to C, plus services in C's region
places, plus shared projects related to C), rank by topic match then relationship
closeness then proximity, ground on the top-k with each chunk carrying its source
and location/relationship, cite every chunk used, refuse clearly when nothing
supports an answer. Geography is curated-region authoritative plus lat/long
distance as a ranking signal only; travel time shown only where sourced.

### Reconciliation decisions (for review)

1. **doc_chunk becomes a framework entity** (oiatc's model) canonically. fnpi's
   raw `anokii_doc_chunk` table migrates to the entity at adoption. This is the
   one schema change fnpi takes on; it is on the parity checklist.
2. **The canonical Retriever is the graph-aware scorer.** Single-vantage
   sovereign installs (fnpi) run it with one community and no region, which
   reproduces today's flat keyword behavior. Same `retrieve()` contract and
   `Passage` shape both apps already use.
3. **Two controllers, one engine.** A stateless `PublicChatController` (vantage,
   no-PII log, no history) and a stateful `WorkspaceChatController` (conversations,
   optional proposals) both call the same Retriever, ChatPromptBuilder, provider,
   and doc_chunk. Proposals (agent writes) stay OFF by default.
4. **Provider stays framework-driven.** The package rebinds `ProviderInterface`
   to `AnthropicProvider` from the key via a helper; it does not fork the provider
   or hard-code a model beyond the documented Claude Sonnet default.

## 6. Per-app configuration matrix

| Concern | fnpi-waaseyaa (fnprocure.ca) | oiatc-waaseyaa (oiatc.ca) | rhtcircle (rhtcircle.ca) |
|---|---|---|---|
| `tenancy_mode` | `sovereign` | `shared-graph` | `shared-graph` |
| Surface family | gated workspace (2.4) | public graph-chat (2.2) + admin (2.3) | public graph-chat (2.2) + admin (2.3) |
| Enabled modules | `cointelligence-workspace, identity, pages, governed-drive, documents` (app: `ventures`) | `public-graph-chat, anokii-admin` | `public-graph-chat, anokii-admin` |
| Public chat | no (workspace only) | yes, `/anokii` + lenses | yes, nav backbone + `/anokii` |
| `/admin/anokii` | full workspace, login-gated (`/admin/anokii/login`) | lean admin, basic_auth | lean admin, the same basic_auth as `/admin/analytics` |
| Default vantage | single tenant (FNPI) | `sagamok` (today), `massey`; treaty-wide later | treaty-wide; the 21 nations as vantages |
| Graph data | single-tenant content | Sagamok, Massey | 21 RHT nations, RHT resources, safety, treaty, Massey |
| Agent writes | available, OFF by default | n/a (public read-only) | n/a (public read-only) |

## 7. Guardrails (carried into the package)

Grounded and cited generation with a clear refusal; server-side key only, never
in the page; no PII collected or logged (question text and chunks used only);
OCAP and data-sovereignty posture (the framework access pipeline and the
classification taxonomy, unchanged); independent, member-led, nonpartisan framing
on public surfaces; no em dashes in public copy; plain language; mobile-first;
accessible (AODA AA, DIR-A001). These are the same guardrails the app
implementations already honor; the package makes them the default.

## 8. Out of scope for this consolidation

- Embeddings and vector retrieval (the keyword retriever is the MVP; the
  `retrieve()` shape leaves room).
- The heavier WP04 internal modules (data-rooms, vault, sheets) beyond preview.
- Any change to oiatc, fnpi, or rhtcircle. This release lands in the package
  only; the apps adopt it in Phase B, app by app, against a parity checklist.
