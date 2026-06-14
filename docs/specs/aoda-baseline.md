# AODA Level AA Baseline (Anokii surface, DRAFT)

> Status: Draft (WP04 design sketch). Built on the Waaseyaa framework. Not yet implemented.

This is a cross-cutting baseline, not a single screen. It describes a contract that every Anokii v0.1 surface inherits (Data Rooms, Co-Intelligence Workspaces, governed forms, the translation contributor dashboard, public SSR views). Where the section template below names a single surface, read it as "the contract this baseline imposes on every surface."

## Purpose

Charter directive DIR-A001 makes WCAG 2.1 Level AA a design constraint at the distribution level, not a feature that can be deferred to a follow-up sprint. A Nation workspace serves Council members, administrators, elders, language keepers, and community members across a wide range of devices, assistive technologies, and connectivity conditions. The accessibility floor is therefore part of the sovereignty story: a surface that a community member cannot operate with a keyboard or a screen reader is a surface that excludes part of the Nation it claims to serve.

This document defines the single baseline that every Anokii surface meets and the per-surface checklist a surface author runs before merge. It covers the five recurring problem areas (keyboard operability, color contrast against the deep-teal brand tokens, focus order and focus management, screen-reader semantics, and motion), plus the two DIR-A001 specifics that go beyond stock WCAG 2.1 AA (live-region announcements for OCAP access denials, and focus management plus progressive announcement for Co-Intelligence response surfaces).

The baseline is enforced, not advisory. Per DIR-A001 the axe-core CI gate runs on every pull request that touches a product surface, backed by per-component tests in Vitest plus Playwright. A surface that ships without an axe-core baseline is a charter violation. Bypassing the baseline requires a `charter-exception` record with a mandatory removal date, per the charter Exception Policy.

## Tier applicability

Accessibility is tier-invariant. The same WCAG 2.1 Level AA floor applies whether Anokii runs in sovereign single-tenant mode (FNPI, Intersnipe: one Nation owns and hosts the install) or shared-graph multi-tenant mode (OIATC: several communities share one graph, each viewing through a vantage). Accessibility is a property of the rendered surface, and the rendered surface is the same code path in both tiers.

Two tier-specific notes matter for the enforcement, not the requirement:

- **Sovereign single-tenant.** A Nation may apply a per-Nation theme override (the `theme` key in the tenant stub, today defaulting to `anokii-default` deep-teal). Any override ships its own contrast attestation: the axe-core contrast checks run against the override's resolved CSS custom properties, not only against the default palette. A theme that drops below the 4.5:1 / 3:1 thresholds is a failing build for that Nation, the same way a code regression would be.
- **Shared-graph multi-tenant.** Cross-Nation reads can surface access-denied states inline (a community-tier or nation-restricted field that the viewing vantage may not read). Those denials are an accessibility concern, not only an OCAP concern: the DIR-A001 live-region rules below govern how a denial is announced so a screen-reader user is told why a field is empty or absent rather than silently encountering nothing.

No accessibility data is held by this baseline. It introduces no per-user accessibility profile and no stored preference beyond the single reduced-motion signal described under Motion, which is read from the operating system, never persisted server-side. Nothing here is public in the sense of crossing the classification tiers; the contract is structural.

## User-facing surface

The baseline is not a screen a user visits. Its user-facing footprint is the set of guarantees a user can rely on across every Anokii surface:

- **A visible skip-to-content link** as the first focusable element, jumping past the banner and navigation to `#main-content` (the framework admin SPA already ships `<a href="#main-content" class="skip-link">`; Anokii surfaces inherit and must not regress it).
- **Full keyboard operability.** Every interactive control (links, buttons, form fields, the entity autocomplete combobox, tabs, disclosure widgets, dialogs) is reachable and operable with Tab, Shift+Tab, Enter, Space, the arrow keys where a composite widget calls for them, and Escape to dismiss overlays. No control is mouse-only.
- **A visible focus indicator** on every focusable element, meeting the 3:1 non-text contrast requirement against its background (see Contrast below). The indicator is never removed without an equally visible replacement.
- **Persistent, visible labels** on every form input. Placeholder-only labeling is prohibited by DIR-A001. Required fields are marked both visually and programmatically (`aria-required` or native `required`), never by color alone.
- **Announced state changes.** Loading, saving, save success, validation errors, pagination changes, and access denials are announced to assistive technology through a live region rather than communicated only by a visual change.
- **A reduced-motion path.** Any nonessential animation (the SSE pulse indicator, transitions, the Co-Intelligence streaming cursor) is reduced or removed when the user signals `prefers-reduced-motion: reduce`.

