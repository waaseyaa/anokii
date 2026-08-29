# Workforce directory integration discovery

Status: proposed discovery input for issue #26

Decision owner: Nation-designated business and identity owners

Implementation status: not authorized

## Purpose and boundaries

This record compares the reusable transport options for bringing a deliberately
scoped workforce directory into Anokii. It is a sanitized architecture input,
not a connector design or a decision to publish staff information.

The following concepts remain separate:

1. the external directory identity;
2. a private governed workforce record;
3. an Anokii login or operator account; and
4. an explicitly approved public profile.

Synchronization may create or update only the private workforce record. It must
never create a login account, grant a role, or publish a field as an implicit
side effect. Stable external object identifiers are protected provenance and
must not appear in public URLs, logs, metrics, or audit attributes.

This document contains no tenant identifiers, directory names, credentials,
staff values, group membership, or private contact details. The factual inputs
that only the Nation can supply remain tracked in issue #30.

## Conditional recommendation

Prefer **assignment-scoped Entra application provisioning to an Anokii SCIM
endpoint** when all of these preconditions are confirmed:

- the authoritative workforce identities are available in Entra;
- the Nation can operate an enterprise application and provisioning job;
- licensing supports the intended group-based assignment model;
- an explicit assignment or security group expresses the approved source scope;
- owners accept the disable, deletion, quarantine, credential-rotation, and
  provisioning-log operating duties; and
- the selected field map and public-projection policy have been approved.

This is preferable because source scoping remains under Nation-controlled Entra
administration and Anokii does not receive a general directory-reader grant.
When `Sync only assigned users and groups` is selected, Microsoft documents that
the provisioning service provisions and deprovisions according to assignment.
Group assignment requires Entra ID P1 or P2 and does not include nested groups.

Use **Microsoft Graph user delta** only when SCIM provisioning is unavailable or
operationally unsuitable. User delta requires `User.Read.All` for application
access. A group-membership read can use `GroupMember.ReadBasic.All`, but that
group permission does not narrow the tenant-wide user-read authority. Anokii
must therefore treat Graph as a broader credential and enforce its own explicit
allowlist after reading.

Use a **private AD DS agent** only when Entra cannot be the boundary. The agent
must run inside the trusted network, query a narrowly scoped directory view,
and send a minimal outbound projection to Anokii. The hosted application must
have no inbound route or credential capable of reaching a domain controller.

Retain **manual governed records** as the fallback and correction path. Manual
records avoid connector privilege, but require a named owner and review cadence
to control drift.

No option is selected until the issue #30 inputs are answered. In particular,
the recommendation must not be converted into implementation merely because an
Entra tenant exists.

## Capability and risk comparison

| Dimension | Entra to SCIM | Graph delta | Private AD DS agent | Manual records |
| --- | --- | --- | --- | --- |
| Direction | Push from Entra provisioning | Pull by Anokii | Outbound push/poll from trusted network | Human entry |
| Source scope | Assigned users or direct members of assigned security groups | Application reads users; Anokii filters to approved scope | Agent query base/filter | Operator workflow |
| Minimum useful privilege | Tenant provisioning credential for one enterprise app | `User.Read.All`; optionally `GroupMember.ReadBasic.All` for the scope group | Read-only directory identity constrained by ACL and query | Anokii authoring capability |
| Change tracking | Provisioning service initial and incremental cycles | `@odata.nextLink` and `@odata.deltaLink` | Agent-owned watermark/change mechanism | Review schedule |
| Disable semantics | `active=false` update for an out-of-scope or soft-deleted user | Interpret deletion/disable changes from delta and current scope | Agent emits explicit suppression event | Operator suppresses |
| Hard delete | SCIM `DELETE` may follow source hard deletion | Retention-governed local action after source evidence | Retention-governed local action | Retention-governed operator action |
| Credential blast radius | One provisioning target | Tenant-wide user profile read | Scoped directory read inside private network | No external credential |
| Main operational risk | Mapping/scope error can affect a cohort | Over-broad read and stale delta token | Private-agent compromise or stale delivery | Drift and missed offboarding |
| Preferred position | First candidate | Second candidate | Last-resort connector | Safe fallback |

