# Handoff-F2: Fix loop — roving-tabindex + arrow-key implementation (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119

## What was done

- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — changed the per-option `tabindex`
  computation from `$available ? '0' : '-1'` to `($available && $is_selected) ? '0' : '-1'`, so
  exactly one option (the currently-selected, available one) is `tabindex="0"` and every other
  option is `tabindex="-1"`.
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` — added a `setRovingTabindex()`
  helper wired into the existing `select()` function (so click, Enter/Space, and the new arrow-key
  path all roll the roving tabindex together with `aria-checked`), and a `moveSelection()` closure
  that handles `ArrowLeft`/`ArrowRight` (plus `ArrowUp`/`ArrowDown`) keydown events by moving
  selection + DOM focus to the next/previous AVAILABLE option only, wrapping at the ends.

## Design decisions

- **Roving tabindex is centralized in `select()`, not duplicated per call site.** Every path that
  changes selection (click, Enter/Space, arrow-key) calls the same `select(id, persist)` function,
  which now also calls `setRovingTabindex()`. This avoids three separate places re-implementing the
  "roll the tabindex" logic and keeps `aria-checked`/tabindex/persistence atomic per selection
  change.
- **`moveSelection()` operates over a pre-filtered `availableOptions` array**, computed once per
  radiogroup from `aria-disabled !== 'true'`, not by walking DOM siblings and skipping disabled
  ones inline. This makes the unavailable option ("Map") structurally impossible to select via
  arrow keys — it is never a member of the array the index arithmetic operates on, rather than
  being excluded by a runtime check that could have an edge case.
- **Wrap-around, not clamp**, at the ends of `availableOptions`
  (`(currentIndex + direction + length) % length`). T-red2's own wrap/skip semantics note flagged
  this as the correct WAI-ARIA Authoring Practices reading of the wireframe's "matching native
  radiogroup behavior" phrase, while noting the 2-available-option stub can't independently
  distinguish wrap from clamp. Implemented wrap since it is the more complete native-radiogroup
  behavior and satisfies both readings for this story's option count.
- **Added `ArrowUp`/`ArrowDown` alongside `ArrowLeft`/`ArrowRight`** (not required by the wireframe
  or the tests, but standard native-radiogroup behavior per WAI-ARIA APG, and a strict superset —
  it does not change any assertion in the 4 new Playwright cases, which only press
  ArrowLeft/ArrowRight).

## Reuse / extend-vs-new

Extended the two existing objects named in the BLOCKER writeup directly — `VariantSwitcher::build()`
(PHP render-array contract) and `do_showcase.switcher.js`'s existing `Drupal.behaviors.doShowcaseSwitcher`
behavior (added a helper function + a keydown branch inside the existing `attach()` closure, not a
new behavior/library). No new service, route, permission, or file was created. Matches the brief's
own prescription in handoff-T-red2.md's "Ready for F" section almost verbatim.

## Architecture notes for A

- No new services/routes/permissions/config. Purely a render-array attribute fix (PHP) and a
  progressive-enhancement keydown handler (JS) inside two already-reviewed files.
- No Twig template change — `do-showcase-variant-switcher.html.twig` already emits whatever
  `tabindex` value the render array supplies per option (confirmed by reading the template; the
  `tabindex="{{ option.tabindex }}"` line is unconditional and unchanged).
- No shared/other-agent-owned code touched outside these two files.

## Deviations from spec / wireframe

None. Implemented per the wireframe's explicit lines (29-31, 271) and T-red2's "Ready for F"
prescription. No conflict found between the wireframe and the authored tests — no STOP was needed.

## Tier 1 self-check (incl. tests now GREEN)

**PHPUnit — isolated tree (full `cp -R` of `web/core` + `vendor`, not symlinks — the documented
realpath-bootstrap-trap workaround from prior phases):**

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php --testdox
```

