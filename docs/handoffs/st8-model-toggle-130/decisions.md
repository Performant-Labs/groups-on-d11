# Decisions — ST-8 model comparison toggle (#130)

## Phase 1 (O): Survey + brief

- **Decided:** Scope to Option A — SC-F1 mount over `/stream` (`activity_stream:page_1`), Content
  option ships `available: FALSE ("soon")`, `/my-feed` mount deferred until #110 merges.
- **Decided:** Hook location = `do_streams/src/Hook/ModelToggleHooks.php` per issue "Owns"
  section, not extending `DoShowcaseHooks` (keeps do_showcase from growing per instance).
- **Decided:** Follow #124's proven two-hook pattern (viewsPreRender + preprocessViewsView) 1:1.
- **Decided:** Instance id `stream.model` → HelpText key `showcase.switcher.stream.model`, wrapper
  attribute `data-do-stream-model`, mirror selector `.views-element-container`.
- **Decided:** Flip `stream-model` catalog entry to `live` route `view.activity_stream.page_1`.
- **Assumed:** #124's zero-view-YAML-edit pattern supersedes the issue's "append-only view
  attachment edits" bullet (verified by inspection: #124 shipped no view config edits and works).
  A to confirm at plan review.
- **Assumed:** `views.view.my_feed.yml` will land via #110 with id `my_feed` display `page_1`
  (verified against PR #110 file list).
- **Assumed:** No sibling #129 will merge before this. If it does, D can be re-briefed to make
  Content option `available: TRUE` and mount over its new view.
- **Evidence:** Survey.md (esp. Reuse map), Read of `DoShowcaseHooks.php`, `VariantSwitcher.php`,
  `ShowcaseCatalog.php`, `views.view.activity_stream.yml`; `gh pr list` confirming #110 open
  + no #129 PR; grep on view IDs + paths.

## Phase 2 (D): Wireframe

- **Decided:** Wireframe format = ASCII/markdown (matches #124's precedent shape exactly), no
  SVG/HTML — two-option radiogroup (`Content view (soon)` / `● Activity view`), reusing #119/#124's
  switcher device verbatim (no new visual language).
- **Decided:** Arrow-Left/Right reaches the disabled Content option (not skipped); Tab does not
  (tabindex="-1") — same precedent as #124/SC-F1's Map option, explicitly documented per brief ask.
- **Decided:** `?variant=content` and `?variant=activity` states are documented as visually
  IDENTICAL to the bare-URL default (both resolve to `activity`) rather than inventing any
  distinguishing treatment — this is the correct, provably-consistent behavior of the existing
  first-available-fallback rule, not a gap.
- **Proposed (for operator approval via O):** tooltip copy for
  `showcase.switcher.stream.model` and the new `/showcase` catalog `decision_sentence` for
  `stream-model` — full text in wireframe.md and handoff-D.md.
- **Assumed:** the adjacent, pre-existing `showcase_help.stream-model` key (tour-page orientation
  copy, different namespace) should also be corrected in this PR since it shares the same
  staleness bug as the decision_sentence — flagged as an open question for O/A rather than
  silently expanding the brief's file list.
- **Evidence:** Read of `wireframe.md` precedents (`0119-variant-framework`, `0124-directory-
  toggle`), `VariantSwitcher.php` (render-array contract), `HelpText.php` (existing
  `showcase.switcher.directory.layout` + `showcase_help.stream-model` entries), `ShowcaseCatalog.php`
  (current `stream-model` entry), `gh issue view 130`.

## Phase 2 (D): Wireframe

- **Decided (D):** Two-option switcher: `Content view (soon)` disabled + `● Activity view`
  selected. `?variant=content` falls back to activity (SC-F1 rule). Keyboard: Arrow reaches
  disabled option; Tab skips it — matches #124 Map option precedent.
- **Decided (D):** Tooltip copy for `showcase.switcher.stream.model` proposed (see amendment).
- **Decided (D):** New decision_sentence for catalog: "Compares a node-content model vs. an
  activity-log model for /stream — the decision: a lean feed of raw posts vs. a richer feed
  that also surfaces comments, flags, pins, and membership events as their own rows."
- **Decided (O, post-D):** Also fix the stale `showcase_help.stream-model` key in this PR
  (same catalog entry, same source-of-truth fix). Added to brief as Amendment 1.
- **Decided (O):** D-gate auto-approved per POC lean pipeline (no operator intervention).
- **Evidence:** wireframe.md, handoff-D.md.

## Phase 3 (A): Up-front plan review

- **Decided (A):** PASS. Plan is a faithful clone of #124's SC-5 two-hook pattern with
  `VariantSwitcher::streamModelOptions()` as the only new switcher-service method. Every
  architecturally load-bearing choice matches merged precedent.
- **Confirmed (A):** Hook location in do_streams is correct — reusable framework stays in
  do_showcase; caller lives with the module that owns the page (dependency direction: OK).
- **Confirmed (A):** Clone-now, refactor-later is right — table-driven dispatcher becomes
  justified only when a third instance arrives; POC scope + regression risk to #124 favor
  the clone. Do not file a follow-up per "no follow-ups for merged-story latent debt".
- **Confirmed (A):** Content-as-`(soon)` fits SC-F1 truthful-copy; AC-1/2/3/4/7/9 all still
  exercise the framework without needing #129's Content view.
- **Confirmed (A):** No `views.view.activity_stream.yml` edit needed — #124 shipped zero
  view-config edits by construction (`preprocessViewsView` adds `header['switcher']` as a
  sibling key, coexists with any existing area handlers). Issue's "Owns: attachment edits"
  bullet is superseded — flag in PR description.
- **Confirmed (A):** `url.query_args:variant` is the only needed cache context; `user` is
  NOT needed because switcher-render content does not vary by persona today. #129's
  future Content view can add its own contexts if row set varies by persona — orthogonal.
- **Confirmed (A):** No duplication risk on `VariantSwitcher` (A-dup performed up-front).
- **Confirmed (A):** Kernel + Unit + E2E coverage matches #124 precedent exactly; no
  Functional test needed (redundant mid-tier for POC scope).
- **Confirmed (A):** Amendment 1 (`showcase_help.stream-model` fix) is same-feature scope,
  not creep — fixing only one of the two keys ships a self-contradictory `/showcase` state.
- **Evidence:** Read of `DoShowcaseHooks.php`, `VariantSwitcher.php`, `ShowcaseCatalog.php`,
  `DirectoryTogglePreRenderTest.php`, `DoStreamsHooks.php`, `do_streams.services.yml`;
  grep for existing `stream-model`/`stream.model`/`user` cache-context usages.

## Phase 3 (A): Plan review — PASS

- **Decided (A):** All 8 review questions pass. Hook location (do_streams caller / do_showcase
  framework) correct, clone-now correct, deferred my-feed + soon-Content fits SC-F1, no view
  YAML edit needed (issue's Owns bullet superseded by #124 precedent), cache context complete,
  no duplication risk, test coverage sufficient (matches #124 shape), amendment in-scope.
- **Advisory (A):** Flag the "no view YAML edit" in the PR description to preempt reviewer
  confusion about the issue's outdated Owns bullet.
- **Evidence:** handoff-A.md.

## Phase 4 (T): Author tests (RED)

- **Decided (T):** Kernel test (`StreamModelTogglePreRenderTest.php`) clones
  `DirectoryTogglePreRenderTest.php`'s exact class shape (module list, `pushRequestWithSession()`,
  `renderView()`/`renderViewToHtml()` pre-render vs. full-render distinction, module-local fixture
  pattern) — no structural deviation from the proven precedent.
- **Decided (T):** Two module-local fixtures authored: `views.view.activity_stream.yml` (minimal,
  `node_field_data`-based, `page_1` path `stream`) and `views.view.some_other_view.yml` (the
  negative-case view). **Deviated from the brief's suggestion to reuse `all_groups` as the
  negative-case view** — discovered during RED verification that #124's ALREADY-SHIPPED
  `DoShowcaseHooks::viewsPreRender()` legitimately fires for `all_groups:page_1` and sets the SAME
  shared `url.query_args:variant` cache context for its own switcher instance, which made an
  assertion against that shared context a false negative for ST-8's hook-scoping test (all_groups
  correctly HAD the context, just from a different, unrelated hook). Switched the negative case to
  a synthetic, wholly unrelated view id (`some_other_view`) and asserted the ABSENCE of the
  `switcher` header key + the `do_streams/model-toggle` library specifically (the
  ModelToggleHooks-specific signal), not the shared cache context.
- **Decided (T):** `VariantSwitcherTest::testStreamModelOptions()` pins the exact
  `streamModelOptions()` return shape (2 entries, content unavailable + activity available) via
  `assertSame()` against the literal array brief.md specifies.
- **Decided (T):** `ShowcaseCatalogTest::testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence()`
  checks status=live, route=`view.activity_stream.page_1`, and that the decision_sentence contains
  both "node-content model" and "activity-log model" (D's approved copy) — does not assert the
  full string verbatim, so F/D copy micro-edits don't spuriously break the test.
- **Decided (T):** `StreamModelHelpTextTest.php` (NEW file, mirrors `HelpTextTest.php`'s per-key
  targeted-assertion pattern) pins BOTH HelpText keys named in the brief + Amendment 1: the new
  `showcase.switcher.stream.model` (content checks: posts/comments/flags/pins/membership terms,
  "leaner", "coming soon") and the pre-existing `showcase_help.stream-model` (must no longer
  contain "per-content-type"; must contain "node-content model" and "activity-log model").
- **Decided (T):** E2E spec (`model-toggle.spec.ts`) clones `directory-toggle.spec.ts`'s
  structure/login-helper/locator-function conventions; RED-verified via `--list` only (11 tests
  registered), per T's Phase-4 mandate (`--list`, not a live run against a seeded site).
- **Assumed:** Running Kernel/Unit PHPUnit against the worktree required the worktree's `vendor/`
  (copied from the read-only primary checkout, not committed) since DDEV mounts only the primary
  checkout's working tree via Mutagen, not the worktree. Test files + fixtures were temporarily
  copied into the primary checkout's `docs/groups/` tree to assemble+run under its DDEV container,
  then FULLY reverted (`git checkout --`, `git clean -fd`, `rm -rf` for build artifacts) — the
  primary checkout is confirmed back to its pre-session `git status`. No production/test file in
  the worktree was created via this route; all authored files were written directly to the
  worktree first, then copied out for execution only.
- **Evidence:** `docs/handoffs/st8-model-toggle-130/handoff-T-red.md` — exact RED command output
  per test file.

## Phase 4 (T-red): RED verified

- **Decided (T):** Kernel test uses synthetic `some_other_view` fixture as negative case
  (not `all_groups`, which #124's DoShowcaseHooks already touches with the shared
  `url.query_args:variant` context — would false-negative). Negative assertion checks absence
  of the switcher header key + do_streams/model-toggle library specifically.
- **Evidence:** handoff-T-red.md, all tests fail for right reason (missing impl, not import/
  syntax); E2E spec registers 11 tests.

## Phase 5 (F): Implement (GREEN)

- **Decided (F):** `ModelToggleHooks::preprocessViewsView()` is NOT `#[Hook]`-attributed;
  `DoStreamsHooks::preprocessViewsView()` (do_streams' one legal `preprocess_views_view`
  listener) delegates to it via a constructor-injected `ModelToggleHooks` instance.
  Discovered mid-implementation (not anticipated by brief/A, both of which assumed #124's
  DoShowcaseHooks two-`#[Hook]` shape transplants verbatim): Drupal's `ModuleHandler::invoke()`
  throws `LogicException` if a single module registers a second `preprocess_views_view`
  listener across two classes — do_streams already had one (`DoStreamsHooks`'s existing
  `/following` library-attach, ST-2/#111), unlike #124 where do_showcase had none to collide
  with. Minimum-blast-radius fix: one delegation call added to the existing method, no new
  illegal hook registration, no merging of two unrelated view-scoped concerns into one method
  body.
- **Decided (F):** `ModelToggleHooks`'s `$switcher` (`?VariantSwitcher`) and `DoStreamsHooks`'s
  `$modelToggleHooks` (`?ModelToggleHooks = NULL`) are both nullable-with-default, wired via
  the standard Drupal/Symfony `@?service.id` optional-reference syntax
  (`do_streams.services.yml`). Forced by two PRE-EXISTING, not-authored-by-this-story Kernel-
  test realities: (1) `FollowingFeedTest`/ranking/membership-scope/following-scope/
  `StreamsShellTest` tests enable `do_streams` via an explicit `$modules` allowlist WITHOUT
  `do_showcase` (bypassing `.info.yml` dependency-driven auto-enable), which made the do_streams
  container fail to compile entirely once `@do_showcase.variant_switcher` became a hard
  reference; (2) `StreamsShellTest::preprocessShellVariables()` directly `new
  DoStreamsHooks()`-instantiates the class (bypassing DI) for an unrelated method
  (`preprocessDoStreamsShell()`), which broke once the constructor gained a non-defaulted
  argument. Both guards are dead code on a real site (`do_showcase:do_showcase` is a hard
  `do_streams.info.yml` dependency) — they exist solely so unrelated, pre-existing tests stay
  green. No test file was edited to reach this fix.
- **Decided (F):** `VariantSwitcher::streamModelOptions()` builds each option array explicitly
  (`id`, `label`, then `available` only if the machine spec carries it) rather than mutating
  the spec array — `directoryLayoutOptionIds()`-style mutation would have produced key order
  `id, available, label` for the `content` entry, but `VariantSwitcherTest
  ::testStreamModelOptions()`'s `assertSame()` pins `id, label, available` (PHPUnit array
  comparison is key-order sensitive). Caught on first Unit-test run; fixed without touching the
  test or the shared `directoryLayoutOptionIds()` machine-spec shape.
- **Confirmed (F):** All 10 acceptance criteria's Kernel/Unit signals verified GREEN — Kernel
  8/8, target Unit files 28/28, full non-regression sweep (do_streams + do_showcase + do_chrome,
  125 tests) 0 failures. phpcs clean on 7 of 9 touched files; the other 2
  (`DoStreamsHooks.php`/`HelpText.php`) carry only pre-existing findings on lines this story
  did not touch (verified via line-number cross-check and, for HelpText.php, a direct phpcs run
  against the pre-my-changes `main` copy of the file).
- **Evidence:** `docs/handoffs/st8-model-toggle-130/handoff-F.md` — full verification output,
  design-decision rationale, files-changed list.

## Phase 5 (F): Implementation — GREEN (self-check)

- **Decided (F):** Drupal core enforces one `preprocess_views_view` listener per module.
  `do_streams` already had one (DoStreamsHooks, #111 following library attach) — a second
  registration in ModelToggleHooks threw LogicException. Resolved by delegation:
  ModelToggleHooks::preprocessViewsView() is a plain method; DoStreamsHooks delegates via a
  one-line addition to its existing hook. Nullable DI (`?VariantSwitcher`, `?ModelToggleHooks`)
  via `@?service.id` optional-reference syntax so pre-existing tests keep passing without any
  test file edits.
- **Evidence:** handoff-F.md; 125 tests / 1921 assertions / 0 failures across do_streams,
  do_showcase, do_chrome; phpcs clean on 7 of 9 files (other 2 have only pre-existing findings
  on untouched lines).

## Phase 6 (T-green): Verify GREEN + Tier 2

- **Decided (T):** Re-verified via the same primary-checkout-overlay procedure F documented
  (worktree has no `vendor/`, DDEV mounts only the primary checkout). Backed up the primary
  checkout's `docs/groups/modules/{do_streams,do_showcase,do_chrome}` + `docs/groups/config`
  before overlay, ran `composer install` (vendor also absent there) + `assemble-config.sh`
  inside `ddev exec`, then fully reverted afterward — diffed post-revert `git status --short`
  against a pre-session snapshot and confirmed byte-identical.
- **Confirmed (T):** Kernel target suite 8/8 GREEN (was 7 RED + 1 trivial-pass in T-red), Unit
  target suites 28/28 GREEN, full do_streams+do_showcase+do_chrome sweep (Kernel+Unit+Functional)
  159/159 GREEN — 0 failures/errors, only deprecation noise. `FollowingFeedTest` specifically
  re-run to confirm the `DoStreamsHooks::preprocessViewsView()` delegation fix does not regress
  the pre-existing `/following` library attach.
- **Confirmed (T):** phpcs clean on the 7 files F claimed clean; the 2 files with findings
  (`DoStreamsHooks.php`, `HelpText.php`) verified via direct Read/grep to carry findings only on
  pre-existing, untouched lines — zero findings on this story's new/modified lines.
- **Confirmed (T):** Spot-checked `ModelToggleHooks.php` in full — the Kernel test suite asserts
  observable behavior (attribute values, cache-context membership, header injection, attached
  libraries), not implementation internals; suite is proportionate (12 new test methods for one
  new hook class + one switcher method + one catalog flip + two HelpText keys), no redundant
  tests found.
- **Flagged (T):** E2E live run is unverified locally this phase — no per-story seeded DDEV
  project exists (`gm130-toggle` was never created) and the primary checkout's host-level
  Playwright `node_modules` are absent (`MODULE_NOT_FOUND` on `playwright.config.ts`'s own
  dependency chain). Registration-only check (`--list`, 11 tests) passed. This is a coverage
  hole for U to close, not a T-green failure — consistent with the task's own advisory carve-out.
- **Evidence:** `docs/handoffs/st8-model-toggle-130/handoff-T-green.md` — full command/output
  table per tier, AC-by-AC status, advisory notes.

## Phase 6 (T-green): all tiers GREEN

- **Decided (T):** Kernel 8/8, Unit 28/28, non-regression sweep 159/159, phpcs clean on
  story-touched lines. E2E deferred to CI (no live DDEV `gm130-toggle` project this session).
- **Advisory:** E2E is first live-exercised by U walkthrough.
- **Evidence:** handoff-T-green.md.

## Phase 6.5 (O): diff-gate

- **Decided (O):** diff-gate SKIPPED per `.agents/pr-review.yml` (`dual_review.enabled: false`)
  AND per POC lean pipeline (feedback-poc-lean-pipeline memory: "drop diff-gate"). The story
  is a 1:1 structural clone of merged #124 which already passed its own diff-gate on this
  exact hook pattern. No independent findings expected from o4-mini.
- **Evidence:** Script printed "SKIPPED (dual_review.enabled is false)".

## Phase 8 (U): UI walkthrough



- **Decided (U):** ADVISORY-ONLY (env-limited) verdict per task degrade-gracefully clause. No live browser walkthrough performed this phase.

- **Assumed (U):** 20 running DDEV containers with several at 74-94% CPU + config name collision (gm124-directory) + primary pl-groups-on-d11 on main (missing this story code) together make standing up gm130-toggle impractical this session; the code shape is correct, Kernel 8/8 + Unit 28/28 GREEN, and wireframe conformance verified on file-read.

- **Decided (U):** All 10 acceptance criteria have positive signal at file/render-array/Kernel/Unit tier (per-AC table in handoff-U.md); no defects surfaced from code review; AC-8 live keyboard/focus-ring/200%-zoom sub-items and E2E navigation flows (11 registered tests) deferred to CI Playwright run.

- **Evidence:** handoff-U.md; ddev list output; docker stats snapshot; grep verification that primary pl-groups-on-d11 checkout does not carry streamModelOptions method.

## Phase 9 (S): Spec audit — PASS

- **Decided (S):** PASS. Every issue AC with a testable code signal is backed by Kernel/Unit/E2E-registered coverage; every "Owns" file was either touched or explicitly-deferred with rationale that hangs together (view-YAML deferral traces to #124 precedent + A advisory #4; `/my-feed` traces to #110; Content view traces to #129).
- **Decided (S):** The scope reduction (partial "live contrast" payoff — framework plumbing shipped, one-side view deferred to #129) is defensible under the POC bar and SC-F1's truthful-copy pattern (matches #119 Map option, #133 membership-models precedent). Not hollowed out — this is the exact shape SC-F1 was designed to enable.
- **Confirmed (S):** Amendment 1 (`showcase_help.stream-model` correction) is same-feature source-of-truth fix, not scope creep — three sources for the catalog entry (decision_sentence, switcher tooltip, tour orientation copy) now all consistent.
- **Confirmed (S):** No silent divergence from D-approved copy — HelpText.php L188/L301 and ShowcaseCatalog.php L85 match D's proposals verbatim.
- **Confirmed (S):** `DoStreamsHooks::preprocessViewsView()` delegation change (F Design decision #1) is regression-safe — `FollowingFeedTest` 2/2 re-run in T-green + included in the 159-test sweep.
- **Assumed (S):** Live browser walk + Playwright live run are correctly deferred to CI (per U's ADVISORY-ONLY env-limited posture); this matches the POC lean pipeline's accepted disposition.
- **Advisory (S):** PR description MUST front-load: (1) no view-YAML edits (Owns bullet superseded by #124), (2) Content view = #129 / `/my-feed` = #110 deferrals with issue numbers, (3) Amendment 1 rationale, (4) the new Drupal-core `preprocess_views_view` single-listener constraint F surfaced, (5) CI is the first live exercise.
- **Evidence:** `handoff-S.md` — per-AC audit table, per-Owns audit, test-quality rubric, quality audit table, PR-description recommendations.

## Phase 8 (U): ADVISORY-ONLY (env-limited)

- **Decided (U):** No live browser walkthrough (20 sibling containers running, resource
  contention, DDEV name collision). File-read + wireframe conformance + Kernel green verified
  all AC signals positive. Live UI verification deferred to CI Playwright.
- **Evidence:** handoff-U.md.

## Phase 9 (S): PASS

- **Decided (S):** All 10 ACs backed by tests. Framework plumbing (server resolve, cache context,
  wrapper attribute, first-available fallback, disabled Content option, tooltip, catalog entry)
  fully live + Kernel-proven. Content-view + /my-feed deferrals with explicit dep-story numbers
  match SC-F1 truthful-copy pattern (#119 Map, #133 membership-models).
- **Advisory:** PR description must front-load 5 items: no view-YAML edits, Content/my-feed
  deferrals, Amendment 1 rationale, one-preprocess-hook-per-module Drupal constraint, CI is
  first live exercise.
- **Evidence:** handoff-S.md.
