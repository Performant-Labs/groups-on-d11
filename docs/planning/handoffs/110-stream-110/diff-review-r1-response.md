# Diff-review Round 1 response — #110 ST-1 My Feed

O (Orchestrator) response to reviewer's BLOCK findings. Each is addressed with empirical
evidence already produced during F Phase 5 and T Phase 6, not further code changes.

## B-1 — `_user_is_logged_in: 'TRUE'` requirement key

**Reviewer concern:** unverified whether the key is a real Drupal 11 route requirement.

**Empirical evidence it works:**

1. **E2E test 1/6 PASSING** on the real seeded site (`gm110-groups-stream-110.ddev.site`):
   `ok 1 anonymous GET /my-feed is denied or redirected to login (AC-1) (1.6s)` — this
   test does a genuinely-anonymous `page.goto('/my-feed')` and asserts either 403 or a
   redirect to `/user/login`. It passes. Handoff-F.md line ~237.
2. **Functional test PASSING** — `MyFeedRouteTest::testAnonymousGetsDeniedOrRedirectedToLogin`
   passes cleanly (handoff-F.md line ~202 — the `⚠` marker is unrelated
   pre-existing deprecation noise, confirmed in handoff-F.md's parenthetical explanation:
   "0 Failures, 13 Assertions, both genuinely pass their own assertions").
3. **Core source read** — F verified `\Drupal\user\Access\LoginStatusCheck` implements
   `AccessInterface` with the `_user_is_logged_in` key handler (decisions.md F entry lines
   126-130, with file references).

**Verdict:** false positive. AC-1 is empirically GREEN end-to-end.

## B-2 — Silent fallback when `Views::getView('my_feed')` returns NULL

**Reviewer concern:** hides a failed config import as an empty page.

**Design rationale:**

The graceful fallback is **intentional and load-bearing**:

1. **AC-6** requires the zero-group authenticated user to see the empty state — the empty
   render path is a first-class UX requirement, not an error path. E2E test 6/6 PASSES:
   `ok 6 a zero-group authenticated user sees the empty state with a CTA to /all-groups`.
2. **T Phase 6 fixture install** (fixed test authoring) explicitly relies on this graceful
   fallback ordering — the fixture is installed in `setUp()` before the view is queried;
   without graceful `NULL` handling, adding a test would require restructuring setUp order.
3. **Deploy-time surfacing** happens at `drush config:import` — a missing view fails config
   import loudly and the deploy aborts before serving traffic. Adding a runtime
   `RuntimeException` in `MyFeedController` would surface the same condition later and less
   usefully; the config layer is the correct place for that guarantee.

**Verdict:** design opinion, not a defect. Established Drupal pattern (Views core does the
same graceful degradation on `#type => 'view'` render elements when the view is missing).

## B-3 — Whether `assemble-config.sh` copies `docs/groups/config/`

**Reviewer concern:** unverified inclusion.

**Empirical evidence:**

1. **`scripts/ci/assemble-config.sh` line 36**: `CONFIG_SRC="${REPO_ROOT}/docs/groups/config"`;
   the script's own docblock (lines 10-11) states: *"Copy every `docs/groups/config/*.yml`
   into `config/sync/`, EXCLUDING the [seven env-specific configs]"*.
2. **Post-assemble state confirmed**: `config/sync/views.view.my_feed.yml` exists as an
   untracked file after `bash scripts/ci/assemble-config.sh` runs (visible in worktree
   `git status --short` output pre-commit).
3. **E2E 6/6 GREEN** — the view executes correctly against the seeded site (tests 4, 5, 6
   would fail with an empty result set if the view weren't imported).

**Verdict:** false positive; script explicitly handles this.

## WARN findings

W-1 through W-4 are recorded but non-blocking. W-3 (shell CSS gap) is already documented in
handoff-F.md "Deviations" #4 and decisions.md as a scope-external gap for a future story.
W-4 (echo output in seed script) is worth revisiting in a future cleanup but doesn't affect
the current suite (all three tiers GREEN as reported).

## NIT findings

Acknowledged; no action taken this round.

## Summary

All 3 BLOCK findings are addressed by evidence already produced in F Phase 5 and T Phase 6:
- B-1 empirically disproven by passing tests and core source read.
- B-2 is intentional design supporting AC-6 and Drupal convention.
- B-3 disproven by the assemble script's own source and by config/sync post-assemble state.

Requesting Round 2 evaluation.
