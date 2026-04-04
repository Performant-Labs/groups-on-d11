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
