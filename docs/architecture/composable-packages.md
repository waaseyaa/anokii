# Composable Anokii packages

Issue [#8](https://github.com/waaseyaa/anokii/issues/8) defines the design boundary: Anokii remains a create-project distribution, while product capabilities that are safe to compose into an existing Waaseyaa application ship as smaller packages from this source monorepo.

## Ownership

`waaseyaa/anokii-core` owns only primitives shared by more than one Anokii capability:

- `Anokii\Core\Support\Values` for conservative normalization of untyped storage/config/request values;
- `Anokii\Core\Entity\SovereignMetadataTrait` for the persisted community and classification fields used by Anokii entities;
- `Anokii\Core\Access\AbstractEntityAccessPolicy` for immutable-principal-aware entity and field access decisions.

It owns no routes, providers, login behavior, roles, or product entities.

`waaseyaa/anokii-identity` owns the complete Identity domain:

- the canonical `identity_pillar` entity and persisted field contract;
- `PillarService`, including revisions and peer-language translations;
- Identity-specific edit/administer permissions and the canonical policy;
- entity/provider/policy/migration discovery metadata;
- the optional authenticated read-only host route/controller.

`waaseyaa/anokii-operator` owns the brandable operator shell, the module contract and catalogue, and fixture-driven demo support for prototyping operator workflows in that shell (`packages/operator/demo/README.md`). It registers no routes or providers. Hosts keep their own branding, fixtures, workflow pages and public-frontend previews.

The root distribution owns standalone login, role composition across all workspace tools, Twig workspace chrome, and the full Identity write controller. Those surfaces consume package classes; they do not redefine the domain.

## Activation and host contract

Composer installation is the entity/policy activation boundary. `IdentityServiceProvider::register()` always registers the one community-scoped, revisionable, translatable entity. Waaseyaa discovers the package policy from `extra.waaseyaa.policies`, including under optimized autoloading.

No second "embedded installation" mode exists. A host opts into only the supported presentation surface with framework configuration:

```php
'anokii' => [
    'identity' => ['host_surface' => 'read_only'],
],
```

When absent, no Anokii host route is mounted. When set to `read_only`, `/admin/anokii` and `/admin/anokii/identity` require framework authentication and the controller accepts only an immutable `AuthorizationPrincipalInterface`. Every returned entity passes through the kernel's canonical `EntityAccessHandler`; repository reads remain bound to the kernel's active `CommunityContextInterface`. Responses are `private, no-store` and `noindex, nofollow`. No write route is contributed.

## Pre-release dependency strategy

Until coordinated tags exist, the root monorepo consumes its package directories from the same source commit through Composer path repositories. External audited consumers require `anokii-core` and `anokii-identity` from split Git repositories and lock the resolved merged-`main` commit hashes; the package metadata's `dev-main` constraint is a development selector, not permission to deploy an unlocked branch. Consumers that need framework fixes newer than the latest tag must also pin the affected framework split package (currently `waaseyaa/foundation`) to its exact audited merged-main split commit. Releases remain frozen during this remediation.

The split repositories are projections, not independent authoring locations. Changes land in this monorepo, pass root and minimal-consumer gates, merge to `main`, then the reviewed split workflow projects the selected package. Tags, when eventually authorized, must be coordinated across dependency order (`anokii-core` before `anokii-identity`) and are outside issue #8.

## Required proof

- root distribution tests prove standalone Identity behavior survives relocation;
- package-boundary tests prove one entity/policy implementation and explicit route activation;
- a minimal Composer consumer proves neither the distribution nor full/AI/deployer dependencies are installed under normal and optimized autoloads;
- an installed host proves anonymous denial, authenticated view, denied writes, active-community isolation, private response headers, and framework policy discovery;
- downstream integration pins remote merged-main commits, never a machine-relative path.
