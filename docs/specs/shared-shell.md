# Shared Anokii shell

The "shared shell" is the common core every Anokii instance used to re-derive by
hand: the dashboard chrome, the public-open / login-gated split, the
role/permission model, the session auth helper, the per-entity access policy
shape, and the idempotent seeder skeleton. The distribution
(`waaseyaa/anokii`, namespace `Anokii\`) ships the BASES; an instance subclasses
them and supplies only its specifics (brand, entities, seed data, nav, routes).

This was extracted from two reference instances:

- intersnipe (`src/Access/DashboardAccess.php`, `src/Support/{Auth,Shell}.php`,
  `src/Controller/DashboardController.php`, `src/Access/{Domain,Project}AccessPolicy.php`,
  `src/Seed/PortfolioSeeder.php`, `templates/dashboard/_shell.html.twig`)
- fnpi-waaseyaa (`src/Access/WorkspaceAccess.php`, `src/Controller/AnokiiController.php`,
  `src/Access/DocumentAccessPolicy.php`, `src/Support/{Auth,AnokiiShell}.php`,
  `templates/anokii/_shell.html.twig`, the `app:assign-role` command, `PageSeeder`)

The dependency is one-way: Anokii consumes Waaseyaa, never the reverse.

## What the distribution ships

| Base | File | Kind | Extension surface |
|------|------|------|-------------------|
| Session auth helper | `src/Support/Auth.php` | `final` | static helpers: `currentUser`, `check`, `login`, `logout`, `userByEmail`, `requireAccountOrRedirect`, `label` |
| Shell context builder | `src/Shell/Shell.php` | `final` | static helpers: `context`, `roleLabel`, `initials`, `humanize` |
| Dashboard gate | `src/Dashboard/DashboardGate.php` | `abstract` | abstract `loginPath()`; protected `requirePage`, `requireAction`, `currentUser`, `redirectIfAuthenticated`, `json`, `render` |
| Workspace roles | `src/Access/AbstractWorkspaceRoles.php` | `abstract` | abstract `roleDefinitions()`; `apply`, `roles` (framework discovery), `allPermissions`, `permissionsFor`, `roleLabels`, `isRole`, `label` |
| Per-entity policy | `src/Access/AbstractEntityAccessPolicy.php` | `abstract` | abstract `entityTypeId()`, `editPermission()`, `administerPermission()`; overridable `viewAccess`, `classifiedFieldAccess` |
| Seeder | `src/Seed/AbstractSeeder.php` | `abstract` | abstract `noun()`, `seedRecords()`; `run` (final), `seedIf`, `skip`, `markCreated`, counters |
| Base shell template | `templates/anokii/_shell.html.twig` | Twig | blocks: `title`, `brand`, `content`, `topbar`, `sidebar_footer`, `head_extra`, `shell_styles`, `page_scripts`; context: `nav`, `nav_active`, `user_*`, `logout_path`, `home_path` |
| Dashboard grid partial | `templates/anokii/_dashboard_grid.html.twig` | Twig | context: `cards` |

Every public method and every block is an extension point and is marked `@api`.

## The six extracted concerns

### 1. Shell + dashboard grid (templates + `Shell`)

`_shell.html.twig` is the two-column app chrome (sidebar with brand slot, nav,
user chip; main content slot). It is entirely CSS-variable driven: an instance
rebrands by overriding the `--anokii-*` / `--color-*` tokens (the brand palette
already lives in `assets/theme/anokii-tokens.css`, which sets `--color-primary`)
and supplying its own `nav` list and `brand` block. No brand colours, fonts, nav
items, or entity names live in the template. `_dashboard_grid.html.twig` is the
home-page card grid, same token discipline.

`Anokii\Shell\Shell::context($user, $active, $extra, $roleLabels)` builds the
`nav_active` / `user_label` / `user_role` / `user_initials` context the shell
expects, merging instance `$extra` (the nav list, page data) on top. The role
label resolves from an instance-supplied `$roleLabels` map (typically
`AbstractWorkspaceRoles::roleLabels()`), falling back to a humanized role id.

### 2. Public-open / login-gated split (`DashboardGate`)

Dashboard routes are registered `allowAll()` at the framework routing layer, so
the framework's `AccessChecker` does NOT gate them; the controller enforces the
session itself. `DashboardGate` is the abstract base controller that owns this:

- An instance declares its app-owned `loginPath()` (for example
  `/dashboard/login` or `/anokii/login`). The redirect target is app-owned,
  never the framework default `/login`.
- `requirePage()` returns a `RedirectResponse` to `loginPath()` for an
  unauthenticated PAGE request, else null.
- `requireAction()` returns a `401` JSON body (`{ ok: false, error }`) for an
  unauthenticated JSON/action request, because an XHR cannot follow an HTML
  redirect.
- `render()` and `json()` are the shared Twig-render and body-decode helpers.

A concrete controller calls `requirePage()` / `requireAction()` at the top of
each handler.

### 3. Role / access model (`AbstractWorkspaceRoles`)

The common core of `DashboardAccess` and `WorkspaceAccess`. The instance declares
its role model in `roleDefinitions()` (id to `{label, permissions, weight?}`); the
base derives everything else:

- `apply(User, roleId): User` replaces any sibling role from this model
  (preserving roles owned by other models) and stamps the role's permission
  strings onto the user. **It returns the updated `User`** because
  `User::setRoles()` / `setPermissions()` return a new instance (entities are
  immutable through setters); the caller persists the returned user. This is a
  correctness fix over the reference instances, which called the setters
  fire-and-forget and relied on in-place mutation.
- `roles(): iterable<Role>` yields framework `Waaseyaa\User\Role` value objects,
  implementing `Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface`.

#### Framework tie-in (the assign-role replacement)

`ProvidesRolesInterface` (framework Phase 0b) is the capability the framework
role registry collects: at boot it iterates every service provider implementing
it and flattens their `roles()` into a `RoleRepository` keyed by role id, which
the new framework `user:assign-role` command consults to stamp a role's
permissions onto a user. By implementing it on `AbstractWorkspaceRoles`, an
instance whose `ServiceProvider` exposes the roles object is auto-discovered, and
the framework command replaces each instance's hand-rolled `app:assign-role`
command (which only existed because the framework previously had no role
discovery).

The `roles()` yield matches the exact `Role` constructor:
`new Role(id, label, permissions, weight)`.

### 4. Session auth helper (`Support\Auth`)

The common core of both instances' `Support\Auth`. Reads the signed-in `User`
from `$_SESSION['waaseyaa_uid']` (the value `AuthManager::login()` writes), so the
framework dev-fallback account (`DevAdminAccount`, id `PHP_INT_MAX`) never
satisfies the gate; only a genuine login does, in every environment. `login()`
resolves the account by email then delegates credential check and session write
to the framework `AuthManager`. `requireAccountOrRedirect()` is the gate
primitive `DashboardGate` builds on. Loads never throw: any failure yields null,
so "no account" and "broken account" are gated identically.

### 5. Per-entity access policy (`AbstractEntityAccessPolicy`)

The common shape across `DomainAccessPolicy` / `ProjectAccessPolicy` /
`DocumentAccessPolicy`:

- `view` -> any authenticated workspace account; anonymous is Neutral
  (workspace-only, fails closed under the handler's deny-by-default `isAllowed()`).
- `create` / `update` -> requires the entity's `editPermission()`.
- `delete` -> requires the entity's `administerPermission()`.

The subclass declares only `entityTypeId()`, `editPermission()`, and
`administerPermission()`. It implements both `AccessPolicyInterface` and
`FieldAccessPolicyInterface`, so the subclass is the access intersection type the
framework's `EntityAccessHandler` discovers via `instanceof`. Field access is
open-by-default (only an explicit Forbidden restricts) and delegates to the
overridable `classifiedFieldAccess()` hook for classification-aware gating; the
default grants everything, so classification gating is opt-in. Register a subclass
with `#[PolicyAttribute(entityType: '<id>')]` (or `#[AccessPolicy]`) on the
concrete class.

### 6. Idempotent seeder (`AbstractSeeder`)

The skip-existing / create / report skeleton both instances re-wrote. `run(CliIO)`
(final) resets counters, calls the subclass `seedRecords()`, and prints the
standard idempotency summary. The subclass loops over its data and calls
`seedIf($io, $label, $exists, $create)` per record (or `skip` / `markCreated`
directly). Re-running is always safe. Built only on the framework `CliIO`, so it
drops into a `nativeCommands()` handler unchanged.

## How an instance adopts the bases (Phase 3a)

1. **Bump the framework floor.** `AbstractWorkspaceRoles` implements
   `ProvidesRolesInterface`, added in framework Phase 0b. The interface (and the
   `RoleRepository` collector + `user:assign-role` command) is NOT in
   `alpha.208`, which is the floor this package currently pins. Move
   `waaseyaa/anokii`'s `require` floor to the framework release that ships Phase
   0b before the roles base can load. The other five bases resolve against
   alpha.208 today.

2. **Roles.** Replace the instance's `DashboardAccess` / `WorkspaceAccess` with a
   subclass of `AbstractWorkspaceRoles` that implements `roleDefinitions()`.
   Delete the instance's `AssignRoleCommand` / `app:assign-role` native command;
   the framework `user:assign-role` discovers the roles instead. Expose the roles
   object from the instance `ServiceProvider` so it is collected.

3. **Access policies.** Replace each per-entity policy with a small subclass of
   `AbstractEntityAccessPolicy` declaring `entityTypeId` + the two permissions.
   Keep the `#[PolicyAttribute]`. Delete the duplicated `view`/`create`/`update`/
   `delete` bodies. The instance's `handler()` factory stays (it lists the
   concrete policies); only the policy bodies collapse.

