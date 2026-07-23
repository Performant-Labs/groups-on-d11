<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

/**
 * Log point 6/6 — "pin toggle" (#116 amended brief, log points item 6).
 *
 * Pins a node via the `pin_in_group` flagging (branch of the same
 * `#[Hook('flagging_insert')]` dispatch FlaggingInsertTest covers for
 * rsvp_event/follow_user) and asserts one Message. Unpinning (deleting the
 * flagging) must clean up that Message per the brief's deletion-hygiene
 * section (`#[Hook('flagging_delete')]`).
 *
 * `do_group_pin` itself (docs/groups/modules/do_group_pin/src/Hook/
 * DoGroupPinHooks.php) does NOT use a distinct storage or a dedicated
 * pin-specific hook — it reacts to the flagging entity's own generic
 * `entity_insert`/`entity_delete` (Flag 4.x fires no dedicated (un)flag
 * event), branching on `$entity->getFlagId() === self::PIN_FLAG_ID` inside
 * `onFlaggingChange()`. `do_activity` is expected to mirror that same
 * generic-flagging-lifecycle model (`flagging_insert`/`flagging_delete`
 * branching on flag id), NOT a separate pin-only storage — so this test
 * covers the flagging path directly rather than a `do_group_pin`-specific API.
 *
 * Template id under test: `activity_pin_toggled`.
 *
 * @group do_activity
 * @group do_tests
 */
class PinTogglePinTest extends ActivityKernelTestBase {

  /**
   * Pinning a node records exactly one Message.
   */
  public function testPinRecordsOneMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);
    $node = $this->addNode($group, 'post', ['title' => 'Pin me', 'uid' => $actor->id()]);

    $flagService = $this->container->get('flag');
    $flagService->flag($this->loadFlag('pin_in_group'), $node, $actor);

    $messages = $this->messagesByTemplate('activity_pin_toggled');
    $this->assertCount(1, $messages, 'Exactly one activity_pin_toggled Message is recorded on pin.');

    $message = reset($messages);
    $this->assertSame((int) $actor->id(), (int) $message->getOwnerId());
    $this->assertSame('node', $message->get('field_referenced_entity_type')->value);
    $this->assertSame((int) $node->id(), (int) $message->get('field_referenced_entity_id')->value);
  }

  /**
   * Unpinning (deleting the pin flagging) removes the pin Message.
   *
   * Deletion-hygiene half of log point 6: the brief requires
   * `#[Hook('flagging_delete')]` to hard-delete the Message row keyed by the
   * referenced entity — verified here at the flagging layer directly (the
   * general cross-entity deletion-hygiene sweep is DeletionHygieneTest).
   */
  public function testUnpinRemovesTheMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);
    $node = $this->addNode($group, 'post', ['title' => 'Pin then unpin', 'uid' => $actor->id()]);

    $flagService = $this->container->get('flag');
    $pinFlag = $this->loadFlag('pin_in_group');
    $flagService->flag($pinFlag, $node, $actor);

    $this->assertCount(1, $this->messagesByTemplate('activity_pin_toggled'), 'Sanity: the pin Message exists before unpinning.');

    $flagService->unflag($pinFlag, $node, $actor);

    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_pin_toggled'),
      'Unpinning (deleting the flagging) removes the pin Message (deletion hygiene).'
    );
  }

  /**
   * Unpinning a node must not delete the node's own post-created Message.
   *
   * Regression pin for the diff-gate "bonus catch": flaggingDelete()
   * previously deleted every Message referencing the flaggable entity's
   * (entity_type, entity_id) pair — for a pinned `post` node, that pair is
   * IDENTICAL to the one activity_post_created's own Message uses, so
   * unpinning a node silently deleted its unrelated activity_post_created
   * Message too. PinTogglePinTest::testUnpinRemovesTheMessage() only asserted
   * the pin-template count, so this was latent. Fixed at 582ea59 by scoping
   * flaggingDelete()'s delete to the SAME template its matching insert used
   * (mirrors flaggingInsert()'s own flag-id branch).
   */
  public function testUnpinDoesNotDeletePostCreatedMessage(): void {
    $group = $this->createGroup();
    $actor = $this->createUser();
    $this->setCurrentUser($actor);
    $node = $this->addNode($group, 'post', ['title' => 'Pin then unpin, keep the post', 'uid' => $actor->id()]);

    $flagService = $this->container->get('flag');
    $pinFlag = $this->loadFlag('pin_in_group');
    $flagService->flag($pinFlag, $node, $actor);

    $this->assertCount(1, $this->messagesByTemplate('activity_post_created'), 'Sanity: the post-created Message exists before unpinning.');
    $this->assertCount(1, $this->messagesByTemplate('activity_pin_toggled'), 'Sanity: the pin Message exists before unpinning.');

    $flagService->unflag($pinFlag, $node, $actor);

    $this->assertCount(
      0,
      $this->messagesByTemplate('activity_pin_toggled'),
      'Unpinning removes its own activity_pin_toggled Message.'
    );
    $this->assertCount(
      1,
      $this->messagesByTemplate('activity_post_created'),
      'Unpinning must NOT delete the node\'s unrelated activity_post_created Message.'
    );
  }

}
