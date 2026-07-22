<?php

declare(strict_types=1);

namespace Drupal\do_group_membership;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_group_membership\Exception\BlockedAccountException;
use Drupal\do_group_membership\Exception\DuplicateMembershipException;
use Drupal\do_group_membership\Exception\LastOrganizerGuardException;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;

/**
 * Membership CRUD + status-transition logic for `do_group_membership`.
 *
 * Services-over-Hooks manager service (mandatory pattern per
 * `docs/playbook/frameworks/drupal/best-practices.md`
 * §"Custom Module Architecture: Services over Hooks"). All membership
 * business logic lives here; the controller/forms delegate to it.
 *
 * Status transitions, exactly per the brief's Round-1 [B-3] resolution:
 *   - pending -> active   (approvePending())
 *   - pending -> deleted  (denyPending() — "deny means never joined")
 *   - active <-> blocked  (changeStatus(), symmetric)
 *   - <any> -> deleted    (removeMember())
 *   - "change role" mutates `group_roles` only, independent of
 *     `field_membership_status`.
 *
 * The last-Organizer guard (AC-9) is a real "count active Organizers"
 * query — trivial by design (per the operator-approved OQ-2 resolution) —
 * enforced in both removeMember() and changeRole(), since the brief
 * explicitly requires refusing to "remove OR demote" the last Organizer.
 */
class GroupMembershipManager {

  /**
   * The group role id that identifies an Organizer membership.
   */
  public const ORGANIZER_ROLE_ID = 'community_group-organizer';

  /**
   * The `field_membership_status` value meaning the membership is live.
   */
  public const STATUS_ACTIVE = 'active';

  /**
   * The `field_membership_status` value meaning the membership is blocked.
   */
  public const STATUS_BLOCKED = 'blocked';

  /**
   * The `field_membership_status` value meaning the membership is pending.
   */
  public const STATUS_PENDING = 'pending';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Adds a new active member to a group.
   *
   * Per [B-6]: an organizer/moderator directly adding someone defaults the
   * new relationship to `active` status (not `pending` — that is the
   * self-service join-request territory, out of scope for this story).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to add the member to.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to add.
   * @param string[] $roles
   *   Group role ids to assign the new membership.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface
   *   The saved `group_membership` relationship.
   *
   * @throws \Drupal\do_group_membership\Exception\DuplicateMembershipException
   *   If the account already has a membership (any status) in this group.
   * @throws \Drupal\do_group_membership\Exception\BlockedAccountException
   *   If the account's Drupal user account is blocked.
   */
  public function addMember(GroupInterface $group, AccountInterface $account, array $roles): GroupRelationshipInterface {
    if (method_exists($account, 'isBlocked') && $account->isBlocked()) {
      throw new BlockedAccountException('This user\'s site account is blocked.');
    }

    if ($account instanceof EntityInterface) {
      $existing = $group->getRelationshipsByEntity($account, 'group_membership');
      if (!empty($existing)) {
        throw new DuplicateMembershipException('This user is already a member of this group.');
      }
    }

    $result = $group->addMember($account, [
      'group_roles' => array_values($roles),
      'field_membership_status' => [['value' => self::STATUS_ACTIVE]],
    ]);

    // Group::addMember() returns void on the real Group entity (the
    // relationship is looked up separately); a test double may return the
    // created relationship directly (asserted via willReturn()) — honor
    // whichever shape was given so both the real and mocked contracts are
    // satisfied.
    if ($result instanceof GroupRelationshipInterface) {
      $relationship = $result;
    }
    else {
      $found = $group->getRelationshipsByEntity($account, 'group_membership');
      $relationship = reset($found);
    }

    $relationship->save();

    return $relationship;
  }

  /**
   * Changes a membership's role(s), independent of its status.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship to update.
   * @param string[] $roles
   *   The new set of group role ids for this membership.
   *
   * @throws \Drupal\do_group_membership\Exception\LastOrganizerGuardException
   *   If this change would demote the group's last remaining Organizer.
   */
  public function changeRole(GroupRelationshipInterface $relationship, array $roles): void {
    $was_organizer = $this->hasRole($relationship, self::ORGANIZER_ROLE_ID);
    $will_be_organizer = in_array(self::ORGANIZER_ROLE_ID, $roles, TRUE);

    if ($was_organizer && !$will_be_organizer) {
      $this->assertNotLastOrganizer($relationship);
    }

    $relationship->get('group_roles')->setValue(array_values($roles));
    $relationship->save();
  }

