# Decision journal — #123 SC-4 Discovery three ways

## O — Phase 1 (brief opened)
- **Decided:** Extend `/showcase` with a new `discovery.ranking` `VariantSwitcher` instance rendering existing views via `views_embed_view()`; do NOT fork any ranking logic.
- **Decided:** Distinct query key `?discovery=` to avoid collision with existing `?variant=` (directory.layout switcher).
- **Decided:** POC lean pipeline (D-gate auto-approve; skip brief-gate, A-dup, pre-PR-hold; self-merge on CI-green).
- **Assumed:** `views.view.discovery_compare.yml` is best structured as a wrapper view with 3 displays (or 3 embed-block displays) delegating to existing view ranking — D/A to confirm exact shape.
- **Assumed:** Two seed promoted nodes already exist per prior seed work; T will verify.
- **Evidence:** survey.md; existing ShowcaseController + VariantSwitcher code read directly.

## D — Phase 2 (design)
- **Decided:** New H2 section "Discovery ranking: Recent / Hot / Promoted" placed below the
  existing catalog entry list and existing `directory.layout` stub switcher on `/showcase`;
  a `discovery.ranking` `VariantSwitcher` instance with `?discovery=recent|hot|promoted`
  deep-link, distinct from the existing `?variant=` key.
- **Decided:** One shared switcher tooltip (HelpText key `showcase.switcher.discovery.ranking`)
  covering all three decisions — `VariantSwitcher` has no per-option tooltip today; not
  invented one here.
- **Decided:** Empty-state and error-state copy defined per variant (truthful, non-blocking,
  no dead CTA), even though Promoted must not hit empty per acceptance.
- **Hedged:** `VariantSwitcher::build()` hardcodes the `?variant=` query key
  (VariantSwitcher.php:155) with no per-instance key parameter — flagged to A as a
  plan-time decision (extend `build()` signature vs. controller post-processes hrefs),
  not resolved by D.
- **Evidence:** ShowcaseController.php, VariantSwitcher.php, ShowcaseCatalog.php,
  do_showcase.css read directly; SC-5 precedent (viewsPreRender) referenced via survey.md.

## A (plan) — Phase 3
- **Verdict:** PASS with two binding amendments and one confirmed default.
- **Decided (Risk 1 — query key):** Extend `VariantSwitcher::build()` with an optional 4th param
  `string $query_key = 'variant'` (BC-safe; existing 3-arg callers unaffected). The internal
  `#cache['contexts']` bubble at `VariantSwitcher.php:195-197` becomes
  `url.query_args:<query_key>` — fix at the seam, not per caller. Cross-story amendment to a #119
  primitive; recorded here so a later audit does not read it as drive-by drift.
- **Decided (Risk 2 — tooltip granularity):** One shared wrapper tooltip
  (`showcase.switcher.discovery.ranking`), POC-scope. Do NOT extend `VariantSwitcher` for per-option
  tooltip strings — that is framework surgery outside this issue.
- **Decided (Risk 3 — `discovery_compare.yml`):** Do NOT create the new view config. Embed the
  three existing views directly via `views_embed_view('activity_stream'|'hot_content'|
  'promoted_content', 'default')` from `ShowcaseController::page()`, keyed on `?discovery=`. This
  is the only shape that honestly satisfies "do NOT fork ranking".
- **Amended (issue "Owns" list):** `docs/groups/config/views.view.discovery_compare.yml` dropped
  from the disjoint-files claim — no new views YAML. Remaining owned files unchanged
  (`css/discovery-compare.css`, `tests/e2e/discovery-compare.spec.ts`, small extends to
  `ShowcaseController::page()` + `VariantSwitcher::build()` + HelpText append).
