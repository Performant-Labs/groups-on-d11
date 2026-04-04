<?php

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Phase 2 integration tests — Group Types & Membership Models.
 *
 * @group do_tests
 */
class Phase2Test extends KernelTestBase {

  protected static $modules = ['system', 'field', 'text', 'taxonomy', 'user', 'node'];

  protected $syncStorage;

  protected function setUp(): void {
    parent::setUp();
    $config_path = DRUPAL_ROOT . '/../config/sync';
    $this->syncStorage = new \Drupal\Core\Config\FileStorage($config_path);
  }

  /**
   * Tests group_type vocabulary config exists.
   */
  public function testGroupTypeVocabulary(): void {
    $data = $this->syncStorage->read('taxonomy.vocabulary.group_type');
    $this->assertNotEmpty($data, 'group_type vocabulary exists in config/sync');
    $this->assertEquals('Group Type', $data['name']);
  }

  /**
   * Tests field_group_type exists on community_group.
   */
  public function testFieldGroupType(): void {
    $storage = $this->syncStorage->read('field.storage.group.field_group_type');
    $this->assertNotEmpty($storage, 'field_group_type storage exists');
    $this->assertEquals('entity_reference', $storage['type']);

    $instance = $this->syncStorage->read('field.field.group.community_group.field_group_type');
    $this->assertNotEmpty($instance, 'field_group_type instance exists on community_group');
  }

  /**
   * Tests all_groups View config exists.
   */
  public function testAllGroupsView(): void {
    $data = $this->syncStorage->read('views.view.all_groups');
    $this->assertNotEmpty($data, 'all_groups view exists in config/sync');
    $this->assertEquals('All Groups', $data['label']);
  }

  /**
   * Tests pending_groups View config exists.
   */
  public function testPendingGroupsView(): void {
    $data = $this->syncStorage->read('views.view.pending_groups');
    $this->assertNotEmpty($data, 'pending_groups view exists in config/sync');
    $this->assertEquals('Pending Groups', $data['label']);
  }

  /**
   * Tests do_group_extras module is in core.extension.
   */
  public function testDoGroupExtrasEnabled(): void {
    $ext = $this->syncStorage->read('core.extension');
    $this->assertNotEmpty($ext, 'core.extension exists');
    $this->assertArrayHasKey('do_group_extras', $ext['module'], 'do_group_extras is enabled');
  }

}
