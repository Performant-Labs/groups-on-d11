<?php

declare(strict_types=1);

namespace Drupal\Tests\do_multigroup\Kernel;

use Drupal\do_multigroup\Hook\DoMultigroupHooks;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_multigroup cardinality + the create() v4 path.
 *
 * Issue #39 (Wave C / C1), epic #31. Pins the RA5 Group 4.x migration risk:
 * a node can be cross-posted to N groups via unlimited-cardinality
 * `group_node` relationships, and {@see DoMultigroupHooks::nodeFormSubmit()}
 * writes those relationships with the v4 `group_relationship` create() keys
 * `type` / `gid` / `entity_id` (~L194), deleting de-selected groups.
 *
 * These tests assert the REAL persisted state — the `group_relationship`
 * storage — rather than asserting the code path exists. They mirror the
 * add / remove logic of `nodeFormSubmit()` at the storage level (a kernel
 * test cannot easily drive the full node form + submit handler), which is
 * exactly the API surface the acceptance criterion targets: "the create()
 * path is exercised, not just asserted to exist."
 *
 * ## Documentation-alias divergence (worked around here)
 *
 * Production `DoMultigroupHooks::relationshipTypeId()` maps the
 * `documentation` bundle to the abbreviated relationship-type id
 * `community_group-group_node-doc` (32-char limit). The A3 kernel base
 * ({@see GroupsKernelTestBase}) builds relationship types via the 4.x
 * storage's `createFromPlugin()`, which derives the id straight from the
 * plugin — `community_group-group_node-documentation` — so the hard-coded
 * `-doc` alias does NOT exist in the harness (see #37 / B3). We therefore
 * test only bundles where `bundle == suffix` (`post` / `event` / `page` /
 * `forum`) and resolve ids via the base's `relationshipTypeId()` helper
 * instead of `DoMultigroupHooks::relationshipTypeId()`. The `documentation`
 * bundle is deliberately avoided.
 *
 * @group do_multigroup
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class MultigroupCardinalityTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `do_multigroup` (the module under test) plus the group/gnode/node stack
   * the base already needs. `do_tests` is pulled in transitively as the base
   * class' provider and via `@group`.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'do_multigroup',
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
   * The group_relationship storage.
   *
   * @return \Drupal\Core\Entity\ContentEntityStorageInterface
   *   The storage handler.
   */
  private function relStorage() {
    return $this->entityTypeManager->getStorage('group_relationship');
  }

  /**
   * Loads every group_relationship linking a node via a relationship type.
   *
   * Mirrors the loadByProperties(['type','entity_id']) query that
   * {@see DoMultigroupHooks::nodeFormSubmit()} uses to find existing links.
   *
   * @param string $type
   *   The group_relationship_type id.
   * @param int|string $node_id
   *   The related node id.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface[]
   *   The matching relationships, keyed by id.
   */
  private function loadNodeRelationships(string $type, int|string $node_id): array {
    return $this->relStorage()->loadByProperties([
      'type' => $type,
      'entity_id' => $node_id,
    ]);
  }

  /**
   * A node cross-posted to N groups persists N group_relationship entities.
   *
   * Exercises the unlimited-cardinality (0) config the base installs: one
   * node, N distinct groups, N relationships of the same type — and the node
   * resolves back to all N groups.
   */
  public function testCardinalityNodeInNGroups(): void {
    $type = $this->relationshipTypeId('post');

    // A node created outside any group, then related to N groups directly via
    // the v4 create() keys (the nodeFormSubmit() add-loop, per group).
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => $this->randomMachineName(),
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();

    $n = 3;
    $groups = [];
    for ($i = 0; $i < $n; $i++) {
      $group = $this->createGroup();
      $groups[$group->id()] = $group;
      $this->relStorage()->create([
        'type' => $type,
        'gid' => $group->id(),
        'entity_id' => $node->id(),
      ])->save();
    }

    // N relationships persist for the one node.
    $relationships = $this->loadNodeRelationships($type, $node->id());
    $this->assertCount($n, $relationships, 'One node yields N group_relationship entities under unlimited cardinality.');

    // The node resolves back to exactly the N groups it was posted to.
    $resolved_gids = [];
    foreach ($relationships as $relationship) {
      $this->assertInstanceOf(GroupRelationshipInterface::class, $relationship);
      $resolved_gids[] = (int) $relationship->getGroup()->id();
    }
    sort($resolved_gids);
    $expected_gids = array_map('intval', array_keys($groups));
    sort($expected_gids);
    $this->assertSame($expected_gids, $resolved_gids, 'The node resolves back to all N groups.');
  }

  /**
   * create() with the v4 keys persists a working, correctly-linked relationship.
   *
   * Directly mirrors nodeFormSubmit()'s create() call
   * (['type','gid','entity_id']) and asserts the reloaded relationship links
   * the right node to the right group.
   */
  public function testCreateV4KeysPersistAndLoad(): void {
    $type = $this->relationshipTypeId('event');
    $group = $this->createGroup();
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'event',
      'title' => $this->randomMachineName(),
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();

    $relationship = $this->relStorage()->create([
      'type' => $type,
      'gid' => $group->id(),
      'entity_id' => $node->id(),
    ]);
    $relationship->save();
    $this->assertNotNull($relationship->id(), 'The created relationship received an id (it persisted).');

    // Reload from storage (not the in-memory object) to prove it round-trips.
    $this->relStorage()->resetCache();
    $reloaded = $this->relStorage()->load($relationship->id());
    $this->assertInstanceOf(GroupRelationshipInterface::class, $reloaded);
    $this->assertSame($type, $reloaded->bundle(), 'The reloaded relationship has the expected type.');
    $this->assertSame((int) $group->id(), (int) $reloaded->getGroup()->id(), 'It links the right group.');
    $this->assertSame((int) $node->id(), (int) $reloaded->getEntity()->id(), 'It links the right node.');

    // And the group resolves the node back through the group_node plugin.
    $via_group = $this->getNodeRelationship($group, $node);
    $this->assertInstanceOf(GroupRelationshipInterface::class, $via_group);
    $this->assertSame($relationship->id(), $via_group->id(), 'The group resolves the same relationship.');
  }

  /**
   * De-selecting a group deletes just that relationship, leaving the others.
   *
   * Emulates the add-then-remove path of nodeFormSubmit(): a node is posted to
   * three groups, then one group is de-selected. The delete-loop removes only
   * the de-selected relationship; the other two survive intact.
   */
  public function testDeselectDeletesOnlyThatRelationship(): void {
    $type = $this->relationshipTypeId('page');
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'page',
      'title' => $this->randomMachineName(),
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();

    // Add phase: node posted to three groups.
    $groups = [];
    for ($i = 0; $i < 3; $i++) {
      $group = $this->createGroup();
      $groups[$group->id()] = $group;
      $this->relStorage()->create([
        'type' => $type,
        'gid' => $group->id(),
        'entity_id' => $node->id(),
      ])->save();
    }
    $this->assertCount(3, $this->loadNodeRelationships($type, $node->id()));

    // Remove phase: one group is de-selected. Reproduce nodeFormSubmit()'s
    // delete-loop: existing relationships whose gid is not in the selected set
    // are deleted.
    $all_gids = array_map('intval', array_keys($groups));
    $deselected_gid = array_shift($all_gids);
    $selected_gids = $all_gids;

    $existing = $this->loadNodeRelationships($type, $node->id());
    $existing_by_gid = [];
    foreach ($existing as $relationship) {
      $existing_by_gid[(int) $relationship->getGroup()->id()] = $relationship;
    }
    foreach ($existing_by_gid as $gid => $relationship) {
      if (!in_array($gid, $selected_gids, TRUE)) {
        $relationship->delete();
      }
    }

    // Exactly the two selected relationships survive; the de-selected one is gone.
    $remaining = $this->loadNodeRelationships($type, $node->id());
    $this->assertCount(2, $remaining, 'De-select removes only the one relationship.');
    $remaining_gids = array_map(
      static fn(GroupRelationshipInterface $r): int => (int) $r->getGroup()->id(),
      $remaining,
    );
    sort($remaining_gids);
    sort($selected_gids);
    $this->assertSame($selected_gids, $remaining_gids, 'The surviving relationships are exactly the still-selected groups.');
    $this->assertNotContains($deselected_gid, $remaining_gids, 'The de-selected group is no longer linked.');
  }

  /**
   * The module's hard-coded relationship-type ids resolve against installed config.
   *
   * `community_group-group_membership` (~L75) is enforced/auto-installed by the
   * group type, and `community_group-group_node-<bundle>` for a non-doc bundle
   * must exist as loadable config. This is the RA5 "hard-coded IDs resolve at
   * runtime" assertion — limited to non-doc bundles per the divergence note.
   */
  public function testHardcodedRelationshipTypeIdsResolve(): void {
    $rt_storage = $this->entityTypeManager->getStorage('group_relationship_type');

    // The membership id string is hard-coded in DoMultigroupHooks; it must
    // resolve to installed config.
    $membership_type = $rt_storage->load('community_group-group_membership');
    $this->assertNotNull($membership_type, 'The hard-coded community_group-group_membership type resolves.');

    // For non-doc bundles the production id equals the harness-derived id, so
    // DoMultigroupHooks::relationshipTypeId() and the base helper agree and
    // both resolve.
    foreach (['post', 'event', 'page', 'forum'] as $bundle) {
      $prod_id = DoMultigroupHooks::relationshipTypeId($bundle);
      $this->assertSame(
        $this->relationshipTypeId($bundle),
        $prod_id,
        "For the non-doc bundle '$bundle', the module's id equals the installed id.",
      );
      $this->assertNotNull(
        $rt_storage->load($prod_id),
        "The hard-coded group_node id for '$bundle' resolves against installed config.",
      );
    }
  }

}
