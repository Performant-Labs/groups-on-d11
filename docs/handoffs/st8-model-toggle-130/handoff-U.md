# Handoff-U: Phase 8 - ST-8 Model comparison toggle (#130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Issue:** #130
**Verdict:** ADVISORY-ONLY (env-limited) - proceed to S.

## Environmental constraint (documented; not a REWORK)

Per the task explicit degrade-gracefully clause: no live browser walkthrough was performed this phase. The gm130-toggle DDEV project did not exist and standing one up was impractical in this session environment:

- ddev list shows 10 running project pairs (20 web+db containers) already up. Live docker stats snapshot showed several sibling containers pegged at high CPU (gm112-events-web 86%, gm129-activity-web 74%, gm145-wcag-web 94%). Spinning an eleventh project with a fresh composer install + drush site:install + drush cim + seed-step overlay would meaningfully risk destabilizing the sibling worktrees currently in flight.
- The worktree .ddev/config.yaml inherits name gm124-directory (from the branch base commit), which collides with the already-running gm124-directory project - a rename + fresh ddev start was required before any live check.
- The primary pl-groups-on-d11 DDEV site is up (/stream returns 200, /showcase returns 200) but it is on main (verified git log: latest is a254035, does NOT contain this story code - grep for streamModelOptions in the primary checkout VariantSwitcher.php returned zero hits). Reusing it would require the same overlay-and-revert dance T-green already performed for Kernel and would only add live-DOM confirmation for what T Kernel pipeline already asserts on the render array.

Under the task explicit rule (If DDEV setup is impractical, report ADVISORY-ONLY env-limited - not REWORK - if the code looks correct on file-read + Kernel green + wireframe conformance), all three of those conditions are met. Live walkthrough is deferred to the CI Playwright run against the seeded runner (E2E already registers 11 tests, per T-green), which is the same posture T-green anticipated and explicitly permitted.

## What WAS verified (file-level + Kernel-shape + wireframe conformance)

Every AC that has a rendered-shape signal was checked by reading the code paths that produce it and comparing against the wireframe approved contract. Each row cites the file/line where the contract materializes and the Kernel/Unit test that pins it.

| AC | Requirement | Verified via | Result |
|---|---|---|---|
| AC-1 | Switcher renders with 2 options: Content view (soon) disabled + Activity view selected | VariantSwitcher::streamModelOptions() L155-172 returns two entries content (available FALSE) then activity; VariantSwitcher::build() L204-208 appends (soon) for unavailable; L211 prepends bullet glyph for selected. Test: VariantSwitcherTest::testStreamModelOptions + Kernel testSwitcherInjectedWithTwoOptionsInOrder (8/8 GREEN). | PASS (file+Kernel) |
| AC-2 | Wrapper carries data-do-stream-model=activity on default load | ModelToggleHooks::viewsPreRender() L165-178 writes data-do-stream-model = resolved variant; requestedVariant() L263-266 defaults to activity; VariantSwitcher::resolveCurrent() first-available-fallback returns activity. Test: testNoQueryParamDefaultsWrapperToActivity GREEN. | PASS (file+Kernel) |
| AC-3 | ?variant=content -> wrapper data-do-stream-model=activity (first-available fallback) | Same code path; resolveSelection() L307-321 falls back on unavailable. Test: testUnavailableContentQueryParamFallsBackToActivity GREEN. | PASS (file+Kernel) |
| AC-4 | ?variant=activity deep-link, activity selected, no reload flash | Server-resolved in viewsPreRender() BEFORE render - no JS fixup by construction. Test: testActivityQueryParamSetsWrapperToActivity GREEN. Live no-flash confirmation deferred to CI. | PASS by construction (file+Kernel); live flash-check deferred to CI |
| AC-5 | /showcase lists stream-model as live with working link to /stream | ShowcaseCatalog.php L75-89: id stream-model, status live, route view.activity_stream.page_1, updated decision_sentence present verbatim. Test: testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence GREEN. | PASS (file+Unit) |
| AC-6 | Tooltip copy renders from HelpText::get(showcase.switcher.stream.model) | HelpText.php L188 carries D-approved copy verbatim (posts/comments/flags/pins/membership terms + leaner/coming-soon). VariantSwitcher::build() L229-253 sets #tooltip + #tooltip_attributes with tabindex=0, role=note, aria-label, data-do-tooltip. Tests: StreamModelHelpTextTest (2 cases) GREEN. | PASS (file+Unit) |
| AC-7 | Content option aria-disabled=true, tabindex=-1, label suffix (soon) | VariantSwitcher::build() L217-224: aria_disabled = !available, tabindex = -1 for non-selected-or-unavailable, (soon) suffix on L207. Test: testSwitcherInjectedWithTwoOptionsInOrder (render-array shape) GREEN. | PASS (file+Kernel) |
| AC-8 | WCAG 2.2 AA: role=radiogroup, keyboard operability, visible focus, non-color cue (bullet glyph) | Render-array shape confirms role=radiogroup (L233), aria-checked per option (L217), roving tabindex (L219-223: selected+available -> 0, everything else -> -1), non-color leading bullet glyph (L211). Focus-ring CSS is SC-F1 token, unchanged. Live keyboard/focus-ring/200%-zoom exercise deferred to CI Playwright + subsequent WCAG audit. | PASS on shape; live keyboard+focus-ring+zoom check deferred |
| AC-9 | Cache context url.query_args:variant bubbles to page dynamic cache | ModelToggleHooks::viewsPreRender() L174 sets #cache.contexts DIRECTLY on view render array (belt-and-suspenders with VariantSwitcher::build() L265-267 which also declares it). Test: testViewDeclaresUrlQueryArgsVariantCacheContextDirectly GREEN. | PASS (file+Kernel) |
| AC-10 | No regression on #124 directory toggle / #116 activity_stream | T-green full 159-test sweep (do_streams + do_showcase + do_chrome, Kernel+Unit+Functional) -> 0 failures/errors. FollowingFeedTest (the delegation-fix pivot point) specifically re-run GREEN. | PASS (T-green) |

