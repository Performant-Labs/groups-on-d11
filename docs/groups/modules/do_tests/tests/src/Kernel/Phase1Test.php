<?php

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;

/**
 * Phase 1 integration tests — Foundation & Module Installation.
 *
 * Verifies all config entities created during Phase 1 exist in config/sync.
 *
 * @group do_tests
 */
class Phase1Test extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'field',
    'text',
    'taxonomy',
    'user',
    'node',
  ];

  /**
   * The config/sync FileStorage.
   *
   * @var \Drupal\Core\Config\FileStorage
   */
  protected $syncStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $config_path = DRUPAL_ROOT . '/../config/sync';
    $this->syncStorage = new \Drupal\Core\Config\FileStorage($config_path);
  }

  /**
   * Tests that the community_group group type config exists in sync.
   */
  public function testGroupTypeExists(): void {
    $data = $this->syncStorage->read('group.type.community_group');
    $this->assertNotEmpty($data, 'group.type.community_group exists in config/sync');
    $this->assertEquals('Community Group', $data['label']);
    $this->assertEquals('community_group', $data['id']);
  }

  /**
   * Tests that all 5 group-node relationship type configs exist.
   */
  public function testRelationshipTypesExist(): void {
    $expected = [
      'community_group-group_node-forum',
      'community_group-group_node-doc',
      'community_group-group_node-event',
      'community_group-group_node-post',
      'community_group-group_node-page',
    ];
    foreach ($expected as $id) {
      $data = $this->syncStorage->read("group.relationship_type.$id");
      $this->assertNotEmpty($data, "Relationship type $id exists in config/sync");
      $this->assertRelationPlugin($data, $id);
    }
  }

  /**
   * Tests that the group membership relationship type exists.
   */
  public function testGroupMembershipType(): void {
    $data = $this->syncStorage->read('group.relationship_type.community_group-group_membership');
    $this->assertNotEmpty($data, 'Membership relationship type exists in config/sync');
    $this->assertRelationPlugin($data, 'community_group-group_membership');
  }

  /**
   * Asserts a group.relationship_type config carries a relation plugin id.
   *
   * Group 4.x renamed the stored property that holds the relation plugin id
   * from `content_plugin` to `relation_type` (CR 2026-06-19, 4.0.0-alpha2); an
   * update hook rewrites exported config. Accept either spelling so this suite
   * stays green across the migration: `content_plugin` on 3.x-era exports and
   * `relation_type` once the config has been re-exported under 4.x.
   *
   * TODO(group4-VERIFY): once config/sync has been re-exported on Group 4.x,
   * tighten this to require `relation_type` and forbid the legacy
   * `content_plugin` key, so a stale 3.x export is caught as a regression.
   *
   * @param array $data
   *   The decoded relationship_type config.
   * @param string $id
   *   The relationship type id, for the failure message.
   */
  protected function assertRelationPlugin(array $data, string $id): void {
    $has_plugin = array_key_exists('relation_type', $data)
      || array_key_exists('content_plugin', $data);
    $this->assertTrue(
      $has_plugin,
      "Relationship type $id declares a relation plugin id (relation_type on Group 4.x, content_plugin on 3.x)"
    );
  }

  /**
   * Tests that group field storage and instances exist in config/sync.
   */
  public function testGroupFields(): void {
    $fields = [
      'field_group_description',
      'field_group_visibility',
      'field_group_image',
    ];
    foreach ($fields as $field_name) {
      $storage_data = $this->syncStorage->read("field.storage.group.$field_name");
      $this->assertNotEmpty($storage_data, "Field storage $field_name exists");

      $instance_data = $this->syncStorage->read("field.field.group.community_group.$field_name");
      $this->assertNotEmpty($instance_data, "Field instance $field_name exists on community_group");
    }
  }

  /**
   * Tests that the event_types vocabulary config exists.
   */
  public function testEventTypesVocabulary(): void {
    $data = $this->syncStorage->read('taxonomy.vocabulary.event_types');
    $this->assertNotEmpty($data, 'event_types vocabulary exists in config/sync');
    $this->assertEquals('Event Types', $data['name']);
  }

  /**
   * Tests that the group-listing View config exists.
   *
   * The canonical directory view is `all_groups` (label "All Groups") published
   * at `/all-groups` — see RUNBOOK Step 160. There is no `views.view.groups`;
   * this matches Phase2Test::testAllGroupsView.
   */
  public function testGroupsViewExists(): void {
    $data = $this->syncStorage->read('views.view.all_groups');
    $this->assertNotEmpty($data, 'all_groups view exists in config/sync');
    $this->assertEquals('All Groups', $data['label']);
  }

  /**
   * Tests that group admin role config exists.
   */
  public function testGroupAdminRoleExists(): void {
    $data = $this->syncStorage->read('group.role.community_group-admin');
    $this->assertNotEmpty($data, 'community_group-admin role exists in config/sync');
  }

}
