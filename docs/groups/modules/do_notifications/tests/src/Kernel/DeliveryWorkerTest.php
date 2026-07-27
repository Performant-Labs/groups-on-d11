<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\group\Entity\GroupInterface;
use Drupal\message\Entity\Message;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' N-3 delivery worker (#231).
 *
 * Part of Notifications epic #237, N-3. Pins the end-to-end contract of the
 * `do_notifications:deliver` Drush command
 * (`Drupal\do_notifications\Drush\Commands\DoNotificationsCommands`): it
 * drains `do_notifications.queue` (frequency-filtered by `--type`), and for
 * each drained entry:
 *   - `immediately`: loads the Message + recipient, calls
 *     `EmailRenderer::render()`, then invokes the `log_mailer`
 *     `message_notify` plugin with `{uid, mid, template}`.
 *   - `daily` / `weekly`: groups entries by recipient uid, loads every
 *     Message for that recipient, calls
 *     `DigestRenderer::render($messages, $recipient, $window)`, then invokes
 *     `log_mailer` ONCE per recipient with
 *     `{uid, mid: first-mid, template: 'digest_<window>'}`.
 * A drained entry whose Message or recipient no longer loads is skipped (not
 * failed) with a `do_notifications` watchdog warning. Re-running the worker
 * against an empty queue is a clean no-op (idempotent).
 *
 * This suite calls the service directly
 * (`\Drupal::service('do_notifications.deliver_commands')->deliver($options)`,
 * the Drush 13 option-array convention) rather than shelling out to the
 * `drush` binary — the command CLASS is the unit under test, not Drush's own
 * argument-parsing layer.
 *
 * These are RED (Phase 4) tests: `DoNotificationsCommands`, its
 * `do_notifications.deliver_commands` service, `drush.services.yml`, and the
 * frequency-filtered `QueueBackendInterface::drain(?string $frequency)`
 * signature do not exist yet. F implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class DeliveryWorkerTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Mirrors `EmailRendererTest` / `DigestRendererTest`'s module list:
   * `do_activity` (whose `activity_*` Message templates EmailRenderer/
   * DigestRenderer render) hard-depends on `group`, `gnode`, `node`,
   * `comment`, so those are required even though this story's own AC
   * exercises only the queue -> renderer -> LogMailer pipeline.
   * `message_notify` + `dblog` are added for the plugin manager + watchdog
   * assertions this suite (unlike the renderer suites) needs directly.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'flag',
    'comment',
    'message',
    'message_notify',
    'dblog',
    'do_activity',
    'do_notifications',
    'user',
  ];

  /**
   * Absolute path to the file sink LogMailer writes to.
   */
  private string $logFilePath;

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
    $this->installSchema('dblog', ['watchdog']);
    $this->installSchema('do_notifications', ['do_notifications_queue']);
    $this->installConfig(['do_activity']);

    CommentType::create([
      'id' => 'comment_activity',
      'label' => 'Activity comment',
      'target_entity_type_id' => 'node',
    ])->save();

    $this->logFilePath = \Drupal::service('file_system')->getTempDirectory() . '/notifications.log';
    if (file_exists($this->logFilePath)) {
      unlink($this->logFilePath);
    }
  }

  /**
   * Returns the do_notifications.queue service under test.
   *
   * @return \Drupal\do_notifications\Queue\QueueBackendInterface
   *   The queue backend service.
   */
  private function queue(): object {
    return $this->container->get('do_notifications.queue');
  }

  /**
   * Returns the do_notifications.deliver_commands service under test.
   *
   * This is the swap point named in the brief: F registers this service id
   * in a new `do_notifications/drush.services.yml`, backed by
   * `Drupal\do_notifications\Drush\Commands\DoNotificationsCommands`.
   *
   * @return object
   *   The delivery worker Drush commands service.
   */
  private function commands(): object {
    return $this->container->get('do_notifications.deliver_commands');
  }

  /**
   * Invokes the delivery worker for the given `--type` option.
   *
   * Matches Drush 13's option-array convention
   * (`$command->method(['type' => $value])`), per the brief's AC #1.
   *
   * @param string $type
   *   One of 'all', 'immediately', 'daily', 'weekly'.
   */
  private function deliver(string $type = 'all'): void {
    $this->commands()->deliver(['type' => $type]);
  }

  /**
   * Creates and saves a real `activity_post_created` Message.
   *
   * Mirrors `DigestRendererTest::createPostMessage()` — a real post node in a
   * real group, so `EmailRenderer`/`DigestRenderer` (which this worker
   * invokes) render a genuine `activity_*` template rather than a stub.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group the post belongs to.
   * @param int|string $author_id
   *   The uid to attribute the Message to.
   *
   * @return \Drupal\message\Entity\Message
   *   The saved Message.
   */
  private function createPostMessage(GroupInterface $group, int|string $author_id): Message {
    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Post by ' . $author_id]);

    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author_id,
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $post->id(),
      'field_group_id' => $group->id(),
    ]);
    $message->save();

    return $message;
  }

  /**
   * Enqueues one entry on the real do_notifications.queue.
   *
   * @param int $uid
   *   The recipient uid.
   * @param \Drupal\message\Entity\Message $message
   *   The routed Message.
   * @param string $frequency
   *   'immediately' | 'daily' | 'weekly'.
   * @param string $day
   *   The `Y-m-d` dedup-key day; defaults to a fixed date so entries in the
   *   same test never accidentally dedup against each other via `day`
   *   drift, while still varying `mid` to stay distinct.
   */
  private function enqueue(int $uid, Message $message, string $frequency, string $day = '2026-07-20'): void {
    $this->queue()->enqueue([
      'uid' => $uid,
      'mid' => (int) $message->id(),
      'template' => $message->bundle(),
      'frequency' => $frequency,
      'day' => $day,
    ]);
  }

  /**
   * Counts watchdog rows of type `do_notifications`, optionally by severity.
   *
   * @param int|null $severity
   *   (optional) A RfcLogLevel value to filter on; NULL counts all severities.
   *
   * @return int
   *   The matching row count.
   */
  private function watchdogCount(?int $severity = NULL): int {
    $query = \Drupal::database()
      ->select('watchdog', 'w')
      ->condition('type', 'do_notifications');
    if ($severity !== NULL) {
      $query->condition('severity', $severity);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Counts lines in the LogMailer file sink.
   *
   * @return int
   *   The number of non-empty lines, or 0 if the file does not exist.
   */
  private function fileSinkLineCount(): int {
    if (!file_exists($this->logFilePath)) {
      return 0;
    }
    $contents = file_get_contents($this->logFilePath);
    return count(array_values(array_filter(explode("\n", trim((string) $contents)))));
  }

  /**
   * AC #6 golden path: `--type=immediately` drains only immediately entries.
   *
   * 3 immediately + 2 daily entries enqueued; running the worker with
   * `--type=immediately` must deliver exactly the 3 immediately entries
   * (3 new file-sink lines, 3 new watchdog rows) and leave the 2 daily
   * entries in the queue untouched.
   */
  public function testDeliverImmediatelyTypeDrainsOnlyImmediatelyEntries(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipientA = $this->createUser();
    $recipientB = $this->createUser();

    // 3 immediately entries (3 distinct messages, delivered individually).
    for ($i = 0; $i < 3; $i++) {
      $message = $this->createPostMessage($group, $author->id());
      $this->enqueue((int) $recipientA->id(), $message, 'immediately');
    }

    // 2 daily entries — must NOT be touched by --type=immediately.
    for ($i = 0; $i < 2; $i++) {
      $message = $this->createPostMessage($group, $author->id());
      $this->enqueue((int) $recipientB->id(), $message, 'daily');
    }

    $this->assertSame(5, $this->queue()->count(), 'Sanity: 5 entries enqueued before delivery.');

    $this->deliver('immediately');

    $this->assertSame(
      3,
      $this->fileSinkLineCount(),
      'Exactly 3 file-sink lines are written — one per immediately entry.',
    );
    $this->assertSame(
      3,
      $this->watchdogCount(),
      'Exactly 3 do_notifications watchdog rows are written — one per immediately entry.',
    );
    $this->assertSame(
      2,
      $this->queue()->count(),
      'The 2 daily entries remain queued; --type=immediately drains only immediately entries.',
    );
  }

  /**
   * AC #2/#3: `--type=all` (default) delivers immediately + BOTH digests.
   *
   * 2 immediately + 2 daily (same recipient) + 1 weekly enqueued. Running
   * the default (`all`) worker must produce: 2 individual deliveries for the
   * immediately entries, 1 grouped daily-digest delivery (both daily entries
   * share a recipient, so they collapse to ONE LogMailer call), and 1
   * weekly-digest delivery — 4 total deliveries, queue left empty.
   */
  public function testDeliverAllTypeDrainsEverythingIncludingDigests(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $immediateRecipient = $this->createUser();
    $dailyRecipient = $this->createUser();
    $weeklyRecipient = $this->createUser();

    for ($i = 0; $i < 2; $i++) {
      $message = $this->createPostMessage($group, $author->id());
      $this->enqueue((int) $immediateRecipient->id(), $message, 'immediately');
    }

    for ($i = 0; $i < 2; $i++) {
      $message = $this->createPostMessage($group, $author->id());
      $this->enqueue((int) $dailyRecipient->id(), $message, 'daily');
    }

    $weeklyMessage = $this->createPostMessage($group, $author->id());
    $this->enqueue((int) $weeklyRecipient->id(), $weeklyMessage, 'weekly');

    $this->assertSame(5, $this->queue()->count(), 'Sanity: 5 entries enqueued before delivery.');

    $this->deliver('all');

    $this->assertSame(
      4,
      $this->fileSinkLineCount(),
      '4 deliveries total: 2 individual immediately + 1 grouped daily digest + 1 weekly digest.',
    );
    $this->assertSame(
      4,
      $this->watchdogCount(),
      '4 do_notifications watchdog rows, one per LogMailer delivery call.',
    );
    $this->assertSame(
      0,
      $this->queue()->count(),
      '--type=all (default) drains every frequency; the queue is left empty.',
    );

    $contents = (string) file_get_contents($this->logFilePath);
    $this->assertStringContainsString(
      'template=digest_daily',
      $contents,
      'The grouped daily digest delivery logs template=digest_daily.',
    );
    $this->assertStringContainsString(
      'template=digest_weekly',
      $contents,
      'The weekly digest delivery logs template=digest_weekly.',
    );
  }

  /**
   * AC #4: idempotency — two runs against an empty queue both no-op cleanly.
   */
  public function testDeliverIsIdempotentOnEmptyQueue(): void {
    $this->assertSame(0, $this->queue()->count(), 'Sanity: queue starts empty.');

    $this->deliver('all');

    $lines_after_first_run = $this->fileSinkLineCount();
    $watchdog_after_first_run = $this->watchdogCount();
    $this->assertSame(0, $lines_after_first_run, 'A run against an empty queue writes no file-sink lines.');
    $this->assertSame(0, $watchdog_after_first_run, 'A run against an empty queue writes no watchdog rows.');

    // Second run must be equally inert — no exception, no new writes.
    $this->deliver('all');

    $this->assertSame(
      $lines_after_first_run,
      $this->fileSinkLineCount(),
      'A second run against an empty queue writes nothing new (idempotent).',
    );
    $this->assertSame(
      $watchdog_after_first_run,
      $this->watchdogCount(),
      'A second run against an empty queue writes no new watchdog rows (idempotent).',
    );
  }

  /**
   * AC #5: a drained entry whose Message no longer exists is skipped+warned.
   *
   * The stale entry (mid = 99999, which was never saved) must not produce a
   * LogMailer delivery, but the worker must still drain it (not leave a
   * permanently stuck entry) and log a warning to the `do_notifications`
   * watchdog channel.
   */
  public function testDeliverSkipsMissingMessageWithWarning(): void {
    $recipient = $this->createUser();

    $this->queue()->enqueue([
      'uid' => (int) $recipient->id(),
      'mid' => 99999,
      'template' => 'activity_post_created',
      'frequency' => 'immediately',
      'day' => '2026-07-20',
    ]);
    $this->assertSame(1, $this->queue()->count(), 'Sanity: the stale entry is enqueued.');

    $this->deliver('immediately');

    $this->assertSame(
      0,
      $this->fileSinkLineCount(),
      'No LogMailer delivery is made for an entry whose Message no longer exists.',
    );
    $this->assertSame(
      0,
      $this->queue()->count(),
      'The stale entry is still drained (removed from the queue), not left stuck.',
    );

    // A warning (RfcLogLevel::WARNING = 4) was logged to the do_notifications
    // channel for the skipped entry.
    $warning_count = (int) \Drupal::database()
      ->select('watchdog', 'w')
      ->condition('type', 'do_notifications')
      ->condition('severity', 4)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(
      1,
      $warning_count,
      'Exactly one do_notifications warning is logged for the missing Message.',
    );
  }

  /**
   * AC #3 digest grouping: daily entries collapse to ONE call per recipient.
   *
   * 3 daily entries for recipient A (3 distinct messages) + 2 daily entries
   * for recipient B (2 distinct messages). Running `--type=daily` must
   * produce exactly 2 LogMailer deliveries — one per recipient — each
   * carrying `template=digest_daily` and `mid` equal to the FIRST message id
   * enqueued for that recipient (brief AC #3's "mid: first-mid" contract).
   */
  public function testDeliverGroupsDailyDigestByRecipient(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipientA = $this->createUser();
    $recipientB = $this->createUser();

    $recipientAMids = [];
    for ($i = 0; $i < 3; $i++) {
      $message = $this->createPostMessage($group, $author->id());
      $recipientAMids[] = (int) $message->id();
      $this->enqueue((int) $recipientA->id(), $message, 'daily');
    }

    $recipientBMids = [];
    for ($i = 0; $i < 2; $i++) {
      $message = $this->createPostMessage($group, $author->id());
      $recipientBMids[] = (int) $message->id();
      $this->enqueue((int) $recipientB->id(), $message, 'daily');
    }

    $this->deliver('daily');

    $this->assertSame(
      2,
      $this->fileSinkLineCount(),
      'Exactly 2 LogMailer deliveries: one grouped digest per recipient, not one per message.',
    );
    $this->assertSame(0, $this->queue()->count(), 'All 5 daily entries are drained.');

    $contents = (string) file_get_contents($this->logFilePath);
    $lines = array_values(array_filter(explode("\n", trim($contents))));

    $found_a = FALSE;
    $found_b = FALSE;
    foreach ($lines as $line) {
      $this->assertStringContainsString('template=digest_daily', $line, 'Every daily-digest line uses template=digest_daily.');
      if (str_contains($line, sprintf('uid=%d', $recipientA->id()))) {
        $this->assertStringContainsString(
          sprintf('mid=%d', $recipientAMids[0]),
          $line,
          "Recipient A's digest line carries the FIRST message id enqueued for them.",
        );
        $found_a = TRUE;
      }
      if (str_contains($line, sprintf('uid=%d', $recipientB->id()))) {
        $this->assertStringContainsString(
          sprintf('mid=%d', $recipientBMids[0]),
          $line,
          "Recipient B's digest line carries the FIRST message id enqueued for them.",
        );
        $found_b = TRUE;
      }
    }
    $this->assertTrue($found_a, 'Recipient A received exactly one grouped digest delivery.');
    $this->assertTrue($found_b, 'Recipient B received exactly one grouped digest delivery.');
  }

}
