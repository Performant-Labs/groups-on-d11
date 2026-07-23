<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

/**
 * Log point 5/6 — "group create" (#116 amended brief, log points item 5).
 *
 * Pins `#[Hook('group_insert')]`. Actor = the group owner, refs = group.
 *
 * Template id under test: `activity_group_created`.
 *
 * @group do_activity
 * @group do_tests
 */
class GroupCreateTest extends ActivityKernelTestBase {

  /**
   * Creating a group records exactly one Message, actor = owner, ref = group.
   */
  public function testGroupCreateRecordsOneMessage(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);

    $group = $this->createGroup(['uid' => $owner->id(), 'label' => 'DrupalCon Planning']);

    $messages = $this->messagesByTemplate('activity_group_created');
    $this->assertCount(1, $messages, 'Exactly one activity_group_created Message is recorded.');

    $message = reset($messages);
    $this->assertSame(
      (int) $owner->id(),
      (int) $message->getOwnerId(),
      'The Message actor is the group owner.'
    );
    $this->assertSame('group', $message->get('field_referenced_entity_type')->value);
    $this->assertSame((int) $group->id(), (int) $message->get('field_referenced_entity_id')->value);
  }

  /**
   * Creating two SEPARATE groups records two distinct Messages.
   *
   * Guards against a static/singleton bug that only records the first group
   * ever created in a request.
   */
  public function testTwoGroupsRecordTwoDistinctMessages(): void {
    $ownerOne = $this->createUser();
    $ownerTwo = $this->createUser();

    $this->setCurrentUser($ownerOne);
    $groupOne = $this->createGroup(['uid' => $ownerOne->id()]);

    $this->setCurrentUser($ownerTwo);
    $groupTwo = $this->createGroup(['uid' => $ownerTwo->id()]);

    $messages = $this->messagesByTemplate('activity_group_created');
    $refIds = array_map(static fn ($m) => (int) $m->get('field_referenced_entity_id')->value, $messages);

    $this->assertContains((int) $groupOne->id(), $refIds);
    $this->assertContains((int) $groupTwo->id(), $refIds);
  }

}
