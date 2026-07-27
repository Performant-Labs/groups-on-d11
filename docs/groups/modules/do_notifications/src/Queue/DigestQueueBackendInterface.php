<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Queue;

/**
 * A swappable backend for aggregated, per-recipient digest email payloads.
 *
 * Part of the Notifications epic #237, N-6 (#234). Backs the
 * `do_notifications_digest_queue` DB table (see `do_notifications.install`)
 * — the destination the daily digest command
 * ({@see \Drupal\do_notifications\Commands\DigestCommands::digestDaily()})
 * enqueues one fully rendered digest per recipient into, for the
 * (not-yet-merged) N-3 delivery worker to eventually consume and send.
 *
 * Deliberately NOT the same interface as {@see QueueBackendInterface}: a
 * digest row has no single `mid` (it aggregates N source rows from that
 * queue) and carries the fully rendered `subject`/`body_text`/`body_html`
 * payload rather than a pointer to a Message entity, so the per-message
 * queue's `(uid, mid, frequency, day)` dedup contract cannot express it.
 * There is also no dedup here: two `enqueue()` calls, even for the same
 * uid/window, both persist as distinct rows — a digest queue entry is a
 * point-in-time rendered artifact, not a routing decision to collapse.
 *
 * `all()` + `deleteByIds()` (NOT a combined `drain()`) mirror the same
 * claim-then-delete idempotency shape `QueueBackendInterface::claimDaily()`
 * + `deleteByIds()` established: N-3 will read via `all()`, attempt
 * delivery, and only delete the ids it successfully sent, so a partial
 * delivery failure leaves the un-sent rows in place to retry on the next
 * run rather than losing them to a combined read-and-delete.
 */
interface DigestQueueBackendInterface {

  /**
   * Enqueues one aggregated digest for a single recipient.
   *
   * No dedup: every call persists a new, distinct row, even if an identical
   * uid/window pair was already enqueued.
   *
   * @param int $uid
   *   The recipient user id.
   * @param string $window
   *   The digest window this row was aggregated for: 'daily' or 'weekly'.
   * @param string $subject
   *   The rendered digest email subject line.
   * @param string $bodyText
   *   The rendered plain-text digest email body.
   * @param string $bodyHtml
   *   The rendered HTML digest email body.
   * @param int $sendAt
   *   A UNIX timestamp: the earliest time this digest may be sent.
   *
   * @return int
   *   The inserted row's id.
   */
  public function enqueue(int $uid, string $window, string $subject, string $bodyText, string $bodyHtml, int $sendAt): int;

  /**
   * Returns every currently queued digest, without removing them.
   *
   * @return array<int, array>
   *   Every queued digest row, each shaped `['id', 'uid', 'window',
   *   'subject', 'body_text', 'body_html', 'send_at', 'created']`. Empty
   *   when nothing is queued.
   */
  public function all(): array;

  /**
   * Deletes the digest queue entries with the given ids.
   *
   * Pairs with {@see self::all()}: a caller reads the queue, does its
   * (potentially fallible) delivery work, and only then deletes the ids it
   * successfully sent. Passing an empty array is a safe no-op — it must
   * never delete every row in the table.
   *
   * @param array<int, int> $ids
   *   The `id` column values to delete. An empty array deletes nothing.
   */
  public function deleteByIds(array $ids): void;

  /**
   * Returns the number of currently queued digests, without removing them.
   *
   * @return int
   *   The digest queue's current entry count.
   */
  public function count(): int;

}
