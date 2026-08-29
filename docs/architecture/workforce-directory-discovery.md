# Workforce directory integration discovery

Status: proposed discovery input for issue #26

Decision owner: Nation-designated business and identity owners

Implementation status: not authorized

Vendor documentation last checked: 2026-08-29

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

Vendor behaviour cited here was read from primary documentation on the date
above. Platform behaviour changes; every citation must be rechecked when an
implementation ADR is proposed.

## Governance frame

Anokii charter DIR-A005 binds every product surface to the framework's OCAP
access-control wiring. A workforce directory connector would be the first
Anokii surface whose authoritative source sits outside Nation-controlled
infrastructure. The governance questions are therefore settled before the
transport question, not after it.

### Applying the OCAP principles

The First Nations principles of OCAP (ownership, control, access, and
possession) are established and stewarded by the First Nations Information
Governance Centre. FNIGC describes ownership as the relationship of First
Nations to their information, held collectively; control as the right to seek
control over all aspects of information management processes that affect them;
access as the requirement that First Nations can reach information about
themselves regardless of where it is held, and can decide who else may; and
possession, or stewardship, as physical control of data, which is the
mechanism by which ownership is asserted and protected.

Two consequences bind this record directly.

First, FNIGC states that OCAP is not a doctrine or a prescription, and that its
interpretation is unique to each First Nation community or region. No vendor
and no software artifact can self-certify OCAP conformance. This document
describes controls a Nation can evaluate. It does not claim that any option is
OCAP compliant, consistent with the standing limitation in the repository
README.

Second, the FNIGC material reviewed for this record addresses community-level
and collective information, research, and information about communities and
their members; it does not address an employer's workforce or human-resources
records. Treating a
workforce directory as OCAP-governed is a position a Nation may choose to
assert. It is recorded here as an Anokii design assumption, not as an FNIGC
position.

| Principle | Question this record must leave answerable by the Nation |
| --- | --- |
| Ownership | Who owns the private workforce record, separately from who operates the directory that fed it? |
| Control | Can the Nation change the source scope, field map, approvals, and revocation without vendor or developer intervention? |
| Access | Can the Nation read, correct, and export the complete record, including provenance, without going through Anokii's operators? |
| Possession | Where does each copy physically rest, and can the Nation state that location with confidence? |

OCAP and PCAP are registered trademarks held by FNIGC. Any product-facing use
of the marks is a question for FNIGC, not one this record settles.

### Data control and residency

Possession is the principle most exposed by a cloud directory, and the
documented position is narrower than it is commonly assumed to be.

- A Microsoft Entra ID tenant is assigned one of six geo-locations: Australia,
  Asia/Pacific, EMEA, Japan, North America, or Worldwide. Canada is not among
  them, so a Canadian tenant resolves to North America. The tenant location
  cannot be changed after it is set.
- Microsoft lists Microsoft Entra ID among the services that do not carry
  specific data-residency commitments unless the Microsoft Product Terms state
  otherwise, and Entra ID does not appear among the services covered by the
  Durable Commitments on Data Location.
- Canada is a local region geography for Microsoft 365 content, and committed
  residency is obtainable for Exchange Online, SharePoint and OneDrive, Teams,
  and Copilot through the Product Terms, Multi-Geo, or the Advanced Data
  Residency add-on. That commitment covers document and communication content.
  It does not extend to the identity directory.
- Advanced Data Residency is licence-contingent. Microsoft states that where no
  durable commitment applies, storage location is subject to change without
  notice.
- Microsoft documents Entra multifactor authentication operations data as
  stored in North America and/or in geo location, and records the user
  principal name, voice-call telephone numbers, and SMS challenges.

The practical reading is that a Nation cannot presently assert Canadian
residency for directory identity data on the strength of a Microsoft 365
residency commitment, and cannot assert physical control over data whose
location may change without notice. This is an argument for keeping the
governed workforce record, its provenance, and its audit trail in
Nation-controlled storage rather than treating the directory as the record.
It is not, by itself, an argument against using the directory as a source.

Residency claims in this section come from vendor documentation, which defers
to the Microsoft Product Terms. The Product Terms are the contractual
authority and must be read before a Nation relies on any residency position.

### Privacy law, notice, and consent

Which Canadian privacy regime governs a First Nation government acting as an
employer is an open legal question, and this record does not answer it.

- The Privacy Act applies to government institutions, defined as a closed list
  of federal departments, scheduled bodies, and parent Crown corporations and
  their wholly-owned subsidiaries. In that Act, the term "aboriginal
  government" appears in the disclosure provisions, as a category of recipient
  for disclosures by federal institutions. Whether a particular Nation entity
  falls within the Act's definition of a government institution is a question
  for counsel; some First Nations bodies created by federal statute are
  scheduled.
- PIPEDA reaches personal information about an employee or an applicant only
  where the organization collects, uses, or discloses it in connection with the
  operation of a federal work, undertaking, or business. The Office of the
  Privacy Commissioner of Canada gives banks, telecommunications companies, and
  transportation companies as its examples.
- The OPC has published no determination that federal privacy legislation does
  or does not cover First Nations governments as employers. In its 2024
  appearance before the Standing Senate Committee on Indigenous Peoples it
  described Indigenous data sovereignty as an unmet need requiring legislative
  attention.
- PIPEDA excludes business contact information, including name, position name
  or title, work address, work telephone and fax numbers, and work email, where
  an organization collects, uses, or discloses it solely to communicate with
  the individual in relation to their employment or profession. The word
  "solely" is load-bearing: a directory field reused for a secondary purpose
  falls outside the exclusion.

