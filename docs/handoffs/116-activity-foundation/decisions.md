# Decision Journal — 116-activity-foundation (issue #116)

## T — Phase 4 (author/RED) — 2026-07-23

- **Decided:** Ported and verified `bash scripts/ci/assemble-config.sh` must run via `ddev exec`
  (the host shell has no `php` on PATH; the script's core.extension.yml patch step shells out to
  `php -r ...` directly). Confirmed by first running it on the host (failed: `php: command not
  found`), then re-running identically inside the `gm116-activity` DDEV container (succeeded:
  103 config files copied, 14 custom modules copied incl. the new `do_activity`, core.extension
  patched).
- **Decided:** `composer install` had not yet been run in this worktree (no `vendor/` present) —
  ran `ddev composer install`, which pulled `drupal/message` 1.8.0 and `drupal/message_notify`
  1.5.0 into `web/modules/contrib/` (installer-paths, NOT `vendor/drupal/*` — a Drupal-project
  convention worth noting since it initially looked like the composer deps hadn't landed).
- **Decided:** Kernel-test bootstrap needs `SIMPLETEST_DB` set explicitly even under DDEV (DDEV's
  own `settings.ddev.php` configures the SITE's DB connection, but `KernelTestBase` requires the
  separate `SIMPLETEST_DB` env var per Drupal core's own outside-of-`run-tests.sh` contract).
  Resolved to `mysql://db:db@db:3306/db` (DDEV's own in-container DB credentials, confirmed via
  `ddev describe`). Documented the exact run command in handoff-T-red.md so F/T-green can repeat
  it without rediscovering this.
- **Decided:** Eight test files, 20 test methods total, exactly per the orchestrator's file-by-file
  spec (NodeInGroupInsertTest, CommentInsertTest, MembershipCreateTest, FlaggingInsertTest,
  GroupCreateTest, PinTogglePinTest, BackfillIdempotencyTest, DeletionHygieneTest), plus one
  non-test shared base class (`ActivityKernelTestBase`) centralizing fixture plumbing — mirrors
  the `do_streams`/`do_group_pin` precedent of NOT duplicating setUp() boilerplate across sibling
  test files in the same module.
- **Decided:** `do_activity.info.yml` is a T-authored skeleton (no hook class, no install file, no
  message template config) — documented at the top of the file with an explicit comment on why,
  per the task brief's "prefer omission" guidance being infeasible here (module enable requires
  SOME info.yml to exist) but everything else (hooks, templates, .install) deliberately omitted so
  F builds the real contract, not a stub T half-wrote.
- **Decided:** `BackfillIdempotencyTest` is written against the brief's ALREADY-RESOLVED
  architecture choice ("backfill-after-seed": subscribers stay enabled always, idempotency key
  prevents duplication) — NOT the orchestrator prompt's alternative phrasing ("seed fixtures with
  subscribers DISABLED"). The brief (`docs/planning/handoffs/116-activity/brief-amended.md`,
  "Backfill" §) is more specific and post-dates the general task template, and explicitly cites
  "Recorded per A question #3" as a settled architectural decision — treating it as binding over
  the more generic orchestrator wording. Seeds fixtures via the LIVE subscriber path, runs the
  backfill script twice, asserts the Message count is unchanged both times (the sharper test: if
  the idempotency key were wrong, the FIRST backfill run against already-hook-logged data would
  immediately double the count).
- **Decided:** Chose `dirname(DRUPAL_ROOT) . '/docs/groups/scripts/step_7xx_backfill_activity.php'`
  (repo-root-relative, NOT module-relative) as the backfill script's resolved path in the test,
  since `assemble-config.sh` copies `docs/groups/modules/*` into `web/modules/custom/` but does
  NOT copy `docs/groups/scripts/` anywhere — the script only ever exists at its source-tree path,
  in both the source checkout and CI's assembled layout, so a module-relative path would be wrong
  in exactly the "assembled layout" case the project's standing instructions warn about.
- **Decided (fixture placement):** Copied `flag.flag.{rsvp_event,follow_user,pin_in_group}.yml`
  byte-identical into `docs/groups/modules/do_activity/tests/fixtures/config/` (module-local),
  never referencing `docs/groups/config/` via a source-relative path — per the standing project
  instruction that source-relative fixture paths pass in the source tree but fail once
  `assemble-config.sh` copies the module elsewhere.
- **Decided (surprise for F, documented in handoff):** `do_group_pin` has NO dedicated pin hook or
  separate storage — it reacts to the flagging entity's own generic `entity_insert`/
  `entity_delete` (Flag 4.x fires no dedicated (un)flag hook), branching on flag id inside
  `onFlaggingChange()`. Modeled `PinTogglePinTest` the same way: pin/unpin coverage goes through
  `flagging_insert`/`flagging_delete` directly, branching on `$flagging->getFlagId()` — matching
  the brief's own hook list, not a `do_group_pin`-specific API path (confirmed by reading
  `docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php` in full; there is no alternative
  storage to mirror instead).
