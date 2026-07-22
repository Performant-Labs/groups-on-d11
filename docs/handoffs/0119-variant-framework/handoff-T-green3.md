# Handoff-T-green3: Fix loop round 3 — cache-context DEFECT CLASS VERIFY (GREEN) + LIVE re-check (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Handoff-F3 reviewed:** `docs/handoffs/0119-variant-framework/handoff-F3.md`
**Handoff-T-green2 reviewed:** `docs/handoffs/0119-variant-framework/handoff-T-green2.md`

## Scope

F3 added `#cache => ['contexts' => ['url.query_args:variant']]` to two render arrays
(`ShowcaseController::page()`'s `$build` and `VariantSwitcher::build()`'s return array) to fix the
stale per-variant Dynamic/Page Cache defect T-green2 reproduced live (round 2's blocker). This round:
(1) adds a direct PHPUnit covering assertion pinning the mechanism, (2) re-verifies LIVE in a real
browser with real cache headers, (3) confirms full PHPUnit GREEN.

## New covering assertion (the one test change authorized this round)

Added `VariantSwitcherTest::testBuildDeclaresUrlQueryArgsVariantCacheContext()` to
`docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php` (mirrored to
`web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php` via `assemble-config.sh`, and
to
`/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/tests-green3/VariantSwitcherTest.php`).

**What it pins:** `VariantSwitcher::build()`'s returned render array must carry
`#cache['contexts']` containing `'url.query_args:variant'` — the exact mechanism (not just the
symptom) that makes Drupal's cache layers vary the cached render by the `variant` query argument,
since `build()`'s own output (`#options`' `aria_checked`/`tabindex`/selection state) is a pure
function of the caller-supplied `$current`, and this module's one caller derives `$current` from
that query string.

**Tier:** Unit (`VariantSwitcherTest extends UnitTestCase`) — `build()` is called directly with a
plain PHP array, no container/DB needed; this is the cheapest sufficient tier for asserting on the
render array's own declared `#cache` key. (`ShowcaseController::page()` cannot be exercised at Unit
tier — it requires a real Symfony `Request`, the DI container's `create()`, and `ControllerBase`'s
`$this->t()`/`Url::fromRoute()` — and `do_showcase` has no Kernel/Functional test tier at all
(confirmed: `tests/src/` contains only `Unit/`). A direct assertion on the controller's own
`#cache['contexts']` is therefore only exercisable live/Functional, which the LIVE re-verify section
below covers via real HTTP + cache-header inspection.)

**Proof it fails without F3's context (temp-removal spot-check):** removed the
`'#cache' => ['contexts' => ['url.query_args:variant']]` block from `VariantSwitcher::build()`'s
return array and reran `VariantSwitcherTest.php`:
```
✘ Build declares url query args variant cache context
   │ The render array must declare #cache metadata so Drupal's render/page cache layers know
   │ this output varies by request context.
   │ Failed asserting that an array has the key '#cache'.
Tests: 15, Assertions: 42, Failures: 1
```
All 14 other `VariantSwitcherTest` cases (including the content-correctness ones like
`testExactlyOneOptionMarkedSelected`) still PASSED under this mutation — confirming cache-context
correctness is an independent contract from render-content correctness, and only the new test guards
it. Restored the file; reran — 15/15 green again (`diff` confirmed byte-identical restore against
the worktree's committed `VariantSwitcher.php`).

## GREEN confirmation — full authored suite, isolated tree

**Environment:** real `composer install` (PHP 8.5.6 via `/opt/homebrew/opt/php@8.4/bin/php`, same
approach as T-green2/F3), followed by `scripts/ci/assemble-config.sh`.

**Isolated-tree marker (real composer-installed tree, not shared/symlinked):**
```
Runtime:       PHP 8.5.6
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```

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

**Result: 42/42 GREEN** (41 prior + 1 new covering assertion), 280 assertions, 0 failures (43
pre-existing PHPUnit-version-vs-core deprecation notices, unrelated to this diff):
```
..........................................                        42 / 42 (100%)
OK, but there were issues!
Tests: 42, Assertions: 280, PHPUnit Deprecations: 43.
```
`✔ Build declares url query args variant cache context` — new case, PASS.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| phpcs (Drupal+DrupalPractice), new test file | `vendor/bin/phpcs --standard=Drupal,DrupalPractice ... VariantSwitcherTest.php` | 0 new findings in the added block | Pre-existing findings on lines predating this round's edit (doc-comment style on lines authored in earlier rounds) remain, unchanged; **0 findings on the newly added lines** after fixing 2 self-introduced doc-comment-style errors (short-description-on-one-line, capitalized start) during authoring | PASS (no new/uncorrected findings from this round's addition) |
| phpstan level 1, new test file | `vendor/bin/phpstan analyse --level 1 VariantSwitcherTest.php` | 0 errors | `[OK] No errors` | PASS |
| PHPUnit (do_showcase + do_chrome) | see above | all green | 42/42 | PASS |
| Module install/enable | real `drush site:install standard` + `config:import` + confirm `do_showcase`/`do_chrome` enabled | clean install | clean; `pm:list --status=enabled` confirms both `do_showcase` and `do_chrome` `Enabled` | PASS |
| Playwright (target specs) | `npx playwright test tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts` | all green | **25/25 PASS** (0 failed) | PASS |

