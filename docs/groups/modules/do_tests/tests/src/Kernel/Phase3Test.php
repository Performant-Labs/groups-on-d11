<?php

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Phase 3 integration tests — Multi-group & Stream.
 *
 * @group do_tests
 */
class Phase3Test extends KernelTestBase {

  protected static $modules = ['system', 'field', 'text', 'taxonomy', 'user', 'node'];

  protected $syncStorage;

  protected function setUp(): void {
    parent::setUp();
    $this->syncStorage = new \Drupal\Core\Config\FileStorage(DRUPAL_ROOT . '/../config/sync');
  }

  /**
   * Tests multi-group cardinality on all 5 relationship types.
   */
  public function testMultiGroupCardinality(): void {
    $types = ['forum', 'doc', 'event', 'post', 'page'];
    foreach ($types as $type) {
      $data = $this->syncStorage->read("group.relationship_type.community_group-group_node-$type");
      $this->assertNotEmpty($data, "Relationship type $type exists");
      $this->assertEquals(0, $data['plugin_config']['entity_cardinality'], "$type has unlimited cardinality");
    }
  }

  /**
   * Tests group_tags vocabulary exists.
   */
  public function testGroupTagsVocabulary(): void {
    $data = $this->syncStorage->read('taxonomy.vocabulary.group_tags');
    $this->assertNotEmpty($data, 'group_tags vocabulary exists');
  }

  /**
   * Tests field_group_tags exists on all 5 content types.
   */
  public function testFieldGroupTags(): void {
    $types = ['forum', 'documentation', 'event', 'post', 'page'];
    foreach ($types as $type) {
      $data = $this->syncStorage->read("field.field.node.$type.field_group_tags");
      $this->assertNotEmpty($data, "field_group_tags exists on $type");
    }
  }

  /**
   * Tests group content stream view exists.
   */
  public function testGroupContentStreamView(): void {
    $data = $this->syncStorage->read('views.view.group_content_stream');
    $this->assertNotEmpty($data, 'group_content_stream view exists');
  }

  /**
   * Tests stream_card view mode exists.
   */
  public function testStreamCardViewMode(): void {
    $data = $this->syncStorage->read('core.entity_view_mode.node.stream_card');
    $this->assertNotEmpty($data, 'stream_card view mode exists');
    $this->assertEquals('Stream Card', $data['label']);
  }

  /**
   * Tests activity_stream view exists with /stream path.
   */
  public function testActivityStreamView(): void {
    $data = $this->syncStorage->read('views.view.activity_stream');
    $this->assertNotEmpty($data, 'activity_stream view exists');
    $this->assertEquals('Activity Stream', $data['label']);
    $this->assertEquals('stream', $data['display']['page_1']['display_options']['path']);
  }

  /**
   * Tests do_multigroup is enabled.
   */
  public function testDoMultigroupEnabled(): void {
    $ext = $this->syncStorage->read('core.extension');
    $this->assertArrayHasKey('do_multigroup', $ext['module'], 'do_multigroup is enabled');
  }

  /**
   * Tests tags_aggregation view exists.
   */
  public function testTagsAggregationView(): void {
    $data = $this->syncStorage->read('views.view.tags_aggregation');
    $this->assertNotEmpty($data, 'tags_aggregation view exists');
  }

}
