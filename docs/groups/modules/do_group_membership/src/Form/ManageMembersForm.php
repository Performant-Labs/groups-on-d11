<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Manage-members page: member table + approve/deny/unblock actions.
 *
 * Implemented as a Form (not a plain controller) so every action in the
 * Actions column is a real `<button type="submit">` element (AC-7/AC-15 —
 * "real button/form submit, never a JS-only div click-handler"). Role
 * change and Remove are single-purpose sub-forms reached via their own
 * routes (ChangeRoleForm / RemoveMemberForm — the latter is a real confirm
 * step per the wireframe); Approve/Deny/Unblock have no field input beyond
 * the target relationship, so they are submit buttons on this form itself.
 */
class ManageMembersForm extends FormBase {

  public function __construct(
    protected GroupMembershipManager $manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('do_group_membership.manager'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'do_group_membership_manage_members_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form['#attached']['library'][] = 'do_group_membership/manage_members';
    $form_state->set('group', $group);

    $form['intro'] = [
      '#markup' => '<p class="do-group-membership__intro">' . $this->t('Add, remove, or change the role of anyone in this group. Pending requests need your approval before the person can access group content.') . '</p>',
    ];

    $form['add_member_link'] = [
      '#type' => 'link',
      '#title' => $this->t('+ Add member'),
      '#url' => Url::fromRoute('do_group_membership.add_member', ['group' => $group->id()]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $memberships = $group->getMembers();
    $active_organizer_count = $this->countActiveOrganizers($group);

    $header = [
      $this->t('Member name'),
      $this->t('Role(s)'),
      $this->t('Status'),
      $this->t('Joined/Requested'),
      $this->t('Actions'),
    ];

    $form['table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['do-group-membership__table']],
      '#header' => $header,
      '#empty' => $this->t('This group has no members yet. Add the first member below.'),
    ];

    foreach ($memberships as $membership) {
      $this->buildRow($form['table'], $membership, $group, $active_organizer_count);
    }

    $form['pager'] = ['#type' => 'pager'];

    return $form;
  }

  /**
   * Builds one member-table row, including its per-row action buttons.
   *
   * @param array $table
   *   The table render array (rows appended by reference).
   * @param \Drupal\group\Entity\GroupRelationshipInterface $membership
   *   The membership relationship (a GroupMembership, itself a
   *   GroupRelationshipInterface).
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param int $active_organizer_count
   *   The group's active-Organizer count, for the last-Organizer guard's
   *   disable-before-attempt treatment.
   */
  protected function buildRow(array &$table, GroupRelationshipInterface $membership, GroupInterface $group, int $active_organizer_count): void {
    $id = (string) $membership->id();
    $account = $membership->getEntity();
    $status = $this->relationshipStatus($membership);
    $role_ids = array_column($membership->hasField('group_roles') ? $membership->get('group_roles')->getValue() : [], 'target_id');
    $role_labels = array_map([$this, 'roleLabel'], $role_ids);
    $role_text = $role_labels ? implode(', ', $role_labels) : (string) $this->t('Member');
    if ($status === GroupMembershipManager::STATUS_PENDING) {
      $role_text = (string) $this->t('@roles (requested)', ['@roles' => $role_text]);
    }

    $is_last_organizer = $status === GroupMembershipManager::STATUS_ACTIVE
      && in_array(GroupMembershipManager::ORGANIZER_ROLE_ID, $role_ids, TRUE)
      && $active_organizer_count <= 1;

    $date_label = $status === GroupMembershipManager::STATUS_PENDING
      ? $this->t('Requested: @date', ['@date' => $this->formatDate($membership)])
      : $this->t('Joined: @date', ['@date' => $this->formatDate($membership)]);

    $row_key = 'row_' . $id;
    $table[$row_key]['#attributes']['data-status'] = $status;

    $table[$row_key]['name'] = [
      '#type' => 'markup',
      '#markup' => $account ? $account->toLink()->toString() : (string) $this->t('(unknown user)'),
    ];
    $table[$row_key]['roles'] = ['#markup' => $role_text];
    $table[$row_key]['status'] = $this->buildBadge($status);
    $table[$row_key]['date'] = ['#markup' => (string) $date_label];
    $table[$row_key]['actions'] = $this->buildActions($id, $group, $status, $is_last_organizer);
  }

  /**
   * Builds the status badge markup: glyph (aria-hidden) + visible text.
   *
   * @param string $status
   *   The relationship status value.
   *
   * @return array
   *   A render array for the badge.
   */
  protected function buildBadge(string $status): array {
    $map = [
      GroupMembershipManager::STATUS_ACTIVE => ['glyph' => '✓', 'label' => $this->t('Active')],
      GroupMembershipManager::STATUS_PENDING => ['glyph' => '⏳', 'label' => $this->t('Pending')],
      GroupMembershipManager::STATUS_BLOCKED => ['glyph' => '⛔', 'label' => $this->t('Blocked')],
    ];
    $info = $map[$status] ?? $map[GroupMembershipManager::STATUS_ACTIVE];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => [
        'class' => ['do-group-membership__badge', 'do-group-membership__badge--' . $status],
        'data-state' => $status,
      ],
      '#value' => '<span aria-hidden="true">' . $info['glyph'] . '</span> ' . $info['label'],
    ];
  }

  /**
   * Builds the Actions-column controls for one row.
   *
   * Every control here is a real `<button type="submit">` (AC-7/AC-15):
   * Approve/Deny/Unblock submit this form directly; Role/Remove submit
   * this form too, but their #submit callback redirects to their own
   * single-purpose route (ChangeRoleForm / RemoveMemberForm's confirm
   * step) rather than acting immediately.
   *
   * @param string $id
   *   The membership relationship id.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param string $status
   *   The relationship's current status.
   * @param bool $is_last_organizer
   *   Whether this row is the group's last active Organizer.
   *
   * @return array
   *   A render array container of submit buttons.
   */
  protected function buildActions(string $id, GroupInterface $group, string $status, bool $is_last_organizer): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['do-group-membership__actions']],
    ];

