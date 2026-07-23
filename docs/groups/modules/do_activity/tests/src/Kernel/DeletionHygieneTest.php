<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

use Drupal\comment\Entity\Comment;

/**
 * Deletion hygiene (#116 amended brief, "Deletion hygiene" §).
 *
 * Pins `#[Hook('node_delete')]`, `#[Hook('comment_delete')]`,
 * `#[Hook('group_relationship_delete')]`, `#[Hook('group_delete')]` —
 * activity Message rows must be hard-deleted when the entity they reference
 * goes away, keyed by `(field_referenced_entity_type,
 * field_referenced_entity_id)`. `flagging_delete` deletion hygiene is
 * covered separately by PinTogglePinTest::testUnpinRemovesTheMessage() (the
 * brief singles pin/unpin out as its own log point); this test covers the
 * remaining four entity types the brief's deletion-hygiene bullet lists.
 *
 * @group do_activity
 * @group do_tests
 */
class DeletionHygieneTest extends ActivityKernelTestBase {

  /**
   * Deleting a node removes its post-created-in-group Message.
   */
  public function testNodeDeleteRemovesPostCreatedMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);
    $node = $this->addNode($group, 'post', ['title' => 'Ephemeral post', 'uid' => $actor->id()]);

    $this->assertCount(1, $this->messagesByTemplate('activity_post_created'), 'Sanity: the Message exists before deletion.');

    $node->delete();

    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_post_created'),
      'Deleting the node removes its activity_post_created Message.'
    );
  }

  /**
   * Deleting a comment removes its comment-created Message.
   */
  public function testCommentDeleteRemovesCommentCreatedMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);
    $node = $this->addNode($group, 'post', ['title' => 'Thread', 'uid' => $actor->id()]);

    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => static::COMMENT_FIELD_NAME,
      'uid' => $actor->id(),
      'comment_type' => 'comment',
      'subject' => 'A reply',
      'comment_body' => ['value' => 'Text.', 'format' => 'plain_text'],
      'status' => 1,
    ]);
    $comment->save();

    $this->assertCount(1, $this->messagesByTemplate('activity_comment_created'), 'Sanity: the Message exists before deletion.');

    $comment->delete();

    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_comment_created'),
      'Deleting the comment removes its activity_comment_created Message.'
    );
  }

  /**
   * Removing a member removes its membership-created Message.
   *
   * "Removing a member" means deleting the group_membership relationship.
   */
  public function testMembershipRelationshipDeleteRemovesMembershipMessage(): void {
    $group = $this->createGroup();
    $member = $this->createUser();
    $this->addMember($group, $member);

    $this->assertNotEmpty(
      $this->messagesByTemplate('activity_membership_created'),
      'Sanity: a membership Message exists before removal.'
    );

    // GroupInterface::getMember() already returns the GroupMembership
    // relationship entity directly (GroupMembershipInterface extends
    // GroupRelationshipInterface) — there is no ->getGroupRelationship()
    // unwrapping method to call (test-authoring bug, fixed T-green).
    $membership = $group->getMember($member);
    $membership->delete();

    $remaining = array_filter(
      $this->messagesByTemplate('activity_membership_created'),
      fn ($m) => (int) $m->get('field_referenced_entity_id')->value === (int) $member->id()
        && (int) $m->get('field_group_id')->target_id === (int) $group->id(),
    );
    $this->assertCount(
      0,
      $remaining,
      'Deleting the membership relationship removes its activity_membership_created Message.'
    );
  }

  /**
   * Deleting a group removes its group-created Message.
   */
  public function testGroupDeleteRemovesGroupCreatedMessage(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $group = $this->createGroup(['uid' => $owner->id()]);

    $this->assertCount(1, $this->messagesByTemplate('activity_group_created'), 'Sanity: the Message exists before deletion.');

    $group->delete();

    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_group_created'),
      'Deleting the group removes its activity_group_created Message.'
    );
  }

  /**
   * Leaving a group must not delete an unrelated follow_user Message.
   *
   * Regression pin for diff-gate B-1: groupRelationshipDelete()'s
   * `group_membership` branch previously deleted EVERY Message referencing
   * the departing user's uid, keyed only on (user, uid) — which also matched
   * an unrelated `activity_flagging_created` Message from a `follow_user`
   * flag where this same user happens to be the FOLLOWEE. Fixed at 582ea59
   * by additionally scoping that delete to `template =
   * activity_membership_created`. Without that scoping, deleting the
   * membership relationship would ALSO silently delete the follow Message
   * asserted here.
   */
  public function testMembershipDeleteDoesNotDeleteUnrelatedFollowMessages(): void {
    $group = $this->createGroup();
    $member = $this->createUser();
    $this->addMember($group, $member);

    $follower = $this->createUser();
    $flagService = $this->container->get('flag');
    $flagService->flag($this->loadFlag('follow_user'), $member, $follower);

    $membershipMessages = array_filter(
      $this->messagesByTemplate('activity_membership_created'),
      fn ($m) => (int) $m->get('field_referenced_entity_id')->value === (int) $member->id()
        && (int) $m->get('field_group_id')->target_id === (int) $group->id(),
    );
    $this->assertCount(1, $membershipMessages, 'Sanity: the membership Message exists before removal.');

    $followMessages = array_filter(
      $this->messagesByTemplate('activity_flagging_created'),
      fn ($m) => $m->get('field_referenced_entity_type')->value === 'user'
        && (int) $m->get('field_referenced_entity_id')->value === (int) $member->id(),
    );
    $this->assertCount(1, $followMessages, 'Sanity: the unrelated follow_user Message exists before removal.');

    $membership = $group->getMember($member);
    $membership->delete();

    $remainingMembershipMessages = array_filter(
      $this->messagesByTemplate('activity_membership_created'),
      fn ($m) => (int) $m->get('field_referenced_entity_id')->value === (int) $member->id()
        && (int) $m->get('field_group_id')->target_id === (int) $group->id(),
    );
    $this->assertCount(
      0,
      $remainingMembershipMessages,
      'Deleting the membership relationship removes its own activity_membership_created Message.'
    );

    $remainingFollowMessages = array_filter(
      $this->messagesByTemplate('activity_flagging_created'),
      fn ($m) => $m->get('field_referenced_entity_type')->value === 'user'
        && (int) $m->get('field_referenced_entity_id')->value === (int) $member->id(),
    );
    $this->assertCount(
      1,
      $remainingFollowMessages,
      'Deleting the membership relationship must NOT delete the unrelated activity_flagging_created (follow_user) Message referencing the same user.'
    );
  }

}