`User.ReadBasic.All` is not assumed sufficient: Microsoft limits it to a basic
profile, while the proposed workforce fields include properties such as job
title, department, business phones, and office location. `Directory.Read.All`
is not requested by this design.

## Target contract

Every accepted source event is normalized into an internal command containing
only:

- tenant-bound connector identity;
- protected stable source object ID;
- source version or deterministic event fingerprint;
- active/suppressed state;
- explicitly mapped private workforce fields;
- source and receipt timestamps; and
- a mapping-policy version.

The write boundary must provide:

- tenant isolation before lookup or mutation;
- idempotency on connector, source object, and source version/fingerprint;
- optimistic concurrency for locally overridden fields;
- an atomic record-and-audit transaction;
- field classification before persistence;
- value-redacted diagnostics; and
- a separate, capability-gated disclosure workflow for any public projection.

Passwords, authentication secrets, personal contact data, HR notes, payroll
data, and unrelated group memberships are outside the schema and rejected even
if a source sends them.

## Trust boundaries and data flow

```text
authoritative directory
        |
        | Nation-controlled source scope and field map
        v
connector boundary (SCIM endpoint, Graph worker, or private agent)
        |
        | authenticated, tenant-bound, replay-safe normalized command
        v
private governed workforce record ----> quarantine/review
        |
        | separate approval and disclosure decision
        v
optional public projection
```

The connector boundary is untrusted input even when Microsoft or a private
agent authenticated successfully. Authentication proves the caller, not the
correctness of its scope, mappings, lifecycle state, or field values.

## Threat model

| Threat | Required control | Verification evidence |
| --- | --- | --- |
| Cross-tenant source ID collision | Resolve connector and tenant before source lookup; tenant participates in every unique key | Same synthetic source ID in two tenants cannot read or mutate across tenants |
| Over-broad source scope | SCIM uses assigned scope; Graph/private agent apply an explicit approved allowlist; reject unscoped events | Out-of-scope synthetic user produces no record and a value-free diagnostic |
| Accidental mass suppression/deletion | Threshold and quarantine before cohort mutation; require named approval; configure Entra accidental-deletion prevention where available | Synthetic threshold breach performs zero workforce or public writes |
| Stale connector data | Track last successful cycle and source watermark; fail closed for publication according to approved stale-source policy | Expired watermark suppresses or queues review without inventing freshness |
| Replay or duplicate delivery | Actor-, tenant-, and source-bound idempotency key; atomic application | Repeated create, patch, deactivate, and delete requests converge without duplicate writes |
| Reordered lifecycle events | Compare source version/watermark and reject older state transitions | A delayed activation cannot reverse a newer suppression |
| Connector credential theft | Shortest practical lifetime, rotation with overlap, tenant-specific credentials, rate limits, revocation, no secrets in logs | Old credential fails after overlap; audit retains identifier, not secret |
| SCIM filter or PATCH abuse | Support only the documented schema and operators; bound page size and payload; reject ambiguous/multi-match filters | Fuzzed filters and patches fail deterministically without partial writes |
| Graph permission expansion | Pin exact application permissions and alert on consent drift | Deployment preflight refuses unexpected grants such as `Directory.Read.All` |
| Private-agent compromise | Outbound-only network path, read-only directory identity, signed releases, host attestation/rotation where available | Hosted Anokii has no route or credential to bind to AD DS |
| Attribute injection or value leakage | Type/length validation, output encoding, field allowlist, value-redacted logging and audit metadata | Synthetic secrets and markup never appear in logs, metrics, traces, or rendered output |
| Account-type confusion | Explicit source category and policy; exclude guests, service/shared accounts, and vacant roles unless separately modeled | Each excluded synthetic category is rejected or routed to its own contract |
| Automatic publication | Private classification by default; distinct approval command and capability | Successful sync leaves every public projection unchanged |
| Local override loss | Per-field authority and override policy with visible conflict state | Source update preserves approved local override and creates a reviewable conflict |
| Incomplete offboarding | Immediate suppression is the safe default; retention-governed hard deletion is separate | Disable/out-of-scope event removes public projection before private retention action |