The author-facing surface is the **per-surface checklist** at the end of this document, run before a surface PR is opened, plus the axe-core gate that mechanizes the parts a machine can verify.

## Data model

This baseline introduces no Waaseyaa entity and stores no per-user accessibility state. It is a rendering and enforcement contract layered on surfaces that persist their own data through the framework entity system. That is deliberate: an accessibility floor that depended on a stored profile would fail closed for any user without one.

Where the baseline touches data, it touches metadata already carried by other surfaces, and only to render it accessibly:

- The classification label on an entity (the `classification_label` field from the framework classification engine) drives whether a field renders normally, renders as access-restricted (the admin SPA's `x-access-restricted` schema flag, shown disabled), or is announced as denied. The baseline consumes that signal; it does not own it.
- The translation strings that label every control come from the DIR-A003 `translation_string` pipeline (see Indigenous-language and translation). The accessible name of a control is therefore a translated value, not a hardcoded English string.

The one reduced-motion signal the baseline reads is the OS-level `prefers-reduced-motion` media query, read at render time in the browser. It is never written to an entity, never synced, never audited.

## Access and classification

Accessibility and OCAP intersect at the moment a surface tells a user they may not see something. DIR-A001 specifies how that moment is announced; DIR-A005 specifies that the decision itself always runs through the framework `AccessChecker` and `FieldAccessPolicyInterface`, never a surface-side shortcut. This baseline binds the two together.

- **Hard denials** (a server-side OCAP `Forbidden`: the viewing account or cross-Nation vantage may not read a nation-restricted field, or a `hold-*` label blocks read) are announced with `aria-live="assertive"`. The denial is a fact about authority, and the user is told immediately rather than left to infer it from an empty space.
- **Soft denials** (a capability not granted in this session, where re-auth or a different vantage could change the answer) are announced with `aria-live="polite"`, so they do not interrupt the user mid-task.
- The denial message itself carries no leaked content. It states that access is restricted and, where appropriate, who controls disclosure (for a nation-restricted field, the owning Nation). It never renders a redacted preview of the protected value, and the field's accessible name still conveys that a value exists but is withheld, so the absence is legible rather than silent.
- The three tiers map cleanly: public-tier fields render normally for all authenticated users; community-tier fields render for members of the owning Nation and announce a soft or hard denial to others per the policy result; nation-restricted fields are `Forbidden` on cross-Nation reads and announce a hard denial.

**Audit expectations.** The access decision behind a denial is already recorded by the framework OCAP audit log (the unified log DIR-A005 commits to spanning every Anokii surface). The accessibility layer adds nothing to the audit record and reads nothing from it; announcing a denial is a presentation concern and must not itself become a side-effecting write. Surfaces must not fabricate an "accessibility event" audit entry.

## Offline-first behavior

The accessibility baseline holds identically offline. The deep-teal contrast ratios, keyboard paths, focus order, and screen-reader semantics are properties of locally rendered markup and do not depend on the network. A user operating a surface in offline-degraded mode (DIR-A002) gets the same accessible experience as online; that is the point of an offline-first design constraint sitting alongside an accessibility design constraint.

Two offline states need an accessible announcement, and both reuse the live-region machinery above rather than inventing a new one:

- **Connectivity transitions** (going offline, coming back online) are announced politely so a screen-reader user knows whether their next action will sync immediately or queue.
- **Queued-write feedback.** When a governed form submission is held in the offline queue (the DIR-A002 multi-submission-merge default), the "saved locally, will sync" state is announced through the same polite live region used for online save confirmation. The user is never left guessing whether their submission took.

Access denials that are evaluated against locally cached policy while offline follow the same assertive/polite rules; on reconnect, the server-authoritative decision reconciles and, if it differs, the corrected state is re-announced. See the offline-first baseline (`offline-first.md`) for the sync engine and queue semantics this section references.

## Accessibility

This is the normative core: the baseline every Anokii surface meets.

### Keyboard

- Every interactive element is focusable and operable from the keyboard. Tab and Shift+Tab move between controls in a logical order; Enter and Space activate; arrow keys drive composite widgets (menus, the entity autocomplete combobox, radio groups, tab lists); Escape closes overlays and returns focus to the trigger.
- No keyboard trap. Focus can always leave a widget by keyboard alone (WCAG 2.1.2). Modal dialogs trap focus intentionally while open and release it on close, returning focus to the element that opened them.
- The skip link is the first focusable element and is visible on focus.
- Custom controls expose the keyboard interaction their ARIA role implies (a `role="combobox"` honors the combobox keyboard contract, a `role="tab"` honors arrow-key navigation).

### Contrast (against the deep-teal tokens)

The brand palette is Deep Teal: `#0d4f4f`, `#0f766e`, `#14b8a6`, exposed as CSS custom properties (`--color-primary` and siblings) by the framework admin shell. Anokii surfaces meet WCAG 2.1 AA contrast against these tokens:

- **Normal text:** at least 4.5:1 against its background. White (`#ffffff`) text on `--color-primary` at `#0d4f4f` or `#0f766e` clears this; white text on the lighter `#14b8a6` accent does not reliably clear 4.5:1, so `#14b8a6` is used for large text, borders, and non-text UI, not for body copy on a white-text button.
- **Large text** (18.66px bold or 24px regular and up): at least 3:1.
- **Non-text UI** (focus rings, input borders, icon-only controls, the SSE status dot, chart and chip boundaries): at least 3:1 against adjacent colors (WCAG 1.4.11).
- **Never color alone.** Required-field marking, validation errors, classification-tier chips, and status indicators pair color with text, an icon, or a shape so a user who does not perceive the color still gets the meaning.
- Per-Nation theme overrides ship their own contrast attestation (see Tier applicability); the contrast checks run against the resolved tokens, not only the default palette.

### Focus order and focus management

- DOM order matches visual reading order so the default tab sequence is logical without scattered `tabindex` values. Positive `tabindex` is prohibited.
- On route change in the SPA, focus moves to the new view's main heading (or the `#main-content` container) so a screen-reader user is not stranded on a stale control.
- Opening a dialog moves focus into it; closing returns focus to the trigger.
- Asynchronous content that replaces the user's context (a submitted form, a destructive confirmation) moves focus to the result or its status message rather than leaving focus on a control that no longer exists.

### Screen-reader semantics

- Native HTML elements first (`<button>`, `<a href>`, `<nav>`, `<main>`, `<table>`, `<label>`); ARIA only where a native element cannot express the pattern.
- Landmark roles frame every surface: `banner`, `navigation`, `main`, and `contentinfo` where present, inherited from the framework admin shell and not regressed.
- Every form control has a programmatically associated label; group related controls with `<fieldset>` and `<legend>` or an `aria-labelledby` group.
- Images and icons that carry meaning have a text alternative; purely decorative graphics are hidden from assistive technology (`aria-hidden="true"` or empty `alt`).
- A polite live region announces routine state (loading, saving, saved, pagination, queued-offline). The denial-specific assertive and polite rules from Access and classification layer on top.
- Headings form a correct outline (one `h1` per view, no skipped levels) so screen-reader users can navigate by heading.

### Motion (DIR-A001 plus WCAG 2.3.3)

- Honor `prefers-reduced-motion: reduce`: disable or substantially reduce nonessential animation (transitions, the SSE pulse, the streaming cursor described next).
- No content flashes more than three times per second (WCAG 2.3.1).
- No animation is the sole carrier of meaning; the reduced-motion path conveys the same information statically.

### DIR-A001 specifics beyond stock WCAG 2.1 AA

- **Access-denied live regions.** Hard denials use `aria-live="assertive"`; soft denials use `aria-live="polite"`. Defined normatively under Access and classification.
- **Co-Intelligence response surfaces.** A surface that streams a model response (built on the framework `StreamingProviderInterface` / `StreamChunk` path) manages focus and announces progressively. On submit, focus moves to the response region. Tokens stream into a live region whose updates are throttled, not announced per token, so a screen reader is not flooded; the region announces a "responding" state at the start and a "complete" state at the end. The streaming cursor animation respects reduced-motion. The user can interrupt and refocus the input by keyboard at any point.

## Indigenous-language and translation

Accessibility and the DIR-A003 Indigenous-language pipeline are bound at one specific point: **the accessible name of a control is a translated value.** A screen reader announces the `aria-label`, the visible label, the skip-link text, the live-region message, and the access-denied wording. Every one of those strings flows through the `translation_string` pipeline rather than being hardcoded, so when a surface is operated in Anishinaabemowin the assistive-technology experience is in Anishinaabemowin too, not a half-translated mix of English chrome around translated content.

Consequences for surface authors:

- No user-facing accessibility string is a string literal in component code. Skip-link text, ARIA labels, live-region announcements, and denial messages are translation keys resolved through the framework i18n composable and backed by `translation_string` entities.
- The language-keeper gate (DIR-A003) applies to these strings exactly as it applies to any other Anishinaabemowin text: no Anishinaabemowin accessibility copy enters the distribution without language-keeper review. An `aria-label` is content, not configuration, and is reviewed as content.
- Right-to-left is not a concern for the pilot languages (English and Anishinaabemowin, both left-to-right), but the baseline assumes locale-driven `lang` attributes on the document and on any element whose language differs from the page default, so a screen reader switches its pronunciation rules. A field showing an Anishinaabemowin term inside an otherwise-English view carries its own `lang="oj"` (or the Nation's resolved code) so it is not read with English phonetics.
- The pilot scope (Sagamok first, Sheguiandah second; English plus southern and northern Ojibwe) inherits this baseline unchanged. Dialect selection changes which `translation_string` rows resolve, not whether the accessible name is translated.

## Framework primitives used

- `waaseyaa/admin` (the Nuxt 3 admin SPA) for the inherited skip link, landmark roles, `.sr-only` utility, polite live region, and the deep-teal `--color-primary` token set this baseline measures against (framework spec `docs/specs/admin-spa.md`, charter DIR-007).
- `waaseyaa/access` (`AccessChecker`, `AccessResult`, `FieldAccessPolicyInterface`) for the OCAP decisions that the assertive/polite denial announcements render (framework specs `docs/specs/access-control.md`, `docs/specs/field-access.md`; charter DIR-A005).
- `waaseyaa/field` classification engine (`classification_label`, `ClassificationFieldAccessPolicy`) for the tier signal that drives normal / restricted / denied rendering (framework spec `docs/specs/classification-and-retention.md`).
- `waaseyaa/i18n` plus the SPA `useLanguage` composable for translated accessible names, backed by the Anokii DIR-A003 `translation_string` pipeline.
- `waaseyaa/ai-agent` `StreamingProviderInterface` / `StreamChunk` for the Co-Intelligence progressive-announcement contract (framework spec `docs/specs/ai-integration.md`).
- axe-core, Vitest, and Playwright as the enforcement mechanism named in charter DIR-A001 (the CI gate, per-component unit tests, and end-to-end keyboard and screen-reader path tests).

## Open questions

- **Theme-override attestation mechanics.** How does a per-Nation theme override (sovereign tier) declare and prove its contrast pass to the axe-core gate? An option is a generated fixture page that mounts every brand token combination so axe-core measures the resolved palette, but the wiring is unspecified.
- **Live-region throttle for Co-Intelligence streaming.** The exact throttle interval (announce-on-pause vs fixed cadence) that keeps a screen reader informed without flooding it is unvalidated and needs testing with real assistive technology.
- **Screen-reader test matrix.** Which combinations (NVDA plus Firefox, VoiceOver plus Safari, Orca plus a Chromium build on Linux) are in the Playwright-backed required set versus a manual pre-release pass is not yet decided. axe-core catches structural failures but not the lived screen-reader experience.
- **Denial-announcement wording across tiers.** A hard denial in shared-graph mode names the owning Nation as the discloser; the exact phrasing, and its translation through the language-keeper gate, is undrafted.
- **Reduced-motion default for high-motion data views.** Whether any future chart or map surface should default to reduced motion regardless of the OS signal (motion as a known vestibular risk) is open.
- **`lang` attribute resolution for mixed-dialect content.** When southern and northern Ojibwe appear in the same view, the precise BCP-47 subtags applied per element (and whether assistive technology honors them) needs verification against the resolved tenant language and dialect keys.
- **Charter-exception visibility.** Whether an active DIR-A001 `charter-exception` should surface a visible, announced banner on the affected surface (so users of assistive technology know a known gap exists) or stay an internal tracking record is unresolved.
