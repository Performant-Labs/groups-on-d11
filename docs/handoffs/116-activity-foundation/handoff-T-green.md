# Handoff-T-green: Phase 6 - #116 ST-F2: Activity layer foundation

**Date:** 2026-07-23
**Branch:** 116-activity-foundation
**Issue:** #116
**Handoff-F reviewed:** `docs/handoffs/116-activity-foundation/handoff-F-green.md`
**Handoff-T-red:** `docs/handoffs/116-activity-foundation/handoff-T-red.md`

## Repair task: four flagged test-authoring bugs

F implemented `do_activity` against the RED suite and, during self-verification, identified four
test-authoring bugs (not production-code gaps). F did not edit any test file — all four were
verified only against a disposable, gitignored assembled copy, then flagged for T. I independently
re-verified each against the actual committed test source before fixing.

1. **`ActivityKernelTestBase::setUp()` never installs `do_activity`'s own config.**
   Verified: `setUp()` (line 60-102, pre-fix) called `installConfig(['filter', 'field'])` but never
   `installConfig(['do_activity'])`. `KernelTestBase::enableModules()` never auto-installs a
   module's own `config/install/` — confirmed by reading the six `message.template.*.yml` +
   attached field config F shipped under `do_activity/config/install/`, none of which land in the
   test DB without an explicit `installConfig()` call. F's diagnosis confirmed correct.
   **Fix:** added `$this->installConfig(['do_activity']);` immediately after the existing
   `installConfig(['filter', 'field'])` call in `ActivityKernelTestBase.php`.

2. **Five call sites read `->value` on `field_group_id`, an `entity_reference` field.**
   Verified: `field.storage.message.field_group_id.yml` declares `type: entity_reference,
   target_type: group`. `EntityReferenceItem::propertyDefinitions()` defines only `target_id` and
   `entity` — no `value` property — so `->value` resolves to `NULL` via the magic-getter fallback,
   and `(int) NULL === 0`. Cross-checked the sibling fields: `field_referenced_entity_type`
   (`type: string`) and `field_referenced_entity_id` (`type: integer`) both correctly have a
   `value` property, so those five `->value` reads on the OTHER two fields are correct and were
   left unchanged. F's diagnosis confirmed correct.
   **Fix:** changed `->get('field_group_id')->value` to `->get('field_group_id')->target_id` at
   all five call sites: `NodeInGroupInsertTest.php:61,103`, `CommentInsertTest.php:60`,
   `MembershipCreateTest.php:43`, `DeletionHygieneTest.php:98` (the sixth reference at
   `DeletionHygieneTest.php:102`, `->isEmpty()`, is a method call and was already correct — no
   change made there, matching F's note).

3. **`DeletionHygieneTest.php:92` calls `->getGroupRelationship()`, a nonexistent method.**
   Verified: `GroupInterface::getMember()` is documented to return
   `\Drupal\group\Entity\GroupMembership` directly, and `GroupMembershipInterface extends
   GroupRelationshipInterface` — the returned object already IS the relationship entity, no
   unwrapping method exists. F's diagnosis confirmed correct.
   **Fix:** `$membership = $group->getMember($member)->getGroupRelationship();` became
   `$membership = $group->getMember($member);` — call dropped entirely.

4. **`MembershipCreateTest::testGroupCreatorMembershipRecordsCreatorAsActor()`'s premise is false
   at the kernel tier.** Verified: `GroupType::$creator_membership` is consumed exclusively inside
   `GroupForm::form()`/`GroupForm::actions()` (`creatorGetsMembership()`), the add-FORM's own
   submit-handler enhancement — nothing in `Group`'s entity-storage layer or
   `GroupsKernelTestBase::createGroup()` (a bare `$storage->save()`) ever creates a creator
   membership relationship outside a real `/group/add` submission. Confirmed via
   `GroupsKernelTestBase::createGroup()` (bare storage save, no membership side effect) and
   `GroupsKernelTestBase::addMember()` (the programmatic `Group::addMember()` path, which DOES
   fire `group_relationship_insert` for `group_membership` at the kernel tier). F's diagnosis
   confirmed correct; took F's preferred option (a).
   **Fix:** rewrote `testGroupCreatorMembershipRecordsCreatorAsActor()` as
   `testOwnerAddedAsMemberRecordsOwnerAsActor()` — constructs the membership explicitly via
   `$this->addMember($group, $owner)` (the kernel-tier-reachable mechanism), keeping the same
   acceptance intent (membership creation logs an activity Message attributing to the correct
   actor, even when that actor is the group owner) without asserting a form-only side effect that
   cannot exist in a kernel-test context. Did NOT delete the test — the acceptance remains covered,
   now by a premise that actually holds at this tier.

Also fixed two pre-existing `phpcs` doc-comment errors surfaced while touching
`MembershipCreateTest.php` and `DeletionHygieneTest.php` (multi-line docblock short-descriptions,
a Drupal-coding-standard violation predating my edits) — condensed both to a single-line summary
with the elaboration moved to a following paragraph. Zero behavior change; `phpcs` now reports 0
errors across the module.

## GREEN confirmation

Ran (via `ddev exec`, `SIMPLETEST_DB=mysql://db:db@db:3306/db`, matching the documented T-red run
command):

```
bash scripts/ci/assemble-config.sh   # 103 config files, 14 custom modules, core.extension patched
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_activity/tests/src/Kernel/
```

Result (final run, exit code 0):

```
Tests: 20, Assertions: 655, Deprecations: 14, PHPUnit Deprecations: 28, Risky: 2.
```

All 20 test methods pass. `Risky: 2` are PHPUnit's "unexpected stdout output" flag on
`BackfillIdempotencyTest`'s two methods (the backfill script legitimately echoes progress,
matching `step_700_demo_data.php`'s own convention, and the test deliberately runs it twice to
prove idempotency) — expected, not a failure, exactly as F documented. `Deprecations: 14` /
`PHPUnit Deprecations: 28` are pre-existing core/contrib deprecation noise (`RunTestsInSeparateProcesses`
attribute, `@EntityType` annotation vs. attribute, `Config::save($has_trusted_data)`, etc.) —
identical in category and count to F's own reported run, not introduced by any T-green change.

