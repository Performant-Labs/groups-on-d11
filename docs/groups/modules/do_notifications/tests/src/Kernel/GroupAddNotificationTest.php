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
   * RA3 — adding an existing node to a group AFTER creation records an event.
   *
   * The central B3 behavior for change record 2025-05-23, now CLOSED (#37). We
   * create a published node NOT in any group (its node_created event is drained
   * away), then relate it to a group via the v4 relationship-create path
   * ($group->addRelationship(...)). In Group 4.x that operation invalidates the
   * node's cache tags and does NOT resave the node — so a node_update-based
   * approach would miss it. do_notifications instead reacts to the
   * group_relationship INSERT (see DoNotificationsHooks::groupRelationshipInsert()),
   * which fires on this path, so the group-add moment now records exactly one
   * group-scoped `added_to_group` event carrying the node and the real group id.
   *
   * This is the product decision from #37: members SHOULD be notified when an
   * existing post is cross-posted into their group.
   */
  public function testAddToGroupAfterCreationRecordsEvent(): void {
    $group = $this->createGroup();

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Created ungrouped',
      'uid' => $this->getCurrentUser()->id(),
      'status' => 1,
    ]);
    $node->save();

    // Drain the node_created (content) event so we isolate the group-add moment.
    $insert_events = $this->drainQueue();
    $this->assertCount(1, $insert_events, 'Baseline: creation itself recorded exactly one event.');
    $this->assertSame('node_created', $insert_events[0]['event']);

    // Now add the existing node to the group (v4 cache-tag path, no resave).
    $group->addRelationship($node, 'group_node:post');

    // The relationship really exists (guards against a no-op false positive)...
    $this->assertNotNull(
      $this->getNodeRelationship($group, $node),
      'Sanity: the node IS now related to the group.',
    );
    // ...and the group-add moment recorded exactly one group-scoped event
    // carrying the node and the group it was added to.
    $add_events = $this->drainQueue();
    $this->assertCount(
      1,
      $add_events,
      'Adding a node to a group records exactly one group-scoped event via the '
      . 'group_relationship insert hook (CR 2025-05-23 gap now closed, #37).',
    );
    $event = $add_events[0];
    $this->assertSame('added_to_group', $event['event']);
    $this->assertSame('node', $event['entity_type']);
    $this->assertSame($node->id(), $event['entity_id']);
    $this->assertSame('post', $event['bundle']);
    $this->assertSame($this->getCurrentUser()->id(), $event['author_uid']);
    $this->assertSame(
      [$group->id()],
      array_values($event['group_ids']),
      'The event carries exactly the group the node was added to.',
    );
    $this->assertArrayHasKey('timestamp', $event);
  }

  /**
   * The addNode() fixture (save-then-relate) records BOTH events.
   *
   * addNode() saves the node (node_insert fires: `node_created`, relationship
   * absent, empty group_ids) THEN relates it (group_relationship insert fires:
   * `added_to_group`, carrying the real group id). This is the "created directly
   * in a group" flow, and it is covered uniformly by the same relationship-insert
   * handler as the cross-post case: one content event + one group event, never a
   * duplicated group-scoped event.
   */
  public function testAddNodeFixtureRecordsContentAndGroupEvents(): void {
    $group = $this->createGroup();
    $node = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Fixture post']);

    $items = $this->drainQueue();
    $this->assertCount(2, $items, 'Both the content and the group event are recorded.');

    // Index by event name so ordering is not asserted brittlely.
    $by_event = [];
    foreach ($items as $item) {
      $by_event[$item['event']] = $item;
    }

    $this->assertArrayHasKey('node_created', $by_event, 'The content event is recorded.');
    $this->assertSame($node->id(), $by_event['node_created']['entity_id']);
    $this->assertSame(
      [],
      $by_event['node_created']['group_ids'],
      'node_created group_ids is empty: the relationship is created after node_insert.',
    );

    $this->assertArrayHasKey('added_to_group', $by_event, 'The group event is recorded.');
    $this->assertSame($node->id(), $by_event['added_to_group']['entity_id']);
    $this->assertSame(
      [$group->id()],
      array_values($by_event['added_to_group']['group_ids']),
      'added_to_group carries the real group id from the relationship insert.',
    );
  }

  /**
   * Adding a MEMBER to a group records no content event (membership excluded).
   *
   * The group_relationship insert handler must react to group_node:* content
   * relationships only, never to group_membership — a member joining is not an
   * "added to group" content notification. addMember() creates a
   * group_membership relationship; the queue must stay empty.
   */
  public function testAddMemberRecordsNoAddedToGroupEvent(): void {
    $group = $this->createGroup();
    $this->drainQueue();

    $account = $this->createUser();
    $this->addMember($group, $account);

    $this->assertSame(
      [],
      $this->drainQueue(),
      'A membership relationship insert records no added_to_group event.',
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