The two federal statutes above are not the whole option set. Provincial
private-sector privacy laws that expressly reach employee personal
information, including Alberta's PIPA, British Columbia's PIPA, and Quebec's
Law 25, are further candidates for counsel to weigh. This record names them so
the regime question is not read as a choice between two federal statutes. The
expectations below are common to the guidance reviewed for this record. They
are not a claim that every candidate regime imposes the same duties, and they
are offered as a defensible floor to design against while the regime question
is open:

- The OPC applies a reasonableness test to employee information covering the
  sensitivity of the information, whether the purpose is a legitimate need,
  whether the collection would be effective, whether less invasive means exist,
  and whether the loss of privacy is proportional to the benefit. The field
  roster should be argued against that test field by field.
- Collection is limited to what is necessary for identified purposes; use and
  disclosure are limited to the purposes for which the information was
  collected; retention is limited to as long as necessary; and the information
  must be kept accurate and up to date.
- Meaningful consent is generally required unless an exception applies, and
  employees should be told what is collected, used, and disclosed, including
  the consequence of declining.
- The OPC notes that the unequal power between employer and employee weakens
  consent as a justification. Any optional or opt-in directory field must be
  genuinely refusable without consequence, or it is not consent.
- Canada's federal, provincial, and territorial privacy commissioners have
  jointly resolved that employers should respect reasonableness, necessity, and
  proportionality when collecting or using employee information.

The OPC guidance reviewed for this record does not speak directly to publishing
employee information on a public website. That gap is not filled by inference
here. It is one of the questions privacy and legal review must answer.

Export and deletion obligations follow the same route. The record must be able
to produce a complete, human-readable export of everything held about a person,
including provenance and change history, and to delete it under an approved
retention rule. Neither capability may depend on the external directory still
being reachable, because a connector can be revoked or a tenant lost while the
Nation's obligation to the person continues.

### Blocking decision gate: privacy and legal review

Privacy and legal review is a gate, not a parallel activity. Implementation of
any transport, and any public projection of employee information, is blocked
until the Nation's counsel or delegated privacy authority has recorded, at
minimum:

1. which privacy regime the Nation accepts as governing these records;
2. the lawful basis and the notice or consent mechanism for each field held
   privately;
3. the separate lawful basis, notice, and approval for each field projected
   publicly, including whether the business contact information reading is
   being relied on and whether the "solely to communicate" condition holds;
4. the retention class and deletion rule for each field, and the export
   obligation;
5. the accepted residency position for the source directory and for the
   governed record; and
6. who may authorize an exception, and how an exception is recorded.

Engineering may build the synthetic proof of concept described below once the
issue #30 capability answers are recorded, and need not wait for this gate to
close, because the proof of concept uses invented identities only and can run
against a provisional retention rule. Engineering may not connect a real
directory, and may not publish any employee field, before this gate closes.

## Conditional recommendation

Prefer **assignment-scoped Entra application provisioning to an Anokii SCIM
endpoint** when all of these preconditions are confirmed:

- the governance gate above has closed;
- the authoritative workforce identities are available in Entra;
- the Nation can operate an enterprise application and provisioning job;
- licensing supports the intended group-based assignment model;
- an explicit assignment or security group expresses the approved source scope;
- owners accept the disable, deletion, quarantine, credential-rotation, and
  provisioning-log operating duties;
- the credential model in "Credential model" below is accepted with its
  documented limits; and
- the selected field map and public-projection policy have been approved.

This is preferable because source scoping remains under Nation-controlled Entra
administration and Anokii does not receive a general directory-reader grant.
When `Sync only assigned users and groups` is selected, Microsoft documents that
the provisioning service provisions and deprovisions according to assignment.
Group assignment requires Entra ID P1 or P2 and does not include nested groups;
only immediate members of an explicitly assigned group are provisioned.

Use **Microsoft Graph user delta** only when SCIM provisioning is unavailable or
operationally unsuitable. Microsoft documents `User.Read.All` as the least
privileged application permission for user delta, and lists `Directory.Read.All`,
`User.ReadWrite.All`, and others as higher privileged permissions that are also
accepted. Anokii must request the least privileged one and refuse the rest.
`User.Read.All` is still tenant-wide over user profiles, so Anokii must treat
Graph as a broader credential and enforce its own explicit allowlist after
reading. A group-membership read can use `GroupMember.ReadBasic.All`, which
Microsoft documents as the least privileged application permission for listing
group members, but that group permission does not narrow the tenant-wide
user-read authority.

Use a **private AD DS agent** only when Entra cannot be the boundary, and only
after the hybrid path in the next section has been ruled out on evidence. The
agent must run inside the trusted network, query a narrowly scoped directory
view, and send a minimal outbound projection to Anokii. The hosted application
must have no inbound route or credential capable of reaching a domain
controller.

Retain **manual governed records** as the fallback and correction path. Manual
records avoid connector privilege, but require a named owner and review cadence
to control drift.

No option is selected until the issue #30 inputs are answered. In particular,
the recommendation must not be converted into implementation merely because an
Entra tenant exists.

## Hybrid identity path and the provisioning boundary

Where on-premises AD DS holds the authoritative workforce identities, the
supported path is to synchronize the selected identities into Microsoft Entra
ID with the Nation's Microsoft hybrid tooling, and to make Entra the boundary
Anokii integrates with. Anokii does not read AD DS.

**Microsoft Entra Cloud Sync** is the cloud-managed option. Provisioning
orchestration runs in Microsoft Online Services; the on-premises component is
a lightweight provisioning agent that requires only outbound connections,
maintains an outbound channel through Azure Service Bus, and queries Active
Directory in response to cloud-issued requests. No inbound firewall opening is
required for the sync path, though egress to the documented Microsoft
endpoints must be permitted. Relative to Connect Sync, Cloud Sync alone
supports disconnected forests, multiple active agents with failover, and
on-demand provisioning.