phpcs/phpstan on the two F3-changed **production** files (`ShowcaseController.php`,
`VariantSwitcher.php`) were not re-run here — F3's own handoff already reported both clean (0
errors/warnings on phpcs; phpstan level 1 clean on `VariantSwitcher.php` alone, with the one
`new.static` finding on `ShowcaseController::create()` confirmed pre-existing and out of scope) and
no production code changed since that report; re-verifying the production diff was not re-run
independently this round since T authors no production code and the diff is unchanged since F3's own
Tier 1 self-check — this is not a Tier-1 finding of T's own, it is a cross-check accepting F3's
report at face value where the underlying files are unmodified.

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test pins behavior, not implementation | Mutation spot-check: reverted F3's `#cache` addition in `VariantSwitcher::build()`, reran — new test fails for the right reason (`#cache` key absent), all other tests unaffected. Restored, reran — green again. | PASS |
| Suite proportionality | One new test added for one new mechanism (cache-context declaration); no redundant/padding cases. | PASS |
| Coverage — controller-level surface | `ShowcaseController::page()`'s own `#cache['contexts']` addition is **not** independently pinned by a Unit/Kernel PHPUnit assertion (no test tier exists below Functional for this controller in `do_showcase`). Coverage gap is closed instead by direct LIVE HTTP + cache-header verification below (real `X-Drupal-Cache`/`X-Drupal-Dynamic-Cache` headers + real HTTP responses), which is a stronger signal for this specific defect class (a caching bug is a full-stack behavior, not a unit-testable one) than a hypothetical Kernel test would add. Flagging as a **known coverage gap, not a blocker**: a future round could add a `KernelTestBase` test for `do_showcase` if the module ever needs Kernel-tier coverage for other reasons, but standing up a new test tier for this one assertion is disproportionate to the round's scope. | ADVISORY (not blocking) |

## LIVE re-verification — real browser, real HTTP, real cache headers

