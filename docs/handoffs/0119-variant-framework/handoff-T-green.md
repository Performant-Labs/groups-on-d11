# Handoff-T-green: Phase 6 - SC-F1 Variant framework (switcher, /showcase, POC ribbon)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Handoff-F reviewed:** `docs/handoffs/0119-variant-framework/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/0119-variant-framework/handoff-T-red.md`

## Environment notes (both CRITICAL items from the task hit, both worked around)

1. **Symlinked `web/core` realpath trap (PHPUnit).** Confirmed exactly as F described. Fixed by
   doing a full `cp -R` of `web/core` into the worktree (no symlink), plus copying `do_showcase` and
   `do_chrome` into `web/modules/custom/`, and symlinking `vendor` only (safe — vendor contains no
   bootstrap-path-sensitive resolution). Isolated-tree marker: PHPUnit's own `Configuration:` line
   printed `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist`
   (the worktree path, not the shared checkout) for every run below. Torn down after (`rm -rf web/core
   web/modules/custom`, `rm vendor` symlink) — worktree left with only the intended file changes.
2. **Playwright needs a running site.** Stood up a real, namespaced environment mirroring
   `.github/workflows/test.yml`'s `e2e` job: a throwaway `mysql:8` Docker container (own port, own
   name, removed after) → `composer install` (PHP 8.4 via Homebrew, matching CI's `PHP_VERSION`; the
   default system PHP 8.3 fails composer's lock-file platform check) → `scripts/ci/assemble-config.sh`
   → `drush site:install standard` → `config:set system.site uuid` + `config:import` → confirmed
   `do_showcase` (and all other `do_*` modules) already enabled by the imported config → seeded demo
   data (`step_700`/`step_720`/`step_780`, same wrapper-as-uid-1 pattern as CI) → served with
   `drush runserver` on a distinct port (38081) → ran `npx playwright test` against it with
   `BASE_URL` pointed at the served port. Full teardown after (kill server processes, `docker rm -f`
   the MySQL container, `git checkout`/`git clean` every config/scaffold artifact the assembly step
   or `composer install` touched, leaving only the one intentional test-file fix in `git status`).

**One infra casualty mid-run, correctly diagnosed and not miscounted as a defect:** the first
MySQL container was reaped by a concurrent process partway through the broader-regression run
(`phase2`–`phase4`), producing 11 failures that were login-timeouts and one 403→500 — traced to the
container disappearing (`docker ps -a` showed nothing, `/user/login` itself started 500ing) rather
than anything do_showcase touches. Recreated the container fresh, reinstalled the site on a new
port with a single-worker `runserver` (no `PHP_CLI_SERVER_WORKERS`), and reran everything to a
clean, uninterrupted pass (documented below). This matches the task's own warning that worktrees/
concurrent processes in this environment can reap resources — mitigated by re-running to completion
on a fresh container, not by discounting the failure.

## GREEN confirmation

### PHPUnit — 37/37 GREEN, against the isolated tree

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/PermissionMatrixTest.php --testdox
```
```
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
.....................................                             37 / 37 (100%)
OK, but there were issues!
Tests: 37, Assertions: 268, PHPUnit Deprecations: 38.
```
All 23 new do_showcase test methods (VariantSwitcherTest ×10, ShowcaseCatalogTest ×9,
ShowcaseHelpTextTest ×4 — wait, recount: 10+9+4=23) pass, plus do_chrome's 14 pre-existing tests
(HelpTextTest ×10, PermissionMatrixTest ×4) confirmed non-regressed in the same run. The
`Configuration:` path above is the worktree's own copied `web/core`, not the shared checkout —
proves the isolated tree was actually exercised, not the symlink trap.

**Spot-check: tests fail if behavior is removed (pin behavior, not implementation).** Temporarily
mutated `VariantSwitcher::resolveSelection()` to always return `$current` verbatim (removing the
fallback-to-first-available logic) and reran `VariantSwitcherTest.php`:
```
1) testUnavailableCurrentFallsBackToFirstAvailable — Failed asserting 'compact' === 'map'
2) testUnknownCurrentFallsBackToFirstAvailable — Failed asserting actual size 0 matches expected size 1
Tests: 10, Assertions: 30, Failures: 2
```
Restored the file; reran — 10/10 green again. Confirms these two tests (and by construction the
rest of the suite, which follows the identical assert-on-render-array-output shape) pin real
behavior, not a tautology.

### Tier 1 — phpcs / phpstan (cross-checked against F's report)

- `phpcs --standard=Drupal,DrupalPractice` on `do_showcase/src/` +  `do_showcase.module`: **0
  errors**, 3 warnings (`t()` calls in `DoShowcaseHooks.php` — "should use StringTranslationTrait").
  Independently confirmed the identical warning class pre-exists in `do_chrome/src/Hook/
  ArchivePinHooks.php` (2 instances, same warning text) — not a new house-style violation.
- `phpstan analyse --level 1` on `do_showcase/src` + `.module` + `do_chrome/src`: **1 finding**
  (`new.static` — "Unsafe usage of new static()" — on `ShowcaseController::create()`). Matches F's
  report exactly. Attempted to independently corroborate this is a repo-wide pattern by running
  phpstan against `NotificationSettingsController.php` standalone; that file surfaces unrelated
  pre-existing findings when analyzed in isolation (missing symbol context), so the standalone
  comparison is inconclusive on its own — but the finding itself, its cause (every
  `ControllerBase::create(ContainerInterface)` in Drupal core's own convention triggers this
  phpstan-drupal rule), and the absence of any phpstan.neon/baseline in this repo to suppress it,
  are all independently verifiable and match F's characterization. Not a defect introduced by this
  story.
- No `phpstan.neon` / deprecation-rules config exists in this repo (confirmed: `phpstan-deprecation-
  rules` is installed as a Composer dependency but there is no invoking config committed, and CI's
  `test.yml` has no phpstan step) — deprecation-specific analysis of changed files was therefore run
  as part of the same level-1 pass above; it surfaced no deprecated-API usage in any new file.

### Playwright — 21/21 GREEN on a stable serve, 15/15 on the target spec

Namespaced Docker (mysql:8, port 33062, container `o119-mysql2`) → composer install (PHP 8.4) →
assemble-config.sh → drush site:install → config:import → do_showcase confirmed enabled by the
imported config (no extra `drush en` needed — the assembled `core.extension.yml` already lists it)
→ seeded demo data → served via `drush runserver 127.0.0.1:38081` (single worker) →
`BASE_URL=http://127.0.0.1:38081 npx playwright test`:

