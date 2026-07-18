<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Hook;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for do_notifications.
 *
 * Per-post opt-out, notification event recording, and comment auto-subscribe.
 * Drupal only records what happened — external system handles delivery.
 *
 * EVENT MODEL (two distinct, non-overlapping events).
 *
 * This module records two kinds of event onto the do_notifications queue:
 *
 *   - `node_created` (off core node_insert) — a CONTENT-scoped signal: "a
 *     publishable post now exists". It is recorded the instant the node row is
 *     written, BEFORE any group_relationship for it can exist (a relationship
 *     needs the node id), so its `group_ids` is intentionally empty. It fires
 *     for every eligible published node, including ones that will never join a
 *     group. It is NOT the group-membership-facing notification.
 *
 *   - `added_to_group` (off group_relationship insert, group_node:* only) — a
 *     GROUP-scoped signal: "this node was added to this group; notify the
 *     group's members". This is the event that carries a real, non-empty
 *     `group_ids` (the group the node was added to) and the node's `entity_id`.
 *
 * Why two events instead of enriching node_created: in Group 4.x, "add an
 * entity to a group" invalidates cache tags instead of resaving the entity
 * (change record 2025-05-23), so it fires neither hook_entity_update nor
 * hook_node_update — node_insert can never see the group. Reacting to the
 * group_relationship INSERT is the only reliable hook, and it fires uniformly
 * for BOTH shapes of "add to group":
 *
 *   - a node created directly in a group (create node -> create relationship);
 *   - an existing node cross-posted into a group later.
 *
 * DOUBLE-NOTIFICATION AVOIDANCE: the two events are deliberately different event
 * names with different scopes, so a single "create a node in a group" flow
 * records exactly one content event (`node_created`, empty group_ids) and one
 * group event (`added_to_group`, real group_ids) — never two identical
 * group-scoped events. groupRelationshipInsert() is the ONLY producer of a
 * group-scoped add event; nodeInsert() never emits one (its group_ids is
 * always empty by construction). See groupRelationshipInsert() and nodeInsert().
 */
class DoNotificationsHooks {

  /**
   * Content types that queue notification events.
   */
  private const CONTENT_TYPES = [
    'forum',
    'documentation',
    'event',
    'post',
    'page',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Adds "Do not send notifications" checkbox to group-postable node forms.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    $node = $form_state->getFormObject()->getEntity();
    if (!in_array($node->bundle(), self::CONTENT_TYPES, TRUE)) {
      return;
    }
    $form['do_notifications_suppress'] = [
      '#type' => 'checkbox',
      '#title' => t('Do not send notifications for this post'),
      '#description' => t('Check to prevent notification emails for this content.'),
      '#default_value' => FALSE,
      '#weight' => 100,
      '#group' => 'advanced',
    ];
    $form['actions']['submit']['#submit'][] = [static::class, 'nodeFormSubmit'];
  }

  /**
   * Submit handler — stores per-post suppression in State API.
   *
   * Static so it can be serialised into form state.
   */
  public static function nodeFormSubmit(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $node = $form_state->getFormObject()->getEntity();
    $key = 'do_notifications_suppress_' . $node->id();
    if ($form_state->getValue('do_notifications_suppress')) {
      \Drupal::state()->set($key, TRUE);
    }
    else {
      \Drupal::state()->delete($key);
    }
  }

  /**
   * Records a CONTENT-scoped `node_created` event when a publishable node is
   * created.
   *
   * This is the "a post now exists" signal, not the "notify group members"
   * signal. It runs the instant the node row is written, before any
   * group_relationship for it can exist, so its `group_ids` is always empty by
   * construction (see recordEvent()/getGroupIds()). The group-facing "added to
   * group" notification — with real group_ids — is recorded separately, off the
   * group_relationship insert, by {@see self::groupRelationshipInsert()}. The
   * two are distinct events by design so a node created directly in a group
   * yields one content event here and one group event there, never a duplicate
   * group-scoped event.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if (!$node->isPublished()) {
      return;
    }
    if (!in_array($node->bundle(), self::CONTENT_TYPES, TRUE)) {
      return;
    }
    $this->recordEvent('node_created', $node);
  }

  /**
   * Records a GROUP-scoped `added_to_group` event when a node joins a group.
   *
   * Reacts to the insert of a `group_relationship` (Group 4.x's group_content)
   * entity for a `group_node:*` content relationship — the ONLY hook that fires
   * uniformly for both "a node created directly in a group" and "an existing
   * node cross-posted into a group later" (Group 4.x add-to-group invalidates
   * cache tags with no node resave, so no node_update hook can see it —
   * CR 2025-05-23).
   *
   * Discrimination is by content-plugin BASE id (`group_node`), derived from the
   * relationship's plugin id (e.g. `group_node:post`). This deliberately:
   *   - EXCLUDES `group_membership` relationships (a member joining is not a
   *     content-added notification);
   *   - is immune to the config-entity-id `documentation -> doc` alias
   *     divergence the harness has, because the plugin id (`group_node:doc` vs
   *     `group_node:documentation`) is matched on its base id `group_node`, not
   *     on the assembled relationship-type id string that getGroupIds() keys on.
   *
   * The recorded event carries the node as `entity_id`/`entity_type` and the
   * single group the relationship links to as `group_ids` — resolved directly
   * from the relationship (getGroup()), NOT via getGroupIds(), so it is correct
   * regardless of the bundle-id alias.
   */
  #[Hook('group_relationship_insert')]
  public function groupRelationshipInsert(GroupRelationshipInterface $relationship): void {
    // Only content (group_node:*) relationships — never memberships or other
    // plugins. The plugin id is '<base>:<derivative>', e.g. 'group_node:post'.
    $plugin_id = $relationship->getPluginId();
    if (!str_starts_with($plugin_id, 'group_node:')) {
      return;
    }

    $entity = $relationship->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }
    // Mirror node_insert's eligibility: published, on-list bundle.
    if (!$entity->isPublished()) {
      return;
    }
    if (!in_array($entity->bundle(), self::CONTENT_TYPES, TRUE)) {
      return;
    }

