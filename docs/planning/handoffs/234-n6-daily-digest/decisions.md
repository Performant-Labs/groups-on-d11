# Decisions — #234 N-6 Daily digest worker

Append-only. One entry per phase completion.

## Phase 1 — O (brief)

**Decided:**
- Base branch = origin/main @ 7d3326a (per operator prompt; do not stack on #261).
- Storage for aggregated digest = NEW `do_notifications_digest_queue` table (not a schema mutation of `do_notifications_queue`). Rationale: per-message queue's `(uid, mid, frequency, day)` dedup contract has no `mid` for an aggregated row; N-5 PR #261 is currently modifying `do_notifications_queue`, so a parallel edit courts merge conflict.
- Query filter = `frequency='daily' AND created < now - windowSeconds`. Not `send_at` (that column doesn't exist yet; N-5 documents it but hasn't added it).
- Window = state-configurable (`window_seconds`, default 86400) so kernel test can compress it.
- Cron hour = state-configurable (`hour_utc`, default 2), informational only in the command (cron wiring = ops).
- Extend `QueueBackendInterface` with `claimDaily()` + `deleteByIds()` rather than raw SQL in the drush command (keeps DB knowledge in the backend).

**Assumed (needs A to confirm):**
- Drush 12+ attribute-based command class is the right pattern (module has no prior drush commands).
- Message entities can be `loadMultiple`d by mid in a kernel test without additional test scaffolding beyond what `DigestRendererTest` already sets up.
- N-3 will be extended to consume `do_notifications_digest_queue` OR N-3 will be re-scoped to include this table when it lands. Either is fine for N-6 (this story just enqueues).

**Hedged:**
- Command result API (return array vs. log-only). T-red locks the exact surface.

**Evidence:**
- Survey confirmed no existing drush command in module (`ls src/Commands` = empty).
- Confirmed N-5 PR #261 still OPEN (`gh pr view 261`), N-3 issue OPEN (`gh issue view 231`).
- Confirmed shipped N-1 install schema has no `send_at` / no `payload` columns.

**Open assumptions still unverified:** A's approval of the "new table + new interface" design vs. an alternative (e.g. render-at-delivery).

**Follow-ups filed:** none yet.

## Phase 3 — A (up-front plan review)

**Verdict:** PASS (3 `warn` findings, 0 `block`).

**Confirmed:**
- New-table + narrow `DigestQueueBackendInterface` is the correct call — mirrors the N-1 narrow-interface + Database/Mock pair pattern; the digest row's contract genuinely differs from the per-message queue (no `mid`, carries rendered body); also avoids conflict with #261.
- Split `claimDaily` + `deleteByIds` (over a single `claimAndDelete`) is the right shape — preserves mid-run idempotency and gives N-7 a parallel `claimWeekly` seat.
- `DigestRenderer` reuse is verbatim, no duplication.

**Warnings for F to address inline:**
1. Rename `hook_update_9001()` → `do_notifications_update_11001()` (D11 numbering).
2. Register `DigestCommands` in `drush.services.yml` only, not also in `do_notifications.services.yml`.
3. Consider swapping the digest interface's `drain()` for `all()` + `deleteByIds()` to match the same claim-then-delete pattern the brief adopts for the per-message queue (optional; T-red can lock).

**Also flagged for T-red to lock:**
- Behavior when a queued row's Message entity is missing at load time (recommend: drop from digest AND delete the queue row to garbage-collect).
- AC8 exact summary surface (already hedged in brief).

**Evidence:** read all files O provided plus GH issue #234; confirmed neighboring patterns in `do_notifications.services.yml`, `QueueBackendInterface`, `DatabaseQueueBackend`, `MockQueueBackend`, `DigestRenderer`, and `do_notifications.install` (no prior update hooks — #11001 is the first).

**Handoff:** `handoff-A.md`.

## Phase 4 — T-red (author tests, RED)

**Decided:**
- Authored 3 test files: `DailyDigestCommandTest.php` (NEW, 4 tests), `DatabaseQueueBackendTest.php` (EXTENDED, +4 tests for `claimDaily`/`deleteByIds`, 2 pre-existing tests untouched), `DigestQueueBackendTest.php` (NEW, 4 tests for `DigestQueueBackendInterface`/`DatabaseDigestQueueBackend`).
- Locked A's finding #3: digest queue interface uses `all()` (read-only) + `deleteByIds()`, no `drain()`.
- Locked the missing-Message behavior per A's recommended option (a): drop from digest AND delete the queue row (garbage-collect), tested explicitly in `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages`.
- Command invoked directly (`\Drupal::service('do_notifications.digest_command')->digestDaily()`), not shelled out via drush, per task prompt's kernel-test guidance.
- No `TestTime` seam exists in this codebase (confirmed via grep) — used raw DB inserts with explicit `created` timestamps to backdate queue rows deterministically, and `\Drupal::state()->set(...)` to narrow the window live in `testWindowSecondsStateOverrideNarrowsWindow`.

**Assumed (needs F to confirm/implement against):**
- `digestDaily()` method name on the command service (not spelled out in the brief beyond the drush command id `do_notifications:digest-daily`).
- `DigestQueueBackendInterface::enqueue()` returns `int` (row id) per the brief's exact signature.
- Digest row's `all()` return shape includes `id`, `uid`, `window`, `subject`, `body_text`, `body_html`, `send_at` as array keys.

**Hedged:**
- AC8's exact summary array keys (`users_digested`, `items_consumed`, `digests_enqueued`) — locked as tested; F must match exactly or T-green fails.

**Evidence:**
- RED run: `Tests: 14, Assertions: 9, Errors: 12, Deprecations: 14.` — 12 tests fail for the right reason (`LogicException: ... does not define a schema for table 'do_notifications_digest_queue'` or `Error: Call to undefined method ...::claimDaily()/deleteByIds()`); 2 pre-existing tests untouched and still pass.
- Full RED output + run command in `handoff-T-red.md`.

**Follow-ups filed:** none (environment fix — stale `.ddev/config.gm253.yaml`/`config.gm139.yaml` override files removed and `ddev config --project-name gm234-daily` re-run — noted in handoff-T-red.md for F/T-green's benefit, not filed as a GH issue per POC no-follow-ups rule).

## Phase 5 — F (implement, GREEN)

**Decided:**
- Implemented all 9 production files per the brief's file list: schema + update hook
  (`do_notifications.install`), 2-method interface extension + Database/Mock implementations
  (`QueueBackendInterface`/`DatabaseQueueBackend`/`MockQueueBackend`), new digest-queue interface +
  DB backend (`DigestQueueBackendInterface`/`DatabaseDigestQueueBackend`), the command class
  (`DigestCommands`), and DI wiring (`do_notifications.services.yml` + new `drush.services.yml`).
- Honored A's warn #1 verbatim: `do_notifications_update_11001()` (D11 numbering, not 9001).
- Honored A's warn #3 verbatim: `DigestQueueBackendInterface` uses `all()` + `deleteByIds()`, no
  `drain()`.
- **Deviated from A's warn #2 / the task prompt's literal instruction**, with justification (see
  "Deviations" below): registered `DigestCommands` in BOTH `do_notifications.services.yml` (as
  `do_notifications.digest_command`) AND `drush.services.yml` (tagged `drush.command`), not
  `drush.services.yml` ONLY.
- Missing-Message (orphan) behavior implemented exactly as T-red locked: drop from digest AND
  delete the queue row (garbage-collect), tested by `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages`
  — GREEN.
- `items_consumed` = count of ALL originally-claimed rows deleted, including orphans (per the
  brief's explicit clarification in this phase's task prompt); `users_digested`/`digests_enqueued`
  only count users who actually got a rendered digest row.

**Deviations (both discovered via source-level verification, not preference):**
1. **`DigestCommands` registered in `do_notifications.services.yml` (not `drush.services.yml`
   ONLY).** Read `vendor/drush/drush/src/Runtime/LegacyServiceInstantiator.php` and
   `DrupalBoot8.php` directly: `drush.services.yml` populates a Drush-internal registry
   (`$instantiatedDrushServices`) that Drush's own `DrupalBoot8::bootstrap()` reads via
   `taggedServices('drush.command')` — this registry is NEVER merged into `\Drupal::getContainer()`.
   A service registered only there is invisible to `\Drupal::service()`, which is exactly how
   T-red's locked test (`DailyDigestCommandTest::command()`) resolves the command — confirmed
   empirically: with the service registered ONLY in `drush.services.yml`, all 4
   `DailyDigestCommandTest` tests failed with `ServiceNotFoundException`. T-red's own claim in
   handoff-T-red.md ("this works identically regardless of which services.yml file registers it")
   is factually incorrect for this mechanism. Registered in BOTH files: `do_notifications.services.yml`
   (container-resolvable, satisfies the test and any future non-CLI caller) AND `drush.services.yml`
   (tagged `drush.command`, satisfies real `drush do_notifications:digest-daily` CLI discovery,
   which ALSO only scans `drush.services.yml`-sourced services — confirmed the same way). Drush's
   legacy instantiator constructs its own separate, stateless instance from `drush.services.yml`
   purely for CLI dispatch; this is an accepted artifact of the deprecated-but-functional legacy
   mechanism, not a functional duplication (only one instance is ever invoked per process).
2. **`Message::setCreatedTime((int) $message->getCreatedTime())` normalization in
   `DigestCommands::digestUser()`** before handing a freshly `loadMultiple()`-loaded Message to
   `DigestRenderer::render()`. A real, pre-existing quirk of core's `timestamp` field TypedData:
   `EmailRenderer::renderEventFragments()` passes `$message->getCreatedTime()` straight into
   `gmdate()` (requires `?int`), but a Message reloaded fresh from the DB can surface `created`'s
   `->value` as a numeric STRING (confirmed by reading
   `web/core/lib/Drupal/Core/Field/Plugin/Field/FieldType/TimestampItem.php` — the `'timestamp'`
   DataDefinition has no int-cast override). Every pre-existing `DigestRendererTest`/
   `EmailRendererTest` fixture only ever passes the SAME in-memory Message object right after
   `->save()` (never reloaded), so this was never exercised before this story — my `DigestCommands`
   is the first caller to hand the renderer a genuinely storage-reloaded Message. Fixed at the
   boundary this story owns (a one-line, in-memory-only, never-persisted normalization on the
   loaded object) rather than editing the shipped N-4/N-8 `EmailRenderer`/`DigestRenderer` classes
   (out of this story's scope; brief says "REUSE VERBATIM").

**Hedged:** none — both deviations above are backed by direct source-code verification (Drush's own
`LegacyServiceInstantiator.php`/`DrupalBoot8.php`; core's `TimestampItem.php`), not assumption.

**Evidence:**
- Target suite GREEN, 3x stable: `Tests: 14, Assertions: 190, Deprecations: 20. OK, but there were
  issues!` (0 errors, 0 failures every run).
- Full `do_notifications` kernel regression GREEN: `Tests: 35, Assertions: 1028, Deprecations: 21.
  OK, but there were issues!` (0 errors, 0 failures — every pre-existing suite, including
  `SubscriptionRouterTest` which N-1's own handoff-F had flagged as needing a schema-install fix,
  passes; that gap has already been fixed upstream of this story).
- phpcs (`--standard=Drupal,DrupalPractice`) on all 9 production files: exit 0, zero findings.
  Bare `vendor/bin/phpcs` (no `--standard` flag, as the task prompt literally specified) falls back
  to the PEAR standard (confirmed via `phpcs --config-show`: no `default_standard` configured,
  matching the prior #229 handoff-F's identical finding) and produces irrelevant noise
  (`@category`/`@package`/`@author` tag requirements) — not a signal on my files specifically, but
  the correct standard flag is required for a meaningful run, exactly as #229's own handoff-F
  documented.

**Follow-ups filed:** none (POC lean pipeline; no GH issues for latent debt found in other files —
the `NotificationSettingsController.php`/`DoNotificationsHooks.php` phpcs findings and the minor
unused-helper lint warning in T's own `DailyDigestCommandTest.php` are pre-existing/T-owned and
out of this story's scope, noted in `handoff-F.md` for visibility only).

## Phase 9 — S (spec audit)

**Verdict:** PASS with one mandatory pre-PR mechanical action (stage the 3 T-authored test files before `gh pr create`).

**Confirmed:**
- AC1–AC10 all backed by passing tests (12 story-specific tests: 4 DailyDigest, 4 DigestQueueBackend, 4 DatabaseQueueBackend extensions).
- Issue #234 alignment complete: nightly cron deferred to ops (documented), UTC-only, 24h window state-configurable, per-user grouping via `orderBy(uid)` + PHP grouping, 50-cap delegated verbatim to N-8 DigestRenderer, skip-empty guard, source-row deletion.
- A's 3 warns: #1 honored (`_11001`), #3 honored (`all()` + `deleteByIds()`, no `drain()`), #2 justifiably deviated with evidence — F's rationale verified by re-reading Drush's `LegacyServiceInstantiator` semantics and confirmed the registered-in-both-files pattern is correct (kernel-test resolvability + CLI discoverability both required).
- F's second deviation (`Message::setCreatedTime((int) getCreatedTime())` normalization): ACCEPTABLE for this PR. Verified `EmailRenderer::renderEventFragments()` line 202 + `DigestRenderer::render()` line 174 `gmdate()` call constitute a real string-vs-int seam that N-6 is genuinely the first caller to exercise. Upstream fix is optional; POC no-follow-ups applies.
- Anti-duplication: no hidden parallel path. `DigestRenderer` reused verbatim, `QueueBackendInterface` extended in place, `MockQueueBackend` kept in sync.
- Architecture: `DigestQueueBackendInterface` mirrors `QueueBackendInterface` conventions (naming, docblocks, method shape, readonly constructor promotion, empty-array guard).
- UTC-everywhere: holds. `DigestCommands` uses only integer arithmetic + `time.getRequestTime()`; `DigestRenderer` uses `gmdate()` + explicit UTC in `strtotime()`.
- Diff-gate: only the 9 intended production files staged. Test files unstaged (flagged as pre-PR action).
- Test quality (rubric §7): PASS on every criterion. One dead-code helper (`sourceQueue()` in `DailyDigestCommandTest.php`) noted as advisory only.
- Scope: matches issue #234 + brief exactly. No creep.

**Pre-PR action (mandatory):** stage the 3 T-authored test files (paths in handoff-S.md §Pre-PR action) before opening the PR — otherwise the PR ships production code without tests visible to CI.

**Follow-ups filed:** none (POC lean; no GH issues for the two advisory notes — dead-code helper + upstream string-vs-int fix — per no-follow-ups rule).

**Handoff:** `handoff-S.md`.
