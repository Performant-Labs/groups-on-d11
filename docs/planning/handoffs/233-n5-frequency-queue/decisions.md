# Decision journal — #233 N-5 frequency queue

Format: append-only. Each phase adds one entry with Decided / Assumed / Hedged / Evidence.

---

## Phase 1 — O (survey + brief), 2026-07-26

**Decided:**
- Add one new service `FrequencyResolver` (not a general-purpose scheduler) — single responsibility.
- Reuse existing `field_notification_frequency` on user; do not create a new field.
- Extend `SubscriptionRouter` (per Reuse map) — inject `FrequencyResolver`, keep everything else.
- Extend `QueueBackendInterface` payload with optional `send_at` key; interface signature unchanged.
- No coordination handshake with N-1 needed — N-1 is not on origin yet. If N-1 lands first, we
  rebase; if we land first, N-1 inherits the payload contract.

**Assumed:**
- Site-wide `default_frequency = immediately` is the intended fallback for users with an empty
  field. Matches the field's own default and today's router behavior.
- `send_at` semantics: "strictly greater than now" for `daily`/`weekly` (a worker running at
  exactly 02:00 UTC picks up today's batch enqueued before 02:00, not the one enqueued at 02:00).

**Hedged:**
- N-1's DB schema will need `frequency` + `send_at` columns + index. Not enforced here (no
  DatabaseQueueBackend to modify yet); documented as contract in the brief so N-1 inherits it.

**Evidence:**
- `SubscriptionRouter.php:135` reads settings.default_frequency for every enqueue — the exact
  extension point.
- `field.storage.user.field_notification_frequency.yml` allowed values match spec
  (`immediately|daily|weekly`), default `immediately`.
- `origin/229-n1-queue-foundation` does not exist; only local branch `229-n1-queue-foundation`
  exists on this machine (not our race concern).

---

## Phase 3 — A up-front plan review, 2026-07-26

**Decided:** PASS. Two warn-level findings absorbed as micro-updates for F, not brief amendments:
- FrequencyResolver bad-user-load path: silent swallow (no logger dep).
- `QueueBackendInterface` docblock: F adds explicit note that `send_at` is NOT part of the
  `(uid, mid, frequency, day)` dedup tuple.

**Assumed:** none new.

**Hedged:** none.

**Evidence:** handoff-A.md.

---

## Phase 4 — T (author RED), 2026-07-26

**Decided:**
- One kernel test file, `FrequencyRoutingTest.php`, scoped purely to the frequency/send_at
  contract (5 tests, one Message per test) — does not duplicate `SubscriptionRouterTest`'s
  dedup/suppression/author-exclusion coverage.
- `send_at` expectations are recomputed independently in the test (`expectedSendAt()` helper),
  never delegated to `FrequencyResolver`'s own math, so the suite pins the CONTRACT rather than
  mirroring the SUT's implementation.
- Field fixture install pattern mirrors the existing flag-fixture pattern (module-local
  `tests/fixtures/config/` YAMLs, load-or-create in `setUp()`), per the harness convention already
  established in `SubscriptionRouterTest`.

**Assumed:** none new beyond Phase 1/3.

**Hedged:** none.

**Evidence:**
- RED run: 5 failures / 0 errors, each on the specific frequency/send_at assertion (see
  `handoff-T-red.md` for full output). Baseline deprecation count cross-checked against
  `SubscriptionRouterTest` (13 vs our 14 — one extra from `$user->set()->save()` reload, harmless).
- PHPCS clean on the new test file.
- Load-bearing setup gotcha found and fixed: `FieldStorageConfig::create()` needs
  `allowed_values` in RUNTIME shape, not the on-disk config-export shape the shipped YAML uses —
  see handoff-T-red.md "Setup surprises" for the full mechanism and fix.

---

## Phase 5 — F (implement GREEN), 2026-07-26

**Decided:**
- `FrequencyResolver::computeSendAt()` uses `match($frequency)` with `default => $now` — an
  unknown/malformed frequency value (not `'daily'`/`'weekly'`) is treated as `'immediately'`
  timing (defensive, per the brief), without a separate explicit branch for `'immediately'`.
- Weekly boundary computed via "this week's Sunday 19:00 UTC minus day-of-week offset, roll
  forward 7 days if not strictly after now" rather than the brief's literal "add
  `days_until_sunday` forward" framing — chose the algebraically simpler subtraction form.
  Verified bit-for-bit equivalent to the test's independently-reimplemented `expectedSendAt()`
  contract across 192 synthetic checks spanning all 7 days-of-week and 9 times-of-day per day,
  including exact-instant boundary checks at 18:59:59 / 19:00:00 / 19:00:01 UTC on a Sunday (see
  Evidence). Daily boundary math is the same shape as the brief verbatim; also cross-checked (35
  checks, 0 mismatches).
