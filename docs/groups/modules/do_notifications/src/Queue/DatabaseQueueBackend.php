<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Queue;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * DB-backed {@see QueueBackendInterface} implementation.
 *
 * Part of the Notifications epic #237, N-1 (#229). Persists entries in the
 * real `do_notifications_queue` DB table (see `do_notifications.install`),
 * replacing N-2's (#230) request-lifetime `MockQueueBackend` as the default
 * — see `do_notifications.services.yml` for the swap point.
 *
 * Implements the SAME dedup-on-(uid, mid, frequency, day)-tuple contract as
 * `MockQueueBackend`, but enforces it via the table's unique key and
 * `Connection::merge()` rather than an in-memory `$seenKeys` map: `merge()`
 * is the idiomatic Drupal upsert, portable across MySQL/PostgreSQL, and lets
 * the DB's own unique constraint silently collapse a repeat tuple into a
 * no-op UPDATE rather than requiring exception-as-control-flow around a
 * duplicate-key INSERT failure.
 *
 * N-5 (#233) added the `send_at` field to the payload / column: `enqueue()`
 * writes it when present, `drain()` returns it when populated. Since
 * `send_at` is NOT part of the dedup tuple (see QueueBackendInterface's
 * docblock), a repeat enqueue of the same tuple with a DIFFERENT send_at
 * updates the row's send_at via merge()->fields() — the last writer wins,
 * matching the "silent no-op" contract's intent (nothing new is added).
 *
 * #234 (N-6) adds `claimDaily()` (read-only SELECT filtered on frequency +
 * created) and `deleteByIds()` (a plain `DELETE ... WHERE id IN (...)`) —
 * see {@see QueueBackendInterface}'s class docblock for why these are two
 * separate calls rather than one combined claim-and-delete.
 *
 * #235 (N-7) adds `claimWeekly()`: a literal sibling of `claimDaily()` with
 * `frequency = 'weekly'` in place of `frequency = 'daily'`. `deleteByIds()`
 * is reused verbatim — it was already frequency-agnostic.
 */
class DatabaseQueueBackend implements QueueBackendInterface {

  /**
   * The name of the DB table this backend persists entries in.
   */
  private const TABLE = 'do_notifications_queue';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enqueue(array $item): void {
    // send_at is optional per the interface docblock: callers that predate
    // N-5 or use a mock payload may omit it. Store NULL when absent so the
    // schema's `not null => FALSE` accepts the write.
    $fields = [
      'template' => $item['template'],
      'created' => $this->time->getRequestTime(),
      'send_at' => $item['send_at'] ?? NULL,
    ];

    $this->database->merge(self::TABLE)
      ->keys([
        'uid' => $item['uid'],
        'mid' => $item['mid'],
        'frequency' => $item['frequency'],
        'day' => $item['day'],
      ])
      ->fields($fields)
      ->execute();
  }

  /**
   * {@inheritdoc}
   *
   * Extended for #231 (N-3): when `$frequency` is given, the read is
   * WHERE-filtered on the `frequency` column and the delete removes ONLY the
   * rows just read — by their primary-key `id`, not by re-applying the
   * frequency filter to a fresh DELETE — so a row inserted between the SELECT
   * and the DELETE (same transaction, but defensive against any future
   * change to isolation level) can never be swept up by a filter it never
   * matched at read time. Passing no `$frequency` reproduces the pre-#231
   * "delete everything just read" behavior exactly.
   */
  public function drain(?string $frequency = NULL): array {
    $transaction = $this->database->startTransaction();
    try {
      $select = $this->database->select(self::TABLE, 'q')
        ->fields('q', ['id', 'uid', 'mid', 'template', 'frequency', 'day', 'send_at']);
      if ($frequency !== NULL) {
        $select->condition('frequency', $frequency);
      }
      $rows = $select
        ->orderBy('id', 'ASC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
      if (!empty($ids)) {
        $this->database->delete(self::TABLE)
          ->condition('id', $ids, 'IN')
          ->execute();
      }
    }
    catch (\Exception $e) {
      // Roll back the read+delete pair together: a caller must never see a
      // partial drain (rows returned but not removed, or vice versa).
      $transaction->rollBack();
      throw $e;
    }

    $items = [];
    foreach ($rows as $row) {
      $item = [
        'uid' => (int) $row['uid'],
        'mid' => (int) $row['mid'],
        'template' => $row['template'],
        'frequency' => $row['frequency'],
        'day' => $row['day'],
      ];
      // Only include send_at when populated (row written by an N-5-aware
      // enqueuer). Preserves BC for consumers that predate #233 and would
      // choke on an unexpected key — this array is documented as
      // shape-strict in the interface.
      if ($row['send_at'] !== NULL) {
        $item['send_at'] = (int) $row['send_at'];
      }
      $items[] = $item;
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return (int) $this->database->select(self::TABLE, 'q')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function claimDaily(int $olderThan): array {
    $rows = $this->database->select(self::TABLE, 'q')
      ->fields('q', ['id', 'uid', 'mid', 'template', 'frequency', 'day', 'created'])
      ->condition('frequency', 'daily')
      ->condition('created', $olderThan, '<')
      ->orderBy('uid', 'ASC')
      ->orderBy('created', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        'id' => (int) $row['id'],
        'uid' => (int) $row['uid'],
        'mid' => (int) $row['mid'],
        'template' => $row['template'],
        'frequency' => $row['frequency'],
        'day' => $row['day'],
        'created' => (int) $row['created'],
      ];
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function claimWeekly(int $olderThan): array {
    $rows = $this->database->select(self::TABLE, 'q')
      ->fields('q', ['id', 'uid', 'mid', 'template', 'frequency', 'day', 'created'])
      ->condition('frequency', 'weekly')
      ->condition('created', $olderThan, '<')
      ->orderBy('uid', 'ASC')
      ->orderBy('created', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        'id' => (int) $row['id'],
        'uid' => (int) $row['uid'],
        'mid' => (int) $row['mid'],
        'template' => $row['template'],
        'frequency' => $row['frequency'],
        'day' => $row['day'],
        'created' => (int) $row['created'],
      ];
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByIds(array $ids): void {
    if (empty($ids)) {
      // Guard against a bare `DELETE FROM do_notifications_queue` with no
      // WHERE clause — an empty IN-clause must delete nothing, not
      // everything.
      return;
    }

    $this->database->delete(self::TABLE)
      ->condition('id', $ids, 'IN')
      ->execute();
  }

}
