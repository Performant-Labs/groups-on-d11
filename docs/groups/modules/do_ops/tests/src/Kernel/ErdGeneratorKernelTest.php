<?php

declare(strict_types=1);

namespace Drupal\Tests\do_ops\Kernel;

use Drupal\do_ops\Erd\ErdGenerator;
use Drupal\Tests\do_activity\Kernel\ActivityKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the entity-metadata walk and the determinism of its output.
 *
 * Extends {@see ActivityKernelTestBase} (do_activity), not the plain
 * `do_tests` `GroupsKernelTestBase` it itself extends, so this suite gets
 * BOTH fixture layers for free: the `community_group` `group_membership` +
 * `group_node:*` relationship types (Group<->User, Group<->content), and
 * do_activity's own real `flag`/`message`/`comment` fixtures — three real
 * `flag.flag.*` config entities (`follow_user`->user, `pin_in_group`->node,
 * `rsvp_event`->node), do_activity's six shipped `message.template.*` (each
 * with a real `field_group_id`->group field), and a real `comment_type`
 * attached to the `post` node bundle. All genuine, installed config — not
 * hand-written stand-ins — for every edge asserted below.
 *
 * @group do_ops
 * @group group
 */
#[RunTestsInSeparateProcesses]
class ErdGeneratorKernelTest extends ActivityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'comment',
    'field',
    'text',
    'filter',
    'group',
    'gnode',
    'flag',
    'message',
    'message_notify',
    'do_activity',
    'do_ops',
  ];

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
   * A leaf entity discovered via a reference field gets its own bundle-of
   * edge as a single extra hop — `node` -> `node_type` — so the diagram
   * shows which Drupal-core bundles actually plug into the group platform,
   * not just the bare entity type. (The same generic `addBundleOfEdge()`
   * code path also produces `taxonomy_term` -> `taxonomy_vocabulary` on a
   * real site — see docs/architecture/groups-erd.md — but this fixture has
   * no taxonomy-referencing field on `group` to exercise that specific
   * pair, so only the `node` case, which the fixture genuinely reaches, is
   * asserted here.)
   */
  public function testLeafEntityShowsItsBundleOfEdge(): void {
    $diagram = $this->generator->generate('group');
    $this->assertStringContainsString('  node }o--|| node_type : "type"', $diagram);
  }

  /**
   * `flagging` is walked (not just added as a leaf), surfacing its real
   * fixture edges: `follow_user` (user), `pin_in_group` + `rsvp_event`
   * (node) via the dynamic `flagged_entity` field, plus `flag_id` -> `flag`
   * and `uid` -> `user` from flagging's own base fields.
   */
  public function testFlaggingEdgesAreDerived(): void {
    $diagram = $this->generator->generate('group');
    $this->assertMatchesRegularExpression('/flagging \}o--(\|\||o\{) flag : "flag_id"/', $diagram);
    $this->assertMatchesRegularExpression('/flagging \}o--(\|\||o\{) user : "flagged_entity"/', $diagram);
    $this->assertMatchesRegularExpression('/flagging \}o--(\|\||o\{) node : "flagged_entity"/', $diagram);
    $this->assertMatchesRegularExpression('/flagging \}o--(\|\||o\{) user : "uid"/', $diagram);
  }

  /**
   * `message` is walked, surfacing do_activity's real `field_group_id`
   * field (present on the `activity_post_created` / `activity_membership_
   * created` templates) resolving to `group`.
   */
  public function testMessageEdgeToGroupIsDerived(): void {
    $diagram = $this->generator->generate('group');
    $this->assertMatchesRegularExpression('/message \}o--(\|\||o\{) group : "field_group_id"/', $diagram);
    $this->assertMatchesRegularExpression('/message \}o--(\|\||o\{) user : "uid"/', $diagram);
  }

  /**
   * `comment` is walked, surfacing its dynamic `entity_id` field resolving
   * to `node` for the real `comment` comment_type attached to the `post`
   * bundle by the do_activity fixture.
   */
  public function testCommentEdgeToNodeIsDerived(): void {
    $diagram = $this->generator->generate('group');
    $this->assertMatchesRegularExpression('/comment \}o--(\|\||o\{) node : "entity_id"/', $diagram);
  }

  /**
   * An unknown scope is rejected rather than silently defaulting.
   */
  public function testInvalidScopeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generator->generate('bogus');
  }

}