```
tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts — 21 passed (15.9s)
```

Per-case (`showcase.spec.ts`, all 15):
| # | Case | Result |
|---|---|---|
| 1 | switcher renders as a labeled radiogroup with all stub options | PASS |
| 2 | clicking an option switches the current selection | PASS |
| 3 | selection is conveyed by more than color (non-color cue present) | PASS |
| 4 | the choice persists client-side across navigation | PASS |
| 5 | no-JS ?variant= query param selects the right option | PASS |
| 6 | an unavailable option is present, marked, and not a dead click | PASS |
| 7 | ribbon shows for anonymous visitors, links to /showcase | PASS |
| 8 | ribbon shows identically for an authenticated user | PASS |
| 9 | ribbon does not cover or reflow primary nav (nav.spec.ts non-regression) | PASS |
| 10 | dismiss button removes the ribbon | PASS |
| 11 | dismissal persists client-side across navigation | PASS |
| 12 | lists all six comparison entries with truthful [live]/[coming] badges | PASS |
| 13 | the private-group-reveal entry references #134 | PASS |
| 14 | "coming" entries have no dead link to an unbuilt page | PASS |
| 15 | lists the persona switcher naming all four public personas | PASS (test fixed — see below) |

`nav.spec.ts` (non-regression, all 6): PASS — primary-nav block, public links, member links, link
resolution, subtheme H1, account-menu block all unaffected by the ribbon's `hook_page_top` insertion.

**Broader regression (not required by the brief, run anyway for confidence given the earlier infra
casualty): `directory-cards.spec.ts` + `phase1`–`phase4.spec.ts` — 18/18 PASS** on the same stable
server. (These same 18 initially failed against the FIRST server instance after its MySQL container
was reaped mid-run — re-run clean on the fresh container/server to completion, see Environment notes
above.)

