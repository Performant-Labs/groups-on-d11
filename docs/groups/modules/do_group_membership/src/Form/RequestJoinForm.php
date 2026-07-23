<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\do_group_membership\Exception\BlockedAccountException;
use Drupal\do_group_membership\Exception\DuplicateMembershipException;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Outsider-facing single-click "Request to join" form (#121 SC-2, AC-2).
 *
 * Distinct actor + distinct flow from `AddMemberForm` (organizer adds
 * someone else, immediately active): this form is self-service — a
 * non-member requests to join a `moderated`-visibility group themselves,
 * creating a `pending` `group_membership` relationship that an organizer
 * later approves or denies from the EXISTING `/group/{group}/members`
 * ManageMembersForm (brief-response-v2 §A-1 — no new organizer surface in
 * this story).
 *
 * A single submit button, no other fields — matches the wireframe's
 * "one-click request" description and AC-10's E2E locator contract
 * (`role=button,name=/Request to join/i` OR
 * `input[type=submit][value*=Request]`, G9 — Drupal's `#type => submit`
 * renders `<input>`, not `<button>`).
 */
class RequestJoinForm extends FormBase {

  public function __construct(
    protected GroupMembershipManager $manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('do_group_membership.manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'do_group_membership_request_join_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form_state->set('group', $group);

    $form['intro'] = [
      '#markup' => '<p class="do-group-membership__request-join-intro">' . $this->t('This group reviews new member requests. An organizer will approve or deny your request to join @group.', ['@group' => $group?->label() ?? '']) . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Request to join'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $group ? $group->toUrl() : Url::fromRoute('<front>'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = $form_state->get('group');
    $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if (!$account instanceof UserInterface) {
      $this->messenger()->addError($this->t('Your account could not be found. Please try again.'));
      $form_state->setRedirectUrl($group->toUrl());
      return;
    }

    try {
      $this->manager->requestJoin($group, $account);
      $this->messenger()->addStatus($this->t('Your request to join @group has been sent. An organizer will review it soon.', ['@group' => $group->label()]));
    }
    catch (DuplicateMembershipException $e) {
      $this->messenger()->addWarning($this->t('You already have a membership (or a pending request) for this group.'));
    }
    catch (BlockedAccountException $e) {
      $this->messenger()->addError($this->t('Your account cannot request to join at this time.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Your request could not be sent. Please try again.'));
    }

    $form_state->setRedirectUrl($group->toUrl());
  }

}