    $item = [
      'event' => 'added_to_group',
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'bundle' => $entity->bundle(),
      'author_uid' => $entity->getOwnerId(),
      // The group this relationship links the node to — resolved from the
      // relationship itself, so it is alias-proof (no getGroupIds() lookup).
      'group_ids' => [$relationship->getGroup()->id()],
      'timestamp' => \Drupal::time()->getRequestTime(),
    ];
    $this->queueFactory->get('do_notifications')->createItem($item);
  }

  /**
   * Records a notification event on comment creation; auto-subscribes commenter.
   */
  #[Hook('comment_insert')]
  public function commentInsert(CommentInterface $comment): void {
    $item = [
      'event' => 'comment_created',
      'entity_type' => 'comment',
      'entity_id' => $comment->id(),
      'bundle' => $comment->bundle(),
      'author_uid' => $comment->getOwnerId(),
      'parent_entity_type' => $comment->getCommentedEntityTypeId(),
      'parent_entity_id' => $comment->getCommentedEntityId(),
      'group_ids' => [],
      'timestamp' => \Drupal::time()->getRequestTime(),
    ];

    if ($comment->getCommentedEntityTypeId() === 'node') {
      $parent = $comment->getCommentedEntity();
      if ($parent) {
        $item['group_ids'] = $this->getGroupIds($parent);
      }
    }

    $this->queueFactory->get('do_notifications')->createItem($item);

    // Auto-subscribe commenter to the thread via follow_content flag.
    // FlagServiceInterface cannot be DI-injected on hook_implementations services
    // (Drupal DefinitionErrorExceptionPass rejects unknown interface aliases).
    try {
      /** @var \Drupal\flag\FlagServiceInterface $flag_service */
      $flag_service = \Drupal::service('flag');
      $flag = $flag_service->getFlagById('follow_content');
      if ($flag && $comment->getCommentedEntityTypeId() === 'node') {
        $entity = $comment->getCommentedEntity();
        $account = $this->entityTypeManager
          ->getStorage('user')
          ->load($comment->getOwnerId());
        if ($entity && $account && !$flag_service->getFlagging($flag, $entity, $account)) {
          $flag_service->flag($flag, $entity, $account);
        }
      }
    }
    catch (\Exception $e) {
      // Auto-subscribe is a convenience, not critical — silently continue.
      \Drupal::logger('do_notifications')->notice(
        'Auto-subscribe failed: @msg',
        ['@msg' => $e->getMessage()],
      );
    }
  }

  /**
   * Records a notification event to the do_notifications queue.
   *
   * One item per triggering event (not per recipient).
   */
  private function recordEvent(string $event, mixed $entity): void {
    $item = [
      'event' => $event,
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'bundle' => $entity->bundle(),
      'author_uid' => $entity->getOwnerId(),
      'group_ids' => $this->getGroupIds($entity),
      'timestamp' => \Drupal::time()->getRequestTime(),
    ];
    $this->queueFactory->get('do_notifications')->createItem($item);
  }

  /**
   * Returns group IDs this entity belongs to via group_relationship.
   *
   * Implements the doc alias (documentation → doc) per the 32-char ID limit
   * established in Step 140.
   *
   * Group 4.x note: this queries group_relationship by the relationship-type
   * config-entity ID (the 'type' property, e.g.
   * 'community_group-group_node-post'). That ID string is NOT the property
   * renamed by CR 2026-06-19 — that CR renamed GroupRelationshipType's
   * $content_plugin property to $relation_type, which is stored ON the type
   * entity, not used as the query key here. So loadByProperties(['type' => ...])
   * is unaffected by the rename and this module ships no config YAML with a
   * content_plugin: key to migrate.
   *
   * TODO(group4-VERIFY): confirm on a real 4.x build that Group still composes
   * relationship-type IDs as '<group_type>-group_node-<bundle>' (the plugin-ID
   * derivation used to build this string is unchanged by the property rename,
   * but the ID convention itself should be spot-checked against installed 4.x
   * config, since a wrong 'type' silently yields an empty group_ids array via
   * the catch below rather than an error).
   */
  private function getGroupIds(mixed $entity): array {
    if ($entity->getEntityTypeId() !== 'node') {
      return [];
    }
    try {
      $bundle = $entity->bundle();
      // Step 140: 'documentation' is abbreviated to 'doc' in relationship IDs.
      $type = 'community_group-group_node-' . ($bundle === 'documentation' ? 'doc' : $bundle);
      $relationships = $this->entityTypeManager
        ->getStorage('group_relationship')
        ->loadByProperties([
          'entity_id' => $entity->id(),
          'type' => $type,
        ]);
      return array_map(fn($r) => $r->getGroup()->id(), $relationships);
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