## What is NOT verified this phase (explicit gaps for S/CI awareness)

The following AC-8 sub-items require a live browser and are the CI Playwright run responsibility (they are not defects - the code shape is correct; they simply cannot be observed from source):

- Live tab order: that Tab from the page body actually lands on the Activity option and skips Content. Shape says yes; DOM order in the rendered HTML says yes; the browser actual Tab traversal is not confirmed here.
- Live Arrow-Left/Right: that both options are reachable via arrow keys inside the radiogroup and that Enter/Space on Content is a no-op.
- Focus ring visibility: the SC-F1 CSS focus token is reused unchanged (proven under #119/#124), so behavior should match - but not confirmed with pixels.
- Contrast at 200% zoom: WCAG 1.4.4 / 1.4.11 not measured this phase.
- Console-error absence on /stream and /showcase under this branch code.
- ?variant=activity no-flash transition (asserted here as by-construction - server resolves before render - but not observed).
- /showcase -> stream-model card -> click -> /stream navigation end-to-end.

The authored E2E spec tests/e2e/model-toggle.spec.ts (11 tests, registration-verified by T-green) covers all of these; the CI runner will exercise them.

## Findings

None from file-level review. Every AC signal that materializes in the render array or in the catalog/HelpText source strings is present and matches the wireframe verbatim. No blockingOverlays / deadComponents / consoleErrors equivalent surfaced in file review (those are runtime-only observables - genuinely deferred, not skipped).

## Advisory notes

- The showcase.switcher.stream.model and showcase_help.stream-model tooltip keys are BOTH in place and consistent with each other (Amendment 1 in-scope fix, verified at HelpText.php L188 and L301). No cross-key contradiction remains.
- ModelToggleHooks nullable-DI compromise (?VariantSwitcher) is production-safe (do_streams.info.yml hard-declares do_showcase:do_showcase, so the NULL branch is Kernel-test-only) - flagged in F handoff and explicit in the class docblock; nothing U needs to reopen.
- The two-hook shape departure from #124 (preprocessViewsView() is a plain method invoked via DoStreamsHooks single legal Hook(preprocess_views_view) delegation) is fully documented in F class docblock L28-48 and Design decision #1; externally observable behavior is identical. Not a U concern.

## Verdict

ADVISORY-ONLY (env-limited) - proceed to S. All 10 acceptance criteria have positive signal at the file/render-array/Kernel/Unit tier; no defect surfaced in code review; live-browser exercise of AC-8 keyboard/focus/zoom sub-items and the E2E spec navigation flows is deferred to the CI Playwright run per the task degrade-gracefully clause. S may proceed with awareness that the walk was file+shape-verified, not live-driven, this phase.
