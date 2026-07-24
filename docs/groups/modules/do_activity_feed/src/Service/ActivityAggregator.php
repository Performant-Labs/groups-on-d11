<?php

declare(strict_types=1);

namespace Drupal\do_activity_feed\Service;

use Drupal\message\MessageInterface;

/**
 * Render-time aggregation: pairwise-consecutive fold within a 6h window.
 *
 * Issue #129 ST-7, brief §"Row model + aggregation", A-advisory #5
 * (verbatim): "Iterate rows in `created DESC`; fold row `n` into the
 * current bucket only if `(actor, template, group)` matches the bucket AND
 * `|current.created - previous_row_in_bucket.created| <= 6h`. A chain t=0
 * / t=5h / t=10h therefore folds into ONE bucket (both 5h gaps qualify). A
 * gap > 6h opens a new bucket."
 *
 * PAIRWISE-CONSECUTIVE, not anchor-based: each row is compared only to the
 * PREVIOUS row already folded into the current bucket — never to the
 * bucket's first/anchor row. This is the exact distinction
 * ActivityAggregationTest::
 * testThreePostsChainWithinPairwiseWindowAggregateAsOneBucket() pins: a
 * t=0/t=5h/t=10h chain must fold into ONE bucket even though t=0-to-t=10h
 * alone is a 10h gap (> 6h), because each step (t=0→t=5h, t=5h→t=10h) is
 * independently <=6h.
 *
 * INPUT CONTRACT (per handoff-T-red.md's own flagged ambiguity, resolved
 * here): `aggregate()` expects an ALREADY-FILTERED, single-aggregable-
 * template array of Messages, pre-sorted `created` DESC (newest first) —
 * matching the real feed Views query's own ordering and every one of T's
 * authored tests (`messagesByTemplateOrdered()` passes exactly one
 * template's Messages). Aggregation across templates is meaningless per the
 * bucket key `(actor, template, group)` — a caller with a mixed-template
 * result set is expected to pre-filter to one aggregable template per call
 * (ActivityFeedController does this once per aggregable template before
 * calling this service).
 */
class ActivityAggregator {

  /**
   * The aggregation window, in seconds (6 hours).
   */
  public const WINDOW_SECONDS = 6 * 3600;

  /**
   * Folds a created-DESC-ordered list of same-template Messages into buckets.
   *
   * @param \Drupal\message\MessageInterface[] $messages
   *   Messages of a SINGLE aggregable template, ordered created DESC
   *   (newest first) — the caller's responsibility per this class's own
   *   docblock.
   *
   * @return array
   *   A list of bucket arrays, each:
   *   - 'messages': \Drupal\message\MessageInterface[], the folded Messages
   *     in the SAME created-DESC order they were encountered (newest
   *     first).
   *   - 'count': int, the number of folded Messages (>=1).
   *   - 'actor_uid': int, the bucket key's actor uid.
   *   - 'template': string, the bucket key's message template.
   *   - 'group_id': int|null, the bucket key's field_group_id target, or
   *     NULL when the Message carries no group reference.
   *   - 'newest_created': int, the created timestamp of the bucket's first
   *     (newest) Message — the timestamp the aggregated row itself
   *     displays.
   *   Buckets are returned in the SAME order their first (newest) Message
   *   was encountered — i.e. newest-bucket-first, matching the input order.
   */
  public function aggregate(array $messages): array {
    $buckets = [];
    /** @var array{actor_uid: int, template: string, group_id: int|null, last_created: int}|null $currentKey */
    $currentKey = NULL;
    $currentBucketIndex = -1;

    foreach ($messages as $message) {
      if (!$message instanceof MessageInterface) {
        continue;
      }

      $actorUid = (int) $message->getOwnerId();
      $template = $message->bundle();
      $groupId = $this->groupIdOf($message);
      $created = (int) $message->getCreatedTime();

      $matchesCurrentBucket = $currentKey !== NULL
        && $currentKey['actor_uid'] === $actorUid
        && $currentKey['template'] === $template
        && $currentKey['group_id'] === $groupId
        && abs($currentKey['last_created'] - $created) <= self::WINDOW_SECONDS;

      if ($matchesCurrentBucket) {
        // Pairwise-consecutive fold: compare against the PREVIOUS row
        // already in the bucket (currentKey['last_created'], updated below
        // to THIS row's created time), never the bucket's original anchor.
        $buckets[$currentBucketIndex]['messages'][] = $message;
        $buckets[$currentBucketIndex]['count']++;
        $currentKey['last_created'] = $created;
        continue;
      }

      // Open a new bucket.
      $currentBucketIndex++;
      $buckets[$currentBucketIndex] = [
        'messages' => [$message],
        'count' => 1,
        'actor_uid' => $actorUid,
        'template' => $template,
        'group_id' => $groupId,
        'newest_created' => $created,
      ];
      $currentKey = [
        'actor_uid' => $actorUid,
        'template' => $template,
        'group_id' => $groupId,
        'last_created' => $created,
      ];
    }

    return array_values($buckets);
  }

  /**
   * Reads a Message's field_group_id target id, or NULL when unset.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message.
   *
   * @return int|null
   *   The referenced group's id, or NULL when the field is empty.
   */
  private function groupIdOf(MessageInterface $message): ?int {
    if (!$message->hasField('field_group_id') || $message->get('field_group_id')->isEmpty()) {
      return NULL;
    }
    $targetId = $message->get('field_group_id')->target_id;
    return $targetId !== NULL ? (int) $targetId : NULL;
  }

}
