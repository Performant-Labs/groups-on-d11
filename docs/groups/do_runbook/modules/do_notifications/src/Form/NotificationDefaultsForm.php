<?php

namespace Drupal\do_notifications\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin form for site-wide notification defaults.
 *
 * Path: /admin/config/people/notification-defaults
 * Access: 'administer site configuration' permission.
 */
class NotificationDefaultsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['do_notifications.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'do_notifications_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('do_notifications.settings');
    $form['default_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Default notification frequency'),
      '#options' => [
        'immediately' => $this->t('Immediately'),
        'daily' => $this->t('Daily digest'),
        'weekly' => $this->t('Weekly digest'),
      ],
      '#default_value' => $config->get('default_frequency') ?? 'immediately',
    ];
    $form['auto_subscribe_comment'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-subscribe on comment'),
      '#description' => $this->t('Automatically follow content when a user comments on it.'),
      '#default_value' => $config->get('auto_subscribe_comment') ?? TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('do_notifications.settings')
      ->set('default_frequency', $form_state->getValue('default_frequency'))
      ->set('auto_subscribe_comment', $form_state->getValue('auto_subscribe_comment'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
