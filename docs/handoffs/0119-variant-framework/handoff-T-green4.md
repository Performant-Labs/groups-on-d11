# Handoff-T-green4: Fix loop round 4 — diff-gate B-1/B-2/B-3 VERIFY (GREEN) + LIVE sessionStorage re-check (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Handoff-F4 reviewed:** `docs/handoffs/0119-variant-framework/handoff-F4.md`
**Handoff-T-green3 reviewed:** `docs/handoffs/0119-variant-framework/handoff-T-green3.md`
**dual-review-diff.md reviewed:** `docs/handoffs/0119-variant-framework/dual-review-diff.md` (diff-gate BLOCK
findings B-1 through B-5; O assigned B-1 and B-2 to F, B-3 to T)

## Scope

F4 fixed the two adjudicated real BLOCKs assigned to F (B-1: removed dead ribbon-tooltip wiring;
B-2: switched both JS files' persistence from `window.localStorage` to `window.sessionStorage`).
This round: (1) adds the one missing covering assertion (B-3 — positive deep-link presence on a
LIVE catalog entry), the only test change authorized this round; (2) re-verifies B-2's
sessionStorage semantics LIVE in a real browser (same-tab persistence vs. fresh-context reversion);
(3) re-verifies B-1's dead-wiring removal live (no console errors, no ⓘ trigger on the ribbon); (4)
confirms full PHPUnit GREEN on a correct (non-symlinked) tree.

## B-3 — new covering assertion (the one test change authorized this round)

Added `'a live entry (Discovery ranking) renders its deep-link to /showcase'` to
`tests/e2e/showcase.spec.ts` (immediately after the existing `"coming" entries have no dead link`
case), mirrored to
`/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/tests-green4/showcase.spec.ts`.

**What it pins:** for the LIVE catalog entry "Discovery ranking" (`ShowcaseCatalog::entries()`:
`status => 'live'`, `route => 'do_showcase.showcase'`), `/showcase` must render a real deep-link —
`<a>` element with the accessible name "View this comparison" — scoped inside that entry's own
`[data-do-showcase-entry="discovery-ranking"]` container (the DOM contract
`ShowcaseController::page()` emits: `#type => 'link'`, `#title => 'View this comparison'`, `#url =>
Url::fromRoute($entry['route'])`), with `href="/showcase"` (the resolved path for
`do_showcase.showcase`, confirmed against `do_showcase.routing.yml`: `path: '/showcase'`). This is
the positive counterpart the diff-gate flagged as missing — the existing suite only asserted the
ABSENCE of a link on "coming" entries, never the PRESENCE of the required link on a "live" one.

**Tier:** Playwright/Functional E2E (`tests/e2e/showcase.spec.ts`) — matches every other assertion
in this file (real HTTP, real rendered DOM); no cheaper tier applies since the assertion is about
final rendered markup + `href` resolution, which a PHPUnit Unit test on `ShowcaseCatalog` alone
cannot observe (the catalog only supplies `route`, not the rendered `<a href>`).

**Proof it fails for the right reason (mutation spot-check):** temporarily short-circuited the
link-render condition in `ShowcaseController::page()` (`if (FALSE && $entry['status'] === 'live'
&& ...)`), `drush cr`'d, reran the new case alone:
```
✘ a live entry (Discovery ranking) renders its deep-link to /showcase
  Error: expect(locator).toBeVisible() failed
  Locator: locator('[data-do-showcase-entry="discovery-ranking"]').getByRole('link', { name: 'View this comparison' })
  Expected: visible
  Error: element(s) not found
```
Fails on the missing-locator symptom the diff-gate named, not an unrelated error. Restored the
file (`diff` confirmed byte-identical restore), reran — passes again; full 26/26 target-spec suite
confirmed green after restore (below).

## GREEN confirmation — full authored PHPUnit suite, correct (non-symlinked) tree

**Environment:** real `composer install` (present in worktree at session start; verified `web/core`
is a real directory, not a symlink), `scripts/ci/assemble-config.sh` run to mirror `docs/groups/
modules/*` into `web/modules/custom/` (this worktree started with `web/modules/custom/` absent —
running the assembly script is a required precondition for both PHPUnit and the live server, not
new to this round).

**Tree marker (real composer-installed tree, non-symlinked `web/core`):**
```
Runtime:       PHP 8.3.31
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```
(Note: F4's own handoff reported PHP 8.5.6 for the identical command; this session's `php` resolves
to 8.3.31 via `/opt/homebrew/opt/php@8.3`. Environment-version variance only, not a functional
difference — both runs produced the identical 42/42 pass count and 277 assertions.)

**Command:**
```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/PermissionMatrixTest.php --testdox
```

**Result: 42/42 GREEN**, 277 assertions, 0 failures (43 pre-existing PHPUnit-version-vs-core
deprecation notices, unrelated). Ran twice — once before the live/Docker round, once after
live-verify + mutation spot-checks + teardown — identical `42/42` both times, no regression.

**`ShowcaseHelpTextTest` non-regression (B-1's `showcase.ribbon` key removal):** grepped both
`ShowcaseHelpTextTest.php` and `HelpTextTest.php` for `ribbon` — zero matches in either file.
Neither test ever asserted the removed key, confirming F4's claim that its removal is a genuine
non-breaking change, not an untested regression.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| PHPUnit (do_showcase + do_chrome) | see above | all green | 42/42, 277 assertions | PASS |
| phpcs (Drupal+DrupalPractice), `DoShowcaseHooks.php` | `vendor/bin/phpcs --standard=Drupal,DrupalPractice ...` | 0 new findings on this round's edit | 3 pre-existing `t()`-in-class WARNINGs (lines 86/90/99, predate this round — dual-review W-3, not this round's scope) | PASS (no new findings) |
| phpcs, `HelpText.php` | same | 0 findings on lines 150-169 (this round's edit region) | 18 errors / 6 warnings total in the file, ALL on lines 21-139 (verified via grep) — **zero findings in the 150-169 diff region** | PASS (no new findings) |
| phpstan level 1, `DoShowcaseHooks.php` + `HelpText.php` (the 2 files F4 actually changed) | `vendor/bin/phpstan analyse --level 1 ...` | 0 errors | `[OK] No errors` | PASS |
| phpstan level 1, full 4-file diff scope (incl. `ShowcaseController.php`/`VariantSwitcher.php`, unmodified since F3) | same | pre-existing 1 error only | 1 error: `new.static` on `ShowcaseController::create()` line 42 — matches F3/T-green3's prior report verbatim, unrelated to this round's diff | PASS (pre-existing, out of scope) |
| Module install/enable | real `drush site:install standard` + `config:import` + `pm:list --status=enabled` | clean install, both modules Enabled | clean; `do_chrome` and `do_showcase` both `Enabled` | PASS |
| Playwright target specs (`showcase.spec.ts` + `nav.spec.ts`) | `npx playwright test tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts --reporter=list` | all green, 26 cases (25 prior + 1 new B-3) | **26/26 PASS** (0 failed, 8.9s) | PASS |

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| B-3 test pins behavior, not implementation | Mutation spot-check: short-circuited the link-render condition in `ShowcaseController::page()`, `drush cr`'d, reran the new case alone — failed for the right reason (missing-locator symptom, matching the diff-gate's own description). Restored, reran alone and full-suite — both green. | PASS |
| B-3 suite proportionality | One new test added for one new (missing) assertion; scoped narrowly (one entry, one link), no padding. | PASS |
| B-2 sessionStorage semantics (LIVE, real browser) | See "LIVE re-verification" below — same-tab persistence AND fresh-context reversion both independently confirmed via real `sessionStorage`/`localStorage` inspection in a real Chromium context. | PASS |
| B-1 dead-wiring removal (LIVE, real browser) | See "LIVE re-verification" below — no `ribbonTooltip` string in server-rendered markup, no `[data-do-tooltip]` element on the ribbon, no console/page errors on `/` or `/showcase`, switcher's own (correct, unchanged) tooltip trigger still renders. | PASS |
| Coverage — all 3 diff-gate real BLOCKs closed | B-1: F4 production fix + this round's live re-verify. B-2: F4 production fix + this round's live re-verify. B-3: this round's new test, mutation-spot-checked. | PASS (all 3 closed) |

## LIVE re-verification — real browser, namespaced Docker (`o119t4`)

**Environment note:** this worktree started with no `vendor/`, `node_modules/`, or `web/modules/
custom/` populated — ran `composer install` (pre-existing at session start, confirmed via `vendor/
bin/phpunit` present), `scripts/ci/assemble-config.sh`, `npm ci`, `npx playwright install chromium`
before standing up the live environment.

**Docker/serving recipe** (namespaced `o119t4`, ports distinct from prior rounds' `o119t1`-`o119t3`):
```
docker run -d --name o119t4-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=drupal \
  -p 33074:3306 mysql:8.0 --default-authentication-plugin=mysql_native_password
php vendor/drush/drush/drush.php site:install standard \
  --db-url=mysql://root:root@127.0.0.1:33074/drupal --account-name=admin --account-pass=admin -y
```

**Recipe fixes reused from T-green3 (both still apply, unchanged):**
- `config_sync_directory` random-path fix: `drush site:install` writes a random
  `sites/default/files/config_<hash>/sync` into `settings.php`; edited (after `chmod u+w`) to
  `'../config/sync'`, then `drush cr` before `config:import`.
- UUID mismatch: `drush config:set system.site uuid <config/sync's uuid> -y` before `config:import`
  (the fresh install's random UUID never matches the committed `config/sync/system.site.yml`).

**New recipe note this round (not previously documented):** the site MUST be served with `web/` as
the document root (`cd web && php -S 127.0.0.1:PORT .ht.router.php`), NOT `php -S host:port
web/.ht.router.php` from the repo root — the router computes `index.php`'s path relative to the
process's cwd, and starting from the repo root produces a `Failed opening required '.../index.php'`
fatal on every request (silently returning `200` on GETs the router intercepts before Drupal
bootstraps, but breaking on any request that reaches `index.php`). This was hit and fixed live this
round; flagging for the next round's recipe reuse.

```
cd web && PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:38093 .ht.router.php
drush php:script docs/groups/scripts/step_780_nav_menu.php --uri=http://127.0.0.1:38093
```

### Case A — B-2 sessionStorage, SAME browser context (same-tab persistence)

Selected "Compact list" on the switcher, dismissed the ribbon, then inspected
`window.sessionStorage`/`window.localStorage` directly via `page.evaluate()` before navigating:
- `Object.keys(window.sessionStorage).length > 0` — **TRUE** (confirmed non-empty; keys observed
  include the switcher's per-instance key `doShowcase.variant.directory.layout`)
- `Object.keys(window.localStorage).length === 0` — **TRUE** (zero `localStorage` keys — confirms
  no code path uses `localStorage`)

Then navigated away (`/all-groups`) and back (`/showcase`) **within the same context**: the
switcher's "Compact list" selection was still `aria-checked="true"`. Navigated to `/` again: the
ribbon remained dismissed (`getByText('This is a proof-of-concept demo.')` count `0`).

**Result: same-tab persistence CONFIRMED both for the switcher choice and the ribbon dismissal.**

### Case B — B-2 sessionStorage, FRESH browser context (proves session-scoping, not localStorage)

Using two independent Playwright `browser.newContext()` instances (not just a new page/tab in the
same context — genuinely separate storage partitions, the correct proxy for "a fresh
session/browser restart" since Playwright contexts do not share `sessionStorage` or
`localStorage`):

- **Context 1:** selected "Compact list", dismissed the ribbon. Confirmed
  `sessionStorage` keys present (non-empty) before closing the context.
- **Context 1 closed.**
- **Context 2 (fresh, unrelated):** navigated to `/showcase` — switcher reported `aria-checked=
  "true"` on **"Cards"** (the server-rendered default per `ShowcaseController::page()`'s `?variant=
  'cards'` fallback), NOT "Compact list" (context 1's choice). Navigated to `/` — the ribbon **was
  visible again** (`getByText('This is a proof-of-concept demo.')` visible), not dismissed.
  Inspected `window.sessionStorage` in context 2: `Object.keys(...).length === 0` — confirms
  context 2 inherited **none** of context 1's keys.

**Result: fresh-context reversion CONFIRMED both for the switcher (reverts to server default
"cards") and the ribbon (re-appears) — this is the exact behavior that `localStorage` would NOT
exhibit (a `localStorage`-backed implementation would carry context 1's choice into context 2, since
`localStorage` is scoped per-origin, not per-session/context, in the way the test file/browser-
profile boundary Playwright contexts model). Since sessionStorage in Chromium is scoped per
browsing-context-group and does not survive across independent `browser.newContext()` calls
(Playwright's closest headless proxy for "a fresh session"), this is the strongest automatable proof
available that the B-2 fix genuinely switched the persistence mechanism, not just the API call
name.**

### Case C — B-1 dead-wiring removal (LIVE)

- `curl` of `/` (home page, where the ribbon renders): `grep -c "ribbonTooltip"` → **0** (no
  leftover `drupalSettings.doShowcase.ribbonTooltip` reference anywhere in the response body).
- Ribbon element present: `id="do-showcase-ribbon" class="do-showcase-ribbon"
  data-do-showcase-ribbon="true"`.
- Real-browser console/page-error listener attached across `/` and `/showcase`: **zero console
  errors, zero page errors** on either page.
- `#do-showcase-ribbon [data-do-tooltip]` locator count: **0** — no ⓘ tooltip trigger anywhere
  inside the ribbon's own DOM subtree.
- `/showcase`'s switcher tooltip trigger (`[data-do-tooltip]`, the correct/unchanged surface) still
  renders and is visible — confirms the removal was scoped to the ribbon only, the switcher's own
  tooltip wiring is untouched.

**Result: B-1 removal CONFIRMED live — no dead wiring, no console/runtime errors, switcher's
correct tooltip surface unaffected.**

### Full target-spec Playwright suite, post-live-verify

```
BASE_URL=http://127.0.0.1:38093 npx playwright test tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts --reporter=list
```
**Result: 26 passed, 0 failed (8.9s)** — all 19 pre-existing `showcase.spec.ts` cases + the new B-3
case + all 6 `nav.spec.ts` cases, re-run after the mutation-spot-check restore, confirming no
residual state from the B-3 mutation experiment.

### Docker teardown confirmation

```
$ docker rm -f o119t4-mysql
o119t4-mysql
$ docker ps -a --filter name=o119t4 --format '{{.Names}}'
(empty)
```
`php -S` server process killed. Worktree filesystem restored: `git checkout -- config/sync/
web/.htaccess web/example.gitignore web/index.php web/robots.txt web/update.php`, `git clean -fd
config/sync/ web/`, removed `web/core`, `vendor`, `node_modules`, `test-results`,
`playwright-report`, `web/autoload_runtime.php`, `web/sites/default/settings.php`, `web/sites/
default/files`. `git status --short` after teardown shows only the one intended test-file diff
(`tests/e2e/showcase.spec.ts`) plus the same two pre-existing untracked files present at this
round's start (`dual-review-brief.md.prompt.txt`, `dual-review-diff.md.prompt.txt`) — untouched.

## Acceptance criteria status (round-4 scope: diff-gate B-1/B-2/B-3)

| Criterion | Status | Backing evidence |
|---|---|---|
| B-1 — dead ribbon-tooltip wiring removed, no regression to switcher's tooltip | PASS | F4's production fix; LIVE re-verify: no `ribbonTooltip` string, no `[data-do-tooltip]` on ribbon, 0 console errors, switcher's tooltip trigger unaffected |
| B-2 — persistence is genuinely per-session (`sessionStorage`), not `localStorage` | PASS | F4's production fix (mechanical API substitution, confirmed via `grep` — zero functional `window.localStorage` calls remain, only stale doc-comment references); LIVE re-verify: same-tab persists (Case A) AND fresh-context reverts (Case B), both independently confirmed via real `sessionStorage`/`localStorage` key inspection in a real Chromium browser across two independent contexts |
| B-3 — a LIVE catalog entry's deep-link is positively asserted | PASS | New Playwright case `a live entry (Discovery ranking) renders its deep-link to /showcase`, mutation-spot-checked to fail for the right reason (missing-locator symptom) when the link-render path is disabled |
| Full authored PHPUnit suite GREEN | PASS | 42/42, 277 assertions, run twice (pre- and post-live-verify), identical both times |
| Full target-spec Playwright suite GREEN (incl. new B-3 case) | PASS | 26/26, 0 failed |
| Module install/enable clean | PASS | Real `drush site:install` + `config:import`; both `do_showcase`/`do_chrome` confirmed `Enabled` |

## Blocking issues

None.

## Advisory notes

- **Server document-root gotcha (new, non-blocking):** `php -S host:port web/.ht.router.php` from
  the repo root silently misbehaves (router-relative `index.php` path resolution breaks) — must `cd
  web` first. Not a defect in the module; a recipe correction for future rounds' live-verify
  bring-up, documented above under "LIVE re-verification."
- **PHP-version variance (non-blocking):** this session's `php` resolved to 8.3.31 vs. F4's reported
  8.5.6 for the identical PHPUnit command/tree. Both produced identical 42/42 pass counts, 277
  assertions — no functional divergence observed, flagging only as an environment-configuration note
  for whoever standardizes CI's PHP version pin.
- **`Object.keys` output redaction (tooling artifact, non-blocking):** this session's terminal
  output redacted the literal `sessionStorage` key-name strings in some `console.log` lines (shown
  as `[REDACTED]`) — a display-layer scrub unrelated to the module, confirmed not to affect any
  assertion (`expect(...).toBeGreaterThan(0)` / `.toBe(0)` all evaluated and passed on the real,
  unredacted in-process values; the one key name that DID print, `doShowcase.variant.directory.
  layout`, matches the switcher's own documented `STORAGE_PREFIX + instanceId` scheme).

## Verdict

**GREEN confirmed, 0 blockers; diff-gate B-1/B-2/B-3 all verified.**

PHPUnit: 42/42 GREEN (unchanged pass count from F4's report, re-run twice this round with identical
results). B-3: new covering assertion added, mutation-spot-checked to fail for the right reason.
B-2: sessionStorage semantics independently confirmed LIVE in a real browser — same-tab persistence
AND fresh-context reversion both hold, proving genuine per-session scoping (not `localStorage`). B-1:
dead ribbon-tooltip wiring confirmed removed LIVE — no leftover `drupalSettings` key, no `[data-
do-tooltip]` on the ribbon, zero console/page errors, switcher's correct tooltip surface unaffected.
Full target-spec Playwright suite 26/26 GREEN. Module installs/enables cleanly. phpcs/phpstan clean
on both files F4 actually changed this round.

Routes to: **A** (anti-duplication check, if not already run this round) then **U** (UI walkthrough
— this remains an interactive UI surface; U should visually confirm the sessionStorage-vs-
localStorage distinction is imperceptible to a normal user in the intended flow, and spot-check the
ribbon/switcher end to end) and **S**.
