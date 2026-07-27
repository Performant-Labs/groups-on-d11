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
 * #234 (N-6) extends this suite with `claimDaily()` and `deleteByIds()`
 * coverage — the two new `QueueBackendInterface` methods the daily digest
 * command claims-then-deletes through (brief §"Extensions to existing
 * interface"; claim and delete are deliberately separate calls so a mid-run
 * failure after claimDaily() but before deleteByIds() is idempotent on the
 * next run — the un-deleted rows are simply claimed again).
 *
 * #235 (N-7) extends this suite further with `claimWeekly()` coverage — the
 * sibling method the weekly digest worker claims through, in the same
 * frequency-AND-threshold idiom as `claimDaily()`.
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
   * Inserts a raw row directly, bypassing enqueue()'s merge/dedup semantics.
   *
   * `claimDaily()` filters on `frequency` and `created`, both of which
   * `enqueue()` either doesn't accept as a controllable input (created is
   * always `time.getRequestTime()`, i.e. "now") or would require a real Time
   * service mock to backdate. Inserting directly lets these tests pin
   * arbitrary `created` timestamps deterministically without depending on a
   * TestTime seam that does not exist in this codebase.
   *
   * @return int
   *   The inserted row's id.
   */
  private function insertRawRow(int $uid, int $mid, string $template, string $frequency, string $day, int $created): int {
    return (int) \Drupal::database()->insert('do_notifications_queue')
      ->fields([
        'uid' => $uid,
        'mid' => $mid,
        'template' => $template,
        'frequency' => $frequency,
        'day' => $day,
        'created' => $created,
      ])
      ->execute();
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

  /**
   * claimDaily() returns only 'daily' rows older than the threshold.
   *
   * Pins the exact filter `claimDaily(int $olderThan)` must apply:
   * `frequency = 'daily' AND created < :olderThan`. Rows that are 'daily' but
   * inside the window, and rows outside the window but a different
   * frequency, must both be excluded — proving the two predicates are
   * ANDed, not ORed.
   */
  public function testClaimDailyFiltersOnFrequencyAndThreshold(): void {
    $backend = $this->backend();
    $threshold = 1700100000;

    $old_daily_id = $this->insertRawRow(1, 1, 'activity_post_created', 'daily', '2026-07-01', $threshold - 100);
    $this->insertRawRow(1, 2, 'activity_post_created', 'daily', '2026-07-02', $threshold + 100);
    $this->insertRawRow(2, 3, 'activity_post_created', 'immediately', '2026-07-01', $threshold - 100);
    $this->insertRawRow(3, 4, 'activity_post_created', 'weekly', '2026-07-01', $threshold - 100);

    $claimed = $backend->claimDaily($threshold);

    $this->assertCount(1, $claimed, 'Only the daily row older than the threshold is claimed.');
    $claimed_row = reset($claimed);
    $this->assertSame($old_daily_id, (int) $claimed_row['id'], 'The claimed row is the old daily row, identified by its id.');
    $this->assertSame('daily', $claimed_row['frequency']);

    // claimDaily() does NOT delete — the rows must still be in the table.
    $this->assertSame(4, $this->rawRowCount(), 'claimDaily() is read-only: all 4 rows remain in the DB table.');
  }

  /**
   * claimWeekly() returns only 'weekly' rows older than the threshold.
   *
   * Part of #235 (N-7). Mirror of {@see self::testClaimDailyFiltersOnFrequencyAndThreshold()}
   * for the sibling method: pins the exact filter `claimWeekly(int $olderThan)`
   * must apply: `frequency = 'weekly' AND created < :olderThan`. A row that
   * is 'weekly' but inside the window, and a row outside the window but a
   * different frequency, must both be excluded — proving the two predicates
   * are ANDed, not ORed.
   */
  public function testClaimWeeklyFiltersOnFrequencyAndThreshold(): void {
    $backend = $this->backend();
    $threshold = 1700100000;

    $old_weekly_id = $this->insertRawRow(1, 1, 'activity_post_created', 'weekly', '2026-07-01', $threshold - 100);
    $this->insertRawRow(1, 2, 'activity_post_created', 'weekly', '2026-07-02', $threshold + 100);
    $this->insertRawRow(2, 3, 'activity_post_created', 'immediately', '2026-07-01', $threshold - 100);
    $this->insertRawRow(3, 4, 'activity_post_created', 'daily', '2026-07-01', $threshold - 100);

    $claimed = $backend->claimWeekly($threshold);

    $this->assertCount(1, $claimed, 'Only the weekly row older than the threshold is claimed.');
    $claimed_row = reset($claimed);
    $this->assertSame($old_weekly_id, (int) $claimed_row['id'], 'The claimed row is the old weekly row, identified by its id.');
    $this->assertSame('weekly', $claimed_row['frequency']);

    // claimWeekly() does NOT delete — the rows must still be in the table.
    $this->assertSame(4, $this->rawRowCount(), 'claimWeekly() is read-only: all 4 rows remain in the DB table.');
  }

  /**
   * claimDaily() groups multiple users' rows, ordered by (uid, created).
   */
  public function testClaimDailyReturnsRowsAcrossMultipleUsers(): void {
    $backend = $this->backend();
    $threshold = 1700100000;

    $this->insertRawRow(7, 1, 'activity_post_created', 'daily', '2026-07-01', $threshold - 300);
    $this->insertRawRow(7, 2, 'activity_post_created', 'daily', '2026-07-01', $threshold - 200);
    $this->insertRawRow(2, 3, 'activity_post_created', 'daily', '2026-07-01', $threshold - 100);

    $claimed = $backend->claimDaily($threshold);

    $this->assertCount(3, $claimed, 'All 3 daily rows older than the threshold are claimed, across both uids.');
    $uids = array_map(static fn (array $row): int => (int) $row['uid'], $claimed);
    sort($uids);
    $this->assertSame([2, 7, 7], $uids, 'Claimed rows cover both recipients (uid 7 x2, uid 2 x1).');
  }

  /**
   * deleteByIds() removes exactly the specified rows and no others.
   *
   * Pins the claim-then-delete split (brief §"Extensions to existing
   * interface"): claimDaily() and deleteByIds() are separate calls so a
   * caller can render/enqueue a digest BEFORE deleting the source rows,
   * making a mid-run failure idempotent on retry.
   */
  public function testDeleteByIdsRemovesOnlySpecifiedRows(): void {
    $backend = $this->backend();

    $id1 = $this->insertRawRow(1, 1, 'activity_post_created', 'daily', '2026-07-01', 1700000000);
    $id2 = $this->insertRawRow(1, 2, 'activity_post_created', 'daily', '2026-07-01', 1700000000);
    $id3 = $this->insertRawRow(2, 3, 'activity_post_created', 'daily', '2026-07-01', 1700000000);

    $backend->deleteByIds([$id1, $id3]);

    $this->assertSame(1, $this->rawRowCount(), 'Only the 2 specified rows were deleted; 1 remains.');

    $remaining = $backend->drain();
    $this->assertCount(1, $remaining);
    $remaining_row = reset($remaining);
    $this->assertSame(2, (int) $remaining_row['mid'], 'The surviving row is the one NOT passed to deleteByIds().');
    unset($id2);
  }

  /**
   * deleteByIds() with an empty array is a safe no-op.
   */
  public function testDeleteByIdsWithEmptyArrayIsNoOp(): void {
    $backend = $this->backend();
    $this->insertRawRow(1, 1, 'activity_post_created', 'daily', '2026-07-01', 1700000000);

    $backend->deleteByIds([]);

    $this->assertSame(1, $this->rawRowCount(), 'An empty deleteByIds() call deletes nothing.');
  }

}