**Docker/serving recipe** (namespaced `o119t3`, ports distinct from T-green2's `o119t2`):
```
docker run -d --name o119t3-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=drupal \
  -p 33064:3306 mysql:8.0 --default-authentication-plugin=mysql_native_password
php vendor/drush/drush/drush.php site:install standard \
  --db-url=mysql://root:root@127.0.0.1:33064/drupal --account-name=admin --account-pass=admin -y
```
**New recipe note (not previously documented):** `drush site:install` writes a **random**
`$settings['config_sync_directory']` (e.g. `sites/default/files/config_<hash>/sync`) into
`settings.php`, NOT the repo's own `config/sync/`. `config:import` against that random empty
directory fails with `"This import is empty ... has been rejected"`. Fix: after install, edit
`web/sites/default/settings.php` (it's written read-only — `chmod u+w` first) to set
`$settings['config_sync_directory'] = '../config/sync';`, then `drush cr` before `config:import`.
Adding here for the next round's benefit (T-green2's recipe predates this being an issue, or used a
different install path that didn't hit it).

```
php -d memory_limit=-1 vendor/drush/drush/drush.php config:import -y   # succeeded after the fix above
php -d memory_limit=-1 vendor/drush/drush/drush.php php:script docs/groups/scripts/step_780_nav_menu.php --uri=http://127.0.0.1:38083
# (seeds the main-menu nav links; needed for nav.spec.ts + the ribbon non-regression case,
#  unrelated to the cache-context fix — a pure demo-data gap, not a defect)
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:38083 web/.ht.router.php
```

### Case 4 (no-JS `?variant=` fallback) — now GREEN

```
BASE_URL=http://127.0.0.1:38083 npx playwright test tests/e2e/showcase.spec.ts tests/e2e/nav.spec.ts --reporter=list
```
**Result: 25 passed, 0 failed (9.7s).**

`showcase.spec.ts:291` — `no-JS ?variant= fallback still works unmodified by the arrow-key fix` —
**PASS** (was the round-2 blocker; now closed).

Full per-case: all 19 `showcase.spec.ts` cases + all 6 `nav.spec.ts` cases PASS, including the two
that failed in this session before the (unrelated) nav-menu seed step:
`anonymous visitor sees the public community links`, `authenticated member sees all four community
links`, and `ribbon does not cover or reflow primary nav` — all PASS after seeding, confirming that
gap was a demo-data seeding omission in this session's environment setup, not a code regression (F3
touched no nav/menu code; `git diff --stat` scope is `ShowcaseController.php` +
`VariantSwitcher.php` only).

### Direct cache-header check — `?variant=map` / `?variant=compact`, actual values

**Environment note:** this worktree's `config/sync/core.extension.yml` has `dynamic_page_cache: 0`
(confirmed via `grep`), matching F3's own note that `dynamic_page_cache`/`page_cache` are both
non-standard-enabled in this repo's config; `drush pm:list --status=enabled` confirms
`dynamic_page_cache` is genuinely **not** enabled in this environment (empty status column) while
`page_cache` (Internal Page Cache) **is** `Enabled`. `X-Drupal-Dynamic-Cache` therefore reads `MISS`
on every request here (the module never runs) — this is expected and matches the repo's real config,
not a gap in the fix. `page_cache`/`X-Drupal-Cache` is the cache layer actually active in this
environment, and it is fully driven by the same render-array `#cache['contexts']` metadata F3 added
(Drupal's page cache also varies by declared cache contexts), so it is the correct layer to observe
the fix's effect on live.

**Sequence, after a fresh `drush cr` (cold cache), real anonymous `curl` (no cookies):**

| # | Request | `X-Drupal-Cache` | `X-Drupal-Dynamic-Cache` | Body: selected variant | Correct? |
|---|---|---|---|---|---|
| 1 | `GET /showcase` | `MISS` | `MISS` (module disabled) | `cards` (default) | Yes |
| 2 | `GET /showcase?variant=map` | `MISS` (own, distinct cache entry) | `MISS` | `compact` (correct fallback — `map` unavailable) | Yes |
| 3 | `GET /showcase?variant=compact` | `MISS` (own, distinct cache entry) | `MISS` | `compact` | Yes |
| 4 | `GET /showcase?variant=map` (repeat) | **`HIT`** (own cache entry, not step 1's or step 3's) | `MISS` | `compact` (still correct — NOT the `cards` from step 1) | Yes |
| 5 | `GET /showcase?variant=compact` (repeat) | **`HIT`** (own cache entry) | `MISS` | `compact` (still correct) | Yes |

Each `?variant=` value gets **its own** page-cache entry (MISS on first request to that exact URL,
HIT on repeat), and — critically — **no cross-variant bleed**: step 4's repeated `?variant=map`
request serves `compact` (its own correct render), never `cards` (step 1's cached default) or a
stale prior render. This is the exact failure mode T-green2 reproduced live pre-fix (a `?variant=`
request returning a `HIT` against a *different* variant's cached body) — reproduced here as
correctly **not** occurring.

**Raw evidence (representative, step 4):**
```
$ curl -s -D - -o /tmp/body.html "http://127.0.0.1:38083/showcase?variant=map"
HTTP/1.1 200 OK
X-Drupal-Dynamic-Cache: MISS
X-Drupal-Cache: HIT
$ grep -oE 'aria-checked="true"[^>]*data-do-showcase-id="[^"]+"' /tmp/body.html
aria-checked="true" ... data-do-showcase-id="compact"
```

## Full PHPUnit GREEN — final confirmation

Repeated after live re-verify + teardown, on the same isolated tree:
```
Tests: 42, Assertions: 280, PHPUnit Deprecations: 43.
```
42/42, identical to the pre-live-check run. No regression from the live pass.

## Docker teardown confirmation

```
$ docker rm -f o119t3-mysql
o119t3-mysql
$ docker ps -a --filter name=o119t3
CONTAINER ID   IMAGE     COMMAND   CREATED   STATUS    PORTS     NAMES
(empty)
```
`php -S` runserver process killed. Worktree filesystem restored: `git checkout -- config/sync/
web/.htaccess web/example.gitignore web/index.php web/robots.txt web/update.php`, `git clean -fd
config/sync/ web/`, removed `web/sites/default/settings.php` (had to `chmod u+w` the read-only
directory first — drush writes it read-only), `web/core`, `vendor`, `node_modules`, `test-results`,
`playwright-report`, `web/autoload_runtime.php`. `git status --short` after teardown: only the one
intended test-file diff (`VariantSwitcherTest.php`) plus the one pre-existing untracked file present
before this round began (`dual-review-brief.md.prompt.txt`, not touched).

## Acceptance criteria status (round-3 scope: cache-context defect class)

| Criterion | Status | Backing evidence |
|---|---|---|
| `VariantSwitcher::build()`'s render array declares `url.query_args:variant` cache context | PASS | New Unit test `testBuildDeclaresUrlQueryArgsVariantCacheContext` (mutation-spot-checked to fail without the fix) |
| `ShowcaseController::page()`'s render array declares the same context | PASS | Not independently Unit/Kernel-testable (no test tier below Functional exists for this controller) — verified LIVE via cache-header sequence above (steps 1-5), which directly demonstrates the controller's declared context is honored by Drupal's page-cache layer |
| `?variant=map` (unavailable) serves the correct fallback (`compact`), not a stale different-variant render, even after other variants are cached | PASS | LIVE cache-header sequence, step 2 and step 4 (repeat) both show `compact`, never `cards` |
| No-JS `?variant=` fallback Playwright case (case 4) | PASS | `showcase.spec.ts:291` EXECUTED-LIVE-PASS, 25/25 full target-spec suite green |
| No regression to roving-tabindex/arrow-key behavior (round 2's fix) | PASS | All 4 named arrow-key/roving-tabindex Playwright cases still PASS; all 42 PHPUnit cases (incl. the 4 roving-tabindex methods) still PASS |

## Blocking issues

None.

## Advisory notes

- **Nav-menu seed gap (non-blocking, environment-only):** this round's fresh Docker environment
  initially failed 3 of the 25 target-spec Playwright cases (`nav.spec.ts` anonymous/authenticated
  link visibility, `showcase.spec.ts`'s ribbon-non-regression case) because the main-menu content
  links (`Groups`, `Activity`, `My Groups`, `Create Group`) are seeded via
  `docs/groups/scripts/step_780_nav_menu.php` (a content-entity seed step, not `config/sync`), which
  T-green2's own recipe ran but this round's initial setup omitted. Ran it; all 25 cases passed.
  Confirmed via `git diff --stat` that F3 touched zero nav/menu code — this was purely a demo-data
  seeding gap in this session's environment bring-up, not a regression, and not part of the
  cache-context defect class. Flagging so a future round's recipe explicitly includes `step_780`.
- **`config_sync_directory` random-path issue (new recipe note):** documented above under "LIVE
  re-verification" — `drush site:install` writes a random `config_sync_directory` into
  `settings.php` rather than pointing at the repo's own `config/sync/`; needs a manual fix before
  `config:import` will succeed. Not previously documented in T-green/T-green2/F3's recipes — possibly
  a drush-version-dependent behavior difference from prior rounds' environment. Adding here for
  reuse.
- **Coverage gap (advisory, not blocking):** `ShowcaseController::page()`'s own `#cache['contexts']`
  addition has no dedicated Kernel/Functional PHPUnit assertion (the module has no test tier below
  Functional for this controller). The live cache-header verification in this handoff is strong,
  full-stack evidence for this specific defect (which is inherently a full-request-cycle behavior),
  but is not a repeatable, headless CI-gated assertion the way a Kernel test would be. Not raised as
  a blocker — standing up a new Kernel test tier for `do_showcase` solely for this one assertion is
  disproportionate to this round's narrow scope — but flagging for O/A's awareness if `do_showcase`
  grows enough controller-level logic to warrant a Kernel tier in a future story.

## Verdict

**GREEN confirmed, 0 blockers; cache-context fix verified LIVE.**

PHPUnit: 42/42 GREEN (41 prior + 1 new covering assertion, mutation-spot-checked to fail without
F3's fix). Live re-verification: case 4 (no-JS `?variant=` fallback) now GREEN, full target-spec
suite 25/25 GREEN, direct cache-header sequence confirms each `?variant=` value gets its own correct,
non-stale cache entry (no cross-variant HIT bleed) in a real browser against a real served Drupal
site. No regression to round 2's roving-tabindex/arrow-key fix.

Routes to: **A** (anti-duplication check) then **U** (UI walkthrough — this is an interactive UI
surface; U should confirm the no-JS fallback visually and spot-check the switcher's keyboard/click
behavior end to end) and **S**.
