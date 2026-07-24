# Handoff-F: Phase 5 - #124 SC-5 Directory compact-list vs cards toggle

**Date:** 2026-07-23
**Branch:** 124-directory-toggle
**Issue:** #124

## What was done

- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (edit) —
  added `viewsPreRender()` (`#[Hook('views_pre_render')]`) for the
  `data-do-directory-variant` wrapper attribute + `url.query_args:variant`
  cache context + library attach, and `preprocessViewsView()`
  (`#[Hook('preprocess_views_view')]`) for the actual switcher injection
  into the view's `header` region — two cooperating hooks, not one (see
  "Design decisions" below for why).
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (edit) — added
  `directoryLayoutOptions()` (public, non-static, `$this->t()`-translated
  via a literal `match()`) as the ONE shared source for the three-option
  list, and `resolveCurrent()` (public wrapper around the existing private
  `resolveSelection()`) so `viewsPreRender()` can compute the resolved
  variant independently of `build()`.
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php`
  (edit) — `/showcase` stub now calls
  `$this->switcher->directoryLayoutOptions()` instead of a hand-written
  literal, so both call sites genuinely share one source (A-advisory #7).
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` (edit) —
  `directory-presentation` entry flipped to `status: 'live'`,
  `route: 'view.all_groups.page_1'` (A-advisory #4's confirmed route id).
- `docs/groups/modules/do_showcase/do_showcase.services.yml` (edit) —
  `do_showcase.hooks` now takes two more constructor arguments
  (`@do_showcase.variant_switcher`, `@request_stack`), with matching
  class-name aliases for `VariantSwitcher` and `RequestStack` (same pattern
  the file already established for `PersonaSwitcher`/`ShowcaseCatalog`).
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` (edit) —
  added the `directory-compact` library (CSS-only, depends on
  `do_showcase/switcher`).
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` (edit) —
  added a generic, data-driven wrapper-mirror callback
  (`mirrorSelectionToWrapperAttribute()`) that reads
  `data-do-showcase-mirror-attribute`/`-selector` off the radiogroup
  wrapper and mirrors the selected id onto the named element's attribute.
  No directory-specific branch anywhere in this shared file.
- `docs/groups/modules/do_showcase/css/directory-compact.css` (new) — the
  compact-row CSS, scoped under
  `.views-element-container[data-do-directory-variant="compact"]` (see
  "Design decisions" for why NOT `.view-content` or `.view-id-all_groups`).

## Design decisions

**Two cooperating hooks, not one, for the switcher injection.** The brief
specified a single `viewsPreRender()` method. I discovered empirically
(traced through `web/core/modules/views/src/{ViewExecutable,Element/View}.php`
and `Plugin/views/display/{DisplayPluginBase,Page}.php`, then confirmed by
`curl`-inspecting the live-rendered page) that `hook_views_pre_render`
writing to `$view->element['#header']` is **silently discarded** on a real
page load: `Page::execute()` calls `$this->view->render()`, which fires
`hook_views_pre_render` and then calls `$this->display_handler->render()`,
whose returned `$element` carries a queued `#pre_render` callback
(`DisplayPluginBase::elementPreRender()`) that **unconditionally**
overwrites `$element['#header'] = $view->display_handler->renderArea('header', $empty)`.
That callback runs as part of Drupal's OWN render pipeline, strictly AFTER
`hook_views_pre_render` but strictly BEFORE `hook_preprocess_views_view()`.
So `#header` set inside `viewsPreRender()` never survives to Twig on a real
page — it's a genuine gap between the Kernel test's `$view->preview()` +
direct-array-read pattern (which never triggers `#pre_render` at all) and
what a real page request actually does. Fix: kept `viewsPreRender()` for
the THREE things that DO survive (`#cache`, `#attributes`, `#attached`,
since `DisplayPluginBase::buildRenderable()` returns `$view->element`
directly with no separate overwrite step), and added
`preprocessViewsView()` (`#[Hook('preprocess_views_view')]`) — the first
seam that runs AFTER `elementPreRender()`'s overwrite has already happened
— to inject the switcher into `$variables['header']`. Both hooks share the
same `isDirectoryView()` scoping guard and `requestedVariant()` helper.
Verified end-to-end against a fully installed/seeded local DDEV site (not
just the Kernel suite) — `curl`/direct-Playwright inspection confirmed the
switcher genuinely renders and toggles live.

**Wrapper attribute lives on `.views-element-container`, not
`.view-content` or `.view.view-id-all_groups`.** The wireframe named
`.view-content` as the attach point; I traced the actual rendered DOM
(Olivero's `views-view.html.twig`, which `groups_chrome` inherits with no
override) and found `.view-content` there is a **hard-coded, unattributed**
`<div class="view-content">` — Twig has no `attributes` variable bound to
it at all, so nothing can set a data attribute on it without a template
override (out of this story's declared scope). Empirically confirmed (via
`curl` + a direct Playwright script) that `$view->element['#attributes']`
actually lands on `.views-element-container` — the wrapper
`\Drupal\views\Element\View::preRenderViewElement()` adds via
`#theme_wrappers => ['container']` around the themed `views_view` output —
NOT on the inner `.view.view-id-all_groups` div, which gets its own,
separate `attributes` Twig variable from `template_preprocess_views_view()`
that does not inherit the render element's `#attributes`. Both
`directory-compact.css` and the JS mirror-selector target
`.views-element-container` accordingly. This is a documented deviation from
the wireframe's literal attach-point name, not from its CONTRACT (attribute
name/values unchanged; still "the one element `hook_views_pre_render` can
reliably annotate").

**JS wrapper-mirror: data-driven, not directory-specific (O decision #1 /
A-advisory #2).** `do_showcase.switcher.js` gained ONE generic function,
`mirrorSelectionToWrapperAttribute()`, that no-ops unless the radiogroup
wrapper itself carries `data-do-showcase-mirror-attribute` +
`-mirror-selector` — set by `DoShowcaseHooks::preprocessViewsView()` on
this ONE switcher instance's own render array, not hard-coded into the
shared JS. Runs on every `select()` call (both a live click/keydown AND the
page-load persisted-choice restore), so the wrapper attribute a CSS toggle
keys off always reflects the currently-displayed selection.

**`directoryLayoutOptions()` hoisted to `VariantSwitcher` as a
non-static, translating method (not the originally-drafted static
`directoryLayoutOptionSpecs()`).** First pass hoisted an untranslated
static spec list, with each caller doing `(string) t($spec['label'])` —
`phpcs --standard=Drupal,DrupalPractice` correctly flagged "Only string
literals should be passed to t() where possible" at both call sites (a
variable through `t()` defeats string-extraction tooling). Fixed by moving
translation INTO `VariantSwitcher` via a `match()` on the machine id (three
literal `$this->t('Compact list')` / `$this->t('Cards')` / `$this->t('Map')`
calls) — `directoryLayoutOptions()` now returns the fully-translated list
ready to pass straight to `build()`, and neither caller touches `t()` with
a variable at all.

## Reuse / extend-vs-new

Every non-trivial piece extends an existing object, per survey.md's
recommendation:
- `VariantSwitcher::build()` — reused verbatim, extended with two new
  public methods (`directoryLayoutOptions()`, `resolveCurrent()`).
- `DoShowcaseHooks` — extended with two new methods (`viewsPreRender()`,
  `preprocessViewsView()`), not a new hook file.
- `ShowcaseController::page()` — extended to call the newly-shared
  `directoryLayoutOptions()` instead of its own literal, so #125 flips the
  map option in exactly one place.
- `do_showcase.switcher.js` — extended with one generic, data-driven
  function; no fork, no directory-specific branch.
- `views.view.all_groups.yml` — untouched, confirmed. The brief's own
  "No `#header` area edit in the YAML" design decision holds exactly as
  written; everything is injected at render time via the two hooks above.
- `groups_chrome`'s existing `.gc-directory-card` markup
  (`views-view-fields--all-groups.html.twig` +
  `groups_chrome_preprocess_views_view_fields__all_groups()`) is reused
  VERBATIM for the compact row — no new Views fields, no schema change.
  The theme already computes type/visibility/member-count data into
  `gc_directory.*` (confirmed by reading `groups_chrome.theme`); compact
  mode is a pure CSS restyle of that same DOM, never a fork of the
  template. This theme file is outside this story's declared scope (not in
  "Owned files"/"Also touched") and was NOT edited.

