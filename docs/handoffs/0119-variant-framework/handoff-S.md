# Handoff-S: Phase 9 — SC-F1 Variant framework (spec audit / final quality gate)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119 (Epic #117)
**Handoff-T reviewed:** docs/handoffs/0119-variant-framework/handoff-T-green4.md
**Handoff-A reviewed:** docs/handoffs/0119-variant-framework/handoff-A-plan.md + handoff-A-dup.md
**Handoff-F reviewed:** docs/handoffs/0119-variant-framework/handoff-F4.md (+ F/F2/F3 chain)
**Handoff-U reviewed:** docs/handoffs/0119-variant-framework/handoff-U.md
**Operator-facing report:** N/A — this story's UI surfaces were verified by U's live axe-core +
navigated-path walkthrough (Phase 8); there is no approved *static pixel reference* to diff against
(the wireframe is low-fidelity structure-only, mode (a) generated, with no color system / hex values —
"AA contrast deferred to F's CSS pass"). A pixel-level visual regression has no reference image to
compare to, so the Tier-3 visual path is N/A; U's rendered-DOM + WCAG audit is the appropriate gate
and it PASSED (0 axe violations, 3 surfaces x 2 viewports).

## A precondition
Confirmed: A returned **PASS** at both gates — handoff-A-plan (Phase 3, up-front) PASS with two
non-blocking `warn` findings (survey doc-hygiene; document the service-vs-Block rationale — F did),
and handoff-A-dup (Phase 7, anti-duplication) PASS with all six checks green and direct evidence.

## T precondition
Confirmed: T reported **zero blocking issues**. handoff-T-green4 "Blocking issues: None"; full
authored PHPUnit 42/42 (277 assertions, run twice); target-spec Playwright 26/26. U independently
re-ran the target suite 26/26 GREEN on the identical committed HEAD.

## Independent verification performed by S
- Read the full accumulated diff (`git diff --stat origin/main...HEAD`): **6629 insertions, one new
  module (`do_showcase`) + one append-only `HelpText.php` edit + one new e2e spec + handoffs.** No
  files touched outside the issue's declared "Owns" set. No `git add .` residue.
- `php -l` on all five changed PHP source files — **no syntax errors**.
- Read every source file (`VariantSwitcher`, `ShowcaseCatalog`, `ShowcaseController`,
  `DoShowcaseHooks`, both JS files, the twig template, all four `.yml`, the `HelpText` append) and
  every test file (3 PHPUnit + `showcase.spec.ts`) line by line.
- Read O's diff-gate adjudication (decisions.md L600-640) and confirmed all five o4-mini BLOCKs are
  correctly dispositioned (B-1/B-2/B-3 fixed + verified; B-4/B-5 rejected with direct source
  evidence, not hand-waved).
- Did NOT re-provision the throwaway Docker/DB stack: T verified GREEN twice and U re-verified GREEN
  once on this exact commit; my mandate is Tier-3 spec/quality, not re-running Tier-1/2. The worktree
  is torn down (no vendor/web-core), consistent with T's/U's documented teardown.

## AC-to-test traceability