**Microsoft Entra Connect Sync** is the on-premises sync engine. Microsoft
states Cloud Sync is replacing it and that it will be retired once Cloud Sync
reaches functional parity, without publishing a retirement date. Microsoft's
supported-scenario guidance names the cases Cloud Sync does not support,
including Microsoft Entra hybrid join, Windows Hello for Business, accounts in
one forest with mailboxes in a resource forest, very large domains, and
filtering directory objects on attribute values. Microsoft's published object
thresholds are not consistent between its comparison pages, so a Nation near
any limit must confirm against its own object count rather than against this
record.

Which of the two is operating, and which system is authoritative for each
attribute, is exactly the fact issue #30 asks for. Anokii's design does not
depend on the answer: in both cases Entra becomes the provisioning boundary and
the connector contract is unchanged. The answer matters for the Nation's
operating burden and for attribute authority, not for Anokii's transport.

Enabling hybrid sync does not by itself make Entra the identity boundary for
every system. Microsoft frames that as a phased migration in which applications
are individually re-pointed, and explicitly scopes applications that perform
LDAP writes or depend on obscure AD features out of that migration. Anokii is a
new consumer, so it can be built against Entra from the start rather than
migrated.

### Disposition: Microsoft's on-premises provisioning agent, ECMA host, and LDAP connector

These components are frequently proposed for this problem and are the wrong
direction for it. The disposition is recorded here so the question is not
reopened without new evidence.

Microsoft Entra on-premises application provisioning moves data **outbound**,
from Entra ID into an on-premises target application or directory. It is not a
mechanism for reading an on-premises directory as a source into Entra ID. The
LDAP connector documentation states both prohibitions explicitly: provisioning
users from Microsoft Entra ID to Active Directory Domain Services is not
supported, and provisioning users from LDAP to Microsoft Entra ID is not
supported. Its supported targets are non-AD-DS directories. The connector's
Full Import run profile exists so Entra can update an existing object for a
user rather than create a duplicate; it is not a source-of-truth ingestion
path into Entra.

The ECMA Connector Host is required only when the on-premises target is not
already SCIM. It exposes a local SCIM endpoint that the provisioning agent
calls. It is not relevant to reading a directory.

The confusion is understandable: Cloud Sync and on-premises application
provisioning share the same provisioning agent binary while moving data in
opposite directions. Microsoft recommends separate agent installations for the
two. Any Anokii document or issue that says "the provisioning agent" without
naming the extension is ambiguous and should be corrected.

One variant is genuinely relevant, and it is a deployment option rather than a
separate source. Microsoft's on-premises SCIM connector lets the provisioning
service reach a SCIM endpoint that has **no public inbound internet route**: the
agent connects outbound to the cloud and reaches the endpoint from inside the
network. If the Nation would prefer that the Anokii SCIM endpoint not be
publicly reachable, this keeps the recommended transport while removing the
public listener. It requires Entra ID P1 or P2, a supported Windows Server host,
and the documented administrative roles, and it adds an agent the Nation must
patch. Microsoft notes the provisioning agent does not currently auto-update for
the on-premises application provisioning scenario.

The tools Microsoft documents as reading AD DS as a source are Cloud Sync,
Connect Sync, and Microsoft Identity Manager with the Graph Connector, and all
three land the identities in Entra ID rather than in a third-party
application. API-driven inbound provisioning sources from an external system
of record pushed to Microsoft Graph, not from AD DS. A
private Anokii AD DS agent therefore remains the last resort it is described as
above, justified only where Entra cannot be the boundary at all.

Microsoft treats the Cloud Sync provisioning agent host as a control-plane
asset, recommending it be administered as a Tier 0 system, because an attacker
who controls the agent server can manipulate users in Microsoft Entra ID. Any
Nation-operated agent, Microsoft's or Anokii's, inherits that classification.

## Credential model

The credential question is separate from the transport question and does not
resolve the same way on both sides. The short form: Anokii will authenticate
**to Microsoft Graph** with a certificate or a federated assertion, but
Entra's provisioning service will authenticate **to an Anokii SCIM endpoint**
with a stored secret unless Anokii builds a federation token endpoint.

### Anokii reading Microsoft Graph

Entra app registrations support three credential types for application-only
authentication: client certificates, federated identity credentials, and client
secrets. Microsoft's own ordering prefers a managed identity first, then an
external platform identity through workload identity federation, then a
certificate, and rejects password secrets, describing them as unsuitable for
production. Client secrets are capped at 24 months with a recommended
expiration under 12 months, so a secret-based design carries a mandatory
recurring rotation obligation.

- **Managed identity** is unavailable unless the workload runs on Azure
  compute or an Azure-supported hosting platform. For a Nation-hosted Anokii
  deployment this is normally out of reach, and that constraint is about where
  the workload runs, not what it may call.
- **Workload identity federation** stores no Entra-side secret or certificate,
  in the scenarios Microsoft documents as supported. The application presents
  a token from an external issuer, which Entra exchanges for an access token.
  Microsoft documents support for Kubernetes clusters including on-premises,
  GitHub Actions, other major clouds, SPIFFE and SPIRE, and generically other
  workloads outside Azure through their own issuer. An application with its
  own OIDC issuer qualifies if the issuer signs with RS256 and publishes a
  discovery and keys endpoint Microsoft's token service can reach. Limits
  worth planning against: a maximum of 20 federated identity credentials per
  application, unique issuer and subject combinations, no wildcard subjects,
  and eventual consistency after configuration such that an immediate token
  request can fail.
- **Certificate credentials** are the private-key-JWT client assertion: only
  the public certificate is registered with Entra and the private key never
  leaves the workload. It is still a long-lived secret the operator must store
  and rotate, though an application can hold multiple registered certificates,
  which is what makes an overlapping rotation possible. Where a certificate
  must be used, Microsoft directs operators to a CA-issued certificate held in
  a secure key vault.

