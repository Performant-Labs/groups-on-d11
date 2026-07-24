# Handoff-F: Phase 5 - ST-8 Model comparison toggle (#130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Issue:** #130

## What was done

- **NEW** `docs/groups/modules/do_streams/src/Hook/ModelToggleHooks.php` — mounts the SC-F1
  `VariantSwitcher` over `/stream` (`activity_stream:page_1`) via two cooperating methods:
  `viewsPreRender()` (a real `#[Hook('views_pre_render')]`) sets the `data-do-stream-model`
  wrapper attribute, `url.query_args:variant` cache context, and attaches
  `do_showcase/switcher` + `do_streams/model-toggle`; `preprocessViewsView()` (NOT
  `#[Hook]`-attributed — see Design decisions) injects the built switcher render array into
  `$variables['header']['switcher']`. Constructor DI: `?VariantSwitcher $switcher` (nullable —
  see Design decisions), `RequestStack $requestStack`.
- **NEW** `docs/groups/modules/do_streams/css/model-toggle.css` — CSS-only scoping seam keyed
  on `[data-do-stream-model="content"]`, inert today (Content view doesn't exist yet), ready for
  #129.
- **MODIFIED** `docs/groups/modules/do_streams/do_streams.libraries.yml` — added the
  `model-toggle` library (depends on `do_showcase/switcher`, mirrors `directory-compact`'s
  dependency shape).
- **MODIFIED** `docs/groups/modules/do_streams/do_streams.services.yml` — registered
  `do_streams.model_toggle_hooks` (`ModelToggleHooks`, arguments `@?do_showcase.variant_switcher`
  + `@request_stack`), added a `ModelToggleHooks` class-name alias, and wired
  `do_streams.hooks` (`DoStreamsHooks`) with a new `@do_streams.model_toggle_hooks` argument.
- **MODIFIED** `docs/groups/modules/do_streams/do_streams.info.yml` — added
  `do_showcase:do_showcase` to `dependencies:` (required for the `@do_showcase.variant_switcher`
  DI target to exist on a real site).
- **MODIFIED** `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — added
  constructor-injected `?ModelToggleHooks $modelToggleHooks = NULL`; its existing
  `preprocessViewsView()` (do_streams' one legal `preprocess_views_view` listener, previously
  ST-2/#111's `/following` library-attach only) now delegates to
  `$this->modelToggleHooks?->preprocessViewsView($variables)` after its own following-feed
  branch. No other method in this file changed.
- **MODIFIED** `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — added
  `streamModelOptionIds()` (private static machine spec) + `streamModelOptions()` (public,
  labels translated via `$this->t()`), mirroring `directoryLayoutOptionIds()`/
  `directoryLayoutOptions()`'s shape, with an explicit-key-order construction (see Design
  decisions).
