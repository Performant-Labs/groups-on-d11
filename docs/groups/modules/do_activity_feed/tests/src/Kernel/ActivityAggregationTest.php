<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

/**
 * Aggregation semantics — pairwise-consecutive 6h window (#129 AC-2a/b/c).
 *
 * Pins the brief's explicit "A-advisory #5" window rule against
 * `ActivityAggregator` directly (not through the controller/render array),
 * since the aggregation ALGORITHM's bucket-fold behavior is the unit under
 * test here — ActivityFeedRenderTest (AC-1/3/4) already covers the
 * controller's end-to-end row-type interleaving using one aggregated case;
 * this file is the one place doing the exhaustive by-1-second-over/under
 * window-boundary proof, so it does not duplicate that coverage.
 *
 * Window rule (brief, verbatim): "Iterate rows in `created DESC`; fold row
 * `n` into the current bucket only if `(actor, template, group)` matches the
 * bucket AND `|current.created - previous_row_in_bucket.created| <= 6h`. A
 * chain t=0 / t=5h / t=10h therefore folds into ONE bucket (both 5h gaps
 * qualify). A gap > 6h opens a new bucket."
 *
 * RED reason: `ActivityAggregator` (docs/groups/modules/do_activity_feed/
 * src/Service/ActivityAggregator.php per survey.md's owned-files list) does
 * not exist yet — the service class itself is absent, so instantiating it
 * (or resolving it from the container once do_activity_feed.services.yml
 * ships) fails at class-load / service-not-found, not on a chain-fold
 * miscalculation.
 *
 * Layer choice: kernel, invoking the service directly with hand-built
 * Message entities at exact offsets — this is the cheapest sufficient tier
 * for a pure-PHP bucketing algorithm; no Views/HTTP layer is needed to prove
 * fold/no-fold at an exact time boundary.
 *
 * Fixture note (T-green fixture repair): `GroupsKernelTestBase::addNode()`
 * relates the node to its group via `Group::addRelationship()`, which fires
 * `do_activity`'s own LIVE `#[Hook('group_relationship_insert')]` as a real
 * side effect — this creates an ADDITIONAL `activity_post_created` Message
 * per `addNode()` call, attributed to `\Drupal::currentUser()` (the
 * throwaway user `GroupsKernelTestBase::setUp()` establishes as the current
 * user, NOT necessarily this test's own `$actor`) and stamped with the
 * node's real (test-run-time) `getCreatedTime()`, not the test's explicit
 * offset. `messagesByTemplate()`/`messagesByTemplateOrdered()` therefore
 * accept an explicit list of actor uids to filter to, so each test's bucket
 * math only sees the Messages it explicitly authored via
 * `createPostMessage()` — not the uncontrolled hook-fired noise `addNode()`
 * generates as a side effect.
 *
 * @group do_activity_feed
 * @group do_tests
 */
class ActivityAggregationTest extends ActivityFeedKernelTestBase {

  /**
   * Loads the ActivityAggregator service, asserting it resolves.
   *
   * @return object
   *   The do_activity_feed.aggregator service.
   */
  protected function aggregator(): object {
    $service = \Drupal::service('do_activity_feed.aggregator');
    $this->assertNotNull($service, 'The do_activity_feed.aggregator service resolves.');
    return $service;
  }

  /**
   * AC-2a: two posts by the same actor, 5h apart, in the same group, fold.
   *
   * A 5h gap is comfortably inside the 6h window — this is the simple,
   * two-row "does aggregation happen at all" case that 2b/2c build on.
   */
  public function testTwoPostsFiveHoursApartAggregateWithCountTwo(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $now = \Drupal::time()->getRequestTime();

    $node1 = $this->addNode($group, 'post', ['title' => 'Topic 1', 'uid' => $actor->id()]);
    $node2 = $this->addNode($group, 'post', ['title' => 'Topic 2', 'uid' => $actor->id()]);
    $this->createPostMessage($actor, $group, $node1, $now - 5 * 3600);
    $this->createPostMessage($actor, $group, $node2, $now);

    $messages = $this->messagesByTemplateOrdered('activity_post_created', [(int) $actor->id()]);
    $buckets = $this->aggregator()->aggregate($messages);

    $this->assertCount(1, $buckets, 'Exactly one bucket results from two same-actor/group posts 5h apart.');
    $this->assertSame(2, $buckets[0]['count'], 'The bucket count is 2.');
  }

