<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

/**
 * Log point 1/6 — "post created in group" (#116, amended brief §"Six event log points" item 1).
 *
 * Pins `#[Hook('group_relationship_insert')]` filtered to `group_node:*`
 * plugin ids (NOT `node_insert` — Group 4.x's add-to-group invalidates cache
 * tags rather than resaving the node, so `node_insert` can never see the
 * group; see `DoNotificationsHooks` lines 20-54/165 for the same pitfall in
 * the sibling module). Asserts exactly one Message on the "post created in
 * group" template, actor = current user, refs = node + group.
 *
 * Template id under test: `activity_post_created` (F's naming; this test
 * documents the CONTRACT the hook must satisfy — see class docblock in
 * ActivityKernelTestBase for the referenced-entity field-name contract).
 *
 * @group do_activity
 * @group do_tests
 */
class NodeInGroupInsertTest extends ActivityKernelTestBase {

  /**
   * Creating a node directly in a group records exactly one Message.
   */
  public function testNodeCreatedInGroupRecordsOneMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);

    $node = $this->addNode($group, 'post', ['title' => 'A new post', 'uid' => $actor->id()]);

    $messages = $this->messagesByTemplate('activity_post_created');
    $this->assertCount(
      1,
      $messages,
      'Exactly one activity_post_created Message is recorded when a node is added to a group.'
    );

    $message = reset($messages);
    $this->assertSame(
      (int) $actor->id(),
      (int) $message->getOwnerId(),
      'The Message actor is the current (acting) user, not the node owner incidentally.'
    );
    $this->assertSame(
      'node',
      $message->get('field_referenced_entity_type')->value,
      'The referenced entity type is the node.'
    );
    $this->assertSame(
      (int) $node->id(),
      (int) $message->get('field_referenced_entity_id')->value,
      'The referenced entity id is the created node.'
    );
    $this->assertSame(
      (int) $group->id(),
      (int) $message->get('field_group_id')->value,
      'The Message carries the group the node was added to.'
    );
  }

  /**
   * Cross-posting an EXISTING node into a group also records one Message.
   *
   * Mirrors do_notifications' RA3 coverage (#37): the group-add moment must
   * fire via the relationship insert regardless of whether the node was
   * created directly in the group or related to it afterwards.
   */
  public function testExistingNodeAddedToGroupRecordsOneMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Created ungrouped',
      'uid' => $actor->id(),
      'status' => 1,
    ]);
    $node->save();

    // No group yet: no post-created-in-group message.
    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_post_created'),
      'No activity message is recorded before the node joins any group.'
    );

    $group->addRelationship($node, 'group_node:post');

    $messages = $this->messagesByTemplate('activity_post_created');
    $this->assertCount(
      1,
      $messages,
      'Adding an existing node to a group afterwards also records exactly one Message.'
    );
    $message = reset($messages);
    $this->assertSame((int) $node->id(), (int) $message->get('field_referenced_entity_id')->value);
    $this->assertSame((int) $group->id(), (int) $message->get('field_group_id')->value);
  }

  /**
   * A group_membership relationship insert must NOT record a post-created event.
   *
   * The hook must discriminate on plugin id base (`group_node:*`), never
   * firing for `group_membership` relationships — that is log point 3's job.
   */
  public function testMembershipRelationshipRecordsNoPostCreatedMessage(): void {
    $group = $this->createGroup();
    $member = $this->createUser();

    $this->addMember($group, $member);

    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_post_created'),
      'A membership relationship insert records no post-created-in-group message.'
    );
  }

}
