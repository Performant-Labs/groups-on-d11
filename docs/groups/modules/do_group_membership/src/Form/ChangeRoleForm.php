<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\do_group_membership\Exception\LastOrganizerGuardException;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Change-role sub-form: checkboxes (multi-valued `group_roles`), per row.
 *
 * Per the operator-approved OQ-1 resolution: checkboxes, matching
 * `group_roles`' own multi-valued cardinality and the add-member form's
 * control type. Server-side, the last-Organizer guard (AC-9) is the
 * authoritative backstop for a demotion that would leave the group with
 * zero active Organizers.
 */
class ChangeRoleForm extends FormBase {

  public function __construct(
    protected GroupMembershipManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('do_group_membership.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'do_group_membership_change_role_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL, ?GroupRelationshipInterface $group_relationship = NULL): array {
    $form_state->set('group', $group);
    $form_state->set('group_relationship', $group_relationship);

    $current_roles = $group_relationship && $group_relationship->hasField('group_roles')
      ? array_column($group_relationship->get('group_roles')->getValue(), 'target_id')
      : [];

    $form['group_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Role(s)'),
      '#required' => TRUE,
      '#options' => [
        'community_group-organizer' => $this->t('Organizer'),
        'community_group-moderator' => $this->t('Moderator'),
        'community_group-member' => $this->t('Member'),
      ],
      '#default_value' => $current_roles,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
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
    /** @var \Drupal\group\Entity\GroupRelationshipInterface $relationship */
    $relationship = $form_state->get('group_relationship');

    $roles = array_values(array_filter($form_state->getValue('group_roles') ?? []));

    try {
      $this->manager->changeRole($relationship, $roles);
      $this->messenger()->addStatus($this->t('The role has been updated.'));
    }
    catch (LastOrganizerGuardException $e) {
      $this->messenger()->addError($this->t('A group must have at least one Organizer.'));
      return;
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('The role could not be updated. Please try again.'));
      return;
    }

    $form_state->setRedirect('do_group_membership.manage_members', ['group' => $group->id()]);
  }

}
