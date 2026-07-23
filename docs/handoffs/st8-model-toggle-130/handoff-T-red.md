# Handoff-T-red: Phase 4 - ST-8 Model comparison toggle (#130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Brief / wireframe reviewed:** `docs/handoffs/st8-model-toggle-130/brief.md` (incl. Amendment 1),
`docs/handoffs/st8-model-toggle-130/wireframe.md`, `docs/handoffs/st8-model-toggle-130/handoff-A.md`,
`docs/handoffs/st8-model-toggle-130/handoff-D.md`

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`) with no blocking findings —
8 advisories, none of which change what T must author. Proceeding to author tests against the
approved plan.

## Files created / modified

**New:**
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamModelTogglePreRenderTest.php`
- `docs/groups/modules/do_streams/tests/fixtures/config/views.view.activity_stream.yml`
- `docs/groups/modules/do_streams/tests/fixtures/config/views.view.some_other_view.yml`
- `docs/groups/modules/do_chrome/tests/src/Unit/StreamModelHelpTextTest.php`
- `tests/e2e/model-toggle.spec.ts`

**Modified (existing test files, adding new cases — T's job per the brief's scope items #9/#10):**
- `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php` (+
  `testStreamModelOptions()`)
- `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php` (+
  `testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence()`)

No production code (`ModelToggleHooks.php`, `VariantSwitcher::streamModelOptions()`,
`ShowcaseCatalog.php`, `HelpText.php`, `.services.yml`, `.libraries.yml`, CSS) was written or
edited — all of that is F's job.

## Tests authored

### Kernel — `StreamModelTogglePreRenderTest.php` (clone of `DirectoryTogglePreRenderTest.php`)

| Test | Criterion / behavior pinned | Tier rationale |
|---|---|---|
| `testSwitcherInjectedWithTwoOptionsInOrder` | AC-1, AC-7: switcher renders in the view's `#header` with exactly 2 options (content, activity) in order; content carries `aria-disabled="true"` + "Content view (soon)" label | Kernel — only tier that can drive `hook_views_pre_render`/`hook_preprocess_views_view` through a real `ViewExecutable` render pipeline |
| `testViewDeclaresUrlQueryArgsVariantCacheContextDirectly` | AC-9: view's own `#cache['contexts']` carries `url.query_args:variant` directly (not solely via child bubbling) | Kernel |
| `testNoQueryParamDefaultsWrapperToActivity` | AC-2: no `?variant=` → wrapper resolves to `activity` | Kernel |
| `testActivityQueryParamSetsWrapperToActivity` | AC-4: explicit `?variant=activity` → `activity`, no reload flash (server-resolved) | Kernel |
| `testUnavailableContentQueryParamFallsBackToActivity` | AC-3: `?variant=content` (unavailable) falls back to `activity` | Kernel |
| `testUnknownQueryParamFallsBackToActivity` | Same fallback rule for an unrecognized id — negative-space completeness | Kernel |
| `testSwitcherAndModelToggleLibrariesAttached` | Brief scope #2/#3: `do_showcase/switcher` + `do_streams/model-toggle` libraries attached | Kernel |
| `testHookDoesNotFireForADifferentViewId` | Scope guard: hook fires ONLY for `activity_stream:page_1` | Kernel |

Fixtures: `views.view.activity_stream.yml` (minimal, module-local, `node_field_data`-based,
`page_1` path `stream`) and `views.view.some_other_view.yml` (unrelated negative-case view — see
"Deviation from spec" below for why this replaced the suggested `all_groups` reuse).

### Unit — `VariantSwitcherTest.php` (+1 case)

| Test | Criterion pinned |
|---|---|
| `testStreamModelOptions` | Brief scope #5: `streamModelOptions()` returns exactly `[{id:'content', label:'Content view', available:FALSE}, {id:'activity', label:'Activity view'}]`, matching `directoryLayoutOptions()`'s shape 1:1 |

### Unit — `ShowcaseCatalogTest.php` (+1 case)

| Test | Criterion pinned |
|---|---|
| `testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence` | AC-5: `stream-model` catalog entry is `status: live`, `route: view.activity_stream.page_1`, and `decision_sentence` names both halves ("node-content model" / "activity-log model") of D's approved comparison copy |

### Unit — `StreamModelHelpTextTest.php` (NEW file, mirrors `HelpTextTest.php`'s pattern)

| Test | Criterion pinned |
|---|---|
| `testSwitcherStreamModelTooltipCopyIsPresentAndMatchesApprovedCopy` | AC-6: NEW key `showcase.switcher.stream.model` — non-empty, plain text, names Activity's row types (posts/comments/flags/pins/membership), describes Content as "leaner", qualifies it "coming soon" |
| `testShowcaseHelpStreamModelCopyIsUpdatedToMatchNewFraming` | Amendment 1: PRE-EXISTING key `showcase_help.stream-model` no longer contains the stale "per-content-type" framing; contains "node-content model" and "activity-log model" |

### E2E — `tests/e2e/model-toggle.spec.ts` (clone of `directory-toggle.spec.ts`, 11 tests)

Covers: default render (2 options, Activity selected), Content's disabled/tabindex/"(soon)"
markers, Activity's aria-checked/tabindex/`●` glyph, click-on-Content no-op, `/showcase` catalog
`live` link-through, `?variant=content` fallback (AC-3), `?variant=activity` deep link (AC-4), ⓘ
tooltip presence + keyboard focus + copy match (AC-6), non-regression of existing
`activity_stream` rows and sibling switcher instances (`/all-groups`, `/showcase`).

## RED confirmation

Ran assembled tests via DDEV (`pl-groups-on-d11`), `SIMPLETEST_DB='mysql://root:root@db:3306/drupal'`
for Kernel, against the FULL worktree source tree (do_showcase/do_chrome/do_streams), not a stale
copy — see "Note on execution environment" below for why this mattered.

**Kernel** (`php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/StreamModelTogglePreRenderTest.php`):

```
FFFFFFFD                                                            8 / 8 (100%)

 ✘ Switcher injected with two options in order
   Failed asserting that '<div class="views-element-container">...</div>' contains
   "data-do-showcase-instance="stream.model"" — the switcher isn't injected (ModelToggleHooks
   doesn't exist yet).
 ✘ View declares url query args variant cache context directly
   Failed asserting that an array contains 'url.query_args:variant'.
 ✘ No query param defaults wrapper to activity
   Failed asserting that null is identical to 'activity'.
 ✘ Activity query param sets wrapper to activity
   Failed asserting that null is identical to 'activity'.
 ✘ Unavailable content query param falls back to activity
   Failed asserting that null is identical to 'activity'.
 ✘ Unknown query param falls back to activity
   Failed asserting that null is identical to 'activity'.
 ✘ Switcher and model toggle libraries attached
   Failed asserting that an array contains 'do_showcase/switcher'.
 ✔ Hook does not fire for a different view id   <- correctly PASSES: no hook exists yet, so
   trivially nothing fires for any view, including the negative-case view. Will stay green once
   F implements the scoping guard correctly.

Tests: 8, Assertions: 195, Failures: 7.
```

Every failure is the RIGHT reason: the wrapper attribute/cache-context/library/`#header` key are
all simply absent because `ModelToggleHooks` doesn't exist — none are import/setup/syntax errors.

**Unit — VariantSwitcherTest** (16 tests: 15 pre-existing pass unchanged, 1 new):
```
✔ (15 pre-existing tests, unaffected by this story's addition)
✘ Stream model options
  Error: Call to undefined method Drupal\do_showcase\VariantSwitcher::streamModelOptions()
Tests: 16, Assertions: 43, Errors: 1.
```
Correct RED reason: a fatal `\Error` (undefined method), not an assertion failure — F has not
implemented the method yet.

**Unit — ShowcaseCatalogTest** (10 tests: 9 pre-existing pass unchanged, 1 new):
```
✔ (9 pre-existing tests, unaffected)
✘ Stream model entry is live with activity stream route and corrected decision sentence
  Failed asserting that two strings are identical.
  - 'live'
  + 'coming'
Tests: 10, Assertions: 92, Failures: 1.
```
Correct RED reason: the catalog entry is still the OLD `coming`/stale-sentence entry.

**Unit — StreamModelHelpTextTest** (2 new tests):
```
✘ Switcher stream model tooltip copy is present and matches approved copy
  Failed asserting that two strings are not identical. (both '' — key does not exist yet)
✘ Showcase help stream model copy is updated to match new framing
  Failed asserting that 'One combined activity stream vs. separate streams per content type...'
  matches PCRE pattern "/node-content model/i". (old stale copy still present)
Tests: 2, Assertions: 5, Failures: 2.
```
Both fail for the right reason: one key doesn't exist yet, the other still carries the
pre-Amendment-1 stale copy.

**E2E** (`npx playwright test tests/e2e/model-toggle.spec.ts --list`):
```
Total: 11 tests in 1 file
```
Registers cleanly (syntactically valid, no import errors). Not executed live — per T's Phase-4
mandate, E2E RED verification is registration-only; the live run happens at T-GREEN against a
fully seeded, running site.

**Lint** (`php vendor/bin/phpcs --standard=Drupal,DrupalPractice`): the new/modified test files
carry the SAME class of docblock-style findings (multi-sentence `@return`/short-description
violations, one non-lowerCamel negative-test method name) already present verbatim in the merged
precedent `DirectoryTogglePreRenderTest.php` (13 identical-category findings there too) — this is
an existing house-style pattern in this test suite's docblocks, not a regression introduced here.
Flagging as advisory, non-blocking.

## Deviation from the task's suggested negative-case fixture

The task instructions suggested reusing `all_groups` as the Kernel negative-case view (mirroring
`DirectoryTogglePreRenderTest`'s own inverse pair, which uses `activity_stream` as ITS negative
case). During RED verification this produced a **false negative**: `all_groups:page_1` legitimately
already carries the `url.query_args:variant` cache context, set by #124's shipped
`DoShowcaseHooks::viewsPreRender()` for ITS OWN (`directory.layout`) switcher instance — asserting
its absence would make the test fail even after F's ModelToggleHooks is correctly scoped, purely
because of an unrelated, already-shipped feature sharing the same cache-context string.

Fixed by: (1) authoring a new, wholly synthetic fixture `views.view.some_other_view.yml` that no
other hook in the codebase targets, and (2) asserting the ABSENCE of the `switcher` `#header` key
and the `do_streams/model-toggle` library specifically — signals unique to ModelToggleHooks — rather
than the shared cache-context key. This is documented in the test's own docblock.

## Note on execution environment

DDEV (`pl-groups-on-d11`) mounts only the **primary checkout** (`~/Projects/groups-on-d11`) via
Mutagen sync — it has no bind mount for this per-story worktree. To get a real `SIMPLETEST_DB`-backed
Kernel run, the authored test/fixture files (and, since the primary checkout's `docs/groups/`
tree was several commits behind this worktree's base, the full `do_showcase`/`do_chrome`/`do_streams`
source trees) were temporarily copied into the primary checkout, assembled
(`scripts/ci/assemble-config.sh`), and run there. The primary checkout was then fully restored
(`git checkout --`, `git clean -fd` for `docs/groups/`, `tests/e2e/`, `config/sync/`,
`package.json`/`package-lock.json`; `rm -rf` for `web/modules/custom`, `vendor`, `node_modules`)
and reconfirmed clean against the session's opening `git status`. No test file was authored in the
primary checkout — every file was written directly to the worktree first; the primary checkout
copy was execution-only scaffolding, never committed there.

## Ready for F

**RED is valid.** All 7 new Kernel assertions, 1 new VariantSwitcher unit test, 1 new
ShowcaseCatalog unit test, and 2 new HelpText unit tests fail for the correct reason (missing
hook/method/config, not import/setup/syntax errors). The negative-case Kernel test
(`testHookDoesNotFireForADifferentViewId`) correctly passes trivially pre-implementation and must
stay green once F adds the view-id/display-id guard. F may implement against these tests.

**Implementation hints for F:**

- `do_streams/src/Hook/ModelToggleHooks.php` — two `#[Hook]` methods, `viewsPreRender()` +
  `preprocessViewsView()`, guarded by a private `isModelToggleView()` (view id
  `activity_stream`, display `page_1`) + private `requestedVariant()` (reads `?variant=` off
  `RequestStack::getCurrentRequest()`, default `'activity'` — NOT `'cards'`, matching this
  story's page default). Constructor: `VariantSwitcher $switcher`, `RequestStack $requestStack`.
  Register in `do_streams.services.yml` as `do_streams.model_toggle_hooks` with
  `@do_showcase.variant_switcher` + `@request_stack`, tagged `hook_implementations` — mirror
  `do_showcase.services.yml`'s `do_showcase.hooks` entry.
- `viewsPreRender(ViewExecutable $view)`: on match, push `'url.query_args:variant'` onto
  `$view->element['#cache']['contexts']`, resolve via
  `$this->switcher->resolveCurrent($this->switcher->streamModelOptions(), $this->requestedVariant())`,
  set `$view->element['#attributes']['data-do-stream-model']`, attach
  `do_showcase/switcher` + `do_streams/model-toggle` to `$view->element['#attached']['library']`.
- `preprocessViewsView(array &$variables)`: on match, `$this->switcher->build('stream.model', $this->switcher->streamModelOptions(), $this->requestedVariant())`,
  set the same `data-do-showcase-mirror-attribute` / `data-do-showcase-mirror-selector` pair
  DoShowcaseHooks sets (`data-do-stream-model` / `.views-element-container`), inject into
  `$variables['header']['switcher']`.
- `VariantSwitcher::streamModelOptions(): array` — a new public method, same shape as
  `directoryLayoutOptions()`: a private `streamModelOptionIds()` machine spec
  (`[['id'=>'content','available'=>FALSE], ['id'=>'activity']]`) + a `match()` translating labels
  ('Content view' / 'Activity view') via `$this->t()`.
- `ShowcaseCatalog::entries()` — flip the `stream-model` entry: `status => 'live'`,
  `route => 'view.activity_stream.page_1'`, new `decision_sentence` (D's approved copy in
  `handoff-D.md`).
- `HelpText::all()` — append `'showcase.switcher.stream.model'` (D's approved copy, see brief.md
  Amendment 1) and UPDATE (not append) the existing `'showcase_help.stream-model'` entry (line
  ~284) to match.
- `do_streams/do_streams.libraries.yml` — add a `model-toggle` library entry (CSS-only, per brief
  scope #2) pointing at a new `css/model-toggle.css`.

Handoff appended to `docs/handoffs/st8-model-toggle-130/decisions.md` (Decided/Assumed/Evidence).
