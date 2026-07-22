# Handoff-T-green2: Fix loop round 2 — roving-tabindex/arrow-key VERIFY (GREEN) + live Playwright (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Handoff-F2 reviewed:** `docs/handoffs/0119-variant-framework/handoff-F2.md`
**Handoff-T-red2 reviewed:** `docs/handoffs/0119-variant-framework/handoff-T-red2.md`
**Handoff-T-green (round 1) reviewed:** `docs/handoffs/0119-variant-framework/handoff-T-green.md`

## Environment notes — real composer install this round, not a copy/symlink workaround

This round used a genuine `composer install` in the worktree (PHP 8.4/8.5 via
`/opt/homebrew/opt/php@8.4/bin/php`, which actually resolves to Homebrew's installed 8.5.6 — no
real `php@8.4` keg is installed on this machine, but 8.5 satisfies the repo's `doctrine/instantiator
2.1.0` → `php ^8.4` lock constraint, so `composer install` succeeded against the committed
`composer.lock` with zero `--ignore-platform-reqs`). This produced a REAL `web/core` and `vendor`
(not a `cp -R`/symlink of the shared checkout) — the isolated-tree marker is therefore the
PHPUnit `Configuration:` line pointing at this worktree's own freshly-installed
`web/core/phpunit.xml.dist`, backed by a real composer-resolved tree, not a copy.

`scripts/ci/assemble-config.sh` placed the 11 `do_*` modules into `web/modules/custom/` and merged
`docs/groups/config/*.yml` into `config/sync/` exactly as CI's `assemble-config.sh` step does.

**Playwright browser install stalled/failed via the normal `npx playwright install chromium` path**
(both the CLI and the underlying Azure Front Door download endpoint returned an immediate
`HTTP 400 GatewayExceptionResponse` / `X-DSGatewayServiceAPI-ErrorCode: 20012` for the exact
revision `1228` playwright-core 1.61.1 pins — confirmed via direct `curl` against both
`cdn.playwright.dev` and the underlying `playwright.download.prss.microsoft.com` host, both
returning the identical 24-byte error body instantly, not a slow/stalled transfer; older revisions
e.g. `1187` returned the expected `307` redirect and downloaded cleanly). Resolved by finding a
**pre-existing, complete `chromium-1228` install already cached on this machine**
(`~/Library/Caches/ms-playwright/chromium-1228/`, dated 2026-06-28, `INSTALLATION_COMPLETE` +
`DEPENDENCIES_VALIDATED` markers present, binary launches and reports the correct
`Chrome for Testing 149.0.7827.55` matching `browsers.json`'s pin) from an earlier session on this
shared machine — used directly, no version substitution needed in the end. (A manual revision-1187
substitution was attempted and fully cleaned up before discovering the working 1228 cache; no
`browsers.json` patch shipped in the final run.)

**MySQL, install, config:import, seed, serve — mirrored T-green's (round 1) documented CI-parity
recipe exactly**, namespaced for this round: Docker container `o119t2-mysql` on host port `33063`
→ `drush site:install standard` → `config:set system.site uuid` + `config:import` (needed
`-d memory_limit=-1`; the default 128M CLI limit fataled mid-import without it — not previously
documented, adding here) → confirmed `do_showcase`/`do_chrome` already enabled by the imported
config → seeded `step_700`/`step_720`/`step_780` demo data as uid 1 → served via
`drush runserver --no-browser 127.0.0.1:38082` (single default worker; `PHP_CLI_SERVER_WORKERS=8`
set per the CI e2e job's own pattern) → ran `npx playwright test` with `BASE_URL` pointed at the
served port.

## PHPUnit — 41/41 GREEN, isolated tree

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/PermissionMatrixTest.php --testdox
```

**Isolated-tree marker (real composer-installed tree, not shared/symlinked):**
```
Runtime:       PHP 8.5.6
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```

**Result: 41/41 GREEN**, 278 assertions, 0 failures (42 pre-existing PHPUnit-version-vs-core
deprecation notices, unrelated to this diff):
```
.........................................                         41 / 41 (100%)
OK, but there were issues!
Tests: 41, Assertions: 278, PHPUnit Deprecations: 42.
```

All 4 new roving-tabindex methods pass:
```
✔ Exactly one available option has roving tabindex zero
✔ Roving tabindex zero follows selection to a different option
✔ Unavailable option is never the roving tabindex target
✔ Roving tabindex invariant holds for arbitrary option count
```

**Spot-check: tests fail if behavior is removed (pin behavior, not implementation).** Reverted
`VariantSwitcher.php` line 96 from `($available && $is_selected) ? '0' : '-1'` back to the
pre-fix `$available ? '0' : '-1'` and reran `VariantSwitcherTest.php`:
```
✘ Exactly one available option has roving tabindex zero — Failed asserting that actual size 2 matches expected size 1.
✘ Roving tabindex zero follows selection to a different option — Failed asserting that actual size 2 matches expected size 1.
✔ Unavailable option is never the roving tabindex target
✘ Roving tabindex invariant holds for arbitrary option count — Failed asserting that actual size 4 matches expected size 1.
Tests: 14, Assertions: 35, Failures: 3
```
Restored the file; reran — 14/14 (41/41 full suite) green again. Confirms the 3 new roving-tabindex
methods pin real behavior, not a tautology (the 4th, "unavailable option is never the roving target,"
correctly holds under both old and new code by design — documented as such in T-red2).

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| phpcs (Drupal+DrupalPractice) | `vendor/bin/phpcs --standard=Drupal,DrupalPractice ... VariantSwitcher.php` | 0 errors | 0 errors, 0 warnings (no output) | PASS |
| phpstan level 1 | `vendor/bin/phpstan analyse --level 1 VariantSwitcher.php` | 0 errors | `[OK] No errors` | PASS (cleaner than F2's report — F2 saw a `class.notFound` scan-scope artifact from a single-file CLI invocation with no autoload; the real composer-installed tree here resolves the `do_chrome\HelpText` reference correctly) |
| PHPUnit (do_showcase + do_chrome) | see above | all green | 41/41 | PASS |
| JS syntax | `node --check do_showcase.switcher.js` | no syntax errors | clean | PASS |
| Module install/enable | `drush site:install` + `config:import` + confirm `do_showcase`/`do_chrome` enabled | clean install | clean; both modules enabled via imported config, no errors | PASS |
| Playwright (target spec) | `npx playwright test tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts` | all green | 24/25 PASS, 1 FAIL (real defect — see below) | **FAIL — 1 blocker** |

## Playwright — LIVE EXECUTION, 24/25 passed; the 4 arrow-key/roving-tabindex cases ALL EXECUTED-LIVE-PASS

Ran against a real served site (`BASE_URL=http://127.0.0.1:38082`), real Chromium
(`Chrome for Testing 149.0.7827.55`), 1 worker, `--reporter=list`.

**Command:**
```
BASE_URL=http://127.0.0.1:38082 npx playwright test tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts --reporter=list
```

**Result: 24 passed, 1 failed (26.6s).**

### The 4 named arrow-key/roving-tabindex cases — explicit per-case status

| # | Case | Status |
|---|---|---|
| 1 | `roving tabindex: only the selected option is Tab-reachable, not every available option` | **EXECUTED-LIVE-PASS** — confirmed `Cards` (selected default) `tabindex="0"`, `Compact list` (available, not selected) `tabindex="-1"` in a real DOM, real browser. |
| 2 | `ArrowRight moves selection to the next available option and rolls the roving tabindex` | **EXECUTED-LIVE-PASS** — real `page.keyboard.press('ArrowRight')` from focused `Cards`; focus, `aria-checked`, and roving `tabindex` all moved together to `Compact list`; `Cards` correctly demoted to `aria-checked="false"`/`tabindex="-1"`; `Map` (unavailable) never touched. |
| 3 | `ArrowLeft moves selection to the previous available option, skipping the unavailable one` | **EXECUTED-LIVE-PASS** — real `ArrowLeft` press from focused `Cards`; landed on `Compact list` (the only other available option), `Map` never became the target, matching native-radiogroup skip-disabled semantics. |
| 4 | `no-JS ?variant= fallback still works unmodified by the arrow-key fix` | **EXECUTED-LIVE-FAIL — but this is a genuine, newly-surfaced PRODUCTION DEFECT, not a JS/arrow-key regression and not a test-authoring error.** See "Blocking issue" below. |

Cases 1-3 are unambiguous GREEN, executed live in a real browser against a real served Drupal site
— this closes the exact gap T-green (round 1) flagged as its blocker (roving-tabindex + arrow-key
behavior was previously traced-not-executed by F2; now it has run in a real browser and passed for
the right reason).

### Full per-case results (`showcase.spec.ts`, 19 cases; `nav.spec.ts`, 6 cases)

| # | Case | Result |
|---|---|---|
| 1 | switcher renders as a labeled radiogroup with all stub options | PASS |
| 2 | clicking an option switches the current selection | PASS |
| 3 | selection is conveyed by more than color (non-color cue present) | PASS |
| 4 | the choice persists client-side across navigation | PASS |
| 5 | no-JS ?variant= query param selects the right option | PASS |
| 6 | an unavailable option is present, marked, and not a dead click | PASS |
| 7 | **roving tabindex: only the selected option is Tab-reachable** | **PASS (live)** |
| 8 | **ArrowRight moves selection + rolls roving tabindex** | **PASS (live)** |
| 9 | **ArrowLeft moves selection, skipping the unavailable one** | **PASS (live)** |
| 10 | **no-JS ?variant= fallback still works unmodified by the arrow-key fix** | **FAIL — real defect (see below)** |
| 11 | ribbon shows for anonymous visitors, links to /showcase | PASS |
| 12 | ribbon shows identically for an authenticated user | PASS |
| 13 | ribbon does not cover or reflow primary nav (nav.spec.ts non-regression) | PASS |
| 14 | dismiss button removes the ribbon | PASS |
| 15 | dismissal persists client-side across navigation | PASS |
| 16 | lists all six comparison entries with truthful [live]/[coming] badges | PASS |
| 17 | the private-group-reveal entry references #134 | PASS |
| 18 | "coming" entries have no dead link to an unbuilt page | PASS |
| 19 | lists the persona switcher naming all four public personas | PASS |

`nav.spec.ts` (non-regression, all 6): PASS — primary-nav block, public/member links, link
resolution, subtheme H1, account-menu block all unaffected.

**Docker teardown confirmed:** `docker rm -f o119t2-mysql` → `docker ps -a --filter name=o119t2`
returns empty. `runserver`/`d8-rs-router.php` processes killed. Worktree filesystem restored:
`git checkout -- config/sync/ web/.htaccess web/example.gitignore web/index.php web/robots.txt
web/update.php`, `git clean -fd config/sync/ web/`, removed `web/sites/default/settings.php`,
`web/core`, `vendor`, `node_modules`, `test-results`, `playwright-report`,
`web/autoload_runtime.php`. `git status --short` after teardown: only the one pre-existing
untracked file from before this round (`dual-review-brief.md.prompt.txt`, not touched by me).

## BLOCKER — `?variant=<id>` server-side fallback is masked by a stale Dynamic Page Cache HIT (real production defect, newly surfaced by live E2E)

**What the failing case found:** `no-JS ?variant= fallback still works unmodified by the arrow-key
fix` requests `/showcase?variant=map` (an unavailable option) expecting the server to fall back to
`compact` (the first available option), per `VariantSwitcher::resolveSelection()`. Instead the
response's switcher shows **`cards`** selected — the same variant an *earlier test in the same run*
had already caused `/showcase` to render.

**Root cause, confirmed via direct `curl` + cache-header inspection (not a guess):**
- `ShowcaseController::page()` builds its render array with `$variant =
  $this->request->query->get('variant') ?? 'cards'` but attaches **no `#cache` context** keyed on
  the query string (no `url.query_args`/`url.query_args:variant` in the returned render array).
- Reproduced the exact sequence live: after a full `cache:rebuild`, `GET /showcase` (default, no
  `?variant=`) resolves `cards` and returns `X-Drupal-Dynamic-Cache: MISS` (correct, first request).
  The **very next** `GET /showcase?variant=map` returns `X-Drupal-Dynamic-Cache: HIT` and serves
  the **same `cards`-selected markup** — Drupal's Dynamic Page Cache is keying the cached render
  array only by URL *path*, not by the `variant` query argument that the controller reads to select
  content, so every subsequent request to `/showcase` with a *different* `?variant=` value gets the
  first-cached variant back until the dynamic-cache entry expires/is invalidated.
- Confirmed this is **not** a Page Cache (`page_cache` module) issue — `page_cache`/
  `dynamic_page_cache` are both `0`/disabled in this repo's `core.extension.yml` per
  `grep`, so on the real deployed image this could route through a different Drupal internal cache
  layer (render cache / cache_render bin) with the same missing-context root cause; the mechanism
  (missing `#cache: ['contexts' => [...]]`) is the same regardless of which cache backend serves it.
- **Confirmed this is pre-existing, not introduced by F2's diff:** `git diff --stat 9918fd8 a19686d
  -- '*Controller*'` shows zero changes to `ShowcaseController.php` in the round-2 commit. This bug
  predates the roving-tabindex/arrow-key fix entirely and was never caught by round 1's T-green
  (its "no-JS ?variant= query param selects the right option" case only ever requested
  `?variant=cards`, which coincidentally matches the page's own already-cached default — so the
  cache-staleness never surfaced).

**Why this could only be caught by live E2E, not PHPUnit:** `VariantSwitcherTest.php` calls
`VariantSwitcher::build()` directly with a PHP array — it never goes through
`ShowcaseController::page()`, Drupal's routing/render pipeline, or any cache layer, so the
render-array logic (which IS correct — `resolveSelection('map', ...)` returns `'compact'` when
tested directly, confirmed by the PHPUnit suite being 100% green) can never expose a cache-context
bug. Only a real HTTP round-trip against a real, cache-warmed Drupal site — exactly what this round
of live Playwright execution did — surfaces it.

**Severity / scope:** narrow but real — a user landing on `/showcase?variant=<unavailable-id>`
after any other visitor has already loaded `/showcase` with a different variant selected will see
the WRONG variant marked as selected (`aria-checked`) until the dynamic-cache entry for that path
expires. This is a genuine functional defect in the no-JS fallback path the wireframe and brief both
require to "just work" without JS.

**Fix (routes to F, not authored here — T writes no production code):** add
`#cache => ['contexts' => ['url.query_args:variant']]` (or the coarser `'url.query_args'`) to the
render array `ShowcaseController::page()` returns, so Drupal correctly varies its cache by the
`variant` query argument. This is a one-line, narrowly-scoped fix in a file F2 did not touch.

**Not a test-authoring error:** the failing test's assertion, locator, and expected value are all
correct per the wireframe/brief contract (`?variant=map` → server-resolved fallback to `compact`).
No test edit is warranted — this is production code needing a fix, then a rerun to confirm GREEN.

## Tier 2 delta — re-audit of what F2 changed only

- **Roving-tabindex contract:** confirmed exactly one `tabindex="0"` per switcher instance, always
  the currently-selected AVAILABLE option, via both PHPUnit (4 methods) and live DOM inspection
  (`curl` + Playwright). PASS.
- **Arrow handler correctness + skip-unavailable + wrap:** confirmed live — `ArrowRight`/`ArrowLeft`
  from the 2-available-option stub both correctly land on the one other available option, `Map`
  (unavailable) is never a target in either direction. Wrap-vs-clamp remains genuinely
  undistinguishable with only 2 available options (as T-red2's own semantics note anticipated) —
  not a defect in this round's scope, flagged only as a future-story consideration if a 3+-available
  instance is ever built.
- **No regression to click-selection / persistence / aria-checked / glyph / tooltip / ribbon /
  no-JS fallback:** all PASS except the one no-JS-fallback case above, which is a **pre-existing**
  cache-context defect newly surfaced by this round's more thorough live test, not a regression
  caused by F2's arrow-key/roving-tabindex diff (confirmed via `git diff --stat` scoping).
- **phpcs/phpstan clean on the two changed files:** confirmed independently (see Tier 1 table above)
  — 0 errors on both `VariantSwitcher.php` and (via `node --check`) `do_showcase.switcher.js`.
- **Tests pin behavior, not implementation:** confirmed via the mutation spot-check (PHPUnit) and by
  the fact that the Playwright arrow-key cases assert on real DOM state (`aria-checked`, `tabindex`,
  `toBeFocused()`) reachable only by the actual keydown handler running, not by any implementation
  detail.
- **Suite proportionality:** no redundant or padding tests found in the 8 cases added this round (4
  PHPUnit + 4 Playwright); each names a distinct behavior per T-red2's own accounting.

## Acceptance criteria status (roving-tabindex / arrow-key gap, this round's scope)

| Criterion | Status | Backing test(s) |
|---|---|---|
| Exactly one option in tab order at a time (roving tabindex) | PASS | VariantSwitcherTest ×4 (PHPUnit); showcase.spec.ts "roving tabindex..." (Playwright, EXECUTED-LIVE-PASS) |
| Arrow-Left/Right moves selection, matching native radiogroup behavior | PASS | showcase.spec.ts "ArrowRight..." + "ArrowLeft..." (Playwright, EXECUTED-LIVE-PASS both) |
| Arrow nav skips unavailable options | PASS | Same two Playwright cases — `Map` confirmed never a target in either direction |
| No-JS `?variant=` fallback unaffected by the arrow-key change | **FAIL — pre-existing cache-context defect, newly surfaced** | showcase.spec.ts "no-JS ?variant= fallback..." (Playwright, EXECUTED-LIVE-FAIL for a real reason) |

## Blocking issues

1. **`ShowcaseController::page()` missing `#cache` context on the `variant` query argument** —
   causes Drupal's Dynamic Page Cache to serve a stale variant selection to any `/showcase?variant=`
   request after a different variant has already been cached for that path. Routes to **F** (add
   `#cache => ['contexts' => ['url.query_args:variant']]` to the render array). Pre-existing (not
   introduced by F2's round-2 diff), narrowly scoped, one-line fix in a file F2 did not touch this
   round. **Must be fixed and reverified GREEN before this switcher UI surface is considered fully
   closed** — U should be aware the no-JS fallback path may show a stale variant on a cache-warmed
   `/showcase` page until this is fixed.

## Advisory notes

- The pre-existing `chromium-1228` Playwright browser cache found on this shared machine (dated
  2026-06-28) was load-bearing for this round's live execution — the normal `npx playwright install`
  path is currently broken for revision 1228 specifically via both playwright.dev's CDN and its
  underlying Microsoft distribution host (`X-DSGatewayServiceAPI-ErrorCode: 20012`), while older
  revisions download fine. This is an upstream Playwright/Microsoft infrastructure issue as of this
  session's date, not specific to this repo or worktree — flagging for O's awareness since a fresh
  machine/container without that pre-existing cache would need a workaround (e.g. pin an older
  chromium revision, or wait for Microsoft's gateway issue to clear) to run Playwright live at all.
- `config:import` needs `-d memory_limit=-1` on the CLI in this environment (the default 128M limit
  fatals partway through `config:import` against the full assembled config set) — not previously
  documented in T-green (round 1)'s recipe; adding here for the next round's benefit.
- phpstan level 1 against the real composer-installed tree resolves the `do_chrome\HelpText` class
  reference cleanly (0 findings on `VariantSwitcher.php`), cleaner than F2's single-file CLI
  invocation which reported a `class.notFound` scan-scope artifact on the same pre-existing line —
  confirms F2's own characterization that the finding was a scan-scope artifact, not a real issue.

## Verdict

**GREEN confirmed for PHPUnit (41/41). Playwright: 24/25 live-executed, 1 blocker** — a genuine,
pre-existing production defect (missing cache context on `ShowcaseController::page()`) newly
surfaced by this round's more thorough live E2E execution, not a regression from F2's
roving-tabindex/arrow-key diff and not a test-authoring error. **The 4 named arrow-key/roving-tabindex
cases are ALL EXECUTED-LIVE-PASS** — the specific gap this fix-loop round was created to close
(T-green round 1's blocker: "roving-tabindex/arrow-key pattern not implemented, not tested") is now
closed and verified in a real browser against a real served site.

**Verdict line: GREEN confirmed, 1 blocker (pre-existing cache-context defect on
`ShowcaseController::page()`, unrelated to the roving-tabindex/arrow-key diff); arrow-key
live-verification status: executed here — all 4 named cases EXECUTED-LIVE-PASS.**

Routes to: **F** (one-line cache-context fix on `ShowcaseController::page()`), then **T** re-verify
GREEN on that one case, before **U** (UI walkthrough — the roving-tabindex/arrow-key surface itself
is now closed and does not need U's own re-verification of keyboard behavior, but U should confirm
the no-JS fallback visually once F's fix lands) and **S**.
