<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\do_notifications\Hook\DoNotificationsHooks;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' group-scoped event recording.
 *
 * Issue #37 (Wave B / B3), epic #31. Pins the Group 4.x migration risk that
 * "adding an entity to a group invalidates cache tags instead of resaving it"
 * (change record 2025-05-23): do_notifications records events off the CORE
 * node/comment lifecycle (node_insert / comment_insert) and never off a Group
 * relationship event, so the group-add moment fires no hook this module
 * listens to.
 *
 * These tests assert the REAL recorded state — the `do_notifications` queue
 * (see {@see \Drupal\do_notifications\Hook\DoNotificationsHooks::recordEvent()},
 * which is the only data model this module writes; there is no notification
 * table, entity, or queue worker — items are enqueued for an external system).
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupAddNotificationTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `do_notifications` (the module under test), `flag` (its hard dependency),
   * plus the group/gnode/node stack the base already needs. `do_tests` is
   * pulled in transitively as the base class' provider and via `@group`.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'flag',
    'do_notifications',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // node_access is required once real node CRUD (grants) is exercised.
    $this->installSchema('node', ['node_access']);
  }

  /**
   * Claims and returns every pending item on the do_notifications queue.
   *
   * Draining (claim + delete) both reads and resets the recorded state, so
   * each assertion sees only the events produced since the previous drain.
   *
   * @return array<int, array>
   *   The `data` payload of each queued item, in claim order.
   */
  private function drainQueue(): array {
    $queue = $this->container->get('queue')->get('do_notifications');
    $items = [];
    while ($item = $queue->claimItem()) {
      $items[] = $item->data;
      $queue->deleteItem($item);
    }
    return $items;
  }

  /**
   * Invokes the private getGroupIds() to assert the ID-convention resolution.
   *
   * @param mixed $entity
   *   The entity to resolve group ids for.
   *
   * @return array
   *   The resolved group ids.
   */
  private function callGetGroupIds(mixed $entity): array {
    $hooks = new DoNotificationsHooks(
      $this->entityTypeManager,
      $this->container->get('queue'),
    );
    $method = new \ReflectionMethod($hooks, 'getGroupIds');
    $method->setAccessible(TRUE);
    return $method->invoke($hooks, $entity);
  }

  /**
   * node_insert records a `node_created` event for a published group bundle.
   *
   * This is the path that DOES fire. Note the event is recorded with an EMPTY
   * `group_ids` array even when the node will belong to a group, because
   * node_insert runs the instant the node row is written — before any
   * group_relationship for it can exist (a relationship needs the node id).
   * getGroupIds() therefore sees no relationship yet. The group-scoped
   * enrichment only ever materialises for entities already related at the time
   * a LATER hook (e.g. comment_insert) calls getGroupIds() — see
   * {@see self::testGetGroupIdsResolvesV4RelationshipTypeIds()}.
   */
  public function testNodeInsertRecordsNodeCreatedEvent(): void {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Published post',
      'uid' => $this->getCurrentUser()->id(),
      'status' => 1,
    ]);
    $node->save();

    $items = $this->drainQueue();
    $this->assertCount(1, $items, 'One notification event recorded for the published node.');

    $event = $items[0];
    $this->assertSame('node_created', $event['event']);
    $this->assertSame('node', $event['entity_type']);
    $this->assertSame($node->id(), $event['entity_id']);
    $this->assertSame('post', $event['bundle']);
    $this->assertSame($this->getCurrentUser()->id(), $event['author_uid']);
    $this->assertArrayHasKey('timestamp', $event);
    // group_ids is empty at insert time: the relationship cannot exist yet.
    $this->assertSame([], $event['group_ids']);
  }

  /**
   * Unpublished / non-postable nodes record no event (guard clauses hold).
   */
  public function testNonEligibleNodeInsertRecordsNothing(): void {
    // Unpublished post: nodeInsert() returns early on !isPublished().
    $unpublished = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Draft',
      'uid' => $this->getCurrentUser()->id(),
      'status' => 0,
    ]);
    $unpublished->save();
    $this->assertSame([], $this->drainQueue(), 'Unpublished node records no event.');

    // Node type outside CONTENT_TYPES: nodeInsert() returns early on bundle.
    if (!\Drupal\node\Entity\NodeType::load('article')) {
      $this->createNodeType(['type' => 'article', 'name' => 'Article']);
    }
    $article = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'article',
      'title' => 'Off-list bundle',
      'uid' => $this->getCurrentUser()->id(),
      'status' => 1,
    ]);
    $article->save();
    $this->assertSame([], $this->drainQueue(), 'Non-postable bundle records no event.');
  }

  /**
   * RA3 — adding an existing node to a group AFTER creation records NO event.
   *
   * This is the central B3 assertion for change record 2025-05-23. We create a
   * published node NOT in any group (its node_insert event is drained away),
   * then relate it to a group via the v4 relationship-create path
   * ($group->addRelationship(...)). In Group 4.x that operation invalidates the
   * node's cache tags — it does NOT resave the node — and this module
   * implements no node_update / hook_entity_update / Group relationship-event
   * listener. So the group-add moment produces zero notification events.
   *
   * VERDICT: this is CORRECT-BY-DESIGN for the module as written (the triggers
   * were always insert-only; there was never a group-add notification to lose),
   * but it is a genuine PRODUCT GAP if members are expected to be notified when
   * an existing post is later cross-posted into their group. Closing it would
   * mean reacting to Group's relationship-create event rather than a node
   * resave — see the TODO(group4-VERIFY) on DoNotificationsHooks::nodeInsert().
   * We pin the ACTUAL behavior (no event) so a future wiring change is a
   * deliberate, test-visible decision rather than a silent regression.
   */
  public function testAddToGroupAfterCreationRecordsNoEvent(): void {
    $group = $this->createGroup();

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Created ungrouped',
      'uid' => $this->getCurrentUser()->id(),
      'status' => 1,
    ]);
    $node->save();

    // Drain the node_insert event so we isolate the group-add moment.
    $insert_events = $this->drainQueue();
    $this->assertCount(1, $insert_events, 'Baseline: creation itself recorded exactly one event.');

    // Now add the existing node to the group (v4 cache-tag path, no resave).
    $group->addRelationship($node, 'group_node:post');

    // The relationship really exists (guards against a no-op false negative)...
    $this->assertNotNull(
      $this->getNodeRelationship($group, $node),
      'Sanity: the node IS now related to the group.',
    );
    // ...yet the group-add moment produced no notification event.
    $this->assertSame(
      [],
      $this->drainQueue(),
      'Adding a node to a group after creation fires no hook this module '
      . 'listens to, so no group-scoped notification is recorded (CR 2025-05-23).',
    );
  }

  /**
   * The addNode() fixture (save-then-relate) also records only the bare insert.
   *
   * Documents that even "create a node and immediately relate it" cannot
   * capture group_ids at insert time: addNode() saves the node (node_insert
   * fires here, relationship absent) THEN relates it (no further hook). So the
   * single recorded event still carries an empty group_ids.
   */
  public function testAddNodeFixtureRecordsInsertWithEmptyGroupIds(): void {
    $group = $this->createGroup();
    $node = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Fixture post']);

    $items = $this->drainQueue();
    $this->assertCount(1, $items, 'Only the node_insert event is recorded.');
    $this->assertSame('node_created', $items[0]['event']);
    $this->assertSame($node->id(), $items[0]['entity_id']);
    $this->assertSame(
      [],
      $items[0]['group_ids'],
      'group_ids is empty: the relationship is created after node_insert.',
    );
  }

  /**
   * getGroupIds() resolves the v4 relationship-type ID convention.
   *
   * Asserts the three ID-convention behaviors the issue calls out:
   *  - a node already related to a group resolves to that group's id;
   *  - an unrelated node resolves to empty;
   *  - a wrong/unknown 'type' is swallowed by the catch and yields empty
   *    (never an error) — demonstrated via the `documentation` bundle, whose
   *    installed relationship-type id in this v4 harness is a HASHED id (the
   *    32-char-limit truncation), so the module's hard-coded
   *    `community_group-group_node-doc` query matches nothing and getGroupIds()
   *    returns empty rather than throwing.
   */
  public function testGetGroupIdsResolvesV4RelationshipTypeIds(): void {
    $group = $this->createGroup();

    // A `post` node related to the group: getGroupIds() resolves its group id.
    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Related post']);
    $this->drainQueue();
    $post_gids = $this->callGetGroupIds($post);
    $this->assertSame(
      [$group->id()],
      array_values($post_gids),
      'A related post resolves to exactly its group id via the '
      . 'community_group-group_node-post relationship type.',
    );

    // An UNRELATED post resolves to empty.
    $unrelated = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Unrelated',
      'uid' => $this->getCurrentUser()->id(),
      'status' => 1,
    ]);
    $unrelated->save();
    $this->drainQueue();
    $this->assertSame(
      [],
      $this->callGetGroupIds($unrelated),
      'A node in no group resolves to an empty group_ids array.',
    );

    // A non-node entity short-circuits to empty (getEntityTypeId() !== 'node').
    $this->assertSame(
      [],
      $this->callGetGroupIds($group),
      'A non-node entity resolves to empty.',
    );

    // The `documentation` bundle: module queries the `-doc` alias, but the
    // installed v4 id here is a hash, so the mismatch yields empty via the
    // catch/empty path -- confirming a wrong `type` is SWALLOWED, not fatal.
    $doc = $this->addNode($group, 'documentation', ['status' => 1, 'title' => 'A doc']);
    $this->drainQueue();
    // Guard: the relationship really exists under the installed (hashed) id.
    $this->assertNotNull(
      $this->getNodeRelationship($group, $doc),
      'Sanity: the documentation node IS related to the group.',
    );
    $doc_installed_type = $this->relationshipTypeId('documentation');
    $this->assertNotSame(
      'community_group-group_node-doc',
      $doc_installed_type,
      'In this v4 harness the documentation relationship-type id is derived '
      . '(a hash), not the assembled-config `-doc` alias the module hard-codes.',
    );
    $this->assertSame(
      [],
      $this->callGetGroupIds($doc),
      'The documentation bundle id mismatch is swallowed by the catch and '
      . 'yields an empty group_ids array rather than raising an error '
      . '(the silent-empty behavior the issue flags).',
    );
  }

}