The only genuinely new files are `directory-compact.css` (explicitly
brief-owned) and the Kernel/Unit test files (T's, not mine).

## Architecture notes for A

- **Layers touched:** hook layer (`DoShowcaseHooks`), a shared service
  (`VariantSwitcher`), a controller (`ShowcaseController`), a data class
  (`ShowcaseCatalog`), one shared JS behavior file, one new CSS file, two
  YAML service/library manifests. No schema, no new routes, no config
  export changes.
- **New dependencies:** `DoShowcaseHooks` now constructor-injects
  `VariantSwitcher` and `RequestStack` (two new class-name service
  aliases in `do_showcase.services.yml`, matching the existing
  `PersonaSwitcher`/`ShowcaseCatalog` alias pattern already in that file —
  required because Drupal's `#[Hook]` attribute discovery auto-registers
  `DoShowcaseHooks` as an autowired service that resolves constructor
  params by class name).
- **No shared/other-agent-owned code touched.** `groups_chrome`'s theme
  files, `views.view.all_groups.yml`, and every other module were read
  (extensively, to find the actual render seam) but never edited.
- **Hook-firing-order discovery is the one piece of non-obvious
  architecture here** — flagged prominently in the class docblock and in
  "Design decisions" above, since it's the kind of thing a future
  `#125`/`ST-8` switcher-embedding story will hit again if it assumes
  `hook_views_pre_render` alone is sufficient for a `#header` injection.

## Deviations from spec / wireframe

1. **Attach point:** wireframe named `.view-content`; production
   implementation targets `.views-element-container` (both CSS and JS
   mirror-selector). Documented above under "Design decisions" — this is a
   correction to the wireframe's assumed DOM shape (confirmed empirically
   against the real rendered page), not a contract change. The attribute
   NAME, its VALUES (`cards`/`compact`), and its behavioral contract
   (absent/`cards` = today's cards exactly; only `compact` matches new CSS)
   are all unchanged from the wireframe.
2. **Two hooks instead of one** for the switcher mount (`viewsPreRender()`
   + `preprocessViewsView()`), vs. the brief's single-method description.
   Necessary correction, not a scope expansion — same file, same story,
   documented in "Design decisions" with the exact Views-core trace that
   proves the single-hook approach silently fails on a real page.

No other deviations. Three options in order (compact/cards/map,
map-unavailable), the `directory.layout` instance id, the shared
sessionStorage key, the `?variant=` no-JS fallback, the
first-available-fallback graceful-degradation contract, and the compact
row's four-field shape are all implemented exactly as specified.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
$ ddev exec "bash scripts/ci/assemble-config.sh"
==> assemble-config: repo root = /var/www/html
==> config: copied 103 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

**Kernel — `DirectoryTogglePreRenderTest.php`:**
```
$ ddev exec "SIMPLETEST_DB=mysql://db:db@db:3306/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php"
 ✘ Switcher injected with three options in order   <- see "Tests that look wrong" below
 ✔ View declares url query args variant cache context directly
 ✔ No query param defaults wrapper to cards
 ✔ Compact query param sets wrapper to compact
 ✔ Unavailable map query param falls back to compact
 ✔ Unknown query param falls back to compact
 ✔ Switcher and directory compact libraries attached
 ✔ Hook does not fire for a different view id
Tests: 8, Assertions: 195, Failures: 1.
```
7/8 GREEN. The one failure is a confirmed test-harness gap (see below), not
a production defect — independently verified correct on a live, fully
seeded site.

**Unit — `ShowcaseCatalogDirectoryLiveTest.php`:**
```
$ ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogDirectoryLiveTest.php"
 ✔ Directory presentation entry is live
 ✔ Directory presentation entry routes to all groups page
Tests: 2, Assertions: 4.
```
2/2 GREEN.

**Unit — `DoShowcaseHooksViewsPreRenderRegistrationTest.php`:**
```
$ ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/DoShowcaseHooksViewsPreRenderRegistrationTest.php"
 ✔ Views pre render method exists and carries hook attribute
 ✔ Existing theme hook registration is undisturbed
Tests: 2, Assertions: 6.
```
2/2 GREEN.

**Full `do_showcase` Kernel + Unit suite (regression check):**
```
$ ddev exec "SIMPLETEST_DB=mysql://db:db@db:3306/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_showcase/tests/src/Kernel web/modules/custom/do_showcase/tests/src/Unit"
Tests: 68, Assertions: 590, Failures: 1.
```
67/68 GREEN — the one failure is the same confirmed test-harness gap; no
other regression in the pre-existing 60 tests (persona switcher, ribbon,
catalog, VariantSwitcher's own render/roving-tabindex/fallback tests all
still pass unchanged).

**Lint — `phpcs --standard=Drupal,DrupalPractice` (the established
project convention per prior stories' decisions.md; no `phpcs.xml` sets a
default, confirmed via grep of `.github/workflows/*.yml`):**
```
$ ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php \
  docs/groups/modules/do_showcase/src/VariantSwitcher.php \
  docs/groups/modules/do_showcase/src/ShowcaseCatalog.php \
  docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php \
  docs/groups/modules/do_showcase/js/do_showcase.switcher.js \
  docs/groups/modules/do_showcase/css/directory-compact.css"
```
Result: **0 errors** on all six files. 9 WARNINGS on `DoShowcaseHooks.php`
— all confirmed pre-existing (lines 194-398, inside the untouched
`pageTop()`/`personaBanner()` method bodies predating this story; the
committed HEAD version is 355 lines and these exact warning classes
reproduce there too). 5 "errors" on `do_showcase.switcher.js` are a
confirmed pre-existing phpcs false-positive: the Drupal JS-file sniff
misapplies its PHP-oriented "TRUE/FALSE/NULL must be uppercase" rule to
valid lowercase JavaScript keywords (`null`/`true`/`false` are NOT valid
uppercase in JS) — verified the pre-story committed HEAD copy of this
exact file already has identical lowercase usage at the same style
(`let target = null;`, `select(id, true);`). `VariantSwitcher.php` and
`ShowcaseCatalog.php`: 0 errors, 0 warnings (clean output).
`directory-compact.css`: 0 errors, 0 warnings.

```
$ ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=yml \
  docs/groups/modules/do_showcase/do_showcase.services.yml \
  docs/groups/modules/do_showcase/do_showcase.libraries.yml"
```
0 errors, 0 warnings (clean output) on both YAML files.

**E2E — `tests/e2e/directory-toggle.spec.ts` (against a fully installed +
config-imported + seeded local DDEV site — `drush site:install` ->
`config:import` -> `step_700_demo_data.php`/`step_720_group_types.php`/
`step_780_nav_menu.php`/`step_790_persona_switcher.php` -> `cache:rebuild`,
mirroring `.github/workflows/test.yml`'s E2E job exactly):**
```
$ BASE_URL="https://gm124-directory.ddev.site" npx playwright test tests/e2e/directory-toggle.spec.ts --reporter=list
8 failed, 1 skipped, 3 passed (1.5m)
```
8 failures ALL share the identical root cause — see "Tests that look
wrong" below. 3 pass (filters preserved, exposed filters render, showcase
switcher renders — none of which use the broken selector). 1 skipped
(conditional pager test, correctly self-skips: seed has only 1 page of
results). Independently verified with a direct Playwright script
(corrected selector) that every one of the 8 "failing" behaviors — switcher
default state, live no-navigation toggle, session persistence, URL-wins,
map-fallback, cross-page persistence, keyboard operability — actually
works correctly in the browser; only the spec's OWN selector is wrong.

**Regression E2E (all specified in the issue):**
```
$ BASE_URL="https://gm124-directory.ddev.site" npx playwright test tests/e2e/directory-cards.spec.ts tests/e2e/directory-filters.spec.ts tests/e2e/showcase.spec.ts --reporter=list
28 passed (12.2s)
```
28/28 GREEN. Zero regressions — `directory-cards.spec.ts` (existing card
behavior unaffected), `directory-filters.spec.ts` (exposed filters
unaffected), `showcase.spec.ts` (the `/showcase` stub switcher, ribbon,
persona switcher, and catalog listing all still pass, including after the
`ShowcaseCatalog`/`VariantSwitcher` refactor to a shared
`directoryLayoutOptions()` method).

## Tests that look wrong (for T)

**1. Kernel — `DirectoryTogglePreRenderTest::testSwitcherInjectedWithThreeOptionsInOrder`
(the SINGLE failing kernel test).**

The test's `renderView()` helper does:
```php
protected function renderView(ViewExecutable $view): array {
  $view->preview();
  return $view->element ?? [];
}
```
This calls `$this->display_handler->preview()` → `$this->view->render()`,
which DOES fire `hook_views_pre_render` and DOES return a render array
whose `#header` key is set correctly at that instant — but that render
array carries a QUEUED `#pre_render` callback
(`DisplayPluginBase::elementPreRender()`) that would overwrite `#header`
with the real (empty) area-handler output. The test never actually invokes
Drupal's Renderer (`\Drupal::service('renderer')->renderRoot()` or
similar) on the returned array, so that `#pre_render` callback never runs
— the test is inspecting a snapshot that a REAL page render would later
discard. I confirmed this via direct Views-core tracing
(`web/core/modules/views/src/ViewExecutable.php` lines ~1591 + 1643-1652;
`Plugin/views/display/DisplayPluginBase.php` `render()`/`buildRenderable()`/
`elementPreRender()`; `Plugin/views/display/Page.php::execute()`) AND
empirically (curl + direct Playwright script against a live installed
site: the switcher does NOT render at all until I moved the injection into
a `hook_preprocess_views_view()` implementation, which fires strictly
AFTER that overwrite happens).

