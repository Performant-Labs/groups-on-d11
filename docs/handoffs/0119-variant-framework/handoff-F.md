# Handoff-F: Phase 5 - SC-F1 Variant framework (switcher, /showcase, POC ribbon)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119

## What was done

New `do_showcase` module (sole new module — `docs/groups/modules/do_showcase/**`, per
Operating rules) plus one append-only edit to `\Drupal\do_chrome\HelpText`:

- `do_showcase.info.yml` — module definition, no dependencies.
- `do_showcase.module` — doc-comment-only file, hooks live in `src/Hook/DoShowcaseHooks.php`.
- `do_showcase.services.yml` — `do_showcase.variant_switcher` (VariantSwitcher, `autowire: false`,
  translation service injected via `calls:`) and `do_showcase.hooks` (DoShowcaseHooks, tagged
  `hook_implementations`), mirroring `do_chrome.services.yml`'s one-service-per-surface shape.
- `do_showcase.routing.yml` — `do_showcase.showcase` route, `GET /showcase`,
  `_permission: 'access content'` (public, matches `do_discovery`'s public-page precedent).
- `do_showcase.libraries.yml` — `switcher` and `ribbon` libraries (locally-authored JS/CSS, no CDN,
  matching the epic #78 house style); neither adds a new tooltip engine.
- `src/VariantSwitcher.php` — the `do_showcase.variant_switcher` service.
  `build(string $instance_id, array $options, string $current): array` — the stable render-array
  contract SC-4/5/6/ST-8 will call (Acceptance criterion #2).
- `src/ShowcaseCatalog.php` — the code-constant catalog (`entries()`, `personas()`), same shape as
  `\Drupal\do_chrome\PermissionMatrix` (StringTranslationTrait, no DI, `t()`-wrapped strings).
- `src/Controller/ShowcaseController.php` — `/showcase` page controller (`ControllerBase` +
  `create(ContainerInterface)`, DI'd `do_showcase.variant_switcher` service + a fresh
  `ShowcaseCatalog`), following `do_notifications`/`do_discovery`'s pattern.
- `src/Hook/DoShowcaseHooks.php` — `#[Hook('theme')]` (registers the switcher's Twig template) and
  `#[Hook('page_top')]` (injects the site-wide POC ribbon — visible markup, so `page_top` is used
  instead of `page_attachments`, which only carries library/settings attachments).
- `templates/do-showcase-variant-switcher.html.twig` — presentation-only template for the
  switcher's `role="radiogroup"` markup, mirroring `do-chrome-permission-matrix.html.twig`'s
  data-in/markup-out shape.
- `js/do_showcase.switcher.js` — click/keyboard selection + client-side (localStorage) persistence,
  keyed per `instance_id`; a `?variant=` query param on the URL always wins over a stored choice.
- `js/do_showcase.ribbon.js` — dismiss-button removal + client-side (localStorage) dismissal
  persistence; hides the ribbon immediately on load if already dismissed.
- `css/do_showcase.css` — fixed-top ribbon, switcher/radio visual states, visible focus rings.
- `docs/groups/modules/do_chrome/src/HelpText.php` — **appended** two new keys (append-only, no
  existing key edited): `showcase.switcher.directory.layout` (the stub switcher's ⓘ copy) and
  `showcase.ribbon` (the ribbon's ⓘ copy). Existing keys unchanged (verified — see Tier 1 below).

## Design decisions

- **Theme-hook render pattern for the switcher.** `VariantSwitcher::build()` returns
  `#theme => 'do_showcase_variant_switcher'` (a registered Twig template) rather than a raw
  `container` of nested render elements, so the roving-tabindex/ARIA markup is fully controlled
  presentation, matching `PermissionMatrixPanel`'s `#theme => 'do_chrome_permission_matrix'`
  pattern. The render array ALSO keeps `#attributes` (a copy of `#wrapper_attributes`) so the
  PHPUnit contract test (`testBuildReturnsLabeledControlGroupKeyedByInstanceId`) can assert the
  role/aria-label without a full render cycle — this is the "stable render-array contract" the
  brief's Acceptance criterion #2 asks for.
- **`page_top`, not `page_attachments`, for the ribbon.** `page_attachments` can only carry
  `#attached` (libraries/settings/JS), not visible render content. The ribbon is a real
  `<button>`/`<a>`, so `hook_page_top` is used — still the same "single global attach point" shape
  `DoChromeHooks::pageAttachments()` established, just the correct hook for visible chrome.
- **No-JS fallback wins over persisted choice.** The switcher JS reads `?variant=` from the URL
  first; only if absent does it apply a stored (localStorage) choice. This keeps a direct/shared
  link to `?variant=map` authoritative and prevents a stale stored choice from silently overriding
  an explicit query param — not specified verbatim in the wireframe, but implied by "no-JS ?variant=
  query param selects the right option" needing to work even when a prior visit stored something
  else.
- **`ShowcaseCatalog` and `VariantSwitcher` are separate classes**, both plain/no-DI in the same
  shape as `PermissionMatrix` — `ShowcaseCatalog` is pure data (mirrors `PermissionMatrix` exactly,
  instantiated with `new` in the controller, not a service), while `VariantSwitcher` IS registered
  as a service (`do_showcase.variant_switcher`) because the brief's Acceptance criterion #2 and the
  forward-compat table explicitly name it as a service other stories will inject/call.

## Service-vs-Block rationale (A-plan action item, one sentence)

`VariantSwitcher` is a plain SERVICE (`do_showcase.variant_switcher`), not a Block plugin, because
the repo's only existing embeddable-render-surface precedent (`GroupMissionBlock`,
`ContributionStatsBlock`) is context-derived (the group comes from block placement/route context),
while the switcher's callers (SC-4/SC-5/SC-6/ST-8, and this story's own `ShowcaseController`) always
supply explicit `instance_id`/`options`/`current` parameters and call it inline from a
controller/template rather than from a placed block region — so SC-4/5/6/ST-8 can call it directly
without needing a block-placement config entity.

## Reuse / extend-vs-new

- **New module `do_showcase`** — per the brief's Reuse map and survey.md's independent
  corroboration (issue's own stated recommendation). Not re-litigated here; A's Phase-3 plan review
  already PASSed this.
- **Extended (append-only) `\Drupal\do_chrome\HelpText`** — added exactly two new keys
  (`showcase.switcher.directory.layout`, `showcase.ribbon`), did not touch any existing key. Verified
  by running `do_chrome`'s own `HelpTextTest.php` after the append — still 100% green (see Tier 1).
- **Reused `do_chrome/tooltips` library** — attached wherever the switcher/ribbon render
  (`ShowcaseController::page()` and `DoShowcaseHooks::pageTop()`); `do_showcase` did not vendor a
  second tooltip engine or write new tippy-init JS. `do_showcase.switcher.js` and
  `do_showcase.ribbon.js` own ONLY selection/dismiss + persistence, never tooltip init.
- **Followed `do_notifications`/`do_discovery`'s `ControllerBase` + `.routing.yml` pattern** for
  `/showcase` — no new controller/routing convention introduced.

## Architecture notes for A

- **Layers touched:** routing (new), services (new — 1 stateless data class instantiated directly +
  1 registered service + 1 hook-tagged service), a new `#[Hook('theme')]` template registration, a
  new `#[Hook('page_top')]` (visible global chrome), 2 new libraries (JS+CSS), 1 new Twig template.
  No config schema/entity changes, no permissions.yml (public route only), no database/state usage.
- **New services:** `do_showcase.variant_switcher` (VariantSwitcher), `do_showcase.hooks`
  (DoShowcaseHooks).
- **New route:** `do_showcase.showcase` → `GET /showcase`, `_permission: 'access content'`.
- **Shared code changed:** `do_chrome/src/HelpText.php` — two new array entries appended at the end
  of `HelpText::all()`'s return array; nothing else in that file touched. This is the one
  cross-story-owned file this story is permitted to append to (per survey.md's disjoint-ownership
  note — SC-2/SD-1/SD-2 also append elsewhere in the same file, on different keys).
- **No `\Drupal::` service location** anywhere in `do_showcase`'s production code — `ShowcaseController`
  uses constructor DI (`create(ContainerInterface)`); `DoShowcaseHooks::pageTop()` uses the
  procedural `t()` helper and `Url::fromRoute()`, matching `ArchivePinHooks.php`'s existing
  in-house pattern for hook classes (confirmed against that file — same phpcs warning shape, not a
  deviation).
- **Client-side-only persistence** (Brief-gate B-2) — no `tempstore.private`, no session write
  anywhere in `do_showcase`. Confirmed by inspection: no `\Drupal::service('tempstore.private')` or
  `session()` call in any new file.

## Deviations from spec / wireframe

- **`survey.md`'s Reuse map still says "EXTEND Drupal core's `tempstore.private` service"** — this
  is the pre-existing stale line A's Phase-3 review already flagged (finding #1, warn, non-blocking,
  O's to fix in survey.md). I implemented against the CORRECTED brief.md/wireframe.md persistence
  decision (client-side cookie/localStorage), not the stale survey.md line. No action needed from
  me; flagging again here only so the Phase-7 anti-dup reviewer doesn't get confused by the two
  documents disagreeing.
- Everything else matches the approved wireframe as written (labeled radiogroup, non-color `●`
  selection cue, `aria-disabled` + `tabindex="-1"` + "(soon)" for the unavailable option, `?variant=`
  no-JS fallback, ribbon fixed-top real `<button>`, `/showcase` `[ live ]`/`[ coming ]` text badges,
  all seven catalog entries, four named personas).

## Tier 1 self-check (incl. tests now GREEN)

**PHPUnit — all 23 new test methods GREEN, plus do_chrome's own 14 pre-existing tests confirmed
non-regressed (37/37 green in one run):**

```
cd <worktree>
cp -R <shared-checkout>/web/core web/core   # full copy, not a symlink (see note below)
mkdir -p web/modules/custom
cp -R docs/groups/modules/do_showcase web/modules/custom/
cp -R docs/groups/modules/do_chrome web/modules/custom/
ln -s <shared-checkout>/vendor vendor
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/PermissionMatrixTest.php
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
.....................................                             37 / 37 (100%)
Time: 00:00.039, Memory: 12.00 MB
OK, but there were issues!
Tests: 37, Assertions: 268, PHPUnit Deprecations: 38.
```
(The "issues" are PHPUnit's own framework deprecation notices, not test failures — 0 failures,
0 errors, 37/37 passed.)

**Note on the invocation:** Drupal core's `tests/bootstrap.php` resolves `bootstrap="tests/bootstrap.php"`
relative to the **realpath** of `phpunit.xml.dist`'s directory. A symlinked `web/core` therefore
silently redirects PHPUnit back to the SHARED checkout's `web/core` (and its non-existent
`web/modules/custom`), which produced misleading `Class ... not found` errors during my first
verification attempt. A full copy of `web/core` (not a symlink) into the worktree fixes this. I
tore down all scratch copies (`web/core`, `web/modules/custom`, `vendor` symlink,
`.deprecation-ignore.txt`) after verifying — `git status` in the worktree is clean of anything but
my intended production files (confirmed below).

**phpcs (Drupal + DrupalPractice) on every new/changed production PHP file:**
```
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php \
  docs/groups/modules/do_showcase/src/ \
  docs/groups/modules/do_showcase/do_showcase.module
```
- `src/Controller/ShowcaseController.php` — 0 errors, 0 warnings.
- `src/VariantSwitcher.php` — 0 errors, 0 warnings.
- `src/ShowcaseCatalog.php` — 0 errors, 0 warnings.
- `src/Hook/DoShowcaseHooks.php` — 0 errors, 3 warnings ("t() calls should be avoided in classes,
  use StringTranslationTrait"). Matches the exact pre-existing pattern in `do_chrome`'s own
  `ArchivePinHooks.php` (same warning, same shape) — confirmed by running phpcs against that file
  too; not a new house-style violation, an accepted existing pattern for `#[Hook]` classes that
  render simple markup.
- `do_chrome/src/HelpText.php` (the appended file) — not re-run standalone; the append only adds
  two array entries in the same shape as every existing entry, no new phpcs surface.

**phpstan (level 1) on the new files + do_chrome (for symbol resolution):**
```
php vendor/bin/phpstan analyse --level 1 --no-progress \
  docs/groups/modules/do_showcase/src \
  docs/groups/modules/do_showcase/do_showcase.module \
  docs/groups/modules/do_chrome/src
```
- 1 finding: `new.static` ("Unsafe usage of new static()") on `ShowcaseController::create()`.
  Confirmed this is the exact same finding phpstan (with the repo's installed phpstan-drupal rules)
  raises against the PRE-EXISTING `NotificationSettingsController::create()` — i.e. every
  `ControllerBase::create(ContainerInterface)` implementation in this codebase gets this finding; it
  is not a defect introduced here, and no phpstan.neon/baseline exists in this repo to suppress it
  project-wide. No `@phpstan-ignore` added (per house rule) — left as the accepted repo-wide
  baseline shape.
- 0 other findings.

**Module install/enable:** Not verified against a live DDEV/Drupal site in this environment (no
groups-on-d11 DDEV site was running here, and this environment is explicitly scoped to a read-only
reference against the shared vendor). Verified instead: all PHP files pass `php -l` (syntax-clean),
all `.yml` files parse as valid YAML, both new JS files pass `node --check`, and the PHPUnit run
above proves the module's classes autoload correctly under Drupal's own module-discovery bootstrap
(the same mechanism `drush en` relies on for PSR-4 registration). Full install/enable-clean +
`npx playwright test tests/e2e/showcase.spec.ts` verification against the namespaced throwaway-DB
Docker is T-GREEN's job (Phase 6), per the brief's own Acceptance criterion.

**Git status of the worktree after verification** (only intended production files touched):
```
 M docs/groups/modules/do_chrome/src/HelpText.php
?? docs/groups/modules/do_showcase/css/
?? docs/groups/modules/do_showcase/do_showcase.info.yml
?? docs/groups/modules/do_showcase/do_showcase.libraries.yml
?? docs/groups/modules/do_showcase/do_showcase.module
?? docs/groups/modules/do_showcase/js/
?? docs/groups/modules/do_showcase/src/
?? docs/groups/modules/do_showcase/templates/
?? docs/groups/modules/do_showcase/do_showcase.routing.yml
?? docs/groups/modules/do_showcase/do_showcase.services.yml
```
(No test files are staged/modified by me — `docs/groups/modules/do_showcase/tests/**` and
`tests/e2e/showcase.spec.ts` are T's, untouched.)

## DOM contract for showcase.spec.ts (T-GREEN reference)

- Switcher wrapper: `<div role="radiogroup" aria-label="Viewing" data-do-showcase-instance="...">`
  containing a label span, N `<a role="radio" aria-checked="true|false" [aria-disabled="true"]
  tabindex="0|-1" data-do-showcase-id="..." href="?variant=<id>">` elements, and (if HelpText copy
  is non-empty) a trailing `<span class="do-showcase-info" tabindex="0" role="note"
  data-do-tooltip="...">ⓘ</span>`. Each option's visible text is `"● <label>"` when selected,
  plain `"<label>"` (or `"<label> (soon)"` if unavailable) otherwise — satisfying "selected text
  differs from the bare label" and the "(soon)" truthful-copy assertions.
- Ribbon: `<div id="do-showcase-ribbon" data-do-showcase-ribbon="true">` containing
  `<span>This is a proof-of-concept demo.</span>`, `<a href="/showcase">See what it compares →</a>`,
  and `<button type="button" aria-label="Dismiss demo banner" data-do-showcase-dismiss="true">✕</button>`.
  Rendered via `hook_page_top` — before the page region, never inside `#block-groups-chrome-main-menu`.
- `/showcase`: `<h1>` (from the route's `_title`), an intro `<p>`, the stub switcher instance
  (`directory.layout` — Compact list / Cards / Map, Map unavailable), then one
  `<div data-do-showcase-entry="<id>">` per catalog entry with an `<h3>` title, a
  `<span class="do-showcase-status-badge">[ live ]</span>`/`[ coming ]`, a decision-sentence `<p>`,
  a "View this comparison" link ONLY when `live`, and (for `persona-switcher` only) a nested
  `<ul>`/`<li>` list naming all four personas.

## Tests that look wrong (for T)

None. All 23 authored PHPUnit test methods and all 15 Playwright cases were implemented against
exactly as written — no test appeared to assert an incorrect contract. (One PHPUnit assertion
worth calling out for awareness, not correction:
`ShowcaseCatalogTest::testPrivateGroupRevealEntryReferencesIssue134` concatenates
`decision_sentence . title` and checks for the substring `'134'` — my `decision_sentence` for that
entry contains the literal text `(#134)`, which satisfies the assertion. No change needed.)

## Known issues

None against the acceptance criteria as specified. Two things flagged for awareness, not blocking:
1. Live install/enable + Playwright verification happens at T-GREEN (Phase 6) against the
   namespaced Docker, as scoped — not run in this environment (see Tier 1 notes above for why, and
   what was verified instead).
2. `survey.md`'s Reuse map still contains the pre-B-2 `tempstore.private` line (A's Phase-3 finding
   #1, non-blocking, O's doc-hygiene fix) — I implemented against the corrected brief.md decision,
   flagged again here for visibility at the Phase-7 gate.

## Files changed

- `docs/groups/modules/do_showcase/do_showcase.info.yml` (new)
- `docs/groups/modules/do_showcase/do_showcase.module` (new)
- `docs/groups/modules/do_showcase/do_showcase.services.yml` (new)
- `docs/groups/modules/do_showcase/do_showcase.routing.yml` (new)
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` (new)
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (new)
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` (new)
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` (new)
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (new)
- `docs/groups/modules/do_showcase/templates/do-showcase-variant-switcher.html.twig` (new)
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` (new)
- `docs/groups/modules/do_showcase/js/do_showcase.ribbon.js` (new)
- `docs/groups/modules/do_showcase/css/do_showcase.css` (new)
- `docs/groups/modules/do_chrome/src/HelpText.php` (modified — append-only, 2 new keys)
