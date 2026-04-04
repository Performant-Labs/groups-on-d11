<?php

namespace Drupal\do_notifications\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Confirmation form for cancelling all subscriptions.
 */
class CancelAllSubscriptionsForm extends ConfirmFormBase {

  /**
   * The user whose subscriptions are being cancelled.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'do_notifications_cancel_all_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to cancel all subscriptions?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will remove all your content follows, user follows, and term follows. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('do_notifications.notification_settings', [
      'user' => $this->user->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL) {
    $this->user = $user;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $flag_service = \Drupal::service('flag');
    $flag_ids = ['follow_content', 'follow_user', 'follow_term'];
    $total = 0;

    foreach ($flag_ids as $flag_id) {
      $flag = $flag_service->getFlagById($flag_id);
      if (!$flag) {
        continue;
      }

      $flaggings = \Drupal::entityTypeManager()
        ->getStorage('flagging')
        ->loadByProperties([
          'flag_id' => $flag_id,
          'uid' => $this->user->id(),
        ]);

      foreach ($flaggings as $flagging) {
        try {
          $entity = $flagging->getFlaggable();
          if ($entity) {
            $flag_service->unflag($flag, $entity, $this->user);
            $total++;
          }
        }
        catch (\Exception $e) {
          // Log but continue.
          \Drupal::logger('do_notifications')->warning('Failed to unflag: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    $this->messenger()->addStatus($this->t('Cancelled @count subscriptions.', ['@count' => $total]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