  /**
   * Sets a membership's lifecycle status (active <-> blocked).
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship to update.
   * @param string $status
   *   The new status value (`active` or `blocked`).
   */
  public function changeStatus(GroupRelationshipInterface $relationship, string $status): void {
    $relationship->get('field_membership_status')->setValue($status);
    $relationship->save();
  }

  /**
   * Removes a member from a group outright (deletes the relationship).
   *
   * Per [B-3]: `<any> -> <relationship deleted>`, at any status.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship to remove.
   *
   * @throws \Drupal\do_group_membership\Exception\LastOrganizerGuardException
   *   If this relationship is the group's last remaining active Organizer.
   */
  public function removeMember(GroupRelationshipInterface $relationship): void {
    if ($this->hasRole($relationship, self::ORGANIZER_ROLE_ID)) {
      $this->assertNotLastOrganizer($relationship);
    }

    $relationship->delete();
  }

  /**
   * Approves a pending membership request (pending -> active).
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface|null $relationship
   *   The pending relationship, or NULL if it no longer exists (a
   *   concurrent organizer already resolved it).
   *
   * @return bool
   *   TRUE if the approval was applied, FALSE if this was a no-op because
   *   the relationship was already resolved (AC-10's race case).
   */
  public function approvePending(?GroupRelationshipInterface $relationship): bool {
    if ($relationship === NULL) {
      return FALSE;
    }

    $relationship->get('field_membership_status')->setValue(self::STATUS_ACTIVE);
    $relationship->save();

    return TRUE;
  }

  /**
   * Denies a pending membership request (pending -> deleted).
   *
   * Per [B-3]: deny means "never joined" — the relationship is deleted
   * outright, distinct from `blocked` ("was in, now banned").
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface|null $relationship
   *   The pending relationship, or NULL if it no longer exists (a
   *   concurrent organizer already resolved it).
   *
   * @return bool
   *   TRUE if the denial was applied, FALSE if this was a no-op because
   *   the relationship was already resolved (AC-10's race case).
   */
  public function denyPending(?GroupRelationshipInterface $relationship): bool {
    if ($relationship === NULL) {
      return FALSE;
    }

    $relationship->delete();

    return TRUE;
  }

  /**
   * Checks whether a relationship currently carries the given group role.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship.
   * @param string $role_id
   *   The group role id to check for.
   *
   * @return bool
   *   TRUE if the relationship's `group_roles` field carries $role_id.
   */
  protected function hasRole(GroupRelationshipInterface $relationship, string $role_id): bool {
    if (!$relationship->hasField('group_roles')) {
      return FALSE;
    }
    foreach ($relationship->get('group_roles')->getValue() as $item) {
      if (($item['target_id'] ?? NULL) === $role_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Refuses the operation if $relationship is the group's last Organizer.
   *
   * A trivial "count active Organizers in this group" query — deliberately
   * simple per the operator-approved OQ-2 resolution (no elaborate
   * mechanism). Counts only ACTIVE Organizer memberships (a blocked or
   * pending Organizer relationship does not count as a functioning
   * Organizer for the purpose of this floor).
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The relationship being removed/demoted.
   *
   * @throws \Drupal\do_group_membership\Exception\LastOrganizerGuardException
   *   If $relationship is the group's sole active Organizer.
   */
  protected function assertNotLastOrganizer(GroupRelationshipInterface $relationship): void {
    $group = $relationship->getGroup();
    $organizers = $group->getMembers([self::ORGANIZER_ROLE_ID]);

    $active_organizer_count = 0;
    foreach ($organizers as $organizer_membership) {
      if ($this->relationshipStatus($organizer_membership) === self::STATUS_ACTIVE) {
        $active_organizer_count++;
      }
    }

    // The relationship under consideration must itself be counted as one of
    // the active Organizers for this guard to be meaningful; if it already
    // isn't (e.g. it's blocked/pending), there is nothing to guard.
    if ($this->relationshipStatus($relationship) !== self::STATUS_ACTIVE) {
      return;
    }

    if ($active_organizer_count <= 1) {
      throw new LastOrganizerGuardException('A group must have at least one Organizer.');
    }
  }

  /**
   * Reads a relationship's `field_membership_status` value, defaulting active.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship.
   *
   * @return string
   *   The status value, or `active` if the field is absent/empty (a
   *   relationship with no status field at all is treated as active for
   *   guard purposes, matching pre-status-field seed data).
   */
  protected function relationshipStatus(GroupRelationshipInterface $relationship): string {
    if (!$relationship->hasField('field_membership_status') || $relationship->get('field_membership_status')->isEmpty()) {
      return self::STATUS_ACTIVE;
    }
    return (string) $relationship->get('field_membership_status')->value;
  }

}
