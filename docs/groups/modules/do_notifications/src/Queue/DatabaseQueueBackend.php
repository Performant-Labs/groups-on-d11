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
    $this->database->merge(self::TABLE)
      ->keys([
        'uid' => $item['uid'],
        'mid' => $item['mid'],
        'frequency' => $item['frequency'],
        'day' => $item['day'],
      ])
      ->fields([
        'template' => $item['template'],
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function drain(): array {
    $transaction = $this->database->startTransaction();
    try {
      $rows = $this->database->select(self::TABLE, 'q')
        ->fields('q', ['uid', 'mid', 'template', 'frequency', 'day'])
        ->orderBy('id', 'ASC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      $this->database->delete(self::TABLE)->execute();
    }
    catch (\Exception $e) {
      // Roll back the read+delete pair together: a caller must never see a
      // partial drain (rows returned but not removed, or vice versa).
      $transaction->rollBack();
      throw $e;
    }

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        'uid' => (int) $row['uid'],
        'mid' => (int) $row['mid'],
        'template' => $row['template'],
        'frequency' => $row['frequency'],
        'day' => $row['day'],
      ];
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

}
