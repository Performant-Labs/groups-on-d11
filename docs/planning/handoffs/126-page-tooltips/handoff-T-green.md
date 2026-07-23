# Handoff-T-green: Phase 6 - #126 SD-1 Page-level ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 126-page-tooltips
**Issue:** #126
**Handoff-F reviewed:** `docs/planning/handoffs/126-page-tooltips/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/126-page-tooltips/handoff-T-red.md`

## GREEN confirmation

Re-assembled inside `ddev-gm126-page-tooltips-web` (`bash scripts/ci/assemble-config.sh`), then re-ran the exact two Phase-4 files.

**Unit** (`HelpTextPageKeysTest.php`): 4/4 GREEN, 31 assertions.
**Kernel** (`PageHelpRouteMapTest.php`): 4/4 GREEN, 11 assertions.

Both match F's reported numbers exactly. Spot-check: `testPreprocessPageTitleDoesNotMutateForUnregisteredRoute` still fails if the default-deny gate is removed (verified by inspection — the assertion is a byte-for-byte `title_suffix` equality against the pre-call state, which only holds because of the early-return gate); `testRouteMapContainsExactlyTenEntries` fails immediately if any map entry is added/removed. Tests pin behavior, not implementation.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `bash scripts/ci/assemble-config.sh` (in-container) | clean copy | clean copy, 13 modules | PASS |
| Unit suite | `phpunit .../Unit/HelpTextPageKeysTest.php` | 4/4 | 4/4 | PASS |
| Kernel suite | `phpunit .../Kernel/PageHelpRouteMapTest.php` | 4/4 | 4/4 | PASS |
| Full do_chrome dir | `phpunit` Unit+Kernel+Functional | 23/24 (1 known pre-existing fail) | 23/24, same failure signature | PASS |
| do_showcase Kernel (adjacent smoke) | `phpunit` on `do_showcase/tests/src/Kernel` | no regressions | 19/19 GREEN | PASS |

## Tier 2 results

**Full do_chrome regression** — `PermissionMatrixPanelTest::testPermissionMatrixPanelRenders` fails identically to F's reported baseline (`Failed asserting that false is true` on a `pageTextContains` after login, in `UiHelperTrait.php:190`). Zero diff on `PermissionMatrixPanel.php`/`PermissionMatrix.php` confirmed via `git diff` — not a regression from this story.