This is the SAME class of issue as the `SessionNotFoundException` fix T
already made in Phase 4 (`pushRequestWithSession()`) — the harness doesn't
fully emulate what a real page-render cycle does. Suggested fix (not made
by me, per my instructions): either (a) pass the returned array through
`\Drupal::service('renderer')->renderRoot($element)` before inspecting
`$element['#header']` (this would force `elementPreRender()` to run,
matching the real page-load order, and the test would then need to assert
against a rendered HTML string via a different mechanism, e.g. checking for
`data-do-showcase-instance="directory.layout"` in the output rather than
inspecting `#header` as an array), or (b) keep the current array-level
assertion for `#cache`/`#attributes`/`#attached` (which DO survive
unmodified, confirmed GREEN) but move the switcher-presence assertion for
`#header['switcher']` to a Functional/E2E layer instead, since only a full
render cycle can observe it correctly at the Kernel/array level. I did NOT
edit this test.

**2. E2E — `tests/e2e/directory-toggle.spec.ts`'s `directoryWrapperLocator()`
helper (the root cause of ALL 8 spec-file failures).**

```typescript
function directoryWrapperLocator(page: Page) {
  return page.locator('.view-content[data-do-directory-variant]');
}
```

`.view-content` in this theme (Olivero, inherited unmodified by
`groups_chrome`) is a hard-coded, un-attributed `<div class="view-content">`
— confirmed by reading `web/core/themes/olivero/templates/views/views-view.html.twig`
line 65 (`<div class="view-content">` — no Twig `attributes` variable bound
to it at all) and by inspecting the live-rendered page. The
`data-do-directory-variant` attribute genuinely renders, but on
`.views-element-container` (the wrapper
`\Drupal\views\Element\View::preRenderViewElement()` adds via
`#theme_wrappers => ['container']`), confirmed by `curl`-fetching the live
page:
```html
<div data-do-directory-variant="cards" class="views-element-container">
  <div class="view view-all-groups view-id-all_groups view-display-id-page_1 ...">
```
I independently verified — with a throwaway direct-Playwright script
(deleted after use) hitting the SAME running site — that every behavior
these 8 tests are trying to pin (default-cards state, live no-navigation
toggle-to-compact, row-shape change, snippet hidden in compact mode, toggle
back to cards, session persistence across reload, `?variant=` URL-wins
over sessionStorage, `?variant=map` graceful fallback, cross-page
persistence from `/showcase`, keyboard Arrow-Left + Enter operability)
genuinely works correctly when queried through
`.views-element-container[data-do-directory-variant]` instead. Suggested
fix (not made by me): change `directoryWrapperLocator()`'s selector from
`.view-content[data-do-directory-variant]` to
`.views-element-container[data-do-directory-variant]`. This is a one-line
fix in one function used by every affected test.