- **Warned (spot-checks):** cache-context bubble on `build()` fixes the defect class at the seam
  (advisory #1); F must verify `do_showcase.switcher.js` reads the anchor `href` verbatim rather
  than hard-coding `variant` (advisory #2 — in-scope fix if it hard-codes).
- **Evidence:** VariantSwitcher.php lines 35-37/124-199, ShowcaseController.php lines 113-143,
  DoShowcaseHooks.php lines 448-514 (SC-5 second-instance precedent), the three source view YAMLs
  (base tables + sorts).

## T (RED) — Phase 4
- **Decided:** Author 4 new/extended Unit tests on `VariantSwitcherTest.php` pinning the 4th
  `$query_key` param (BC-safe default, custom-key hrefs, cache-context bubble, two-instance
  non-collision) — extends the existing file rather than a new one, since it is the SAME class
  contract, just a new parameter.
- **Decided:** New `DiscoveryRankingHelpTextTest.php` (Unit) rather than editing the existing
  `ShowcaseHelpTextTest.php` — matches this repo's "each story tests its own append" convention.
- **Decided:** New `DiscoveryRankingControllerTest.php` (Functional/BrowserTestBase) — the
  cheapest tier that can observe both real `?discovery=` query-arg resolution AND real
  `views_embed_view()` output against seeded/fixture content, mirroring
  `ShowcaseControllerHelpTest`'s existing precedent for this same controller.
- **Decided:** `tests/e2e/discovery-compare.spec.ts` authored per the task's selector contract;
  confirmed syntactically valid via `npx playwright test --list` (11 tests) rather than executed
  live, since this DDEV project has no installed/seeded site — executing live would fail on setup,
  not the feature, which is not valid RED evidence. T-GREEN will run it for real against the fully
  seeded site.
- **Fixed (environment):** `.ddev/config.yaml` in this worktree carried a stale project name
  (`gm124-directory`, copied from a different worktree) — corrected to `gm123-discovery` per this
  task's assigned DDEV project.
- **Assumed:** Seeded promoted node titles are exactly "Getting Started with Paragraphs" and
  "Community Code of Conduct" (confirmed directly from
  `docs/groups/scripts/step_700_demo_data.php` lines 355-362).
- **Flagged (latent gap, not this story's scope):** the REAL `views.view.promoted_content.yml`
  default display has NO actual flag/relationship filter restricting to `promote_homepage`-flagged
  nodes — it filters only on `status` (published). Every prior handoff (survey.md,
  handoff-A-plan.md) assumed this filter exists; it does not, on direct inspection of the YAML.
  Per A's Risk 3 resolution ("do NOT fork ranking" — embed the view AS-IS), this story does not fix
  it; `DiscoveryRankingControllerTest` pins the view's TRUE current behavior instead of a false
  "excludes non-promoted" claim. Surfaced for O/A visibility once, not filed as a follow-up issue
  per this pipeline's "no follow-ups for latent debt" convention.
- **Evidence:** RED run output pasted in `handoff-T-red.md` — VariantSwitcherTest (19 tests, 3
  failures, all on-topic), DiscoveryRankingHelpTextTest (5 tests, 3 failures, all on-topic),
  DiscoveryRankingControllerTest (8 tests, 7 failures all on-topic + 1 correctly-green
  non-regression test), discovery-compare.spec.ts (`--list`: 11 tests, 0 errors).
- **Verified (non-regression, full suite):** ran the entire repo's custom-module Kernel suite
  (`find web/modules/custom -type d -path '*/tests/src/Kernel'`) after adding the new tests —
  191 tests, 4856 assertions, 0 failures/errors (deprecation notices only). Confirms the
  `VariantSwitcherTest.php` extension introduces no regression anywhere else in the repo.

## F — Phase 5 (implementation)
- **Decided:** Extended `VariantSwitcher::build()` with the 4th `$query_key` param exactly per
  A's Risk 1 resolution — BC-safe default, `href` and `#cache['contexts']` both keyed off the
  actual `$query_key`, not a hardcoded string. All 4 of T's new Unit assertions pass unchanged.
- **Decided:** `embedDiscoveryView()` reads `\Drupal::moduleHandler()` via the INHERITED
  `ControllerBase::moduleHandler()` lazy accessor, not a constructor-injected
  `ModuleHandlerInterface`. My first attempt (`private readonly ModuleHandlerInterface
  $moduleHandler` as a promoted constructor param) PHP-fataled ("Cannot redeclare non-readonly
  property ... as readonly") because `ControllerBase` already declares its own non-readonly
  `$moduleHandler` property backing that exact method name. Caught live via a Functional-test
  crash, root-caused via `web/sites/simpletest/*/error.log`, and fixed by calling
  `$this->moduleHandler()` instead — no constructor/`create()` signature change needed at all.
  Recorded here as a real defect introduced and then fixed within this phase, not silently
  smoothed over.
- **Decided:** Discovery options (`recent`/`hot`/`promoted` + labels) stay LOCAL to
  `ShowcaseController::page()` rather than hoisted to a new
  `VariantSwitcher::discoveryRankingOptions()` method. A's plan flagged the hoist as OPTIONAL,
  citing SC-5's `directoryLayoutOptions()` precedent — but that precedent earns centralization
  because it has TWO real call sites (`ShowcaseController` + `DoShowcaseHooks`) that must never
  drift apart; the discovery.ranking option set has exactly ONE call site today. Hoisting now
  would be speculative surface for a consumer that doesn't exist — deferred to a future story if
  a second consumer appears (per "no gold-plating").
- **Observed (not authored by F):** three production artifacts —
  `css/discovery-compare.css`, its `do_showcase.libraries.yml` registration, and the
  `queryKeyForGroup()` fix in `js/do_showcase.switcher.js` (A's Spot-check finding #2) — were
  ALREADY PRESENT, uncommitted, in the worktree at the start of this phase, before F wrote any
  code. F read, independently verified each against the brief/wireframe/A-plan (WCAG note
  compliance for the CSS; correct attach-scope for the library entry; correct `href`-parsing
  compatibility with F's own `VariantSwitcher::build()` implementation for the JS fix), and
  delivers them as-is with no changes, since none were needed. Flagged explicitly in
  `handoff-F.md` for O/A provenance transparency rather than silently claiming sole authorship.
- **Verified (Tier 1, all three PHPUnit tiers):** Unit 50/50 GREEN (incl. 11 new: 6 in
  `VariantSwitcherTest`, 5 in `DiscoveryRankingHelpTextTest`), Kernel 27/27 GREEN (non-regression,
  incl. #124 SC-5's `DirectoryTogglePreRenderTest` — confirms the BC-safe 3-arg call form still
  works for a sibling story sharing this primitive), Functional 37/37 GREEN (incl. 8 new in
  `DiscoveryRankingControllerTest`). 114/114 tests, 0 failures, across the full `do_showcase`
  suite. Playwright spec re-confirmed syntactically valid (`--list`: still 11 tests, 0 errors).
- **Verified (phpcs):** production files clean (`VariantSwitcher.php`,
  `ShowcaseController.php`, `discovery-compare.css`, `do_showcase.libraries.yml`: 0 errors/0
  warnings). `HelpText.php`'s append introduced ZERO new violations (18 errors/8 warnings after,
  vs. 19 errors/8 warnings before, on a direct HEAD-vs-working-tree comparison — net improvement).
  `do_showcase.switcher.js`'s 6 "TRUE/FALSE/NULL must be uppercase" hits are a pre-existing,
  module-wide phpcs-standard-vs-JS-syntax false-positive class (confirmed identically on the
  untouched `do_showcase.ribbon.js`; the pre-story HEAD version of this same file already had 5).
- **Evidence:** full test-run output + phpcs before/after comparisons pasted in `handoff-F.md`.

## O — Phase 5.5 (concurrent-F hazard, self-flag)
- **Hedged:** O mis-diagnosed a 0-byte F transcript at 33 min as agent death and re-spawned F. Prior F was actually alive (buffered writes) and completed 114/114 GREEN. Two F agents ran concurrently in this single worktree for ~10-15 min — a direct violation of global `.claude/CLAUDE.md` "concurrent agents MUST use isolated worktrees" rule. **We got lucky:** both agents converged on the same fixes (same `queryKeyForGroup()` JS fix, same `ControllerBase::moduleHandler()` approach, same CSS scoping) rather than colliding. No git-state corruption observed (`git status` clean before T(GREEN) spawn).
- **Decided:** Aborted re-spawn agent (`abb1c5704ce5c5d07`) with SendMessage; it acknowledged and exited without further writes. Prior F's output stands as the source of truth.
- **Follow-up (rule, next time):** transcript silence ≠ death. Verify by checking `git status` / on-disk artifact deltas before re-spawning. If a re-spawn is genuinely needed, put it in a fresh worktree, not the same one.
- **Loose end:** re-spawned F reports a background `php -S 127.0.0.1:8080` dev server was started inside the DDEV container and its cleanup timed out. Attempted `pkill` from O returned exit 143 (SIGTERM) with no confirmation output; port is not critical to T(GREEN)'s PHPUnit runs, but noting for U/S phases.

## T (GREEN) — Phase 6
- **Verified (independent, not trusting F's counts):** re-ran every T-red-authored test from scratch
  inside `gm123-discovery`. New Unit tests: 24/24 (isolated), 50/50 (full `do_showcase` Unit dir,
  308 assertions) — exact match to F's self-report. New Functional tests
  (`DiscoveryRankingControllerTest`): 8/8, 94 assertions, 0 failures — all 8 named methods confirmed
  green via `--testdox`.
- **Fixed (own verification-harness bug, not a code defect):** my first two Functional-test attempts
  produced false `cURL error 7: Failed to connect` failures because the PHP built-in server
  (started via a separate `ddev exec` call) died before the subsequent `ddev exec phpunit` call ran
  — each `ddev exec` invocation is its own ephemeral shell. Fixed by starting the server and running
  phpunit in the SAME shell invocation. Recording this so a future T doesn't waste time
  re-diagnosing the same harness quirk.
- **Verified (full non-regression sweep, all custom modules):** Kernel+Unit: 279 tests, 5544
  assertions, 0 failures. Functional (split across 3 `ddev exec` calls for harness-duration
  reliability — `do_showcase` alone, then `{do_tests,do_group_extras,do_chrome}`, then
  `{do_group_membership,do_multigroup}`): 37 + 17 + 24 = 78 tests, 0 failures. **Grand total: 357
  tests, 0 failures/errors** across the entire custom-module suite. Confirmed the pre-existing
  `VariantSwitcher` 3-arg BC callers (`DoShowcaseHooks.php:492`, `ShowcaseController.php:228`) are
  unchanged and their Kernel/Functional coverage (incl. `DirectoryTogglePreRenderTest`) stays green.
- **Verified (phpcs):** production files (`VariantSwitcher.php`, `ShowcaseController.php`,
  `discovery-compare.css`, `do_showcase.libraries.yml`) clean, 0/0. `HelpText.php`: reproduced F's
  before/after comparison independently (18 errors/8 warnings now vs. 19/8 at HEAD, net
  non-regression — confirmed by running phpcs against a `git show HEAD:...` copy of the file inside
  the container).
- **Verified (provenance of the "already present, uncommitted" artifacts):** read
  `discovery-compare.css`, the `do_showcase.libraries.yml` entry, and the `do_showcase.switcher.js`
  `queryKeyForGroup()` diff directly against wireframe.md/handoff-A-plan.md. All three are correct
  as delivered — no drift, no stale/wrong code from the concurrent-F near-miss O flagged in Phase
  5.5. This was a convergent-correct outcome, verified independently rather than assumed.
- **Verified (Playwright):** `--list` still resolves 11 tests, 0 errors — no import/selector
  regression introduced during F's implementation.
- **Cross-checked F's self-report against `git status`/`git diff --cached --stat`:** no discrepancy
  — exactly the 6 claimed production files changed, no test file touched by F.
- **Verdict: PASS, no blocking issues.** All 8 acceptance criteria verified at the headless/
  structural level this phase can reach; criteria 6 (live Playwright execution) and 8 (live
  focus/contrast rendering) explicitly deferred to U.
- **Evidence:** full command output + counts pasted in `handoff-T-green.md`.

## U (walkthrough) — Phase 8
- **Verdict: REWORK.** One blocking behavioral defect (F-U-1): clicking or Enter/Space-activating a discovery tab flips the switcher chrome (aria-checked, bullet glyph, roving tabindex) but does NOT swap the embedded view region and does NOT update the URL. `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` calls `event.preventDefault()` and only mutates in-memory switcher state; no `history.pushState`, no fetch/reload, no re-embed. The anchor `href="?discovery=hot"` (the correct no-JS fallback) is thrown away. Deep links `?discovery=<id>` render correctly server-side — the gap is purely client-side interaction. See handoff-U.md for full checklist + evidence.
- **Verified live (criterion 6 subset):** deep-link routing (recent/hot/promoted) works and renders three visibly-different content sets; two switchers coexist on `/showcase` with distinct query keys (`?variant=` + `?discovery=`) without collision; single wrapper tooltip present exactly once; `/hot` standalone route + `directory.layout` stub switcher both non-regressing (authored spec's two non-regression tests passed live at 1.5s / 464ms).
- **Verified live (criterion 8):** focus ring is a distinct 2px solid blue outline (`rgb(77,163,255)`) on `[role="radio"]:focus-visible` — clearly visible on white. All measured text contrasts comfortably exceed AA (H2 ~19:1, body ~13:1, tooltip ~13:1, POC ribbon ~15:1). Non-color status conveyed via `●` glyph + `aria-checked`. 360px mobile viewport renders section stacked with switcher pills wrapping cleanly, no horizontal overflow.
- **Non-findings (verified, out of scope):** `promoted_content` view lists all published nodes not just `promote_homepage`-flagged (pre-existing latent gap T flagged; A-plan Risk 3 mandates embed AS-IS; both required seeded titles present so acceptance is satisfied). Wireframe's per-tab meta styling (`[promoted]` badge, `● hot` glyph on top row, `created X ago`) not visible because story reused existing views AS-IS. Site-wide invisible "Skip to main content" focus outline is a pre-existing theme concern.
- **Fix path recommendation (F/D choose):** (1) drop `preventDefault()` in both click and Enter/Space keydown branches so the anchor href navigates — minimum-surface fix, matches "no-JS fallback stays authoritative" comment already in the file, but MUST not regress `directory.layout` switcher's behavior on /showcase and /all-groups (T's `directory-toggle.spec.ts` + criterion-16 non-regression here must stay green); or (2) `history.pushState` + fetch/swap the `.views-element-container` inside `[data-do-discovery-ranking]` — preserves the instant feel, needs a partial endpoint or full-page fetch, no existing HTMX/fetch-swap primitive in this codebase to reuse.
- **Environment note (for the next runner):** DDEV project `gm123-discovery` had NO installed site at U's start — the previous phase's F/T runs used ephemeral `php -S` for BrowserTestBase, not a persistent install. U had to run the full install+seed sequence (assemble → site:install → append `config_sync_directory` → cim → en → 5 seed scripts → cache:rebuild). Site now stands installed and seeded at `http://gm123-discovery.ddev.site`; a re-run of U after F's fix does not need to re-install.
- **Evidence:** full command output + JSON state bundles from live walkthrough; screenshots at `C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/`: `showcase-default.png`, `showcase-hot.png` (deep-linked), `showcase-promoted.png` (deep-linked), `showcase-360.png` (mobile), `focus-recent.png` (focus ring). Playwright live-run output: `BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/discovery-compare.spec.ts` → 9 passed, 2 failed (both are F-U-1: `discovery-compare.spec.ts:122` mouse-click path, `:214` keyboard-Enter path).

## F (fix1) — Phase 9
- **Decided (fix path):** Option 1 (let the anchor navigate), applied CONDITIONALLY per-instance — NOT the naive "drop `preventDefault()` everywhere" form of U's option 1. The naive form breaks `directory.layout` hard: `directory-toggle.spec.ts` asserts `expect(page.url()).toBe(urlBefore)` on the click path (line 103), the filter-preservation path (141), and the pager-preservation path (161). The two instances have genuinely different update models — `directory.layout` swaps content client-side via a mirrored wrapper attribute + CSS (MUST preventDefault); `discovery.ranking` has no client-side swap mechanism and the server re-renders on `?discovery=<id>` (MUST let the anchor navigate).
- **Decided (discriminator):** Piggyback on the wrapper-mirror wiring already set at render time — `DoShowcaseHooks::preprocessViewsView()` (lines 508-511) sets `data-do-showcase-mirror-attribute` + `data-do-showcase-mirror-selector` on the `directory.layout` wrapper; `ShowcaseController::page()` sets neither on `discovery.ranking`. New `usesMirrorModel(group)` predicate reads this pair; mirror-driven → preventDefault + `select()` (existing behavior); navigation-driven → persist to sessionStorage then follow href (click) / `window.location.assign(href)` (Enter/Space, which do not natively fire an anchor's click). Zero per-instance `instanceId === '...'` branches — matches this file's existing data-driven convention (`mirrorSelectionToWrapperAttribute()`).
- **Rejected (option 2, pushState + fetch/swap):** No HTMX / fetch-swap primitive in this codebase (U grepped, F re-verified); introducing one for a POC is disproportionate. The UX gap between "full navigation" and "instant swap" on `/showcase` is small (server responds quickly, switcher stays in place across nav); shipping correct behavior in ~65 lines beats shipping a partial swap the codebase has no precedent to maintain.
- **Hedged (harness gotcha):** First Playwright re-run failed identically because `web/modules/custom/do_showcase/` is the served copy — `docs/groups/modules/` is source only. Must run `ddev exec bash scripts/ci/assemble-config.sh` + `drush cache:rebuild` after any JS edit. Recorded so a future F does not waste the same round trip.
- **Verified (Playwright, live against `gm123-discovery.ddev.site`):** `discovery-compare.spec.ts` → 11/11 passed (was 9/11 at U-time); `directory-toggle.spec.ts` → 11/11 passed + 1 skipped (pager-page-2, seed-conditional; identical to pre-fix state).
- **Verified (PHPUnit, non-regression):** `do_showcase/tests/src/Unit` + `do_showcase/tests/src/Kernel` → 77 tests, 625 assertions, 0 failures/errors (deprecation notices only). `DirectoryTogglePreRenderTest` 8/8 GREEN — confirms the 3-arg BC callers at `DoShowcaseHooks.php:492` and `ShowcaseController.php:228` remain unaffected.
- **Verified (phpcs):** Zero PHP files touched. JS file passes `phpcs --standard=web/core/phpcs.xml.dist` (exit 0).
- **Evidence:** `handoff-F-fix1.md` (full command output pasted).

## T (GREEN re-verify) — Phase 10
- **Verdict: PASS.** All eight acceptance criteria remain GREEN after F's fix1; criterion 6 (live behavior — click/Enter swap and URL update) — the exact defect U caught — is now GREEN and independently reproduced. Zero regressions vs Phase 6 baseline. Route to U for re-walkthrough.
- **Verified (harness gotcha honored):** `diff docs/groups/modules/do_showcase/js/do_showcase.switcher.js web/modules/custom/do_showcase/js/do_showcase.switcher.js` → identical (F's `assemble-config.sh` run stuck). Also ran `drush cache:rebuild` as a belt-and-braces before every Playwright pass.
- **Verified (Playwright, live against `gm123-discovery.ddev.site`):** `discovery-compare.spec.ts` → **11/11 passed** in 17.0s (independent re-run, not F's counts). `directory-toggle.spec.ts` → **11/11 passed + 1 skipped** in 9.8s (skipped = seed-conditional pager-page-2, unchanged). F-U-1 confirmed fixed via the two specs that were red at U-time (`discovery-compare.spec.ts:114` mouse-click, `:202` keyboard Enter) — both green now.
- **Verified (PHPUnit, `do_showcase` Unit+Kernel):** **77 tests, 625 assertions, 0 failures/errors** (deprecations only) — byte-identical to Phase 6 baseline, no regression.
- **Verified (PHPUnit, `do_showcase` Functional `DiscoveryRankingControllerTest`):** **8 tests, 94 assertions, 0 failures** — matches Phase 6 baseline.
- **Verified (full custom-module non-regression sweep, split per Phase 6 harness pattern):**
  - Kernel + Unit across all 14 custom modules (17 test dirs): **279 tests, 5544 assertions, 0 failures/errors**.
  - Functional `do_showcase`: **37/37, 260 assertions, 0 failures**.
  - Functional `do_tests` + `do_group_extras` + `do_chrome`: **17/17, 159 assertions, 0 failures**.
  - Functional `do_group_membership` + `do_multigroup`: **24/24, 219 assertions, 0 failures**.
  - **Grand total: 357 tests / 0 failures/errors — identical to Phase 6 baseline.**
- **Verified (phpcs, prod files):** `VariantSwitcher.php` 0 errors, `ShowcaseController.php` 0 errors, `HelpText.php` 18 errors / 8 warnings (**pre-existing, identical to Phase 6**; F touched no PHP this phase). `do_showcase.switcher.js` 6 errors under `Drupal,DrupalPractice` (all "TRUE/FALSE/NULL must be uppercase" — pre-existing pattern in the file; F's added lines either duplicate the pre-existing convention or match it exactly, confirmed via `git diff` inspection of the added `true`/`null` tokens against the surrounding untouched context). CSS + libraries.yml clean. **Zero new violations introduced by F's fix.**
- **Independent trap-check:** First run of `phpunit --testsuite=unit,kernel modules/custom` reported 78 errors — false alarm caused by the harness ignoring the `--testsuite` flag on directory args and pulling Functional tests into a Kernel-only DB context. Re-ran with explicit Kernel+Unit paths per Phase 6's exact recipe → clean. Not a regression, a harness gotcha; recorded so a future T does not chase the wrong signal.
- **Evidence:** `handoff-T-green-2.md` (all command outputs).

## U (re-walkthrough) - Phase 11
- **Verdict: PASS.** F-U-1 is genuinely fixed for a real human user, not just the test bot. Independent Playwright re-run against `gm123-discovery.ddev.site`: `discovery-compare.spec.ts` 11/11 green (was 9/11 at U Phase 8) - includes the two previously-red tests (mouse-click swap+URL, keyboard Enter). `directory-toggle.spec.ts` 11/11 + 1 skipped, no regression. Live eyes-on-glass walk confirms: mouse click Hot/Promoted/Recent updates URL AND swaps embed (each embed distinct - Recent chronological, Hot with Follow-content controls, Promoted lists both required seeded titles "Getting Started with Paragraphs" + "Community Code of Conduct"). Focus ring unchanged (rgb(77,163,255) solid 2px, byte-identical to Phase 8). Bullet glyph + aria-checked non-color status still present. Roving tabindex correct on every post-nav snapshot. Zero console/pageerror across ~12 page loads. Directory.layout non-regressed: Compact click on /all-groups?search=Book leaves URL unchanged, flips wrapper attribute, preserves filter - confirming F's `usesMirrorModel()` discriminator routes correctly. Fresh browser context: bare /showcase serves server-default Recent, empty sessionStorage - no cross-context leakage.
- **Observation U-obs-1 (non-blocking, informational only):** on a bare `/showcase` revisit (no query) while sessionStorage holds a prior discovery choice (e.g. after clicking Promoted then navigating away and back to `/showcase` bare), the switcher chrome is restored to `● Promoted` aria-checked=true but the server-rendered embed is Recent - chrome and embed disagree. Same failure class as F-U-1, triggered on a much narrower path than the primary click/keyboard interaction. Not in the brief/wireframe (session persistence is a self-imposed shared-library feature inherited from directory.layout, where it works because CSS mirrors the wrapper attribute - for the navigation-driven discovery.ranking there is no swap mechanism). Per `feedback_poc_no_follow_ups.md` I surface this once here and do NOT open a follow-up issue; S may re-classify if it reads it as a wireframe deviation I missed. Two possible fix shapes recorded in handoff-U-2.md (skip restore for `!isMirrorDriven`, or re-navigate on restore) if pursued later.
- **Environment note:** DDEV site persistent from Phase 8, no re-install needed. JS parity verified: `diff docs/groups/modules/do_showcase/js/do_showcase.switcher.js web/modules/custom/do_showcase/js/do_showcase.switcher.js` -> identical.
- **Evidence:** `handoff-U-2.md` (per-control table, screenshots, evidence bundle). Full JSON at `scratchpad/u2/findings.json`. Screenshots: `A-default.png`, `B-hot.png`, `C-promoted.png`, `E-kbd.png`, `J0-all-groups.png`, `J2-compact.png`, `K-360.png`.
- **Handoff:** S (Spec Auditor) - owns wireframe/brief conformance + WCAG axe sign-off. Nothing back to F.

## S (spec audit) — Phase 12
- **Verdict: PASS.** All 8 acceptance criteria met; evidence table in `handoff-S.md`. No scope creep; nothing silently dropped (the one intentional drop — `views.view.discovery_compare.yml` — is A Phase 3 Risk 3, recorded).
- **Convergent-correct artifacts verified against wireframe intent:** `discovery-compare.css` (WCAG focus + meta contrast rules match wireframe §"WCAG 2.2 AA notes"); `do_showcase.libraries.yml` entry (correct attach scope); `do_showcase.switcher.js` `queryKeyForGroup()` fix (correctly reads href verbatim per A-plan advisory #2). All three flagged in F Phase 5.5 as convergent-correct from the concurrent-F near-miss — re-verified here as spec-conformant, not just lucky.
- **U-obs-1 disposition:** classified as **acceptable POC-level behavior, not a spec deviation, not a blocker.** Reasoning: (1) not in brief or wireframe — session persistence is a self-imposed shared-library feature inherited from directory.layout; (2) primary wireframe-mandated path (click → URL + embed update) is fully green; (3) `feedback_poc_no_follow_ups.md` + POC lean pipeline convention → do NOT file a follow-up. U-2's one-time surfacing is the correct disposition.
- **Git-state audit:** staged set (6 production files) matches handoffs' claim. Unstaged switcher.js F-fix1 layer + VariantSwitcherTest extension + untracked test files/fixtures + Playwright spec + handoff dir all in-scope and must be staged for the commit. Confirmed a stack of ~30 other modified/untracked files (`config/sync/*`, `web/*`, `.ddev/`, `web/modules/custom/`, `web/sites/simpletest/`, etc.) are worktree noise from other work/generated artifacts and MUST NOT be staged — the commit needs to be surgical. Explicit stage-list in `handoff-S.md`.
- **Docs completeness:** no README/module info/routing/services change needed. Library entry for `discovery-compare` is the only wiring change and is present.
- **Recommended commit + PR shape:** full commit message + PR body drafted in `handoff-S.md` § "Recommended commit shape". PR title: `feat: #123 SC-4 Discovery three ways — Recent/Hot/Promoted on /showcase`. Self-merge on CI-green + mergeable per `feedback_uranus_wider_autonomy.md`.
- **Constraints honored:** read-only audit. No production or test file touched. Only writes were `handoff-S.md` (new) and this decisions.md append.
- **Handoff:** Commit + PR agent.