For a Nation-hosted Anokii, the ranking is therefore workload identity
federation where an acceptable issuer already exists or can be operated, and a
CA-issued certificate otherwise. A stored client secret is the option of last
resort and, if chosen, must carry a recorded rotation owner and cadence. Running
an OIDC issuer to enable federation is itself a signing-key lifecycle the Nation
takes on; that trade is a Nation decision, not a default.

### Entra provisioning into an Anokii SCIM endpoint

Microsoft documents four authorization methods for provisioning to a SCIM
endpoint: username and password, which is unsupported; a long-lived bearer
token; the OAuth 2.0 client credentials grant; and workload identity federation.
The authorization code grant has been retired.

The finding that matters for this record is that **SCIM does not inherently
avoid a long-lived shared credential**, and the common configurations retain
one at the Anokii-hosted endpoint:

- The provisioning Secret Token field holds a long-lived bearer token issued by
  the target application and presented by Entra on every SCIM request. It is a
  shared secret held by both parties and stored in the Entra provisioning
  configuration.
- Choosing the OAuth 2.0 client credentials grant does not remove the shared
  secret. Microsoft's own configuration takes a client secret that its
  documentation describes as long-lived. Client credentials rotates the derived
  access token automatically; it does not rotate the credential.
- Workload identity federation is the only production-supported method that
  stores no secret in the provisioning configuration. Entra presents a
  short-lived signed assertion under the OAuth 2.0 JWT bearer profile, and the
  target validates it against Microsoft's published keys and mints its own
  access token. That is a build requirement on the Anokii side, not a
  configuration toggle: Anokii must implement a token endpoint that performs
  the validation.
- Leaving the Secret Token blank so the endpoint validates an Entra-issued
  token is documented, but Microsoft describes it as a testing configuration
  that should not be used in production, and its audience is an application
  identifier shared by all custom SCIM applications, so an endpoint relying on
  it must additionally pin the issuing tenant. It is not a sovereign secretless
  design and must not be presented as one.
- Certificate or mutual-TLS authentication from the provisioning service to a
  custom SCIM target is not documented. Treat that as undocumented rather than
  as an affirmative statement that it is impossible.

Microsoft documents workload identity federation as supported for both gallery
and non-gallery applications, and publishes the setup steps and an
implementation guide, so its availability is not the open question. Microsoft
also documents that each customer establishes its own trust relationship and
that a single application-wide credential is not supported. What remains open
is practical rather than documentary: what the token endpoint costs Anokii to
build and operate, and whether the Nation's tenant, licensing, and
registration make the method selectable in practice. Those must be proven by
hands-on test, which is why the proof of concept carries them as a step. Until
they are proven, Anokii should be built to accommodate a long-lived shared
credential as the fallback rather than to assume one is unavoidable.

Rotation is a documented weak point on the SCIM side. Provisioning secrets are
replaced wholesale through the Microsoft Graph synchronization secrets
endpoint, with a separate call to validate credentials before saving.
Microsoft documents no overlap or dual-credential window, and does not name a
credential change among the changes that automatically restart a provisioning
job, so an explicit restart belongs in the runbook. The failure mode is slow
rather than loud: consistently failing calls against the target, invalid
credentials being Microsoft's own example, send the job to quarantine with a
one-time notification where a notification address is configured, after which
retries back off to daily and the job is disabled entirely if quarantine
persists for four weeks. A botched rotation can therefore degrade quietly for
weeks, which is an argument for monitoring provisioning job health
independently of the notification email.

## Capability and risk comparison

| Dimension | Entra to SCIM | Graph delta | Private AD DS agent | Manual records |
| --- | --- | --- | --- | --- |
| Direction | Push from Entra provisioning | Pull by Anokii | Outbound push/poll from trusted network | Human entry |
| Source scope | Assigned users or direct members of assigned security groups | Application reads users; Anokii filters to approved scope | Agent query base/filter | Operator workflow |
| Least privileged accepted grant | Tenant provisioning credential for one enterprise app | `User.Read.All` for user delta; `GroupMember.ReadBasic.All` for the scope group; higher privileged permissions are accepted but must be refused | Read-only directory identity constrained by ACL and query | Anokii authoring capability |
| Credential held at rest | Long-lived shared secret at the Anokii endpoint unless federation is built and verified | Certificate or federated credential; client secret only as last resort | Agent-held directory credential inside the network | None external |
| Change tracking | Provisioning service initial and incremental cycles | `@odata.nextLink` and `@odata.deltaLink`, token valid at most seven days | Agent-owned watermark/change mechanism | Review schedule |
| Disable semantics | `active=false` update for an out-of-scope or soft-deleted user, subject to the delete caveats below | Interpret `@removed` and property changes against current scope | Agent emits explicit suppression event | Operator suppresses |
| Hard delete | SCIM `DELETE` on source hard deletion, and on other events if the target does not support soft delete | Retention-governed local action after source evidence | Retention-governed local action | Retention-governed operator action |
| Out-of-scope signal | Explicit, from the provisioning service | None; scope changes are invisible to delta | Agent-defined | Operator-defined |
| Credential blast radius | One provisioning target | Tenant-wide user profile read | Scoped directory read inside private network | No external credential |
| Main operational risk | Mapping/scope error can affect a cohort; quiet credential-rotation failure | Over-broad read, seven-day token expiry, throttling | Private-agent compromise or stale delivery | Drift and missed offboarding |
| Preferred position | First candidate | Second candidate | Last-resort connector | Safe fallback |

`User.ReadBasic.All` is not assumed sufficient: Microsoft documents it as
constraining an application to a limited set of properties for other users,
and job title, department, business phones, and office location are not among
them. It is also not listed in either column of the permissions table for user
delta. `Directory.Read.All` is not requested by this design.