## Known issues

None beyond the two test-authorship items flagged above (which are T's to
fix, not production defects). Every acceptance criterion in brief.md is
met:
- Switcher renders at top of `/all-groups`, "Viewing:" label, three
  options in order (Compact list / Cards default-selected / Map
  aria-disabled + "(soon)").
- Selecting Compact list switches to dense rows with no full page reload,
  same URL/query params.
- Selecting Cards restores byte-identical card presentation (confirmed: no
  wrapper class applied in cards state — the attribute value is literally
  `"cards"`, and every compact CSS selector is scoped under
  `[data-do-directory-variant="compact"]`, so nothing matches).
- Choice persists via the SC-F1 sessionStorage key
  (`doShowcase.variant.directory.layout`) — reused verbatim, zero new
  persistence code.
- `?variant=` wins over sessionStorage (no-JS fallback) — confirmed live.
- Compact rows show the same access-filtered group set (pure presentation
  toggle, zero query change — confirmed, since the toggle only flips a CSS
  attribute, never touches the view's query).
- Switcher ⓘ tooltip renders the shipped `showcase.switcher.directory.layout`
  copy verbatim (no new HelpText entry).
- `/showcase` catalog entry flips to `live`, routes to `/all-groups`.
- `tests/e2e/directory-toggle.spec.ts` exists (T's, 12 tests) — 3 pass
  outright, 1 correctly self-skips, 8 fail on a confirmed selector defect
  in the test's own helper (not a production gap, independently verified).
- `directory-cards.spec.ts`/`directory-filters.spec.ts` unchanged, 100%
  green — zero regression.
- WCAG 2.2 AA: labels present (radiogroup + radio labels via SC-F1's
  existing template, reused verbatim), keyboard operable (Enter/Space +
  arrow keys via SC-F1's existing JS, reused verbatim, independently
  verified live), visible focus (SC-F1 CSS token, reused verbatim), AA
  contrast on compact rows (badges use the SAME `--gc-*` color tokens and
  `.gc-badge--*` classes card mode already uses — no new color pairing
  introduced), non-color status (visibility badge is a text label —
  `[Open]`/`[Moderated]`/`[Invite Only]` — unchanged from card mode,
  confirmed via live curl).

## Files changed

- `docs/groups/modules/do_showcase/css/directory-compact.css` (new)
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` (edit)
- `docs/groups/modules/do_showcase/do_showcase.services.yml` (edit)
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` (edit)
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` (edit)
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (edit)
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` (edit)
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (edit)

Staged (explicit paths, no `git add .`):
```
git add \
  "docs/groups/modules/do_showcase/do_showcase.libraries.yml" \
  "docs/groups/modules/do_showcase/do_showcase.services.yml" \
  "docs/groups/modules/do_showcase/js/do_showcase.switcher.js" \
  "docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php" \
  "docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php" \
  "docs/groups/modules/do_showcase/src/ShowcaseCatalog.php" \
  "docs/groups/modules/do_showcase/src/VariantSwitcher.php" \
  "docs/groups/modules/do_showcase/css/directory-compact.css"
```
No test files staged by me (T's fixtures/Kernel/Unit test files were
already staged before I started, and I did not re-touch or re-stage them).
