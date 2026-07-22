<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\do_group_membership\Exception\BlockedAccountException;
use Drupal\do_group_membership\Exception\DuplicateMembershipException;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add-member form: user autocomplete + role checkboxes (default Member).
 *
 * Per the brief's [B-6]: validation rejects (form error) a user who
 * already has ANY membership (active/pending/blocked) to this group, and
 * rejects a Drupal-blocked user account (AC-8). New memberships default to
 * `active` status (an organizer/moderator directly adding someone is not
 * a self-service join request).
 */
class AddMemberForm extends FormBase {

  public function __construct(
    protected GroupMembershipManager $manager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('do_group_membership.manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'do_group_membership_add_member_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form_state->set('group', $group);

    $form['uid'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('User'),
      '#target_type' => 'user',
      '#required' => TRUE,
      '#description' => $this->t('Start typing a name or email.'),
    ];

    $form['group_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Role(s)'),
      '#required' => TRUE,
      '#options' => [
        'community_group-member' => $this->t('Member'),
        'community_group-moderator' => $this->t('Moderator'),
        'community_group-organizer' => $this->t('Organizer'),
      ],
      '#default_value' => ['community_group-member'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add member'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('do_group_membership.manage_members', ['group' => $group->id()]),
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
    $uid = $form_state->getValue('uid');
    $account = $uid ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;

    if (!$account) {
      $this->messenger()->addError($this->t('The selected user could not be found.'));
      return;
    }

    $roles = array_values(array_filter($form_state->getValue('group_roles') ?? []));

    try {
      $this->manager->addMember($group, $account, $roles);
      $this->messenger()->addStatus($this->t('@name has been added to this group.', ['@name' => $account->label()]));
    }
    catch (DuplicateMembershipException $e) {
      $this->messenger()->addError($this->t('This user is already a member of this group.'));
      return;
    }
    catch (BlockedAccountException $e) {
      $this->messenger()->addError($this->t("This user's site account is blocked."));
      return;
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('The member could not be added. Please try again.'));
      return;
    }

    $form_state->setRedirect('do_group_membership.manage_members', ['group' => $group->id()]);
  }

}
