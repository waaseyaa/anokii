# Operator demo

Prototype an operator workflow inside the real Anokii operator dashboard, using
fictional data. The demo renders the unmodified `@anokii_operator` dashboard and
module templates from a fixture file that the host supplies, under PHP's built-in
server. It needs no Waaseyaa kernel, database or sign-in, and the package's own
code makes no network calls.

Anokii owns the shell, the demo entry point and its inert chrome. The host owns
its branding and theme, fixtures, workflow pages, and any previews of its own
public website, email or social posts. Nothing host-specific belongs in Anokii.

## Trust model

The fixture file, the host's templates, and the CSS and JavaScript they load are
trusted host code. The fixture is plain PHP that runs in the demo process. Page
styles and scripts run in the browser with the same reach as the package's own
chrome. The package guarantees only what it controls:

- **Package code** makes no network calls. It renders templates and serves the
  files the fixture declares, plus the package's own two primitive assets.
- **Browser connections** are blocked by the Content-Security-Policy below
  (`connect-src 'none'`), whatever script the page runs.
- **Host sources** are the host's responsibility. Keep them free of network
  calls, outside links and real data, and guard them in your own tests. See
  `tests/OperatorDemoOfflineGuardTest.php` in the Anokii repository for the
  guard Anokii runs over its own demo sources.

## What stays inert

- Every response carries `Content-Security-Policy: default-src 'none';
  script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';
  img-src 'self' data:; font-src 'self'; connect-src 'none'; form-action 'none';
  frame-ancestors 'none'; base-uri 'none'`, plus `Cache-Control: private,
  no-store`, `X-Robots-Tag: noindex, nofollow` and `X-Content-Type-Options:
  nosniff`. Under that policy the browser blocks connections, remote files and
  form submissions from the page.
- A persistent "Demo: nothing is saved or sent" marker sits at the top of the
  main column on every page.
- The user chip labels the fixture operator as a sample identity and has no
  sign-out link.
- Only `GET` and `HEAD` are accepted. The demo serves `/admin/anokii`, the
  fixture's module routes, the assets the fixture declares, and
  `/anokii-demo/primitives.css` and `/anokii-demo/primitives.js`. Every other
  path is a 404, including files in the directory `php -S` was started from.
- `router.php` answers only under PHP's built-in server; any other SAPI gets an
  empty 404. The package registers no provider or route, so production routing
  never reaches the demo.

The marker, user chip, brand and nav come from the package, and a page's Twig
blocks can't replace them (see [Custom module pages](#custom-module-pages)).
That guarantee covers templates only: host CSS or JavaScript running in the page
can still hide or change them.

## Run the example

From the Anokii repository root, after `composer install`:

```bash
ANOKII_OPERATOR_DEMO_AUTOLOAD=vendor/autoload.php \
ANOKII_OPERATOR_DEMO_FIXTURE=packages/operator/examples/demo/fixture.php \
php -S 127.0.0.1:8080 packages/operator/demo/router.php
```

```powershell
$env:ANOKII_OPERATOR_DEMO_AUTOLOAD = 'vendor/autoload.php'
$env:ANOKII_OPERATOR_DEMO_FIXTURE = 'packages/operator/examples/demo/fixture.php'
php -S 127.0.0.1:8080 packages/operator/demo/router.php
```

Then open <http://127.0.0.1:8080/admin/anokii>. The example is a fictional
"Example Nation" with two scenario pages and one generic module page:

- **Communications desk** (`desk`): choose a source (drop a document, whose
  name is the only thing kept, or pick a sample), choose Public or Members only,
  choose channels, read every draft, approve, and publish as a simulation. Public
  updates offer the website, the newsletter and social media. Members-only
  updates offer only the members portal and email to opted-in members, never a
  public or social channel.
- **Needs review** (`review`): approve sample submissions or send them back with
  a required note, confirm, and apply the decisions as a simulation.
- **Records** (`records`): the package's generic module page.

Each flow has one target set to fail on its first try: Social media post
(public) and Email to opted-in members (members only) on the desk, and the
summer student job in review. Choose that target to see a simulated failure
and retry.

## Run a host demo

Keep the demo files in your own repository, outside the web root, for example
`demos/operator/` with `fixture.php`, `templates/` and `assets/`. Leave that
directory out of release archives (`/demos/ export-ignore`). Then, from your
project root:

```bash
ANOKII_OPERATOR_DEMO_AUTOLOAD=vendor/autoload.php \
ANOKII_OPERATOR_DEMO_FIXTURE=demos/operator/fixture.php \
php -S 127.0.0.1:8080 vendor/waaseyaa/anokii-operator/demo/router.php
```

Both settings are required. The router never guesses paths. Relative values
resolve against the directory `php -S` starts in, and paths inside the fixture
resolve against the fixture file's directory. The fixture is read again on every
request, so edits show on reload. Bind to `127.0.0.1`.