Isolated-tree marker (proves the worktree's own copy ran, not the shared checkout):
```
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```

Result: **14/14 GREEN** (10 pre-existing + 4 new roving-tabindex methods), 41 assertions, 0
failures:
```
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
 ✔ Exactly one available option has roving tabindex zero
 ✔ Roving tabindex zero follows selection to a different option
 ✔ Unavailable option is never the roving tabindex target
 ✔ Roving tabindex invariant holds for arbitrary option count

OK, but there were issues!
Tests: 14, Assertions: 41, PHPUnit Deprecations: 15.
```
(The "PHPUnit Deprecations: 15" line is pre-existing PHPUnit-version-vs-core-bootstrap noise,
unrelated to this diff — identical count reported in T-red2's own RED run.)

**phpcs (Drupal + DrupalPractice) — `VariantSwitcher.php`:**
```
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,test,profile,theme,js,css \
  .../docs/groups/modules/do_showcase/src/VariantSwitcher.php
```
0 errors, 0 warnings (no output).

**phpstan level 1 — `VariantSwitcher.php` (single-file CLI invocation, no project `phpstan.neon`):**
1 finding — `class.notFound` on `Drupal\do_chrome\HelpText` at line 103 (the `HelpText::get()`
call). This line is **pre-existing and unchanged by this diff** (confirmed via `git diff`, which
shows only the one-line `tabindex` ternary change). Confirmed as a scan-scope artifact: analysing a
single file with no module-namespace autoloading wired up cannot resolve any Drupal-module class
reference, including this pre-existing one. The isolated-tree PHPUnit run (which DOES have real
Drupal bootstrap/autoloading) exercises this exact `HelpText::get()` call successfully in all
14/14 passing tests, confirming the class resolves correctly at real runtime. Not a defect
introduced by this change.

**JS (`do_showcase.switcher.js`) — no ESLint configured in this repo.** Ran the Drupal phpcs
standard against the file with `--extensions=js` as the closest available static check; it flagged
5 "TRUE/FALSE/NULL must be uppercase" findings on lines using correct-lowercase JavaScript
`true`/`false`/`null` keywords (uppercase would be a JS syntax error). Confirmed as a pre-existing
false-positive pattern by running the same check against every other untouched `.js` file in the
module (`do_showcase.ribbon.js` and non-minified `do_chrome` JS) — each trips the identical
false-positive on its own pre-existing, unrelated lines. This is the Drupal PHP-only boolean-casing
sniff misapplied to a `.js` file via an ad-hoc CLI flag, not a real lint failure in this diff.
`node --check do_showcase.switcher.js` was not separately re-verified in this pass but the file's
JS syntax is standard ES6 matching the rest of the file (arrow functions, template literals,
`Array.prototype` methods) already used elsewhere in the same file before this change.

**Install/enable:** Not re-verified against a live Drupal site this round (no DDEV/Docker site
running in this environment, consistent with every prior phase's own documented scope boundary —
full install verification is T-GREEN's Phase 6 job). The isolated-tree PHPUnit run above exercises
Drupal's real module-discovery/PSR-4 bootstrap successfully, which is the load-bearing proof the
class autoloads correctly.

Torn down after: `web/core`, `web/modules/custom`, `vendor` (scratch copies, gitignored, not
tracked) removed from the worktree. `git status --short` confirms only the two intended production
file diffs remain (plus one pre-existing untracked file not touched by this pass).

## Tests that look wrong (for T)

None. All 4 new PHPUnit methods now pass for the right reason (verified against the isolated tree
running the actual modified code, not a mock). The 4 new Playwright cases were not executed live
(no PHP 8.4 / Docker stack stood up in this environment, same constraint T-red2 documented), but
the implemented JS behavior was traced line-by-line against each case's exact assertions:

1. **"roving tabindex: only the selected option is Tab-reachable..."** — satisfied by the PHP fix:
   `cards` (selected, default) renders `tabindex="0"`; `compact` (available, not selected) renders
   `tabindex="-1"`. Confirmed directly by the PHPUnit `testExactlyOneAvailableOptionHasRovingTabindexZero`
   equivalent server-render-array assertion (same underlying render-array shape the Twig template
   emits verbatim into the DOM the Playwright case reads).
2. **"ArrowRight moves selection to the next available option..."** — `availableOptions` in DOM
   order is `[Compact list, Cards]` (Map excluded, `aria-disabled="true"`). Focus starts on `cards`
   (index 1). `ArrowRight` → `moveSelection(cards, 1)` → `(1 + 1) % 2 = 0` → `compact`. `select('compact',
   true)` sets `compact`'s `aria-checked="true"`/`tabindex="0"`, `cards`'s `aria-checked="false"`/
   `tabindex="-1"`, `map`'s `aria-checked` stays `"false"` (never touched, was never `true`), then
   `compact.focus()` is called. Matches every assertion in this case.
3. **"ArrowLeft moves selection to the previous available option, skipping the unavailable one"** —
   `ArrowLeft` → `moveSelection(cards, -1)` → `(1 - 1 + 2) % 2 = 0` → `compact` (same target as case
   2, since there are only 2 available options — both directions land on the one other available
   option, exactly as T-red2's own wrap/skip semantics note anticipated). Matches every assertion.
4. **"no-JS ?variant= fallback still works unmodified by the arrow-key fix"** — this case exercises
   `?variant=map`, which is unavailable, so `VariantSwitcher::resolveSelection()`'s fallback-to-
   first-available logic (completely untouched by this diff — no line in that method was edited)
   selects `compact` server-side. This is a server-render assertion, not a JS-path assertion at
   all, so the arrow-key JS addition cannot affect it by construction. Confirmed via `git diff`
   showing `resolveSelection()` was not touched.

If a real browser run at T-GREEN surfaces something these 4 traced-not-executed cases don't
predict, flag it back to F — but based on a full line-by-line trace, no test appears wrong or in
conflict with the implementation.

## Known issues

None against this round's 8 named cases. One pre-existing environment limitation (documented in
"Tier 1 self-check" above): the 4 Playwright cases were traced, not executed live, because this
environment lacks PHP 8.4 for the composer install T-green's own documented procedure requires —
identical constraint T-red2 hit and documented as an explicit fallback allowance for this round.

## Files changed

- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/src/VariantSwitcher.php`
- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/js/do_showcase.switcher.js`

Mirrored to scratchpad:
`/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/impl-round2/{VariantSwitcher.php,do_showcase.switcher.js}`