- **Decided (bug fix, mine, during RED confirmation):** `ActivityKernelTestBase::setUp()` was
  initially missing `installSchema('flag', ['flag_counts'])` — the flag module's own
  `FlagCountManager` subscriber errored with `Base table or view not found` on the very first
  `flag()`/`unflag()` call in any test that exercises a flagging (`FlaggingInsertTest`,
  `PinTogglePinTest`, `BackfillIdempotencyTest`). This is a genuine test-authoring gap (setup
  bug), fixed directly before finalizing RED, not routed to F — confirmed the fix by re-running
  the full suite and observing the failure mode change from a fatal `EntityStorageException` to a
  clean `Failed asserting that actual size 0 matches expected size N` (the real feature-absence
  assertion), which is the valid RED shape.
- **Decided:** Confirmed 19/20 tests fail for the right reason (a Message-count/existence
  assertion failing because no production hook exists yet, or a `assertFileExists()` failing
  because the backfill script doesn't exist yet) and 1/20 passes by design
  (`NodeInGroupInsertTest::testMembershipRelationshipRecordsNoPostCreatedMessage`, a negative-space
  guard asserting the ABSENCE of an event for `group_membership` relationships — true both before
  and after F's implementation, and would flip red if F's hook wrongly fired on both relationship
  kinds). Did not treat the one passing test as an invalid RED, since its assertion is genuinely
  about correct discrimination, not an assertion that trivially holds regardless of the feature.
- **Decided:** Staged only the 13 files I authored by explicit path (`git add <path> <path> ...`),
  never `git add -A`/`.` — `config/sync/*` (assemble-config.sh output) and `web/modules/custom/*`
  + `web/autoload_runtime.php` (composer scaffold + assembled-module copies) are build artifacts
  of running the verification tooling in this worktree, not source changes, and were left
  unstaged/untracked exactly as the primary checkout's `.gitignore`/repo convention expects.
- **Assumed:** No standalone `handoff-A.md`/Phase-3-PASS artifact was found in this worktree
  (only `brief-amended.md`, which self-describes as the A-amended, binding plan) — proceeded on
  the brief's own text as the A precondition per the task brief's explicit framing ("brief +
  wireframe" as the Phase-3 output for a no-UI story), rather than blocking to search for a
  separate A handoff file that may not exist for this story.
- **Evidence:** `docs/planning/handoffs/116-activity/brief-amended.md` (full read), `gh issue view
  116` (Scope + Acceptance sections), `docs/groups/modules/do_notifications/src/Hook/
  DoNotificationsHooks.php` (full read — group_relationship_insert discrimination pattern),
  `docs/groups/modules/do_notifications/tests/src/Kernel/GroupAddNotificationTest.php` (full
  read — queue-drain kernel-test pattern), `docs/groups/modules/do_group_pin/src/Hook/
  DoGroupPinHooks.php` (full read — confirmed no dedicated pin hook/storage),
  `docs/groups/modules/do_group_pin/tests/src/Kernel/PinnedStreamOrderingTest.php` (fixture-install
  pattern), `docs/groups/modules/do_tests/tests/src/Kernel/GroupsKernelTestBase.php` (full read —
  `createGroup`/`addMember`/`addNode` fixture helpers), `docs/groups/modules/do_streams/tests/src/
  Kernel/{StreamsScopeTest,StreamsRankingTest}.php` (setUp() boilerplate precedent),
  `docs/groups/scripts/step_700_demo_data.php` (comment-creation + idempotency-echo convention),
  `web/modules/contrib/message/src/Entity/Message.php` + `MessageTemplate.php` (full reads — API
  surface: `setCreatedTime`/`getCreatedTime`/`getOwnerId`/bundle-as-template-id),
  `web/modules/contrib/message/tests/src/Kernel/MessageTest.php` (full read — `Message::create()`
  kernel-test pattern), `web/core/modules/comment/tests/src/Kernel/CommentIntegrationTest.php`
  (full read — `FieldStorageConfig`/`FieldConfig` comment-field-attach pattern),
  `web/modules/contrib/flag/config/schema/flag.schema.yml` (confirmed `follow_user`'s
  `show_on_profile`/`extra_permissions` keys are schema-valid, no stripping needed unlike
  do_streams' `follow_content` fixture), `.github/workflows/test.yml` lines 55-140 (exact CI
  kernel-job recipe: assemble-config, settings.php prep, `SIMPLETEST_DB`, phpunit invocation —
  replicated verbatim inside DDEV), live DDEV run output (20 tests, 19 failures + 1 designed pass,
  616 assertions, captured verbatim in handoff-T-red.md), `git status --porcelain` (post-stage,
  13 files only under `docs/groups/modules/do_activity/`).
