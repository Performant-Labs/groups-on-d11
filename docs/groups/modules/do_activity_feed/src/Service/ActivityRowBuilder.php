<?php

declare(strict_types=1);

namespace Drupal\do_activity_feed\Service;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\message\MessageInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Builds one row model per Message, plus the comment-snippet transform.
 *
 * Issue #129 ST-7, survey.md §"Feed row model" + §"Comment snippet" key
 * finding: loads the referenced entity (node/comment/user/group) and shapes
 * the render-array row a feed template consumes. Content rows additionally
 * gate on `$node->access('view')` (brief §Access) — the CALLER
 * (ActivityFeedController) is responsible for dropping a row this service
 * flags as not viewable; this service itself never decides "should render",
 * only "what would this row look like."
 */
class ActivityRowBuilder {

  /**
   * The maximum length (in bytes) of a comment snippet.
   */
  public const SNIPPET_MAX_LENGTH = 180;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds a single row model array for one (non-aggregated) Message.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message to build a row for.
   * @param string $type
   *   The row's `type` value (e.g. 'social_join', 'content_card',
   *   'social_comment') — the caller (ActivityFeedController) determines
   *   this from the Message's template plus whether it stands alone or was
   *   already folded into an aggregate bucket by ActivityAggregator.
   *
   * @return array|null
   *   The row model array (keys documented on
   *   \Drupal\do_activity_feed\Controller\ActivityFeedController::renderFeed()),
   *   or NULL when the row must be omitted entirely (e.g. a content row
   *   referencing a node the current viewer cannot view — brief §Access:
   *   "on deny, drop the row," never an access-denied placeholder). Also
   *   carries `actor_url`/`group_url`, plain URL strings (or NULL) precomputed
   *   here — U's Phase 8 rework report (Defect 2): the three row templates
   *   previously called Twig's `path('entity.user.canonical', {'user':
   *   row.actor.id})` directly, but Twig's magic attribute getter on a
   *   ContentEntity returns the `id` FIELD (a FieldItemList), not the scalar
   *   id Symfony's URL generator's `preg_match()` requires — a TypeError on
   *   every row render. Resolving the URL here (the idiomatic Drupal pattern
   *   — see \Drupal\do_group_mission\Plugin\Block\GroupMissionBlock::
   *   build()'s identical `Url::fromRoute(...)->toString()` precomputation,
   *   and this controller's own pre-existing
   *   ActivityFeedController::memberUrl()) keeps entity-API resolution out
   *   of the template entirely.
   */
  public function buildRow(MessageInterface $message, string $type): ?array {
    $actor = $this->loadActor($message);
    $groupId = $this->groupIdOf($message);
    $group = $groupId !== NULL ? $this->entityTypeManager->getStorage('group')->load($groupId) : NULL;
    $group = $group instanceof GroupInterface ? $group : NULL;

    [$referencedEntityType, $referencedEntityId] = $this->referencedEntityIdentity($message);

    $row = [
      'type' => $type,
      'message_id' => (int) $message->id(),
      'actor' => $actor,
      'actor_url' => $actor !== NULL ? $this->entityUrl($actor) : NULL,
      'group' => $group,
      'group_url' => $group !== NULL ? $this->entityUrl($group) : NULL,
      'referenced_entity_type' => $referencedEntityType,
      'referenced_entity_id' => $referencedEntityId,
      'created' => (int) $message->getCreatedTime(),
      'snippet' => NULL,
      'card' => NULL,
    ];

    if ($type === 'content_card') {
      $node = $referencedEntityType === 'node' && $referencedEntityId !== NULL
        ? $this->entityTypeManager->getStorage('node')->load($referencedEntityId)
        : NULL;
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        // Brief §Access: drop the row entirely — never an "access denied"
        // placeholder.
        return NULL;
      }
      $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
      $row['card'] = $viewBuilder->view($node, 'stream_card');
    }

    if ($type === 'social_comment') {
      $row['snippet'] = $this->buildCommentSnippet($message);
    }

    return $row;
  }

  /**
   * AC-5: builds the truncated, tag-stripped comment snippet for a Message.
   *
   * Per survey.md's "Comment snippet" key finding: load the comment via
   * `(field_referenced_entity_type='comment', id)`, take
   * `comment_body.value`, strip tags, truncate to 180 chars. Byte-safe
   * `substr()` (not `mb_substr()`) is used deliberately: the pinned
   * contract is `strlen($snippet) <= 180` (byte length, per
   * ActivityCommentSnippetTest's own `strlen()` assertions), and no
   * ellipsis/word-boundary trimming is added — the brief's snippet wording
   * names truncation + tag-stripping only, and appending characters after
   * the cut risks the strict `<=180` boundary the kernel tests assert byte-
   * for-byte.
   *
   * @param \Drupal\message\MessageInterface $message
   *   An `activity_comment_created` Message.
   *
   * @return string
   *   The truncated, tag-stripped snippet, or an empty string if the
   *   referenced comment cannot be loaded or has an empty body.
   */
  public function buildCommentSnippet(MessageInterface $message): string {
    $comment = $this->loadReferencedComment($message);
    if (!$comment instanceof CommentInterface || !$comment->hasField('comment_body') || $comment->get('comment_body')->isEmpty()) {
      return '';
    }

    $raw = (string) $comment->get('comment_body')->value;
    $stripped = strip_tags($raw);

    if (strlen($stripped) <= self::SNIPPET_MAX_LENGTH) {
      return $stripped;
    }

    return substr($stripped, 0, self::SNIPPET_MAX_LENGTH);
  }

  /**
   * Loads the Comment entity an `activity_comment_created` Message refers to.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message.
   *
   * @return \Drupal\comment\CommentInterface|null
   *   The referenced Comment, or NULL if not resolvable.
   */
  private function loadReferencedComment(MessageInterface $message): ?CommentInterface {
    [$type, $id] = $this->referencedEntityIdentity($message);
    if ($type !== 'comment' || $id === NULL) {
      return NULL;
    }
    $comment = $this->entityTypeManager->getStorage('comment')->load($id);
    return $comment instanceof CommentInterface ? $comment : NULL;
  }

  /**
   * Loads the actor (acting user) for a Message.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message.
   *
   * @return \Drupal\user\UserInterface|null
   *   The actor, or NULL if not resolvable.
   */
  private function loadActor(MessageInterface $message): ?UserInterface {
    $actor = $this->entityTypeManager->getStorage('user')->load($message->getOwnerId());
    return $actor instanceof UserInterface ? $actor : NULL;
  }

  /**
   * Resolves an entity's canonical URL as a plain string.
   *
   * Precomputes the URL here (never passed as a raw entity id into Twig's
   * `path()`) — see this class's own `buildRow()` docblock (Defect 2) for
   * why. Matches \Drupal\do_group_mission\Plugin\Block\GroupMissionBlock's
   * own `Url::fromRoute(...)->toString()` convention and this module's
   * ActivityFeedController::memberUrl(), which already used this exact
   * pattern for aggregated-row child links.
   *
   * @param \Drupal\user\UserInterface|\Drupal\group\Entity\GroupInterface $entity
   *   The user or group entity to link to.
   *
   * @return string|null
   *   The entity's canonical URL string, or NULL if it cannot be resolved
   *   (a toUrl()/toString() failure is not expected for a loaded, saved
   *   entity, but this guards defensively rather than letting a template
   *   render fail on an edge case unrelated to the row's own content).
   */
  private function entityUrl(UserInterface|GroupInterface $entity): ?string {
    try {
      return $entity->toUrl()->toString();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Resolves the (type, id) of a Message's referenced entity.
   *
   * Per the brief's A-advisory #2: `activity_membership_created` MAY leave
   * `field_referenced_entity_type` empty in principle (the user IS the
   * referenced entity) — this defaults to 'user' when empty, reading the id
   * off `field_referenced_entity_id` (confirmed against DoActivityHooks::
   * groupRelationshipInsert()'s membership branch, which in practice always
   * sets `field_referenced_entity_type => 'user'` explicitly — this default
   * is a defensive fallback for any Message saved without going through that
   * live hook, e.g. a future direct Message::create() call that omits the
   * field).
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message.
   *
   * @return array{0: string, 1: int|null}
   *   A [type, id] tuple; id is NULL when the Message carries no referenced
   *   entity id at all.
   */
  private function referencedEntityIdentity(MessageInterface $message): array {
    $type = $message->hasField('field_referenced_entity_type') && !$message->get('field_referenced_entity_type')->isEmpty()
      ? (string) $message->get('field_referenced_entity_type')->value
      : 'user';

    $id = $message->hasField('field_referenced_entity_id') && !$message->get('field_referenced_entity_id')->isEmpty()
      ? (int) $message->get('field_referenced_entity_id')->value
      : NULL;

    return [$type, $id];
  }

  /**
   * Reads a Message's field_group_id target id, or NULL when unset.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message.
   *
   * @return int|null
   *   The referenced group's id, or NULL when the field is empty.
   */
  private function groupIdOf(MessageInterface $message): ?int {
    if (!$message->hasField('field_group_id') || $message->get('field_group_id')->isEmpty()) {
      return NULL;
    }
    $targetId = $message->get('field_group_id')->target_id;
    return $targetId !== NULL ? (int) $targetId : NULL;
  }

}