    if ($status === GroupMembershipManager::STATUS_PENDING) {
      $build['approve'] = [
        '#type' => 'submit',
        '#name' => 'approve_' . $id,
        '#value' => $this->t('Approve'),
        '#attributes' => ['class' => ['button', 'button--primary']],
        '#submit' => ['::approveSubmit'],
        '#relationship_id' => $id,
        '#limit_validation_errors' => [],
      ];
      $build['deny'] = [
        '#type' => 'submit',
        '#name' => 'deny_' . $id,
        '#value' => $this->t('Deny'),
        '#attributes' => ['class' => ['button', 'button--danger']],
        '#submit' => ['::denySubmit'],
        '#relationship_id' => $id,
        '#limit_validation_errors' => [],
      ];
      return $build;
    }

    if ($status === GroupMembershipManager::STATUS_BLOCKED) {
      $build['unblock'] = [
        '#type' => 'submit',
        '#name' => 'unblock_' . $id,
        '#value' => $this->t('Unblock'),
        '#attributes' => ['class' => ['button']],
        '#submit' => ['::unblockSubmit'],
        '#relationship_id' => $id,
        '#limit_validation_errors' => [],
      ];
    }

    $build['role'] = [
      '#type' => 'submit',
      '#name' => 'role_' . $id,
      '#value' => $this->t('Role ▾'),
      '#attributes' => ['class' => ['button']],
      '#submit' => ['::roleSubmit'],
      '#relationship_id' => $id,
      '#limit_validation_errors' => [],
      '#disabled' => $is_last_organizer,
    ];
    $build['remove'] = [
      '#type' => 'submit',
      '#name' => 'remove_' . $id,
      '#value' => $this->t('Remove'),
      '#attributes' => ['class' => ['button', 'button--danger']],
      '#submit' => ['::removeSubmit'],
      '#relationship_id' => $id,
      '#limit_validation_errors' => [],
      '#disabled' => $is_last_organizer,
    ];