4. **Auth + Shell.** Delete the instance `Support\Auth` and `Support\Shell` /
   `AnokiiShell`; use `Anokii\Support\Auth` and `Anokii\Shell\Shell`. Pass the
   instance's `roleLabels()` into `Shell::context()` for the user chip.

5. **Dashboard controller.** Make the instance's dashboard controller extend
   `Anokii\Dashboard\DashboardGate`, implement `loginPath()`, and replace the
   inline `Auth::currentUser(...) === null` guards with `requirePage()` /
   `requireAction()`. The login/logout/settings handler bodies stay
   instance-owned.

6. **Templates.** Point the instance `_shell.html.twig` at the base
   (`{% extends "anokii/_shell.html.twig" %}` or `{% include %}` the grid), fill
   the `brand` block, pass the instance `nav`, and move brand colours into the
   `--anokii-*` token overrides in the instance theme CSS. Delete the duplicated
   chrome.

7. **Seeders.** Make each instance seeder extend `AbstractSeeder`, implement
   `noun()` + `seedRecords()`, and replace the bespoke skip/create/count loop with
   `seedIf()`.

What stays instance-specific and is never extracted: concrete brand colours and
font choices (CSS-var overrides), concrete entity classes (Domain, Document,
Project, ...), concrete seed data, the instance nav/module list, and the instance
route table.