## Field scope

The candidate field set is the one recorded in issue #26 and is not widened
here. Each field still requires the per-field decisions issue #30 collects, and
each must survive the reasonableness test in the governance frame.

Two scope notes are settled in this record.

**Profile photos are excluded from the initial scope.** They are not carried by
the first connector and not projected publicly. If a Nation later wants them,
they are handled as a separate decision with their own approval, because their
mechanics differ from the text fields:

- The least privileged application permission for reading another user's photo
  is `ProfilePhoto.Read.All`, a permission distinct from the workforce read. An
  application already holding `User.Read.All` can read photos without it, which
  means the text-field grant silently carries photo-read authority and the
  design must not rely on permission scoping alone to keep photos out.
- A photo read returns binary data or a 404, and a metadata read for a user
  with no photo can return a 1x1 placeholder rather than an error, so absence
  must be detected rather than assumed from a success status.
- Requested sizes may be unavailable and a smaller uploaded size returned, so a
  fixed output size requires the dedicated sized endpoint.
- Microsoft notes that guest users can view profile photos even under the most
  restrictive guest access setting, which is a disclosure consideration in its
  own right for a tenant hosting external collaborators.
- A photo is a likeness. Its publication decision is not the same decision as
  publishing a job title, and it should not inherit that approval.

Whether a photo read has any mailbox or licensing dependency is not stated in
the current Microsoft reference pages. Widely repeated claims to that effect
could not be traced to primary documentation and are not asserted here. If a
Nation adopts photos, that behaviour must be established by test against its
own tenant.

**Fields stored outside Entra ID are out of scope.** Microsoft documents that
delta query does not track changes to properties such as `aboutMe`, `birthday`,
`hireDate`, `interests`, `skills`, and others held elsewhere. A design that
promised to reflect them could not keep that promise through delta.

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
- value-redacted diagnostics;
- per-field change history sufficient to reconstruct and roll back a single
  field; and
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
| Compromised directory tenant | Treat every source event as untrusted regardless of successful authentication; hold the governed record, its provenance, and its audit trail in Nation-controlled storage; support immediate connector suspension that stops ingestion without destroying the record; never let a source event delete Anokii-side history | Synthetic hostile tenant sending mass mutations produces quarantine, not writes; suspending the connector leaves every prior record and its history intact and readable |
| Malicious or compromised provisioning administrator | Recognize that the enterprise application owner, an object ownership relation rather than a directory role, can change scope and attribute mappings; do not treat tenant-side configuration as a trusted control; enforce the Anokii-side allowlist and field map independently; alert on mapping-policy version change | A scope or mapping change made only in the tenant cannot widen the Anokii field set or allowlist; the attempt is recorded and surfaced for review |
| Targeted single-record tampering | Per-field change history with actor, timestamp, prior value, and new value; independent review for high-sensitivity and already-public fields; no reliance on volume thresholds to catch a single edit | A single synthetic field change to one in-scope record is recorded, attributable, reviewable, and reversible without restoring unrelated fields |
| Field-level change to an already-public projection | Any change to a field that is currently publicly projected re-enters the disclosure workflow rather than flowing straight through; the prior public value is retained | A synthetic source update to a published field does not change the public projection until approved, and the previous public value remains recoverable |
| Insufficient audit retention | Retain Anokii-side provenance and change history on a Nation-set schedule, independent of the directory's retention; export tenant-side provisioning and audit logs where longer retention is required | Reconstructing a record's state at a date beyond the directory's log retention succeeds from Anokii-held evidence alone |
| Stale connector data | Track last successful cycle and source watermark; fail closed for publication according to approved stale-source policy | Expired watermark suppresses or queues review without inventing freshness |
| Replay or duplicate delivery | Actor-, tenant-, and source-bound idempotency key; atomic application | Repeated create, patch, deactivate, and delete requests converge without duplicate writes |
| Reordered lifecycle events | Compare source version/watermark and reject older state transitions | A delayed activation cannot reverse a newer suppression |
| Connector credential theft | Shortest practical lifetime, rotation with overlap where the platform allows it, tenant-specific credentials, rate limits, revocation, no secrets in logs | Old credential fails after overlap; audit retains identifier, not secret |
| Quiet credential-rotation failure | Monitor provisioning job health directly rather than relying on a one-time notification; alert on quarantine | A synthetic invalid credential raises an Anokii-side alert well before the platform disables the job |
| SCIM filter or PATCH abuse | Support only the documented schema and operators; bound page size and payload; reject ambiguous/multi-match filters | Fuzzed filters and patches fail deterministically without partial writes |
| Graph permission expansion | Pin exact application permissions and alert on consent drift at deployment preflight | Deployment preflight refuses unexpected grants such as `Directory.Read.All` |
| Private-agent compromise | Outbound-only network path, read-only directory identity, signed releases, host attestation/rotation where available; administer any agent host as a control-plane asset | Hosted Anokii has no route or credential to bind to AD DS |
| Attribute injection or value leakage | Type/length validation, output
encoding, field allowlist, value-redacted logging and audit metadata |
Synthetic secrets, markup, and stable source object identifiers never appear
in logs, metrics, traces, or rendered output |
| Account-type confusion | Explicit source category and policy; exclude guests, service/shared accounts, and vacant roles unless separately modeled | Each excluded synthetic category is rejected or routed to its own contract |
| Automatic publication | Private classification by default; distinct approval command and capability | Successful sync leaves every public projection unchanged |
| Local override loss | Per-field authority and override policy with visible conflict state | Source update preserves approved local override and creates a reviewable conflict |
| Incomplete offboarding | Immediate suppression is the safe default; retention-governed hard deletion is separate | Disable/out-of-scope event removes public projection before private retention action |

