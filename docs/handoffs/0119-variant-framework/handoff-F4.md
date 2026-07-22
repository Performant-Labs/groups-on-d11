# Handoff-F4: Phase 5 (fix loop round 4) — diff-gate BLOCKs B-1, B-2 (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Worktree:** `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework`
**O's adjudication reviewed:** diff-gate (o4-mini) commit `caa1245` — "BLOCK x5 adjudicated (3 real, 2
rejected)"; this round addresses the 2 real BLOCKs O assigned to F (B-1, B-2). The third real BLOCK is
out of this round's scope per the task brief (F was instructed to fix exactly these two).

## Scope

Two adjudicated real defects, fixed exactly as specified:
- **B-2** — "per session" persistence used `window.localStorage` (persists across browser restarts)
  instead of `window.sessionStorage` (clears at session end), contradicting the issue/brief's "per
  session" contract.
- **B-1** — `DoShowcaseHooks::pageTop()` attached `do_chrome/tooltips` and set
  `drupalSettings.doShowcase.ribbonTooltip` from `HelpText::get('showcase.ribbon')`, but no ribbon
  element carried `data-do-tooltip` and `ribbon.js` never read `ribbonTooltip` — dead/unconsumed
  wiring.

## What was done

- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` — switched
  `window.localStorage` → `window.sessionStorage` (both the persist-on-select write and the
  restore-on-load read); doc-comment updated from "localStorage" to "sessionStorage (per-session)";
  try/catch fallback comments updated to say "sessionStorage unavailable" but logic/behavior
  unchanged (falls back to server-rendered default on failure, exactly as before).
- `docs/groups/modules/do_showcase/js/do_showcase.ribbon.js` — same substitution: both the
  dismiss-flag write (`setItem`) and the on-load dismissed-check (`getItem`) now use
  `window.sessionStorage`; doc-comment updated to "sessionStorage (per-session)"; fallback behavior
  unchanged ("the ribbon simply always shows" on storage failure).
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — removed the dead ribbon-tooltip
  wiring: dropped the `use Drupal\do_chrome\HelpText;` import (no longer used in this file), removed
  `do_chrome/tooltips` from the ribbon's `#attached.library`, and removed the
  `drupalSettings.doShowcase.ribbonTooltip` block entirely. Added a doc-comment on `pageTop()`
  explaining the ribbon intentionally carries no ⓘ trigger per the approved wireframe, and the
  issue's "carries a do_chrome tooltip" requirement is satisfied by the switcher (unchanged, still
  correct). Also updated the class-level doc-comment's "cookie/localStorage" reference to
  "sessionStorage (per-session)" for consistency with the B-2 fix.
- `docs/groups/modules/do_chrome/src/HelpText.php` — removed the now-unused `'showcase.ribbon' => ...`
  entry (the key I — do_showcase, via this same story — had appended earlier; append-only surface,
  did not touch any other module's keys). Replaced with an explanatory comment (no key) so a future
  reader understands why Surface 3 has no HelpText entry, referencing this diff-gate round.
  `showcase.switcher.directory.layout` (the switcher's tooltip key, which IS consumed) is untouched.

## Design decisions

**B-2 — sessionStorage over cookie.** The task brief pre-specified sessionStorage as the fix (not a
cookie), citing that a cookie would touch request headers / anon page cache, which sessionStorage
(pure client-side, JS-only) does not. Followed as directed — no alternative considered independently
since the brief's reasoning is sound and matches the existing graceful-degradation architecture (same
try/catch shape, same keys, only the storage object changed).

**B-1 — remove vs. add trigger.** Read `wireframe.md` Surface 3 (lines 204-263) and `handoff-D.md`
(lines 33-39) first, per the brief's instruction. Both sources depict the ribbon as exactly
`{POC text + "See what it compares →" link + dismiss ✕}` — no ⓘ glyph, no tooltip trigger anywhere in
the ASCII mock or D's screen/state descriptions for Surface 3. The wireframe's cross-surface AA table
(wireframe.md lines 266-276) also lists the ribbon's affordances as "banner text itself is the label;
dismiss button has aria-label" with no tooltip-trigger row for the ribbon (contrast Surface 1, which
explicitly has one ⓘ per switcher instance). The issue's "every switcher instance carries a do_chrome
tooltip" requirement names the switcher specifically, and that requirement is already correctly
implemented (unchanged this round — verified live, see below). Concluded: the ribbon-tooltip wiring
was dead code from an earlier round that over-attached `do_chrome/tooltips` + a `ribbonTooltip`
setting without a consuming element, not an intentional-but-incomplete feature. **Chose REMOVE** (the
wireframe-faithful, minimal fix) over ADD-a-trigger, per the brief's stated default and the wireframe
evidence.