    if ($is_last_organizer) {
      $build['role']['#attributes']['aria-describedby'] = 'do-group-membership-guard-' . $id;
      $build['remove']['#attributes']['aria-describedby'] = 'do-group-membership-guard-' . $id;
      $build['guard_note'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'id' => 'do-group-membership-guard-' . $id,
          'class' => ['do-group-membership__guard-note'],
        ],
        '#value' => 'ⓘ ' . $this->t('Last Organizer — promote another member first.'),
      ];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No default action; every button has its own #submit callback.
  }

  /**
   * Submit handler: approve a pending request.
   */
  public function approveSubmit(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');
    $relationship = $this->loadRelationship($form_state);
    $account = $relationship?->getEntity();
    $applied = $this->manager->approvePending($relationship);
    if ($applied) {
      $this->messenger()->addStatus($this->t("@name's request to join has been approved. They are now an active Member.", [
        '@name' => $account ? $account->label() : $this->t('The user'),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('This request was already handled.'));
    }
    $form_state->setRedirect('do_group_membership.manage_members', ['group' => $group->id()]);
  }

  /**
   * Submit handler: deny a pending request.
   */
  public function denySubmit(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');
    $relationship = $this->loadRelationship($form_state);
    $account = $relationship?->getEntity();
    $applied = $this->manager->denyPending($relationship);
    if ($applied) {
      $this->messenger()->addStatus($this->t("@name's request to join has been denied.", [
        '@name' => $account ? $account->label() : $this->t('The user'),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('This request was already handled.'));
    }
    $form_state->setRedirect('do_group_membership.manage_members', ['group' => $group->id()]);
  }

  /**
   * Submit handler: unblock a blocked membership.
   */
  public function unblockSubmit(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');
    $relationship = $this->loadRelationship($form_state);
    if ($relationship) {
      $this->manager->changeStatus($relationship, GroupMembershipManager::STATUS_ACTIVE);
      $account = $relationship->getEntity();
      $this->messenger()->addStatus($this->t('@name has been unblocked.', [
        '@name' => $account ? $account->label() : $this->t('The user'),
      ]));
    }
    $form_state->setRedirect('do_group_membership.manage_members', ['group' => $group->id()]);
  }

  /**
   * Submit handler: navigate to the Role-change sub-form for this row.
   */
  public function roleSubmit(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');
    $triggering = $form_state->getTriggeringElement();
    $form_state->setRedirect('do_group_membership.change_role', [
      'group' => $group->id(),
      'group_relationship' => $triggering['#relationship_id'],
    ]);
  }

  /**
   * Submit handler: navigate to the Remove-member confirm step for this row.
   */
  public function removeSubmit(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');
    $triggering = $form_state->getTriggeringElement();
    $form_state->setRedirect('do_group_membership.remove_member', [
      'group' => $group->id(),
      'group_relationship' => $triggering['#relationship_id'],
    ]);
  }

  /**
   * Loads the relationship targeted by the triggering button, if it exists.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface|null
   *   The relationship, or NULL if it no longer exists (AC-10's race case).
   */
  protected function loadRelationship(FormStateInterface $form_state): ?GroupRelationshipInterface {
    $triggering = $form_state->getTriggeringElement();
    $id = $triggering['#relationship_id'] ?? NULL;
    if ($id === NULL) {
      return NULL;
    }
    $relationship = $this->entityTypeManager->getStorage('group_relationship')->load($id);
    return $relationship instanceof GroupRelationshipInterface ? $relationship : NULL;
  }

  /**
   * Maps a group role id to a short human label for the Role(s) column.
   *
   * @param string $role_id
   *   The group role id (e.g. 'community_group-organizer').
   *
   * @return string
   *   'Organizer', 'Moderator', or 'Member' (falls back to the raw suffix
   *   for any other role id, e.g. a future custom role).
   */
  protected function roleLabel(string $role_id): string {
    $map = [
      'community_group-organizer' => (string) $this->t('Organizer'),
      'community_group-moderator' => (string) $this->t('Moderator'),
      'community_group-member' => (string) $this->t('Member'),
    ];
    return $map[$role_id] ?? $role_id;
  }

  /**
   * Reads a relationship's status value, defaulting to active.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship.
   *
   * @return string
   *   The status value.
   */
  protected function relationshipStatus(GroupRelationshipInterface $relationship): string {
    if (!$relationship->hasField('field_membership_status') || $relationship->get('field_membership_status')->isEmpty()) {
      return GroupMembershipManager::STATUS_ACTIVE;
    }
    return (string) $relationship->get('field_membership_status')->value;
  }

  /**
   * Formats a relationship's `created` base field for display.
   *
   * Per [B-4]: "joined date" reuses the relationship entity's own base
   * `created` field — no new field.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship.
   *
   * @return string
   *   A formatted date string.
   */
  protected function formatDate(GroupRelationshipInterface $relationship): string {
    $timestamp = method_exists($relationship, 'getCreatedTime') ? $relationship->getCreatedTime() : NULL;
    if (!$timestamp) {
      return (string) $this->t('unknown');
    }
    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d');
  }

  /**
   * Counts the group's active Organizer memberships.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   *
   * @return int
   *   The number of active Organizer memberships.
   */
  protected function countActiveOrganizers(GroupInterface $group): int {
    $count = 0;
    foreach ($group->getMembers([GroupMembershipManager::ORGANIZER_ROLE_ID]) as $organizer_membership) {
      if ($this->relationshipStatus($organizer_membership) === GroupMembershipManager::STATUS_ACTIVE) {
        $count++;
      }
    }
    return $count;
  }

}
