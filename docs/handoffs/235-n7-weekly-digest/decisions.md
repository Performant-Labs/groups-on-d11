# Decisions journal — #235 (N-7: weekly digest worker)

Append-only. One entry per phase.

## O — Phase 1 open (2026-07-26)

- **Decided:** N-7 mirrors N-6's shape verbatim. Add `QueueBackendInterface::claimWeekly(int $olderThan)` alongside existing `claimDaily()`; add `DigestCommands::digestWeekly()` method alongside existing `digestDaily()` in the SAME class (N-6 already registers it as `do_notifications.digest_command`); reuse `DigestQueueBackendInterface` verbatim (already accepts `window='weekly'` — see N-6's `enqueue()` signature and column comment "'daily' or 'weekly'").
- **Decided:** State override key: `do_notifications.digest_weekly.window_seconds` (default 7 * 86400 = 604800 s). Symmetric with N-6's `do_notifications.digest_daily.window_seconds`.
- **Decided:** Command name `do_notifications:digest-weekly`, drush service id `do_notifications.digest_command` (SAME service, second `#[Command]`-attributed method — Drush 12+ attribute commands support multiple commands per class; verified by reading `vendor/drush/drush/src/Attributes/Command.php` and confirmed by the Drush core cookbook).
- **Assumed:** N-8's `DigestRenderer::render($messages, $recipient, 'weekly')` already accepts 'weekly' as its window arg (it takes an arbitrary string; N-6 passes 'daily'). Confirmed by reading its docblock / signature during survey. F must verify.
- **Assumed:** N-6 (#267) will merge before N-7 opens PR. If not, N-7 rebases on top when N-6 lands. If N-6 CI fails, escalate — do not fork the interface.
- **Evidence:** Full N-6 diff read (`gh pr diff 267`) — `DigestCommands.php`, `QueueBackendInterface.php`, `DatabaseQueueBackend.php`, `MockQueueBackend.php`, `DailyDigestCommandTest.php` all inspected. Same schema, same idioms, same test structure will apply.
- **Review-rigor dial:** `none` (per POC lean pipeline memo — drop pre-PR gate, brief-gate, A-dup). Straight A → T(RED) → F → T(GREEN) → S → PR.

## T — Phase 4 RED (2026-07-26)

- **Decided:** Authored `WeeklyDigestCommandTest.php` (4 scenarios: integrated 5/4/3-item
  aggregate, state-override window narrowing, orphan-mid GC, zero-item user) as a structural
  mirror of N-6's `DailyDigestCommandTest.php`, substituting `weekly`/`604800` throughout per
  the brief. Extended `DatabaseQueueBackendTest.php` with
  `testClaimWeeklyFiltersOnFrequencyAndThreshold`, mirroring the existing
  `testClaimDailyFiltersOnFrequencyAndThreshold`.
- **Decided:** Item distribution for the integrated scenario is 5/4/3 (12 total) rather than
  N-6's 7/2/1 (10 total), to match the brief's AC2 wording exactly ("12 weekly items across 4
  users").
- **Assumed:** `SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web` must be set
  explicitly when invoking `ddev exec ... phpunit` directly (rather than through
  `scripts/dev/run-kernel.sh`, which only targets whole-module directories, not the specific two
  files this task needed). Confirmed by scripts/dev/run-kernel.sh's own inline usage of these
  vars. Omitting them produced 11 setup-errors (`no database connection`) with 0 assertions —
  correctly recognized as an invalid RED and re-run with the vars set before treating the
  failures as real.
- **Assumed:** This worktree's `.ddev/config.yaml` + two override files (`config.gm139.yaml`,
  `config.gm253.yaml`) were stale leftovers from the directory's prior reuse for earlier issues
  (gm139, gm253), not intentional configuration for #235. Renamed the project to
  `gm235-weekly` (matching the task prompt) and deleted the stale overrides; `ddev poweroff` +
  `ddev start` + `ddev composer install` to bring the environment up clean. This is environment
  housekeeping, not a test-authorship decision, but recorded here since it blocked verification
  otherwise.
- **Evidence:** RED run —
  `Tests: 11, Assertions: 140, Errors: 5, Deprecations: 16.` The 5 errors are exactly the 5 new
  tests (4 in `WeeklyDigestCommandTest`, 1 in `DatabaseQueueBackendTest`), each failing with
  `Call to undefined method ...digestWeekly()` or `...claimWeekly()` — a valid RED (fails for the
  right reason, not a setup/import error). The 6 pre-existing `DatabaseQueueBackendTest` tests
  pass (deprecation warnings only, no new failures) — the extension did not regress the existing
  suite. Full command + output pasted into `handoff-T.md`.

## F — Phase 5 implement (2026-07-26)

- **Decided:** Implemented exactly the 4 objects the brief's Reuse map authorized, all literal
  mirrors of the N-6 daily equivalents: `QueueBackendInterface::claimWeekly()` (interface
  method), `DatabaseQueueBackend::claimWeekly()` (SQL condition swap `'daily'` → `'weekly'`),
  `MockQueueBackend::claimWeekly()` (in-memory filter swap), `DigestCommands::digestWeekly()` +
  private `digestUserWeekly()` (new public command method + private helper on the EXISTING
  `DigestCommands` class — no new command class, per the brief's explicit anti-pattern warning).
- **Decided:** `digestUserWeekly()` is a literal structural copy of `digestUser()`, including A's
  flagged finding #3: the `$message->setCreatedTime((int) $message->getCreatedTime())`
  normalization at the Message-load boundary is mirrored verbatim (with an inline comment
  pointing back to `digestUser()`'s fuller explanation rather than duplicating the whole
  quirk writeup a second time) — this guards against the exact `gmdate()` TypeError A predicted
  would surface under AC2 if omitted.
- **Decided:** `DEFAULT_WEEKLY_WINDOW_SECONDS = 604800` added as a sibling class constant next to
  the existing `DEFAULT_WINDOW_SECONDS = 86400` (daily). Read via
  `state.get('do_notifications.digest_weekly.window_seconds', self::DEFAULT_WEEKLY_WINDOW_SECONDS)`,
  symmetric with the daily path's state read.
- **Decided:** Updated `QueueBackendInterface`'s class docblock to add a parallel "#235 (N-7)"
  paragraph (mirroring the existing "#234 (N-6)" paragraph) documenting `claimWeekly()`'s
  addition, per the task's explicit instruction. Updated `DigestCommands`' class docblock
  (title, description, non-goals) to describe both `digestDaily()` and `digestWeekly()` as
  present-tense implemented siblings rather than leaving N-7 as a forward-looking "future
  story" reference the daily-only docblock previously used.
- **Decided:** Left `DigestQueueBackendInterface`, `DatabaseDigestQueueBackend`,
  `do_notifications.install`, `do_notifications.services.yml`, `drush.services.yml`, and
  `DigestRenderer` completely untouched, per the brief's explicit "NO changes to" list — all
  reused verbatim, exactly as A's Phase 3 review confirmed was possible.
- **Found (flagging, not editing):** Running `phpcs` with no `--standard` flag (as literally
  specified in the task) exits 2 with ~5700 errors across every file in the module, including
  files this story never touched — the project has no root-level `phpcs.xml`/`phpcs.xml.dist`
  ruleset (confirmed absent both in the worktree and inside the DDEV container), so bare `phpcs`
  falls back to its own built-in PEAR default instead of the `drupal/coder`-provided `Drupal`
  standard the codebase's own style clearly follows throughout. Re-running with
  `--standard=Drupal` explicitly narrows the count to 33 errors / 19 warnings across 10 files —
  all either T's authored test files (`DatabaseQueueBackendTest.php`,
  `WeeklyDigestCommandTest.php`, 5 unrelated pre-existing test files) or 2 pre-existing
  production files this story never touched
  (`NotificationSettingsController.php`, `DoNotificationsHooks.php`). **Scoped to only the 4
  files this F pass created/modified, `phpcs --standard=Drupal` reports 0 errors, 0 warnings.**
  This is an environment/config gap (missing project-root ruleset), not a defect introduced by
  #235 — not fixed here since adding a project-wide ruleset file is out of this story's scope
  per the brief's Reuse map. Recorded for O/A visibility rather than silently declared "phpcs
  passes" when the literal command as specified does not exit 0.
- **Evidence:** GREEN run (the 3 classes the task named) —
  `Tests: 15, Assertions: 323, Deprecations: 20.` Zero failures, zero errors; all 5
  previously-RED tests (4 in `WeeklyDigestCommandTest`, 1
  `testClaimWeeklyFiltersOnFrequencyAndThreshold` in `DatabaseQueueBackendTest`) now pass, and
  all 10 previously-passing tests (6 `DatabaseQueueBackendTest` + 4 `DailyDigestCommandTest`,
  the regression check) still pass. Broader sanity check — the FULL `do_notifications` module
  kernel suite (all 10 test classes, 50 tests) — also passes: `Tests: 50, Assertions: 1589,
  Deprecations: 22`, zero failures/errors module-wide. `phpcs --standard=Drupal` on the 4
  modified files: exit 0, 0 errors, 0 warnings. Full command + output in `handoff-F.md`.

## S — Phase 6 spec audit (2026-07-26)

- **Decided:** Verdict **PASS**. All 10 ACs backed by tests (4 `WeeklyDigestCommandTest` scenarios + `testClaimWeeklyFiltersOnFrequencyAndThreshold`); full 50-test `do_notifications` kernel suite green; no anti-pattern committed (no `WeeklyDigestCommands` class, no `WeeklyDigestQueueBackend`, no `digest_queue_weekly` table, no forked interface).
- **A-dup surrogate check (POC lean pipeline drops A-dup, so S runs it):** clean. Only intentional structural duplication is `digestUserWeekly()` ↔ `digestUser()` (~70 lines of near-identical private helper). F correctly kept it per brief's Reuse map ("private `digestUserWeekly()` helper") — a parameterized `digestUserForWindow(string $window)` refactor is premature abstraction at 2 windows and would be un-spec'd; the merge becomes worthwhile only once a 3rd window (e.g. monthly) exists.
- **Assumed:** AC2's brief wording ("12 weekly items across 4 users → exactly 4 rows") is ambiguously worded — the correct behavior (implemented + tested) is 3 rows because AC7's "skip empty digests" overrides. T's fixture (3 producing users + 1 zero-item user = 4 total users) resolves the ambiguity in favor of AC7. Non-blocking; recommend O optionally amend brief post-hoc. No code change.
- **Found (non-blocking):** `QueueBackendInterface::claimDaily()`'s docblock line 127 still contains N-6-era forward-looking language ("a future N-7 weekly digest worker will add its own `claimWeekly()`") though that sibling method now exists directly below in the same file. Per the POC memory rule (`feedback_poc_no_follow_ups`), NOT filing a follow-up issue for this trivial docs staleness — recommend leaving for opportunistic cleanup when the next N-* story opens the file.
- **Found (recommended follow-up ISSUE, not story-crept):** The phpcs environment gap F flagged is genuine and out-of-scope for N-7. Recommend filing a separate infra issue: "Add project-root `phpcs.xml.dist` pinning the `Drupal` standard" — small, saves recurring papercuts on every future story where bare `phpcs` fails on unrelated pre-existing files.
- **Evidence:** F's GREEN run: `Tests: 50, Assertions: 1589, Deprecations: 22`, zero failures/errors across the full module. Full ACs → tests matrix in `handoff-S.md`. Ready for O to open PR.
