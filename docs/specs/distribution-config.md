# Distribution Config: the both-tiers switch

Status: DRAFT (WP04). This documents the v0.1 design contract for the Anokii
distribution switch. The typed resolver (`Anokii\Config\DistributionConfig`) is
implemented; the surfaces it gates are drafts wired in a later increment.

## What this is

Anokii ships in two tenancy tiers. The distribution switch is one YAML file,
`config/anokii.yaml` (see `config/anokii.yaml.example`), read by a typed
resolver, `src/Config/DistributionConfig.php`. The switch chooses the tier,
fixes the data-residency posture, and gates which WP04 product surfaces are
exposed.

The switch sits ABOVE the framework's per-entity tenancy primitive. The
Waaseyaa framework already isolates entities with `EntityType` `tenancy:
['scope' => 'community']` plus the `CommunityScope` storage driver (framework
mission #1257), and enforces field-level cross-Nation access through
`FieldAccessPolicyInterface` (see `docs/specs/access-control.md` and
`docs/specs/field-access.md` in the framework). Anokii does not reimplement any
of that. The distribution switch only decides the POSTURE the install runs in
and which surfaces are wired. Access control is never weakened by this file
(charter DIR-A005).

## The two tiers

### Sovereign (single owned-and-hosted Anokii per Nation)

A single install, owned and hosted by one Nation, serving only that Nation.
Data is nation-owned end to end. Cross-tenant reads do not exist because there
is one tenant. This is the tier for FNPI and Intersnipe (single sovereign
owners) and for each pilot Nation (Sagamok, Sheguiandah).

Sovereign is the safe-by-default tier. When `tenancy_mode` is absent or
unrecognised, the resolver returns `TenancyMode::Sovereign`, the most
sovereign-protective reading.

### Shared-graph (one install, many communities as vantage views)

One install serving many communities as vantage views over a shared PUBLIC
graph. Only public-sourced data lives in the shared layer. Each community sees
its own vantage plus the public graph. Cross-tenant reads are permitted ONLY
for public-tier data; the community and nation-restricted tiers stay Forbidden
across tenants via the framework `FieldAccessPolicyInterface`. This is the tier
for an OIATC-style multi-community install.

## The data-residency posture

The `data_residency` block makes the install's posture explicit and
safe-by-default. The resolver always returns a fully populated array with three
keys. Explicit config values win; absent keys are derived from the tier using
the most protective reading.

| Key | Sovereign default | Shared-graph default | Meaning |
|---|---|---|---|
| `ownership` | `nation` | `shared` | Who owns the held data. |
| `default_classification` | `nation-restricted` | `public` | Tier a record gets when unlabelled. |
| `cross_tenant_reads` | `false` | `true` | May one tenant read another's records? |

Two invariants are enforced in code, not just documented:

1. Sovereign NEVER enables cross-tenant reads, even if the file asks for it.
   In sovereign mode there is one tenant, so the resolver forces the value
   `false`.
2. An unlabelled sovereign record defaults to `nation-restricted`, never
   `public`. A record is never accidentally exposed by omission (OCAP,
   DIR-A005).

`default_classification` values map to the levels in
`config/classification.anokii-default.yaml`: `public` (Neutral field access),
`community` (Neutral for Nation members), and `nation-restricted` (Forbidden
across Nations via `FieldAccessPolicyInterface`).

## The module map

`modules` gates the WP04 surfaces with two lists:

- `enabled`: surfaces wired and offered as production-ready.
- `preview`: surfaces visible but flagged not-for-production. A preview surface
  carries a "preview / not production" banner and is excluded from the AODA
  axe-core production baseline (charter DIR-A001) until promoted.

Safe-by-default resolution:

- A surface in neither list is disabled (`moduleEnabled` returns `false`).
- A surface in BOTH lists is treated as preview, the conservative reading, so
  it is not enabled for production.

WP04 surface vocabulary (v0.1 design names):

| Module | Surface |
|---|---|
| `governed-drive` | OCAP-governed file storage over the framework media layer. |
| `forms` | Governed community-data intake (multi-submission-merge default, DIR-A002). |
| `tasks` | Task and assignment tracking. |
| `data-rooms` | Scoped document collections with classification gates. |
| `docs` | Collaborative documents over revisionable entities. |
| `sheets` | Structured tabular data surface. |
| `cointelligence-workspaces` | Per-record AI workspaces (per-record AI grants, DIR-A005). |
| `admin-centre` | Nation-scoped administration surface. |

## The typed resolver

`Anokii\Config\DistributionConfig` (final, `declare(strict_types=1)`, `@api`)
exposes:

- `tenancyMode(): TenancyMode` -- `Sovereign` or `SharedGraph`; Sovereign when
  absent or unknown.
- `dataResidency(): array` -- the three-key posture array described above.
- `moduleEnabled(string $module): bool` -- unknown module returns `false`;
  preview-listed module returns `false`.
- `modulePreview(string $module): bool` -- unknown module returns `false`.

Constructors: `fromArray(array $raw)` for callers holding a decoded document
(and for tests), and `fromFile(string $path)` which parses YAML via
`symfony/yaml`. A missing file resolves to the fully safe sovereign defaults; a
present-but-malformed file is allowed to surface as a parser exception rather
than silently defaulting a corrupt config.

`Anokii\Config\TenancyMode` is a backed string enum (`Sovereign = 'sovereign'`,
`SharedGraph = 'shared-graph'`) with `fromStringOrSovereign(?string): self` for
the safe fallback.

## Worked example: Sagamok (sovereign)

Sagamok Anishnawbek First Nation runs its own owned-and-hosted Anokii. The
per-tenant file `config/tenants/sagamok.yaml.example` carries the tier down to
the tenant level, consistent with the sovereign defaults.

```yaml
# config/anokii.yaml
tenancy_mode: sovereign
data_residency:
  ownership: nation
  default_classification: nation-restricted
  cross_tenant_reads: false
modules:
  enabled:
    - governed-drive
    - forms
    - tasks
    - admin-centre
  preview:
    - data-rooms
    - docs
    - sheets
    - cointelligence-workspaces
```

Resolved behavior:

- `tenancyMode()` is `Sovereign`.
- `dataResidency()` is nation-owned, `nation-restricted` default,
  cross-tenant reads off. An unlabelled record is never public.
- `forms` and `governed-drive` are production-ready; `docs` and
  `cointelligence-workspaces` render the preview banner.

## Worked example: OIATC-style (shared-graph)

An OIATC-style install serves several member communities as vantage views over
a shared public graph. There is no per-Nation tenant file; communities are
enumerated as vantage views at the install level.

```yaml
# config/anokii.yaml
tenancy_mode: shared-graph
data_residency:
  ownership: shared
  default_classification: public
  cross_tenant_reads: true
modules:
  enabled:
    - governed-drive
    - admin-centre
  preview:
    - forms
    - tasks
    - data-rooms
    - docs
    - sheets
    - cointelligence-workspaces
```

Resolved behavior:

- `tenancyMode()` is `SharedGraph`.
- `dataResidency()` holds only public-sourced data, defaults records to
  `public`, and permits cross-tenant reads. The framework
  `FieldAccessPolicyInterface` still blocks community and nation-restricted
  fields across tenants, so "cross-tenant reads on" means public-tier only.
- Community-data surfaces (`forms`, `tasks`, `data-rooms`) stay in preview here
  because governed community intake in a shared install needs per-community
  vantage wiring that lands in a later increment.

## Relationship to the framework

| Anokii concern | Framework primitive it builds on |
|---|---|
| Tenancy tier posture | `EntityType` `tenancy: ['scope' => 'community']` + `CommunityScope` (#1257) |
| Cross-tenant field access | `FieldAccessPolicyInterface` (Neutral / Forbidden) |
| Classification levels | `config/classification.anokii-default.yaml` -> framework field classification |
| Revisionable surfaces (docs, forms) | two-axis revisions (`RevisionableStorageDriver`, framework DIR-005) |
| Per-record AI (cointelligence) | framework per-record AI access grants (DIR-A005) |

Anokii references these by name. It does not reimplement them, and it never
modifies the framework from inside the Anokii repository (distribution posture,
charter and framework DIR-004).