SCIM traffic must use TLS. Credentials must not be placed in a URL. LDAP binds
must use signing or TLS with channel binding as applicable; unsigned or simple
unencrypted binds are prohibited. Microsoft documents a discovery path before
enforcing LDAP signing, using the domain controller events that report unsigned
and clear-text binds, so that enforcement does not silently break clients.

Three limits on tenant-side detection are recorded because they shape what
Anokii must own rather than delegate.

First, Microsoft's accidental-deletion prevention is a per-cycle volume circuit
breaker. It counts objects staged for removal in a single cycle, covers disables
as well as deletes, and quarantines the job with a notification when the
threshold is exceeded. It is evaluated per cycle, so a change spread across
cycles never trips it, a single targeted removal below the threshold proceeds
silently, and Microsoft documents nothing analogous for attribute updates. A
targeted single-record edit is therefore outside its protection entirely.

Second, tenant-side evidence expires. Entra audit and sign-in logs are retained
seven days on the free edition and 30 days on P1 and P2, and provisioning logs
carry a comparable window, with export to Azure Monitor as the documented route
to longer retention. Provisioning log detail does record old and new values per
changed attribute, but only within that window. Entra's own backup and recovery
retains up to seven days of daily backups, does not cover hard-deleted objects,
and Microsoft describes recovering from an in-place misconfiguration as a
deliberate comparison against an externally held baseline rather than a restore.
Microsoft positions per-attribute rollback as a capability outside the built-in
platform.

Third, an application cannot infer tenant integrity. There is no documented push
mechanism telling an application that its own granted permissions changed;
detecting consent drift requires the application to re-read its state, which
itself needs a broader grant, or to inspect the roles in its own tokens, which
lags an actual revocation by the token lifetime. Continuous access evaluation
carries user-state events only and no signal about tenant configuration.

Taken together, these are the reason the reconstruction, rollback, and
field-level review controls above are Anokii's own design positions rather than
delegations to the identity platform. They are stated here as requirements on
Anokii, not as vendor-documented behaviour.

## Lifecycle and failure semantics

- **Create:** default to private and inactive for public projection. Duplicate
  matching must fail closed rather than choose a record.
- **Update:** apply only mapped source-owned fields. Local-owned fields remain
  unchanged; conflicting dual-owned fields enter review.
- **Out of scope, disabled, or soft deleted:** immediately suppress any public
  projection and retain private provenance according to policy. For SCIM the
  expected form is `active=false`.
- **Hard deleted:** record the event, keep the public projection suppressed,
  and perform private deletion only under the approved retention rule.
- **Source unavailable:** do not treat absence of a successful poll as deletion.
  Surface connector staleness without logging workforce values.
- **Mass change:** quarantine the whole candidate batch before any write.
- **Partial failure:** commit no batch-level mapping change. Individual SCIM
  requests remain idempotent and expose a stable, non-sensitive error code.

### An out-of-scope user can arrive as a DELETE

The most consequential correction in this record is that leaving scope does not
reliably arrive as a disable.

Microsoft documents four events that trigger a deprovisioning action: the user
is soft deleted in Entra ID, the user is permanently deleted, the user is
unassigned from the application, and the user stops passing a scoping filter.
Separately, Microsoft documents that the computed `IsSoftDeleted` attribute
can be true in four scenarios: the user is unassigned from the application,
the user does not meet a scoping filter, the user is soft deleted in Entra ID,
or the user's `AccountEnabled` property is set to false. These are two
different lists, and the consequence is the same either way: a target
receiving `active=false` cannot tell from the request alone which condition
produced it.

Microsoft further documents that when one of those events occurs and **the
target application does not support soft deletes**, the provisioning service
sends an HTTP `DELETE` to permanently delete the user from the application. The
documented trigger for substituting a delete is a property of the target's
capability, not of the tenant's attribute mapping. An Anokii endpoint that did
not implement `active=false` could therefore receive a hard `DELETE` on nothing
more than an unassignment or a scoping-filter change.

Two related points must not be merged into that one, because the documentation
points the other way:

- Removing `isSoftDeleted` from the attribute mappings is documented as the
  configuration for taking **no** action in the target, not as a cause of
  deletion.
- For the separate case of a user deleted in Entra ID, Microsoft instructs
  administrators to ensure `Delete` is not selected among the provisioning
  job's target object actions if nothing should happen in the target.
- The claim that an unmapped `active` attribute causes a `DELETE` could not be
  substantiated in Microsoft's documentation and is not asserted here.

The safe reading is that lifecycle behaviour is not deterministic from
configuration alone. Microsoft's own deployment-planning guidance states the
expected result for an out-of-scope user disjunctively, as disabled or deleted.
Anokii must therefore implement soft delete properly, must not assume the
mapping is correct, and must verify the actual wire behaviour by test.

### Skip out of scope deletions

Microsoft documents that the provisioning engine soft deletes or disables users
that go out of scope by default, and provides `SkipOutOfScopeDeletions` to
override it. Set to false, accounts going out of scope are disabled in the
target; set to true, they are not. The flag is set per provisioning application
through the Microsoft Graph beta synchronization secrets endpoint by an
administrator holding at least Application Administrator, with no admin-centre
toggle; the secrets collection is replaced wholesale, so the existing values
must be read and re-sent. It does not apply to cross-tenant synchronization.

Two limits matter for a Nation relying on it. Both documented outcomes concern
disabling, and the documentation does not state that the flag suppresses a hard
delete, so it must not be treated as deletion protection. And it depends on a
beta Graph endpoint, which is a supportability risk worth recording rather than
discovering later.

### Anokii-side handling is retention-governed and non-destructive