## Reuse / extend-vs-new

No new object created. Both fixes edit existing files within `do_showcase` (own module) and remove
one entry `do_showcase` itself had appended to `do_chrome\HelpText` (the shared, append-only copy
store) — no parallel copy mechanism introduced, and the removal only touches the key this story added,
not any other module's entries (`demo.foundation`, `permissions.panel.*`, etc. all untouched, and the
existing `ShowcaseHelpTextTest.php`/`HelpTextTest.php` non-regression assertions for those confirm it,
see Tier 1 below).

## Architecture notes for A

- No new services, routes, permissions, schema, or config changes.
- `do_showcase/src/Hook/DoShowcaseHooks.php`: removed an unused `use` import and shrank the ribbon's
  `#attached` array (library list now `['do_showcase/ribbon']` only, `drupalSettings` key removed
  entirely since it had no other entries).
- `do_chrome/src/HelpText.php`: removed one array entry (`showcase.ribbon`) from the shared, append-
  only copy-store class. This is a **shared-code edit**, but it is removing a key that `do_showcase`
  itself added in an earlier round of this same story (not another agent's/module's key), per the
  task brief's explicit authorization ("you are removing the key YOU added earlier in this same
  story; do not touch any other module's keys") — not a drive-by refactor of other-owned code.
- Both JS files: mechanical API substitution (`window.localStorage` → `window.sessionStorage`), same
  method calls (`getItem`/`setItem`), same keys, same try/catch shape — no new client-side mechanism.

## Deviations from spec / wireframe

None. B-1's fix makes the code conform to the wireframe (it previously deviated by attaching dead
tooltip wiring the wireframe never called for); B-2's fix makes the code conform to the brief's
literal "per session" wording (it previously deviated by using a persistence mechanism that survives
browser restarts).

## Tier 1 self-check (incl. tests now GREEN)

**Tree marker (real `composer install`, not the shared symlinked checkout):**
```
Runtime:       PHP 8.5.6
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```

**PHPUnit** (`do_showcase` + `do_chrome` full authored suite, same 5-file target as prior rounds):
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/PermissionMatrixTest.php --testdox
```
Result: **42/42 GREEN**, 277 assertions, 0 failures (43 pre-existing PHPUnit-version-vs-core
deprecation notices, unrelated). `ShowcaseHelpTextTest` (which pins the `showcase.switcher.*` keys and
a `demo.foundation` non-regression check, but never asserted `showcase.ribbon`) passed unmodified —
**no test broke from the key removal.**

**phpcs** (`Drupal,DrupalPractice`) on the 4 changed files: 0 **new** findings. `DoShowcaseHooks.php`
and `HelpText.php` retain pre-existing findings (comment-indentation / array-indentation / line-length
on lines predating this round, confirmed via `git stash` diff-before/after — identical finding sets on
identical line numbers both with and without this round's diff). Both JS files show pre-existing
"TRUE/FALSE/NULL must be uppercase" findings — these are phpcs Drupal-standard PHP sniffs misapplied
to legitimate lowercase JS keywords (`true`/`false`/`null` are correct in JS), present before this
round's edit at the same relative density (5 in switcher.js, 1 in ribbon.js, unchanged in count and
shape) — not introduced by this round.

**phpstan** (level 1) on `DoShowcaseHooks.php` + `HelpText.php`: **`[OK] No errors`**.

**Module install/enable:** real `drush site:install standard` + `config:import` (after fixing the
random `config_sync_directory` + `system.site` UUID mismatch, same recipe T's round-3 handoff
documented) → clean. `drush pm:list --status=enabled` confirms both `do_showcase` and `do_chrome`
`Enabled`.

**Live smoke check** (`php -S` against the real installed site, `/showcase` route):
- `GET /showcase` → `200`.
- Ribbon renders (`id="do-showcase-ribbon"` present once), dismiss button present
  (`data-do-showcase-dismiss="true"`).
- **No `ribbonTooltip` string anywhere in the response body** (confirms the dead
  `drupalSettings`/library wiring is gone).
- Switcher's tooltip trigger still carries the correct copy:
  `data-do-tooltip="Compact list favors scanning many groups fast; Cards shows more per-group
  detail; Map plots groups geographically."` — confirms the switcher's (correct, unchanged) tooltip
  wiring still works after the ribbon-side removal.