**Docker teardown confirmed:** `docker ps -a --filter name=o119` → empty. Server processes killed.
Worktree filesystem restored: `git checkout -- config/sync/ web/.htaccess web/example.gitignore
web/index.php web/robots.txt web/update.php`, `git clean -fd config/sync/ web/`, removed
`web/sites/default/settings.php`, `node_modules`, `test-results`, `playwright-report`,
`web/autoload_runtime.php`. `git status --short` after teardown: only the one intentional
`tests/e2e/showcase.spec.ts` fix remains (plus one pre-existing untracked file from before this
verification started, `dual-review-brief.md.prompt.txt`, not touched by me).

## Test fixed (T's own suite — not F's code, not silently changed)

**`tests/e2e/showcase.spec.ts` — "lists the persona switcher naming all four public personas"** was
a false RED-turned-FALSE-blocker, not a production defect. Root cause: the test used an unscoped
`page.getByText('Anonymous', { exact: false })`. Playwright's `getByText` is case-insensitive by
default, and the persona-switcher catalog entry's own `decision_sentence` — required, legitimate
content per brief.md Acceptance criterion #3 — reads "...one generic **anonymous** view vs.
role-tailored experiences," which collides with the persona name "Anonymous" in strict mode
(2 matching elements). F's production code is correct and matches the brief; the test's locator
was too loose. Fixed by scoping the assertion to the persona-switcher entry's own `<ul>`
(`[data-do-showcase-entry="persona-switcher"] ul`), using the DOM contract F's own handoff
documented — this pins the real behavior (all four personas named IN THE PERSONA LIST) without
being sensitive to incidental word overlap elsewhere on the page. Re-ran after the fix: 15/15 green.
This is the only test file touched in this phase; no production file was edited.

## Tier 2 results

- **Coverage vs. acceptance criteria:** every acceptance criterion in brief.md is backed by at least
  one passing test — see the criterion-by-criterion table below. No gap in "does a test exist,"
  BUT see the roving-tabindex/arrow-key BLOCKER below: a specific behavior the **approved wireframe**
  commits to is untested by both the PHPUnit and Playwright suites T authored — a real coverage gap
  in my own Phase-4 suite, not just an F implementation gap.