- `configFactory` WAS removed from `SubscriptionRouter`'s constructor and from its services.yml
  args. Confirmed via grep it was used in exactly the one line I deleted (the single-shot
  `$frequency` read) — no other reference in the class. Kept `FrequencyResolver` reading
  `ConfigFactoryInterface` itself (per brief's explicit 3-arg constructor spec), since it now owns
  the site-default-frequency read the router used to do.
- No logger injected into `FrequencyResolver` (handoff-A finding #1: silent swallow). Both the
  bad-user-load path and the "field missing/empty" path collapse into the same
  `resolveFrequency()` → `siteDefaultFrequency()` fallback, since both are "we don't know this
  user's preference" cases with identical resolution.
- `QueueBackendInterface` docblock: added the `send_at` key description directly under the
  existing bullet list (mirroring the existing `day` bullet's style) rather than as a separate
  paragraph, and added the explicit "NOT part of the ... dedup tuple" sentence per handoff-A
  finding #2, inline in that same bullet (kept the two facts co-located since they're both about
  the same key).
- Did not add a `FrequencyResolver::DEFAULT_FREQUENCY` etc. constants to `SubscriptionRouter` —
  those constants live in `FrequencyResolver` only, since the router no longer computes frequency
  at all (matches the "extend, don't duplicate" instruction: the frequency policy is now fully
  owned by one object).

**Assumed:** none new beyond Phase 1/3/4.

**Hedged:** none — both the weekly/daily boundary formulas were empirically verified against
the test's independent implementation (not asserted from reasoning alone) before running the real
suite, given they are the crux of the story's numeric contract.

**Evidence:**
- GREEN run: `FrequencyRoutingTest` 5/5 passing, `Tests: 5, Assertions: 232, Deprecations: 14,
  Failures: 0, Errors: 0`.
- Regression run: full `do_notifications` Kernel suite (`EmailRendererTest`,
  `FrequencyRoutingTest`, `GroupAddNotificationTest`, `SubscriptionRouterTest`) — `Tests: 18,
  Assertions: 788, Deprecations: 19, Failures: 0, Errors: 0`. Deprecation count matches the
  additive baseline (13 from `SubscriptionRouterTest` + 1 extra from `FrequencyRoutingTest`'s
  reload cycle, as T's handoff predicted, + pre-existing `EmailRendererTest` deprecations).
- PHPCS: 0 errors on all 4 touched/new files. `SubscriptionRouter.php` carries exactly 1
  pre-existing `DrupalPractice` warning (line 69, `\Drupal::logger(...)` inside `route()`'s
  catch-all) — confirmed via direct comparison against the `origin/main` baseline copy of the
  same file (placed at the real registered path, since PHPCS's namespace-aware sniff silently
  skips analysis on files dropped at an arbitrary un-registered path — a scratch-test false
  negative caught and corrected before drawing the conclusion): identical warning fires on the
  untouched baseline at the same source line (line 63 pre-change, shifted only by added
  docblock/comment lines). This warning existed before #233 and is on code this story did not
  modify.
- Boundary-math equivalence: ad-hoc PHP script run inside the DDEV container (not committed —
  scratch-only) comparing `FrequencyResolver`'s `nextWeeklyBoundary()`/`nextDailyBoundary()`
  arithmetic against the test file's independently-written `expectedSendAt()` helper across a
  3-week synthetic date range at 9 times-of-day plus 3 explicit exact-instant boundary checks:
  0 mismatches out of 192 weekly + 35 daily checks.

## Phase 6 (T-green) — 2026-07-26

**Decided:** GREEN verified independently. Re-ran the full N-5 suite, the full do_notifications
Kernel regression, and — beyond F's own report — the do_activity Kernel suite as the Tier-2
blast-radius check for SubscriptionRouter's constructor-signature change (do_activity injects the
router). All three suites: 0 Failures, 0 Errors. Whole-module PHPCS run (not just F's touched
files) surfaced pre-existing debt in 4 unrelated files (NotificationSettingsController,
DoNotificationsHooks, GroupAddNotificationTest, EmailRendererTest) — confirmed via `git diff
origin/main` that all four have zero diff in this branch, i.e. none of it is new. No new PHPCS
issues anywhere in scope.

**Assumed:** none beyond Phase 4.

**Hedged:** did not re-run F's ad-hoc weekly-boundary-equivalence script myself; treated the test
suite's own independently-written `expectedSendAt()` helper (which uses the brief's literal
"days-until-Sunday" framing, distinct from F's "subtract day-of-week" implementation) passing
against F's code as sufficient independent confirmation for the cases this suite exercises.

**Evidence:**
- N-5 suite (fresh re-run, own assemble): `Tests: 5, Assertions: 232, Deprecations: 14, Failures:
  0, Errors: 0` — identical to F's reported figures.
- do_notifications full Kernel regression: `Tests: 18, Assertions: 788, Deprecations: 19,
  Failures: 0, Errors: 0` — identical to F's reported figures.
- do_activity Kernel regression (new check, not in F's report): `Tests: 23, Assertions: 759,
  Deprecations: 14, Failures: 0, Errors: 0` — no regression from the router's dependency swap.
- PHPCS whole-module run: 0 new errors/warnings in any file touched or added by #233; pre-existing
  warning on SubscriptionRouter.php:69 confirmed unchanged; unrelated pre-existing debt in 4 other
  files confirmed out-of-scope via empty `git diff origin/main`.
- Contract-drift spot check: SubscriptionRouter constructor = 6 args, no configFactory anywhere,
  services.yml arg order/count matches. FrequencyResolver's own 3-arg constructor (including
  configFactory) is correct per brief design — it now owns the site-default read.
- PHPStan: no config found (`phpstan.neon` absent repo-wide) — skipped, not a gap introduced by
  this story.

Full detail: `handoff-T-green.md`.

---

## Phase 10 — O post-merge sweep BLOCKED on CI infra, 2026-07-26

**Decided:** Do NOT self-merge. Widened autonomy grants self-merge on CI-green + mergeable —
CI is red, so the precondition is not satisfied. Handing back to operator.

**Assumed:** CI red is external / systemic, not a code-level regression. Evidence: same failure
signature on 4 unrelated PRs (#233 mine, #252 header-search, #229 N-1, #236 N-8) starting at
~2026-07-26T23:17:23Z. Root cause is `harbor.performantlabs.com` Docker registry rejecting the
runner's login credential — every job fails in "Initialize containers" step before any test code
runs. Retrying #233's failed jobs reproduced the identical failure.

**Hedged:** None. The story code is proven green locally (46 tests: 5 N-5 + 18 module + 23
do_activity regression) and S PASS is on record. If/when the harbor auth is restored, this PR
should sail through with no code changes needed.

**Evidence:**
- PR https://github.com/Performant-Labs/groups-on-d11/pull/261
- Failing run https://github.com/Performant-Labs/groups-on-d11/actions/runs/30226146847
- Log excerpt: `##[error]Docker login for 'harbor.performantlabs.com' failed with exit code 1`
  (3 backoff retries all failed) → `##[error]Value cannot be null. (Parameter 'ContainerId')`.

**Follow-up:** operator to investigate `harbor.performantlabs.com` service health / rotate
runner credential (`HARBOR_USER`/`HARBOR_PASS` secrets in repo settings, or similar). Same fix
unblocks #252, #229, #236, and any other PR opened after 23:17 UTC on 2026-07-26.

## Chain summary (delivery blocked at PR gate)

**Outcome:** Story implementation is complete and locally proven; PR #261 is open but CANNOT
self-merge because the shared CI infrastructure (harbor.performantlabs.com Docker registry) has
been rejecting all runner logins since ~23:17 UTC on 2026-07-26, affecting every open PR in the
repo, not just this one.

**Key decisions:**
- FrequencyResolver as a new service (justified — reused by N-6/N-7 schedulers, isolates time-math).
- Extended existing `SubscriptionRouter` (per Reuse map) rather than forking.
- `send_at` added as OPTIONAL, non-dedup payload key (safe backward-compat for MockQueueBackend
  and future N-1 DatabaseQueueBackend).
- Silent swallow (no logger dep) on FrequencyResolver's fail-soft user-load path.
- `configFactory` removed from `SubscriptionRouter` (moved to resolver where it belongs).
- Weekly boundary formula algebraically simplified but bit-equivalent to brief (verified with
  synthetic checks; documented in F's Phase 5 entry).

**Open assumptions still unverified:**
- Downstream N-1 schema will honor the payload contract (`frequency VARCHAR NOT NULL DEFAULT
  'immediately'`, `send_at INT UNSIGNED NULL`, composite index on `(frequency, send_at)`) —
  documented in the brief; N-1 rebase will inherit.
- Users changing frequency mid-day get a NEW queue entry rather than a mutation to the existing
  one (`frequency` is in the dedup key). Documented as acceptable in the brief; no test yet
  exercises "user flips preference between two events" — a candidate follow-up if the epic
  finds that edge case surfaces in dogfooding.

**Follow-ups filed:** None from the code work. The CI infra red is a **repo-wide operational
issue** — operator to triage the harbor registry credentials outside of this story's scope.
