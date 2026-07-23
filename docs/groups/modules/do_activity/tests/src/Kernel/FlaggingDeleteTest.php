<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

/**
 * Deletion hygiene for non-pin flaggings (#116 diff-gate W-1).
 *
 * Companion to FlaggingInsertTest: that suite pins that a `follow_user`
 * flagging records exactly one `activity_flagging_created` Message, but
 * before this test was added, nothing asserted that UNFLAGGING removes that
 * Message again. Diff-gate flagged this as W-1 — a coverage gap that let a
 * scoping bug in `#[Hook('flagging_delete')]` ship latent (see
 * DeletionHygieneTest::testMembershipDeleteDoesNotDeleteUnrelatedFollowMessages
 * for the sibling regression this same bug produced on the membership-delete
 * path).
 *
 * Template id under test: `activity_flagging_created`. `pin_in_group`
 * unflag/delete hygiene is covered separately by
 * PinTogglePinTest::testUnpinRemovesTheMessage() (log point 6).
 *
 * @group do_activity
 * @group do_tests
 */
class FlaggingDeleteTest extends ActivityKernelTestBase {

  /**
   * Unflagging a follow_user flagging removes its own flagging Message.
   *
   * Pins the flagging_delete branch's scoping fix directly: the delete must
   * be keyed on (referenced entity, template), not merely the referenced
   * entity, or a later change to that scoping could resurrect the class of
   * bug diff-gate caught (deleting Messages that belong to an unrelated
   * template but share the same referenced-entity pair).
   */
  public function testUnflagFollowUserRemovesFlaggingMessage(): void {
    $follower = $this->createUser();
    $followee = $this->createUser();
    $this->setCurrentUser($follower);

    $flagService = $this->container->get('flag');
    $followFlag = $this->loadFlag('follow_user');
    $flagService->flag($followFlag, $followee, $follower);

    $messages = array_values(array_filter(
      $this->messagesByTemplate('activity_flagging_created'),
      fn ($m) => $m->get('field_referenced_entity_type')->value === 'user'
        && (int) $m->get('field_referenced_entity_id')->value === (int) $followee->id(),
    ));
    $this->assertCount(1, $messages, 'Sanity: exactly one activity_flagging_created Message exists for the follow.');

    $flagService->unflag($followFlag, $followee, $follower);

    $remaining = array_filter(
      $this->messagesByTemplate('activity_flagging_created'),
      fn ($m) => $m->get('field_referenced_entity_type')->value === 'user'
        && (int) $m->get('field_referenced_entity_id')->value === (int) $followee->id(),
    );
    $this->assertCount(
      0,
      $remaining,
      'Unflagging follow_user removes the activity_flagging_created Message referencing the followee.'
    );
  }

}