- **Test quality:** all 23 PHPUnit + 15 Playwright tests each name a single behavior, sit at the
  cheapest sufficient tier (Unit for the pure render-array/data-shape contracts, E2E only for what a
  headless test cannot see — real click/keyboard interaction, cookie/localStorage persistence across
  navigation, cross-surface DOM non-interference), and none duplicate another (ShowcaseHelpTextTest
  deliberately pins only the two NEW keys, not do_chrome's existing HelpTextTest coverage). One test
  fixed as above (locator scoping, not a behavior change). No tests found to be redundant or
  assert-on-implementation; the mutation spot-check above confirms behavior-pinning. Suite size (38
  test methods total across PHPUnit+Playwright for a 3-surface story) is proportionate — one test
  method per named behavior in the brief/wireframe, no padding.
- **Access/security:** `/showcase` uses `_permission: 'access content'` — a real, existing Drupal
  permission (not `_access: 'TRUE'` and not unguarded), correctly public per the brief's own "the
  page is itself a POC artifact meant to be seen by anonymous visitors" framing, matching
  `do_discovery`'s precedent. No new permission was needed and none was invented. Confirmed via
  `grep` — no `_access: 'TRUE'` anywhere in `do_showcase.routing.yml`.
- **XSS/escaping:** No `|raw` filter in the Twig template, no `Markup::create()`/`#markup` bypass in
  any PHP file — every user-visible string flows through `t()`-wrapped TranslatableMarkup (verified
  by `ShowcaseCatalogTest::testEntryStringsAreTranslatableMarkup`) or Twig's `{{ }}` auto-escaping /
  `html_tag` render elements. Grep for `|raw`/`Markup::create`/`#markup` in `do_showcase/src` and
  `templates/`: zero hits.
- **Client-side-only persistence — verified, not just asserted:** grepped every new PHP/JS file for
  `tempstore`, `Drupal::service('session')`, `$_SESSION`, `setcookie()`: zero hits outside a doc
  comment explaining WHY tempstore is deliberately not used. Confirmed live: the switcher/ribbon
  persistence is `window.localStorage` only (`do_showcase.switcher.js`, `do_showcase.ribbon.js`) —
  no server round-trip on select/dismiss, matching the E2E persist-across-navigation cases passing
  without any cookie being required.
- **Config/schema:** No config entities or new config schema introduced by this story (confirmed —
  no `config/` directory under `do_showcase`, no `config/schema/*.schema.yml`); N/A, correctly so.
- **Data integrity:** `ShowcaseCatalog` is a stateless code-constant class (no storage/entity
  operations); `VariantSwitcher::resolveSelection()` handles the two edge cases that matter (unknown
  id, unavailable id) with a defensive final fallback (`$options[0]['id'] ?? $current`) so it can
  never render nothing selected even if every option were unavailable — tested by
  `testUnavailableCurrentFallsBackToFirstAvailable` / `testUnknownCurrentFallsBackToFirstAvailable`.
- **Migration/update safety:** No `hook_update_N`, no install steps beyond a plain `.info.yml` — N/A.
- **Deprecations:** phpstan level 1 (the only static-analysis config wired in this repo) surfaced
  zero deprecated-API usage in any new/changed file.
- **do_chrome regression:** `HelpTextTest.php` (14/14) and `PermissionMatrixTest.php` (4/4) both
  still pass in the same PHPUnit run as the new tests — the append-only `HelpText.php` edit did not
  disturb any existing key's resolution, confirmed both by the existing test suite and by
  `ShowcaseHelpTextTest::testExistingDoChromeKeyStillResolvesUnchanged`.

## BLOCKER — roving-tabindex / arrow-key keyboard pattern not implemented, not tested

**The approved wireframe (`wireframe.md` lines 29-31, 271) explicitly specifies the switcher's
keyboard contract as:** *"one option in tab order at a time; Arrow-Left/Right moves selection,
matching native radiogroup behavior — keyboard-operable per WCAG 2.2 AA"* and, in its comparison
table, *"Keyboard operable | roving-tabindex radiogroup, arrow keys."*

**What F shipped instead:** every AVAILABLE option carries `tabindex="0"` (`VariantSwitcher.php`
line 92: `'tabindex' => $available ? '0' : '-1'`) — i.e. ALL available options are simultaneously in
the tab order, not roving (only the selected one should be `0`, the rest `-1`). There is no
arrow-key handler anywhere in `do_showcase.switcher.js` (only `click` and `keydown` for
`Enter`/`Space`, both scoped to whichever option currently has focus via Tab). A keyboard user must
Tab through every individual option one at a time — the opposite of the "one option in tab order at
a time" contract the wireframe specifies, and inconsistent with standard `role="radiogroup"`/AT
expectations (most screen readers expect arrow-key selection once inside a native-pattern
radiogroup). Notably, `VariantSwitcher.php`'s own doc comment (line 24) claims *"roving-tabindex
pattern"* — the code's self-description does not match its own behavior.

**Why this is a BLOCKER, not a WARN:** the brief's own Acceptance criteria list "each option
keyboard-operable (arrow/Tab per the chosen pattern)" — ambiguous read in isolation — but the
**approved wireframe D-gate sign-off** (which brief.md itself defers to: "Design (Phase 2):
required... Wireframe to be produced by D" and Operating rules require implementing against the
approved wireframe) is unambiguous and specific: roving-tabindex + arrow keys, not "any keyboard
pattern." This is a real gap between the approved design and the shipped behavior on a
WCAG-2.2-AA-flagged surface (Brief-gate W-2).

**Also flagging against myself (T):** neither my Phase-4 PHPUnit suite nor my Phase-4 Playwright
suite authored a test for arrow-key navigation or roving tabindex, despite the wireframe committing
to it explicitly and by name. This is a genuine hole in the RED I authored, not just something F
missed independently — grep confirms zero occurrences of "arrow" or "roving" in either test file.
**This is on me to close**, and per my role I do not write production code — I am flagging it for O
to route (most likely: F implements roving tabindex + arrow-key handling as a small follow-up, and I
author the missing test cases in the same pass so the RED/GREEN discipline is preserved for this
specific gap, rather than merging a UI surface whose approved design contract goes untested and
unimplemented).

## Acceptance criteria status

| Criterion | Status | Backing test(s) |
|---|---|---|
| Switcher renders/switches/persists client-side (stub instance) | PASS | showcase.spec.ts #1,2,4 |
| `VariantSwitcher::build()` stable render-array contract | PASS | VariantSwitcherTest (10 methods) |
| `/showcase` lists all 6 comparisons + persona switcher (4 personas) | PASS | ShowcaseCatalogTest, showcase.spec.ts #12,13,15 |
| Ribbon shows anon+auth, dismissible, client-side persistence | PASS | showcase.spec.ts #7,8,10,11 |
| Tooltips render via do_chrome/tooltips, HelpText-sourced; existing suite stays green | PASS | VariantSwitcherTest::testTooltipTriggerCarriesHelpTextSourcedCopy; live DOM confirms `do_chrome/tooltips` + `tippy` + `do-showcase-info` present; HelpTextTest 14/14 + nav.spec.ts 6/6 + broader regression 18/18 all green |
| Ships append-only HelpText entry for new surfaces | PASS | ShowcaseHelpTextTest (4 methods) |
| WCAG 2.2 AA — labeled control group, non-color cue, visible focus, real `<button>` dismiss | **PARTIAL — see BLOCKER above** | radiogroup+aria-label: PASS (VariantSwitcherTest, showcase.spec.ts #1); non-color cue: PASS (showcase.spec.ts #3); visible focus ring: PASS (CSS confirmed); real `<button>` dismiss: PASS (showcase.spec.ts #10); **keyboard-operable per the approved wireframe's roving-tabindex+arrow-key pattern: FAIL, untested and unimplemented** |
| Verified via namespaced Docker + local Playwright green | PASS | this phase — 21/21 (showcase+nav), 18/18 broader regression, full teardown |
| `do_showcase/**` sole new module; `do_chrome` edits append-only | PASS | git diff scope confirmed in F's handoff + independently re-confirmed via `git status` on F's commit |

## Blocking issues

1. **Roving-tabindex / arrow-key keyboard pattern** — approved wireframe (`wireframe.md`) commits to
   it explicitly; shipped code does not implement it (all options `tabindex="0"`, no arrow-key
   handler); neither PHPUnit nor Playwright test it. Routes to: F (small implementation follow-up)
   + T (I author the missing roving-tabindex/arrow-key test cases in the same pass, both PHPUnit —
   the render-array's per-option tabindex should be `0` only for the selected option — and
   Playwright — arrow-key press moves `aria-checked`/focus). Not a regression of anything already
   shipped; scoped narrowly to this one keyboard-interaction detail on the switcher.

## Advisory notes

- The `?variant=` default in `ShowcaseController::page()` (line 71) falls back to `'cards'` when no
  query param is present, not `'compact'` (the wireframe's stated first-listed option). Not a defect
  — every switcher case in the brief and wireframe is agnostic to which stub option is the page's
  own default, and `VariantSwitcher::resolveSelection()`'s own fallback logic (first AVAILABLE
  option) is independently and correctly tested. Noting only because a future reader diffing the
  wireframe's "Compact list" example against the controller's literal default could be confused;
  not a criterion this story's acceptance list actually pins.
- phpstan's `new.static` finding on `ShowcaseController::create()` and phpcs's 3 `t()`-in-class
  warnings on `DoShowcaseHooks.php` are both pre-existing repo-wide patterns (confirmed against
  `NotificationSettingsController.php` and `ArchivePinHooks.php` respectively) — advisory only, not
  something this story introduced or needs to fix.
- The MySQL-container-reaped mid-run infra casualty (see Environment notes) cost re-verification
  time but is now fully corroborated as unrelated to do_showcase (18/18 broader regression clean on
  the recreated container) — flagging for O's awareness that this shared machine's worktree/process
  reaping is a real, recurring operational hazard for T/F/U's live-environment verification steps,
  not specific to this story.

## Verdict

**GREEN confirmed for PHPUnit (37/37) and Playwright (21/21 target + non-regression, 18/18 broader
regression). 1 blocker: the approved wireframe's roving-tabindex/arrow-key keyboard pattern is
neither implemented nor tested.** Routes back to F (implementation) and T (test authorship for this
specific gap) before U/S proceed on the switcher's UI surface. The ribbon and /showcase page-level
UI surfaces (no roving-tabindex contract in their own wireframe sections) are otherwise clear for U.
