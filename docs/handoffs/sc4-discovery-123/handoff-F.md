# Handoff-F: Phase 5 - #123 SC-4 Discovery three ways

**Date:** 2026-07-23
**Branch:** 123-discovery-three-ways
**Issue:** #123

## What was done

- **`docs/groups/modules/do_showcase/src/VariantSwitcher.php`** — extended `build()` with an
  optional 4th param `string $query_key = 'variant'` (BC-safe default). Every option's no-JS
  fallback `href` now reads `?<query_key>=<id>` instead of the hardcoded `?variant=<id>`, and the
  render array's `#cache['contexts']` now bubbles `url.query_args:<query_key>` instead of a
  hardcoded `url.query_args:variant`. Docblock updated to document the new parameter and the
  cache-context bubble (per handoff-A-plan.md Risk 1 + Spot-check finding #1).
- **`docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php`** — added the
  "Discovery ranking" section: a private `DISCOVERY_OPTIONS` const mapping
  `recent/hot/promoted` ids to their real view ids (`activity_stream`/`hot_content`/
  `promoted_content`); a new private `embedDiscoveryView()` method that guards
  `views_embed_view($view_id, 'default')` behind a `moduleExists('views')` check and normalizes
  its NULL-on-miss return into an empty render array; and a new render subtree
  (`$build['discovery_ranking']`) hosting an `<h2>`, a decision-framing `<p>`, a second
  `discovery.ranking` `VariantSwitcher` instance (keyed on `discovery`), and the resolved
  embedded view. Added `url.query_args:discovery` to the page's `#cache['contexts']` alongside
  the existing `url.query_args:variant`. Region wrapper carries `data-do-discovery-ranking`.
- **`docs/groups/modules/do_chrome/src/HelpText.php`** — appended the
  `showcase.switcher.discovery.ranking` key (single wrapper tooltip, per A's Risk 2 resolution),
  placed in the existing `showcase.switcher.*` block immediately after
  `showcase.switcher.directory.layout`. Append-only; no existing key touched.
- **`docs/groups/modules/do_showcase/css/discovery-compare.css`** (new) — section/typography-only
  styling for `.do-showcase-discovery-ranking`, scoped strictly to the new container and its
  embedded-view row/meta-line typography; deliberately does not redefine
  `[role="radio"]:focus-visible` (reuses `do_showcase.css`'s existing rule verbatim, per
  wireframe.md's WCAG note). This file (and its library registration below) were already present
  on disk at session start — verified correct against the wireframe/brief and left as-is (see
  Deviations below).
- **`docs/groups/modules/do_showcase/do_showcase.libraries.yml`** — added the `discovery-compare`
  library entry (CSS-only, `do_showcase/switcher` dependency), attached from
  `ShowcaseController::page()`'s `discovery_ranking` subtree. Also already present at session
  start (see Deviations below).
- **`docs/groups/modules/do_showcase/js/do_showcase.switcher.js`** — already contained A's
  Spot-check finding #2 fix at session start (a `queryKeyForGroup()` helper that reads the query
  key generically off an option's own `href` rather than hardcoding `params.has('variant')`).
  Verified correct against my `VariantSwitcher::build()` contract (confirmed the `?<key>=<id>`
  href shape parses correctly via `URLSearchParams`) and left as-is — no changes needed.

## Design decisions

- **`ModuleHandlerInterface` via the inherited `ControllerBase::moduleHandler()` accessor, not
  constructor injection.** My first attempt constructor-promoted a `private readonly
  ModuleHandlerInterface $moduleHandler` — this PHP-fatals ("Cannot redeclare non-readonly
  property ... as readonly") because `ControllerBase` already declares its own non-readonly
  `protected $moduleHandler` property backing its lazy `moduleHandler()` method. Fixed by calling
  `$this->moduleHandler()` directly in `embedDiscoveryView()` instead — smaller diff, no
  constructor/`create()` signature change, and it is the idiomatic `ControllerBase` pattern this
  codebase already uses elsewhere (`routeExists()` reads `$this->routeProvider` the same way).
  Caught via a live Functional-test crash (`error.log`: "Test was run in child process and ended
  unexpectedly" resolved to the exact fatal via `web/sites/simpletest/*/error.log`), not by static
  analysis — recorded as a real defect I introduced and fixed, not something latent.
- **Discovery options kept local to `ShowcaseController`, not hoisted to a new
  `VariantSwitcher::discoveryRankingOptions()` method.** A's plan flagged this as optional
  ("idiomatic given SC-5's precedent," not mandatory). `directoryLayoutOptions()` earns its home on
  `VariantSwitcher` because it has TWO call sites (`ShowcaseController` + `DoShowcaseHooks`) that
  must never drift. The discovery.ranking option set has exactly ONE call site today
  (`ShowcaseController::page()`); centralizing it now would be speculative surface for a
  consumer that doesn't exist, contrary to the "no gold-plating" instruction. If a second
  discovery-ranking consumer appears in a future story, hoisting then is a one-method, low-risk
  follow-up.
- **`DISCOVERY_OPTIONS` id-to-view-id map as a private class const, resolved via
  `resolveCurrent()` + a linear `foreach`, not a `match()`.** Kept the const as a simple array
  (not translated labels — those live separately in `$discovery_options` inside `page()`) so the
  view-id mapping and the switcher's translated option list stay two disjoint concerns that
  can't accidentally leak translation-wrapped strings into the view-id lookup.
- **Unknown `?discovery=` fallback relies entirely on `VariantSwitcher::resolveSelection()`'s
  existing first-available-option rule** (Recent listed first) rather than a second, hand-written
  fallback check in the controller — matches the exact pattern `DoShowcaseHooks::
  viewsPreRender()` already established for `directory.layout` (call `resolveCurrent()` to decide
  what to do with the OUTER render array, let `build()` privately re-derive the identical rule for
  the OPTIONS array). Avoids a second copy of "what does an unknown id resolve to" that could
  silently drift from `build()`'s own rule.

## Reuse / extend-vs-new

- **Extended** `VariantSwitcher::build()` (the SC-F1 #119 primitive) with the 4th `$query_key`
  param — per A-plan Risk 1, BC-safe, no parallel switcher-building path created.
- **Extended** `ShowcaseController::page()` — added a second render subtree to the SAME
  controller/route rather than a new controller or route.
- **Reused the three EXISTING views AS-IS** via `views_embed_view()` — per A-plan Risk 3, no
  `views.view.discovery_compare.yml` was created (that file is explicitly absent from this diff;
  confirmed not present anywhere in the worktree).
- **Appended** to the existing `do_chrome\HelpText::all()` array — no parallel copy store.
- No new object was created where the brief's Reuse map called for an extension. The only NEW
  files are `css/discovery-compare.css` (explicitly named "new" in the brief's Owned-files list)
  and T's test files (not mine).

## Architecture notes for A

- **Layers touched:** Controller (`ShowcaseController`), a shared service
  (`VariantSwitcher`), and a shared copy store (`HelpText`) — no schema, no new config entity, no
  new route.
- **New dependency:** `embedDiscoveryView()` calls the core procedural `views_embed_view()`
  function, which Drupal 11.4 marks `@deprecated` (in favor of `'#type' => 'view'` render
  elements, removed in Drupal 13) but does not yet remove. This is per A-plan's explicit
  directive to use `views_embed_view()` literally, not a choice I made independently — flagging
  for A/O visibility since a future Drupal-13 migration story will need to revisit this one call
  site (and any other `views_embed_view()` caller in the codebase, not scoped to this story).
- **Cache-context surface:** `ShowcaseController::page()`'s top-level `#cache['contexts']` now
  carries both `url.query_args:variant` and `url.query_args:discovery`; `VariantSwitcher::build()`
  bubbles the correct one per-instance automatically now that `$query_key` is honored in the
  cache-context line too — both callers (this controller's two instances) get correct behavior
  from the ONE seam-level fix, matching A's Spot-check finding #1's intent.
- **No shared/other-agent-owned code was refactored.** `DoShowcaseHooks.php` (a different SC-5
  story's file, sharing `VariantSwitcher`'s BC-safe 3-arg call form) was read to confirm
  non-regression but not edited — its Kernel test suite (`DirectoryTogglePreRenderTest`, 8 tests)
  re-ran GREEN unchanged.

## Deviations from spec / wireframe

- **Two production artifacts (`css/discovery-compare.css` and its
  `do_showcase.libraries.yml` entry) plus the `do_showcase.switcher.js` fix were already present
  on disk, untracked/uncommitted, at the start of my session** — before I wrote any code. I did
  not author these; I read, verified, and now deliver them as-is because on inspection they are
  each already correct against the brief/wireframe/A-plan:
  - `discovery-compare.css` matches the wireframe's "WCAG 2.2 AA notes" exactly (does not
    redefine focus-visible, reuses the `#767676` AA-floor gray, scopes to
    `.do-showcase-discovery-ranking` only).
  - The `do_showcase.libraries.yml` entry correctly declares a `do_showcase/switcher` dependency
    and is attached only from the new render subtree (verified: my controller code does attach
    `do_showcase/discovery-compare` on `$build['discovery_ranking']['#attached']`, not
    site-wide).
  - `do_showcase.switcher.js`'s `queryKeyForGroup()` fix correctly resolves A's Spot-check
    finding #2 and its `href`-parsing logic is verified compatible with my
    `VariantSwitcher::build()` implementation (`?<query_key>=<id>` parses to the right key via
    `URLSearchParams`).
  I flag this for O/A transparency rather than silently claiming sole authorship of files I did
  not write, per the parallel-agent-coexistence discipline — but I made no changes to any of the
  three, since none were needed.
- No other deviation from the plan. Options order (Recent/Hot/Promoted), query key
  (`discovery`), HelpText key name, and embed strategy (`views_embed_view()`, no new view config)
  all match handoff-A-plan.md verbatim.

## Tier 1 self-check (incl. tests now GREEN)

Assembled via `bash scripts/ci/assemble-config.sh` before every run (inside the `gm123-discovery`
DDEV container).

### Unit (50 tests, incl. 11 new from this story: 6 in `VariantSwitcherTest`, 5 in
`DiscoveryRankingHelpTextTest`)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/
```

```
OK, but there were issues!
Tests: 50, Assertions: 308, PHPUnit Deprecations: 37.
```

(Deprecations only — pre-existing framework-level notices reproduced identically on an untouched
sibling test file (`ShowcaseCatalogTest.php`); zero test failures. Exit code 0.)

### Kernel (27 tests, non-regression only — no new Kernel tests this story)

```
SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Kernel/
```

```
OK, but there were issues!
Tests: 27, Assertions: 317, Deprecations: 6, PHPUnit Deprecations: 40.
```

(Includes `DirectoryTogglePreRenderTest`'s 8 tests — the #124 SC-5 story that shares
`VariantSwitcher::build()`'s BC-safe 3-arg call form — all still GREEN. Exit code 0.)

### Functional (37 tests, incl. 8 new from `DiscoveryRankingControllerTest`)

```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8080' \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Functional/
```

```
OK, but there were issues!
Tests: 37, Assertions: 260, Deprecations: 23, PHPUnit Deprecations: 54.
```

(All 8 `DiscoveryRankingControllerTest` methods GREEN, incl. the two content-proving tests
— Recent embeds `activity_stream`'s real seeded node, Promoted embeds `promoted_content`'s two
real seeded nodes — and the non-regression `/hot` test. Exit code 0.)

**Total: 114 tests, 0 failures, across all three tiers.**

### Playwright spec syntax check (cannot run live — no seeded site on this DDEV project)

```
npx playwright test tests/e2e/discovery-compare.spec.ts --list
```

```
Total: 11 tests in 1 file
```

(Unchanged from T's RED confirmation — same 11 tests still resolve with no import/selector
errors. Not executed live per this task's own instruction; T-GREEN/CI's `e2e` job runs it for
real against the fully seeded site.)

### phpcs (`docs/groups/modules/do_showcase/`, per task instruction)

Literal command output (includes T's test files, which are not mine to fix):

```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_showcase/
```

```
A TOTAL OF 201 ERRORS AND 13 WARNINGS WERE FOUND IN 23 FILES
```

**Every one of these is either (a) a pre-existing baseline issue in a file I did not touch, or
(b) a T-authored test file** (not mine to edit per role). Confirmed by isolating production-only
paths:

```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_showcase/src/VariantSwitcher.php \
  docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php \
  docs/groups/modules/do_showcase/css/discovery-compare.css \
  docs/groups/modules/do_showcase/do_showcase.libraries.yml
```

Result: **0 errors, 0 warnings** — clean.

`docs/groups/modules/do_chrome/src/HelpText.php`: 18 errors / 8 warnings after my edit, versus
**19 errors / 8 warnings before** (confirmed via a side-by-side run against the HEAD version) —
my appended key introduced ZERO new violations (net -1 vs. baseline).

`docs/groups/modules/do_showcase/js/do_showcase.switcher.js`: phpcs's `Drupal` standard flags
lowercase JS `true`/`false`/`null` as "must be uppercase" (a PHP-standard sniff false-positive
against JS syntax — uppercasing would break the JavaScript). Confirmed pre-existing and
module-wide: the untouched `do_showcase.ribbon.js` shows the identical false-positive class (1
instance); the pre-story HEAD version of `switcher.js` already had 5; the current file has 6 (the
+1 is the new `queryKeyForGroup()` helper's `return null;`). Not something to "fix."

`docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (untouched by me): 0 errors, 9
pre-existing warnings, unchanged from before this story.

## Tests that look wrong (for T)

None. All of T's authored assertions (Unit ×2 files, Functional ×1 file) pass against this
implementation without needing any test edited. Two things worth flagging as observations, not
test defects:

1. T's own `DiscoveryRankingControllerTest.php` class docblock already flags (and the test itself
   correctly pins) that the REAL `views.view.promoted_content.yml` has no actual flag/relationship
   filter restricting to `promote_homepage`-flagged content — it filters only on `status`
   (published). This is a pre-existing latent gap in a REUSED view this story is scoped NOT to
   fix (per A-plan Risk 3: embed AS-IS). T already surfaced this for O/A visibility in
   `handoff-T-red.md`; I have nothing to add beyond confirming the implementation correctly embeds
   the view's true current behavior rather than a false "excludes non-promoted" claim.
2. `DiscoveryRankingControllerTest`'s `@group do_showcase` PHPUnit Functional tests trigger a
   framework-level deprecation ("Functional/FunctionalJavascript test classes must specify the
   `#[RunTestsInSeparateProcesses]` attribute... throwing an exception in drupal:12.0.0") —
   this is emitted by EVERY BrowserTestBase subclass in this codebase (confirmed identically on
   the untouched `ShowcaseControllerHelpTest.php`), not specific to T's new file. Not a test
   defect; a repo-wide, pre-existing Drupal-11-to-12 forward-compat notice outside this story's
   scope.

## Known issues

None. All 8 acceptance criteria from the issue are satisfied by this implementation (verified
against T's Functional/Unit tests): three variants render non-empty from seed (Promoted's 2
seeded nodes confirmed by `testPromotedTabEmbedsPromotedContentViewWithSeededNodes`); switcher +
single wrapper tooltip present (`testDiscoverySwitcherCarriesExactlyOneWrapperTooltip`); deep
links land pre-switched (`testDiscoveryQueryArgDeepLinksToHotTabPreSelected`,
`testUnknownDiscoveryQueryArgFallsBackToRecent`); `/hot` unaffected
(`testExistingHotPageRouteIsUnaffected`); existing suite stays green (114/114 across all tiers);
Playwright spec present and syntactically valid; HelpText entry appended
(`DiscoveryRankingHelpTextTest`, 5/5 GREEN); WCAG reuse (radiogroup/roving-tabindex/focus-visible
verbatim from `VariantSwitcher`, no new interaction code).

## Files changed

- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (modified)
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` (modified)
- `docs/groups/modules/do_chrome/src/HelpText.php` (modified — append-only)
- `docs/groups/modules/do_showcase/css/discovery-compare.css` (new)
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` (modified)
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` (modified — see Deviations: already
  present at session start, verified correct, no changes made by me)

No test files were created or modified by me (T owns
`tests/src/Unit/VariantSwitcherTest.php`'s new methods,
`tests/src/Unit/DiscoveryRankingHelpTextTest.php`,
`tests/src/Functional/DiscoveryRankingControllerTest.php`, and the 3 fixture YAMLs under
`tests/fixtures/config/`).
