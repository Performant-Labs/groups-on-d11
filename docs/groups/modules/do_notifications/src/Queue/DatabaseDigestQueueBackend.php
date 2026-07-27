<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Queue;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * DB-backed {@see DigestQueueBackendInterface} implementation.
 *
 * Part of the Notifications epic #237, N-6 (#234). Persists aggregated
 * digest rows in the real `do_notifications_digest_queue` DB table (see
 * `do_notifications.install`) — the service `do_notifications.digest_queue`
 * resolves to this class (see `do_notifications.services.yml`).
 *
 * No dedup/merge semantics here (unlike `DatabaseQueueBackend`'s
 * `Connection::merge()` upsert): every `enqueue()` is a plain INSERT, since
 * a digest row has no unique tuple to collapse on — see
 * {@see DigestQueueBackendInterface} for the full rationale.
 */
class DatabaseDigestQueueBackend implements DigestQueueBackendInterface {

  /**
   * The name of the DB table this backend persists entries in.
   */
  private const TABLE = 'do_notifications_digest_queue';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enqueue(int $uid, string $window, string $subject, string $bodyText, string $bodyHtml, int $sendAt): int {
    $id = $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'window' => $window,
        'subject' => $subject,
        'body_text' => $bodyText,
        'body_html' => $bodyHtml,
        'send_at' => $sendAt,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();

    return (int) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    $rows = $this->database->select(self::TABLE, 'q')
      ->fields('q', ['id', 'uid', 'window', 'subject', 'body_text', 'body_html', 'send_at', 'created'])
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        'id' => (int) $row['id'],
        'uid' => (int) $row['uid'],
        'window' => $row['window'],
        'subject' => $row['subject'],
        'body_text' => $row['body_text'],
        'body_html' => $row['body_html'],
        'send_at' => (int) $row['send_at'],
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
      // Guard against a bare `DELETE FROM do_notifications_digest_queue`
      // with no WHERE clause — an empty IN-clause must delete nothing, not
      // everything.
      return;
    }

    $this->database->delete(self::TABLE)
      ->condition('id', $ids, 'IN')
      ->execute();
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
