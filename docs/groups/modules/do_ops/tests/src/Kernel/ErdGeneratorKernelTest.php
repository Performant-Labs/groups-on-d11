<?php

declare(strict_types=1);

namespace Drupal\Tests\do_ops\Kernel;

use Drupal\do_ops\Erd\ErdGenerator;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the entity-metadata walk and the determinism of its output.
 *
 * Uses {@see GroupsKernelTestBase}'s `community_group` fixture (a real
 * `group_membership` relationship type plus `group_node:*` relationship
 * types for every bundle in `NODE_BUNDLES`) so the assertions below exercise
 * genuine, installed `group_relationship_type` config — not a hand-written
 * stand-in — for both edges the brief calls out as the ones a diagram must
 * not miss: Group<->User via membership, Group<->content via
 * group_relationship.
 *
 * @group do_ops
 * @group group
 */
#[RunTestsInSeparateProcesses]
class ErdGeneratorKernelTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_ops'];

  private ErdGenerator $generator;

  protected function setUp(): void {
    parent::setUp();
    $this->generator = $this->container->get('do_ops.erd_generator');
  }

  /**
   * Running the generator twice against unchanged config is byte-identical.
   *
   * This is the property the CI "regenerate and diff" guard depends on.
   */
  public function testOutputIsDeterministic(): void {
    $first = $this->generator->generate('group');
    $second = $this->generator->generate('group');
    $this->assertSame($first, $second);

    $first_all = $this->generator->generate('all');
    $second_all = $this->generator->generate('all');
    $this->assertSame($first_all, $second_all);
  }

  /**
   * The output is a well-formed Mermaid erDiagram.
   */
  public function testOutputIsValidMermaidShape(): void {
    $diagram = $this->generator->generate('group');
    $this->assertStringStartsWith('erDiagram', $diagram);
    // Every non-blank line is either an entity/attribute block line or a
    // `from RELATIONSHIP to : "field"` edge line.
    foreach (explode("\n", trim($diagram)) as $line) {
      $this->assertMatchesRegularExpression(
        '/^(erDiagram|\s*[a-z0-9_]+ \{|\s*string label ".*"|\s*\}|\s*[a-z0-9_]+ \}o--(\|\||o\{) [a-z0-9_]+ : ".+")$/',
        $line,
        "Unexpected Mermaid line shape: {$line}",
      );
    }
  }

  /**
   * The group scope diagram contains all five seed entity type boxes.
   */
  public function testGroupScopeIncludesSeedEntityTypes(): void {
    $diagram = $this->generator->generate('group');
    foreach (ErdGenerator::GROUP_SCOPE_ENTITY_TYPES as $entity_type_id) {
      $this->assertStringContainsString("  {$entity_type_id} {\n", $diagram, "Missing entity box for {$entity_type_id}");
    }
  }

  /**
   * Group<->User via membership: `group_relationship`'s `entity_id` field
   * resolves to `user` for the (enforced) `group_membership` relationship
   * type, verified against the real installed config entity, not a stub.
   */
  public function testMembershipEdgeToUserIsDerived(): void {
    $rt_storage = $this->entityTypeManager->getStorage('group_relationship_type');
    $membership_type_id = $rt_storage->getRelationshipTypeId(static::GROUP_TYPE_ID, 'group_membership');
    $this->assertNotNull($membership_type_id, 'group_membership relationship type was not installed by the fixture.');

    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('group_relationship', $membership_type_id);
    $this->assertSame('user', $fields['entity_id']->getFieldStorageDefinition()->getSetting('target_type'));

    $diagram = $this->generator->generate('group');
    $this->assertStringContainsString('group_relationship }o--', $diagram);
    $this->assertMatchesRegularExpression('/group_relationship \}o--(\|\||o\{) user : "entity_id"/', $diagram);
  }

  /**
   * Group<->Content via group_relationship: at least one `group_node:*`
   * relationship type (installed by the base fixture for every bundle in
   * NODE_BUNDLES) resolves `entity_id` to `node`.
   */
  public function testContentEdgeToNodeIsDerived(): void {
    $node_type = static::NODE_BUNDLES['page'];
    $relationship_type_id = $this->relationshipTypeId($node_type);

    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('group_relationship', $relationship_type_id);
    $this->assertSame('node', $fields['entity_id']->getFieldStorageDefinition()->getSetting('target_type'));

    $diagram = $this->generator->generate('group');
    $this->assertMatchesRegularExpression('/group_relationship \}o--(\|\||o\{) node : "entity_id"/', $diagram);
  }

  /**
   * `group_relationship`'s `gid` field always resolves to `group`.
   */
  public function testGroupRelationshipToGroupEdgeIsDerived(): void {
    $diagram = $this->generator->generate('group');
    $this->assertMatchesRegularExpression('/group_relationship \}o--\|\| group : "gid"/', $diagram);
  }

  /**
   * An unknown scope is rejected rather than silently defaulting.
   */
  public function testInvalidScopeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generator->generate('bogus');
  }

}
