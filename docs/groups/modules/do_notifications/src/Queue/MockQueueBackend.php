<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Queue;

/**
 * In-memory {@see QueueBackendInterface} implementation.
 *
 * Was the shippable default for #230 (N-2) until `DatabaseQueueBackend`
 * (N-1, #229) landed and swapped in via `do_notifications.services.yml`.
 * Retained in the codebase for unit tests that want a fast, real-DB-free
 * `QueueBackendInterface` implementation. Backed by a single
 * request-lifetime array — entries do not persist across requests, which is
 * fine for tests but NOT a substitute for `DatabaseQueueBackend`, the real
 * DB-backed queue a production digest worker claims from.
 */
class MockQueueBackend implements QueueBackendInterface {

  /**
   * The queued entries, in enqueue order.
   *
   * @var array<int, array>
   */
  private array $items = [];

  /**
   * Dedup keys already seen, so a repeat enqueue() is a fast, cheap no-op.
   *
   * @var array<string, true>
   */
  private array $seenKeys = [];

  /**
   * {@inheritdoc}
   */
  public function enqueue(array $item): void {
    $key = implode(':', [
      $item['uid'],
      $item['mid'],
      $item['frequency'],
      $item['day'],
    ]);
    if (isset($this->seenKeys[$key])) {
      // Silent drop: an overlapping subscription resolving to the same
      // (uid, mid, frequency, day) tuple must not produce a second entry.
      return;
    }
    $this->seenKeys[$key] = TRUE;
    $this->items[] = $item;
  }

  /**
   * {@inheritdoc}
   *
   * Extended for #231 (N-3): when `$frequency` is given, only the matching
   * in-memory entries are removed and returned — the remaining entries (and
   * their dedup keys) are left in place so a later drain() for a different
   * frequency still sees them, and so a repeat enqueue() of an
   * already-queued (non-matching) tuple still correctly no-ops. Passing no
   * `$frequency` reproduces the pre-#231 "drain everything" behavior
   * exactly, including the full `$seenKeys` reset.
   */
  public function drain(?string $frequency = NULL): array {
    if ($frequency === NULL) {
      $items = $this->items;
      $this->items = [];
      $this->seenKeys = [];
      return $items;
    }

    $matched = [];
    $remaining = [];
    foreach ($this->items as $item) {
      if ($item['frequency'] === $frequency) {
        $matched[] = $item;
        $key = implode(':', [
          $item['uid'],
          $item['mid'],
          $item['frequency'],
          $item['day'],
        ]);
        unset($this->seenKeys[$key]);
      }
      else {
        $remaining[] = $item;
      }
    }
    $this->items = $remaining;

    return $matched;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->items);
  }

}
