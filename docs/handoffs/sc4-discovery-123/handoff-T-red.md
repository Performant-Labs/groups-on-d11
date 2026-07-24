# Handoff-T-red: Phase 4 - #123 SC-4 Discovery three ways

**Date:** 2026-07-23
**Branch:** 123-discovery-three-ways
**Brief / wireframe reviewed:** `docs/handoffs/sc4-discovery-123/brief.md`,
`docs/handoffs/sc4-discovery-123/wireframe.md`, `docs/handoffs/sc4-discovery-123/handoff-A-plan.md`

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3), with three binding directives (query-key
4th param on `VariantSwitcher::build()`, single wrapper tooltip, embed the three existing views
directly rather than create `views.view.discovery_compare.yml`) recorded in
`handoff-A-plan.md` and `decisions.md`. No BLOCK. Tests below are authored against A's resolved
plan, not the original (dropped) `discovery_compare.yml` approach.

## Tests authored

### 1. `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php` (extended, not new)

Six new test methods appended to the existing suite, pinning A-plan Risk 1 (the new optional 4th
`string $query_key = 'variant'` parameter):

| Test | Criterion pinned | Tier | Why this tier |
|---|---|---|---|
| `testBuildWithCustomQueryKeyEmitsThatKeyInEveryOptionHref` | Calling `build()` with `$query_key='discovery'` emits `?discovery=<id>` hrefs, never `?variant=` | Unit | Pure render-array construction, no Drupal services — cheapest tier that can pin this |
| `testBuildWithoutQueryKeyStillDefaultsToVariantForBackwardCompatibility` | The existing 3-arg call form is BC-safe (still defaults to `?variant=`) | Unit | Same — non-regression pin distinct from content-correctness tests already in the file |
| `testBuildWithCustomQueryKeyBubblesMatchingCacheContext` | `#cache['contexts']` bubbles `url.query_args:discovery`, not a hardcoded `url.query_args:variant` (A-plan Risk 1 + Spot-check finding #1) | Unit | Cache-metadata contract, independently testable at the array level |
| `testTwoSimultaneousInstancesWithDistinctQueryKeysDoNotCollide` | Two `build()` calls (default key + `discovery`) coexist without either leaking the other's query key | Unit | Proves the wireframe's own justification for two switchers sharing one page |

Existing 15 tests in this file are untouched and still pass (verified — see RED confirmation
below; they remain GREEN, which is correct: they pin the pre-existing 3-arg contract, which is
unaffected by adding an optional 4th param).

### 2. `docs/groups/modules/do_showcase/tests/src/Unit/DiscoveryRankingHelpTextTest.php` (new)

| Test | Criterion pinned | Tier |
|---|---|---|
| `testDiscoveryRankingSwitcherTooltipKeyResolvesToNonEmptyPlainText` | `HelpText::get('showcase.switcher.discovery.ranking')` is non-empty, plain text | Unit |
| `testDiscoveryRankingSwitcherTooltipCopyNamesAllThreeVariantsAndTheirDecisions` | Copy names Recent/Hot/Promoted AND their decisions (chronological / engagement-ranked / editorial) — the issue's own phrasing | Unit |
| `testDiscoveryRankingSwitcherTooltipKeyIsPresentInAllArray` | Key is a literal entry in `HelpText::all()` | Unit |
| `testDiscoveryRankingKeyIsDistinctFromTheExistingShowcaseHelpKey` | Guards against confusing this NEW key with the ALREADY-PRESENT `showcase_help.discovery-ranking` key (#132, different namespace/consumer) | Unit |
| `testExistingDirectoryLayoutSwitcherKeyStillResolvesUnchanged` | Non-regression: appending this key doesn't disturb `showcase.switcher.directory.layout` | Unit |

A new file (not an edit to the existing `ShowcaseHelpTextTest.php`), matching this repo's
established "each story tests its own append" convention (`HelpText.php`'s own docblock: "No
B-story edits another's entry").

### 3. `docs/groups/modules/do_showcase/tests/src/Functional/DiscoveryRankingControllerTest.php` (new)

BrowserTestBase (Functional tier — a real HTTP request through the real `/showcase` route),
mirroring `ShowcaseControllerHelpTest`'s established pattern for this controller:

| Test | Criterion pinned |
|---|---|
| `testDiscoveryRankingSectionRendersSwitcherWithThreeAvailableOptions` | New H2 section, `discovery.ranking` switcher, 3 options, none unavailable (brief.md: "all three variants render non-empty from seed") |
| `testDiscoveryQueryArgDeepLinksToHotTabPreSelected` | `?discovery=hot` pre-selects the Hot tab server-side |
| `testRecentTabEmbedsActivityStreamViewContent` | `?discovery=recent` embeds the REAL `activity_stream` view's content (proves no forked ranking) |
| `testPromotedTabEmbedsPromotedContentViewWithSeededNodes` | Promoted tab shows the two seeded promoted nodes |
| `testUnknownDiscoveryQueryArgFallsBackToRecent` | Unknown `?discovery=` value falls back to first option, never blank |
| `testBothSwitchersCoexistWithIndependentQueryKeys` | `?variant=cards&discovery=hot` sets BOTH switchers independently (key constraint) |
| `testDiscoverySwitcherCarriesExactlyOneWrapperTooltip` | Exactly one `[data-do-tooltip]` on the switcher (POC scope, not per-option) |
| `testExistingHotPageRouteIsUnaffected` | Non-regression: `/hot` (hot_content's own `page_1`) unchanged |

**Fixtures added** (module-local, `docs/groups/modules/do_showcase/tests/fixtures/config/`, per
PROJECT_CONTEXT.md — never a source-relative path):
- `views.view.hot_content.yml` — minimal title-only stand-in (no `do_discovery` hot-score
  dependency), same pattern as the pre-existing `views.view.activity_stream.yml` fixture in this
  directory.
- `views.view.promoted_content.yml` — mirrors the REAL
  `docs/groups/config/views.view.promoted_content.yml` default display byte-for-byte.
- `flag.flag.promote_homepage.yml` — mirrors the real flag config, scoped to bundle `post` (the
  only node type this test's minimal install creates).
- Reused the existing `views.view.activity_stream.yml` fixture as-is (already present, already a
  faithful minimal stand-in per its own docblock).

## RED confirmation

Assembled the layout first (`bash scripts/ci/assemble-config.sh` inside DDEV, after `ddev composer
install`), then ran PHPUnit from the assembled layout inside the `gm123-discovery` DDEV container
(fixed `.ddev/config.yaml`'s stale `name: gm124-directory` left over from a different worktree
copy — corrected to `gm123-discovery` to match this story's assigned project).

### VariantSwitcherTest (Unit)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php
```

Result: **19 tests, 3 failures** (the 3 new query-key tests) — all others (including the 15
pre-existing tests) pass unchanged.

```
✘ Build with custom query key emits that key in every option href
  Option "recent" must carry a ?discovery= fallback link (the caller-supplied query_key), not ?variant=.
  Failed asserting that '?variant=recent' contains "discovery=recent".

✘ Build with custom query key bubbles matching cache context
  build() called with $query_key="discovery" must bubble url.query_args:discovery ...
  Failed asserting that an array contains 'url.query_args:discovery'.

✘ Two simultaneous instances with distinct query keys do not collide
  Failed asserting that '?variant=recent' contains "discovery=recent".
```

RED for the right reason: `build()`'s current 3-parameter signature silently ignores the 4th
argument PHP passes it (no `ArgumentCountError` — PHP does not enforce arity against a caller
supplying extra positional args to a non-variadic user function), so every assertion fails on
**content** (`?variant=` instead of `?discovery=`), never on a fatal/import/setup error. This is
the correct RED shape for a signature-extension feature.

### DiscoveryRankingHelpTextTest (Unit)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Unit/DiscoveryRankingHelpTextTest.php
```

Result: **5 tests, 3 failures** (the 3 tests asserting the NEW key resolves/exists); the 2
non-regression tests (distinctness from the existing `showcase_help.discovery-ranking` key,
`showcase.switcher.directory.layout` still resolving) pass — correctly, since they don't depend on
the unbuilt feature.

```
✘ Discovery ranking switcher tooltip key resolves to non empty plain text
  The appended HelpText key "showcase.switcher.discovery.ranking" must resolve to non-empty copy.
  Failed asserting that two strings are not identical.

✘ Discovery ranking switcher tooltip copy names all three variants and their decisions
  Tooltip copy must name "Recent".
  Failed asserting that '' matches PCRE pattern "/recent/i".

✘ Discovery ranking switcher tooltip key is present in all array
  "showcase.switcher.discovery.ranking" must be a literal key in HelpText::all().
  Failed asserting that an array has the key 'showcase.switcher.discovery.ranking'.
```

RED for the right reason: the key does not exist yet, so `HelpText::get()` resolves it via the
documented unknown-key fallback (`''`) — every failure is the on-topic "missing key" assertion, not
an import/setup error.

### DiscoveryRankingControllerTest (Functional/BrowserTestBase)

Required a served docroot + a real DB (BrowserTestBase installs its own throwaway prefixed site
per test method, but the test router still needs `SIMPLETEST_DB` + `SIMPLETEST_BASE_URL`, mirroring
`.github/workflows/test.yml`'s functional job exactly). Set up locally inside DDEV:

```
php -S 127.0.0.1:8080 -t web "$PWD/web/.ht.router.php"   # background, DDEV's own PHP/router
SIMPLETEST_DB='mysql://db:db@db:3306/db' \
SIMPLETEST_BASE_URL='http://127.0.0.1:8080' \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Functional/DiscoveryRankingControllerTest.php
```

Result: **8 tests, 7 failures, 1 pass**.

```
✘ Discovery ranking section renders switcher with three available options
  Behat\Mink\Exception\ElementNotFoundException: Element matching css "[data-do-discovery-ranking]" not found.

✘ Discovery query arg deep links to hot tab pre selected
  Element matching css "[data-do-discovery-ranking]" not found.

✘ Recent tab embeds activity stream view content
  Element matching css "[data-do-discovery-ranking]" not found.

✘ Promoted tab embeds promoted content view with seeded nodes
  Element matching css "[data-do-discovery-ranking]" not found.

✘ Unknown discovery query arg falls back to recent
  Element matching css "[data-do-discovery-ranking]" not found.

✘ Both switchers coexist with independent query keys
  Element matching css "[data-do-discovery-ranking]" not found.

✘ Discovery switcher carries exactly one wrapper tooltip
  Element matching css "[data-do-discovery-ranking]" not found.

⚠ Existing hot page route is unaffected   [PASSES — correct: non-regression test, /hot is
  unaffected by anything not yet built]
```

RED for the right reason: `ShowcaseController::page()` does not yet render the
`[data-do-discovery-ranking]` region at all — every failing assertion targets markup that
genuinely does not exist in the current codebase, never an import/autoload/setup error. The one
passing test (`testExistingHotPageRouteIsUnaffected`) is a non-regression pin and is CORRECTLY
green already (it does not depend on unbuilt code) — this is the expected shape for a
non-regression test authored alongside new-feature RED tests, not an invalid RED.

**Fixture debugging along the way** (recorded for transparency, not hidden):
1. First fixture attempt for `promoted_content` invented a `flag_relationship`-plugin `flagged`
   filter with no matching config schema (`SchemaIncompleteException`) — my own fixture-authoring
   error, unrelated to the feature under test. Fixed by mirroring the REAL
   `views.view.promoted_content.yml`'s actual default-display shape instead of inventing a filter.
2. That same fixture's `description:` field broke YAML parsing (mixed nested single/double quotes
   and backticks) — simplified to plain prose.
3. `hot_content`'s `page_1` (`path: hot`) was never registered as a route because
   programmatically-created view config entities (via `EntityStorage::create()->save()`, not
   `drush config:import`) do not trigger a router rebuild — added an explicit
   `\Drupal::service('router.builder')->rebuild()` call to `setUp()` after creating the view
   entities.
None of these were feature-authorship RED-invalidating errors — they were fixture/test-harness
setup bugs, now fixed, and the resulting RED failures are all on-topic.

### discovery-compare.spec.ts (Playwright E2E)

```
npx playwright test tests/e2e/discovery-compare.spec.ts --list
```

Result: **11 tests listed across 1 file, no errors** — confirms the spec is syntactically valid,
has no missing imports, and every selector/locator helper resolves without a TypeScript/Playwright
API error.

This DDEV project (`gm123-discovery`) has no installed/seeded Drupal site (composer install +
assemble-config were run for the PHPUnit tiers above, but `drush site:install` / `config:import` /
the `docs/groups/scripts/step_*.php` seed sequence were NOT run) — running the spec live against
it would fail on setup (no site installed), not on the feature, which would NOT be valid RED
evidence. Per this task's own instruction ("If Playwright cannot run locally without a seeded
site, note that; a syntactically valid spec ... is acceptable RED evidence"), `--list` is the RED
evidence for this tier; T-GREEN will run it for real against the fully seeded site per this repo's
established E2E convention (`.github/workflows/test.yml`'s `e2e` job: assemble → site:install →
config:import → seed step_700/720/780/790 → runserver → `npx playwright test`).

## Assumptions made about seed data

1. **Promoted node count/titles**: confirmed directly from
   `docs/groups/scripts/step_700_demo_data.php` lines 355-362 — exactly two nodes are flagged
   `promote_homepage`: **"Getting Started with Paragraphs"** and **"Community Code of Conduct"**.
   The Playwright spec's Promoted-tab test asserts these two exact titles.
2. **`promoted_content` view has no actual flag filter** (flagged prominently in the
   `DiscoveryRankingControllerTest` class docblock and in the fixture's `description:` field): the
   real `docs/groups/config/views.view.promoted_content.yml` default display filters ONLY on
   `status` (published) — despite its label/description ("Promoted Content" / "Content flagged as
   Promote to homepage"), it carries no relationship/filter that actually restricts to
   `promote_homepage`-flagged nodes. Every prior handoff (survey.md, handoff-A-plan.md) assumed
   this filter exists ("promoted_content's flag filter", "filtered by promote_homepage flag"); it
   does not, on inspection of the actual YAML. Per A-plan's Risk 3 resolution ("do NOT fork
   ranking" — embed the three views AS-IS), this story does not fix that view, so my Functional
   test pins the view's TRUE current behavior (the two seeded promoted nodes appear because they
   are published, not because a flag filter excludes non-promoted content) rather than asserting a
   false "excludes non-promoted" claim. **This is a pre-existing latent gap outside this story's
   scope — flagging for O/A visibility, not blocking F's implementation**, since F is only
   responsible for the controller/switcher wiring, not the reused view's filter correctness.
3. **Hot tab ordering is cron-dependent** (brief.md: "Hot shows commented threads on top after
   cron") — the Playwright spec's Hot-tab test asserts only non-emptiness, not a specific row
   order, since `hot_content`'s score is comment-driven and cron-recomputed (an environment/timing
   precondition outside a single spec run's control, consistent with how
   `directory-toggle.spec.ts`'s pager test already conditionally skips on seed-size preconditions
   it cannot control).
4. **`.ddev/config.yaml` in this worktree had a stale project name** (`gm124-directory`, left over
   from being copied from a different worktree) — corrected to `gm123-discovery` per this task's
   assigned DDEV project name before `ddev start`.

## Ready for F

Confirmed RED is valid across all three PHPUnit-tier test files (Unit ×2, Functional ×1) and the
Playwright spec is syntactically valid and will exercise real markup once seeded. F may implement
against these tests:
1. Extend `VariantSwitcher::build()` with the optional 4th `string $query_key = 'variant'`
   parameter + fix the `#cache['contexts']` bubble.
2. Extend `ShowcaseController::page()` with the `discovery.ranking` switcher instance +
   `[data-do-discovery-ranking]` region + `views_embed_view()` routing on `?discovery=`.
3. Append the `showcase.switcher.discovery.ranking` HelpText key to `do_chrome/src/HelpText.php`.
4. Per A's Spot-check finding #2: verify `do_showcase.switcher.js` (currently hardcodes
   `params.has('variant')` at line 195 for the "URL wins over stale sessionStorage" check) reads
   the query key generically rather than assuming `variant` — this WILL affect the
   `discovery.ranking` instance's client-side behavior once wired, even though no Kernel/Functional
   test in this RED set exercises client-side JS (that is Playwright's job, exercised at T-GREEN
   against the live seeded site).