  /**
   * AC-2b: t=0 / t=5h / t=13h — the first two fold, the third stands alone.
   *
   * The gap between row 2 (t=5h) and row 3 (t=13h) is 8h, exceeding the 6h
   * window, so row 3 opens a NEW bucket rather than joining the first.
   * (Timestamps below are expressed as seconds-since-epoch offsets, with
   * t=0 being the OLDEST message — iteration order in the algorithm is
   * `created DESC`, i.e. newest first, so the fixture creates all three but
   * the assertions read buckets in newest-first order.)
   */
  public function testThreePostsWithGapPastWindowSplitsIntoTwoBuckets(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $epoch = 1_700_000_000;

    $nodeT0 = $this->addNode($group, 'post', ['title' => 'T0', 'uid' => $actor->id()]);
    $nodeT5h = $this->addNode($group, 'post', ['title' => 'T5h', 'uid' => $actor->id()]);
    $nodeT13h = $this->addNode($group, 'post', ['title' => 'T13h', 'uid' => $actor->id()]);

    $this->createPostMessage($actor, $group, $nodeT0, $epoch);
    $this->createPostMessage($actor, $group, $nodeT5h, $epoch + 5 * 3600);
    $this->createPostMessage($actor, $group, $nodeT13h, $epoch + 13 * 3600);

    $messages = $this->messagesByTemplateOrdered('activity_post_created', [(int) $actor->id()]);
    $buckets = $this->aggregator()->aggregate($messages);

    $this->assertCount(2, $buckets, 't=0/t=5h fold together; t=13h (8h past the t=5h row) opens a second bucket.');

    $counts = array_column($buckets, 'count');
    sort($counts);
    $this->assertSame([1, 2], $counts, 'One bucket has count=2 (t=0+t=5h); the other stands alone with count=1.');
  }

  /**
   * AC-2c: t=0 / t=5h / t=10h all chain into ONE bucket (pairwise-consecutive).
   *
   * The critical case distinguishing "pairwise consecutive" from "all-pairs
   * within window": t=0 to t=10h is a 10h gap (> 6h) — if the algorithm
   * compared every row to the BUCKET'S FIRST/anchor row, this chain would
   * split. The brief is explicit that it must NOT: each row is compared
   * only to the PREVIOUS row already in the bucket (t=5h to t=10h is a
   * qualifying 5h gap), so all three fold into one bucket with count=3.
   */
  public function testThreePostsChainWithinPairwiseWindowAggregateAsOneBucket(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $epoch = 1_700_000_000;

    $nodeT0 = $this->addNode($group, 'post', ['title' => 'Chain T0', 'uid' => $actor->id()]);
    $nodeT5h = $this->addNode($group, 'post', ['title' => 'Chain T5h', 'uid' => $actor->id()]);
    $nodeT10h = $this->addNode($group, 'post', ['title' => 'Chain T10h', 'uid' => $actor->id()]);

    $this->createPostMessage($actor, $group, $nodeT0, $epoch);
    $this->createPostMessage($actor, $group, $nodeT5h, $epoch + 5 * 3600);
    $this->createPostMessage($actor, $group, $nodeT10h, $epoch + 10 * 3600);

    $messages = $this->messagesByTemplateOrdered('activity_post_created', [(int) $actor->id()]);
    $buckets = $this->aggregator()->aggregate($messages);

    $this->assertCount(1, $buckets, 't=0/t=5h/t=10h all chain into exactly one bucket (pairwise-consecutive semantics — t=0 to t=10h alone (10h) would fail an anchor-based window, but each step is <=6h from its own predecessor).');
    $this->assertSame(3, $buckets[0]['count'], 'The single bucket reports count=3.');
  }

