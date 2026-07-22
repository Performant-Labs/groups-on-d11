# Handoff-T-red2: Fix loop — roving-tabindex/arrow-key coverage gap (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Wireframe reviewed:** `docs/handoffs/0119-variant-framework/wireframe.md` lines 29-31, 271
**handoff-T-green.md reviewed:** `docs/handoffs/0119-variant-framework/handoff-T-green.md`
(BLOCKER section: "roving-tabindex / arrow-key keyboard pattern not implemented, not tested")
**handoff-F.md reviewed:** `docs/handoffs/0119-variant-framework/handoff-F.md`

## Context

A Tier-2 BLOCKER was found during Phase 6: the approved wireframe (lines 29-31 — "one option in
tab order at a time; Arrow-Left/Right moves selection, matching native radiogroup behavior";
line 271's comparison table — "Keyboard operable | roving-tabindex radiogroup, arrow keys") is
not implemented and, critically, was not tested by either Phase-4 test file authored by T. This
handoff closes T's half of that gap: new failing coverage authored against the CURRENT shipped
code, confirmed RED for the right reason. F will implement the fix against this new RED in the
next phase.

## Tests authored

### PHPUnit — `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php`

All four new methods pin the STATIC render-array contract emitted by `VariantSwitcher::build()`
— assertable without a browser, matching the existing file's tier and style (Unit, no container).

1. **`testExactlyOneAvailableOptionHasRovingTabindexZero`** — pins wireframe.md lines 29-31/271:
   exactly ONE option in `#options` may carry `tabindex === '0'`; it must be the currently
   SELECTED option; every other AVAILABLE option must carry `tabindex === '-1'`. This is the
   direct structural assertion of the defect named in handoff-T-green.md
   (`VariantSwitcher.php` line 92: `'tabindex' => $available ? '0' : '-1'` — gives every
   available option `0`, not roving).
2. **`testRovingTabindexZeroFollowsSelectionToADifferentOption`** — same invariant with
   `$current = 'compact'` instead of the stub's default `'cards'`, so the assertion is not
   coincidentally true only for one specific selection; proves the `0` slot must track selection,
   not a fixed position.
3. **`testUnavailableOptionIsNeverTheRovingTabindexTarget`** — the unavailable option ("map")
   must never be the roving tabindex=0 target regardless of selection. This restates part of the
   existing `testUnavailableOptionCarriesDisabledMarkers` coverage deliberately, to make the
   roving-vs-disabled distinction explicit as its own named behavior — not redundant, since it is
   the one new-method assertion that already holds true under current code (see RED confirmation
   below: this one passes, the other three fail).
4. **`testRovingTabindexInvariantHoldsForArbitraryOptionCount`** — forward-compat: the
   exactly-one-tabindex-zero invariant holds for a 5-option instance, not just the 3-option stub
   (mirrors the existing file's own `testBuildWorksForArbitraryOptionCount` pattern).

Tier: **Unit** (cheapest sufficient — this is a pure render-array/data-shape contract, identical
tier to every other method already in this file; no container/DB/browser needed).

### Playwright — `tests/e2e/showcase.spec.ts`

Four new cases added inside the existing `SC-F1 — Variant switcher (#119)` `test.describe` block,
immediately after "an unavailable option is present, marked, and not a dead click":

1. **`roving tabindex: only the selected option is Tab-reachable, not every available option`**
   — pins wireframe.md lines 29-31: asserts `tabindex="0"` on the selected option ("Cards", the
   page's default) and `tabindex="-1"` on the non-selected available option ("Compact list").
2. **`ArrowRight moves selection to the next available option and rolls the roving tabindex`** —
   pins wireframe.md's "Arrow-Left/Right moves selection, matching native radiogroup behavior."
   Focuses the selected option, presses `ArrowRight`, asserts focus AND selection (`aria-checked`)
   AND the roving `tabindex` all move together to the next available option, skipping the
   unavailable "Map" option (native-radiogroup arrow nav visits only enabled radios — WAI-ARIA
   Authoring Practices radiogroup pattern, which the wireframe's "matching native radiogroup
   behavior" phrase invokes).
3. **`ArrowLeft moves selection to the previous available option, skipping the unavailable one`**
   — same contract, opposite direction; explicitly proves arrow nav skips over "Map" rather than
   landing on a disabled option.
4. **`no-JS ?variant= fallback still works unmodified by the arrow-key fix`** — regression guard
   requested by the task: asserts the existing no-JS query-param fallback (already covered by an
   earlier case in this file) is unaffected by whatever arrow-key handler F adds. Deliberately a
   narrow duplicate of the existing fallback assertion shape, scoped specifically to this
   follow-up so a future regression in the arrow-key change is caught here too.

Tier: **E2E** (cheapest sufficient for real keyboard/focus interaction — a headless Unit/Kernel
test cannot observe DOM focus transfer or `keydown` handling).

**Wrap/skip semantics note:** the wireframe does not spell out wrap-vs-clamp verbatim; it says
"matching native radiogroup behavior," which is the WAI-ARIA Authoring Practices radiogroup
pattern — arrow keys move among ENABLED radios only (skip disabled ones) and wrap at the ends.
Cases 2/3 assert the skip-unavailable behavior explicitly (both directions land on the one other
available option, never on "Map"); wrap-at-the-end is implied by the 2-available-option stub
(ArrowRight and ArrowLeft from "Cards" both necessarily land on "Compact list," the only other
available option) but not independently distinguished from a hard clamp with only 2 available
options in the stub — flagged for F: if F's fix does not wrap (e.g. clamps at the last available
option with more than 2 available options elsewhere), that's a wireframe-conformance question for
a future story with 3+ available options, not a gap in this round's tests, which correctly pin
what is testable against this story's stub instance.

## What was NOT added (and why)

No new Kernel/Functional test was authored — the roving-tabindex/arrow-key contract is fully
pinned by the PHPUnit render-array contract (static markup) + Playwright (dynamic interaction);
a Kernel test would duplicate the PHPUnit case at a more expensive tier for no additional
coverage, and a Functional (browser-less HTTP) test cannot observe `keydown`/focus transfer at
all — only a real browser (Playwright) can, so Functional is not the right tier for the dynamic
half either.

## RED confirmation

### PHPUnit — executed to a REAL RED against the current shipped code, isolated tree

Isolated-tree setup mirrored F's/T-green's documented procedure (symlinked `web/core` silently
redirects PHPUnit's bootstrap resolution back to the SHARED checkout's realpath — the "symlink
realpath trap"): copied `web/core` (full `cp -R`, not a symlink) plus `do_showcase`/`do_chrome`
into `web/modules/custom/`, full `cp -R` of `vendor` (not a symlink — the same care applied even
though vendor itself is not bootstrap-path-sensitive, to avoid any doubt). Ran:

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php --testdox
```

**Isolated-tree marker (proves the worktree's own copy was exercised, not the shared checkout):**
```
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```

**Output:**
```
..........FF.F                                                    14 / 14 (100%)

 ✔ Build returns labeled control group keyed by instance id
 ✔ Build renders one item per option
 ✔ Exactly one option marked selected
 ✔ Unavailable current falls back to first available
 ✔ Unknown current falls back to first available
 ✔ Unavailable option carries disabled markers
 ✔ Available options are not disabled
 ✔ Every option carries no js variant fallback link
 ✔ Build works for arbitrary option count
 ✔ Tooltip trigger carries help text sourced copy
 ✘ Exactly one available option has roving tabindex zero
   │ Roving-tabindex pattern (wireframe.md lines 29-31, 271): exactly ONE option may carry
   │ tabindex=0 at a time — the rest of the radiogroup must be reachable only via arrow keys,
   │ not Tab. Got 2 options with tabindex=0.
   │ Failed asserting that actual size 2 matches expected size 1.
 ✘ Roving tabindex zero follows selection to a different option
   │ Exactly one tabindex=0 option regardless of which option is selected.
   │ Failed asserting that actual size 2 matches expected size 1.
 ✔ Unavailable option is never the roving tabindex target
 ✘ Roving tabindex invariant holds for arbitrary option count
   │ Exactly one tabindex=0 option must hold for a 5-option instance, not just the 3-option stub.
   │ Failed asserting that actual size 4 matches expected size 1.

FAILURES!
Tests: 14, Assertions: 35, Failures: 3, PHPUnit Deprecations: 15.
```

**All 10 pre-existing methods still pass (no regression to the existing RED-round-1 suite).**
Three of the four new methods fail for the RIGHT reason — the roving-tabindex assertion itself
(`actual size 2/4 matches expected size 1`), never a class-not-found/bootstrap error, matching
exactly the current code's documented defect (every available option gets `tabindex="0"`). The
fourth new method (`testUnavailableOptionIsNeverTheRovingTabindexTarget`) correctly PASSES — it
pins an invariant that already holds under current code (the unavailable option is already
`tabindex="-1"`, just not for the *roving* reason); this is expected and by design, not a defect
in the RED — it documents that this specific sub-behavior does not need fixing.

Torn down after: removed `web/core`, `web/modules/custom`, `vendor` (all were scratch full
copies, gitignored, not tracked) from the worktree. `git status --short` confirms only the two
intended test-file diffs remain.

### Playwright — RED-by-construction (not executed)

No live site was stood up in this environment for this fix-loop round. Standing up the full
Docker/composer/drush stack T-green already documented (namespaced MySQL container, PHP 8.4 via
Homebrew — not installed in this environment; only PHP 8.3 is present — composer install,
`assemble-config.sh`, `drush site:install`, demo-data seed, `drush runserver`) purely to execute
3 narrow keyboard-interaction assertions is disproportionate infrastructure for this scoped
fix-loop round, and this environment lacks PHP 8.4 (T-green's own documented requirement — PHP
8.3 fails composer's lock-file platform check). Per the task's own explicit allowance ("If you
cannot stand up a site to execute the e2e case here, mark it RED-by-construction with the precise
selector/keypress/assertion and why it must fail against the current no-arrow-handler code").

**What WAS verified:** `npx playwright test --list tests/e2e/showcase.spec.ts` (against a
read-only symlink to the shared checkout's `node_modules`, torn down after) confirms the spec
file is syntactically valid TypeScript, lands in the correct `testDir`, and all 19 cases
(15 pre-existing + 4 new) are collected with zero parse errors:
```
[chromium] › showcase.spec.ts:202:7 › SC-F1 — Variant switcher (#119) › roving tabindex: only the selected option is Tab-reachable, not every available option
[chromium] › showcase.spec.ts:229:7 › SC-F1 — Variant switcher (#119) › ArrowRight moves selection to the next available option and rolls the roving tabindex
[chromium] › showcase.spec.ts:264:7 › SC-F1 — Variant switcher (#119) › ArrowLeft moves selection to the previous available option, skipping the unavailable one
[chromium] › showcase.spec.ts:291:7 › SC-F1 — Variant switcher (#119) › no-JS ?variant= fallback still works unmodified by the arrow-key fix
Total: 19 tests in 1 file
```

**Precise RED-by-construction reasoning per case, against the CURRENT shipped code (read directly
— `do_showcase.switcher.js` and `VariantSwitcher.php` as committed at HEAD, branch
`0119-variant-framework`):**

1. **`roving tabindex: only the selected option is Tab-reachable...`** — MUST fail on the
   `await expect(compact).toHaveAttribute('tabindex', '-1')` assertion. Current code
   (`VariantSwitcher.php` line 92: `'tabindex' => $available ? '0' : '-1'`) renders BOTH
   available options ("Cards" and "Compact list") with `tabindex="0"` — the "Compact list" locator
   will report `tabindex="0"`, not `-1`, failing the assertion with a value mismatch, not a
   missing-locator/404 error (the switcher itself renders fine; only this one attribute is wrong).
2. **`ArrowRight moves selection to the next available option...`** — MUST fail on
   `await expect(compact).toBeFocused()` (or the subsequent `aria-checked`/`tabindex` assertions).
   `do_showcase.switcher.js` (read in full) registers `click` and `keydown` listeners per option,
   but the `keydown` handler only checks `event.key !== 'Enter' && event.key !== ' '` and returns
   early otherwise — there is no `case`/branch for `'ArrowRight'` or `'ArrowLeft'` anywhere in the
   file (confirmed: zero occurrences of the string "Arrow" in `do_showcase.switcher.js`). Pressing
   `ArrowRight` while focus is on "Cards" does nothing; focus, `aria-checked`, and `tabindex` all
   remain exactly as they were on "Cards" — the assertion that focus moved to "Compact list" fails
   because focus never moved.
3. **`ArrowLeft moves selection to the previous available option...`** — same root cause as case 2
   (no arrow-key handler exists), MUST fail identically on the `toBeFocused()`/`aria-checked`
   assertions for "Compact list" after `ArrowLeft`.
4. **`no-JS ?variant= fallback still works unmodified by the arrow-key fix`** — this is a
   regression GUARD, not a RED case: it asserts behavior that already works under current code
   (`VariantSwitcher::resolveSelection()`'s fallback-to-first-available logic, already covered and
   passing per T-green's own PHPUnit mutation spot-check) and is expected to remain GREEN both now
   and after F's fix. Included so F's arrow-key change is checked against this specific regression
   at T-GREEN time, not to prove a current defect.

## Valid RED confirmed

**PHPUnit: valid RED, executed for real.** 3/4 new methods fail for the right reason (the roving-
tabindex assertion itself, evidenced by `Failed asserting that actual size N matches expected
size 1` against the isolated tree's own `Configuration:` path) against the current shipped
`VariantSwitcher.php`; the 4th (`testUnavailableOptionIsNeverTheRovingTabindexTarget`) correctly
passes as a restated pre-existing invariant, not a false RED. All 10 pre-existing methods remain
green — no regression introduced to round-1 coverage.

**Playwright: RED-by-construction, precisely reasoned against the current committed code** (not
executed — no live site stood up this round, per the task's explicit fallback allowance and this
environment's missing PHP 8.4 dependency). 3 of 4 new cases must fail against
`do_showcase.switcher.js`'s confirmed absence of any Arrow-key handling; the 4th is a regression
guard expected to already pass.

**Overall: valid RED for the roving-tabindex/arrow-key gap.** F may implement the fix (roving
tabindex on selection + Arrow-Left/Right keydown handling in `do_showcase.switcher.js` and the
corresponding `tabindex` output in `VariantSwitcher::build()`) against this authored coverage.

## Files changed (this round only)

- `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php` (modified — 4 new
  methods appended, nothing existing edited)
- `tests/e2e/showcase.spec.ts` (modified — 4 new cases inserted into the existing
  `SC-F1 — Variant switcher (#119)` describe block, nothing existing edited)

Mirrored to scratchpad:
`/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/tests-red-round2/{VariantSwitcherTest.php,showcase.spec.ts}`

## Ready for F

Confirmed. RED is valid for the roving-tabindex/arrow-key gap. F may implement:
1. `VariantSwitcher.php`: change `'tabindex' => $available ? '0' : '-1'` to roving —
   `'tabindex' => ($available && $is_selected) ? '0' : '-1'`.
2. `do_showcase.switcher.js`: add an `ArrowRight`/`ArrowLeft` `keydown` handler that moves
   selection + focus to the next/previous AVAILABLE option (skipping disabled ones), updating
   `aria-checked`/`tabindex` on both the old and new selected option, matching native-radiogroup
   (WAI-ARIA APG) semantics.
3. `do-showcase-variant-switcher.html.twig` needs no structural change (already emits whatever
   `tabindex` value `VariantSwitcher::build()` supplies per option).
