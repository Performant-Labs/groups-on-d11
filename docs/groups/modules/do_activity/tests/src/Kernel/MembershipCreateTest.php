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
    $this->assertSame((int) $group->id(), (int) $message->get('field_group_id')->target_id);
  }

  /**
   * Adding the group OWNER as a member still attributes to that owner.
   *
   * Originally this test relied on `community_group`'s `creator_membership`
   * setting firing automatically off a bare `Group::create()->save()` — but
   * that setting is consumed EXCLUSIVELY inside `GroupForm::form()`/
   * `GroupForm::actions()` (`creatorGetsMembership()`), the add-FORM's own
   * submit-handler enhancement. Nothing in `Group`'s entity-storage layer
   * (nor `GroupsKernelTestBase::createGroup()`, a bare `$storage->save()`)
   * ever creates a creator-membership relationship outside an actual
   * `/group/add` form submission, so that relationship never exists at the
   * kernel tier (confirmed by F during Phase 5 self-check; fixed T-green).
   * Rewritten to construct the membership via the mechanism that DOES fire
   * `group_relationship_insert` at this tier — `Group::addMember()` — so the
   * same acceptance intent (membership creation logs an activity Message
   * attributing to the correct actor, even when that actor is the group
   * owner) stays covered without asserting a form-only side effect.
   */
  public function testOwnerAddedAsMemberRecordsOwnerAsActor(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);

    $group = $this->createGroup(['uid' => $owner->id()]);
    $this->addMember($group, $owner);

    $messages = $this->messagesByTemplate('activity_membership_created');
    $this->assertNotEmpty($messages, 'Adding the owner as a member records a membership Message.');
    foreach ($messages as $message) {
      $this->assertSame(
        (int) $owner->id(),
        (int) $message->getOwnerId(),
        'The membership Message for the owner-as-member attributes to the owner, not the current user incidentally.'
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
