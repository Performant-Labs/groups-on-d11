<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

/**
 * Log point 4/6 — "flagging created" (#116 amended brief, log points item 4).
 *
 * Pins `#[Hook('flagging_insert')]` handling `rsvp_event` and `follow_user`
 * (and, by construction, any other flag id) in one method, branching on
 * `$flagging->getFlagId()`. Two independent flaggings (one per flag id) must
 * each record their own Message, correctly scoped to the flagged entity.
 *
 * Template id under test: `activity_flagging_created`. `pin_in_group` is
 * intentionally NOT covered here — it is log point 6 (PinTogglePinTest),
 * which the brief separates out because of its additional unpin/delete
 * hygiene half.
 *
 * @group do_activity
 * @group do_tests
 */
class FlaggingInsertTest extends ActivityKernelTestBase {

  /**
   * Flagging a node with rsvp_event records one Message.
   */
  public function testRsvpEventFlaggingRecordsOneMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);

    $event = $this->addNode($group, 'event', ['title' => 'DrupalCon Sprint Day', 'uid' => $actor->id()]);

    $flagService = $this->container->get('flag');
    $flagService->flag($this->loadFlag('rsvp_event'), $event, $actor);

    $messages = $this->messagesByTemplate('activity_flagging_created');
    $rsvpMessages = array_values(array_filter(
      $messages,
      fn ($m) => $m->get('field_referenced_entity_type')->value === 'node'
        && (int) $m->get('field_referenced_entity_id')->value === (int) $event->id(),
    ));

    $this->assertCount(1, $rsvpMessages, 'Exactly one Message is recorded for the rsvp_event flagging.');
    $message = reset($rsvpMessages);
    $this->assertSame((int) $actor->id(), (int) $message->getOwnerId(), 'The actor is the flagging user.');
  }

  /**
   * Flagging a user with follow_user records one Message, distinct from RSVP.
   *
   * Both flaggings happen in the same test to prove the branch-on-flag-id
   * dispatch produces two separately-scoped Messages, not one conflated
   * event or a crash from handling two different flaggable entity types.
   */
  public function testFollowUserFlaggingRecordsOneDistinctMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $followee = $this->createUser();
    $this->setCurrentUser($actor);

    $event = $this->addNode($group, 'event', ['title' => 'Sprint Day', 'uid' => $actor->id()]);

    $flagService = $this->container->get('flag');
    $flagService->flag($this->loadFlag('rsvp_event'), $event, $actor);
    $flagService->flag($this->loadFlag('follow_user'), $followee, $actor);

    $messages = $this->messagesByTemplate('activity_flagging_created');
    $this->assertCount(2, $messages, 'Two distinct flagging Messages are recorded (rsvp_event + follow_user).');

    $userMessages = array_values(array_filter(
      $messages,
      fn ($m) => $m->get('field_referenced_entity_type')->value === 'user',
    ));
    $this->assertCount(1, $userMessages, 'Exactly one Message references the followed user.');
    $followMessage = reset($userMessages);
    $this->assertSame((int) $followee->id(), (int) $followMessage->get('field_referenced_entity_id')->value);
    $this->assertSame((int) $actor->id(), (int) $followMessage->getOwnerId(), 'The actor is the follower, not the followee.');
  }

}
