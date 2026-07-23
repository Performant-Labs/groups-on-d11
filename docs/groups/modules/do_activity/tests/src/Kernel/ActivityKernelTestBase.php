<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\comment\Entity\CommentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\FlagInterface;
use Drupal\message\Entity\Message;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;

/**
 * Shared fixture plumbing for the do_activity kernel test suite (#116).
 *
 * Every log-point test needs the same base stack (message + flag + comment
 * schemas, a comment field on a node bundle, and the three flag fixtures the
 * brief names: rsvp_event, follow_user, pin_in_group), so it lives here once
 * rather than being duplicated across eight files.
 *
 * Fixture config is read from THIS module's own tests/fixtures/config/ dir
 * (never a source-relative ../../../../config path), so it resolves
 * identically in the source worktree and in CI's assembled
 * web/modules/custom/do_activity layout.
 *
 * @group do_activity
 * @group do_tests
 */
abstract class ActivityKernelTestBase extends GroupsKernelTestBase {

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
  ];

  /**
   * The machine name of the comment field attached to the `post` bundle.
   */
  protected const COMMENT_FIELD_NAME = 'field_activity_comments';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('flagging');
    $this->installEntitySchema('message');
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'field']);

    // A comment type + a comment-type field attached to the `post` bundle
    // (already created by GroupsKernelTestBase::setUp() via NODE_BUNDLES), so
    // CommentInsertTest can create real Comment entities against a real node.
    CommentType::create([
      'id' => 'comment',
      'label' => 'Default comments',
      'target_entity_type_id' => 'node',
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'type' => 'comment',
      'field_name' => static::COMMENT_FIELD_NAME,
      'settings' => ['comment_type' => 'comment'],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'post',
      'field_name' => static::COMMENT_FIELD_NAME,
    ])->save();

    // Flag fixtures the brief names explicitly: rsvp_event (node/event),
    // follow_user (user), pin_in_group (node, global). Byte-identical copies
    // of the shipped docs/groups/config/flag.flag.*.yml, installed as test
    // fixtures per the do_group_pin / do_streams precedent (optional config
    // is not auto-installed by kernel module-enable).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $flag_storage = $this->entityTypeManager->getStorage('flag');
    foreach (['flag.flag.rsvp_event', 'flag.flag.follow_user', 'flag.flag.pin_in_group'] as $config_name) {
      $flag_storage->create($fixtures->read($config_name))->save();
    }
  }

  /**
   * Loads a flag entity by id, asserting it exists.
   *
   * @param string $flag_id
   *   The flag machine name.
   *
   * @return \Drupal\flag\FlagInterface
   *   The loaded flag.
   */
  protected function loadFlag(string $flag_id): FlagInterface {
    /** @var \Drupal\flag\FlagInterface|null $flag */
    $flag = $this->entityTypeManager->getStorage('flag')->load($flag_id);
    $this->assertNotNull($flag, "The $flag_id flag fixture is installed.");
    return $flag;
  }

  /**
   * Loads every Message entity currently in storage, freshly queried.
   *
   * @return \Drupal\message\MessageInterface[]
   *   All Message entities, keyed by mid.
   */
  protected function loadAllMessages(): array {
    $storage = $this->entityTypeManager->getStorage('message');
    $storage->resetCache();
    /** @var \Drupal\message\MessageInterface[] $messages */
    $messages = $storage->loadMultiple();
    return $messages;
  }

  /**
   * Filters loaded messages down to a single template id.
   *
   * @param string $template
   *   The message template id.
   *
   * @return \Drupal\message\MessageInterface[]
   *   Matching messages, re-indexed numerically.
   */
  protected function messagesByTemplate(string $template): array {
    return array_values(array_filter(
      $this->loadAllMessages(),
      static fn (Message $m): bool => $m->bundle() === $template,
    ));
  }

}
