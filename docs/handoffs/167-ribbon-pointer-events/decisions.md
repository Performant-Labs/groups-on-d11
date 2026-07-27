# Decisions — #167 ribbon pointer-events fix

## A (plan review) — 2026-07-24

PASS. Plan extends the existing `.do-showcase-ribbon` rule in `docs/groups/modules/do_showcase/css/do_showcase.css` and adds one opt-in rule for anchors + dismiss button. Confirmed against render array (DoShowcaseHooks::pageTop lines 179-212): the ribbon container holds exactly a `<span>` (text), one `#type => link` (anchor), and one `<button.do-showcase-ribbon-dismiss>` — no other interactive descendants. The JS (do_showcase.ribbon.js) listens only on `[data-do-showcase-dismiss]`, which is the same button; `pointer-events: auto` on `.do-showcase-ribbon-dismiss` keeps it clickable. No new file, no new class, no duplicated selector blocks. No neighboring-CSS drift risk (persona-switcher rules live in a separate module and do not target ribbon selectors). Z-index remains 1000 but with `pointer-events: none` on the container the ribbon no longer intercepts clicks on covered controls; keyboard focus is unaffected (pointer-events does not gate Tab traversal) and the dismiss button + link remain reachable via Tab because they opt back in. Existing `:focus-visible` outlines still apply.
## T-RED (author) — 2026-07-24

**Spec:** `tests/e2e/showcase-ribbon-pointer-events.spec.ts`

**Tests added** (all in `test.describe('#167 showcase ribbon does not intercept pointer events')`, no `beforeEach` dismiss — the whole point is proving dismissal is no longer required for the covered control):

1. `the ribbon does not intercept a click meant for the page beneath it` — loads `/`, confirms `#do-showcase-ribbon` is visible (undismissed), takes its `boundingBox()`, computes a safe interior point (horizontal middle, vertical middle — avoids the leftmost text/link region and rightmost dismiss button), then via `page.evaluate` calls `document.elementFromPoint(midX, midY)` and asserts the hit element is neither the ribbon nor a descendant of it (`ribbonEl === hit || ribbonEl.contains(hit)` must be `false`). This is the load-bearing RED assertion.
2. `the ribbon's own "See what it compares ->" link remains clickable` — same `elementFromPoint` technique targeted at `#do-showcase-ribbon a`'s own center; asserts the hit IS the anchor or a descendant, pinning the opt-in `pointer-events: auto` rule for ribbon anchors.
3. `the ribbon's dismiss button remains clickable and dismisses the ribbon` — same technique targeted at `.do-showcase-ribbon-dismiss`'s center (asserts hit IS the button or descendant), then actually clicks it and asserts `#do-showcase-ribbon` has count 0 afterward (end-to-end confirmation the button still works post-fix).

**Tier placement:** all three are E2E (Playwright) because the bug is a real-browser rendering/layering effect (`position: fixed` + `z-index` + default `pointer-events` compositing) that only exists once actual CSS is loaded and laid out by a browser engine — not observable at unit/kernel tier.

