# Handoff-S: Phase 9 — Spec audit, ST-8 Model comparison toggle (#130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Issue:** Performant-Labs/groups-on-d11#130
**Verdict:** **PASS** — safe to PR.

## Preconditions

- **A precondition:** PASS (`handoff-A.md` returned PASS with 8 non-blocking advisories).
- **T precondition:** T-green (`handoff-T-green.md`) reports zero blocking issues (Kernel 8/8, Unit 28/28, non-regression sweep 159/159; only E2E live-run env gap flagged as advisory).
- **U precondition:** U (`handoff-U.md`) returned ADVISORY-ONLY (env-limited) per the task's degrade-gracefully clause — code-shape + Kernel + wireframe conformance verified in lieu of a live browser walk. Consistent with POC lean pipeline; not a blocker per U's own explicit disposition.
- **Visual-diff-tool precondition:** N/A this cycle — no live rendered surface was produced (per U's env-limited posture); Tier 3 visual diff correctly deferred to the CI Playwright run. Not gating PASS here, per the pipeline's own accepted posture for this pass.

## Spec preview sanity check

Issue and brief internally consistent. Only two known internal tensions, both explicitly reconciled upstream:

1. Issue's "Owns" bullet re: `views.view.my_feed.yml` / `views.view.activity_stream.yml` "append-only attachment edits" — superseded by the #124 proven zero-view-YAML-edit precedent (`preprocessViewsView` writes `$variables['header']['switcher']` alongside any existing area handlers). A endorsed (advisory #4), F implemented accordingly, brief and decisions.md both call this out explicitly. **Recommend the PR description surface this explicitly** so a reviewer opening the issue in a fresh tab doesn't hunt for view-YAML edits that never happened.
2. Issue "switcher flips between two models at same scope; Elena's My Feed shows §6b contrast live" — the full "live contrast" payoff cannot land in this PR (Content view = #129; `/my-feed` = #110, neither merged). Partial payoff shipped: framework plumbing (server-side resolve, cache context, wrapper attribute, deep-link, `(soon)` disabled option) is fully live and Kernel-proven; Amendment 1 also fixes the adjacent `showcase_help.stream-model` staleness so `/showcase`'s two keys don't self-contradict. This is the correct "framework proven now, one-line flip later" shape SC-F1 is designed for — same posture as Map option under #119. Not a defect.

No ADVISORY-HOLD warranted — spec is coherent; the "hollowing" concern is defensible under the POC bar (see per-bullet audit).

## Per-issue-bullet acceptance audit

| Issue AC bullet | Evidence (code + test) | Status |
|---|---|---|
| "switcher flips between two models at same scope; Elena's My Feed shows §6b contrast live (ghost-row content in Content view's absence vs social rows present in Activity view)" | Switcher renders on `/stream` with 2 options; only Activity is live-available today (`VariantSwitcher::streamModelOptions()` L155 marks Content `available: FALSE`); `ModelToggleHooks::viewsPreRender()` L172 first-available-fallback resolves any request to `activity`. Kernel `StreamModelTogglePreRenderTest::testSwitcherInjectedWithTwoOptionsInOrder` + `testUnavailableContentQueryParamFallsBackToActivity`. The full Content-vs-Activity live contrast is NOT shipped this PR — Content view is #129, `/my-feed` is #110. | **PARTIAL — deferrable.** Framework plumbing shipped and proven; user-facing "contrast" flip is one dependency-story away. Consistent with SC-F1 truthful-copy precedent (#119 Map option, #133 membership-models). PR description must be explicit. |
| "/showcase lists comparison; tooltip renders; choice persists per session" | `ShowcaseCatalog::entries()` `stream-model` entry: `status: 'live'`, `route: 'view.activity_stream.page_1'`, new decision_sentence (L85). Tooltip: `HelpText::get('showcase.switcher.stream.model')` L188. Session persistence: `do_showcase/switcher` JS reused verbatim (proven under #119/#124). Tests: `ShowcaseCatalogTest::testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence`, `StreamModelHelpTextTest` (2 cases), E2E `model-toggle.spec.ts` covers ⓘ presence + persistence. | **PASS** — all three sub-clauses backed. |
| "existing suite stays green; Playwright spec flips both surfaces both ways" | Non-regression sweep: 159/159 pass (T-green Tier 1 table). Playwright spec authored (11 tests, registration-verified). **Coverage limited to `/stream` only** — `/my-feed` view doesn't exist in main (blocked on #110), so a "both surfaces" flip literally cannot be authored yet. | **PASS with documented scope reduction.** Acceptable per POC lean pipeline: the flip mechanism is fully exercised; adding `/my-feed` is a one-line E2E addition after #110 merges. |
| "HelpText entry (append-only) for any new user-facing surface" | `showcase.switcher.stream.model` appended at HelpText.php L188 (new key). Amendment 1: pre-existing `showcase_help.stream-model` L301 corrected in place (same catalog entry — the append-only contract explicitly permits correction of a semantically-broken pre-existing key when it describes the same feature). | **PASS.** |
| "WCAG 2.2 AA" | Render-array shape (Unit) confirms `role="radiogroup"`, `aria-checked` per option, roving tabindex, `aria-disabled="true"` + `tabindex="-1"` on Content, non-color leading `●` glyph, translatable labels. SC-F1 focus-ring token reused (proven under #119/#124). Live keyboard/focus/zoom deferred to CI Playwright + #145 WCAG audit backstop. | **PASS on shape** (live check deferred per U's advisory-only verdict). |
| "namespaced-docker rendered-DOM check + local `npx playwright test` green" | Both deferred to CI: no per-story `gm130-toggle` DDEV project this session (U handoff §Environmental constraint — 20 running containers, gm124 config-name collision, primary site on main without the story code); host-level Playwright deps absent. Kernel + Unit run via the primary-checkout-overlay procedure T-red/T-green documented, byte-identically reverted. | **DEFERRED to CI** (advisory, not blocker) — CI Playwright runner is the standard gate for this AC per POC lean pipeline. |

## Per-issue-Owns audit

| Owns bullet | Status |
|---|---|
| `do_streams/src/Hook/ModelToggleHooks.php` (new) | Touched — new file, present. |
| `do_streams/css/model-toggle.css` (new) | Touched — new file, CSS-only scoping seam for #129. |
| `tests/e2e/model-toggle.spec.ts` (new) | Touched — 11 tests, `/stream` scope. |
| `views.view.my_feed.yml` / `views.view.activity_stream.yml`: append-only attachment edits | **Superseded** — #124's zero-view-YAML-edit precedent (`preprocessViewsView` writes to `$variables['header']['switcher']` alongside existing area handlers). A endorsed (advisory #4). No `views.view.*.yml` was touched. **Must be flagged in the PR description** — this is the highest-friction spec/implementation mismatch a reviewer will hit. |

## Amendment 1 verification

Both HelpText keys are consistent and coherent:

- `showcase.switcher.stream.model` (L188, new): tooltip copy for the switcher on `/stream`.
- `showcase_help.stream-model` (L301, corrected): the `/showcase` tour orientation copy for the same catalog entry.

Both now describe the "node-content model vs. activity-log model" comparison; the corrected key no longer contains the stale "per-content-type" framing. The `ShowcaseCatalog` `decision_sentence` (L85) uses the same framing. Three sources of truth for the same catalog entry, all now consistent. Amendment scope defensible (same-feature source-of-truth fix, not scope creep) — A already approved (advisory #8).

## Silent-divergence check (wireframe / D vs. code)

- Tooltip copy at HelpText.php L188 matches D's approved copy verbatim (posts/comments/flags/pins/membership + "leaner" + "coming soon"). No drift.
- Decision-sentence at ShowcaseCatalog.php L85 matches D's approved copy verbatim ("node-content model vs. activity-log model" + "lean feed of raw posts vs. a richer feed…"). No drift.
- Two options in order (Content, Activity), Content `available: FALSE`, "(soon)" suffix, `●` glyph on Activity, `data-do-stream-model` wrapper attribute, `.views-element-container` mirror selector — all present in `ModelToggleHooks::viewsPreRender()`/`preprocessViewsView()` and `VariantSwitcher::streamModelOptions()`. Matches wireframe Surfaces 1/2/5 verbatim.

## Regression risk — DoStreamsHooks delegation

F's Design decision #1 changes `DoStreamsHooks::preprocessViewsView()` from a single-purpose (`following_feed` library attach) implementation to a two-branch method: keeps the following-feed branch, then null-safely delegates to `ModelToggleHooks::preprocessViewsView()`. T-green Tier 1 explicitly re-ran `FollowingFeedTest` (2/2 pass) and included it in the 159-test sweep (0 failures). The nullable-DI compromise (`?ModelToggleHooks $modelToggleHooks = NULL`) is production-safe (`do_streams.info.yml` hard-declares `do_showcase:do_showcase`) and exists solely for pre-existing Kernel-test-harness realities. Documented in the DoStreamsHooks class docblock (L38-51) and ModelToggleHooks class docblock (L86-106). Risk: acceptable.

## Test-quality audit (per playbook §7 rubric)

- **Per test:** Each new test names one behavior (`testStreamModelOptions` = return shape; `testSwitcherInjectedWithTwoOptionsInOrder` = render integration; `testViewDeclaresUrlQueryArgsVariantCacheContextDirectly` = cache context; each `?variant=` case = one resolution branch). No mock-shaped or tautological tests. RED evidence in T-red confirms each fails for the right reason when the corresponding behavior is absent.
- **Per suite:** 12 new test methods (8 Kernel + 1 VariantSwitcher unit + 1 ShowcaseCatalog unit + 2 HelpText unit) + 11 E2E for one new hook class, one new switcher-options method, one catalog flip, two HelpText keys. Proportionate — matches #124 precedent's own count almost 1:1.
- **Tier placement:** Cheapest-sufficient: Unit for pure data-shape + string content (`streamModelOptions`, `ShowcaseCatalog` entry, HelpText keys), Kernel for render-pipeline integration + hook scoping, E2E for click-through + persistence. No re-proving across tiers.
- **Smells:** None. The negative-case `testHookDoesNotFireForADifferentViewId` uses a synthetic `some_other_view` fixture to avoid a false-negative against #124's own `all_groups`-scoped hook (documented deviation in T-red, defensible).
- No "delete or merge this test" findings.

## Quality audit

| Area | Result | Notes |
|---|---|---|
| Architecture gate | PASS | A returned PASS; F's post-A `preprocess_views_view` collision fix is correctly documented as a Drupal-core constraint the brief/A couldn't have anticipated, not a plan deviation. |
| Code organization | PASS | Sibling hook class in do_streams (correct module for the caller). Class docblocks thorough and cite the constraints they resolve. |
| Naming consistency | PASS | Instance id `stream.model`, attribute `data-do-stream-model`, HelpText key `showcase.switcher.stream.model`, library `do_streams/model-toggle`, hook service `do_streams.model_toggle_hooks` — all match the wireframe/brief. |
| API consistency | PASS | `streamModelOptions()` matches `directoryLayoutOptions()`'s shape 1:1 as required. |
| Error handling | PASS | Fallback path exercised in Kernel (`testUnavailableContentQueryParamFallsBackToActivity` + `testUnknownQueryParamFallsBackToActivity`); reuses `VariantSwitcher::resolveCurrent()`'s existing rule, no hand-duplicated logic. |
| UI/UX match to spec | PASS on shape (live check deferred to CI). |
| Accessibility | PASS on render-array shape; live keyboard/focus deferred to CI + #145. |
| Security | PASS | No new trust boundary — `?variant=` reuses the existing allowlist-fallback rule; no new route/permission. |
| Performance | PASS | POC efficiency waiver applies (issue explicit); one view-cache-context addition, no new queries. |
| Visual regression | N/A | No design assets touched; SC-F1 CSS focus token reused verbatim. |
| Test quality | PASS | See rubric audit above. |

## Scope check

F delivered exactly the phase scope. Zero over-delivery (no view-YAML edits, no JS added, no CSS beyond the inert `[data-do-stream-model="content"]` seam for #129). Under-deliveries (Content view, `/my-feed` mount) are explicitly deferred with dependency-story rationale (#129, #110) — defensible.

## Findings

None blocking. One PR-description-level advisory (see recommendations).

## PR description recommendations

The reviewer opens the issue and will immediately look for two things that were intentionally not done. The PR body needs to preempt both:

1. **Front-load the "no view-YAML edits" call-out.** The issue's Owns section lists
   `views.view.my_feed.yml` / `views.view.activity_stream.yml` — neither is touched. Explain: the
   #124 SC-5 precedent (merged) proved that `preprocessViewsView` writes to
   `$variables['header']['switcher']` alongside any existing view area handlers without needing a
   view-config export; that same pattern applies here. A endorsed (`handoff-A.md` advisory #4).

2. **State the Content view / `/my-feed` deferrals plainly, with issue numbers.**
   - Content view of `/stream` → #129 (option ships `available: FALSE ("soon")` per SC-F1's
     truthful-copy rule; wrapper + cache context + resolution rule are all live and Kernel-proven
     so #129 becomes a pure `available: TRUE` flip plus the actual Content view markup).
   - `/my-feed` mount → #110 (`views.view.my_feed.yml` does not exist in main; hook's target set
     is `{'activity_stream' => 'page_1'}` today, one-line addition when #110 merges).

3. **Amendment 1 note.** Same-feature append-safe correction of the pre-existing
   `showcase_help.stream-model` HelpText key, so the `/showcase` tour orientation copy and the new
   switcher tooltip / decision_sentence all describe the same (correct) comparison. Not scope
   creep — a self-consistent `/showcase` state would otherwise ship broken.

4. **New Drupal-core constraint surfaced (F Design decision #1).** Mention briefly for future
   authors: `ModuleHandler::invoke()` allows exactly one `preprocess_views_view` per module. The
   sibling-class delegation pattern (one method holds the `#[Hook]`; sibling classes are called
   from it) is the reusable fix.

5. **CI is the first live exercise.** U's walkthrough was ADVISORY-ONLY (env-limited); the CI
   Playwright job against a seeded runner is the first live exercise of the E2E spec + AC-8
   keyboard/focus sub-items. This is the intended posture per POC lean pipeline, not a coverage
   gap being papered over.

## Verdict

**PASS.** All ACs with a testable code signal are backed (Kernel + Unit + E2E-registration). Deferrals (Content view, `/my-feed`, live browser walk) are all traceable to declared dependency stories (#129, #110) or to the CI runner per the POC lean pipeline. No spec drift, no silent divergence from the wireframe, no test-quality smells, no regression risk detected. Amendment 1 is defensibly in-scope. Safe to rebase + push + open PR.
