<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Hook;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for do_notifications.
 *
 * Per-post opt-out, notification event recording, and comment auto-subscribe.
 * Drupal only records what happened — external system handles delivery.
 *
 * GROUP 4.x COMPATIBILITY NOTE (behavioral risk — read before relying on
 * group-scoped notifications).
 *
 * Notification events are recorded off the CORE node/comment lifecycle hooks
 * (node_insert, comment_insert) — NOT off any Group entity/relationship hook or
 * event. Group membership is only ever resolved as a snapshot at node-insert
 * time, inside getGroupIds().
 *
 * In Group 4.x, "add an entity to a group" invalidates cache tags instead of
 * resaving the entity (change record 2025-05-23), so it fires neither
 * hook_entity_update nor hook_node_update. This module does not implement
 * node_update either, so:
 *
 *   - A node created ALREADY in its group(s) is captured correctly: node_insert
 *     runs after the relationship exists, so getGroupIds() sees the groups.
 *   - A node created UNGROUPED and added to a group LATER records no
 *     group-scoped notification event — on 3.x this was masked only if the
 *     group-add happened to resave the node (which this module also ignores,
 *     as it has no node_update hook); on 4.x there is no resave at all, so the
 *     gap is now permanent and cannot be closed by a resave side effect.
 *
 * This is NOT a regression introduced by the 4.x upgrade for the currently
 * implemented triggers (they were already insert-only), but the 4.x change
 * removes the only path that could have retro-fired them. If group-add-time
 * notifications are ever required, subscribe to Group's relationship
 * create/insert event on the new API rather than expecting a node resave.
 * See TODO(group4-VERIFY) on nodeInsert()/getGroupIds().
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
   * Records a notification event when a published group node is created.
   *
   * TODO(group4-VERIFY): This fires on core node creation only. Group 4.x's
   * "add to group invalidates cache tags instead of resaving the entity"
   * (CR 2025-05-23) means a node added to a group AFTER creation records no
   * group-scoped event here (no node_update hook exists, and there is no
   * resave to piggyback on). Verify on a real 4.x build whether product
   * requirements need a group-add-time notification; if so, react to Group's
   * relationship-create event instead of a node resave. Recording itself is
   * unaffected — this hook does not touch the Group API.
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
