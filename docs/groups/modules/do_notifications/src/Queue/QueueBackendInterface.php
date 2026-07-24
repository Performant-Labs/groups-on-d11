<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Queue;

/**
 * A swappable backend for per-user, per-Message notification queue entries.
 *
 * Part of the Notifications epic #237, N-2 (#230). This deliberately does NOT
 * extend core's \Drupal\Core\Queue\QueueInterface — that interface's
 * claim/release/delete lifecycle models an anonymous work queue for a single
 * consumer, with no concept of the recipient the item is FOR. This backend's
 * contract is different on two axes core's queue does not address:
 *
 *   - DEDUPLICATION: enqueue() must silently collapse a second write with the
 *     same (uid, mid, frequency, day) key into a no-op, so a single user whose
 *     overlapping subscriptions (e.g. follow_content AND follow_term on the
 *     same referenced entity) both resolve to the same Message gets exactly
 *     ONE queue entry, not two. Core's QueueInterface::createItem() has no
 *     dedup concept at all — every call unconditionally appends.
 *   - PER-USER BROADCAST: a single routing decision (one Message) fans out to
 *     N recipients, each needing their OWN row (so a later digest/frequency
 *     worker can claim/send per-user). Core's queue models one item consumed
 *     by one worker execution, not one event broadcast to N addressees.
 *
 * `MockQueueBackend` (this story) is the shippable default;
 * `DatabaseQueueBackend` (N-1, #229) implements this SAME interface against a
 * real DB table — see `do_notifications.services.yml` for the swap point.
 */
interface QueueBackendInterface {

  /**
   * Enqueues one notification entry for a single recipient.
   *
   * Implementations MUST dedup on the (uid, mid, frequency, day) tuple:
   * a second enqueue() call with an identical tuple is silently dropped
   * (still returns normally, writes nothing new).
   *
   * @param array $item
   *   The entry to enqueue, shaped:
   *   - uid: (int) the recipient user id.
   *   - mid: (int) the routed Message's id.
   *   - template: (string) the Message's template (message_template) id.
   *   - frequency: (string) the recipient's notification frequency
   *     (e.g. 'immediately', 'daily', 'weekly').
   *   - day: (string) the enqueue date, `Y-m-d` format — used only as part of
   *     the dedup key, so an overlapping subscription on the SAME day
   *     collapses, while a genuinely later day's routing for the same
   *     (uid, mid, frequency) is a distinct entry.
   */
  public function enqueue(array $item): void;

  /**
   * Removes and returns every currently queued entry.
   *
   * A test/inspection helper (mirrors the "drain-and-assert" idiom already
   * established by {@see \Drupal\Tests\do_notifications\Kernel\GroupAddNotificationTest::drainQueue()}
   * for the core-queue path) — draining both reads and resets the recorded
   * state, so each assertion sees only entries written since the previous
   * drain.
   *
   * @return array<int, array>
   *   Every queued entry (see {@see self::enqueue()} for the payload shape),
   *   in enqueue order. Empty when nothing is queued.
   */
  public function drain(): array;

  /**
   * Returns the number of currently queued entries, without removing them.
   *
   * @return int
   *   The queue's current entry count.
   */
  public function count(): int;

}