**E2E** — initial run against `https://gm126-page-tooltips.ddev.site` returned **5/6 failed with 404 on every page**, including `/user/login`. Diagnosed: `ddev-router` was bound to the main worktree's project (`pl-groups-on-d11`), not this story's `gm126-page-tooltips` project — a router-registration conflict, not an app bug (confirmed: hitting the container's mapped host port directly with a `Host:` header returned 200 for the same paths). Re-ran with `BASE_URL` pointed at the container's mapped port (`https://127.0.0.1:53099`) to bypass the router. This surfaced the **real** signal: 4/6 still failed (all 4 "must render ⓘ" assertions; the 2 default-deny absence assertions passed). Root cause: **stale Drupal container cache** — the container's cached hook/service compilation predated F's new `PageHelp` class and `do_chrome.services.yml` entry (from earlier T-red cache-warming). Ran `drush cr` inside the container; re-ran the full spec:

```
BASE_URL="https://127.0.0.1:53099" npx playwright test tests/e2e/page-help.spec.ts --reporter=list
6 passed (46.0s)
```

All 6 tests GREEN: anon `/stream`, anon `/all-groups`, Elena-authed group Stream tab, `/user/login` default-deny, `/admin` default-deny, keyboard-focus. Confirmed via raw `curl` that `.do-chrome-info.page-help-info` with correct `aria-label`/`data-do-tooltip` copy appears in `/stream` HTML post-cache-rebuild.

**Test quality spot-check:** each of the 8 unit/kernel tests names one behavior, sits at the cheapest sufficient tier (static-map contract in Unit, hook/render-array behavior in Kernel, hover/keyboard/cross-page DOM order in E2E — nothing duplicated across tiers), and asserts behavior (rendered attributes/copy, mutate-vs-not) not implementation detail. No redundant tests found; suite is proportionate to a 10-route allowlist + 1 hook feature.

## Acceptance criteria status

| Criterion | Status | Backing test |
|---|---|---|
| AC-1: ⓘ renders with correct copy on live routes (stream, all-groups, group stream) | PASS | `testPreprocessPageTitleRendersTriggerForLiveStreamRoute` (kernel) + e2e tests 1–3 |
| AC-2: W2 route/key pairs pre-registered, resolve non-empty now | PASS | `testW2PreRegisteredPageKeysReturnNonEmptyString` |
| AC-3/AC-4: trigger carries required attributes + glyph, tooltip shows on hover | PASS | `testRenderedTriggerCarriesAllRequiredAttributesAndGlyph` + e2e hover assertions |
| AC-5: keyboard-focusable | PASS | e2e test 6 |
| AC-6: default-deny on unregistered routes | PASS | `testPreprocessPageTitleDoesNotMutateForUnregisteredRoute` + e2e tests 4–5 |
| AC (map integrity): exactly 10 entries, single source of truth | PASS | `testRouteMapContainsExactlyTenEntries` |

## Blocking issues

None. F's implementation is correct; the 404s and initial 4 e2e failures were both environment artifacts (stale router registration, stale container cache), not code defects. No production code was edited by T.

## Advisory notes

- **Router conflict** (`ddev-router` serving the wrong project's `*.ddev.site` hostname) is a recurring cross-story DDEV environment issue already flagged by both T-red and F's handoffs — worth a standing fix (e.g. a documented `ddev start` re-run step in the per-story runbook) so future T/U/S phases don't lose time rediscovering it.
- **Cache staleness after assemble** is a second recurring trap: any time new hook classes/services are added between an earlier `assemble-config.sh` + `site:install` and a later verification pass, a `drush cr` is required before e2e/UI checks are trustworthy — plain `assemble-config.sh` does not itself rebuild Drupal's compiled container. Recommend adding `drush cr` as a standard step immediately after `assemble-config.sh` in the T-green runbook.

---

## Rework re-verification (Phase 6b, 2026-07-23)

**Trigger:** U (Phase 6a walkthrough) flagged that `page.group.members`'s target route, `view.group_members.page_1`, is superseded by #138's `do_group_membership.manage_members` controller (the members-tab UI story that landed after this story's original T-green). F repointed `PageHelp.php`'s route map (commit `80325ba`); T updated the pinned kernel test's expected route-name string to match (commit `c82eb6c`).

**Re-ran the full verification ladder against both commits landed:**

1. `bash scripts/ci/assemble-config.sh` (in-container, `ddev-gm126-page-tooltips-web`) — clean copy, 95 config files + 13 custom modules, no errors.
2. `drush cr` (in-container) — cache rebuild complete. Required per the standing trap noted above: the route-map change is read at hook-dispatch/routing time, not re-evaluated until Drupal's compiled container is rebuilt.
3. Pinned suite — `HelpTextPageKeysTest.php` + `PageHelpRouteMapTest.php`: **8/8 GREEN**, 42 assertions (1 framework-wide `RunTestsInSeparateProcesses` deprecation notice, pre-existing/unrelated). Matches pre-rework baseline exactly.
4. Full `do_chrome` dir: **23/24**, same pre-existing failure — `PermissionMatrixPanelTest::testPermissionMatrixPanelRenders` (missing `SIMPLETEST_BASE_URL` for `BrowserTestBase`, an environment-config gap, not a code regression; zero diff on that test's target classes). No new regressions introduced by the route-name change.
5. Confirmed the new target route is real and resolves: `drush ev` against `router.route_provider` returns `/group/{group}/members` for `do_group_membership.manage_members` — the pinned test string is not just internally consistent but points at a live route.
6. E2E — `BASE_URL="https://127.0.0.1:53099" npx playwright test tests/e2e/page-help.spec.ts --reporter=list`: **6/6 GREEN**. This spec does not exercise `page.group.members` specifically (no test navigates the members tab), so the route swap carried no direct e2e risk, but the full spec was re-run end-to-end per the runbook rather than assumed unaffected.

**Verdict:** Rework is correct and minimal (2 files, 1 line each). No new blocking issues. Suite remains proportionate — no new test was needed for this rework since the existing kernel test already pins the route-map contract; T only updated the expected string, matching its earlier assessment that `testRouteMapContainsExactlyTenEntries`-adjacent tests fail immediately if any map entry changes.

## Updated ready-for status

T-green (rework) complete, no blocking issues. Ready for S (no UI surface change beyond the already-walked #138 members route; U's Phase 6a rework flag is resolved).