- **MODIFIED** `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — flipped the
  `stream-model` entry: `status: 'live'`, `route: 'view.activity_stream.page_1'`,
  `decision_sentence` updated to D's approved copy.
- **MODIFIED** `docs/groups/modules/do_chrome/src/HelpText.php` — appended
  `showcase.switcher.stream.model` (D's approved tooltip copy, brief Amendment 1) and updated
  the pre-existing `showcase_help.stream-model` entry (line ~284, now ~301) to match the same
  corrected framing (Amendment 1).

No test file was created or edited — all test files (`StreamModelTogglePreRenderTest.php`,
`StreamModelHelpTextTest.php`, the two Kernel fixtures, the `VariantSwitcherTest.php`/
`ShowcaseCatalogTest.php` additions, `model-toggle.spec.ts`) are T's, authored in Phase 4 and
untouched here.

## Design decisions

1. **`preprocessViewsView()` collision fix — `ModelToggleHooks`'s copy is NOT `#[Hook]`-attributed;
   `DoStreamsHooks` delegates to it.** Discovered mid-implementation, not anticipated by the
   brief or A's plan review (both assumed #124's `DoShowcaseHooks` two-`#[Hook]` shape would
   transplant verbatim): Drupal's `ModuleHandler::invoke()` throws `LogicException` ("Module
   do_streams should not implement preprocess_views_view more than once") if a SINGLE module
   registers a second `preprocess_views_view` listener, even across two classes. Unlike #124
   (do_showcase had no pre-existing `preprocess_views_view` implementation), do_streams already
   has one — `DoStreamsHooks::preprocessViewsView()` (ST-2/#111, the `/following` library
   attach). Alternatives considered: (a) merge the model-toggle logic directly into
   `DoStreamsHooks::preprocessViewsView()`'s body — rejected, would mix two unrelated
   view-scoped concerns in one hand-written method and lose `ModelToggleHooks`'s clean
   single-responsibility shape the brief/A asked for; (b) refactor `DoStreamsHooks` into a
   dispatcher — rejected as unnecessary scope growth for a two-branch guard. Chosen: keep
   `ModelToggleHooks::preprocessViewsView()` as a plain public method (same `array &$variables`
   signature `hook_preprocess_views_view()` itself receives), and have
   `DoStreamsHooks::preprocessViewsView()` (the module's one legal slot) delegate to it via a
   constructor-injected `ModelToggleHooks` instance — the minimum-blast-radius fix.

2. **Both `ModelToggleHooks`'s `$switcher` and `DoStreamsHooks`'s `$modelToggleHooks` are
   nullable-with-default.** Two independent, PRE-EXISTING (not authored/touched by this story)
   Kernel-test-harness realities forced this:
   - Several `do_streams` Kernel tests (`FollowingFeedTest`, `RankingTest`,
     `MembershipScopeTest`, `FollowingScopeTest`, `StreamShellTest`'s scope-tabs/ranking-control
     cases) enable `do_streams` via an explicit `protected static $modules` allowlist that does
     NOT include `do_showcase` — bypassing `.info.yml`'s dependency-driven auto-enable a real
     site install would perform. Once `do_streams.model_toggle_hooks`'s service definition
     required `@do_showcase.variant_switcher` unconditionally, the WHOLE `do_streams` container
     failed to compile (`ServiceNotFoundException`) for every one of those tests, not just
     mine. Fixed with the standard Drupal/Symfony optional-service-reference syntax
     (`@?do_showcase.variant_switcher`, e.g. `core.services.yml`'s own
     `'@?router.request_context'`) plus a nullable `?VariantSwitcher` constructor parameter,
     guarded on `=== NULL` in both `viewsPreRender()`/`preprocessViewsView()`.
   - `StreamsShellTest::preprocessShellVariables()` (a pre-existing test helper, unrelated to
     the model toggle) directly `new DoStreamsHooks()`-instantiates the class with zero
     arguments to call `preprocessDoStreamsShell()`. Requiring a non-defaulted
     `$modelToggleHooks` constructor argument broke that call site
     (`ArgumentCountError`). Fixed with `?ModelToggleHooks $modelToggleHooks = NULL` + the
     null-safe delegation call (`$this->modelToggleHooks?->preprocessViewsView($variables)`).

   Neither test file was edited (F does not edit tests) — both fixes are entirely production-side.
   On a real site, `do_showcase:do_showcase` is a hard `dependencies:` entry and
   `do_streams.hooks` is always container-built with a real `ModelToggleHooks` instance, so
   neither NULL path is ever exercised in production; it exists solely so pre-existing,
   unrelated tests that predate this story keep passing.

3. **`streamModelOptions()` builds each option explicitly (`id`, then `label`, then `available`
   if present) rather than mutating the machine-spec array via `$spec['label'] = ...`.**
   `streamModelOptionIds()`'s `content` entry carries `['id' => 'content', 'available' =>
   FALSE]` — appending `label` via array mutation would produce key order `id, available,
   label`, but `VariantSwitcherTest::testStreamModelOptions()` pins `id, label, available`
   (PHPUnit's `assertSame()` on nested arrays is key-order sensitive). Caught during the first
   Unit-test run (`Failed asserting that two arrays are identical` diff showed only key-order
   drift, not a value mismatch) and fixed by explicit construction rather than reordering the
   underlying machine spec (which would have broken `directoryLayoutOptionIds()`'s parallel
   shape for no reason).

4. **`ModelToggleHooks` lives in `do_streams`, not `do_showcase`.** Per handoff-A.md advisory
   #1 and the brief's own "Owns" framing: the switcher SERVICE/template/JS stay in do_showcase
   as the reusable framework; the CALLER (the hook set mounting the switcher onto a specific
   page) belongs with the module that owns that page. `do_streams` already owns `/stream` via
   `activity_stream` and its own `DoStreamsHooks` ranking/shell hooks.

## Reuse / extend-vs-new

- **Extended** `VariantSwitcher` (added `streamModelOptions()`/`streamModelOptionIds()` — no
  parallel switcher class). `build()`/`resolveCurrent()`/the render-array assembly are reused
  verbatim, untouched.
- **Extended** `ShowcaseCatalog::entries()` (in-place flip of the existing `stream-model`
  entry — no new catalog entry, no parallel catalog).
- **Extended** `HelpText::all()` (append-only for the new key; in-place correction for the
  pre-existing `showcase_help.stream-model` key per Amendment 1 — no parallel copy store).
- **New class** `ModelToggleHooks` — justified in the brief/A-review as the do_streams-side
  caller for the SC-F1 switcher, mirroring `DoShowcaseHooks`'s two-hook shape as a sibling, not
  a duplicate (A-advisory #2: "shallow structural duplication per instance, not a semantic
  one" — the one object that MUST be single-sourced, `VariantSwitcher`, is reused verbatim).
  A's plan review explicitly approved this shape; no parallel path was created where the brief
  called for extending an existing object.
- **Extended** `DoStreamsHooks::preprocessViewsView()` (added a one-line delegation call after
  its existing following-feed branch) — this is the anti-duplication-driven fix described in
  Design decision #1: Drupal's core constraint (one `preprocess_views_view` listener per
  module) made a parallel `#[Hook]` registration illegal, so extending the existing method with
  a delegation call, rather than duplicating a second registration, was the only correct
  option.
