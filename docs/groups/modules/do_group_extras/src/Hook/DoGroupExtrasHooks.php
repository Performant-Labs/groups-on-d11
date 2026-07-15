<?php

declare(strict_types=1);

namespace Drupal\do_group_extras\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for do_group_extras.
 *
 * Provides archive enforcement, submission guidelines, and moderation
 * defaults for community groups.
 */
class DoGroupExtrasHooks {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly QueueFactory $queueFactory,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Adds submission guidelines to group forms.
   */
  #[Hook('form_alter')]
  public function formAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    if (str_contains($form_id, 'group_community_group')) {
      $form['submission_guidelines'] = [
        '#type' => 'details',
        '#title' => t('Submission Guidelines'),
        '#open' => TRUE,
        '#weight' => -100,
        '#markup' => t('<p>Please review community guidelines before creating a group. All new groups created by non-administrators require moderator approval before they become visible.</p>'),
      ];
    }
  }

  /**
   * Defaults new groups created by non-admins to unpublished (pending review).
   */
  #[Hook('entity_presave')]
  public function entityPresave(mixed $entity): void {
    if (!($entity instanceof GroupInterface) || !$entity->isNew()) {
      return;
    }
    if (
      $this->currentUser->hasPermission('administer group') ||
      $this->currentUser->hasPermission('administer groups')
    ) {
      return;
    }
    $entity->set('status', 0);
  }

  /**
   * Queues a moderator notification when a new group is pending approval.
   */
  #[Hook('entity_insert')]
  public function entityInsert(mixed $entity): void {
    if (!($entity instanceof GroupInterface) || $entity->isPublished()) {
      return;
    }
    $this->notifyModerators($entity);
  }

  /**
   * Adds "Archived" CSS class and library to archived groups.
   */
  #[Hook('preprocess_group')]
  public function preprocessGroup(array &$variables): void {
    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = $variables['group'];
    if (!$group->hasField('field_group_type')) {
      return;
    }
    $group_type_ref = $group->get('field_group_type')->entity;
    if ($group_type_ref && $group_type_ref->getName() === 'Archive') {
      $variables['attributes']['class'][] = 'group--archived';
      $variables['archived'] = TRUE;
      $variables['#attached']['library'][] = 'do_group_extras/do_group_extras';
    }
  }

  /**
   * Denies node creation in archived groups.
   */
  #[Hook('node_access')]
  public function nodeAccess(
    NodeInterface $node,
    string $op,
    AccountInterface $account,
  ): AccessResult {
    if ($op !== 'create') {
      return AccessResult::neutral();
    }
    $group = $this->routeMatch->getParameter('group');
    if (!($group instanceof GroupInterface) || !$group->hasField('field_group_type')) {
      return AccessResult::neutral();
    }
    $group_type_ref = $group->get('field_group_type')->entity;
    if ($group_type_ref && $group_type_ref->getName() === 'Archive') {
      return AccessResult::forbidden('This group is archived. No new content can be created.')
        ->addCacheableDependency($group);
    }
    return AccessResult::neutral();
  }

  /**
   * Queues a pending-group notification for site_moderator users.
   *
   * Records to a queue only — actual email delivery is handled externally.
   * Logger uses \Drupal::logger() — acceptable for a private helper method.
   */
  private function notifyModerators(GroupInterface $group): void {
    $queue = $this->queueFactory->get('do_group_extras_pending_notification');
    $queue->createItem([
      'group_id' => $group->id(),
      'group_label' => $group->label(),
      'author_uid' => $group->getOwnerId(),
      'timestamp' => \Drupal::time()->getRequestTime(),
    ]);
    \Drupal::logger('do_group_extras')->notice(
      'Pending group notification queued for group %label (gid=%gid)',
      ['%label' => $group->label(), '%gid' => $group->id()],
    );
  }

}
