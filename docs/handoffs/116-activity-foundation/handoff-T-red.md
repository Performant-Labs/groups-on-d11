# Handoff-T-red: Phase 4 - #116 ST-F2: Activity layer foundation

**Date:** 2026-07-23
**Branch:** 116-activity-foundation
**Brief / wireframe reviewed:** `docs/planning/handoffs/116-activity/brief-amended.md` (A-amended); issue #116 (`gh issue view 116`). No wireframe — this story is storage/logging/backfill only, no UI surface (rendering is ST-7 #129).

## A precondition
Confirmed: brief-amended.md is the A-approved plan artifact (post A-BLOCK pass 1, six amendments folded in). No separate A-PASS handoff file was found in this worktree, but the brief explicitly states it is the amended, binding spec — proceeding on that basis per the task brief.

## Tests authored
All under `docs/groups/modules/do_activity/tests/src/Kernel/` (module `do_activity` does not yet exist in production code — only a skeleton `do_activity.info.yml` was added, see below).

1. **`NodeInGroupInsertTest`** (3 methods) — log point 1: `group_relationship_insert` filtered to `group_node:*`. Pins: node created directly in a group, an existing node added to a group afterwards (RA3-style), and the negative case (a `group_membership` relationship must NOT fire this event). Kernel tier — needs live Group 4.x relationship storage + DB.
2. **`CommentInsertTest`** (2 methods) — log point 2: `comment_insert`. Pins the in-group case (group ref resolved) and the ungrouped case (empty group ref, no fabrication/error). Kernel tier — needs a real Comment entity against a real node with a comment field.
3. **`MembershipCreateTest`** (3 methods) — log point 3: `group_relationship_insert` filtered to `group_membership`. Pins actor = new member (not group owner), the creator-membership relationship (community_group has `creator_membership => TRUE`), and two distinct members producing two distinct Messages.
4. **`FlaggingInsertTest`** (2 methods) — log point 4: `flagging_insert` branching on flag id (`rsvp_event`, `follow_user`). Pins each flag id independently and together (two flags in one test proving the branch dispatch keeps them distinct).
5. **`GroupCreateTest`** (2 methods) — log point 5: `group_insert`. Pins actor = owner, ref = group, and two separate groups producing two distinct Messages (no static/singleton bug).
6. **`PinTogglePinTest`** (2 methods) — log point 6: pin via `flagging_insert` (branch for `pin_in_group`, same dispatch as #4) and unpin via `flagging_delete`. Pins the create-then-delete round trip explicitly, since the brief singles pin/unpin out as its own log point with an extra deletion-hygiene half.
7. **`BackfillIdempotencyTest`** (2 methods) — pins the resolved architecture choice from the brief ("backfill-after-seed": subscribers ALWAYS enabled, idempotency key prevents duplication). Seeds fixtures via the LIVE subscriber path (not disabled), runs `docs/groups/scripts/step_7xx_backfill_activity.php` twice, asserts the Message count is unchanged both times. A second method proves the backfill preserves the SOURCE entity's original timestamp (not `\Drupal::time()->getRequestTime()`) by deleting the live-hook Message and letting only the backfill recreate it.
8. **`DeletionHygieneTest`** (4 methods) — one per remaining entity type the brief's deletion-hygiene bullet lists (node, comment, group_relationship/membership, group). `flagging_delete` hygiene is covered by `PinTogglePinTest::testUnpinRemovesTheMessage()` instead, per the brief's own separation of pin/unpin into its own log point.

All 20 test methods sit at the **kernel** tier (cheapest sufficient tier — every assertion needs a live DB, Group 4.x relationship storage, and the Message/flag entity APIs; none can be unit-tested without faking the entire ORM). No UI surface exists for this story, so no e2e/functional tests were authored.

A shared `ActivityKernelTestBase` (not itself a test class) centralizes fixture plumbing (schemas, comment field on the `post` bundle, and the three named flag fixtures: `rsvp_event`, `follow_user`, `pin_in_group`, copied module-locally into `docs/groups/modules/do_activity/tests/fixtures/config/` — never a source-relative `__DIR__/../../../../config` path, so it resolves identically in the source tree and CI's assembled `web/modules/custom/do_activity` layout).

## RED confirmation

Run command (from the assembled DDEV layout, `gm116-activity`):
```
bash scripts/ci/assemble-config.sh   # (run via `ddev exec`, since `php` is only on PATH inside the container)
ddev exec "SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_activity/tests/src/Kernel"
```

Result: **19 of 20 tests fail**, each for the right reason:

- 17 tests fail with `Failed asserting that actual size 0 matches expected size N` (or `array is not empty` / `array contains N`) — the Message-query assertion itself fails because **no hook exists yet** to create any Message, so every count is 0. This is the real feature assertion failing, not a setup/import error.
- `BackfillIdempotencyTest::testBackfillPreservesSourceTimestamp` fails on `assertFileExists('.../docs/groups/scripts/step_7xx_backfill_activity.php')` — the backfill script (F's file, per "Owns") does not exist yet. A real missing-production-artifact failure.
- `BackfillIdempotencyTest::testBackfillIsNoOpAgainstAlreadyLoggedFixtures` fails on `assertGreaterThan(0, $baselineCount)` — the sanity check that the live subscriber path already logged something fails because, again, no hook exists yet to log anything. Also a real feature-absence failure, not a setup bug.

1 test **passes** by design: `NodeInGroupInsertTest::testMembershipRelationshipRecordsNoPostCreatedMessage` asserts the ABSENCE of a `activity_post_created` Message when a `group_membership` relationship is created. This is true both before and after F's implementation (correct discrimination never fires this event for membership), so it is a legitimate negative-space guard, not an invalid green-before-code test — it will continue to pass once F's code correctly discriminates `group_node:*` from `group_membership`, and would turn red if F's implementation wrongly fired on both.

Two genuine test-authoring bugs were found and fixed during RED confirmation (both mine, fixed before finalizing RED, not routed to F):
- Missing `installSchema('flag', ['flag_counts'])` in `ActivityKernelTestBase::setUp()` — the flag module's own count-tracking subscriber errored on a missing table when `flag()`/`unflag()` ran. Fixed by adding the schema install.

Full tally after the fix: `Tests: 20, Assertions: 616, Failures: 19` (1 pass, as designed above), all failures attributable to missing production code.

## Ready for F
Confirmed RED is valid. F may implement `do_activity` (hooks, message templates, install file) and `docs/groups/scripts/step_7xx_backfill_activity.php` against these tests.

### Advisory notes for F (interface contract the tests expect)

**Message templates (bundle ids) — six required:**
- `activity_post_created` — log point 1
- `activity_comment_created` — log point 2
- `activity_membership_created` — log point 3
- `activity_flagging_created` — log point 4 (rsvp_event, follow_user; NOT pin_in_group)
- `activity_group_created` — log point 5
- `activity_pin_toggled` — log point 6 (pin_in_group only)

**Message fields (custom, presumably bundle/base fields F adds via `do_activity.install` or per-template `config/install/message.template.*.yml` field config):**
- `field_referenced_entity_type` (string) — e.g. `'node'`, `'comment'`, `'user'`, `'group'`.
- `field_referenced_entity_id` (integer) — the id of the referenced entity.
- `field_group_id` (integer, entity reference to `group`) — used by `activity_post_created` and `activity_comment_created` only; must support an EMPTY value (not a fabricated/zero group) when there is no group context (see `CommentInsertTest::testCommentOnUngroupedNodeRecordsMessageWithNoGroupRef`, which asserts `$message->get('field_group_id')->isEmpty()`).
- Message actor/owner uses the entity's own `uid` base field (`$message->getOwnerId()`) — no custom actor field needed.
- Message timestamp uses the entity's own `created` base field (`$message->getCreatedTime()` / `setCreatedTime()`), per the brief's explicit timestamp contract for the backfill.

**Hook methods expected (attribute-based, mirroring `DoNotificationsHooks`):**
- `#[Hook('group_relationship_insert')]` — ONE method handling BOTH the `group_node:*` (log point 1) and `group_membership` (log point 3) cases, discriminating on `$relationship->getPluginId()` (mirrors `DoNotificationsHooks::groupRelationshipInsert()`'s `str_starts_with($plugin_id, 'group_node:')` pattern) — OR two separate hook methods on the same class; tests don't care which, only the resulting Messages.
- `#[Hook('comment_insert')]` — log point 2.
- `#[Hook('flagging_insert')]` — ONE method branching on `$flagging->getFlagId()` for BOTH `rsvp_event`/`follow_user` (log point 4, template `activity_flagging_created`) and `pin_in_group` (log point 6, template `activity_pin_toggled`) — two different templates from the same hook, dispatched by flag id.
- `#[Hook('group_insert')]` — log point 5.
- `#[Hook('flagging_delete')]`, `#[Hook('node_delete')]`, `#[Hook('comment_delete')]`, `#[Hook('group_relationship_delete')]`, `#[Hook('group_delete')]` — deletion hygiene; hard-delete the Message(s) whose `field_referenced_entity_type`/`field_referenced_entity_id` match the entity being deleted.

**Backfill script:** `docs/groups/scripts/step_7xx_backfill_activity.php` (exact number is F's to pick, per the "Owns" section — tests resolve it via `dirname(DRUPAL_ROOT) . '/docs/groups/scripts/step_7xx_backfill_activity.php'`, i.e. relative to the repo root, NOT the module). It must be `require`-able standalone (drush-script convention, no return value expected) and safe to run twice with zero net effect against entities the live hooks already logged (the idempotency key: `template` + `field_referenced_entity_type` + `field_referenced_entity_id` + `created`).

**Surprise for F:** `do_group_pin` (`docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php`) does NOT have a dedicated pin hook or distinct storage — Flag 4.x fires no dedicated (un)flag event, so it reacts to the flagging entity's own generic `entity_insert`/`entity_delete`, branching on `$entity->getFlagId() === self::PIN_FLAG_ID`. `do_activity`'s pin/unpin coverage (log point 6) is modeled the same way in these tests — via `flagging_insert`/`flagging_delete` on the flagging entity itself, branching on flag id — NOT via any `do_group_pin`-specific API. Confirmed no separate storage exists to mirror instead.

## Files created
- `docs/groups/modules/do_activity/do_activity.info.yml` (skeleton, T-authored per Phase-4 convention — commented at top explaining why; F may extend `dependencies`/`package` if needed but should not need to touch the id/type)
- `docs/groups/modules/do_activity/tests/fixtures/config/flag.flag.rsvp_event.yml`
- `docs/groups/modules/do_activity/tests/fixtures/config/flag.flag.follow_user.yml`
- `docs/groups/modules/do_activity/tests/fixtures/config/flag.flag.pin_in_group.yml`
- `docs/groups/modules/do_activity/tests/src/Kernel/ActivityKernelTestBase.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/NodeInGroupInsertTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/CommentInsertTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/MembershipCreateTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/FlaggingInsertTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/GroupCreateTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/PinTogglePinTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/BackfillIdempotencyTest.php`
- `docs/groups/modules/do_activity/tests/src/Kernel/DeletionHygieneTest.php`

Committed to `116-activity-foundation` (commit `2313166`, explicit paths only — no `config/sync/*` or `web/modules/custom/*` build artifacts from `assemble-config.sh`/`composer install` were staged).
