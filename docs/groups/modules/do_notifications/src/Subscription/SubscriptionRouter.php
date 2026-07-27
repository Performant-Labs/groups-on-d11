<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Subscription;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\State\StateInterface;
use Drupal\do_notifications\Frequency\FrequencyResolver;
use Drupal\do_notifications\Queue\QueueBackendInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\message\MessageInterface;
use Drupal\node\NodeInterface;

/**
 * Routes an activity Message to its interested subscribers.
 *
 * Part of the Notifications epic #237, N-2 (#230). Resolves the union of
 * subscribers across three flag-based subscription dimensions
 * (`follow_content`, `follow_user`, `follow_term`), applies three suppression
 * filters (never notify the Message's own author, `mute_group_notifications`
 * on the Message's group, and the per-post `do_notifications_suppress_<nid>`
 * State flag), and writes one deduplicated queue entry per remaining
 * recipient via the injected {@see QueueBackendInterface}.
 *
 * See docs/planning/handoffs/230-n2-subscribe-routing/brief.md for the full
 * design ("Design — routing orchestrator"). This class never throws: any
 * unloadable reference or flag lookup failure is logged and treated as "no
 * candidates from that source", so a single bad Message can never break
 * routing for every other Message.
 *
 * N-5 (#233): each recipient's `frequency`/`send_at` payload keys are now
 * resolved PER RECIPIENT via the injected {@see FrequencyResolver}, rather
 * than a single site-wide frequency stamped on every entry. See
 * docs/planning/handoffs/233-n5-frequency-queue/brief.md ("Design —
 * SubscriptionRouter change").
 */
