<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' DB-backed queue (#229, N-1).
 *
 * Part of Notifications epic #237, N-1. Pins the
 * `Drupal\do_notifications\Queue\DatabaseQueueBackend` contract: it MUST
 * implement {@see \Drupal\do_notifications\Queue\QueueBackendInterface}
 * identically to N-2's `MockQueueBackend` (same dedup-on-tuple semantics,
 * same enqueue/drain/count signatures), but persist rows in the real
 * `do_notifications_queue` DB table rather than an in-request array — the
 * swap point named in `do_notifications.services.yml`
 * (`do_notifications.queue`).
 *
 * These are RED (Phase 4) tests: `DatabaseQueueBackend`, the
 * `do_notifications_queue` schema, and the `do_notifications.queue` service
 * swap do not exist yet. F implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class DatabaseQueueBackendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flag', 'do_notifications'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('do_notifications', ['do_notifications_queue']);
  }

  /**
   * Returns the do_notifications.queue service under test.
   *
   * This is the swap point named in the brief: once F lands the
   * `do_notifications.services.yml` class swap, this container lookup
   * resolves to `DatabaseQueueBackend` instead of N-2's `MockQueueBackend`.
   *
   * @return \Drupal\do_notifications\Queue\QueueBackendInterface
   *   The queue backend service.
   */
  private function backend(): object {
    return \Drupal::service('do_notifications.queue');
  }

  /**
   * Counts rows directly in the `do_notifications_queue` DB table.
   *
   * A ground-truth check independent of the backend's own count(), so a
   * backend that reported a correct in-memory count without actually
   * persisting rows would still be caught.
   *
   * @return int
   *   The row count.
   */
  private function rawRowCount(): int {
    return (int) \Drupal::database()
      ->select('do_notifications_queue', 'q')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Three distinct items enqueue, persist to the DB, and drain correctly.
   *
   * Pins: enqueue() persists a real row per distinct (uid, mid, frequency,
   * day) tuple (verified independently via a raw DB count, not just the
   * backend's own count()); drain() returns every item with its payload
   * intact and empties the table (both the backend's count() and a fresh raw
   * DB count agree the queue is empty afterward).
   */
  public function testEnqueueDrainCount(): void {
    $backend = $this->backend();

    $items = [
      ['uid' => 1, 'mid' => 10, 'template' => 'activity_post_created', 'frequency' => 'immediately', 'day' => '2026-07-01'],
      ['uid' => 2, 'mid' => 11, 'template' => 'activity_comment_created', 'frequency' => 'daily', 'day' => '2026-07-01'],
      ['uid' => 1, 'mid' => 12, 'template' => 'activity_post_created', 'frequency' => 'weekly', 'day' => '2026-07-02'],
    ];
    foreach ($items as $item) {
      $backend->enqueue($item);
    }

    $this->assertSame(3, $backend->count(), 'count() reports 3 after enqueuing 3 distinct items.');
    $this->assertSame(3, $this->rawRowCount(), 'The DB table itself has 3 rows (not merely an in-memory count).');

    $drained = $backend->drain();
    $this->assertCount(3, $drained, 'drain() returns all 3 queued items.');

    // Compare payloads by the dedup-relevant keys, order-independent.
    $normalize = static fn (array $item): array => [
      'uid' => $item['uid'],
      'mid' => $item['mid'],
      'template' => $item['template'],
      'frequency' => $item['frequency'],
      'day' => $item['day'],
    ];
    $expected = array_map($normalize, $items);
    $actual = array_map($normalize, $drained);
    sort($expected);
    sort($actual);
    $this->assertEquals($expected, $actual, 'Drained payloads match exactly what was enqueued.');

    $this->assertSame(0, $backend->count(), 'count() is 0 after drain().');
    $this->assertSame(0, $this->rawRowCount(), 'The DB table is empty after drain().');
  }

  /**
   * A second enqueue() with an identical (uid, mid, frequency, day) tuple
   * is silently dropped, matching MockQueueBackend's dedup contract exactly.
   */
  public function testEnqueueDedupOnTuple(): void {
    $backend = $this->backend();

    $item = [
      'uid' => 5,
      'mid' => 100,
      'template' => 'activity_post_created',
      'frequency' => 'immediately',
      'day' => '2026-07-01',
    ];
    $backend->enqueue($item);
    $backend->enqueue($item);

    $this->assertSame(1, $backend->count(), 'A repeat enqueue() of the identical tuple is a no-op.');
    $this->assertSame(1, $this->rawRowCount(), 'The DB table has exactly 1 row, not 2.');

    $drained = $backend->drain();
    $this->assertCount(1, $drained, 'drain() returns exactly 1 item for the deduped tuple.');
  }

}
