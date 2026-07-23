<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

use Drupal\comment\Entity\Comment;

/**
 * Backfill idempotency (#116 amended brief, "Backfill" + "Acceptance" §).
 *
 * The brief's resolved architecture choice (per A's question #3) is
 * **backfill-after-seed**: subscribers stay enabled ALWAYS (never disabled
 * during seed), and `step_7xx_backfill_activity.php` is safe to run against
 * already-logged data because of its idempotency key —
 * `(template, field_referenced_entity_type, field_referenced_entity_id,
 * created)`. This is NOT a "disable hooks, backfill from scratch" design; it
 * is "hooks always log live, and backfill only catches PRE-EXISTING entities
 * whose creation predates do_activity being enabled" (e.g. an entity created
 * before the module existed, or before a channel's hook shipped).
 *
 * So this test seeds fixtures via the LIVE subscriber path (mirroring every
 * other test in this suite — a real group/node/comment/membership/flagging,
 * each of which the enabled hooks already log), captures the resulting
 * Message count N, then runs the backfill script TWICE and asserts the count
 * is unchanged both times — proving the idempotency key prevents the
 * backfill from re-logging entities the live hooks already covered. This is
 * the sharpest test of the idempotency key: if the key match were wrong
 * (e.g. missing `created` in the key, or matching on the wrong field), the
 * backfill would double the count on its very first run against
 * already-hook-logged data.
 *
 * The backfill script itself is F's to author
 * (`docs/groups/scripts/step_7xx_backfill_activity.php`, "Owns" §) — this
 * test `require`s it directly (the drush-script convention has no service
 * wrapper), so until it exists the test fails on a real missing-file error,
 * not a setup bug.
 *
 * @group do_activity
 * @group do_tests
 */
class BackfillIdempotencyTest extends ActivityKernelTestBase {

  /**
   * The backfill script's path, relative to the module's own tests dir.
   *
   * Resolved via this module's own directory (do_activity/tests/src/Kernel/
   * -> ../../../../scripts/step_7xx_backfill_activity.php lands at
   * docs/groups/scripts/... in the source tree, and at the equivalent
   * assembled-module-relative path once do_activity is copied into
   * web/modules/custom — scripts/ is NOT copied per-module by
   * assemble-config.sh, so this path is intentionally anchored at the
   * REPO ROOT via the Drupal root constant, not a module-relative one).
   */
  private function backfillScriptPath(): string {
    // DRUPAL_ROOT is web/; the repo root (containing docs/groups/scripts) is
    // one level up in both the source tree and the assembled CI layout.
    return dirname(DRUPAL_ROOT) . '/docs/groups/scripts/step_7xx_backfill_activity.php';
  }

  /**
   * Runs the backfill script in the current kernel test's container context.
   */
  private function runBackfill(): void {
    $script = $this->backfillScriptPath();
    $this->assertFileExists(
      $script,
      'The backfill script docs/groups/scripts/step_7xx_backfill_activity.php exists (F owns this file per the brief).'
    );
    require $script;
  }