None of the above changes Anokii's own behaviour, which is the point of keeping
the governed record separate from the directory. A `DELETE` arriving at the SCIM
endpoint is an event to record, not an instruction to execute. Anokii suppresses
the public projection immediately, retains private provenance and change history
under the approved retention rule, and performs private deletion only when that
rule calls for it. The source can be wrong, compromised, or misconfigured; the
Nation's record survives that.

One documented ordering trap belongs in the operator runbook: if a user is
unassigned from the application before being deleted from the directory, the
provisioning service stops managing them after the disable and never sends the
subsequent deletion signal. Offboarding that unassigns first strands a disabled
record that will never be told the person was deleted, so reconciliation cannot
rely on the connector alone.

## Connector operations

**Throttling.** Microsoft Graph signals throttling with HTTP 429 and a
`Retry-After` header. The documented handling is to honour `Retry-After` and
retry, falling back to exponential backoff where the header is absent, and to
avoid immediate retries because throttled requests still accrue against usage
limits. Microsoft names continuous polling and repeated full scans of a
collection as patterns that lead to throttling, and directs applications to
change tracking instead, which is direct support for a delta-based design over
periodic full enumeration. Identity limits are expressed as resource units per
application and tenant over a short window, with smaller tenants in the tightest
bucket, so a small Nation tenant has less headroom than a large one rather than
more. Microsoft states that the resources described in the service-specific limits
provide a `Retry-After` header except where indicated, so an unattended client
must implement backoff as a fallback rather than depend on the header always
being present.

**Delta token expiry and full resynchronization.** Delta tokens for directory
objects are valid for at most seven days. Microsoft documents two distinct
failure signals: a 410 Gone synchronization reset, where the client follows the
supplied link and re-enumerates, and a 4xx error such as `syncStateNotFound`
where the token has simply expired and the client starts a fresh delta cycle.
Neither path preserves prior state; both are full synchronizations. The error
code `resyncRequired` belongs to a different Graph surface and must not be
written into an Anokii implementation as the code a workforce client will
receive.

Two consequences follow for correctness rather than availability. Resources
deleted before a delta cycle is initialized are never returned, so a fresh
baseline after an expired token cannot replay the deprovisioning that happened
while the connector was down. And a scoping decision made on the Anokii side has
no equivalent in raw delta: user delta supports only tracking specific objects
by identifier, so nobody ever falls out of a business-rule filter in the feed.
Both gaps must be closed by an independent reconciliation pass rather than
assumed away. Relatedly, if `$select` is used, changes to unselected properties
do not surface the object at all, so any property the lifecycle depends on,
including account state, must be selected on the initial request.

**Offboarding a connector or a tenant.** Revoking access is a sequence, not a
single action. An administrator deletes the application's delegated permission
grants and application role assignments, noting that the admin centre does not
cover every case and that revocation does not prevent later re-consent.
Revocation is not instantaneous: already-issued access tokens remain valid for
their remaining lifetime, so revocation should be paired with deactivating or
deleting the service principal. Microsoft suggests deactivation as the middle
step where the intent is to suspend an integration pending review while
preserving its configuration and audit surface. Full removal is deleting the
enterprise application, which soft deletes for 30 days before automatic
permanent deletion.

On the Anokii side, offboarding must be a first-class operation rather than an
absence of traffic: the connector is marked suspended, ingestion stops, public
projections follow the approved suppression policy, stored credentials are
destroyed, and the governed records and their history remain intact and
exportable. Because tenant-side evidence expires on the retention windows noted
above, any log export required for the Nation's own review must happen before
the connector is torn down, not after.

## Synthetic proof-of-concept plan

The first PoC uses an isolated synthetic tenant and invented identities only.
It must not require or accept a production directory export.

1. Confirm the issue #30 capability matrix and select one transport.
2. Freeze the exact source scope, field map, permissions, lifecycle behavior,
   deletion threshold, and a provisional retention rule in a reviewable
   fixture. The provisional rule is a test input, and is replaced by the
   approved rule before any real directory is connected.
3. Exercise create, update, disable, restore, hard-delete, replay, reordering,
   duplicate match, and connector-credential rotation.
4. Verify, rather than assume, how the source actually represents lifecycle
   state: confirm the `active` and `IsSoftDeleted` mapping is present and
   correct, and record the exact request Anokii receives for each of
   unassignment, scoping-filter exit, sign-in block, soft deletion, and
   permanent deletion. Prove specifically whether any of those arrive as
   `DELETE` rather than `active=false`, and prove that the `Delete` target
   object action and `SkipOutOfScopeDeletions` behave as configured.
5. Prove that a `DELETE` at the endpoint suppresses the public projection and
   preserves private provenance under the retention rule, performing no
   immediate destructive local write.
6. Include employee, contractor, elected-official, guest, shared/service
   account, and vacant-role categories with synthetic values.
7. Prove two-tenant isolation with identical source IDs.
8. Prove that synchronization alone creates no login, role, or public profile.
9. Prove logs, traces, metrics, audit attributes, and error bodies contain no
   workforce attribute values, stable source object identifiers, or
   credentials.
10. Prove mass-change quarantine and recovery before enabling an incremental
    cycle.
11. Prove single-record controls that thresholds cannot cover: a single field
    edit is attributable, reviewable, and reversible without disturbing other
    fields, and a change to an already-public field re-enters the disclosure
    workflow.
12. Prove the credential model end to end: build and exercise the workload
    identity federation token endpoint against a synthetic provisioning job,
    confirm the method is selectable in practice for a non-gallery
    application, and rehearse a credential rotation including the job restart.
13. Prove recovery from delta-token expiry and from a 410 synchronization
    reset, including the reconciliation pass that catches deprovisioning missed
    while the connector was down.
14. Prove connector offboarding: suspension, credential destruction, projection
    suppression, and continued exportability of the governed record.
15. Record operator runbooks for scope change, credential rotation, connector
    outage, quarantine, correction, emergency suppression, and offboarding.