- **Reused verbatim**: `do_showcase/switcher` JS library (zero new JS), the
  `.views-element-container` mirror selector, the `data-do-showcase-mirror-attribute`/
  `-selector` wrapper-mirror contract, `HelpText::get()`.

## Architecture notes for A

- **New Drupal-core constraint surfaced**: `ModuleHandler::invoke()` enforces exactly one
  `preprocess_views_view` listener per module (throws `LogicException` otherwise). This did
  not surface in #124 because `do_showcase` had no pre-existing `preprocess_views_view`
  implementation to collide with. Any FUTURE module that already owns a `preprocess_views_view`
  hook and wants to add a second, independently-scoped switcher/view-preprocess concern will
  hit this same constraint — the delegation pattern here (one method holds the `#[Hook]`
  attribute, dispatches to sibling classes) is the reusable fix, not a table-driven dispatcher
  refactor (A's own advisory #2 already reasoned that refactor is premature at 2 instances).
- **New optional-service-reference pattern in this codebase**: `@?service.id` +
  `?Type $param` constructor nullability, matching `core.services.yml`'s own precedent, used
  here purely to keep several PRE-EXISTING Kernel tests (that predate this story and are not
  authored by it) passing after `do_streams`' container gained a new cross-module DI edge to
  `do_showcase`. Worth knowing this pattern exists if a future story hits the same
  "container-wide DI edge breaks an unrelated Kernel test's narrow `$modules` allowlist" class
  of problem.
- **No schema, route, or migration changes.** `do_streams.info.yml` gained one dependency
  line; no `.routing.yml`/`.permissions.yml`/entity-schema changes anywhere.
- **`views.view.activity_stream.yml` was NOT edited** (per brief/A: the mount is
  hook-pipeline-only, matching #124's proven zero-view-config-edit pattern).

## Deviations from spec / wireframe

None from the wireframe's visible contract (switcher options, tooltip copy, wrapper attribute,
catalog entry) — all match D's approved copy verbatim. The one deviation from the BRIEF's
literal implementation guidance is Design decision #1 (the `#[Hook]`-attribute placement),
which is a correction the brief could not have anticipated (it assumed #124's shape would
transplant unmodified) — flagging for A/O awareness, not as an unapproved scope change; the
externally-observable behavior (switcher renders in `#header`, wrapper attribute, cache
context, libraries) is identical to what the brief/wireframe specify.

## Tier 1 self-check (incl. tests now GREEN)

Assembled via `bash scripts/ci/assemble-config.sh` (run inside the primary checkout's DDEV
container — the worktree has no `vendor/`; DDEV mounts only the primary checkout via Mutagen.
Followed T's documented procedure: temporarily copied the current worktree's `do_showcase`/
`do_chrome`/`do_streams` module trees + `docs/groups/config` + `tests/e2e/model-toggle.spec.ts`
into the primary checkout, assembled/ran there, then FULLY reverted the primary checkout
— `git checkout --`, `git clean -fd`, `rm -rf` for build artifacts — reconfirmed identical to
this session's opening `git status`. No file was authored in the primary checkout; every
production file was written directly to the worktree first).

**Kernel** (`StreamModelTogglePreRenderTest.php`, 8 tests):
```
DDDDDDDD                                                            8 / 8 (100%)
 ⚠ Switcher injected with two options in order   <- pre-existing core Twig-sandbox
   deprecation noise (TwigSandboxPolicy::checkSecurity()), not a failure; identical
   to the deprecation the merged #124 precedent's own renderViewToHtml()-based test
   triggers.
 ✔ View declares url query args variant cache context directly
 ✔ No query param defaults wrapper to activity
 ✔ Activity query param sets wrapper to activity
 ✔ Unavailable content query param falls back to activity
 ✔ Unknown query param falls back to activity
 ✔ Switcher and model toggle libraries attached
 ✔ Hook does not fire for a different view id
Tests: 8, Assertions: 201, Deprecations: 5, PHPUnit Deprecations: 9.
```
0 Failures, 0 Errors.

**Unit** (`StreamModelHelpTextTest.php` + `VariantSwitcherTest.php` + `ShowcaseCatalogTest.php`,
28 tests):
```
Tests: 28, Assertions: 154, PHPUnit Deprecations: 31.
```
0 Failures, 0 Errors — all 28 pass, including all 15 pre-existing `VariantSwitcherTest` cases
and all 9 pre-existing `ShowcaseCatalogTest` cases (unaffected by this story's additions).

**Non-regression (AC-10)** — full `do_streams` + `do_showcase` Unit/Kernel + `do_chrome` Unit
suites, run together:
```
Tests: 125, Assertions: 1921, Deprecations: 24, PHPUnit Deprecations: 139.
```
0 Failures (grep-verified: `grep -c "^ ✘"` = 0). This includes `do_streams`'s full 33-test
suite (`FollowingFeedTest`, ranking tests, membership/following-scope tests,
`StreamsShellTest`'s scope-tabs/ranking-control tests, `StreamsInstallTest` — none authored by
this story, all green), `do_showcase`'s full 43-test Unit suite (including the pre-existing
`GroupsModerateRoleConfigShapeTest` and `DirectoryTogglePreRenderTest`/
`PersonaSwitcherRenderTest` Kernel tests, all green), and `do_chrome`'s full 22-test Unit suite.

**phpcs** (`--standard=Drupal,DrupalPractice`, all 9 touched production files):
- `ModelToggleHooks.php`, `css/model-toggle.css`, `do_streams.libraries.yml`,
  `do_streams.services.yml`, `do_streams.info.yml`, `VariantSwitcher.php`,
  `ShowcaseCatalog.php`: **0 findings** (clean).
- `DoStreamsHooks.php`: 0 errors, 4 warnings — all 4 are pre-existing `\Drupal::service()` /
  `\Drupal::currentUser()` calls on lines I did not touch (`applyLastActivityRanking`/
  `applyHotRanking`/`applyPinnedRanking`/`viewsPostRender`), matching the codebase's existing
  DI-avoidance advisory pattern seen elsewhere in this file already.
- `HelpText.php`: 18 errors + 8 warnings — verified (via `phpcs` run directly against the
  `main`-branch pre-my-changes copy of this file) that this is IDENTICAL pre-existing
  comment/array-indentation house-style debt (19 errors + 6 warnings on `main` at the
  equivalent lines, same "Comment indentation error, expected only 1 spaces" /
  "Array indentation error, expected 6 spaces but found 8" pattern). Confirmed via line-number
  cross-check that ZERO findings land on either of my two new content ranges (lines 180-188,
  293-301) — my additions are clean.

## Tests that look wrong (for T)

None.

## Known issues

None affecting ST-8's scope. One unrelated, pre-existing item observed while running the full
`do_showcase` Unit suite for non-regression: `GroupsModerateRoleConfigShapeTest` (a test from
the already-merged #138 story, not authored/touched by this story) initially appeared to fail
(`admin` flag mismatch) — this turned out to be an artifact of my OWN partial verification-copy
procedure (I had copied only the three module trees into the primary checkout, not
`docs/groups/config`, so the test ran against the primary checkout's stale `main` config).
Once I copied the full `docs/groups/config` tree (needed anyway to fix an unrelated
`views.view.following_feed.yml` staleness issue), this test passed cleanly — confirming it was
never a real defect in the worktree, only a byproduct of the verification harness. No action
needed; noting for transparency since it appeared as a failure mid-session.

## Files changed

- `docs/groups/modules/do_streams/src/Hook/ModelToggleHooks.php` (new)
- `docs/groups/modules/do_streams/css/model-toggle.css` (new)
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (modified)
- `docs/groups/modules/do_streams/do_streams.services.yml` (modified)
- `docs/groups/modules/do_streams/do_streams.info.yml` (modified)
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (modified)
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (modified)
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` (modified)
- `docs/groups/modules/do_chrome/src/HelpText.php` (modified)