Testdox output (18 of 20 shown; the 2 `BackfillIdempotencyTest` methods are excluded from testdox
listing because PHPUnit routes their stdout-capture warning there instead, but both are confirmed
passing via the `Tests: 20` / zero-Failures/zero-Errors summary and exit code 0):

```
 ✔ Comment on group node records message with group ref
 ✔ Comment on ungrouped node records message with no group ref
 ✔ Node delete removes post created message
 ✔ Comment delete removes comment created message
 ✔ Membership relationship delete removes membership message
 ✔ Group delete removes group created message
 ✔ Rsvp event flagging records one message
 ✔ Follow user flagging records one distinct message
 ✔ Group create records one message
 ✔ Two groups record two distinct messages
 ✔ Add member records one membership message
 ✔ Owner added as member records owner as actor
 ✔ Two members record two distinct messages
 ✔ Node created in group records one message
 ✔ Existing node added to group records one message
 ✔ Membership relationship records no post created message
 ✔ Pin records one message
 ✔ Unpin removes the message
```

**Spot-check: rewritten test still pins real behavior.** On the disposable, gitignored
`web/modules/custom/do_activity/` assembled copy only (never the committed `docs/groups/...`
source — discarded via a fresh `assemble-config.sh` run afterward), disabled the
`group_membership` branch of `DoActivityHooks::groupRelationshipInsert()` and re-ran
`MembershipCreateTest`. All three methods failed for the right reason, including the rewritten
`testOwnerAddedAsMemberRecordsOwnerAsActor` (`Failed asserting that an array is not empty.`) —
confirming the rewrite still pins the "membership creation logs an activity Message" behavior
rather than passing vacuously. Reassembled from pristine source immediately after
(`git status --porcelain` on `docs/groups/modules/do_activity/tests/` shows only the five
intentional edits below; no drift from the spot-check).

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `ddev exec bash scripts/ci/assemble-config.sh` | exit 0 | exit 0, 103 config files, 14 custom modules, core.extension patched | PASS |
| Kernel suite | `php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_activity/tests/src/Kernel/` | 20/20 GREEN | `Tests: 20, Failures: 0, Errors: 0`, exit 0 | PASS |
| Syntax | (covered by F's `php -l`, re-confirmed implicitly by successful PHPUnit bootstrap of all edited files) | clean | clean | PASS |

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test coverage vs. acceptance criteria | Cross-checked all 6 log points (post-created, comment-created, membership-created, flagging/pin, group-created) + deletion hygiene + backfill idempotency against the 8 test files / 20 methods | All criteria have a passing test; no gaps found | PASS |
| Test quality (behavior not implementation) | Read all 5 edited files; spot-check (see above) proves the rewritten membership test still fails when behavior is removed; the four fixes were pure bug-repairs (wrong property access / wrong API call / missing setup), not weakenings of any assertion | Suite proportionate, no redundant tests, each test names a distinct behavior | PASS |
| phpcs (`Drupal,DrupalPractice`) | `php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_activity/` | 0 errors; 3 pre-existing `\Drupal::` DrupalPractice warnings in `DoActivityHooks.php` (baseline-matched against `do_notifications`, per F); 3 line-length + 1 unused-var warnings in test files (pre-existing, unrelated to the four bugs) | PASS (0 errors) |
| Type safety | Read all edited PHP — no `any`-equivalent casts introduced; `(int)` casts on entity IDs match the existing suite convention | No issues | PASS |
| Error handling / data integrity | Deletion-hygiene tests (4 methods) cover missing-record cleanup on node/comment/membership/group delete; `CommentInsertTest::testCommentOnUngroupedNodeRecordsMessageWithNoGroupRef` covers the "no group context" edge case explicitly | Covered | PASS |
| API contract | Message field shapes (`field_referenced_entity_type`/`_id`/`field_group_id`) verified against F's shipped config; assertions match the config-declared field types exactly (string/integer/entity_reference) | Matches | PASS |
| Peer regression smoke | `php vendor/bin/phpunit ... do_notifications/.../GroupAddNotificationTest.php do_streams/.../StreamsScopeTest.php` | Both green | `Tests: 13, Failures: 0, Errors: 0`, exit 0 | PASS |
| phpstan | N/A — not configured in this repo (no `phpstan.neon`/`phpstan.dist.neon` found) | — | SKIPPED (not configured) |

## Acceptance criteria status

| Criterion (from brief, six log points + deletion hygiene + backfill) | Status | Backing test(s) |
|---|---|---|
| Post created in group → one Message, actor = current user, refs = node+group | PASS | `NodeInGroupInsertTest::testNodeCreatedInGroupRecordsOneMessage` |
| Existing node added to group afterwards → one Message | PASS | `NodeInGroupInsertTest::testExistingNodeAddedToGroupRecordsOneMessage` |
| Membership relationship insert does NOT fire post-created | PASS | `NodeInGroupInsertTest::testMembershipRelationshipRecordsNoPostCreatedMessage` |
| Comment created (in-group node) → one Message with group ref | PASS | `CommentInsertTest::testCommentOnGroupNodeRecordsMessageWithGroupRef` |
| Comment created (ungrouped node) → one Message, empty (never fabricated) group ref | PASS | `CommentInsertTest::testCommentOnUngroupedNodeRecordsMessageWithNoGroupRef` |
| Membership created → one Message, actor = new member (not group creator) | PASS | `MembershipCreateTest::testAddMemberRecordsOneMembershipMessage` |
| Membership created for the owner-as-member → attributes to owner | PASS | `MembershipCreateTest::testOwnerAddedAsMemberRecordsOwnerAsActor` (rewritten, see repair #4) |
| Two distinct members → two distinct Messages | PASS | `MembershipCreateTest::testTwoMembersRecordTwoDistinctMessages` |
| Flagging (rsvp_event) → one Message | PASS | `FlaggingInsertTest::testRsvpEventFlaggingRecordsOneMessage` |
| Flagging (follow_user) → one distinct Message | PASS | `FlaggingInsertTest::testFollowUserFlaggingRecordsOneDistinctMessage` |
| Pin toggled (flag) → one Message | PASS | `PinTogglePinTest::testPinRecordsOneMessage` |
| Unpin (unflag) → the Message is removed | PASS | `PinTogglePinTest::testUnpinRemovesTheMessage` |
| Group created → one Message | PASS | `GroupCreateTest::testGroupCreateRecordsOneMessage` |
| Two groups → two distinct Messages | PASS | `GroupCreateTest::testTwoGroupsRecordTwoDistinctMessages` |
| Node delete → its post-created Message is removed | PASS | `DeletionHygieneTest::testNodeDeleteRemovesPostCreatedMessage` |
| Comment delete → its comment-created Message is removed | PASS | `DeletionHygieneTest::testCommentDeleteRemovesCommentCreatedMessage` |
| Membership relationship delete → its membership-created Message is removed | PASS | `DeletionHygieneTest::testMembershipRelationshipDeleteRemovesMembershipMessage` |
| Group delete → its group-created Message is removed | PASS | `DeletionHygieneTest::testGroupDeleteRemovesGroupCreatedMessage` |
| Backfill is idempotent (running twice does not duplicate) | PASS | `BackfillIdempotencyTest::testBackfillIsNoOpAgainstAlreadyLoggedFixtures` |
| Backfill preserves the source entity's own timestamp | PASS | `BackfillIdempotencyTest::testBackfillPreservesSourceTimestamp` |

## Blocking issues

None.

## Advisory notes

- No production bugs were found beyond the two F already discovered and fixed during self-check
  (`getCreatedTime()` strict-int cast; `field_group_id` `['target_id' => ...]` array form) — both
  already in F's commit, not something T needed to flag further.
- No UI surface exists for this story (ST-7 #129 owns rendering), so **U is N/A** — this handoff
  routes directly to S per the pipeline's UI-surface branch.
- The `phpcs` doc-comment cleanup (two files) is a cosmetic side-fix bundled into this commit since
  it touched the same lines as the required bug repairs; flagging here for transparency rather than
  treating it as a silent scope-creep.