The PoC is evidence for a later ADR. It is not a release or deployment.

## Nation-owned decision gates

Implementation remains blocked until issue #30 records these answers without
private values:

- directory topology and authoritative system by field;
- whether the hybrid path is Cloud Sync or Connect Sync, where applicable;
- business owner, identity owner, emergency-removal owner, and approvers by
  role;
- Entra provisioning availability, licensing, and consent authority;
- approved source-scope mechanism and account-category rules;
- field classification, public purpose, override, and suppression rules (the
  per-field retention class and deletion rule are recorded by the privacy and
  legal review gate, not here);
- whether profile photos are adopted after the initial scope, and if so under
  what separate approval;
- accepted credential model, and the named owner and cadence for rotation;
- residency position as recorded by the privacy and legal review gate,
  together with any technical constraint the Nation's hosting imposes on it;
- stale-source and mass-change behavior; and
- connector operations, monitoring, quarantine, and credential rotation
  ownership.

The privacy and legal review gate described in the governance frame is a
separate blocking condition. It is not satisfied by the issue #30 capability
answers, and it is not delegated to the implementation team.

Unknown answers stay unknown. The implementation team must not infer them.

## Primary references

Indigenous data governance and Canadian privacy:

- First Nations Information Governance Centre, [The First Nations Principles of OCAP](https://fnigc.ca/ocap-training/)
- Office of the Privacy Commissioner of Canada, [Privacy in the workplace](https://www.priv.gc.ca/en/privacy-topics/employers-and-employees/02_05_d_17/)
- Office of the Privacy Commissioner of Canada, [Joint resolution on employee privacy](https://www.priv.gc.ca/en/about-the-opc/what-we-do/provincial-and-territorial-collaboration/joint-resolutions-with-provinces-and-territories/res_231005_02/)
- Government of Canada, [Personal Information Protection and Electronic Documents Act](https://laws-lois.justice.gc.ca/eng/acts/P-8.6/page-1.html)
- Government of Canada, [Privacy Act](https://laws-lois.justice.gc.ca/eng/acts/P-21/page-1.html)

Hybrid identity and provisioning:

- Microsoft, [What is Microsoft Entra Cloud Sync](https://learn.microsoft.com/en-us/entra/identity/hybrid/cloud-sync/what-is-cloud-sync)
- Microsoft, [What is Microsoft Entra Connect](https://learn.microsoft.com/en-us/entra/identity/hybrid/connect/whatis-azure-ad-connect)
- Microsoft, [Understand how application provisioning works](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/how-provisioning-works)
- Microsoft, [Develop and plan provisioning for a SCIM endpoint](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups)
- Microsoft, [Manage users and groups assigned to an application](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/assign-user-or-group-access-portal)
- Microsoft, [On-premises application provisioning architecture](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/on-premises-application-provisioning-architecture)
- Microsoft, [Configure the on-premises LDAP connector](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/on-premises-ldap-connector-configure)
- Microsoft, [Provisioning to SCIM applications on-premises](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/on-premises-scim-provisioning)
- Microsoft, [Skip deletion of out of scope users](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/skip-out-of-scope-deletions)
- Microsoft, [Accidental deletion prevention for provisioning](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/accidental-deletions)
- Microsoft, [Application provisioning in quarantine status](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/application-provisioning-quarantine-status)

Microsoft Graph:

- Microsoft Graph, [Get incremental changes for users](https://learn.microsoft.com/en-us/graph/api/user-delta?view=graph-rest-1.0)
- Microsoft Graph, [Use delta query to track changes](https://learn.microsoft.com/en-us/graph/delta-query-overview)
- Microsoft Graph, [Permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference)
- Microsoft Graph, [List group members](https://learn.microsoft.com/en-us/graph/api/group-list-members?view=graph-rest-1.0)
- Microsoft Graph, [Get profilePhoto](https://learn.microsoft.com/en-us/graph/api/profilephoto-get?view=graph-rest-1.0)
- Microsoft Graph, [Throttling guidance](https://learn.microsoft.com/en-us/graph/throttling)
- Microsoft Graph, [Get access without a user](https://learn.microsoft.com/en-us/graph/auth-v2-service)

Credentials, residency, and operations:

- Microsoft, [Workload identity federation](https://learn.microsoft.com/en-us/entra/workload-id/workload-identity-federation)
- Microsoft, [Certificate credentials for application authentication](https://learn.microsoft.com/en-us/entra/identity-platform/certificate-credentials)
- Microsoft, [Security best practices for application registration](https://learn.microsoft.com/en-us/entra/identity-platform/security-best-practices-for-app-registration)
- Microsoft, [Microsoft Entra ID data residency](https://learn.microsoft.com/en-us/entra/fundamentals/data-residency)
- Microsoft, [Microsoft 365 data residency overview](https://learn.microsoft.com/en-us/microsoft-365/enterprise/m365-dr-overview?view=o365-worldwide)
- Microsoft, [Microsoft Entra reports data retention](https://learn.microsoft.com/en-us/entra/identity/monitoring-health/reference-reports-data-retention)
- Microsoft, [Provisioning logs](https://learn.microsoft.com/en-us/entra/identity/monitoring-health/concept-provisioning-logs)
- Microsoft, [Review and revoke application permissions](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/manage-application-permissions)
- Microsoft, [Delete an enterprise application](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/delete-application-portal)
- Microsoft, [LDAP signing for Active Directory Domain Services](https://learn.microsoft.com/en-us/windows-server/identity/ad-ds/ldap-signing)
- IETF, [RFC 7644: SCIM protocol](https://www.rfc-editor.org/rfc/rfc7644.html)

These sources define platform capabilities and regulator expectations, not the
Nation's policy decision. They must be rechecked when an implementation ADR is
proposed.