## Fixture reference

A fixture is a PHP file that returns an array. Like the rest of your demo files,
it's trusted host code (see [Trust model](#trust-model)).

```php
use Anokii\Operator\Module\OperatorModule;

return [
    'brand' => [
        'title' => 'Example Nation',             // required
        'tag' => 'Operator workspace',           // optional
        'logo_src' => '/demo-assets/logo.svg',   // optional; must be a declared asset
        'logo_alt' => 'Example Nation',          // optional; defaults to the title
    ],
    'theme_href' => '/demo-assets/theme.css',    // optional; must be a declared asset
    'operator' => ['name' => 'Sample Operator', 'role' => 'Communications'], // required
    'dashboard_intro' => 'Choose a task to get started.', // optional
    'modules' => [                               // required, at least one
        new OperatorModule('updates', 'Updates', 'Daily work', '/admin/anokii/updates', 'Review sample updates.'),
    ],
    'pages' => [                                 // optional, keyed by module id
        'updates' => [
            'template' => 'updates.html.twig',   // optional host page; see below
            'title' => 'Updates',                // optional; defaults to the module label
            'eyebrow' => 'Communications',       // optional; defaults to the module group
            'intro' => 'Review sample updates.', // optional; defaults to the module description
            'context' => ['drafts' => []],       // optional; extra template variables
        ],
    ],
    'templates' => 'templates',                  // required when a page names a template
    'assets' => [                                // URL path => file
        '/demo-assets/theme.css' => 'assets/theme.css',
        '/demo-assets/logo.svg' => 'assets/logo.svg',
    ],
];
```

Validation happens before anything renders, and unknown keys are errors.

- **Modules** are `OperatorModule` values, so they get the same checks as in
  production, and they pass through `OperatorModuleCatalog`, which rejects
  duplicate ids. Each module needs its own route below `/admin/anokii/`, with no
  query or fragment.
- **Page context** can't replace the keys the shell owns: `nav`, `tiles`,
  `nav_active`, `home_path`, `logout_path`, `user_*`, `brand_*`, `theme_href`,
  `page_title`, `page_eyebrow`, `page_intro` and `page_blocks`.
- **Assets** are served only at the paths you list. A path must be a plain local
  path outside `/admin/anokii` and `/anokii-demo/` (the package's own demo
  assets), and its extension must be one of: css, js, svg, png, jpg, jpeg, gif,
  webp, avif, woff or woff2. Scripts can be ES modules
  (`<script type="module" src="...">`).

## Branding

Set `brand` and `operator`, then point `theme_href` at a stylesheet that
overrides the shell tokens:

`--anokii-shell-font`, `--anokii-shell-display-font`, `--anokii-shell-ink`,
`--anokii-shell-bg`, `--anokii-shell-card`, `--anokii-shell-line`,
`--anokii-shell-muted`, `--anokii-shell-side-bg`, `--anokii-shell-side-line`,
`--anokii-shell-side-muted`, `--anokii-shell-accent`, `--anokii-shell-accent-ink`
and `--anokii-shell-accent-soft`.

The same theme file can serve your live operator pages, so the demo and
production look the same. Declare fonts and images the theme loads as assets
too; the CSP blocks anything remote.

## Custom module pages

Write a demo page the way you'd write a live module page: extend the shell and
override page blocks.

```twig
{% extends '@anokii_operator/shell.html.twig' %}

{% block shell_styles %}.desk-list{display:grid;gap:12px}{% endblock %}

{% block content %}
  <h1>{{ page_title }}</h1>
  <ul class="desk-list">{% for draft in drafts %}<li>{{ draft.title }}</li>{% endfor %}</ul>
{% endblock %}

{% block page_scripts %}<script>/* page behaviour, no network calls */</script>{% endblock %}
```

- A page may define `title`, `head_extra`, `shell_styles`, `content` and
  `page_scripts`. Link your own stylesheets, such as public-frontend preview
  styles, from `head_extra` as declared assets.
- The demo renders each of those blocks on its own and places the results in the
  shell. Code outside blocks never runs: a top-level `{% set %}` has no effect,
  so pass data through `pages.<id>.context`, and a top-level `{% import %}` or
  `{% from %}` in the page, or in a host layout it extends by a plain template
  name, fails with an error. Import macros inside the block that uses them.
- The brand, nav, dashboard tiles, user chip, demo marker and mobile nav are
  always rendered by the package. A page that overrides `brand`, `nav`,
  `userchip`, `sidebar_footer`, `topbar` or `main_footer` fails with an error
  naming the block. This holds for the page's Twig only; its styles and scripts
  still run against the whole document.
- Template names resolve inside the fixture's `templates` directory, so pages
  can include your own partials by name.

A module without a `template` uses the package's
`@anokii_operator/module-preview.html.twig`. Fill it through `context` with
`primary_title`, `primary_intro`, `preview_rows` (a list of `title`, `detail`
and `state`) and `quick_actions` (a list of `label` and `href`; keep each
`href` local, such as `#section`).

