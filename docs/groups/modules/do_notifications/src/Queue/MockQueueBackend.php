<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Queue;

/**
 * In-memory {@see QueueBackendInterface} implementation.
 *
 * The shippable default for #230 (N-2) until `DatabaseQueueBackend` (N-1,
 * #229) lands and swaps in via `do_notifications.services.yml`. Backed by a
 * single request-lifetime array — entries do not persist across requests,
 * which is acceptable for tests and local dev but NOT a substitute for the
 * real DB-backed queue a production digest worker would claim from.
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
   */
  public function drain(): array {
    $items = $this->items;
    $this->items = [];
    $this->seenKeys = [];
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->items);
  }

}
