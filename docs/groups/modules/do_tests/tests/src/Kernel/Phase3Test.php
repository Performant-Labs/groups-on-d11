<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\group\Entity\GroupRelationshipType;

/**
 * Phase 3 behavioral tests — Multi-group relationship behavior.
 *
 * Converted from config-existence to behavioral. The one Phase-3 assertion with
 * migration value — unlimited group_node cardinality, which lets a node live in
 * many groups (do_multigroup) — is now proven two ways: (1) read off the
 * *installed* relationship-type plugin_config, and (2) exercised by actually
 * relating one node to two groups via the fixtures.
 *
 * Retired (moved out of this behavioral suite): the Phase-3 checks that only
 * asserted assembled YAML *ships* in config/sync with no behavior —
 * group_tags / stream vocabularies, the group_content_stream / activity_stream
 * / tags_aggregation views, the stream_card view mode, field_group_tags on the
 * five content types, and `do_multigroup` being listed in core.extension. Those
 * are config-artifact smoke checks, not behavior, and belong to the per-module
 * behavioral tests in Wave C (#39 do_multigroup) / the clean-room config:import
 * gate — not the integration base. The v4 relation_type/no-content_plugin
 * tightening from #28/#29 lives on in {@see Phase1Test}.
 *
 * Group 4.x note: creator auto-membership is now form-only (CR 2026-04-24); the
 * API path (Group::create()->save()) does not auto-add the creator. Tests that
 * need a creator membership must call addMember() explicitly — see the base's
 * addMember() fixture.
 *
 * @group do_tests
 */
class Phase3Test extends GroupsKernelTestBase {

  /**
   * Every group_node relationship type has unlimited entity cardinality.
   *
   * Read off the installed relationship-type plugin_config (cardinality 0 =
   * unlimited), the precondition for a node belonging to multiple groups.
   */
  public function testGroupNodeUnlimitedCardinality(): void {
    foreach (self::NODE_BUNDLES as $node_type) {
      $rt = GroupRelationshipType::load($this->relationshipTypeId($node_type));
      $this->assertNotNull($rt, "Relationship type for $node_type is installed.");
      $config = $rt->get('plugin_config');
      $this->assertSame(0, (int) $config['entity_cardinality'], "$node_type allows a node in unlimited groups.");
    }
  }

  /**
   * A single node can be related to two groups (multigroup behavior).
   *
   * Proves the unlimited-cardinality config actually permits the v4
   * create-relationship path to place one node in more than one group.
   */
  public function testNodeCanBelongToMultipleGroups(): void {
    $group_a = $this->createGroup(['label' => 'Group A']);
    $group_b = $this->createGroup(['label' => 'Group B']);

    $node = $this->addNode($group_a, 'post', ['title' => 'Shared post']);
    // Relate the same node to a second group.
    $group_b->addRelationship($node, 'group_node:post');

    $this->assertNotNull($this->getNodeRelationship($group_a, $node), 'Node is in group A.');
    $this->assertNotNull($this->getNodeRelationship($group_b, $node), 'Node is also in group B.');
  }

}
