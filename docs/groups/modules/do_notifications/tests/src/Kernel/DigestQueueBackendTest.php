<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for the digest-payload queue (#234, N-6).
 *
 * Part of the Notifications epic #237, N-6. Pins the
 * `Drupal\do_notifications\Queue\DatabaseDigestQueueBackend` contract against
 * its narrow `Drupal\do_notifications\Queue\DigestQueueBackendInterface`
 * (brief §"New objects" #2/#3): `enqueue()` persists one aggregated-digest
 * row (uid, window, subject, body_text, body_html, send_at) into the NEW
 * `do_notifications_digest_queue` table, `all()` reads without deleting,
 * `count()` reports the row count, and `deleteByIds()` removes specific rows
 * by id (the claim-then-delete idempotency shape A's finding #3 asked T-red
 * to lock in place of a combined `drain()`).
 *
 * These are RED (Phase 4) tests: `DatabaseDigestQueueBackend`,
 * `DigestQueueBackendInterface`, the `do_notifications_digest_queue` schema,
 * and the `do_notifications.digest_queue` service do not exist yet. F
 * implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class DigestQueueBackendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flag', 'do_notifications'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('do_notifications', [
      'do_notifications_queue',
      'do_notifications_digest_queue',
    ]);
  }

  /**
   * Returns the do_notifications.digest_queue service under test.
   *
   * @return \Drupal\do_notifications\Queue\DigestQueueBackendInterface
   *   The digest queue backend service.
   */
  private function backend(): object {
    return \Drupal::service('do_notifications.digest_queue');
  }

  /**
   * Counts rows directly in the `do_notifications_digest_queue` DB table.
   *
   * A ground-truth check independent of the backend's own count(), matching
   * the pattern established by DatabaseQueueBackendTest::rawRowCount().
   *
   * @return int
   *   The row count.
   */
  private function rawRowCount(): int {
    return (int) \Drupal::database()
      ->select('do_notifications_digest_queue', 'q')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * enqueue() persists a real row and returns its id; all()/count() agree.
   *
   * Pins: enqueue() returns the inserted row's id (per the brief's
   * `enqueue(...): int` signature); the row is independently verifiable via
   * a raw DB count (not merely the backend's own count()); all() returns the
   * full payload without deleting anything.
   */
  public function testEnqueuePersistsRowAndReturnsId(): void {
    $backend = $this->backend();

    $id = $backend->enqueue(7, 'daily', 'Your daily summary — 3 updates', 'plain text body', '<p>html body</p>', 1700000000);

    $this->assertIsInt($id, 'enqueue() returns the inserted row id as an int.');
    $this->assertGreaterThan(0, $id, 'The returned id is a positive row identifier.');

    $this->assertSame(1, $backend->count(), 'count() reports 1 after a single enqueue().');
    $this->assertSame(1, $this->rawRowCount(), 'The DB table itself has 1 row (not merely an in-memory count).');

    $rows = $backend->all();
    $this->assertCount(1, $rows, 'all() returns exactly the 1 enqueued row.');

    $row = reset($rows);
    $this->assertSame(7, (int) $row['uid'], 'Persisted row preserves the uid.');
    $this->assertSame('daily', $row['window'], 'Persisted row preserves the window.');
    $this->assertSame('Your daily summary — 3 updates', $row['subject'], 'Persisted row preserves the subject.');
    $this->assertSame('plain text body', $row['body_text'], 'Persisted row preserves body_text.');
    $this->assertSame('<p>html body</p>', $row['body_html'], 'Persisted row preserves body_html.');
    $this->assertSame(1700000000, (int) $row['send_at'], 'Persisted row preserves send_at.');

    // all() must NOT delete — a second call still sees the row.
    $this->assertSame(1, $backend->count(), 'all() is read-only: count() is unchanged after calling it.');
    $this->assertSame(1, $this->rawRowCount(), 'all() is read-only: the DB table still has the row.');
  }

  /**
   * Multiple enqueue() calls each persist a distinct row (no dedup).
   *
   * Unlike QueueBackendInterface::enqueue(), the digest queue has no
   * dedup-tuple contract (a digest row has no `mid`); two enqueue() calls,
   * even for the same uid/window, must both persist.
   */
  public function testMultipleEnqueuesPersistDistinctRows(): void {
    $backend = $this->backend();

    $id1 = $backend->enqueue(1, 'daily', 'Subject A', 'text A', '<p>A</p>', 1700000000);
    $id2 = $backend->enqueue(1, 'daily', 'Subject B', 'text B', '<p>B</p>', 1700000100);

    $this->assertNotSame($id1, $id2, 'Two enqueue() calls for the same uid/window produce distinct row ids.');
    $this->assertSame(2, $backend->count(), 'Both rows persist independently (no dedup on the digest queue).');
    $this->assertSame(2, $this->rawRowCount());
  }

  /**
   * deleteByIds() removes only the specified rows, leaving others intact.
   *
   * Pins the claim-then-delete idempotency shape (A's finding #3): a caller
   * reads via all(), decides what to delete, then explicitly deletes by id —
   * distinct from a combined read-and-delete drain().
   */
  public function testDeleteByIdsRemovesOnlySpecifiedRows(): void {
    $backend = $this->backend();

    $id1 = $backend->enqueue(1, 'daily', 'Subject A', 'text A', '<p>A</p>', 1700000000);
    $id2 = $backend->enqueue(2, 'daily', 'Subject B', 'text B', '<p>B</p>', 1700000000);
    $id3 = $backend->enqueue(3, 'daily', 'Subject C', 'text C', '<p>C</p>', 1700000000);

    $backend->deleteByIds([$id1, $id3]);

    $this->assertSame(1, $backend->count(), 'Only the 2 specified rows were deleted; 1 remains.');
    $this->assertSame(1, $this->rawRowCount());

    $remaining = $backend->all();
    $row = reset($remaining);
    $this->assertSame((int) $id2, (int) $row['id'], 'The row NOT passed to deleteByIds() is the one that survives.');
    $this->assertSame(2, (int) $row['uid']);
  }

  /**
   * deleteByIds() with an empty array is a safe no-op.
   */
  public function testDeleteByIdsWithEmptyArrayIsNoOp(): void {
    $backend = $this->backend();
    $backend->enqueue(1, 'daily', 'Subject A', 'text A', '<p>A</p>', 1700000000);

    $backend->deleteByIds([]);

    $this->assertSame(1, $backend->count(), 'An empty deleteByIds() call deletes nothing.');
  }

}