| # | Issue #119 acceptance criterion | Backing test(s) | Genuinely pins? |
|---|---|---|---|
| 1 | Switcher renders + switches + persists **per session** on ≥1 wired instance | `showcase.spec.ts`: `switcher renders as a labeled radiogroup`, `clicking an option switches`, `the choice persists client-side across navigation`; live: U Surface-1 table + T-green4 Case A/B (sessionStorage same-tab persist / fresh-context revert); unit: `VariantSwitcherTest::testExactlyOneOptionMarkedSelected` | YES — persist test navigates away+back and re-asserts `aria-checked`; T-green4 Case B proves *session* scoping (fresh context reverts to server default), which is exactly "per session," not "forever." |
| 2 | `VariantSwitcher::build()` stable render-array contract (forward-compat) | `VariantSwitcherTest` (17 cases): labeled group, one-item-per-option, exactly-one-selected, fallback-on-unavailable/unknown, disabled markers, no-JS href, **arbitrary option count** (2/5), roving-tabindex invariant, cache-context | YES — arbitrary-count + roving-invariant cases prove the contract holds for SC-4/5/6's own option sets, not just the stub. |
| 3 | `/showcase` lists all planned comparisons incl. "coming", each w/ decision framing; names 4 personas | `showcase.spec.ts`: `lists all six comparison entries with [live]/[coming] badges`, `references #134`, `lists the persona switcher naming all four public personas`; unit: `ShowcaseCatalogTest` (all-7-entries, complete-shape, coming-no-route, live-has-route, membership-stays-coming, #134, 4 personas) | YES — catalog unit tests pin per-entry status/route/shape; e2e pins rendered presence + persona list scoped to the persona entry's `<ul>`. |
| 4 | Ribbon site-wide anon + auth; dismissible per session | `showcase.spec.ts`: `ribbon shows for anonymous`, `ribbon shows identically for an authenticated user`, `dismiss button removes the ribbon`, `dismissal persists client-side across navigation`; live: U Surface-3 (anon+auth identical, keyboard dismiss, zero session cookie, sessionStorage-only) | YES — auth test logs in via real `/user/login`; dismiss-persist navigates and re-asserts absence; U confirms zero session cookie (Brief-gate B-2 holds live). |
| 5 | Tooltip renders on switcher (`do_chrome/tooltips`, HelpText copy); suite stays green | `VariantSwitcherTest::testTooltipTriggerCarriesHelpTextSourcedCopy`; `ShowcaseHelpTextTest` (key resolves, describes-what-differs, existing key unchanged, unknown→''); live: U Surface-1 tooltip hover+focus shows tippy root with HelpText copy | YES — unit pins non-empty HelpText-sourced copy + append-only non-regression; U live-verifies tippy renders. `nav.spec.ts` + `HelpTextTest.php` non-regression confirmed (26/26 incl. nav; 42/42 incl. HelpText). |
| 6 | Ships HelpText entry (append-only) | `ShowcaseHelpTextTest::testExistingDoChromeKeyStillResolvesUnchanged` + `testUnknownKeyStillReturnsEmptyString`; A-dup check #1 (net delta = exactly one live key) | YES — pins the new key resolves AND an existing key is undisturbed; A-dup confirms no parallel copy store. |
| 7 | WCAG 2.2 AA (labels, keyboard, visible focus, non-color status) | `showcase.spec.ts`: `selection conveyed by more than color`, `roving tabindex`, `ArrowRight/ArrowLeft moves selection`, `unavailable option present/marked`; live: **U axe-core 0 violations, 3 surfaces × 2 viewports (wcag2a/2aa/2.2aa)**; focus-ring + keyboard dismiss verified live | YES — non-color-cue test asserts the `●` glyph delta, not just aria; roving + arrow tests pin keyboard operability; axe is the AA backstop. |
| 8 | Delivery via namespaced Docker + `npx playwright test` green | T-green4 (Docker `o119t4`, 26/26) + U (Docker `o119u1`, 26/26), both torn down | YES — two independent namespaced provisions, teardown confirmed. |

**No acceptance criterion is asserted-not-tested.** Every AC has at least one non-vacuous backing
test, most have both a unit contract pin and a live/e2e behavioral pin.

## Precise-spec conformance

| Conformance point | Finding |
|---|---|
| Brief-gate B-2 "per session" = sessionStorage | **CONFORMANT.** Both JS files use `window.sessionStorage` (grep-confirmed by T/A-dup: zero functional `localStorage` calls). T-green4 Case B proves session-scoping via two independent contexts (fresh context reverts). Matches issue wording "remembers choice per session" / "dismissible per session" — sessionStorage clears on browser/tab-session end, which is the correct semantics for "per session" (localStorage would be "forever"). |
| D-gate: ribbon fixed-top, no nav reflow | **CONFORMANT.** `page_top` hook injects a fixed element; U measured ribbon y:0..43 vs nav y:76.5..175.5 (no overlap); `nav.spec.ts` green. |
| D-gate: unavailable option aria-disabled + tabindex=-1 | **CONFORMANT.** `VariantSwitcher::build()` L91/L96; `VariantSwitcherTest::testUnavailableOptionCarriesDisabledMarkers`; e2e `an unavailable option is present, marked, and not a dead click`. |
| D-gate: no ribbon re-entry required | **CONFORMANT.** No re-entry affordance built (correctly out of scope); ribbon links to `/showcase` which stays nav-reachable. |
| Roving-tabindex + arrow keys match wireframe | **CONFORMANT.** JS `moveSelection` cycles AVAILABLE options only (skips disabled "Map"), roving tabindex kept at exactly 1; unit `testExactlyOneAvailableOptionHasRovingTabindexZero` + e2e ArrowRight/ArrowLeft; U live-verified `tabindexZeroCount:1` after every move and Map correctly skipped. |
| Cache-context fix (`url.query_args:variant`) present + tested | **CONFORMANT.** Declared in both `VariantSwitcher::build()` (L138-140) AND `ShowcaseController::page()` (L66) — the defect class fixed at the reusable service, not just the one caller. `VariantSwitcherTest::testBuildDeclaresUrlQueryArgsVariantCacheContext` pins the mechanism with explicit revert-reasoning. |
| No theme/skin toggle | **CONFORMANT.** No theme variant anywhere in the diff (epic-wide exclusion honored). |
| Membership stays [coming] | **CONFORMANT.** `ShowcaseCatalog` `membership-models` status='coming', route=NULL; `testMembershipModelsEntryStaysComing`. |
| No grequest assumption | **CONFORMANT.** No grequest reference in code; membership entry is copy-only [coming]. |
| Route access = real permission | **CONFORMANT.** `do_showcase.routing.yml` uses `_permission: 'access content'` (matches `do_discovery` public-page precedent), NOT `_access: 'TRUE'`. Appropriate for a POC page meant for anonymous evaluators. |

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| Access / security (Drupal) | PASS | Real `_permission: 'access content'` on `/showcase`, not `_access:'TRUE'`. No user input persisted server-side (client-only sessionStorage); `?variant=` is read-only + rawurlencoded on output; no secrets. |
| Config / schema | N/A | No config entities / config schema introduced (catalog is a code constant per Brief-gate B-4 — deliberately not config). |
| Error handling | PASS | `/showcase` `#empty` copy present; JS wraps every `sessionStorage` access in try/catch with graceful degradation (private mode / disabled storage → control still works, just no persist). |
| UI/UX match to spec | PASS | All 3 surfaces match wireframe.md per U's navigated-path walkthrough; truthful [live]/[coming] badges, no dead links on "coming" entries, working live deep-links. |
| Accessibility | PASS | U axe-core 0 violations across 3 surfaces × 2 viewports (wcag2a/2aa/2.2aa); non-color `●` cue + `[ live ]`/`[ coming ]` text badges; roving-tabindex keyboard nav; real `<button>` dismiss with `aria-label`; visible 2px focus ring. |
| Architecture gate | PASS | A-plan PASS + A-dup PASS (both read independently by S; evidence holds). |
| Code organization | PASS | Clean, well-documented classes; no dead code (round-4 removed the dead ribbon-tooltip wiring); no stray TODOs. One minor stale doc-comment (below, advisory). |
| Docs (Keystatic-editable, links) | N/A | Drupal module story, not a `docs/` Starlight content change. |
| Naming consistency | PASS | `do_showcase.*` route/service/library names, `showcase.switcher.<instance_id>` HelpText key, `data-do-showcase-*` attributes — all consistent with `do_chrome`/`do_discovery` house style. |
| Test quality | PASS | See rubric below. |

### Test-quality rubric (over T's suite)
- **Per-test validity:** every PHPUnit case names one behavior, sits at the cheapest sufficient tier
  (Unit for the pure render-array/catalog contract; E2E only where rendered-DOM/`href`-resolution is
  the thing under test — correctly justified in T-green4 for the B-3 case), and asserts behavior
  (aria state, tabindex, selection, cache metadata), not implementation internals. The
  roving-tabindex and cache-context tests each carry explicit revert/mutation reasoning proving they
  fail RED for the right reason (T-green: roving; T-green2/F3: cache; T-green4 mutation spot-check:
  B-3 deep-link). Non-vacuous.
- **Per-suite proportionality:** 17 `VariantSwitcher` + 11 `ShowcaseCatalog` + 4 `ShowcaseHelpText`
  unit cases + 19 e2e cases for a 3-surface foundational component that blocks 4 downstream stories —
  proportionate, not padded. The forward-compat arbitrary-count cases are load-bearing (SC-4/5/6 will
  call `build()` with their own option sets).
- **Smells:** none blocking. Two minor observations (advisory, not "delete/merge"):
  1. `showcase.spec.ts` `lists all six comparison entries...` uses a **page-level**
     `page.getByText(badgeText).first()` inside its per-entry loop — it proves *a* `[ coming ]` badge
     exists on the page, not that *this* entry has one. The per-entry status contract is however
     fully pinned by `ShowcaseCatalogTest` (coming-no-route / live-has-route / membership-stays-coming)
     and the B-3 live-deep-link case, so no AC is left unbacked; this is a loose-but-not-vacuous e2e
     assertion, not a gap. Advisory only.
  2. `no-JS ?variant= fallback still works unmodified by the arrow-key fix` is deliberately a narrow
     regression duplicate of `no-JS ?variant=` (T documented this intent). Justified as a targeted
     regression pin for the arrow-key follow-up; acceptable, not redundant-signal padding.

## Scope check
F delivered **exactly** the #119 phase scope: the reusable device + one wired stub instance
(`directory.layout`), the `/showcase` catalog (all 7 entries, 5 correctly [coming]), and the ribbon.
The SC-4/5/6 *real* comparisons are correctly **NOT** built (catalog entries are copy-only [coming]
with NULL routes). No theme toggle. No grequest. No over-delivery, no under-delivery. Sole new module
is `do_showcase`; the only `do_chrome` edit is the append-only HelpText key.

## Verdict

**PASS** — all eight #119 acceptance criteria are met and backed by non-vacuous tests, the
implementation conforms to the brief (incl. Brief-gate B-2 sessionStorage), the wireframe, and both
D-gate resolutions, the dual-review diff-gate BLOCKs are all correctly resolved, access/security is
sound (real permission, no server-session write, no secrets), WCAG 2.2 AA holds live (0 axe
violations), and scope is exactly the phase scope. A and A-dup gates hold under my own read. Ready for
O to open the MR.

## Advisory notes (non-blocking — do NOT re-open an F cycle for these)
1. **Persona `name` fields are raw strings, not t()-wrapped** (`ShowcaseCatalog::personas()`:
   "Anonymous" / "Elena Garcia" / "Maria Chen" / "Moderator"). `testEntryStringsAreTranslatableMarkup`
   consciously scopes the t()-wrap requirement to entry `title`/`decision_sentence`; persona
   `description` fields ARE t()-wrapped. Proper names correctly should not be translated, but
   "Anonymous" is arguably a localizable common noun. Brief-gate W-3 says "all user-facing strings" —
   this is a genuine ambiguity in the source of truth on a POC, not a clear defect (see also the
   ADVISORY-HOLD note below). Left as advisory; a one-word future i18n polish at most.
2. **Stale doc-comment:** `do_showcase.module`'s file docblock says the ribbon is a
   "page_attachments hook"; it is actually `page_top` (the code and all handoffs are correct — this
   is a lone comment out of date). Cosmetic; fix opportunistically if the file is next touched.
3. **`?variant=` fallback href drops any pre-existing query string** (twig emits bare
   `?variant=<id>`). Harmless for the stub / current callers; SC-4/5/6 should be aware if they ever
   combine the switcher with other query params on the same route.

## ADVISORY-HOLD consideration (raised, then resolved to PASS, per S's mandate to not silently pass)
I considered whether the persona-name t()-wrapping (advisory #1) or the "per session" semantics rise
to **ADVISORY-HOLD** (source-of-truth defect). They do **not**: "per session" is unambiguously
satisfied by sessionStorage (the correct reading of the issue's own words), and the persona-name
non-wrap is a defensible implementation choice (proper names) that the test suite deliberately
encodes — F faithfully matches a reasonable reading of the brief, and the residual ambiguity is a
one-word i18n nit, not a pipeline-blocking source-of-truth defect. No hold warranted. **PASS stands.**