class SubscriptionRouter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FlagServiceInterface $flagService,
    private readonly QueueBackendInterface $queue,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
    private readonly FrequencyResolver $frequencyResolver,
  ) {}

  /**
   * Routes one Message to its subscribers, enqueuing one entry per recipient.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The activity Message to route.
   *
   * @return int
   *   The number of queue entries written (0 or more). Returns 0 when the
   *   referenced entity cannot be loaded, when no candidate subscribers
   *   remain after filtering, or when per-post suppression blocks the route
   *   entirely.
   */
  public function route(MessageInterface $message): int {
    try {
      return $this->doRoute($message);
    }
    catch (\Throwable $e) {
      \Drupal::logger('do_notifications')->error(
        'SubscriptionRouter::route() failed for message @mid: @msg',
        ['@mid' => $message->id(), '@msg' => $e->getMessage()],
      );
      return 0;
    }
  }

  /**
   * The actual routing algorithm, wrapped by {@see self::route()}'s catch-all.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The activity Message to route.
   *
   * @return int
   *   The number of queue entries written.
   */
  private function doRoute(MessageInterface $message): int {
    // Step 1: resolve the referenced entity. Not loadable -> no routing.
    $referenced_entity = $this->resolveReferencedEntity($message);
    if ($referenced_entity === NULL) {
      return 0;
    }

    // Step 6 (checked early since it short-circuits the whole route, and only
    // applies when the reference is a node): per-post suppression.
    if ($referenced_entity instanceof NodeInterface) {
      $suppress_key = 'do_notifications_suppress_' . $referenced_entity->id();
      if ($this->state->get($suppress_key) === TRUE) {
        return 0;
      }
    }

    $author_uid = (int) $message->getOwnerId();

    // Step 2: build the candidate uid set (union across the three flags).
    // Keyed by uid to dedup a single user's overlapping subscriptions to the
    // SAME Message before we ever reach the queue backend.
    $candidate_uids = [];

    foreach ($this->resolveFollowContentUids($referenced_entity) as $uid) {
      $candidate_uids[$uid] = TRUE;
    }
    foreach ($this->resolveFollowTermUids($referenced_entity) as $uid) {
      $candidate_uids[$uid] = TRUE;
    }
    foreach ($this->resolveFollowUserUids($message, $referenced_entity, $author_uid) as $uid) {
      $candidate_uids[$uid] = TRUE;
    }

    if (empty($candidate_uids)) {
      return 0;
    }

    // Step 3: never notify the Message's own author.
    unset($candidate_uids[$author_uid]);

    if (empty($candidate_uids)) {
      return 0;
    }

    // Step 4: filter out users muting the Message's group.
    $muted_uids = $this->resolveMutedUids($message);
    foreach ($muted_uids as $uid) {
      unset($candidate_uids[$uid]);
    }

    if (empty($candidate_uids)) {
      return 0;
    }

    // Step 7: enqueue one entry per remaining recipient. Frequency and
    // send_at are resolved PER UID (N-5, #233) via FrequencyResolver, rather
    // than a single site-wide value stamped on every entry.
    $day = date('Y-m-d', $this->time->getRequestTime());
    $mid = (int) $message->id();
    $template = $message->getTemplate()->id();

    $count = 0;
    foreach (array_keys($candidate_uids) as $uid) {
      $resolved = $this->frequencyResolver->resolve($uid);
      $this->queue->enqueue([
        'uid' => $uid,
        'mid' => $mid,
        'template' => $template,
        'frequency' => $resolved['frequency'],
        'day' => $day,
        'send_at' => $resolved['send_at'],
      ]);
      $count++;
    }

    return $count;
  }

  /**
   * Resolves the Message's referenced entity via its two reference fields.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message to resolve a reference for.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The referenced entity, or NULL if either field is empty or the entity
   *   cannot be loaded.
   */
  private function resolveReferencedEntity(MessageInterface $message): ?EntityInterface {
    $entity_type = $message->get('field_referenced_entity_type')->value ?? NULL;
    $entity_id = $message->get('field_referenced_entity_id')->value ?? NULL;
    if (!$entity_type || $entity_id === NULL) {
      return NULL;
    }

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
    }
    catch (\Exception $e) {
      return NULL;
    }

    return $storage->load((int) $entity_id);
  }

  /**
   * Resolves follow_content subscribers of the referenced entity.
   *
   * For a `comment` reference, resolves against the comment's OWN commented
   * entity instead (follow_content targets content, not comments) — the
   * comment is reloaded via entity storage (rather than trusting the entity
   * instance already in hand) so this method is correct regardless of what
   * the caller passed in.
   *
   * @param \Drupal\Core\Entity\EntityInterface $referenced_entity
   *   The Message's resolved referenced entity.
   *
   * @return int[]
   *   The uids of users flagging the resolved content entity via
   *   follow_content. Empty when the flag is missing, the referenced entity
   *   type is not one follow_content targets, or nobody follows it.
   */
  private function resolveFollowContentUids(EntityInterface $referenced_entity): array {
    $content_entity = $referenced_entity;

    if ($referenced_entity->getEntityTypeId() === 'comment') {
      try {
        $comment = $this->entityTypeManager->getStorage('comment')->load($referenced_entity->id());
      }
      catch (\Exception $e) {
        return [];
      }
      if ($comment === NULL) {
        return [];
      }
      $content_entity = $comment->getCommentedEntity();
      if ($content_entity === NULL) {
        return [];
      }
    }

    if ($content_entity->getEntityTypeId() !== 'node') {
      return [];
    }

    return $this->getFlaggingUserIds('follow_content', $content_entity);
  }

  /**
   * Resolves follow_term subscribers of any taxonomy term the entity carries.
   *
   * Best-effort: iterates the referenced entity's own field definitions for
   * `entity_reference` fields targeting `taxonomy_term`, and unions the
   * follow_term flaggers of every referenced term. Empty when the entity is
   * not fieldable, carries no such field, or nobody follows any tagged term.
   *
   * @param \Drupal\Core\Entity\EntityInterface $referenced_entity
   *   The Message's resolved referenced entity.
   *
   * @return int[]
   *   The uids of users flagging any tagged term via follow_term.
   */
  private function resolveFollowTermUids(EntityInterface $referenced_entity): array {
    if (!$referenced_entity instanceof FieldableEntityInterface) {
      return [];
    }

    $uids = [];
    foreach ($referenced_entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference') {
        continue;
      }
      if ($definition->getSetting('target_type') !== 'taxonomy_term') {
        continue;
      }
      foreach ($referenced_entity->get($field_name)->referencedEntities() as $term) {
        foreach ($this->getFlaggingUserIds('follow_term', $term) as $uid) {
          $uids[$uid] = TRUE;
        }
      }
    }

    return array_keys($uids);
  }

  /**
   * Resolves follow_user subscribers relevant to this Message.
   *
   * Always resolves flaggers of the Message's own author. Additionally, for
   * the `activity_membership_created` template, also resolves flaggers of
   * the referenced new member — a distinct uid from the author whenever an
   * organizer adds someone else to a group (see DoActivityHooks::
   * groupRelationshipInsert()'s membership branch).
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message being routed.
   * @param \Drupal\Core\Entity\EntityInterface $referenced_entity
   *   The Message's resolved referenced entity.
   * @param int $author_uid
   *   The Message's own author uid.
   *
   * @return int[]
   *   The uids of users flagging the relevant user(s) via follow_user.
   */
  private function resolveFollowUserUids(MessageInterface $message, EntityInterface $referenced_entity, int $author_uid): array {
    $uids = [];

    if ($author_uid > 0) {
      try {
        $author = $this->entityTypeManager->getStorage('user')->load($author_uid);
      }
      catch (\Exception $e) {
        $author = NULL;
      }
      if ($author !== NULL) {
        foreach ($this->getFlaggingUserIds('follow_user', $author) as $uid) {
          $uids[$uid] = TRUE;
        }
      }
    }

    if ($message->getTemplate()->id() === 'activity_membership_created' && $referenced_entity->getEntityTypeId() === 'user') {
      foreach ($this->getFlaggingUserIds('follow_user', $referenced_entity) as $uid) {
        $uids[$uid] = TRUE;
      }
    }

    return array_keys($uids);
  }

  /**
   * Resolves users muting the Message's group via mute_group_notifications.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The Message being routed.
   *
   * @return int[]
   *   The uids to filter out. Empty when the Message carries no group
   *   context, the group cannot be loaded, or nobody mutes it.
   */
  private function resolveMutedUids(MessageInterface $message): array {
    if ($message->get('field_group_id')->isEmpty()) {
      return [];
    }
    $group_id = $message->get('field_group_id')->target_id;
    if (!$group_id) {
      return [];
    }

    try {
      $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    }
    catch (\Exception $e) {
      return [];
    }
    if ($group === NULL) {
      return [];
    }

    return $this->getFlaggingUserIds('mute_group_notifications', $group);
  }

  /**
   * Looks up the uids flagging an entity with a given flag id.
   *
   * Wrapped so a missing flag definition (getFlagById() returning NULL) or
   * any flag-service failure degrades gracefully to "nobody", rather than
   * failing the whole route.
   *
   * @param string $flag_id
   *   The flag machine name.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The flagged entity to look up flaggers for.
   *
   * @return int[]
   *   The flagging users' uids.
   */
  private function getFlaggingUserIds(string $flag_id, EntityInterface $entity): array {
    try {
      $flag = $this->flagService->getFlagById($flag_id);
      if ($flag === NULL) {
        return [];
      }
      $users = $this->flagService->getFlaggingUsers($entity, $flag);
    }
    catch (\Throwable $e) {
      return [];
    }

    return array_map(static fn ($user): int => (int) $user->id(), $users);
  }

}
