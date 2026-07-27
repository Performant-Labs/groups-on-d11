<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\group\Entity\GroupInterface;
use Drupal\message\Entity\Message;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for the weekly digest drush command (#235, N-7).
 *
 * Part of the Notifications epic #237, N-7. Pins the
 * `Drupal\do_notifications\Commands\DigestCommands::digestWeekly()` contract
 * (brief §"Reuse & Analogous-Feature map"): claims every `frequency='weekly'`
 * queue row older than a configurable window (default 7 days), groups by
 * recipient, renders one aggregated digest per user via N-8's
 * `DigestRenderer` (reused verbatim, called with `'weekly'`), enqueues one
 * row per user into the existing `do_notifications_digest_queue` table with
 * `window='weekly'`, deletes the consumed source rows from
 * `do_notifications_queue`, and skips users with zero in-window items (no
 * empty-digest email). This is a structural mirror of N-6's
 * `DailyDigestCommandTest` with `weekly`/`604800` substituted throughout.
 *
 * Exercises AC1-AC10 from the brief:
 * - AC1: command is registered/callable (invoked directly here per the same
 *   kernel-test guidance N-6 used — no drush shell-out from a kernel test).
 * - AC2/AC3/AC4: 12 weekly items across 4 users -> 4 digest rows,
 *   window='weekly', body content traceable to that user's messages, source
 *   rows deleted.
 * - AC5: an in-window weekly item is not consumed.
 * - AC6: 'daily'/'immediately' items older than the window are not consumed.
 * - AC7: a user with zero in-window items produces zero digest rows.
 * - AC8: command result summary shape.
 * - AC9: `state.set('do_notifications.digest_weekly.window_seconds', N)`
 *   narrows the window.
 * - AC10: a queue row whose `mid` points to a deleted/non-existent Message
 *   is dropped from the digest AND its own row is deleted (garbage
 *   collected) — but does not block digesting the user's other valid
 *   messages.
 *
 * These are RED (Phase 4) tests: `digestWeekly()` and `claimWeekly()` do not
 * exist yet. F implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class WeeklyDigestCommandTest extends GroupsKernelTestBase {

  /**
   * The default window (brief: 604800s = 7d), overridable via state.
   */
  private const DEFAULT_WINDOW_SECONDS = 604800;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'flag',
    'comment',
    'message',
    'do_activity',
    'do_notifications',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('message');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['do_activity']);
    $this->installSchema('do_notifications', [
      'do_notifications_queue',
      'do_notifications_digest_queue',
    ]);

    \Drupal\comment\Entity\CommentType::create([
      'id' => 'comment_activity',
      'label' => 'Activity comment',
      'target_entity_type_id' => 'node',
    ])->save();
  }

  /**
   * Returns the do_notifications.digest_command service under test.
   *
   * Invoked directly (not shelled out via drush) — matches N-6's kernel-test
   * idiom for this same command class.
   *
   * @return \Drupal\do_notifications\Commands\DigestCommands
   *   The digest command service.
   */
  private function command(): object {
    return \Drupal::service('do_notifications.digest_command');
  }

  /**
   * Returns the do_notifications.queue service (the source queue).
   */
  private function sourceQueue(): object {
    return \Drupal::service('do_notifications.queue');
  }

  /**
   * Returns the do_notifications.digest_queue service (the destination).
   */
  private function digestQueue(): object {
    return \Drupal::service('do_notifications.digest_queue');
  }

  /**
   * Inserts a raw row directly into `do_notifications_queue`.
   *
   * Bypasses `enqueue()`'s merge/dedup semantics and its hardcoded
   * `time.getRequestTime()` for `created`, so this suite can pin arbitrary
   * `created` timestamps deterministically without a TestTime seam (none
   * exists in this codebase).
   *
   * @return int
   *   The inserted row's id.
   */
  private function insertQueueRow(int $uid, int $mid, string $frequency, int $created, string $template = 'activity_post_created'): int {
    return (int) \Drupal::database()->insert('do_notifications_queue')
      ->fields([
        'uid' => $uid,
        'mid' => $mid,
        'template' => $template,
        'frequency' => $frequency,
        'day' => gmdate('Y-m-d', $created),
        'created' => $created,
      ])
      ->execute();
  }

  /**
   * Creates a saved `activity_post_created` Message with a given created time.
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message.
   */
  private function createMessage(GroupInterface $group, int|string $author_id, int $created): Message {
    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Post at ' . $created]);

    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author_id,
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $post->id(),
      'field_group_id' => $group->id(),
    ]);
    $message->save();
    $message->setCreatedTime($created);
    $message->save();

    return $message;
  }

  /**
   * Counts rows directly in `do_notifications_queue`.
   */
  private function sourceRowCount(): int {
    return (int) \Drupal::database()
      ->select('do_notifications_queue', 'q')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * The full scenario: 12 weekly items (5/4/3 across 3 users) older than the
   * window are digested into 3 rows; in-window and other-frequency items
   * are left untouched; a zero-item user produces nothing.
   *
   * Covers AC2, AC3, AC4, AC5, AC6, AC7, AC8 in one integrated scenario, per
   * N-6's cheapest-sufficient-tier rationale: one kernel test proving the
   * whole claim/render/enqueue/delete pipeline together, since these
   * behaviors are not independently triggerable without re-running the same
   * expensive setup.
   */
  public function testDigestWeeklyAggregatesPerUserAndConsumesSourceRows(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();

    $user_a = $this->createUser();
    $user_b = $this->createUser();
    $user_c = $this->createUser();
    $user_zero = $this->createUser();

    $now = \Drupal::time()->getRequestTime();
    $old = $now - self::DEFAULT_WINDOW_SECONDS - 3600;
    $in_window = $now - 60;

    // 5 weekly items, older than window, for user A.
    $user_a_message_ids = [];
    for ($i = 0; $i < 5; $i++) {
      $message = $this->createMessage($group, $author->id(), $old - $i);
      $user_a_message_ids[] = (int) $message->id();
      $this->insertQueueRow((int) $user_a->id(), (int) $message->id(), 'weekly', $old - $i);
    }

    // 4 weekly items, older than window, for user B.
    for ($i = 0; $i < 4; $i++) {
      $message = $this->createMessage($group, $author->id(), $old - 100 - $i);
      $this->insertQueueRow((int) $user_b->id(), (int) $message->id(), 'weekly', $old - 100 - $i);
    }

    // 3 weekly items, older than window, for user C.
    for ($i = 0; $i < 3; $i++) {
      $message = $this->createMessage($group, $author->id(), $old - 200 - $i);
      $this->insertQueueRow((int) $user_c->id(), (int) $message->id(), 'weekly', $old - 200 - $i);
    }

    // 2 weekly items INSIDE the window (must not be consumed).
    $in_window_message_1 = $this->createMessage($group, $author->id(), $in_window);
    $in_window_message_2 = $this->createMessage($group, $author->id(), $in_window - 1);
    $this->insertQueueRow((int) $user_a->id(), (int) $in_window_message_1->id(), 'weekly', $in_window);
    $this->insertQueueRow((int) $user_b->id(), (int) $in_window_message_2->id(), 'weekly', $in_window - 1);

    // 2 'immediately' items, older than window (must not be consumed).
    $immediately_message_1 = $this->createMessage($group, $author->id(), $old - 5);
    $immediately_message_2 = $this->createMessage($group, $author->id(), $old - 6);
    $this->insertQueueRow((int) $user_a->id(), (int) $immediately_message_1->id(), 'immediately', $old - 5);
    $this->insertQueueRow((int) $user_c->id(), (int) $immediately_message_2->id(), 'immediately', $old - 6);

    // 2 'daily' items, older than window (must not be consumed).
    $daily_message_1 = $this->createMessage($group, $author->id(), $old - 7);
    $daily_message_2 = $this->createMessage($group, $author->id(), $old - 8);
    $this->insertQueueRow((int) $user_b->id(), (int) $daily_message_1->id(), 'daily', $old - 7);
    $this->insertQueueRow((int) $user_c->id(), (int) $daily_message_2->id(), 'daily', $old - 8);

    // $user_zero has zero items at all.
    unset($user_zero);

    $this->assertSame(18, $this->sourceRowCount(), 'Sanity check: 18 rows total before running the command (5+4+3+2+2+2).');

    $result = $this->command()->digestWeekly();

    // AC8: summary shape.
    $this->assertIsArray($result, 'digestWeekly() returns an array summary.');
    $this->assertSame(3, $result['users_digested'], 'Exactly 3 distinct users had weekly items in window.');
    $this->assertSame(12, $result['items_consumed'], 'Exactly 12 weekly+out-of-window items were consumed (5+4+3).');
    $this->assertSame(3, $result['digests_enqueued'], 'Exactly 3 digest rows were enqueued, one per user.');

    // AC2/AC3: exactly 3 rows in the digest queue, source rows deleted.
    $digest_rows = $this->digestQueue()->all();
    $this->assertCount(3, $digest_rows, 'Exactly 3 rows landed in do_notifications_digest_queue.');

    foreach ($digest_rows as $row) {
      $this->assertSame('weekly', $row['window'], 'Every digest row is tagged window=weekly.');
      $this->assertEqualsWithDelta($now, (int) $row['send_at'], 2, 'send_at is approximately now (allow a couple seconds of test wall-clock drift).');
    }

    // AC4: user A's digest body traces to exactly 5 rendered fragments.
    $user_a_row = NULL;
    foreach ($digest_rows as $row) {
      if ((int) $row['uid'] === (int) $user_a->id()) {
        $user_a_row = $row;
        break;
      }
    }
    $this->assertNotNull($user_a_row, 'A digest row exists for user A.');
    $this->assertStringContainsString(
      '5 updates',
      $user_a_row['subject'],
      "User A's digest subject reports exactly 5 rendered updates (matches DigestRenderer's fragment count), proving the body was produced from user A's own 5 messages and not some other count.",
    );

    // AC3: the 12 consumed rows are gone; the 6 untouched rows remain.
    $this->assertSame(6, $this->sourceRowCount(), 'The 12 consumed weekly/out-of-window rows are deleted; the 2 in-window + 4 other-frequency rows remain (6 total).');

    $remaining_mids = array_map(
      static fn (array $row): int => (int) $row['mid'],
      \Drupal::database()->select('do_notifications_queue', 'q')->fields('q', ['mid'])->execute()->fetchAll(\PDO::FETCH_ASSOC),
    );
    sort($remaining_mids);
    $expected_remaining = [
      (int) $in_window_message_1->id(),
      (int) $in_window_message_2->id(),
      (int) $immediately_message_1->id(),
      (int) $immediately_message_2->id(),
      (int) $daily_message_1->id(),
      (int) $daily_message_2->id(),
    ];
    sort($expected_remaining);
    $this->assertSame($expected_remaining, $remaining_mids, 'The surviving rows are exactly the 2 in-window + 4 other-frequency rows, by mid.');
  }

  /**
   * `state.set('do_notifications.digest_weekly.window_seconds', N)` narrows
   * the window: a row that would be "in window" under the 7d default is
   * consumed once the window is compressed to make it "older than window".
   */
  public function testWindowSecondsStateOverrideNarrowsWindow(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $user = $this->createUser();

    $now = \Drupal::time()->getRequestTime();
    // 10 minutes old: within the DEFAULT 7-day window (would not be consumed
    // by default), but OUTSIDE a compressed 5-minute window.
    $created = $now - 600;
    $message = $this->createMessage($group, $author->id(), $created);
    $this->insertQueueRow((int) $user->id(), (int) $message->id(), 'weekly', $created);

    // Under the default window, this item is NOT consumed.
    $result_default = $this->command()->digestWeekly();
    $this->assertSame(0, $result_default['items_consumed'], 'Under the default 7-day window, a 10-minute-old item is still in-window and not consumed.');
    $this->assertSame(1, $this->sourceRowCount(), 'The row is untouched under the default window.');

    // Narrow the window to 5 minutes (300s) via state.
    \Drupal::state()->set('do_notifications.digest_weekly.window_seconds', 300);

    $result_narrowed = $this->command()->digestWeekly();
    $this->assertSame(1, $result_narrowed['items_consumed'], 'Under a 300s window, the 600s-old item is now older than the window and IS consumed.');
    $this->assertSame(0, $this->sourceRowCount(), 'The row is deleted once consumed under the narrowed window.');
    $this->assertSame(1, $this->digestQueue()->count(), 'Exactly 1 digest row was enqueued once the window was narrowed.');
  }

  /**
   * A queue row whose `mid` points to a non-existent Message is dropped from
   * the digest AND its own queue row is deleted (garbage-collected) — but
   * does not block digesting the user's other valid messages.
   *
   * Pins AC10 (mirrors N-6's AC "orphan" test for the weekly path's private
   * `digestUserWeekly()` helper).
   */
  public function testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $user = $this->createUser();

    $now = \Drupal::time()->getRequestTime();
    $old = $now - self::DEFAULT_WINDOW_SECONDS - 3600;

    // A real, valid message.
    $valid_message = $this->createMessage($group, $author->id(), $old);
    $this->insertQueueRow((int) $user->id(), (int) $valid_message->id(), 'weekly', $old);

    // A queue row pointing at a Message id that does not exist.
    $nonexistent_mid = 999999;
    $this->insertQueueRow((int) $user->id(), $nonexistent_mid, 'weekly', $old - 1);

    $this->assertSame(2, $this->sourceRowCount(), 'Sanity check: 2 rows before running the command.');

    $result = $this->command()->digestWeekly();

    // The user still gets a digest (from the 1 valid message) — the orphan
    // does not block digesting.
    $this->assertSame(1, $result['users_digested'], 'The user is still digested despite the orphaned row.');
    $this->assertSame(1, $result['digests_enqueued'], 'Exactly 1 digest row is enqueued (built from the valid message only).');

    $digest_rows = $this->digestQueue()->all();
    $this->assertCount(1, $digest_rows);
    $row = reset($digest_rows);
    $this->assertStringContainsString(
      '1 updates',
      $row['subject'],
      "The digest subject reports exactly 1 rendered update — the orphan's mid contributed no fragment.",
    );

    // Both source rows (valid + orphan) are gone: the valid one was
    // consumed normally; the orphan was garbage-collected.
    $this->assertSame(0, $this->sourceRowCount(), 'Both the valid row and the orphaned row are deleted from the source queue.');
  }

  /**
   * A user with zero items in the window produces zero digest rows.
   *
   * Isolates AC7 as its own minimal test (cheapest sufficient case: no
   * queue rows at all for the only user in the fixture) rather than relying
   * solely on the integrated scenario's incidental `$user_zero`, so this
   * behavior has a test that fails in isolation if the "skip empty digests"
   * guard is removed.
   */
  public function testUserWithNoInWindowItemsProducesNoDigestRow(): void {
    $this->createUser();

    $result = $this->command()->digestWeekly();

    $this->assertSame(0, $result['users_digested'], 'No users had any weekly items at all.');
    $this->assertSame(0, $result['items_consumed']);
    $this->assertSame(0, $result['digests_enqueued'], 'Zero digest rows are enqueued when no items are queued.');
    $this->assertSame(0, $this->digestQueue()->count(), 'The digest queue table has zero rows.');
  }

}
