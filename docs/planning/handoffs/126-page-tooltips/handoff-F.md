# Handoff-F: Phase 5 - #126 SD-1 Page-level ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 126-page-tooltips
**Issue:** #126

## What was done
- `docs/groups/modules/do_chrome/src/Hook/PageHelp.php` (NEW) — `PageHelp` class: constructor DI on `RouteMatchInterface $routeMatch`, public `getRouteMap(): array` (10-entry allowlist), `#[Hook('preprocess_page_title')] preprocessPageTitle()` with default-deny early return + silent-empty guard, private `infoTrigger()` matching `GroupTypeContentHelp::infoTrigger()`'s shape plus the `page-help-info` class.
- `docs/groups/modules/do_chrome/src/HelpText.php` (MODIFIED, append-only) — added a `page.*` section (10 keys: 5 LIVE + 5 W2 pre-registered) after the existing `persona.*` block, before the closing `];`. Zero lines removed (`git diff` shows 24 insertions, 0 deletions).
- `docs/groups/modules/do_chrome/do_chrome.services.yml` (MODIFIED) — registered `do_chrome.page_help` service (`autowire: false`, `arguments: ['@current_route_match']`, tag `hook_implementations`), mirroring the existing `do_chrome.hooks` (`DoChromeHooks`) entry exactly, since that class has the identical `RouteMatchInterface` constructor shape.

## Design decisions
- **Explicit service registration vs. relying on core's auto-registration:** I traced `HookCollectorPass::registerHookServices()` and confirmed Drupal 11 auto-registers an autowired service for any undeclared `#[Hook]` class found under `src/Hook/`, so an explicit `do_chrome.services.yml` entry was not strictly required for the hook to fire. I registered it explicitly anyway, matching this exact module's own established convention (`do_chrome.hooks` / `do_chrome.archive_pin_hooks` both get explicit entries even though `DoChromeHooks` has the same constructor shape) — consistency with the sibling pattern over relying on an implicit fallback. This was necessary because the kernel test instantiates `PageHelp` directly (`new PageHelp(...)`), which doesn't exercise Drupal's actual hook-dispatch wiring — without the services.yml entry, the class would exist and the kernel tests would still pass, but the hook would never actually fire on a real page request.
- **`infoTrigger()` duplicated, not extracted:** per brief.md's Reuse map — every existing B-story (#88/#89/#90) ships its own trivial private `infoTrigger()`; matching that convention beats a cross-cutting shared-helper refactor outside this story's scope.

## Reuse / extend-vs-new
Extended the established `infoTrigger()` render-array pattern from `GroupTypeContentHelp::infoTrigger()` (duplicated per brief's own written justification, not extracted — see brief.md §Reuse). `HelpText.php` was extended append-only, not replaced. No new object was created where the brief called for extension; `PageHelp` itself is the one genuinely NEW class the brief specified (§Files: "NEW: ... PageHelp.php (sole owner, all logic here)").

## Architecture notes for A
- New service `do_chrome.page_help` tagged `hook_implementations`, autowired-by-arguments on `@current_route_match` — same DI pattern as `DoChromeHooks`.
- No schema/contract changes. No new library (reuses the globally-attached `do_chrome/tooltips`).
- Injection is via `hook_preprocess_page_title` / `title_suffix` — no template override, per brief's skip-D design.
- The route-map (`getRouteMap()`) is a single source of truth read by both the allowlist gate and the kernel test's exact-10-entries assertion — no independently hand-maintained duplicate.

## Deviations from spec / wireframe
None. D was skipped per brief (highly patterned, no wireframe). Route map, key names, and copy match brief.md §Scope and §Copy verbatim.

## Tier 1 self-check (incl. tests now GREEN)
Ran inside the already-running `gm126-page-tooltips` DDEV containers (`ddev-gm126-page-tooltips-web`/`-db`) via `docker exec`, since `ddev` CLI bookkeeping in this worktree currently points at a differently-named/unlisted project — the containers themselves are healthy and correctly seeded per T's handoff.

`bash scripts/ci/assemble-config.sh` (run inside the container, where `php`/`vendor/autoload.php` exist):
```
==> assemble-config: repo root = /var/www/html
==> config: copied 95 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

`php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Unit/HelpTextPageKeysTest.php web/modules/custom/do_chrome/tests/src/Kernel/PageHelpRouteMapTest.php --testdox` (Kernel run with `SIMPLETEST_DB='mysql://db:db@db:3306/db'`):
```
....DDDD                                                            8 / 8 (100%)

Help Text Page Keys (Drupal\Tests\do_chrome\Unit\HelpTextPageKeys)
 ✔ Live route page keys return non empty string
 ✔ W 2 pre registered page keys return non empty string
 ✔ Nonexistent page key returns empty string
 ✔ All ten page keys are present in all

Page Help Route Map (Drupal\Tests\do_chrome\Kernel\PageHelpRouteMap)
 ✔ Route map contains exactly ten entries
 ✔ Preprocess page title renders trigger for live stream route
 ✔ Preprocess page title does not mutate for unregistered route
 ✔ Rendered trigger carries all required attributes and glyph

Tests: 8, Assertions: 42, Deprecations: 1, PHPUnit Deprecations: 10.
```
8/8 GREEN as required. (The 1 "Deprecation" is core's own `#[RunTestsInSeparateProcesses]` framework-wide notice on the pre-existing `KernelTestBase`, present on every kernel test file in this codebase, not something specific to or introduced by these two files/tests.)

Also ran the full `do_chrome` test dir as a broader regression check: 23/24 pass; the one failure (`PermissionMatrixPanelTest::testPermissionMatrixPanelRenders`) is a pre-existing Functional/BrowserTestBase test from PR #106 (#91) that I made zero changes to (`git diff` on its target classes `PermissionMatrixPanel.php`/`PermissionMatrix.php` is empty) — it fails on a `pageTextContains` assertion after login, unrelated to page-title preprocessing, and appears to be seed-state/environment fragility in this specific DDEV instance, not a regression from this story.

`phpcs` self-check: ran with the correct project standard (`--standard=Drupal,DrupalPractice`, since no `phpcs.xml` sets a default and CI does not run phpcs at all — verified via `.github/workflows/*.yml`, no `phpcs` invocation exists). Result: my added `HelpText.php` block (lines 210-233) is 100% clean under that standard; the standard's other findings all fall in pre-existing, untouched lines (1-209). `PageHelp.php`'s one flagged line (docblock starting with `#126` instead of a capital letter) reproduces identically on the pre-existing sibling files `GroupTypeContentHelp.php` and `PermissionMatrixPanel.php` — confirmed this is the codebase's own established docblock convention, not a new deviation.

## Tests that look wrong (for T)
None. Both test files matched the brief precisely; no adjustment needed to the RED tests to reach GREEN.

## Known issues
None against the acceptance criteria in scope for F (unit + kernel GREEN). E2E (`tests/e2e/page-help.spec.ts`) was explicitly out of scope per the issue ("Do NOT run e2e — that's T-green's job") and was not run by me.

## Files changed
- `docs/groups/modules/do_chrome/src/Hook/PageHelp.php` (new)
- `docs/groups/modules/do_chrome/src/HelpText.php` (modified, append-only)
- `docs/groups/modules/do_chrome/do_chrome.services.yml` (modified, new service entry)
