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
 *
 * #234 (N-6) adds `claimDaily()`/`deleteByIds()` in-memory equivalents so
 * unit tests that construct this mock directly (rather than going through
 * the DB-backed service) don't break when code starts depending on the two
 * new `QueueBackendInterface` methods. Entries are keyed by a synthetic
 * sequential id (mirroring `DatabaseQueueBackend`'s auto-increment PK) so
 * `claimDaily()` can return each row's `id` and `deleteByIds()` can remove
 * specific entries by that id.
 *
 * #235 (N-7) adds `claimWeekly()`: a literal in-memory sibling of
 * `claimDaily()`, filtering on `'weekly'` instead of `'daily'`.
 */
class MockQueueBackend implements QueueBackendInterface {

  /**
   * The queued entries, in enqueue order, keyed by a synthetic sequential id.
   *
   * Keyed (rather than a plain list) so `claimDaily()` can return each row's
   * `id` and `deleteByIds()` can remove specific entries by that same id —
   * mirroring `DatabaseQueueBackend`'s real auto-increment primary key.
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
   * The next synthetic id to assign to a newly enqueued item.
   */
  private int $nextId = 1;

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
    $item += ['created' => 0];
    $this->items[$this->nextId] = $item;
    $this->nextId++;
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
      $items = array_values($this->items);
      $this->items = [];
      $this->seenKeys = [];
      return $items;
    }

    $matched = [];
    foreach ($this->items as $id => $item) {
      if ($item['frequency'] === $frequency) {
        $matched[] = $item;
        $key = implode(':', [
          $item['uid'],
          $item['mid'],
          $item['frequency'],
          $item['day'],
        ]);
        unset($this->seenKeys[$key], $this->items[$id]);
      }
    }

    return $matched;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->items);
  }

  /**
   * {@inheritdoc}
   */
  public function claimDaily(int $olderThan): array {
    $claimed = [];
    foreach ($this->items as $id => $item) {
      if ($item['frequency'] === 'daily' && ($item['created'] ?? 0) < $olderThan) {
        $claimed[] = ['id' => $id] + $item;
      }
    }

    usort(
      $claimed,
      static fn(array $a, array $b): int => [$a['uid'], $a['created']] <=> [$b['uid'], $b['created']],
    );

    return $claimed;
  }

  /**
   * {@inheritdoc}
   */
  public function claimWeekly(int $olderThan): array {
    $claimed = [];
    foreach ($this->items as $id => $item) {
      if ($item['frequency'] === 'weekly' && ($item['created'] ?? 0) < $olderThan) {
        $claimed[] = ['id' => $id] + $item;
      }
    }

    usort(
      $claimed,
      static fn(array $a, array $b): int => [$a['uid'], $a['created']] <=> [$b['uid'], $b['created']],
    );

    return $claimed;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByIds(array $ids): void {
    if (empty($ids)) {
      // Match DatabaseQueueBackend's guard: an empty id list deletes
      // nothing, not everything.
      return;
    }

    foreach ($ids as $id) {
      unset($this->items[$id]);
    }
  }

}
