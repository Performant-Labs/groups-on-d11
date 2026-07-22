# Handoff-F3: Fix loop round 3 — cache-context DEFECT CLASS fix (#119)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Handoff-T-green2 reviewed:** `docs/handoffs/0119-variant-framework/handoff-T-green2.md`

## What was done

- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` — added
  `$build['#cache']['contexts'][] = 'url.query_args:variant';` at the top of `page()`, merged onto
  (not clobbering) the existing `$build` array, before the `#attached` lines. This is the render
  array Drupal's Dynamic Page Cache keys on for the `/showcase` route, and it is the surface T's
  live repro (`curl` + `X-Drupal-Dynamic-Cache` header) identified as serving a stale cached
  variant.
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — added a matching
  `'#cache' => ['contexts' => ['url.query_args:variant']]` entry to the render array
  `VariantSwitcher::build()` returns. This is the second half of the defect class: `build()`'s
  `$current` parameter (and therefore its `#options`/`aria-checked`/roving-`tabindex` output)
  varies by the caller-supplied `$current`, and `ShowcaseController::page()` derives that argument
  directly from the `variant` query string. Declaring the context on the switcher's own render
  array (not just relying on Drupal's child-to-parent `#cache` bubbling from the controller) keeps
  the contract correct for `build()` in isolation — any future caller (SC-4/5/6/ST-8) that also
  derives `$current` from the query string inherits the correct cache behavior automatically,
  rather than each new caller having to remember to add the context itself at its own call site.

## Design decisions

- **Two-surface fix, not one line.** T's blocker report suggested the one-line controller fix as
  "routes to F" language, but the round-3 brief explicitly asked for the DEFECT CLASS, not just
  the reproduced instance. Traced every place `?variant=`/query args feed a server-rendered array
  (grep across the whole module) and found exactly one additional surface whose *output* varies by
  the query: `VariantSwitcher::build()`'s render array (its `#options`/`aria-checked`/`tabindex`
  content is a function of `$current`, which in this module's one caller is query-derived). Added
  the context there too, as belt-and-suspenders — Drupal's render-cache bubbling from
  `$build['switcher']` up into the controller's own `#cache` should already cover this via
  bubbling, but declaring it at the source (the sub-array whose content actually varies) is the
  idiomatic Drupal pattern (each render array declares the cacheability its own content depends
  on) and protects future callers of `build()` who may not think to add the context themselves at
  their own call site.
- **`url.query_args:variant`, not the coarser `url.query_args`.** The only query parameter this
  module's output depends on is `variant`; using the narrower, parameter-scoped context avoids
  needlessly fragmenting the cache on unrelated query args a future caller might add to the same
  URL (e.g. UTM params), keeping the anon page cache healthy per the round's own instruction not
  to disable caching wholesale.
- **No cache tags added.** This is purely a missing-context bug — the render array's *content*
  already correctly derives from `$current` (confirmed by 41/41 PHPUnit green testing
  `VariantSwitcher::build()`'s logic directly); nothing about invalidation-on-entity-change is in
  play here, so adding tags would be scope creep beyond the diagnosed defect.

## Reuse / extend-vs-new

Not applicable in the reuse-map sense — this is a metadata fix on two already-existing render
arrays (`ShowcaseController::page()`'s `$build`, `VariantSwitcher::build()`'s return value), not a
new object. No parallel path created; both edits are additive `#cache` keys on the existing
returned arrays.

## Architecture notes for A

- No new services, routes, permissions, schema, or config. Purely `#cache` render-array metadata
  on two existing methods.
- `ShowcaseController::page()` — the sole route (`/showcase`) that reads `$this->request->query`.
  No other controller/route exists in `do_showcase` (confirmed via `grep` across the module +
  reading `do_showcase.routing.yml`).
- `VariantSwitcher::build()` — the sole caller-facing render-array producer for the switcher. No
  DI change; the class remains the plain, no-service-dependency shape A signed off on in Phase 3.
- Swept every other query-arg-adjacent surface in the module and found none needing a context (see
  "surfaces swept" below) — no drive-by changes to `DoShowcaseHooks::pageTop()` (ribbon) or
  `ShowcaseCatalog` (both confirmed query-independent).

## Surfaces swept (fixed / exempt-with-reason)

| Surface | Reads a query arg? | Action |
|---|---|---|
| `ShowcaseController::page()` | Yes — `$this->request->query->get('variant')` | **FIXED** — added `#cache.contexts += url.query_args:variant` |
| `VariantSwitcher::build()` | No direct query read, but its `$current` param is query-derived at this module's one call site, and its own render-array content (`#options`, `aria-checked`, `tabindex`) varies with `$current` | **FIXED** — added the same `#cache.contexts` entry to `build()`'s own return array (defense-in-depth / correct-at-source for future callers) |
| `DoShowcaseHooks::pageTop()` (ribbon) | No — ribbon markup/copy is identical for every visitor regardless of any query string; confirmed by reading the full method, no `\Drupal::request()`/query read anywhere in the class | **EXEMPT** — no context added; adding one here would be a spurious/incorrect cache fragmentation |
| `ShowcaseCatalog::entries()` / `::personas()` | No — pure code-constant data, no request access at all (confirmed: class has zero Drupal service dependencies, `StringTranslationTrait` only) | **EXEMPT** — not request-dependent in any way |
| `do_showcase.routing.yml` | N/A — no route-level cache config (`_no_cache`, etc.) present or needed; route-level access is `_permission: 'access content'`, unrelated to this defect | **EXEMPT** — route-level cacheability is controlled correctly via the render array's own `#cache`, which is the idiomatic mechanism; no route.yml change needed |

Grep confirmed no other `getQuery`/`query->get`/`variant` occurrence anywhere in `do_showcase` PHP
source outside the two fixed call sites (`ShowcaseController.php` line 71, and the JS-side
`do_showcase.switcher.js` client-side read, which is out of scope — that file runs in the browser,
not the render pipeline, and was explicitly preserved unmodified per the round's "preserve all
current GREEN behavior" instruction).

## Deviations from spec / wireframe

None. This is a caching-correctness fix; no visible markup, ARIA, copy, or interaction behavior
changed. Confirmed via PHPUnit (41/41 green, identical pass/fail set to T-green2's own run) that no
render-array *content* shape changed — only `#cache` metadata was added.

## Tier 1 self-check (incl. tests now GREEN)

Environment: real `composer install` in the worktree (PHP 8.5.6 via
`/opt/homebrew/opt/php@8.4/bin/php`, satisfies the repo's `^8.4` lock constraint — same approach
T-green2 documented), followed by `scripts/ci/assemble-config.sh` to place the `do_*` modules +
merged config, matching this round's own instructions and prior rounds' recipe.

**Isolated-tree marker (real composer-installed tree, not shared/symlinked):**
```
Runtime:       PHP 8.5.6
Configuration: /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/web/core/phpunit.xml.dist
```

**PHPUnit — 41/41 GREEN** (identical count/pass-set to T-green2's own round-2 verification; no
render-array shape changed, only `#cache` metadata added):
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
.........................................                         41 / 41 (100%)
OK, but there were issues!
Tests: 41, Assertions: 278, PHPUnit Deprecations: 42.
```
All 4 roving-tabindex methods and all 10 VariantSwitcher contract methods still pass unchanged.

**phpcs (Drupal + DrupalPractice), both changed files:**
```
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc \
  web/modules/custom/do_showcase/src/Controller/ShowcaseController.php \
  web/modules/custom/do_showcase/src/VariantSwitcher.php
```
Result: 0 errors, 0 warnings (no output) — PASS.

**phpstan level 1:**
- `VariantSwitcher.php` (the file with the more substantive edit) analysed alone: `[OK] No errors`
  — PASS, fully clean.
- `ShowcaseController.php` + `VariantSwitcher.php` analysed together: 1 finding —
  `Unsafe usage of new static()` on `ShowcaseController.php` line 42 (inside `create()`).
  **Pre-existing, not introduced by this diff** — confirmed via `git diff --stat HEAD -- .../ShowcaseController.php` showing the only change is 8 added lines inside `page()` (lines 60-67),
  nowhere near line 42's `create()` method. Matches the identical, already-flagged
  `new.static` finding on `NotificationSettingsController::create()` documented in this story's
  own Phase-5 decisions.md entry as a pre-existing repo pattern, not a defect of this fix. Not
  touched here (out of scope — a drive-by fix to `create()`'s DI pattern is not part of this
  round's defect class).

**Module install/enable:** `scripts/ci/assemble-config.sh` registered `do_showcase` (and all 10
other `do_*` custom modules) as enabled via the merged `core.extension.yml`; `php -l` clean on both
changed files; PHPUnit's own successful autoload of `Drupal\do_showcase\Controller\ShowcaseController`
and `Drupal\do_showcase\VariantSwitcher` under Drupal's real PSR-4/module-discovery bootstrap is
itself confirmation the module's class-loading is intact (same reasoning prior rounds used). A full
`drush site:install` was not re-run this round (no schema/service/routing/config change in this
diff to warrant it) — Tier 1 confidence here rests on syntax-clean + PHPUnit-green + confirmed
module-enabled state, consistent with the narrow scope of this fix.

**Worktree left clean after self-check** (tore down the composer install per T's documented
teardown recipe): `git status --short` shows only the two intended production-file diffs plus the
one pre-existing untracked file present before this round began.

## Tests that look wrong (for T)

None. T's authored Playwright case (`no-JS ?variant= fallback still works unmodified by the
arrow-key fix`) was correctly written per the wireframe/brief contract — the defect was in
production code, not the test.

## Known issues

None against this round's scope. One pre-existing, out-of-scope phpstan finding noted above
(`new.static` on `ShowcaseController::create()`) — not introduced by this diff, not part of the
cache-context defect class, left untouched.

## Note to T (live re-verification)

- Re-run the failing Playwright case (`no-JS ?variant= fallback still works unmodified by the
  arrow-key fix`) against a freshly-warmed `/showcase` page. Expected sequence:
  1. `GET /showcase` (no `?variant=`) → resolves `cards`, `X-Drupal-Dynamic-Cache: MISS` (first
     request, as before).
  2. `GET /showcase?variant=map` (an unavailable option) → must now return
     `X-Drupal-Dynamic-Cache: MISS` (a **different** cache entry, keyed by the `variant` query arg,
     not a stale hit against step 1's entry) and the switcher must show **`compact`** selected
     (the correct fallback per `VariantSwitcher::resolveSelection()`), not `cards`.
  3. A repeat of the exact same `GET /showcase?variant=map` request should now return
     `X-Drupal-Dynamic-Cache: HIT` — confirming caching still works correctly *per variant*, not
     that caching was disabled.
- `VariantSwitcher::build()`'s render array now carries `#cache.contexts = ['url.query_args:variant']`
  directly, so a direct PHPUnit assertion is now possible if T wants a static contract case
  covering the cache-context presence in addition to the live header check (existing PHPUnit
  contract tests test-suite for `build()` did not previously assert on `#cache` at all — this is a
  new key T may want a dedicated regression case for, since nothing currently pins its presence at
  the Unit tier; flagging as a coverage opportunity, not a defect).

## Files changed

- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php`
- `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/src/VariantSwitcher.php`

Mirrored to
`/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/impl-round3/`.