  /**
   * Aggregation only applies within the SAME (actor, template, group) key.
   *
   * A different actor's post at the same moment must NOT fold into another
   * actor's bucket, even inside the window — guards against an
   * implementation that aggregates purely on elapsed time.
   */
  public function testDifferentActorsDoNotAggregateTogether(): void {
    $group = $this->createGroup();
    $actorOne = $this->createUser();
    $actorTwo = $this->createUser();
    $now = \Drupal::time()->getRequestTime();

    $nodeOne = $this->addNode($group, 'post', ['title' => 'Actor one post', 'uid' => $actorOne->id()]);
    $nodeTwo = $this->addNode($group, 'post', ['title' => 'Actor two post', 'uid' => $actorTwo->id()]);
    $this->createPostMessage($actorOne, $group, $nodeOne, $now);
    $this->createPostMessage($actorTwo, $group, $nodeTwo, $now - 60);

    $messages = $this->messagesByTemplateOrdered('activity_post_created', [(int) $actorOne->id(), (int) $actorTwo->id()]);
    $buckets = $this->aggregator()->aggregate($messages);

    $this->assertCount(2, $buckets, "Two different actors' posts never share a bucket, even one minute apart.");
    foreach ($buckets as $bucket) {
      $this->assertSame(1, $bucket['count'], 'Each actor-specific bucket has count=1.');
    }
  }

  /**
   * Loads Messages of one template, ordered created DESC (newest first).
   *
   * Matches the brief's own iteration-order contract ("Iterate rows in
   * `created DESC`") — the aggregator's `aggregate()` method is expected to
   * consume its input pre-sorted this way, the same order the real feed
   * Views query produces.
   *
   * @param string $template
   *   The message_template bundle id.
   * @param int[] $actorUids
   *   The uids this test explicitly attributes its own fixture Messages to.
   *   Restricts the result to Messages authored by one of these actors,
   *   excluding uncontrolled `activity_post_created` Messages `addNode()`'s
   *   own `do_activity` hook side effect creates for whichever user happens
   *   to be `\Drupal::currentUser()` at fixture-setup time (see class
   *   docblock).
   *
   * @return \Drupal\message\MessageInterface[]
   *   Messages ordered newest-created-first.
   */
  protected function messagesByTemplateOrdered(string $template, array $actorUids): array {
    $messages = $this->messagesByTemplate($template, $actorUids);
    usort($messages, static fn ($a, $b): int => $b->getCreatedTime() <=> $a->getCreatedTime());
    return array_values($messages);
  }

  /**
   * Filters loaded messages down to a single template id and actor set.
   *
   * Mirrors ActivityKernelTestBase::messagesByTemplate() exactly (do_activity
   * does not expose this helper for reuse outside its own test namespace, so
   * it is duplicated here rather than adding a cross-module test dependency),
   * plus an actor-uid filter — see messagesByTemplateOrdered()'s docblock for
   * why this filter is required (do_activity's live hooks fire as an
   * uncontrolled side effect of this suite's own `addNode()`/`createGroup()`
   * fixture calls).
   *
   * @param string $template
   *   The message template id.
   * @param int[] $actorUids
   *   The uids to restrict the result to.
   *
   * @return \Drupal\message\MessageInterface[]
   *   Matching messages, re-indexed numerically.
   */
  protected function messagesByTemplate(string $template, array $actorUids): array {
    $storage = $this->entityTypeManager->getStorage('message');
    $storage->resetCache();
    $messages = $storage->loadMultiple();
    return array_values(array_filter(
      $messages,
      static fn ($m): bool => $m->bundle() === $template && in_array((int) $m->getOwnerId(), $actorUids, TRUE),
    ));
  }

}
