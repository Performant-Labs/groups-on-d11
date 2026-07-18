<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_pin\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\FileStorage;
use Drupal\do_group_pin\Hook\DoGroupPinHooks;
use Drupal\flag\Entity\Flag;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * Behavioral test for do_group_pin's group-stream pin ordering + DISTINCT.
 *
 * B4 / #38 (part of epic #31), RA4 in TEST_PLAN §2. `do_group_pin` does not
 * change the shipped `group_content_stream` view's YAML; instead its
 * {@see \Drupal\do_group_pin\Hook\DoGroupPinHooks::viewsQueryAlter()} LEFT JOINs
 * the `flagging` table on the `pin_in_group` flag and adds a
 * `CASE WHEN pin_flagging.id IS NOT NULL THEN 1 ELSE 0 END DESC` order-by so
 * pinned nodes float to the top. The view itself sets `distinct: true` and
 * reaches Group through the `group_relationship` Views relationship.
 *
 * Static analysis cannot confirm the generated SQL (the whole point of #38): the
 * risk is that the flagging LEFT JOIN and/or the group_relationship join fan a
 * node out into multiple rows, and that the DISTINCT rewrite must collapse those
 * back to one row per node *without* dropping the pin_flagging alias the
 * order-by depends on. So this test EXECUTES the real, altered view against a
 * live DB and inspects the ordered result rows — the K/F layer TEST_PLAN allows
 * for RA4.
 *
 * Layer choice: kernel view-execution rather than BrowserTestBase. The behavior
 * under test is entirely in `hook_views_query_alter` + the DISTINCT query
 * rewrite; executing the view and reading `$view->result` asserts ordering and
 * (critically) row-uniqueness at the results level far more precisely and
 * deterministically than scraping rendered stream HTML. No UI assertion is
 * faked — we assert against the actual query results.
 *
 * Note (B2 / #36): do_group_pin creates no group relationships of its own and so
 * does not rely on the form-only creator auto-membership path. This test relates
 * every node to the group explicitly via {@see GroupsKernelTestBase::addNode()}
 * (the programmatic `Group::addRelationship()` API), never via creator
 * membership, so ordering is asserted independently of that regression surface.
 *
 * TWO DEFECTS this suite pins down (both are the #38 risk made concrete):
 *  1. Pinned-first ordering — FIXED in #52. The hook now moves its `pin_sort`
 *     order-by to the FRONT of the query's orderby, so the compiled query is
 *     `ORDER BY pin_sort DESC, created DESC` and a pinned node LEADS the stream.
 *     See {@see self::testPinnedNodeLeadsStream()}.
 *  2. The view's `distinct: true` does NOT dedupe a relationship-side fan-out,
 *     because the `group_relationship` id is in the SELECT list — a node with two
 *     relationships appears twice. LATENT and LEFT AS-IS (fixing it risks the
 *     group-scoping); kept as characterization, still tracked in #52. See
 *     {@see self::testDistinctDoesNotDedupeRelationshipFanOut()}.
 * The pin flagging LEFT JOIN itself does not fan out (global flag = one row), so
 * a pinned stream is duplicate-free today
 * ({@see self::testPinnedStreamHasNoDuplicateRows()}).
 *
 * @group do_group_pin
 * @group do_tests
 */
class PinnedStreamOrderingTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_group_pin',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
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

    // The flagging content entity storage + flag's counts table (used by
    // FlagService on (un)flag) are not part of the base fixture.
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);

    // Install the shipped config as test fixtures. flag.flag.pin_in_group ships
    // in do_group_pin/config/OPTIONAL, and optional config is NOT auto-installed
    // by kernel module enabling, so it must be installed explicitly here — the
    // flag fixture is byte-identical to the shipped one.
    // views.view.group_content_stream ships in docs/groups/config/ (not in any
    // module's config/install) and likewise must be installed here. The view
    // fixture is the shipped view with two RENDER-ONLY field settings removed
    // (title.settings.link_to_entity and created.date_format), whose config
    // schema is only resolvable with the full entity-field views integration and
    // which have zero effect on the query, ordering, or DISTINCT under test. All
    // query-shaping options are preserved verbatim: base_table node_field_data,
    // distinct: true, the group_relationship relationship, the gid argument, the
    // created-DESC sort, and the status filter. This keeps strict config schema
    // checking ON (no $strictConfigSchema override) while exercising the real
    // query the view generates.
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = $this->container->get('entity_type.manager');
    foreach ([
      'flag' => 'flag.flag.pin_in_group',
      'view' => 'views.view.group_content_stream',
    ] as $storage_id => $config_name) {
      $entity_type_manager->getStorage($storage_id)
        ->create($fixtures->read($config_name))
        ->save();
    }

    $this->flagService = $this->container->get('flag');
    $this->pinFlag = Flag::load('pin_in_group');
    $this->assertNotNull($this->pinFlag, 'The pin_in_group flag is installed by the module.');

    // The stream view is access-controlled: Group's GroupRelationshipQueryAlter
    // adds an `alwaysFalse()` condition unless the viewing user holds the
    // relationship view permission, so an un-permissioned run returns zero rows
    // regardless of ordering. The lightweight base fixture creates the group type
    // via the storage API and so ships no synchronized roles; create an
    // outsider-scope group role granting every `group_node:*` view permission to
    // all authenticated users, so the base test's non-member current user can see
    // the group's content. This isolates the test on ordering/DISTINCT rather than
    // on Group's access policy (that is B1 / #35's surface).
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
  }

  /**
   * Executes the group_content_stream view for a group and returns its rows.
   *
   * @param int|string $gid
   *   The group id to pass as the view's `gid` contextual argument.
   *
   * @return \Drupal\views\ResultRow[]
   *   The ordered result rows (post-DISTINCT).
   */
  protected function executeStream(int|string $gid): array {
    $view = Views::getView('group_content_stream');
    $this->assertNotNull($view, 'The group_content_stream view loaded.');
    $view->setDisplay('page_1');
    $view->preExecute([(string) $gid]);
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
   * A pinned node LEADS the group stream (Defect 1 of #52, now fixed).
   *
   * #38's central claim is that pinning a node floats it to the top of the group
   * stream. The hook adds `ORDER BY CASE WHEN pin_flagging.id IS NOT NULL ...
   * DESC` via `hook_views_query_alter`. The historical bug (#52) was that
   * query-alter runs AFTER the view registers its own `created DESC` sort and the
   * pin order-by was merely APPENDED, so the compiled query was:
   *
   *   ORDER BY node_field_data_created DESC, pin_sort DESC
   *
   * making `pin_sort` a *secondary* tie-breaker that never actually reordered
   * distinct-timestamp rows. The fix moves the pin order-by to the FRONT of
   * $query->orderby (array_unshift), so the compiled query is now:
   *
   *   ORDER BY pin_sort DESC, node_field_data_created DESC
   *
   * i.e. `pin_sort` is the PRIMARY key: the pinned node leads and the remaining
   * nodes keep their created-DESC order.
   *
   * This test EXECUTES the real altered view and asserts the pinned oldest node
   * (A) is first and the rest stay newest-first — the intended [A, D, C, B].
   */
  public function testPinnedNodeLeadsStream(): void {
    $group = $this->createGroup();

    // Increasing created timestamps: unpinned order is D, C, B, A (newest first).
    // We pin A (the OLDEST): once pinning works it jumps to the front.
    $base = \Drupal::time()->getRequestTime();
    $nodeA = $this->addNode($group, 'page', ['title' => 'Alpha', 'created' => $base + 1]);
    $nodeB = $this->addNode($group, 'page', ['title' => 'Bravo', 'created' => $base + 2]);
    $nodeC = $this->addNode($group, 'page', ['title' => 'Charlie', 'created' => $base + 3]);
    $nodeD = $this->addNode($group, 'page', ['title' => 'Delta', 'created' => $base + 4]);

    // Baseline: no pins -> created DESC (D, C, B, A), one row per node.
    $rows = $this->executeStream($group->id());
    $this->assertCount(4, $rows, 'Unpinned stream returns exactly the 4 nodes, one row each.');
    $this->assertSame(
      [(int) $nodeD->id(), (int) $nodeC->id(), (int) $nodeB->id(), (int) $nodeA->id()],
      $this->nidsInOrder($rows),
      'Without pins the stream is ordered created DESC (D, C, B, A).'
    );

    // Pin the oldest node (A).
    $this->flagService->flag($this->pinFlag, $nodeA, $this->createUser());
    $rows = $this->executeStream($group->id());

    // FIXED: A leads. `pin_sort` is now the PRIMARY sort key, so the pinned
    // oldest node floats to the front and the remaining nodes keep created DESC:
    // the intended [A, D, C, B].
    $this->assertSame(
      (int) $nodeA->id(),
      $this->nidsInOrder($rows)[0],
      'The pinned node leads the stream (pin_sort is the primary sort key).'
    );
    $this->assertSame(
      [(int) $nodeA->id(), (int) $nodeD->id(), (int) $nodeC->id(), (int) $nodeB->id()],
      $this->nidsInOrder($rows),
      'Pinned A leads, remaining nodes stay created DESC: [A, D, C, B].'
    );

    // DISTINCT / no-duplicate half still holds: 4 rows, no duplicate nids, even
    // with the pin_flagging LEFT JOIN active.
    $this->assertCount(4, $rows, 'The pin flagging join adds no duplicate rows.');
    $nids = $this->nidsInOrder($rows);
    $this->assertSame($nids, array_values(array_unique($nids)), 'No duplicate node rows in the pinned stream.');
  }

  /**
   * With the pin flagging LEFT JOIN active, the stream lists each node once.
   *
   * This is the no-duplicate-rows half of #38: after pinning, the hook's
   * `pin_flagging` LEFT JOIN is present, and the view's `distinct: true` must keep
   * one row per node. Because `pin_in_group` is a GLOBAL flag, a node has at most
   * one matching `flagging` row, so this join does not itself fan out — this test
   * asserts (and confirms) that the pinned stream contains no duplicate node rows.
   *
   * NB (reported separately, see the class-level finding): the view's DISTINCT
   * does NOT actually protect against a relationship-side fan-out, because the
   * `group_relationship` id column is in the SELECT list, so two relationships
   * for one node produce two DISTINCT rows. That is latent — a single group_node
   * relationship per (group, node) is the normal case, so no fan-out occurs in
   * practice — but it means `distinct: true` is not the row-dedupe guarantee it
   * appears to be. See {@see self::testDistinctDoesNotDedupeRelationshipFanOut()}.
   */
  public function testPinnedStreamHasNoDuplicateRows(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();
    $pinned = $this->addNode($group, 'page', ['title' => 'Pinned', 'created' => $base + 1]);
    $plain = $this->addNode($group, 'page', ['title' => 'Plain', 'created' => $base + 2]);

    $this->flagService->flag($this->pinFlag, $pinned, $this->createUser());

    $rows = $this->executeStream($group->id());
    $nids = $this->nidsInOrder($rows);

    $this->assertCount(2, $rows, 'The pinned stream lists exactly the 2 nodes, one row each.');
    $this->assertSame($nids, array_values(array_unique($nids)), 'No node is duplicated once the pin flagging join is active.');
    $this->assertContains((int) $pinned->id(), $nids, 'The pinned node is present.');
    $this->assertContains((int) $plain->id(), $nids, 'The plain node is present.');
  }

  /**
   * Documents the DISTINCT limitation: a relationship fan-out is NOT collapsed.
   *
   * The view reaches Group through the `group_relationship` Views relationship,
   * whose id column is in the SELECT list. When a node has more than one group
   * relationship, the INNER JOIN fans it out and — because each fanned row has a
   * distinct relationship id — the query's `SELECT DISTINCT` treats them as
   * distinct rows, so the node appears more than once. This is the #38 risk made
   * concrete: `distinct: true` is NOT a per-node dedupe here.
   *
   * This asserts the CURRENT (defective) behavior so the suite fails loudly if a
   * future change silently alters it. Defect 2 is LATENT (one group_node
   * relationship per (group, node) is the norm, so no fan-out occurs in
   * practice) and was deliberately LEFT AS-IS by the #52 fix: excluding the
   * relationship id from the SELECT — or otherwise collapsing on nid — cannot be
   * done from hook_views_query_alter without risking the group-scoping the
   * relationship provides, and Defect 1 (the real user-facing bug) was the fix's
   * mandate. Defect 2 remains tracked in #52 as characterization.
   */
  public function testDistinctDoesNotDedupeRelationshipFanOut(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();
    $fannedOut = $this->addNode($group, 'page', ['title' => 'FannedOut', 'created' => $base + 1]);
    $this->addNode($group, 'page', ['title' => 'Plain', 'created' => $base + 2]);

    // Relate the same node to the group a second time (page cardinality is
    // unlimited in the base fixture) -> two group_relationship rows.
    $group->addRelationship($fannedOut, 'group_node:page');
    $relationshipCount = (int) \Drupal::database()
      ->select('group_relationship_field_data', 'g')
      ->condition('entity_id', $fannedOut->id())
      ->condition('plugin_id', 'group_node:page')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(2, $relationshipCount, 'The node genuinely has two group relationships.');

    $rows = $this->executeStream($group->id());
    $nids = $this->nidsInOrder($rows);

    // FINDING: DISTINCT does not collapse the fan-out — 3 rows for 2 nodes, and
    // the fanned-out node appears twice. If a fix makes DISTINCT dedupe per node,
    // this test should be updated to expect 2 rows / no duplicates.
    $this->assertCount(3, $rows, 'DISTINCT does NOT collapse the relationship fan-out (2 nodes -> 3 rows).');
    $duplicateCount = count($nids) - count(array_unique($nids));
    $this->assertSame(1, $duplicateCount, 'The doubly-related node is duplicated in the stream (the #38 defect).');
  }

  /**
   * The pin/unpin toggle round-trips cleanly and the flag mechanics are sound.
   *
   * The intended contract is "pin -> node leads; unpin -> default order". Because
   * the ordering defect above means a pin never changes order, this test verifies
   * the parts that DO work correctly today, so the fix can be validated against a
   * green baseline:
   *  - flagging then unflagging is a clean round-trip (the flag storage toggles),
   *  - the stream after unpin is the plain created-DESC order (B, A) with no
   *    residual duplicate rows from the (now absent) flagging join.
   *
   * Now that ordering is fixed (Defect 1 of #52), this also asserts that while A
   * is pinned it LEADS the stream, and that unpinning returns to (B, A).
   */
  public function testPinUnpinTogglesFlagAndLeavesCleanOrder(): void {
    $group = $this->createGroup();
    $base = \Drupal::time()->getRequestTime();
    $nodeA = $this->addNode($group, 'page', ['title' => 'Alpha', 'created' => $base + 1]);
    $nodeB = $this->addNode($group, 'page', ['title' => 'Bravo', 'created' => $base + 2]);

    $account = $this->createUser();

    // Pin A: the flagging row exists.
    $this->flagService->flag($this->pinFlag, $nodeA, $account);
    $this->assertTrue(
      (bool) $this->flagService->getFlagging($this->pinFlag, $nodeA),
      'After flagging, a pin flagging exists for A.'
    );

    // While A is pinned it leads the stream (A before the newer B).
    $this->assertSame(
      [(int) $nodeA->id(), (int) $nodeB->id()],
      $this->nidsInOrder($this->executeStream($group->id())),
      'While pinned, the older node A leads the newer node B: [A, B].'
    );

    // Unpin A: the flagging row is gone (clean toggle).
    $this->flagService->unflag($this->pinFlag, $nodeA, $account);
    $this->assertNull(
      $this->flagService->getFlagging($this->pinFlag, $nodeA),
      'After unflagging, no pin flagging remains for A.'
    );

    // With no pins the stream is plain created DESC (B, A), one row per node.
    $restoredOrder = $this->nidsInOrder($this->executeStream($group->id()));
    $this->assertSame(
      [(int) $nodeB->id(), (int) $nodeA->id()],
      $restoredOrder,
      'Unpinned stream is created DESC (B, A) with no duplicate rows.'
    );
  }

  /**
   * The stream's render metadata carries the per-group pin cache tag (#69).
   *
   * The stream's render cache must depend on a tag that a pin toggle
   * invalidates; do_group_pin adds the scoped `do_group_pin:group_stream:<gid>`
   * tag to the view's render metadata in hook_views_post_render. This executes
   * the real stream view and fires the `views_post_render` hook exactly as the
   * render pipeline does (via the module handler), then asserts the tag landed
   * on `$view->element['#cache']['tags']`. (A full HTML render of the Page
   * display is intentionally NOT used — like the ordering tests, this suite
   * exercises the view at the query/metadata level, not by scraping themed HTML,
   * which needs routing/theme integration outside the behavior under test.)
   *
   * Together with {@see self::testPinToggleInvalidatesStreamCacheTagWithoutFlush()}
   * — which shows a flag/unflag invalidates that exact tag — this proves the pin
   * toggle drops the stream's render cache without a manual full flush.
   */
  public function testStreamRenderCarriesPinCacheTag(): void {
    $group = $this->createGroup();
    $this->addNode($group, 'page', ['title' => 'Only']);

    $view = Views::getView('group_content_stream');
    $view->setDisplay('page_1');
    $view->preExecute([(string) $group->id()]);
    $view->execute();

    // Fire views_post_render exactly as ViewExecutable::render() does, so the
    // module's real hook implementation runs against the executed view.
    $output = '';
    $cache = $view->display_handler->getPlugin('cache');
    $this->container->get('module_handler')
      ->invokeAll('views_post_render', [$view, &$output, $cache]);

    $this->assertContains(
      DoGroupPinHooks::streamCacheTag($group->id()),
      $view->element['#cache']['tags'] ?? [],
      'The rendered group stream carries the per-group pin cache tag it can be invalidated by.'
    );
  }

  /**
   * A pin toggle invalidates the stream's cache tag WITHOUT a full flush (#69).
   *
   * This is the #69 fix's core guarantee: pinning/unpinning a node invalidates
   * exactly the cache tag the stream render depends on, so a stream cached
   * before the toggle is dropped and re-rendered in the new order — with no
   * manual drupal_flush_all_caches(). We prove it at the cache layer: seed a
   * probe item tagged with the group's stream tag (standing in for the cached
   * stream render), then flag and unflag the node and assert the probe is
   * invalidated each time — i.e. the toggle invalidated the right tag.
   *
   * It also asserts the invalidation is SCOPED: an unrelated group's stream tag
   * (seeded on a second probe) is never invalidated by pinning in the first
   * group.
   */
  public function testPinToggleInvalidatesStreamCacheTagWithoutFlush(): void {
    $group = $this->createGroup();
    $otherGroup = $this->createGroup();
    $node = $this->addNode($group, 'page', ['title' => 'Target']);
    $account = $this->createUser();

    $cache = $this->container->get('cache.default');
    $tag = DoGroupPinHooks::streamCacheTag($group->id());
    $otherTag = DoGroupPinHooks::streamCacheTag($otherGroup->id());

    $seed = function () use ($cache, $tag, $otherTag): void {
      $cache->set('do_group_pin_test:stream:' . $tag, 'cached-stream', Cache::PERMANENT, [$tag]);
      $cache->set('do_group_pin_test:stream:' . $otherTag, 'other-cached-stream', Cache::PERMANENT, [$otherTag]);
    };

    // FLAG (pin): the target group's cached stream is invalidated; the unrelated
    // group's cached stream survives.
    $seed();
    $this->assertNotFalse($cache->get('do_group_pin_test:stream:' . $tag), 'The stream cache item is seeded.');
    $this->flagService->flag($this->pinFlag, $node, $account);
    $this->assertFalse(
      $cache->get('do_group_pin_test:stream:' . $tag),
      'Pinning invalidated the group stream cache tag (no manual flush).'
    );
    $this->assertNotFalse(
      $cache->get('do_group_pin_test:stream:' . $otherTag),
      'Pinning did NOT invalidate an unrelated group stream (invalidation is scoped).'
    );

    // UNFLAG (unpin): the target group's cached stream is invalidated again.
    $seed();
    $this->flagService->unflag($this->pinFlag, $node, $account);
    $this->assertFalse(
      $cache->get('do_group_pin_test:stream:' . $tag),
      'Unpinning invalidated the group stream cache tag (no manual flush).'
    );
    $this->assertNotFalse(
      $cache->get('do_group_pin_test:stream:' . $otherTag),
      'Unpinning did NOT invalidate an unrelated group stream (invalidation is scoped).'
    );
  }

}
