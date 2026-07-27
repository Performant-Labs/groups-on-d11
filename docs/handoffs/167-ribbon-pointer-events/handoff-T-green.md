# Handoff-T-green: Phase 6 - #167 showcase ribbon pointer-events CSS fix

**Date:** 2026-07-24
**Branch:** 167-showcase-ribbon-pointer-events
**Issue:** #167
**Handoff-F reviewed:** `docs/handoffs/167-ribbon-pointer-events/decisions.md` (F entry, 2026-07-24)
**Handoff-T-red:** `docs/handoffs/167-ribbon-pointer-events/decisions.md` (T-RED entry, 2026-07-24 — no separate handoff-T-red.md file was written for this run; the RED narrative lives inline in decisions.md)

## GREEN confirmation

Authored suite (`tests/e2e/showcase-ribbon-pointer-events.spec.ts`, 3 tests) now passes in full
against the live seeded DDEV site:

```
BASE_URL="http://gm167-ribbon.ddev.site" npx playwright test tests/e2e/showcase-ribbon-pointer-events.spec.ts --reporter=list

Running 3 tests using 1 worker

  ok 1 [chromium] › #167 showcase ribbon does not intercept pointer events › the ribbon does not intercept a click meant for the page beneath it (3.4s)
  ok 2 [chromium] › #167 showcase ribbon does not intercept pointer events › the ribbon's own "See what it compares ->" link remains clickable (302ms)
  ok 3 [chromium] › #167 showcase ribbon does not intercept pointer events › the ribbon's dismiss button remains clickable and dismisses the ribbon (376ms)

  3 passed (5.6s)
```

**Spot-check (behavior, not implementation, is pinned):** reverted
`docs/groups/modules/do_showcase/css/do_showcase.css` to the pre-fix `HEAD` version, reassembled,
rebuilt cache, re-ran the same spec — test 1 (the load-bearing assertion) failed exactly as at
T-RED (`Expected: false, Received: true`), tests 2/3 stayed green (they pin the already-true
anchor/button clickability, unaffected either way). Restored the fixed CSS, reassembled, rebuilt
cache, re-ran — back to 3/3 passing. Confirms the suite is not a tautology.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| DDEV bring-up | `ddev start` (worktree `gm167-ribbon`, after fixing `.ddev/config.yaml`'s copy-pasted `name: gm129-activity`) | containers healthy | web/db up, router ready | PASS |
| Composer deps | `ddev composer install --no-interaction --no-progress --prefer-dist` | clean install | completed, autoload generated | PASS |
| Config assembly | `ddev exec bash scripts/ci/assemble-config.sh` | 131 config files + 15 modules copied, do_* registered in core.extension | matched | PASS |
| Site install + config:import | `ddev drush site:install standard ...` then `config:import -y` (after fixing commented-out `config_sync_directory` in `settings.php`) | clean install, 131 config changes applied | succeeded | PASS |
| Seed data | step_700/720/780/790/7xx-backfill/795 + `cron` + `cache:rebuild`, run in CI order | idempotent seed completes | completed, no errors | PASS |
| Site serving | `curl http://gm167-ribbon.ddev.site/` | HTTP 200 | 200 (HTTPS 000 — mkcert not installed on host, expected/noted by ddev itself) | PASS (via HTTP) |
| New spec suite | `npx playwright test tests/e2e/showcase-ribbon-pointer-events.spec.ts --reporter=list` | 3/3 pass | 3/3 pass | PASS |
| Regression suite | `npx playwright test tests/e2e/showcase.spec.ts tests/e2e/showcase-help.spec.ts tests/e2e/persona-switcher.spec.ts --reporter=list` | all pass, no regression | 30/30 pass | PASS |

## Tier 2 results

- **Test coverage:** All 3 acceptance-criterion behaviors from the brief (ribbon doesn't intercept
  underlying clicks; ribbon's own link stays clickable; dismiss button stays clickable/functional)
  each have exactly one dedicated E2E test. No gaps, no duplication.
- **Test quality:** Each test names a single behavior, targets the cheapest sufficient tier (E2E —
  correctly, since this is a real-browser CSS-compositing/pointer-events effect not observable at
  unit/kernel tier), and asserts behavior (`elementFromPoint` hit-testing + an actual dismiss click)
  rather than implementation (no test greps the CSS file or asserts a `pointer-events` computed
  style directly — it asserts the *effect* of that property). Suite is proportionate: 3 tests, no
  more than needed to cover the 3 stated behaviors, no redundant tests to prune.
- **Type safety:** N/A — CSS-only change, no TypeScript/PHP touched beyond the test spec itself
  (already reviewed at T-RED).
- **Error handling:** N/A — no new error paths introduced by a pure CSS fix.
- **Data integrity:** N/A — no database/schema change.
- **API contract:** N/A — no request/response shape change.
- **Security:** N/A — no input handling change; `pointer-events` is a rendering-only CSS property.
- **Migration safety:** N/A — no config/schema migration involved.
- **Lint (phpcs):** `ddev exec php vendor/bin/phpcs docs/groups/modules/do_showcase/css/do_showcase.css`
  — exit 0, no output. No project-level phpcs.xml exists, so no sniffs ran against this `.css` file
  either way; not a meaningful signal, reported as expected per the task brief.

## Acceptance criteria status

1. **Ribbon does not intercept clicks meant for the page beneath it** — PASS, backed by
   `the ribbon does not intercept a click meant for the page beneath it` (test 1).
2. **Ribbon's own "See what it compares ->" link remains clickable** — PASS, backed by
   `the ribbon's own "See what it compares ->" link remains clickable` (test 2).
3. **Ribbon's dismiss button remains clickable and functional** — PASS, backed by
   `the ribbon's dismiss button remains clickable and dismisses the ribbon` (test 3).

## Blocking issues

None.

## Advisory notes

- This worktree's `.ddev/config.yaml` had been copy-pasted from another worktree
  (`name: gm129-activity`), which silently collides with the `groups-st3-events-112` worktree's
  already-registered `gm129-activity` project in `ddev list`. Corrected locally to
  `name: gm167-ribbon` per the orchestrator's brief; this correction is host-local DDEV
  configuration and was not something F needed to touch, but future worktree setup for this issue
  number should carry the right project name from the start to avoid the same collision.
- `web/sites/default/settings.php`'s scaffolded `config_sync_directory` line ships **commented
  out**; a clean-room DDEV bring-up must explicitly append/uncomment it before `config:import`,
  matching a gotcha the project's own `.github/workflows/test.yml` already works around for CI.
  Worth a line in the RUNBOOK if it isn't there already (did not check exhaustively).
- HTTPS on this host 000/timeouts because `mkcert` isn't installed (`ddev start` prints this
  directly) — used the HTTP DDEV URL for `BASE_URL` instead of chasing HTTPS trust setup, since
  functionally identical for this CSS/pointer-events-only verification.
