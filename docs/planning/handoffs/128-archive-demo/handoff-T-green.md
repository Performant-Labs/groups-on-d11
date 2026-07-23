# Handoff-T-green: Phase 6 - #128 SD-3 Archive Demonstrator Seeds

**Date:** 2026-07-23
**Branch:** 128-archive-demo
**Issue:** #128
**Handoff-F reviewed:** `docs/planning/handoffs/128-archive-demo/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/128-archive-demo/handoff-T-red.md`

## Verdict: GREEN

All 8 Phase-4-authored E2E tests pass. Kernel + Functional smoke for
`do_group_extras`/`do_chrome` is clean (0 failures, 0 errors). AC-3 and AC-4
both confirmed. One pre-existing, latent test-authorship bug in
`group-restore.spec.ts` (inherited unchanged from #143, not introduced by
#128) was found and fixed — full root-cause below. No blocking issues remain.

## GREEN confirmation

Command (matching the task's exact instruction):

```
BASE_URL="http://gm128-archive-demo.ddev.site" npx playwright test \
  tests/e2e/demonstrator-seeds.spec.ts tests/e2e/group-restore.spec.ts tests/e2e/directory-cards.spec.ts \
  --reporter=list
```

Final result (clean, single-pass, from a freshly-reset environment):

```
Running 8 tests using 1 worker

  ok 1 AC-1a: anonymous sees Legacy Infrastructure on /all-groups, tagged with the Archive type (305ms)
  ok 2 AC-1b: clicking the Legacy Infrastructure card lands on the group page showing the Archived state (badge + tooltip) (1.5s)
  ok 3 AC-1c: content-create is denied on the archived group but allowed on a non-archived control group for the same non-Organizer user (5.2s)
  ok 4 AC-2 (regression guard): anonymous sees the Pinned badge + tooltip on the pinned post's canonical page (1.4s)
  ok 5 directory-cards.spec.ts: anonymous sees cards with type + visibility badges and member counts (310ms)
  ok 6 directory-cards.spec.ts: anonymous gets a "View group" affordance, never a Join button (230ms)
  ok 7 directory-cards.spec.ts: a logged-in member sees the "Member" note on groups they belong to (2.8s)
  ok 8 group-restore.spec.ts: archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group (8.8s)

  8 passed (21.5s)
```

**Spot-check that behavior, not implementation, is pinned:** confirmed
`ArchivePinHooks::preprocessGroup` (`docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php`)
is real production logic conditionally rendering `span.group__archived-badge`
based on `field_group_type` — the badge locator used throughout
`group-restore.spec.ts` and `demonstrator-seeds.spec.ts` is a genuine
behavior signal that would fail if restore/archive logic broke, not a
vacuous/hardcoded check.

## Test fix made during T-green (T owns test authorship — this is not a
## production-code change and F did not touch this file)

`tests/e2e/group-restore.spec.ts` had a real, reproducible defect at three
call sites using an unscoped `page.getByText(/Archived/i)` locator:

- Legacy Infrastructure's own seeded `field_group_description`
  ("Archived: Drupal 7 module maintenance coordination. This group is no
  longer active.", `docs/groups/scripts/step_700_demo_data.php:75`)
  permanently renders the literal word "Archived" on the group's canonical
  page **regardless of archive/restore state** — confirmed live via
  `curl http://gm128-archive-demo.ddev.site/group/8` both before and after
  a restore action.
- Step 3's assertion, `expect(page.getByText(/Archived/i)).toHaveCount(0)`,
  could therefore **never legitimately pass** — it failed deterministically
  on every run, aborting the test mid-sequence (after Step 2's restore, but
  before Step 4's re-archive), which left gid=8 stuck in a "Working
  group"-typed state and corrupted the fixture for the *next* run too (a
  compounding failure I hit repeatedly during verification until I traced
  it to source).
- This bug **predates #128** — it is unchanged from #143's original
  T-green commit (`6ad4469`), confirmed via `git log`/`git show`. #128's own
  T-RED commit (`1a54114`) only edited `findLegacyInfrastructureGid()`,
  never touched these three lines. It was masked before #128 because the
  pre-#128 seed (Legacy Infrastructure `status=0`) made the lookup helper
  fail at the very first step (element not found on `/all-groups`), so the
  suite never reached line 120 to expose the defect. **#128 is what first
  makes this test run to completion** — and in doing so, surfaces a defect
  #128 did not cause.
- **Fix:** removed the two redundant `getByText(/Archived/i)` checks at
  Step 1 and Step 5 (each immediately followed by the correct, unambiguous
  `span.group__archived-badge` locator — the free-text check added no
  additional signal and duplicated an assertion, so it is deleted rather
  than reworded, per test-quality guidance against redundant assertions of
  the same behavior), and replaced the Step-3 `toHaveCount(0)` target with
  the same badge-scoped locator (the actual archive-state signal this step
  needs).
- Re-verified from a clean, reset gid=8 state (Archive-typed, published):
  passes deterministically across 3 consecutive isolated runs.

Diff (`git diff tests/e2e/group-restore.spec.ts`): 1 file changed, 23
insertions(+), 3 deletions(-) — comment-heavy (documents the root cause
inline for future readers) but structurally a 3-line locator fix.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Touched E2E specs | `npx playwright test tests/e2e/demonstrator-seeds.spec.ts tests/e2e/group-restore.spec.ts tests/e2e/directory-cards.spec.ts` | 8/8 pass | 8/8 pass (21.5s) | PASS |
| AC-3 static check | `grep -n 'set("status", 0)' docs/groups/scripts/step_700_demo_data.php` | no match | no match (exit 1) | PASS |
| AC-4 idempotency | re-run `step_700_demo_data.php` on already-seeded site | no fatal error; gid=8 unchanged | completed cleanly; gid=8 `status=1 type=Archive` before AND after | PASS |
| Kernel: `do_group_extras` | `phpunit ... do_group_extras/tests/src/Kernel/` | all pass | 12/12 pass (2 pre-existing `⚠` warnings, unrelated core deprecations) | PASS |
| Kernel: `do_chrome` (Unit) | `phpunit ... do_chrome/tests/src/` | all pass | 15/15 pass | PASS |
| Functional: `do_group_extras` | `phpunit ... do_group_extras/tests/src/Functional/` (CI-matching `SIMPLETEST_*` env + test router) | all pass | 10/10 pass | PASS |
| Functional: `do_chrome` | `phpunit ... do_chrome/tests/src/Functional/` | all pass | 1/1 pass | PASS |
| Live DDEV site reachable | `curl -o /dev/null -w '%{http_code}' /all-groups` | 200 | 200 (before and after all env repairs) | PASS |

Combined Kernel+Functional PHPUnit summary: `Tests: 38, Assertions: 681,
Failures: 0, Errors: 0` — reported as `OK, but there were issues!` solely
because of 16 pre-existing Drupal-core deprecation notices (`cache.static`,
`EntityInterface::getOriginal()`, `plugin.manager.archiver`, etc.) — the
same category of noise CI's own `SYMFONY_DEPRECATIONS_HELPER: disabled`
exists to silence. None are related to #128's 4-line seed-script diff.

**Functional-test environment note:** the Functional (`BrowserTestBase`)
suites require a setup this worktree hadn't exercised before — a *separate*
throwaway MySQL DB (`SIMPLETEST_DB`) and a bare `php -S` "test router"
webserver on `127.0.0.1:8080` (`SIMPLETEST_BASE_URL`), matching
`.github/workflows/test.yml`'s Functional job exactly (this is NOT the live
`gm128-archive-demo.ddev.site` install — BrowserTestBase installs its own
throwaway site per test). Reproduced inside the DDEV container:
`mysql -h db -u root -proot -e 'CREATE DATABASE IF NOT EXISTS drupal_functest'`,
appended a `hash_salt` line to the (gitignored) `web/sites/default/settings.php`
so the test router could bootstrap, then
`php -S 127.0.0.1:8080 -t web "$PWD/web/.ht.router.php"` (absolute router
path — a relative path resolves to the wrong location and 500s every
request; CI's own comment explicitly warns of this, and it was my first,
corrected mistake). Router stopped and the `hash_salt` line reverted after
verification; live site confirmed unaffected (`curl` 200 post-revert).

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test coverage for each AC | Reviewed T-RED's 4 new tests (AC-1a/1b/1c/AC-2) + AC-3 (diff-verifiable) + AC-4 (this handoff's idempotency re-run) | All 6 ACs covered |
| Test quality — behavior not implementation | Spot-checked `ArchivePinHooks::preprocessGroup` drives the badge; confirmed `group-restore.spec.ts` would fail if restore logic broke | PASS |
| Test quality — redundant assertions pruned | Found + removed 2 duplicate `getByText(/Archived/i)` checks in `group-restore.spec.ts` (each shadowed by the immediately-following badge locator) — coverage ceiling respected, not just floor | Fixed |
| Type safety | No TS `any` casts introduced; `.spec.ts` files use typed Playwright APIs throughout | PASS (no changed files introduce type issues) |
| Error handling / access-control paths | AC-1c tests the 403/200 differential for the same non-Organizer user across archive-typed vs. control group — a real access-control assertion, not a truism (empirically verified in T-RED) | PASS |
| Data integrity / idempotency | AC-4: re-ran seed script on already-seeded site; no duplicate/orphaned state; `loadByProperties`-then-`continue` guard (step_700:78-79) confirmed still effective | PASS |
| API/route contract | AC-1c's route (`/group/{gid}/content/create/group_node%3Aforum`) and its 403/200 behavior matches survey.md's empirically-corrected understanding (not the original assumption) | PASS |
| Security (input validation / auth checks) | Not applicable — #128 is a data-only seed change; no new form/route/input surface | N/A |
| Migration safety | Not applicable — no schema/config change; seed-script-only edit | N/A |
| Playwright suite structural pass | Full `npx playwright test` (all 13 spec files, 71 tests) | 68 passed / 2 failed / 1 skipped (see below) — 0 failures attributable to #128 |

**Full-suite result and regression-sweep detail:** ran the complete E2E suite
(`tests/e2e/*.spec.ts`, 71 tests) immediately after a fixture-group cleanup
pass. Result: **68 passed, 2 failed, 1 skipped**.

- The 1 skip is `manage-members.spec.ts`'s pending-membership-request test —
  pre-existing, self-documented in its own `test.skip()` message ("No
  pending-request seeding path is exposed in this build yet... belongs to
  #121's territory"), unrelated to #128, confirmed via `git log` predates
  this branch.
- The 2 failures are both in `membership-models.spec.ts` (#121, authored
  and finalized entirely before #128 per `git log`). Root cause: this spec
  is **not idempotent by design** — its own assertions are "user joins
  group instantly" / "user requests to join", i.e., one-way state mutations
  with no teardown. Running the full suite twice in a row against the same
  persistent DB (which is what happened across my multiple verification
  passes this session) will always fail these 2 tests on the second pass,
  regardless of #128. Verified this diagnosis by (a) querying
  `group_relationship_field_data` directly and finding `sophie_mueller`/
  `ravi_patel` already held the memberships the tests attempt to create,
  (b) removing just those 2 leftover memberships, (c) re-running
  `membership-models.spec.ts` alone: **4/4 pass**. Not a #128 regression;
  not a defect requiring a fix in this story (out of #128's Files In Scope,
  and the non-idempotency is #121's pre-existing test-authorship
  characteristic, not something #128 exposes or touches).

**Regression sweep of adjacent surfaces, as instructed:**
- Grep for hardcoded group counts on `/all-groups`
  (`count.*7|toHaveCount(7)|.length.*=== 7|.length.*7[^0-9]`) across
  `tests/e2e/`: **no matches**. No test hardcodes the pre-#128 7-published-
  group assumption; nothing to update.
- `nav.spec.ts` (4 tests) and `persona-switcher.spec.ts` (4 tests), both of
  which exercise `/all-groups`-adjacent surfaces: all pass in the full-suite
  run above.
- `all_groups` View: confirmed Legacy Infrastructure now renders on
  `/all-groups` for anonymous visitors (AC-1a), where it was previously
  filtered out by `status=0`.

**Environment-hygiene findings (session-local, not code defects, advisory
only):**
1. Repeated full-suite Playwright runs against one long-lived DDEV instance
   accumulate real, uncleaned e2e-fixture groups (created by
   `phase1-4.spec.ts`, `manage-members.spec.ts`, etc. — a pre-existing,
   cross-story suite characteristic, not introduced by #128). Once total
   group count exceeds the `all_groups` View's 25-item pager, low-gid groups
   like Legacy Infrastructure (gid=8) get pushed to page 2, causing
   `/all-groups`-dependent tests to fail with "element not found" — purely a
   session artifact of re-running the suite many times without a DB reset
   between runs (a fresh CI checkout installs once per job and would not
   exhibit this). Cleaned the accumulated fixture groups (gid > 8) each time
   this was hit during verification.
2. The DDEV container (`gm128-archive-demo.ddev.site`) stopped unexpectedly
   once mid-session (Docker/DDEV resource event) — restarted cleanly via
   `ddev start`; DB state was intact; no code impact.
3. Both findings above, plus the `membership-models.spec.ts` non-idempotency,
   are noted here for U/S's awareness but are **not blocking** and require
   no test or code change — they are artifacts of this verification
   session's repeated manual full-suite runs, not of #128's change or a
   fresh single-pass CI run.

## Acceptance criteria status

| AC | Status | Backing evidence |
|---|---|---|
| AC-1a: Legacy Infrastructure visible on `/all-groups`, Archive-typed | PASS | `demonstrator-seeds.spec.ts` AC-1a |
| AC-1b: clicking through shows Archived badge + tooltip on canonical page | PASS | `demonstrator-seeds.spec.ts` AC-1b |
| AC-1c: content-create denied on archived group, allowed on control group, same user | PASS | `demonstrator-seeds.spec.ts` AC-1c |
| AC-2: Pin badge + tooltip regression guard | PASS | `demonstrator-seeds.spec.ts` AC-2 |
| AC-3: no `set("status", 0)` mutation remains in step_700 | PASS | `grep` — no match |
| AC-4: idempotent re-run of step_700 | PASS | re-run completed cleanly; gid=8 state unchanged before/after |

## Blocking issues

None.

## Advisory notes

- The `group-restore.spec.ts` fix (this handoff) is the only test-file
  change in T-green. It is a genuine bug fix (the assertion could never
  legitimately pass), not a weakening of coverage — the badge-scoped
  locators it now uses are strictly more precise than the free-text checks
  they replace, and the spec still asserts the same 5-step behavior
  end-to-end.
- Pre-existing gap (flagged by T-RED, reconfirmed here, no action taken —
  outside #128's Files In Scope): `.gc-directory-card` on `/all-groups`
  renders only a plain `.gc-directory-card__type` "Archive" taxonomy label,
  not the full `span.group__archived-badge` + tooltip markup (Views-fields
  row rendering never invokes `hook_preprocess_group`). Worth a follow-up
  ticket.
- Recommend a lightweight `afterEach`/global-teardown convention for e2e
  fixture-creating specs (`phase1-4.spec.ts`, `manage-members.spec.ts`,
  `membership-models.spec.ts`, etc.) in a future story — not urgent for CI
  (which seeds fresh per job) but would make local/manual multi-run
  verification less fragile. Out of scope for #128.

## Files changed by T-green

- `tests/e2e/group-restore.spec.ts` (test-authorship fix — 23 insertions,
  3 deletions; see "Test fix made during T-green" above)
- `docs/planning/handoffs/128-archive-demo/decisions.md` (Phase 6 entry
  appended)
- `docs/planning/handoffs/128-archive-demo/handoff-T-green.md` (this file)

No production code touched. No build artifacts staged
(`web/modules/custom/`, `web/autoload_runtime.php`, `config/sync/*` beyond
F's already-modified set, `.ddev/config.yaml` remain untracked/reverted per
project convention).
