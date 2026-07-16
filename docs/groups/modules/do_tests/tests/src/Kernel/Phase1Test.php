<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\group\Entity\GroupRelationshipType;
use Drupal\group\Entity\GroupType;

/**
 * Phase 1 behavioral tests — Foundation, group type & relationship types.
 *
 * Converted from config-existence (reading `config/sync` YAML) to behavioral:
 * every assertion runs against the *installed* group type and relationship-type
 * config entities the {@see GroupsKernelTestBase} reconstructs via the 4.x
 * storage APIs, and at least one test exercises the create-group / add-member /
 * add-node fixtures end to end.
 *
 * The tightened `relation_type` (v4) / no-`content_plugin` (legacy 3.x)
 * assertion from #28/#29 is preserved — now read off the loaded
 * group_relationship_type entity rather than the YAML file.
 *
 * @group do_tests
 */
class Phase1Test extends GroupsKernelTestBase {

  /**
   * The community_group group type is installed with the expected identity.
   */
  public function testCommunityGroupTypeInstalled(): void {
    $type = GroupType::load(self::GROUP_TYPE_ID);
    $this->assertNotNull($type, 'community_group group type is installed.');
    $this->assertSame('Community Group', (string) $type->label());
    $this->assertSame('community_group', $type->id());
  }

  /**
   * Each group_node relationship type resolves a v4 relation plugin.
   */
  public function testGroupNodeRelationshipTypesResolveV4Plugin(): void {
    foreach (self::NODE_BUNDLES as $node_type) {
      $id = $this->relationshipTypeId($node_type);
      $rt = GroupRelationshipType::load($id);
      $this->assertNotNull($rt, "Relationship type $id is installed.");
      // v4 stores the plugin id under relation_type (CR 2026-06-19); assert the
      // resolved plugin id, not a raw config key, and that it targets group_node.
      $this->assertSame('group_node:' . $node_type, $rt->getPluginId(), "$id resolves group_node:$node_type");
      $this->assertNotNull($rt->getPlugin(), "$id resolves a live relation plugin instance.");
    }
  }

  /**
   * The membership relationship type resolves the group_membership plugin.
   */
  public function testGroupMembershipRelationshipType(): void {
    $rt = GroupRelationshipType::load('community_group-group_membership');
    $this->assertNotNull($rt, 'Membership relationship type is installed.');
    $this->assertSame('group_membership', $rt->getPluginId());
  }

  /**
   * The fixtures work end to end: create a group, add a member and a node.
   *
   * This is the proof the behavioral base functions — real entities are saved
   * and read back from the live DB, not config asserted off disk.
   */
  public function testFixturesCreateLiveEntities(): void {
    $group = $this->createGroup(['label' => 'Fixture group']);
    $this->assertNotNull($group->id(), 'Group was saved to the DB.');
    $this->assertSame(self::GROUP_TYPE_ID, $group->bundle());

    // API membership path.
    $member = $this->createUser();
    $this->addMember($group, $member);
    $this->assertNotNull($group->getMember($member), 'addMember() created a membership.');

    // v4 create-relationship node path.
    $node = $this->addNode($group, 'event', ['title' => 'Fixture event']);
    $this->assertNotNull($node->id(), 'Node was saved.');
    $this->assertSame('event', $node->bundle());
    $relationship = $this->getNodeRelationship($group, $node);
    $this->assertNotNull($relationship, 'Node is related to the group via group_node:event.');
    $this->assertSame($node->id(), $relationship->getEntity()->id());
  }

}