SCIM traffic must use TLS. Credentials must not be placed in a URL. LDAP binds
must use signing or TLS with channel binding as applicable; unsigned or simple
unencrypted binds are prohibited.

## Lifecycle and failure semantics

- **Create:** default to private and inactive for public projection. Duplicate
  matching must fail closed rather than choose a record.
- **Update:** apply only mapped source-owned fields. Local-owned fields remain
  unchanged; conflicting dual-owned fields enter review.
- **Out of scope, disabled, or soft deleted:** immediately suppress any public
  projection and retain private provenance according to policy. For SCIM this
  maps to `active=false`.
- **Hard deleted:** record the event, keep the public projection suppressed,
  and perform private deletion only under the approved retention rule.
- **Source unavailable:** do not treat absence of a successful poll as deletion.
  Surface connector staleness without logging workforce values.
- **Mass change:** quarantine the whole candidate batch before any write.
- **Partial failure:** commit no batch-level mapping change. Individual SCIM
  requests remain idempotent and expose a stable, non-sensitive error code.

Microsoft recommends that SCIM targets support both disable and hard-delete
operations. Entra normally disables users who leave scope and can send `DELETE`
after a source hard deletion. Anokii must not collapse those into one action.

## Synthetic proof-of-concept plan

The first PoC uses an isolated synthetic tenant and invented identities only.
It must not require or accept a production directory export.

1. Confirm the issue #30 capability matrix and select one transport.
2. Freeze the exact source scope, field map, permissions, lifecycle behavior,
   and deletion threshold in a reviewable fixture.
3. Exercise create, update, disable, restore, hard-delete, replay, reordering,
   duplicate match, and connector-credential rotation.
4. Include employee, contractor, elected-official, guest, shared/service
   account, and vacant-role categories with synthetic values.
5. Prove two-tenant isolation with identical source IDs.
6. Prove that synchronization alone creates no login, role, or public profile.
7. Prove logs, traces, metrics, audit attributes, and error bodies contain no
   workforce attribute values or credentials.
8. Prove mass-change quarantine and recovery before enabling an incremental
   cycle.
9. Record operator runbooks for scope change, credential rotation, connector
   outage, quarantine, correction, emergency suppression, and offboarding.

The PoC is evidence for a later ADR. It is not a release or deployment.

## Nation-owned decision gates

Implementation remains blocked until issue #30 records these answers without
private values:

- directory topology and authoritative system by field;
- business owner, identity owner, emergency-removal owner, and approvers by
  role;
- Entra provisioning availability, licensing, and consent authority;
- approved source-scope mechanism and account-category rules;
- field classification, public purpose, override, suppression, and retention
  policy;
- stale-source and mass-change behavior; and
- connector operations, monitoring, quarantine, and credential rotation
  ownership.

Unknown answers stay unknown. The implementation team must not infer them.

## Primary references

- Microsoft, [Understand how application provisioning works](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/how-provisioning-works)
- Microsoft, [Develop and plan provisioning for a SCIM endpoint](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups)
- Microsoft, [Manage users and groups assigned to an application](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/assign-user-or-group-access-portal)
- Microsoft Graph, [Get incremental changes for users](https://learn.microsoft.com/en-us/graph/api/user-delta?view=graph-rest-1.0)
- Microsoft Graph, [Permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference)
- Microsoft Graph, [List group members](https://learn.microsoft.com/en-us/graph/api/group-list-members?view=graph-rest-1.0)
- Microsoft, [Accidental deletion prevention for provisioning](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/accidental-deletions)
- Microsoft, [LDAP signing for Active Directory Domain Services](https://learn.microsoft.com/en-us/windows-server/identity/ad-ds/ldap-signing)
- IETF, [RFC 7644: SCIM protocol](https://www.rfc-editor.org/rfc/rfc7644)

These sources define platform capabilities, not the Nation's policy decision.
They must be rechecked when an implementation ADR is proposed.