  /**
   * Running the backfill against already-hook-logged fixtures adds nothing.
   */
  public function testBackfillIsNoOpAgainstAlreadyLoggedFixtures(): void {
    // The backfill script (docs/groups/scripts/step_7xx_backfill_activity.php)
    // intentionally echoes per-row progress ("=== step_7xx.N: ..." / "Exists:
    // ...") for operators watching a real seed/backfill run — see that
    // script's docblock. This test `require`s it directly (twice, per the
    // idempotency assertion below), so PHPUnit sees stdout from this test
    // method. Declaring it here (rather than suppressing it via ob_start())
    // tells PHPUnit the output is expected, clearing the "risky" flag that
    // trips CI's failOnRisky=true, while keeping the echoed lines visible to
    // anyone debugging a real run.
    $this->expectOutputRegex('/.*/s');

    // Seed via the LIVE subscriber path: group -> membership -> node-in-group
    // -> comment -> flagging, each already logged by the enabled hooks.
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $group = $this->createGroup(['uid' => $owner->id()]);

    $member = $this->createUser();
    $this->addMember($group, $member);

    $node = $this->addNode($group, 'post', [
      'title' => 'Backfill fixture post',
      'uid' => $owner->id(),
      // A known PAST timestamp — the backfill's timestamp contract (brief:
      // "Never \Drupal::time()->getRequestTime()") is exercised meaningfully
      // only against a source entity whose created time is not "now".
      'created' => strtotime('2024-01-15 10:00:00'),
    ]);

    $commenter = $this->createUser();
    $this->setCurrentUser($commenter);
    Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => static::COMMENT_FIELD_NAME,
      'uid' => $commenter->id(),
      'comment_type' => 'comment',
      'subject' => 'Re: Backfill fixture',
      'comment_body' => ['value' => 'A reply.', 'format' => 'plain_text'],
      'status' => 1,
      'created' => strtotime('2024-01-15 11:00:00'),
    ])->save();

    $flagger = $this->createUser();
    $this->container->get('flag')->flag($this->loadFlag('pin_in_group'), $node, $flagger);

    $baselineCount = count($this->loadAllMessages());
    $this->assertGreaterThan(
      0,
      $baselineCount,
      'Sanity: the live subscriber path already logged at least one Message before backfill runs.'
    );

    // First backfill run: must add NOTHING (every fixture is already logged
    // by the live hooks, and the idempotency key must recognise that).
    $this->runBackfill();
    $this->assertCount(
      $baselineCount,
      $this->loadAllMessages(),
      'Running the backfill once against already-hook-logged fixtures adds no new Messages.'
    );

    // Second backfill run: still a no-op (true idempotency, not "only the
    // first extra run is skipped").
    $this->runBackfill();
    $this->assertCount(
      $baselineCount,
      $this->loadAllMessages(),
      'Running the backfill a second time still adds no new Messages.'
    );
  }

  /**
   * The backfill preserves the SOURCE entity's original timestamp.
   *
   * Brief: "Message::create([...])->setCreatedTime($source->getCreatedTime())
   * — never \Drupal::time()->getRequestTime()." This test seeds a node whose
   * activity Message was NOT yet recorded by a live hook (simulated by
   * deleting the Message the live hook created, leaving only the source
   * node), so the backfill is the ONLY thing that can (re-)create it, and
   * asserts the recreated Message's created time equals the node's created
   * time, not "now".
   */
  public function testBackfillPreservesSourceTimestamp(): void {
    // Declare expected stdout up front — see the comment in
    // testBackfillIsNoOpAgainstAlreadyLoggedFixtures() above for why: the
    // backfill script's operator-facing progress echoes are expected output,
    // not a symptom of a broken test, so PHPUnit's failOnRisky=true (CI-side)
    // must not flag this method for producing them.
    $this->expectOutputRegex('/.*/s');

    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $group = $this->createGroup(['uid' => $owner->id()]);

    $pastTimestamp = strtotime('2024-01-15 10:00:00');
    $node = $this->addNode($group, 'post', [
      'title' => 'Pre-existing content',
      'uid' => $owner->id(),
      'created' => $pastTimestamp,
    ]);

    // Simulate content that predates do_activity: remove whatever the live
    // hook already logged for this node, so only the backfill can restore it.
    $storage = $this->entityTypeManager->getStorage('message');
    $existing = $this->messagesByTemplate('activity_post_created');
    $storage->delete($existing);
    $this->assertCount(0, $this->messagesByTemplate('activity_post_created'), 'Sanity: no Message remains before backfill.');

    $this->runBackfill();

    $messages = $this->messagesByTemplate('activity_post_created');
    $this->assertCount(1, $messages, 'The backfill recreates exactly one Message for the pre-existing node.');
    $message = reset($messages);
    $this->assertSame(
      $pastTimestamp,
      (int) $message->getCreatedTime(),
      "The backfilled Message's created time equals the source node's created time, not the backfill run time."
    );
  }

}