## Shared demo primitives

The package ships a primitive only when both fictional scenarios in the Anokii
repository use it. There are three:

| Primitive | Markup (Twig macro) | Behaviour (`/anokii-demo/primitives.js`) |
| --- | --- | --- |
| Simulation label | `demo.sim(text)` | None. |
| Step indicator | `demo.steps(label, names)`, starting on the first step | `showStep(list, sections, index)` shows one step section, hides the others, marks earlier steps done, and moves focus to the section's heading. |
| Outcome list | `demo.outcomes(id, label)` | `showOutcomes(container, targets, options)` shows one simulated result per `{ id, label }` target. Targets in `options.failFirst` fail once and offer Retry; a retry always succeeds and keeps focus on that row. `doneText`, `failedText` and `simulatedText` set the wording. |

Use them from a host page:

```twig
{% block content %}
  {% import '@anokii_operator_demo/primitives.html.twig' as demo %}
  {{ demo.steps('Publishing steps', ['Source', 'Review', 'Results']) }}
  ...
{% endblock %}

{% block page_scripts %}<script type="module" src="/demo-assets/page.js"></script>{% endblock %}
```

```js
import { showOutcomes, showStep } from '/anokii-demo/primitives.js';
```

Host demo pages load the primitives' styles automatically. The simulation
label, outcome states, Retry button and focus rings use fixed colours, measured
for AA contrast against white cards. The step indicator follows the shell
tokens, so its contrast depends on the host theme; check it with your theme.
Nothing in the primitives reads files, stores data or opens a connection.

The communications desk's drop zone is not a primitive, because only that
scenario uses one. It stays in the example page (`examples/demo/`), where it
keeps only a chosen or dropped file's name and clears the file input straight
away. Channel drafts and public-frontend previews also stay with each host.

## Accessibility and DIR-A001

Maintainer decision, 2026-09-24: demo scenario pages are **not** production
product surfaces. They are fictional, local-only development tooling with no
production route, so they need no DIR-A001 charter exception. The real operator
shell stays governed by DIR-A001.

Demo pages and the shared primitives must still meet WCAG 2.1 AA. They are held
to it by the checks below, without adding a JavaScript toolchain for axe-core:

- **Semantic markup:** one `h1` per page and no skipped heading levels; a
  visible label on every control; radio and checkbox groups in a `fieldset` with
  a `legend`; errors in `role="alert"` regions and results in a `role="status"`
  region; state shown in words or marks, never by colour alone.
- **Keyboard:** only native controls; no positive `tabindex`; a visible focus
  ring on every control; focus moves to each new step's heading, to the field
  that needs fixing after an error, and to a row's new state after Retry.
- **Responsive:** no horizontal scrolling at 320px; the shell's mobile nav
  behaves as it does in production.
- **Contrast:** fixed colours and the example theme are measured in tests. A
  host theme needs its own check, because the shell and step indicator follow
  its tokens.
- **Returning to a page:** a scenario page rebuilt on Back or Forward starts
  clean, so a control the browser restored is never out of step with what the
  page shows. A page kept whole in the browser's back/forward cache comes back
  exactly as it was left.

`tests/OperatorDemoAccessibilityTest.php` checks the rendered markup and the
colour pairs. Keyboard, focus and responsive behaviour are checked by hand, and
the results are recorded in the pull request that changes them:

1. Walk each scenario with the keyboard alone (Tab, Shift+Tab, Space, Enter,
   and the arrow keys in radio groups), including every error and a Retry.
2. Leave a scenario part-way through, then come back with Back.
3. Check widths of 320, 375 and 640 pixels (the last approximates 200% zoom).

## Pin and refresh the package

Until tagged releases exist, hosts consume the `waaseyaa/anokii-operator` split
repository, which this monorepo projects from reviewed `main` commits. Always
pin an exact reviewed commit, never a moving branch:

- **Composer VCS dependency:** add the split repository as a `vcs` repository,
  require `"waaseyaa/anokii-operator": "dev-main#<40-character commit>"`, and
  commit `composer.lock`.
- **Package carried in your repository** (a Composer `path` repository with
  `symlink: false`): copy the package tree of one reviewed split commit, for
  example from `git archive <commit>`, which leaves out Anokii's tests and
  examples. Record that commit beside the copy.

To refresh, pick the new reviewed commit, update the pin or the copy and its
recorded commit, run `composer update waaseyaa/anokii-operator`, then run your
installed-package tests and check your live operator pages. A refresh changes
what your `/admin/anokii` serves, so review and ship it like any other
production change. Running the demo changes nothing you serve.

Package archives leave out `tests/` and `examples/`, so a dist install has no
example fixture. Copy `examples/demo/` from the Anokii repository as a starting
point.