**RED-by-construction reasoning:**
- Read current `docs/groups/modules/do_showcase/css/do_showcase.css` (lines 13-47): `.do-showcase-ribbon` sets `position: fixed; top/left/right; z-index: 1000` etc. but **no `pointer-events` declaration anywhere** — so the UA default (`pointer-events: auto`) applies to the container and every descendant today.
- Because the container is `position: fixed` with `z-index: 1000`, it paints above all in-flow page content at any point inside its own box. With default `auto` pointer-events, `document.elementFromPoint()` at a coordinate inside the ribbon's painted rectangle returns the ribbon (or a child) on **every** page, including `/` where nothing else occupies that fixed top strip in the visible viewport paint order.
- Test 1 asserts the **opposite** (`isRibbonOrDescendant` must be `false`) — this is false on current `main`, so the test fails there **for the right reason**: the real, current CSS behavior contradicts the assertion. It is not a missing import/selector/setup error — `#do-showcase-ribbon` itself resolves and is visible fine on main (confirmed via `DoShowcaseHooks::pageTop()`, lines 177-213, which unconditionally attaches this render array via `hook_page_top` on every page).
- Tests 2 and 3 are **already true on `main`** (the anchor/button are the topmost elements at their own coordinates regardless of the container's implicit pointer-events) and **remain true after the fix** (the opt-in `pointer-events: auto` rules on `.do-showcase-ribbon a` / `.do-showcase-ribbon-dismiss` restore exactly this once the container flips to `none`). These aren't RED today — they're regression guards pinning the opt-in half of the fix, so a future "simplify to one blanket `pointer-events: none`" change breaks a test instead of silently reintroducing an inaccessible ribbon.
- Net: the suite is RED overall today (test 1 fails), and will be GREEN once F ships `.do-showcase-ribbon { pointer-events: none; }` + the opt-in anchor/button rules — matching the brief exactly, no other CSS change required.

**`npx playwright test --list tests/e2e/showcase-ribbon-pointer-events.spec.ts` output:**

```
Listing tests:
  [chromium] › showcase-ribbon-pointer-events.spec.ts:70:7 › #167 showcase ribbon does not intercept pointer events › the ribbon does not intercept a click meant for the page beneath it
  [chromium] › showcase-ribbon-pointer-events.spec.ts:107:7 › #167 showcase ribbon does not intercept pointer events › the ribbon's own "See what it compares ->" link remains clickable
  [chromium] › showcase-ribbon-pointer-events.spec.ts:140:7 › #167 showcase ribbon does not intercept pointer events › the ribbon's dismiss button remains clickable and dismisses the ribbon
Total: 3 tests in 1 file
```

Full-suite `--list` (all 30 spec files, 164 tests) also lists cleanly with the new file added — no collateral syntax breakage in other specs.

**Not run live:** DDEV was not confirmed up in this environment; per instructions, RED is confirmed by construction (reasoning above) rather than a live execution. `npm install` was required first (`node_modules` was absent in this worktree) — `@playwright/test@^1.49.1` per existing `package.json`; no dependency changes made.

**Ready for F:** RED is valid by construction. F may implement the CSS fix (`pointer-events: none` on `.do-showcase-ribbon`, `pointer-events: auto` opt-in on `.do-showcase-ribbon a`, `.do-showcase-ribbon button`, `.do-showcase-ribbon-dismiss`) against this spec.
## F (implement) — 2026-07-24

Edited `docs/groups/modules/do_showcase/css/do_showcase.css` only, per the pre-approved plan. Two additions, 10 lines total (`git diff --stat`: 1 file changed, 10 insertions(+), 0 deletions(-)): (1) added `pointer-events: none;` inside the existing `.do-showcase-ribbon` rule, placed immediately after `z-index: 1000;` as directed; (2) inserted one new rule block — `.do-showcase-ribbon a, .do-showcase-ribbon button, .do-showcase-ribbon-dismiss { pointer-events: auto; }` with the two-line explanatory comment referencing #167 — positioned between the existing `.do-showcase-ribbon a` rule and `.do-showcase-ribbon-dismiss` rule, exactly as specified. No restructuring of any other rule in the file. Confirmed via `git status --porcelain` that no other tracked file was touched; the only other worktree entries (`tests/e2e/showcase-ribbon-pointer-events.spec.ts`, this `decisions.md`) are T/A artifacts already present before this edit, not created or modified by F. No test file edited, no PHP/JS/markup touched, no `git add`/commit/push performed.
## T-GREEN (verify) — 2026-07-24

**DDEV bring-up (worktree `gm167-ribbon`, live seeded site):** `.ddev/config.yaml` in this
worktree still carried a copy-pasted `name: gm129-activity` (colliding with the
`groups-st3-events-112` worktree's registered project) — corrected to `name: gm167-ribbon` before
`ddev start`. Full bring-up: `ddev start` -> `ddev composer install --no-interaction --no-progress
--prefer-dist` (no `vendor/` existed yet) -> `ddev exec bash scripts/ci/assemble-config.sh` (must
run *inside* the container — the host has no `php` on PATH, so running the script from the host
shell fails at the core.extension.yml patch step with `php: command not found`) -> `ddev drush
site:install standard --account-name=admin --account-pass=admin --site-name='Groups on D11' -y` ->
set `system.site` uuid to match assembled `config/sync/system.site.yml` -> appended
`$settings['config_sync_directory'] = '../config/sync';` to `web/sites/default/settings.php`
(DDEV's scaffolded settings.php only ships this line **commented out**, so `drush config:import`
otherwise reads the default hashed `sites/default/files/sync` dir and rejects the import as
"empty... would delete all of your configuration" — same class of gotcha the CI workflow's own
comments already document) -> `ddev drush config:import -y` (131 files, clean) -> `do_tests
do_group_extras do_group_language do_group_mission do_group_pin do_multigroup do_notifications
do_profile_stats do_discovery do_showcase` already enabled via the assembled `core.extension.yml`
-> `ddev drush user:password admin admin` -> `pmu`/`en` cycle on `do_activity_feed` (#129
workaround, per CI) -> `cache:rebuild` -> ran seed steps in CI order: `step_700_demo_data.php`
(all-in-one, itself running steps 751/780/790/735/736/737 internally), `step_720_group_types.php`,
`step_780_nav_menu.php` (idempotent no-op, already seeded by 700), `step_790_persona_switcher.php`,
`step_7xx_backfill_activity.php`, `step_795_activity_feed_e2e_fixture.php`, `drush cron`,
`cache:rebuild`. Site served at `http://gm167-ribbon.ddev.site` (HTTP 200); HTTPS 000/timeout
because `mkcert` isn't installed on this host (DDEV printed this at `ddev start` time) — used the
plain HTTP URL as Playwright's `BASE_URL` override, since `playwright.config.ts`'s hardcoded
default points at a different worktree's HTTPS URL/port entirely.

**GREEN confirmation — new spec** (`BASE_URL="http://gm167-ribbon.ddev.site" npx playwright test
tests/e2e/showcase-ribbon-pointer-events.spec.ts --reporter=list`):

```
Running 3 tests using 1 worker

  ok 1 [chromium] › ... › the ribbon does not intercept a click meant for the page beneath it (3.4s)
  ok 2 [chromium] › ... › the ribbon's own "See what it compares ->" link remains clickable (302ms)
  ok 3 [chromium] › ... › the ribbon's dismiss button remains clickable and dismisses the ribbon (376ms)

  3 passed (5.6s)
```

**Spot-check — tests still fail if the fix is removed** (proves behavior, not implementation, is
pinned): reverted `docs/groups/modules/do_showcase/css/do_showcase.css` to the pre-fix `HEAD`
version via `git show HEAD:...`, re-ran `assemble-config.sh` + `cache:rebuild`, re-ran the spec:

```
  x  1 [chromium] › ... › the ribbon does not intercept a click meant for the page beneath it (6.7s)
  ok 2 [chromium] › ... › the ribbon's own "See what it compares ->" link remains clickable (361ms)
  ok 3 [chromium] › ... › the ribbon's dismiss button remains clickable and dismisses the ribbon (358ms)

  1 failed (Expected: false, Received: true, at line 104)
  2 passed
```

Exactly matches the T-RED reasoning: test 1 is the load-bearing assertion (fails without the fix,
passes with it); tests 2/3 are regression guards for the opt-in half and are true either way.
Restored the fixed CSS (`cp` from the pre-revert backup), reassembled, rebuilt cache, re-ran — back
to 3/3 passing, confirmed via `git diff --stat` showing the original 10-line insertion intact.

**Regression suite** (`BASE_URL="http://gm167-ribbon.ddev.site" npx playwright test
tests/e2e/showcase.spec.ts tests/e2e/showcase-help.spec.ts tests/e2e/persona-switcher.spec.ts
--reporter=list`): **30 passed (29.3s)**, 0 failed. Full test list confirmed clean, including the
existing per-test ribbon-dismiss `beforeEach` in `persona-switcher.spec.ts` /
`showcase-help.spec.ts` — dismissing an already-non-intercepting ribbon is a harmless no-op, as
predicted.

**Tier 2 — phpcs**: `ddev exec php vendor/bin/phpcs docs/groups/modules/do_showcase/css/do_showcase.css`
— exit 0, no output. No project-level `phpcs.xml`/`.phpcs.xml.dist` exists in this repo, so phpcs
ran with no configured standard/sniffs against a `.css` file and had nothing to flag either way —
expected and reported per the task brief, not a real signal either way.

**Verdict: GREEN, no blocking issues.** All 3 authored tests pass, the RED/GREEN delta is a real
behavior flip (not a tautology), and the 30-test showcase/showcase-help/persona-switcher regression
suite is unaffected. do_showcase.css diff remains exactly the pre-approved 10-line change (`git
diff --stat`: 1 file changed, 10 insertions(+)). No UI surface beyond what's already covered by
this E2E suite is implicated (pure CSS fix, no new markup/JS) — routing straight to S; U is N/A per
the story's own scope (no new interactive control, no wireframe).
## S (spec audit) — 2026-07-24

**Verdict: PASS.** Ship it.

Per-criterion (against #167):

1. **AC1 "ribbon must not intercept clicks on unrelated form controls"** — PASS. Test 1 (`showcase-ribbon-pointer-events.spec.ts:70`) asserts `document.elementFromPoint(midX, midY)` at the ribbon's interior returns a non-ribbon, non-descendant element; T-GREEN's revert spot-check confirmed it flips RED without the CSS change (Expected: false, Received: true) and GREEN with it — behavior pinned, not implementation.
2. **Option match — exact** — PASS. Diff is precisely the brief's option: one `pointer-events: none;` inserted into the existing `.do-showcase-ribbon` rule (line 19, immediately after `z-index: 1000;`), plus one new opt-in block `.do-showcase-ribbon a, .do-showcase-ribbon button, .do-showcase-ribbon-dismiss { pointer-events: auto; }` (lines 38-42). No other options (z-index lowering, geometry constraint) taken. Line-level match to plan.
3. **Blast-radius elimination** — PASS. Test 1 proves external controls are freed (dismissal no longer required for the covered control); tests 2+3 prove ribbon internals still work; T-GREEN 30/30 regression pass with the existing per-test `beforeEach` dismiss still in place confirms it degrades to a harmless no-op. Future SPA-route tests need no ribbon-dismiss workaround.
4. **No visual regression risk** — PASS. Diff touches `pointer-events` only. z-index (1000), background (#1a1a1a), color (#ffffff), position/top/left/right, flex layout, gap, padding, font-size, and the `:focus-visible` outline block are all byte-identical. Pure hit-testing semantics change; no paint change.
5. **PROJECT_CONTEXT compliance** — PASS. `git show 22df408 --name-only` lists exactly `docs/groups/modules/do_showcase/css/do_showcase.css` and `tests/e2e/showcase-ribbon-pointer-events.spec.ts`. Zero touches to `web/modules/custom/**`, `config/sync/**`, `.ddev/config.yaml`, or any assembled build artifact. Source-first rule respected.
6. **Keyboard/a11y** — PASS. `pointer-events: none` gates only mouse/touch hit-testing; it does not affect `Tab` traversal or focusability (elements retain their tabindex). Ribbon anchor + dismiss button remain keyboard-reachable both because pointer-events doesn't gate keyboard focus AND because they opt back in for pointer-events anyway. Controls underneath the ribbon were always tabbable — a fixed-position visual overlay doesn't remove elements from the Tab sequence. Existing `:focus-visible` outline rule (lines 53-57) still applies to both ribbon interactive descendants. WCAG 2.2 AA unaffected.
7. **Follow-up debt (noted, not filed)** — the `beforeEach` ribbon-dismiss workarounds in `tests/e2e/persona-switcher.spec.ts` and `tests/e2e/showcase-help.spec.ts` are now redundant (harmless no-ops as of this fix). Per POC memory (`feedback_poc_no_follow_ups.md`), NOT filing a GH issue and NOT proposing a cleanup PR here — surfaced once, left for organic removal if/when those specs are next edited. Bug fix stays scoped tight.

**Test-quality rubric spot-check** (`testing/test-quality.md` §7): 3 tests, proportionate to a single-behavior CSS bug fix (one load-bearing assertion + two regression guards for the opt-in half). Each names one behavior, asserts on rendered browser behavior (not CSS-file text), sits at the cheapest sufficient tier (E2E — the bug is a real-browser compositing effect not observable at unit/kernel). No tautological, snapshot, or mock-shaped smells. Revert spot-check proved test 1 fails for the right reason. Suite passes the "delete or merge" test — nothing to prune.

**Ship recommendation:** PR #186 is ready to merge on CI-green + mergeable, per standing uranus-wider-autonomy rule.
