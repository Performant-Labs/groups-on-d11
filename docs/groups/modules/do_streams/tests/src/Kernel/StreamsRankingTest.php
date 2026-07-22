<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\FileStorage;
use Drupal\flag\Entity\Flag;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * Behavioral test for do_streams' four ranking modes over the do_streams_demo view.
 *
 * Issue #109 (epic #108), acceptance criterion 3 ("All four rankings selectable
 * and correct") + brief [B-1]/[B-2]/[B-6]/[B-8]/[W-2]/[A-W2]/[A-W3]. The
 * ranking parameter is carried as a Views contextual ARGUMENT (per [A-W3]'s
 * resolution: `$view->args`, matching do_group_pin's `$view->args[0]` gid
 * pattern) — the `do_streams_demo` fixture view's second argument, `ranking`
 * (id `ranking`), which `DoStreamsHooks::viewsQueryAlter()` reads and branches
 * on to build the corresponding ORDER BY, exactly as `DoGroupPinHooks` does
 * for the (single-purpose) pin sort.
 *
 * Each ranking gets its OWN test asserting the documented order:
 *  - recent = created DESC (baseline; also the fixture view's registered sort,
 *    so this test also proves the ranking param doesn't silently do nothing).
 *  - last-activity = GREATEST(changed, COALESCE(NULLIF(last_comment_timestamp,
 *    0), changed)) DESC per [B-1] — asserts a node whose ONLY recent activity
 *    is a comment out-ranks a node with a newer `created` but no comments, AND
 *    that a never-commented node falls back to `changed` (not sorted as never-
 *    active).
 *  - hot = do_discovery_hot_score.score DESC via a LEFT JOIN per [W-2] — a
 *    node with NO score row still appears (COALESCE 0), not excluded.
 *  - pinned-first = pin_in_group leads as the PRIMARY sort key (not a tie-
 *    breaker), deduped, no duplicate rows — mirroring
 *    PinnedStreamOrderingTest::testPinnedNodeLeadsStream().
 *
 * None of `DoStreamsHooks`, the `ranking` query-alter branch, or the hot-score
 * relationship exist yet, so every test in this suite either errors
 * (`DoStreamsHooks` class-not-found) or executes against an UNALTERED query
 * (ranking parameter silently ignored, so the assertions on order/inclusion
 * fail) — the intended RED for a ranking wiring that has not been built.
 *
 * @group do_streams
 * @group do_tests
 */
class StreamsRankingTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'do_group_pin',
    'do_discovery',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
    'comment',
    'taxonomy',
  ];

  /**
   * The flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * The pin_in_group flag entity.
   *
   * @var \Drupal\flag\FlagInterface
   */
  protected $pinFlag;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('flagging');
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('do_discovery', ['do_discovery_hot_score']);

    $fixtures = new FileStorage(__DIR__ . '/../../../../../config');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('flag')
      ->create($fixtures->read('flag.flag.pin_in_group'))
      ->save();

    $view_fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager->getStorage('view')
      ->create($view_fixtures->read('views.view.do_streams_demo'))
      ->save();

    // Group's own access-policy query alter LEFT JOINs
    // group_relationship_field_data and hides any node that IS group content
    // unless the viewer holds the relevant view permission (mirroring
    // PinnedStreamOrderingTest's setUp()) — grant it here too so ranking is
    // asserted independently of Group's access layer.
    $permissions = [];
    foreach (static::NODE_BUNDLES as $node_type) {
      $permissions[] = "view group_node:$node_type relationship";
      $permissions[] = "view group_node:$node_type entity";
    }
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);

    $this->flagService = $this->container->get('flag');
    $this->pinFlag = Flag::load('pin_in_group');
    $this->assertNotNull($this->pinFlag, 'The pin_in_group flag is installed.');
  }

  /**
   * Executes the do_streams_demo global-scope display for a given ranking.
   *
   * The `page_global` display carries no scope filter (per [B-8], "Global" is
   * simply the absence of a scope filter), so this proof exercises ranking in
   * isolation from the scope plugins under test in StreamsScopeTest.
   *
   * @param string $ranking
   *   The ranking argument value (`recent`, `last_activity`, `hot`, `pinned`).
   *
   * @return \Drupal\views\ResultRow[]
   *   The ordered result rows.
   */
  protected function executeRanked(string $ranking): array {
    $view = Views::getView('do_streams_demo');
    $this->assertNotNull($view, 'The do_streams_demo fixture view loaded.');
    $view->setDisplay('page_global');
    $view->preExecute([$ranking]);
    $view->execute();
    return $view->result;
  }

  /**
   * Maps an ordered result set to the node ids in that order.
   *
   * @param \Drupal\views\ResultRow[] $rows
   *   The result rows.
   *
   * @return int[]
   *   Node ids in result order.
   */
  protected function nidsInOrder(array $rows): array {
    return array_map(static fn ($row) => (int) $row->nid, $rows);
  }

  /**
   * Ranking 1/4: "recent" sorts created DESC.
   */
  public function testRecentRankingOrdersByCreatedDesc(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();
    $older = $this->addNode($group, 'page', ['title' => 'Older', 'created' => $base + 1]);
    $newer = $this->addNode($group, 'page', ['title' => 'Newer', 'created' => $base + 2]);

    $nids = $this->nidsInOrder($this->executeRanked('recent'));

    $this->assertSame(
      [(int) $newer->id(), (int) $older->id()],
      $nids,
      'The "recent" ranking orders nodes created DESC (newest first).'
    );
  }

  /**
   * Ranking 2/4: "last-activity" out-ranks plain "recent" via comment stats.
   *
   * [B-1]'s central claim: a node whose only recent activity is a NEW COMMENT
   * must out-rank a node with a newer `created` timestamp but no comments —
   * this is what distinguishes "last-activity" from "recent" (sorting by
   * `changed` alone would not do this either, since a comment does not touch
   * the node's own `changed` column).
   */
  public function testLastActivityRankingPrefersRecentCommentOverNewerCreation(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();

    // Commented is OLDER (created first) but has a comment stamped AFTER
    // Newer's creation time.
    $commented = $this->addNode($group, 'page', ['title' => 'Commented', 'created' => $base + 1, 'changed' => $base + 1]);
    $newerNoComments = $this->addNode($group, 'page', ['title' => 'Newer, no comments', 'created' => $base + 5, 'changed' => $base + 5]);

    $this->seedCommentStatistics((int) $commented->id(), $base + 10);

    $nids = $this->nidsInOrder($this->executeRanked('last_activity'));

    $this->assertSame(
      (int) $commented->id(),
      $nids[0],
      'A node with a later comment timestamp leads a node with a later creation but no comments under "last-activity" ranking.'
    );
  }

  /**
   * Ranking 2/4: a never-commented node falls back to `changed`, not "never".
   *
   * [B-1]: "Do not sort by raw last_comment_timestamp alone (a node with zero
   * comments would sort as if never active)." A node with zero comment
   * activity must still be ordered by its `changed` timestamp among other
   * never-commented nodes, not sink to the bottom / sort as epoch-zero.
   */
  public function testLastActivityRankingFallsBackToChangedWhenNoComments(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();

    $olderChanged = $this->addNode($group, 'page', ['title' => 'Older changed', 'created' => $base + 1, 'changed' => $base + 1]);
    $newerChanged = $this->addNode($group, 'page', ['title' => 'Newer changed', 'created' => $base + 2, 'changed' => $base + 2]);
    // Neither node has any comment_entity_statistics row seeded.

    $nids = $this->nidsInOrder($this->executeRanked('last_activity'));

    $this->assertSame(
      [(int) $newerChanged->id(), (int) $olderChanged->id()],
      $nids,
      'With no comment activity on either node, "last-activity" falls back to `changed` DESC (not treated as never-active).'
    );
  }

  /**
   * Ranking 3/4: "hot" sorts by do_discovery_hot_score.score DESC.
   */
  public function testHotRankingOrdersByScoreDesc(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();
    // Deliberately INVERT creation order vs score order: the newER node gets
    // the LOWER score, so a test that (wrongly) still sorted by created DESC
    // (the ranking parameter silently ignored) would produce the OPPOSITE
    // order from what "hot" requires — this makes the assertion fail loudly
    // for the right reason instead of passing by created-DESC coincidence.
    $lowScoreNewer = $this->addNode($group, 'page', ['title' => 'Low score, newer', 'created' => $base + 2]);
    $highScoreOlder = $this->addNode($group, 'page', ['title' => 'High score, older', 'created' => $base + 1]);

    $this->seedHotScore((int) $lowScoreNewer->id(), 1.0);
    $this->seedHotScore((int) $highScoreOlder->id(), 99.0);

    $nids = $this->nidsInOrder($this->executeRanked('hot'));

    $this->assertSame(
      [(int) $highScoreOlder->id(), (int) $lowScoreNewer->id()],
      $nids,
      'The "hot" ranking orders nodes by do_discovery_hot_score.score DESC (the older/lower-score-vs-created-order pairing rules out a created-DESC coincidence).'
    );
  }

  /**
   * Ranking 3/4 [W-2]: a node with NO hot-score row still appears (LEFT JOIN).
   *
   * The hot-score join must be a LEFT JOIN, not an INNER JOIN — a freshly
   * published node with no computed score row yet (before the next cron run)
   * must still appear in the "hot" ranking, sorted as if score 0
   * (COALESCE(do_discovery_hot_score.score, 0)), never excluded outright.
   */
  public function testHotRankingIncludesNodesWithNoScoreRow(): void {
    $group = $this->createGroup();
    $scored = $this->addNode($group, 'page', ['title' => 'Has a score']);
    $unscored = $this->addNode($group, 'page', ['title' => 'No score row at all']);

    $this->seedHotScore((int) $scored->id(), 5.0);
    // Deliberately do NOT seed a do_discovery_hot_score row for $unscored.

    $nids = $this->nidsInOrder($this->executeRanked('hot'));

    $this->assertContains(
      (int) $unscored->id(),
      $nids,
      'A node with no do_discovery_hot_score row still appears under "hot" ranking (LEFT JOIN, [W-2]).'
    );
    $this->assertSame(
      (int) $scored->id(),
      $nids[0],
      'The scored node still leads (unscored treated as score 0, not equal/greater).'
    );
  }

  /**
   * Ranking 4/4: "pinned" is the PRIMARY sort key, not a tie-breaker.
   *
   * Mirrors PinnedStreamOrderingTest::testPinnedNodeLeadsStream(): a pinned
   * OLDER node must LEAD a newer unpinned node — proving pin_sort is
   * array_unshift'd to the front of $query->orderby (the #52 fix pattern),
   * not appended after `created DESC` (which would make it a no-op
   * tie-breaker on distinct timestamps).
   */
  public function testPinnedRankingLeadsAsPrimaryKeyNotTiebreaker(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();
    $pinnedOlder = $this->addNode($group, 'page', ['title' => 'Pinned older', 'created' => $base + 1]);
    $unpinnedNewer = $this->addNode($group, 'page', ['title' => 'Unpinned newer', 'created' => $base + 2]);

    $this->flagService->flag($this->pinFlag, $pinnedOlder, $this->createUser());

    $nids = $this->nidsInOrder($this->executeRanked('pinned'));

    $this->assertSame(
      [(int) $pinnedOlder->id(), (int) $unpinnedNewer->id()],
      $nids,
      'The pinned older node leads the unpinned newer node under "pinned" ranking (primary key, not tie-breaker).'
    );
  }

  /**
   * Ranking 4/4: "pinned" ranking produces no duplicate rows.
   *
   * Mirrors PinnedStreamOrderingTest::testStreamDedupesRelationshipFanOut() —
   * a node related to its group more than once must still appear exactly
   * once under "pinned" ranking (the GROUP BY dedupe per [B-6]/[A-W1] must be
   * discovered generically by table membership, not a hardcoded alias
   * string, since do_streams' join set differs from do_group_pin's).
   */
  public function testPinnedRankingDedupesRelationshipFanOut(): void {
    $group = $this->createGroup();
    $fannedOut = $this->addNode($group, 'page', ['title' => 'Fanned out']);
    $group->addRelationship($fannedOut, 'group_node:page');

    $nids = $this->nidsInOrder($this->executeRanked('pinned'));

    $this->assertSame(
      $nids,
      array_values(array_unique($nids)),
      'A node with two group_node relationships appears exactly once under "pinned" ranking (dedupe holds).'
    );
  }

  /**
   * Pin toggle invalidates the do_streams user-stream cache tag, no full flush.
   *
   * Acceptance criterion + brief [W-4]/[A-W2]: cache tags must be SCOPE-AWARE
   * and per-viewing-user for membership/following scope — the tag do_streams
   * emits/invalidates for the CURRENT viewing user's stream must be
   * `do_streams:user_stream:<uid>` shaped (per [A-W2]), NOT
   * `do_group_pin:...` (do_group_pin's own tag is per-GROUP, not per-user,
   * and reusing its namespace here would be the wrong scope entirely).
   * Mirrors PinnedStreamOrderingTest::testPinToggleInvalidatesStreamCacheTagWithoutFlush.
   *
   * T's Phase-6 adjudication note (T-green): the ORIGINAL version of this test
   * (Phase 4) asserted that pinning invalidates a THIRD PARTY's ($viewer, who
   * neither flags nor is a group member) tag while a symmetric bystander
   * ($otherViewer) is untouched — but nothing in the fixture ties $viewer
   * (as opposed to $otherViewer) to the flag event, the node, or its group;
   * they are fully interchangeable bystanders. do_group_pin's OWN precedent
   * test (PinnedStreamOrderingTest::testPinToggleInvalidatesStreamCacheTag-
   * WithoutFlush) derives its tag from the NODE'S GROUP (a real,
   * fixture-derivable relationship — the flagging entity's flaggable has a
   * group), which do_group_pin's per-group tag can express; do_streams' tag
   * is per-VIEWING-USER instead (per [A-W2]'s own resolution: membership/
   * following scope is per-user, so a per-user tag is the correct
   * granularity) — pinned-first ranking is a GLOBAL reorder (no scope
   * argument gates it, see [B-2]/[A-W3]), so there is no membership or
   * following relationship to derive "which viewing user is affected" from
   * the flagging entity alone. The only viewing-user tag this module CAN
   * correctly derive without a broadcast is the FLAGGER'S OWN tag (the one
   * user whose action just made their own cached "pinned" ranking stale).
   * Rewritten so $viewer performs the toggle themselves — this still pins
   * the AC's actual contract: a pin toggle invalidates the affected user's
   * `do_streams:user_stream:<uid>` tag WITHOUT a full flush, and that
   * invalidation is SCOPED (an uninvolved bystander's tag is untouched) —
   * while asserting only what is honestly derivable from the flagging event.
   */
  public function testPinToggleInvalidatesUserStreamCacheTagWithoutFlush(): void {
    $viewer = $this->createUser();
    $otherViewer = $this->createUser();
    $group = $this->createGroup();
    $node = $this->addNode($group, 'page', ['title' => 'Target']);

    $cache = $this->container->get('cache.default');

    // The reference tag shape under test is do_streams:user_stream:<uid>
    // (per [A-W2]), NOT do_group_pin:.... This test constructs the tag
    // literally (rather than calling a do_streams helper, since none exists
    // yet) and asserts the do_streams implementation invalidates exactly
    // this shape when a pin toggle occurs while the tagged user is the
    // current viewing user's own stream.
    $tag = 'do_streams:user_stream:' . $viewer->id();
    $otherTag = 'do_streams:user_stream:' . $otherViewer->id();

    $seed = function () use ($cache, $tag, $otherTag): void {
      $cache->set('do_streams_test:stream:' . $tag, 'cached-stream', Cache::PERMANENT, [$tag]);
      $cache->set('do_streams_test:stream:' . $otherTag, 'other-cached-stream', Cache::PERMANENT, [$otherTag]);
    };

    $seed();
    $this->assertNotFalse($cache->get('do_streams_test:stream:' . $tag), 'The stream cache item is seeded.');
    // $viewer performs the pin toggle themselves -- the one viewing user the
    // implementation can correctly derive as affected from the flagging
    // entity alone (its owner), per the adjudication note above.
    $this->flagService->flag($this->pinFlag, $node, $viewer);
    $this->assertFalse(
      $cache->get('do_streams_test:stream:' . $tag),
      'Pinning invalidated the flagging viewing-user\'s own stream cache tag (no manual flush).'
    );
    $this->assertNotFalse(
      $cache->get('do_streams_test:stream:' . $otherTag),
      'Pinning did NOT invalidate an unrelated viewing user\'s stream cache tag (invalidation is scoped per-user, never a blanket flush).'
    );
  }

  /**
   * Seeds a comment_entity_statistics row so last-activity ranking can read it.
   *
   * @param int $nid
   *   The node id.
   * @param int $lastCommentTimestamp
   *   The last_comment_timestamp value to seed.
   */
  protected function seedCommentStatistics(int $nid, int $lastCommentTimestamp): void {
    $this->container->get('database')->insert('comment_entity_statistics')
      ->fields([
        'entity_id' => $nid,
        'entity_type' => 'node',
        'field_name' => 'comment',
        'cid' => 0,
        'last_comment_timestamp' => $lastCommentTimestamp,
        'last_comment_name' => NULL,
        'last_comment_uid' => 0,
        'comment_count' => 1,
      ])
      ->execute();
  }

  /**
   * Seeds a do_discovery_hot_score row.
   *
   * @param int $nid
   *   The node id.
   * @param float $score
   *   The score value to seed.
   */
  protected function seedHotScore(int $nid, float $score): void {
    $this->container->get('database')->merge('do_discovery_hot_score')
      ->key('nid', $nid)
      ->fields(['score' => $score, 'computed' => \Drupal::time()->getRequestTime()])
      ->execute();
  }

}
