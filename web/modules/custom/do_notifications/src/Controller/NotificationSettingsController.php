<?php

namespace Drupal\do_notifications\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\flag\FlagServiceInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the notification settings page.
 *
 * Shows subscription management: active subscriptions table, disable toggle,
 * cancel-all link, and email frequency settings.
 */
class NotificationSettingsController extends ControllerBase {

  /**
   * The flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * Constructs a NotificationSettingsController.
   */
  public function __construct(FlagServiceInterface $flag_service) {
    $this->flagService = $flag_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('flag')
    );
  }

  /**
   * Renders the notification settings page.
   */
  public function page(UserInterface $user) {
    $build = [];
    $current_user = $this->currentUser();

    // Only allow users to view their own settings.
    if ($current_user->id() != $user->id() && !$current_user->hasPermission('administer users')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    // Check if notifications are disabled.
    $disabled = \Drupal::state()->get('do_notifications_disabled_' . $user->id(), FALSE);

    if ($disabled) {
      $build['disabled_warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('Your notifications are currently <strong>disabled</strong>. You will not receive any notification emails.') .
          '</div>',
      ];

      $build['enable_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Re-enable notifications'),
        '#url' => Url::fromRoute('do_notifications.notification_settings', ['user' => $user->id()], ['query' => ['action' => 'enable']]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    // Handle enable/disable actions.
    $action = \Drupal::request()->query->get('action');
    if ($action === 'disable') {
      \Drupal::state()->set('do_notifications_disabled_' . $user->id(), TRUE);
      $this->messenger()->addStatus($this->t('All notifications have been temporarily disabled.'));
      return $this->redirect('do_notifications.notification_settings', ['user' => $user->id()]);
    }
    elseif ($action === 'enable') {
      \Drupal::state()->delete('do_notifications_disabled_' . $user->id());
      $this->messenger()->addStatus($this->t('Notifications have been re-enabled.'));
      return $this->redirect('do_notifications.notification_settings', ['user' => $user->id()]);
    }

    // Get all subscriptions.
    $subscriptions = $this->getSubscriptions($user);
    $count = count($subscriptions);

    $build['summary'] = [
      '#markup' => '<h3>' . $this->t('Active Subscriptions: @count', ['@count' => $count]) . '</h3>',
    ];

    // Notification frequency.
    if ($user->hasField('field_notification_frequency') && !$user->get('field_notification_frequency')->isEmpty()) {
      $frequency = $user->get('field_notification_frequency')->value;
      $build['frequency'] = [
        '#markup' => '<p>' . $this->t('Email frequency: <strong>@freq</strong>', [
          '@freq' => $frequency,
        ]) . '</p>',
      ];
    }

    // Subscriptions table.
    if ($count > 0) {
      $rows = [];
      foreach ($subscriptions as $sub) {
        $rows[] = [
          $sub['type'],
          $sub['title'],
          $sub['remove_link'],
        ];
      }

      $build['subscriptions'] = [
        '#type' => 'table',
        '#header' => [$this->t('Type'), $this->t('Title'), $this->t('Action')],
        '#rows' => $rows,
        '#empty' => $this->t('No active subscriptions.'),
      ];
    }

    // Action links.
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['notification-actions']],
    ];

    if (!$disabled) {
      $build['actions']['disable'] = [
        '#type' => 'link',
        '#title' => $this->t('Temporarily disable all notifications'),
        '#url' => Url::fromRoute('do_notifications.notification_settings', ['user' => $user->id()], ['query' => ['action' => 'disable']]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    if ($count > 0) {
      $build['actions']['cancel_all'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel all subscriptions'),
        '#url' => Url::fromRoute('do_notifications.cancel_all', ['user' => $user->id()]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }

    return $build;
  }

  /**
   * Builds the list of active subscriptions for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return array
   *   An array of subscription data.
   */
  protected function getSubscriptions(UserInterface $user): array {
    $subscriptions = [];

    // Follow content (nodes).
    $follow_content = $this->flagService->getFlagById('follow_content');
    if ($follow_content) {
      $flaggings = \Drupal::entityTypeManager()
        ->getStorage('flagging')
        ->loadByProperties([
          'flag_id' => 'follow_content',
          'uid' => $user->id(),
        ]);

      foreach ($flaggings as $flagging) {
        $entity = $flagging->getFlaggable();
        if ($entity) {
          $subscriptions[] = [
            'type' => $this->t('Content'),
            'title' => $entity->label() ?: $this->t('(unknown)'),
            'remove_link' => Link::fromTextAndUrl(
              $this->t('Remove'),
              Url::fromRoute('flag.action_link_unflag', [
                'flag' => 'follow_content',
                'entity_id' => $entity->id(),
              ])
            )->toString(),
          ];
        }
      }
    }

    // Follow user.
    $follow_user = $this->flagService->getFlagById('follow_user');
    if ($follow_user) {
      $flaggings = \Drupal::entityTypeManager()
        ->getStorage('flagging')
        ->loadByProperties([
          'flag_id' => 'follow_user',
          'uid' => $user->id(),
        ]);

      foreach ($flaggings as $flagging) {
        $entity = $flagging->getFlaggable();
        if ($entity) {
          $subscriptions[] = [
            'type' => $this->t('User'),
            'title' => $entity->label() ?: $this->t('(unknown)'),
            'remove_link' => Link::fromTextAndUrl(
              $this->t('Remove'),
              Url::fromRoute('flag.action_link_unflag', [
                'flag' => 'follow_user',
                'entity_id' => $entity->id(),
              ])
            )->toString(),
          ];
        }
      }
    }

    // Follow term.
    $follow_term = $this->flagService->getFlagById('follow_term');
    if ($follow_term) {
      $flaggings = \Drupal::entityTypeManager()
        ->getStorage('flagging')
        ->loadByProperties([
          'flag_id' => 'follow_term',
          'uid' => $user->id(),
        ]);

      foreach ($flaggings as $flagging) {
        $entity = $flagging->getFlaggable();
        if ($entity) {
          $subscriptions[] = [
            'type' => $this->t('Tag'),
            'title' => $entity->label() ?: $this->t('(unknown)'),
            'remove_link' => Link::fromTextAndUrl(
              $this->t('Remove'),
              Url::fromRoute('flag.action_link_unflag', [
                'flag' => 'follow_term',
                'entity_id' => $entity->id(),
              ])
            )->toString(),
          ];
        }
      }
    }

    return $subscriptions;
  }

}