- `GET /` → `200` (no regression to the ribbon's site-wide attach).

Full teardown performed (Docker container removed, `git checkout --`/`git clean -fd` on
`config/sync/`+`web/`, scaffold files removed); `git status --short` after teardown shows only the
4 intended production-file diffs.

## Tests that look wrong (for T)

None. No authored test needed editing or looked incorrect; `ShowcaseHelpTextTest.php` never asserted
the removed `showcase.ribbon` key in the first place, so its scope already matched this round's fix.

## Known issues

None. Both B-1 and B-2 fully resolved per O's adjudication and the task brief's exact instructions.

## Note to T — what to re-verify live

- **Per-session persistence (B-2):** the switcher's selected-variant choice and the ribbon's
  dismissed state must now survive **navigation within the same browser session/tab** (same as
  before — sessionStorage persists across page loads in the same tab) but must **NOT** survive a
  fresh session (new tab in some browsers, or a full browser restart clears sessionStorage per
  origin). This PHPUnit/CI environment only ever exercises a single fresh session per test run, so
  the "does NOT survive session end" half of the contract is not independently provable by the
  existing Unit suite (sessionStorage vs. localStorage is a browser-API distinction PHPUnit can't
  observe) — this needs a live/Playwright check: (a) select a variant / dismiss the ribbon, (b)
  navigate to another page in the SAME tab, confirm the choice/dismissal persists (proves
  navigation-persistence still works), (c) open the same URL in a **new, unrelated browser context**
  (e.g. Playwright's `browser.newContext()`, not just a new page/tab in the same context) and confirm
  the switcher/ribbon revert to server-rendered defaults (proves it is NOT using localStorage
  anymore).
- **Ribbon tooltip removed:** confirm no ⓘ trigger appears on the ribbon in a real browser render, and
  that no console error/warning appears from `do_chrome/tooltips` (that library is no longer attached
  to the ribbon's render array at all — but IS still attached wherever the switcher renders, since the
  switcher's tooltip wiring is unchanged).
- No JS changes could be executed with a headless PHPUnit/phpstan/phpcs pass in this round (no
  browser) — the `window.localStorage`→`window.sessionStorage` substitution was verified by careful
  read/diff of both files (identical API shape, `getItem`/`setItem` signatures unchanged) plus a live
  curl smoke-check of the surrounding server-rendered markup, but the actual runtime persistence
  behavior needs T's live/Playwright re-verification per the above.

## Files changed

- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/js/do_showcase.switcher.js`
- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/js/do_showcase.ribbon.js`
- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`
- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_chrome/src/HelpText.php`

Mirrored (scratchpad, per task instructions):
- `/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/impl-round4/do_showcase.switcher.js`
- `/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/impl-round4/do_showcase.ribbon.js`
- `/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/impl-round4/DoShowcaseHooks.php`
- `/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/impl-round4/HelpText.php`
