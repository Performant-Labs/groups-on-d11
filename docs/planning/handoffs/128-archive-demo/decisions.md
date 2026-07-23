# #128 — Decision Journal

## Phase 1 (O): brief written, D skipped

- **Decided:** skip D. #128 is a seed semantic correction; the visual (Archive
  badge/tooltip + Pin badge/tooltip + Restore action) shipped in #92/#143 and is
  unchanged. Nothing new to design.
- **Decided:** skip brief-gate o4-mini review (POC lean, "none" rigor). #128 is
  spec-definitive — no plan choices to arbitrate.
- **Assumed:** Sprint Planning post's pin badge already renders on some anonymous-
  reachable page. T-RED will empirically verify and, if false, seed additional
  pin/surface. (Not blocking A up-front — A reviews the plan, T proves the
  premise.)
- **Assumed:** DoGroupExtrasHooks::nodeAccess() is the effective enforcement path
  for at least one anonymous- or non-Organizer-reachable content-create URL on an
  Archive-typed group. T-RED will pick whichever route holds; if none is
  reachable, will document with #143's non-blocking follow-up and re-scope AC-1c
  to "badge visibility as sole observable" (matching #143's PR-time posture).
- **Evidence:** survey.md sections "Key Findings" + "Reuse & Analogous-Feature
  Map"; issue #128 body; step_720:101; step_700:397–400.

## Phase 3 (A): up-front plan review — PASS

- **Decided:** PASS. Plan is a strict extension of shipped mechanisms; no
  parallel-path or new-object smell. Reuse map is sound across all six
  requirements. Test plan covers all six ACs. Idempotency preserved by the
  existing `loadByProperties` guard at step_700:78–79. #134 non-adjacent to the
  edit region. No runtime forward-compat consumer of the `status=0` on Legacy
  Infrastructure — only two doc references in RUNBOOK.md.
- **Assumed:** RUNBOOK.md:2638 and :2800 (which still describe Archive as
  "set to unpublished / status=0") are documentation drift that belongs to #133
  (final honesty sweep) or a spin-off follow-up, not to #128 — the brief's
  non-goals explicitly forbid copy edits in this story. Flagged as `warn`
  finding, not `block`.
- **Assumed:** AC-1c enforcement-path fallback (badge-visibility as sole
  observable if neither create route is anonymously-denied) is pre-authorized by
  the Phase 1 decision above; A does not need to re-litigate at T-RED time.
- **Evidence:** brief.md; survey.md; step_700_demo_data.php:78–92 and 397–400;
  step_720_group_types.php:101; DoGroupExtrasHooks.php:64, 80–94, 99–118;
  views.view.all_groups.yml:83–91; grep for `Legacy Infrastructure` +
  `set("status", 0)` across the tree (only step_700:400 references the
  status mutation; only RUNBOOK.md docs echo the stale semantic).

## Phase 4 (T): author tests (RED) — valid RED confirmed

- **Decided:** AC-1c enforcement path is
  `/group/{gid}/content/create/group_node%3Aforum`, tested with an
  AUTHENTICATED NON-ORGANIZER persona (`elena_garcia` / seeded
  `demo_password_2026`), not anonymous. Empirically probed against a live
  copy of gid=8 (Legacy Infrastructure) with `status` temporarily flipped to
  1 (simulating F's fix): `/node/add/forum?group={gid}` returns 403 for
  ANONYMOUS on every group tested regardless of archive state (a truism, not
  enforcement — survey.md's flagged concern). The `content/create` route ALSO
  returns 403 for anonymous on every group (anonymous lacks the site-wide
  "create group content" permission needed to even attempt it) — but for
  elena_garcia (seeded member of Core Committers, NOT a member of Legacy
  Infrastructure), the SAME route returns 403 on gid=8 (Archive-typed) and
  200 on gid=3/Core Committers (non-archive, elena is a member). This is the
  archive-driven differential signal AC-1c requires. Contrary to survey.md's
  flagged concern (this route bypasses `hook_node_access` via
  `_group_relationship_create_any_entity_access`), in THIS build the route
  IS gated by the archive state — no fallback to badge-only observability
  was needed.
- **Decided:** AC-1a's directory-card assertion targets the actual rendered
  markup — a plain `.gc-directory-card__type` "Archive" taxonomy-label badge
  (no tooltip) — NOT `span.group__archived-badge` (which only renders on the
  group's own canonical page via `hook_preprocess_group`, which the
  `all_groups` View's Views-fields row render never invokes). This is a
  real, pre-existing gap in the `.gc-directory-card` component (outside
  #128's Files In Scope — no theme/template changes permitted per the
  brief's non-goals), documented in the spec header and here, not a test
  authorship error. AC-1b (the group page itself) carries the full
  assertion (`span.group__archived-badge` + `data-do-tooltip`).
- **Decided:** AC-2 is a REGRESSION GUARD, not a RED-driver. Probed
  anonymously against the current (pre-#128) seed: `/node/1` ("Sprint
  Planning: Portland 2026") already renders
  `<span class="pin-badge" data-do-tooltip="...">Pinned</span>` for
  anonymous visitors. `/group/1` and `/group/1/stream` do NOT render the
  badge (Views/teaser row rendering skips `title_suffix`). AC-2 passes today
  and must keep passing after #128's seed change — no seed addition needed.
- **Evidence:** live probes against a seeded `gm128-archive-demo` DDEV site
  (assembled via `scripts/ci/assemble-config.sh`, `site:install standard`,
  `config:import`, full `step_700`/`step_720`/`step_780`/`step_790` seed —
  mirroring `.github/workflows/test.yml`). Confirmed RED against the CURRENT
  seed (Legacy Infrastructure `status=0`): `demonstrator-seeds.spec.ts`
  AC-1a/1b/1c fail (element/route not found — group absent/403 due to
  unpublish, the correct-reason failure); `group-restore.spec.ts`'s
  simplified `/all-groups` lookup helper also correctly fails for the same
  reason (expected: the helper's fix is a no-op only once F's seed change
  ships). Confirmed GREEN when `status` is temporarily flipped to 1
  (simulating F's fix, then reverted): all 8 tests across
  `demonstrator-seeds.spec.ts` + `group-restore.spec.ts` +
  `directory-cards.spec.ts` pass. `directory-cards.spec.ts`'s 3 tests are
  unaffected either way (doc-comment-only edit, assertion unchanged).

## Phase 5 (F): implement (GREEN) — sole seed edit applied, all 8 tests pass

- **Decided:** Applied the sanctioned single deletion at
  `step_700_demo_data.php:397–400` exactly as scoped — removed the
  `$groups = ...; $g = reset($groups); if ($g) { $g->set("status", 0); ... }`
  block and replaced it with a 5-line comment explaining the semantic
  correction and pointing at brief.md. No other production file touched. `git
  diff --stat` confirms 1 file changed, 5 insertions(+), 4 deletions(-).
  `grep -n 'set("status", 0)' step_700_demo_data.php` returns no match
  (AC-3 satisfied by the diff itself).
- **Decided:** Did not edit any test file. `demonstrator-seeds.spec.ts`,
  `group-restore.spec.ts`, `directory-cards.spec.ts` are exactly as T-RED
  left them — no test looked wrong; none needed flagging back to T.
- **Assumed → confirmed:** T-RED's claim that "no other seed/config change is
  needed" (handoff-T-red.md "Surprises for F" #4) held exactly — re-applying
  the seed's effect (flipping gid=8 to published, keeping `field_group_type` =
  Archive, which `step_720` already sets and which the idempotency guard at
  step_700:78–79 leaves untouched on re-run) was sufficient alone for all 4
  new anonymous-persona tests and both edited specs to go GREEN.
- **Decided (environment, not code):** the DDEV worktree's `web/modules/custom/`
  and `web/autoload_runtime.php` had been cleaned (correctly, per T's own
  "revert build artifacts before commit" hygiene) since T's last verification
  pass, leaving the running `gm128-archive-demo.ddev.site` container serving a
  PHP fatal error (`autoload_runtime.php` missing) on every request. Re-ran
  `bash scripts/ci/assemble-config.sh` (via `ddev exec`, regenerating
  `web/modules/custom/` from `docs/groups/modules/` — 13 modules, matches T's
  count) and `ddev composer install` (regenerating the Symfony-runtime
  `web/autoload_runtime.php` scaffold file) to restore the site to a servable
  state. Both are gitignored-equivalent build artifacts per the project
  override — regenerated, never edited, never staged.
- **Decided (flake diagnosis, not a code or test bug):** the FIRST full-suite
  Playwright run showed 7/8 green and 1 failure
  (`group-restore.spec.ts`, `page.goto('/group/8/edit')` →
  `net::ERR_ABORTED` at the 30s test-level timeout). Diagnosed via the
  Playwright trace (`0-trace.trace` `before`/`after` `startTime`/`endTime`
  pairs) rather than accepting "env-blocked": the failing test's OWN first
  navigation (`goto('/all-groups')`, `waitUntil: 'load'` — the Playwright
  default, used because the helper doesn't override it) took 14,590ms by
  itself, consuming half the test's 30s budget before Step 1 even asserted
  anything; by the time Step 4's `goto('/group/8/edit')` fired, only ~600ms
  of budget remained, so the outer test-level timeout force-aborted an
  in-flight (not hung, not erroring) navigation. Root-caused to Drupal's
  well-known cold-cache first-hit-after-`cache:rebuild` penalty (Twig/render-
  cache/asset-aggregate recompilation): `curl` timing showed the identical
  `/all-groups` route go from 9.3s (first hit right after my `cache:rebuild`)
  to 54–62ms on the next two hits. Re-ran the full 8-test suite a second time
  with the cache now warm (no code or test change in between) — **8/8 passed,
  26.4s total**, `group-restore.spec.ts`'s previously-timing-out test alone
  passing in 7.9s (vs. the ~35s+ that triggered the abort cold). This is
  purely a local-environment artifact of MY OWN verification sequence
  (assemble → composer install → cache:rebuild → immediately hit Playwright)
  colliding with `playwright.config.ts`'s fixed 30s global test timeout and
  the `waitUntil: 'load'` default on one navigation; #128's 4-line diff has
  zero code path that touches caching, rendering, or font/asset delivery, so
  there is no plausible mechanism by which this story's change caused or
  could cause this. Not flagged as a test-authorship issue for T; not
  reproduced on a warm cache; no code or test edit made in response to it.
- **Evidence:** `git diff -- docs/groups/scripts/step_700_demo_data.php`
  (5 insertions, 4 deletions, no other file); two full Playwright runs against
  `gm128-archive-demo.ddev.site` — first (cold cache) 7/8 pass with the one
  timeout diagnosed above, second (warm cache) 8/8 pass in 26.4s; extracted
  trace zip `test-results/group-restore-.../trace.zip` →
  `0-trace.trace`/`0-trace.network` call-timing analysis; `curl` timing
  before/after `cache:rebuild` on `/all-groups` (9.3s cold → 0.05s warm);
  server logs (`ddev logs`) showing no PHP fatal/error at the failure
  timestamp (only an earlier, since-fixed `autoload_runtime.php` fatal from
  before the environment repair, and a client-side "prematurely closed
  connection" INFO line matching the client-aborted `ERR_ABORTED`, not a
  server error); `drush php:eval` confirming gid=8's final state
  (`field_group_type` = Archive, `published` = yes) after the full suite.

## Phase 6 (T): verify (GREEN) + Tier 2 — GREEN, one latent test bug fixed

- **Decided (test fix, T owns test authorship):**
  `tests/e2e/group-restore.spec.ts` had a real, pre-existing, reproducible
  defect — inherited unchanged from #143's original T-green (`6ad4469`), not
  introduced or touched by #128's T-RED (`1a54114`, which only edited the
  lookup helper) — at three call sites using an unscoped
  `page.getByText(/Archived/i)` locator. Legacy Infrastructure's own seeded
  `field_group_description` ("Archived: Drupal 7 module maintenance
  coordination. This group is no longer active.",
  `step_700_demo_data.php:75`) permanently contains the literal word
  "Archived" on the group's canonical page REGARDLESS of archive/restore
  state (confirmed live via `curl http://.../group/8` both before and after
  restore). The Step-3 assertion `expect(page.getByText(/Archived/i))
  .toHaveCount(0)` could therefore never legitimately pass — it deterministically
  failed on every run, aborting the test mid-sequence (after Step 2's restore
  but before Step 4's re-archive), which left gid=8 stuck in a "Working
  group"-typed state and corrupted the fixture for the NEXT run too (a
  compounding failure). This bug was masked before #128 because the
  pre-#128 seed (`status=0`) made `findLegacyInfrastructureGid()` fail at
  the very first step (element not found on `/all-groups`), so the suite
  never reached line 120 to expose it. #128 is what first makes this test
  run to completion — and in doing so, surfaces a defect #128 did not cause.
  **Fix:** removed the two redundant `getByText(/Archived/i)` assertions at
  Step 1 and Step 5 (each already followed by the correct, unambiguous
  `span.group__archived-badge` locator — the free-text check added nothing
  and duplicated an assertion, so it is deleted rather than reworded, per
  test-quality guidance against redundant assertions of the same behavior),
  and replaced the Step-3 `toHaveCount(0)` target with the same
  badge-scoped locator. Re-ran from a clean gid=8 state (Archive-typed,
  published): passes deterministically, 3 consecutive times. Spot-checked
  test validity: `ArchivePinHooks::preprocessGroup`
  (`do_chrome/src/Hook/ArchivePinHooks.php`) is real production logic that
  conditionally emits the badge based on `field_group_type` — the badge
  locator is a genuine behavior signal (it would fail if restore/re-archive
  logic broke), not a vacuous check.
- **Decided (environment hygiene, not code):** two full-suite Playwright runs
  during this verification session accumulated 32–34 unclosed e2e-fixture
  groups apiece (created as real side effects by unrelated specs —
  `phase1/2/3/4.spec.ts`, `manage-members.spec.ts`, etc. — none of which
  clean up after themselves; a pre-existing, cross-story characteristic of
  this suite, confirmed via `git log` showing these specs predate #128).
  Once total group count exceeded the `all_groups` View's 25-item pager,
  Legacy Infrastructure (gid=8, an early/low id) was pushed to page 2,
  making `demonstrator-seeds.spec.ts`/`directory-cards.spec.ts`/
  `group-restore.spec.ts` fail with "element not found" on `/all-groups` —
  NOT a #128 regression, but a session-local side effect of repeatedly
  re-running the full suite against one persistent DDEV DB without a reset
  between runs (a fresh CI checkout, which installs once per job, would not
  exhibit this). Deleted the accumulated fixture groups (gid > 8) each time
  this was hit, restoring the environment to the same 8-group state F/T-RED
  left it in. Also encountered, diagnosed, and cleaned two similarly
  session-local side effects: (1) the DDEV container stopped unexpectedly
  mid-session (Docker/DDEV resource event, unrelated to #128) — restarted
  cleanly, DB state intact, no code impact; (2) `membership-models.spec.ts`
  (#121, authored and finalized before #128, confirmed via `git log`) is
  itself NOT idempotent — its own assertions are "user joins group
  instantly" / "user requests to join", i.e., one-way state changes — so
  running the full suite twice in a row without a DB reset between runs
  will always show these 2 tests fail on the second pass, by design,
  regardless of #128. Removed the resulting leftover memberships
  (`sophie_mueller`→Drupal France, `ravi_patel`→Leadership Council) to
  restore a clean starting state. None of these three findings required a
  test or production-code change — advisory-only, noted in the handoff for
  awareness (repeated manual full-suite runs against one long-lived DDEV
  instance is not representative of CI, which seeds once per job).
- **Decided (Kernel + Functional smoke, env repair required):**
  `do_group_extras`/`do_chrome` Functional (`BrowserTestBase`) suites
  initially errored with "You must provide a SIMPLETEST_BASE_URL environment
  variable" — not a code or test defect, but a missing test-harness
  precondition never before exercised in this worktree's DDEV container
  (`.github/workflows/test.yml`'s Functional job runs on a SEPARATE
  throwaway MySQL DB + a bare `php -S` "test router" webserver on
  `127.0.0.1:8080`, distinct from the live `gm128-archive-demo.ddev.site`
  install). Reproduced the CI recipe inside the container: created a
  `drupal_functest` MySQL DB, appended a `hash_salt` override to the
  (gitignored, per-environment) `web/sites/default/settings.php` so the test
  router could bootstrap, and started
  `php -S 127.0.0.1:8080 -t web "$PWD/web/.ht.router.php"` — using an
  ABSOLUTE router path per the CI comment's explicit warning (a relative
  path resolves to the wrong `web/web/...` location and 500s every request,
  which is what my first attempt did, diagnosed via the router's own PHP
  error log rather than accepted as "env-blocked"). With
  `SIMPLETEST_DB`/`SIMPLETEST_BASE_URL`/`BROWSERTEST_OUTPUT_DIRECTORY`/
  `SYMFONY_DEPRECATIONS_HELPER` set to match CI exactly, all 38 tests across
  `do_group_extras` (Kernel: 12, Functional: 10) and `do_chrome` (Unit: 15,
  Functional: 1) passed — 0 failures, 0 errors; the only "issues" PHPUnit
  reported were 16 pre-existing Drupal-core deprecation notices
  (`cache.static`, `getOriginal()`, `plugin.manager.archiver`, etc.),
  identical in kind to what CI's own `SYMFONY_DEPRECATIONS_HELPER: disabled`
  exists to silence — unrelated to #128's 4-line seed-script diff. Stopped
  the router and reverted the `hash_salt` append after verification
  (gitignored file — never staged, live DDEV site unaffected, confirmed
  `curl` 200 on `/all-groups` post-revert).
- **Evidence:** final clean Tier-1 run (post-fix, post-cleanup, single pass):
  `demonstrator-seeds.spec.ts` (4) + `group-restore.spec.ts` (1) +
  `directory-cards.spec.ts` (3) = 8/8 passed, 21.5s. Full-suite run
  immediately after a fixture-group cleanup: 68/71 passed, 1 pre-existing
  skip (`manage-members.spec.ts`'s pending-request test, self-documented gap
  predating #128, confirmed via its own `test.skip()` message), 2 failures
  fully traced to #121's `membership-models.spec.ts` non-idempotency (not
  #128). Kernel+Functional: `Tests: 38, Assertions: 681, Failures: 0,
  Errors: 0` (`OK, but there were issues!` — 16 deprecations only).
  AC-3: `grep -n 'set("status", 0)' step_700_demo_data.php` → no match
  (exit 1). AC-4: re-ran `step_700_demo_data.php` on the already-seeded
  site — completed without error; gid=8 confirmed
  `status=1 type=Archive` both immediately before and immediately after the
  re-run (idempotent).

## Phase 8 (U): live browser walkthrough — PASS

- **Decided:** PASS, no REWORK. Drove the live gm128-archive-demo.ddev.site
  site headlessly with a standalone Playwright script (chromium) -- no
  u-drive.mjs helper exists in this repo (correctly; this is Drupal/DDEV,
  not the HTMX Language-Buddy stack the generic U contract targets, per the
  project override). Anonymous persona for AC-1a/AC-1b/AC-2, authenticated
  elena_garcia (seeded non-Organizer) for AC-1c, at desktop (1280x900) and
  mobile (360x800) viewports.
- **Confirmed via real DOM/visual rendering** (not just T's curl status
  codes): AC-1a's Archive type badge on the /all-groups card; AC-1b's
  span.group__archived-badge with visible "Archived" text and a truthy
  data-do-tooltip, confirmed via hover + focus; AC-1c's 403 "Access denied"
  render on /group/8 (Legacy Infrastructure) vs. 200 real content-create
  form render on /group/3 (Core Committers control), same user
  (elena_garcia), same route; AC-2's span.pin-badge "Pinned" + tooltip on
  /node/1 for anonymous.
- **Zero console errors** across all pages/contexts driven (the only
  console entry was the browser's own network-log echo of the expected 403
  response on AC-1c, not a script error).
- **A11y quick check (POC bar):** Archive badge has tabindex="0", reachable
  via natural keyboard Tab order (step 21 on /group/8), shows a visible
  default focus outline. Tooltip copy on both badges is non-empty and
  behaviorally meaningful. Card link accessible name on /all-groups is
  "Legacy Infrastructure" (real, descriptive). No obvious a11y regression --
  full axe scan deferred to S per the pipeline contract.
- **Reconfirmed advisory, not blocking:** the /all-groups directory card
  renders only the plain .gc-directory-card__type "Archive" label, no
  data-do-tooltip -- this is because the all_groups View renders rows via
  Views fields, which never invokes hook_preprocess_group (where the real
  badge+tooltip markup is attached). Pre-existing, out of #128's Files In
  Scope (brief's non-goals forbid template changes), already flagged
  identically by T-RED and T-green. Worth a follow-up ticket, not a #128
  blocker.
- **Evidence:** 7 screenshots (desktop + 360px) under
  docs/planning/handoffs/128-archive-demo/screenshots/; full per-AC
  action/expected/observed table in handoff-U.md.

## Phase 9 (S): spec audit — PASS

- **Decided:** PASS. All 6 acceptance criteria met with concrete evidence; scope
  discipline is exact (merge-base diff is 1 seed file + 3 test files + handoff
  docs/screenshots); test quality is high; T-green's in-band fix of the inherited
  #143 `group-restore.spec.ts` free-text-locator bug is genuine remediation, not
  scope creep. Diff-gate round-2 PASS is consistent with this audit. O may open
  the PR.
- **AC-1a downgrade defensibility.** The card lacks `data-do-tooltip`. This is
  a legitimate scope boundary, not a spec defect warranting ADVISORY-HOLD:
  (a) the brief's non-goals explicitly forbid theme/template/CSS changes;
  (b) the gap is architectural — `hook_preprocess_group` cannot fire in a
  Views-fields row render in Drupal 11 (framework fact, not per-build);
  (c) the visible "Archive" type-badge on the card preserves the
  discovery signal; (d) U hover-captured the full tooltip working on the
  group page (AC-1b) at both viewports. Updating the spec would not help —
  the fix requires a template change the story's non-goals correctly forbid
  given #128's seed-only mission.
- **Advisories (non-blocking follow-ups):**
  1. RUNBOOK.md doc drift (`:2638`, `:2800`) — belongs to #133.
  2. `.gc-directory-card` tooltip gap — worth a small follow-up ticket for
     the card component.
  3. E2E fixture hygiene (leaks across runs on a persistent DB) — worth a
     lightweight `afterEach`/global-teardown story.
- **Evidence read:** issue #128, brief.md, survey.md, handoff-A.md,
  handoff-T-red.md, diff-review-r1.md, diff-review-r1-response.md,
  diff-review-r2.md, handoff-F.md, handoff-T-green.md, handoff-U.md,
  `git diff 7dd8368..HEAD -- docs/ tests/`, `grep -n 'set("status", 0)'
  step_700_demo_data.php` (no match — AC-3 re-verified in this audit).
- **Parked-after-S for O.** Overnight-autonomous authorized per brief;
  merge on green CI: `gh pr merge <N> --repo Performant-Labs/groups-on-d11
  --merge --delete-branch`.
