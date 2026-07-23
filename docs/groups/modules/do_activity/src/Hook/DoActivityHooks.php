<?php

declare(strict_types=1);

namespace Drupal\do_activity\Hook;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\flag\FlaggingInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\message\Entity\Message;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for do_activity (#116, ST-F2).
 *
 * Records six activity log points as `message` entities for later stream
 * rendering (ST-7 #129 owns the render surface — this module is storage +
 * logging only, no UI). See the amended brief
 * (docs/planning/handoffs/116-activity/brief-amended.md) for the full event
 * model and docs/groups/scripts/step_7xx_backfill_activity.php for the
 * companion one-time backfill.
 *
 * SIX LOG POINTS (template ids in parentheses):
 *   1. Post created in group (activity_post_created) — group_relationship
 *      insert, `group_node:*` plugin ids. NOT `node_insert`: Group 4.x's
 *      add-to-group invalidates cache tags rather than resaving the node
 *      (change record 2025-05-23), so `node_insert` can never see the group.
 *      See {@see DoNotificationsHooks} (do_notifications module) lines
 *      20-54/165 for the identical pitfall in the sibling module.
 *   2. Comment created (activity_comment_created) — comment_insert. Group
 *      context is resolved by walking the commented entity through
 *      group_relationship storage; left empty (never fabricated) when the
 *      commented entity has no group context.
 *   3. Membership created (activity_membership_created) — the SAME
 *      group_relationship_insert hook as (1), filtered to the
 *      `group_membership` plugin id instead. Actor is the relationship's own
 *      entity (the new member), never the currently logged-in user.
 *   4. Flagging created, non-pin (activity_flagging_created) — flagging_insert,
 *      any flag id EXCEPT pin_in_group (see 6).
 *   5. Group created (activity_group_created) — group_insert. Actor = owner.
 *   6. Pin toggled (activity_pin_toggled) — the SAME flagging_insert hook as
 *      (4), filtered to the pin_in_group flag id; flagging_delete for the
 *      same flag id removes the Message (unpin). Mirrors do_group_pin's own
 *      model (docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php):
 *      Flag 4.x fires no dedicated (un)flag event, so pin/unpin is modeled via
 *      the flagging entity's own generic insert/delete lifecycle, branching on
 *      flag id — not a do_group_pin-specific API.
 *
 * DELETION HYGIENE: node_delete, comment_delete, group_relationship_delete,
 * flagging_delete, group_delete each hard-delete the Message row(s) keyed by
 * (field_referenced_entity_type, field_referenced_entity_id) — see
 * {@see self::deleteMessagesReferencing()}.
 *
 * TYPE NOTE: every entity id / timestamp base-field accessor in Drupal core
 * (getCreatedTime(), id(), etc.) returns the field item's raw storage value,
 * which is loosely typed (often a numeric STRING, not a native int, depending
 * on where the entity is in its lifecycle) — under this file's own
 * declare(strict_types=1), passing one of those straight into a strictly
 * `int`-typed parameter throws a TypeError. Every such value is cast to
 * `(int)` at its call site below, including getCreatedTime() (only
 * \Drupal::time()->getRequestTime() is already a native int and needs no
 * cast).
 */
class DoActivityHooks {

  /**
   * The machine name of the pin-in-group flag.
   *
   * Mirrors DoGroupPinHooks::PIN_FLAG_ID (docs/groups/modules/do_group_pin) —
   * not imported directly, since do_activity does not depend on do_group_pin
   * (per the brief: do_activity reacts to the raw flagging lifecycle, not a
   * do_group_pin-specific API).
   */
  private const PIN_FLAG_ID = 'pin_in_group';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Log points 1 + 3 — a group_relationship (Group 4.x group_content) insert.
   *
   * Discriminates on the relationship's plugin id:
   *   - `group_node:*` (log point 1) — a node was added to (or created in) a
   *     group. Actor = the CURRENT user (the one performing the add), refs =
   *     node + group.
   *   - `group_membership` (log point 3) — a user became a group member.
   *     Actor = the relationship's OWN entity (the new member), refs = user +
   *     group. This deliberately does NOT use the current user, because the
   *     group owner's creator-membership relationship (community_group has
   *     `creator_membership => TRUE`) must attribute to the owner even when a
   *     different account is the request's current user, and because an
   *     organizer adding another member on someone's behalf must attribute to
   *     the member being added, not the organizer performing the add.
   *   - anything else — ignored.
   */
  #[Hook('group_relationship_insert')]
  public function groupRelationshipInsert(GroupRelationshipInterface $relationship): void {
    $plugin_id = $relationship->getPluginId();

    if (str_starts_with($plugin_id, 'group_node:')) {
      $entity = $relationship->getEntity();
      if (!$entity instanceof NodeInterface) {
        return;
      }
      $this->createMessage(
        'activity_post_created',
        (int) \Drupal::currentUser()->id(),
        'node',
        (int) $entity->id(),
        (int) $relationship->getGroup()->id(),
        (int) $entity->getCreatedTime(),
      );
      return;
    }

    if ($plugin_id === 'group_membership') {
      $member = $relationship->getEntity();
      if ($member === NULL) {
        return;
      }
      $this->createMessage(
        'activity_membership_created',
        (int) $member->id(),
        'user',
        (int) $member->id(),
        (int) $relationship->getGroup()->id(),
        \Drupal::time()->getRequestTime(),
      );
      return;
    }
  }

  /**
   * Log point 2 — a comment is created.
   *
   * Refs = the comment itself. Group context is resolved by walking the
   * commented entity's own group_relationship(s) (GroupRelationship::
   * loadByEntity()) — left EMPTY (never fabricated) when the commented entity
   * has no group relationship at all, e.g. a comment on a page with no group
   * context. Actor = the commenter.
   */
  #[Hook('comment_insert')]
  public function commentInsert(CommentInterface $comment): void {
    $group_id = NULL;
    $commented_entity = $comment->getCommentedEntity();
    if ($commented_entity !== NULL) {
      $group_id = $this->resolveGroupIdForEntity($commented_entity);
    }

    $this->createMessage(
      'activity_comment_created',
      (int) $comment->getOwnerId(),
      'comment',
      (int) $comment->id(),
      $group_id,
      (int) $comment->getCreatedTime(),
    );
  }

  /**
   * Log point 5 — a group is created.
   *
   * Refs = the group itself; no field_group_id (the group IS the referenced
   * entity, not a group-context field). Actor = the group owner.
   */
  #[Hook('group_insert')]
  public function groupInsert(GroupInterface $group): void {
    $this->createMessage(
      'activity_group_created',
      (int) $group->getOwnerId(),
      'group',
      (int) $group->id(),
      NULL,
      (int) $group->getCreatedTime(),
    );
  }

  /**
   * Log points 4 + 6 — a flagging is created.
   *
   * Discriminates on the flag id:
   *   - `pin_in_group` (log point 6) — records on the activity_pin_toggled
   *     template.
   *   - anything else (log point 4, e.g. rsvp_event, follow_user) — records
   *     on the activity_flagging_created template.
   * Refs = the flagged entity; actor = the flagging user.
   */
  #[Hook('flagging_insert')]
  public function flaggingInsert(FlaggingInterface $flagging): void {
    $flaggable = $flagging->getFlaggable();
    if ($flaggable === NULL) {
      return;
    }

    $template = $flagging->getFlagId() === self::PIN_FLAG_ID
      ? 'activity_pin_toggled'
      : 'activity_flagging_created';

    $this->createMessage(
      $template,
      (int) $flagging->getOwnerId(),
      $flagging->getFlaggableType(),
      (int) $flagging->getFlaggableId(),
      NULL,
      \Drupal::time()->getRequestTime(),
    );
  }

  /**
   * Deletion hygiene — a flagging is deleted.
   *
   * Covers BOTH the log-point-6 unpin case (deletes the matching
   * activity_pin_toggled Message) and general deletion hygiene for
   * activity_flagging_created Messages (log point 4), keyed by the flagging's
   * own id (not the flagged entity's id) — the referenced entity for these
   * two templates IS the flagging's flaggable, not the flagging itself, but a
   * flaggable entity (e.g. a node) can be flagged AND unrelated to any other
   * activity Message about it, so we key the delete on the SAME
   * (referenced_entity_type, referenced_entity_id) pair the insert used.
   */
  #[Hook('flagging_delete')]
  public function flaggingDelete(FlaggingInterface $flagging): void {
    $this->deleteMessagesReferencing($flagging->getFlaggableType(), (int) $flagging->getFlaggableId());
  }

  /**
   * Deletion hygiene — a node is deleted.
   *
   * Removes any activity_post_created Message referencing this node.
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    $this->deleteMessagesReferencing('node', (int) $node->id());
  }

  /**
   * Deletion hygiene — a comment is deleted.
   *
   * Removes any activity_comment_created Message referencing this comment.
   */
  #[Hook('comment_delete')]
  public function commentDelete(CommentInterface $comment): void {
    $this->deleteMessagesReferencing('comment', (int) $comment->id());
  }

  /**
   * Deletion hygiene — a group_relationship is deleted.
   *
   * Covers removing a member (deletes the activity_membership_created
   * Message keyed on the member's user id) — the group_node:* case has no
   * deletion-hygiene requirement of its own in the brief (a node's own
   * node_delete hook already covers the node-referenced Message; removing
   * only the RELATIONSHIP while keeping the node is not a listed hygiene
   * case), so this only acts on group_membership relationships.
   */
  #[Hook('group_relationship_delete')]
  public function groupRelationshipDelete(GroupRelationshipInterface $relationship): void {
    if ($relationship->getPluginId() !== 'group_membership') {
      return;
    }
    $member = $relationship->getEntity();
    if ($member === NULL) {
      return;
    }
    $this->deleteMessagesReferencing('user', (int) $member->id());
  }

  /**
   * Deletion hygiene — a group is deleted.
   *
   * Removes any activity_group_created Message referencing this group.
   */
  #[Hook('group_delete')]
  public function groupDelete(GroupInterface $group): void {
    $this->deleteMessagesReferencing('group', (int) $group->id());
  }

  /**
   * Resolves the group id for an arbitrary entity via its own relationships.
   *
   * Used to resolve group context for a commented entity. Returns NULL (never
   * a fabricated 0/empty-string group id) when the entity has no
   * group_relationship at all, or when relationship storage is unavailable.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to resolve group context for (typically the commented node).
   *
   * @return int|null
   *   The first group id the entity is related to, or NULL if none.
   */
  private function resolveGroupIdForEntity(EntityInterface $entity): ?int {
    try {
      $storage = $this->entityTypeManager->getStorage('group_relationship');
    }
    catch (\Exception $e) {
      return NULL;
    }

    $relationships = $storage->loadByEntity($entity);
    if (!$relationships) {
      return NULL;
    }
    $relationship = reset($relationships);
    return (int) $relationship->getGroup()->id();
  }

  /**
   * Creates and saves one activity Message.
   *
   * @param string $template
   *   The message_template bundle id.
   * @param int $actor_uid
   *   The uid to attribute the Message to (NOT necessarily the current user —
   *   see groupRelationshipInsert()'s membership branch).
   * @param string $referenced_entity_type
   *   The referenced entity's type (e.g. 'node', 'comment', 'user', 'group').
   * @param int $referenced_entity_id
   *   The referenced entity's id.
   * @param int|null $group_id
   *   (optional) The group context, or NULL when there is none — left
   *   entirely unset (never a fabricated 0) so the field reads as empty.
   * @param int $created
   *   The Message's created timestamp — the SOURCE entity's own created time
   *   when available, so a later backfill run recognises this exact row via
   *   the idempotency key. Live hooks that react to an entity insert use that
   *   entity's getCreatedTime(); events with no natural created time of their
   *   own (membership, flagging) use the request time.
   */
  private function createMessage(
    string $template,
    int $actor_uid,
    string $referenced_entity_type,
    int $referenced_entity_id,
    ?int $group_id,
    int $created,
  ): void {
    $values = [
      'template' => $template,
      'uid' => $actor_uid,
      'field_referenced_entity_type' => $referenced_entity_type,
      'field_referenced_entity_id' => $referenced_entity_id,
    ];
    if ($group_id !== NULL) {
      // Explicit ['target_id' => ...] form — NOT a bare scalar. A bare
      // scalar/int passed as an entity_reference field's value is interpreted
      // by EntityReferenceItem::setValue() as an ENTITY object assignment
      // (`$this->set('entity', $values)`), not a target_id shorthand, so it
      // silently fails to resolve to a real reference. The explicit array
      // form is the documented, unambiguous way to set an entity_reference by
      // id (mirrors docs/groups/scripts/step_700_demo_data.php's own
      // `["target_id" => $tid]` idiom for tag references).
      $values['field_group_id'] = ['target_id' => $group_id];
    }

    $message = Message::create($values);
    $message->setCreatedTime($created);
    $message->save();
  }

  /**
   * Hard-deletes every Message referencing the given entity.
   *
   * Keyed by (field_referenced_entity_type, field_referenced_entity_id) per
   * the brief's deletion-hygiene contract — never a soft-delete or a
   * cascading delete of unrelated Messages.
   *
   * @param string $entity_type
   *   The referenced entity type to match.
   * @param int $entity_id
   *   The referenced entity id to match.
   */
  private function deleteMessagesReferencing(string $entity_type, int $entity_id): void {
    $storage = $this->entityTypeManager->getStorage('message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_referenced_entity_type', $entity_type)
      ->condition('field_referenced_entity_id', $entity_id)
      ->execute();
    if (!$ids) {
      return;
    }
    $storage->delete($storage->loadMultiple($ids));
  }

}
