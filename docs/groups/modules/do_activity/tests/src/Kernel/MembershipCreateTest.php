<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

/**
 * Log point 3/6 — "membership create" (#116 amended brief, log points item 3).
 *
 * Pins `#[Hook('group_relationship_insert')]` filtered to the
 * `group_membership` bundle (the complementary discrimination to log point
 * 1's `group_node:*` filter — see NodeInGroupInsertTest's negative-space
 * assertion). Asserts one Message on the "membership create" template,
 * actor = the new member (NOT the group owner/creator), refs = user + group.
 *
 * Template id under test: `activity_membership_created`.
 *
 * @group do_activity
 * @group do_tests
 */
class MembershipCreateTest extends ActivityKernelTestBase {

  /**
   * Adding a member via the API path records exactly one Message.
   */
  public function testAddMemberRecordsOneMembershipMessage(): void {
    $group = $this->createGroup();
    $newMember = $this->createUser();

    $this->addMember($group, $newMember);

    $messages = $this->messagesByTemplate('activity_membership_created');
    $this->assertCount(1, $messages, 'Exactly one activity_membership_created Message is recorded.');

    $message = reset($messages);
    $this->assertSame(
      (int) $newMember->id(),
      (int) $message->getOwnerId(),
      'The Message actor is the NEW MEMBER, not whichever user created the group.'
    );
    $this->assertSame('user', $message->get('field_referenced_entity_type')->value);
    $this->assertSame((int) $newMember->id(), (int) $message->get('field_referenced_entity_id')->value);
    $this->assertSame((int) $group->id(), (int) $message->get('field_group_id')->value);
  }

  /**
   * The group creator's own creator-membership relationship is out of scope
   * scope-wise, but if it fires it must record the CREATOR as actor, not an
   * unrelated user — proving the hook reads the relationship's own entity,
   * not e.g. the currently logged-in test-runner default user.
   */
  public function testGroupCreatorMembershipRecordsCreatorAsActor(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);

    $group = $this->createGroup(['uid' => $owner->id()]);

    $messages = $this->messagesByTemplate('activity_membership_created');
    // The community_group type has creator_membership => TRUE (base setUp()),
    // so creating the group also creates the creator's own membership
    // relationship — this must attribute to the owner, not silently to no one.
    $this->assertNotEmpty($messages, 'The creator membership relationship also records a membership Message.');
    foreach ($messages as $message) {
      $this->assertSame(
        (int) $owner->id(),
        (int) $message->getOwnerId(),
        'Every membership Message for the creator relationship attributes to the group owner.'
      );
    }
  }

  /**
   * Adding two DIFFERENT members to the same group records two Messages.
   *
   * Guards against an implementation that only reacts to the first
   * membership relationship ever created, or that dedupes across users.
   */
  public function testTwoMembersRecordTwoDistinctMessages(): void {
    $group = $this->createGroup();
    $memberOne = $this->createUser();
    $memberTwo = $this->createUser();

    $this->addMember($group, $memberOne);
    $this->addMember($group, $memberTwo);

    $messages = $this->messagesByTemplate('activity_membership_created');
    $actorIds = array_map(static fn ($m) => (int) $m->getOwnerId(), $messages);

    $this->assertContains((int) $memberOne->id(), $actorIds, 'The first member has a membership Message.');
    $this->assertContains((int) $memberTwo->id(), $actorIds, 'The second member has a membership Message.');
  }

}
