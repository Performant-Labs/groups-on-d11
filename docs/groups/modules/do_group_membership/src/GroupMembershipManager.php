<?php

declare(strict_types=1);

namespace Drupal\do_group_membership;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\do_group_membership\Exception\BlockedAccountException;
use Drupal\do_group_membership\Exception\DuplicateMembershipException;
use Drupal\do_group_membership\Exception\LastOrganizerGuardException;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\user\UserInterface;

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
 *
 * #121 SC-2 adds two self-service verbs on top of the same status model:
 *   - <none> -> pending  (requestJoin() — a moderated group's outsider
 *     self-request; distinct actor from addMember()'s organizer-adds-
 *     someone-else path)
 *   - joinPolicyFor()    (a pure classifier: `field_group_visibility` ->
 *     'open' | 'request' | 'invite', reused by the route-level access
 *     callback and the entity-create access hook so both stay in
 *     lockstep with one source of truth)
 * Per A-W1, addMember() and requestJoin() both delegate to a private
 * createMembership() helper so the blocked/duplicate guards and the
 * `Group::addMember()` call shape cannot drift apart between the two
 * verbs.
 *
 * #144 MC-6 adds ensureRole() — an additive, idempotent role grant on an
 * EXISTING relationship (e.g. adding Organizer to the creator's
 * already-form-created, Admin-carrying membership), deliberately distinct
 * from changeRole()'s replace-the-whole-set semantics (see ensureRole()'s
 * own docblock for why the two must not be conflated).
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
   * self-service join-request territory, handled by requestJoin() as of
   * #121).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to add the member to.
   * @param \Drupal\user\UserInterface $account
   *   The user account to add.
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
  public function addMember(GroupInterface $group, UserInterface $account, array $roles): GroupRelationshipInterface {
    return $this->createMembership($group, $account, self::STATUS_ACTIVE, $roles);
  }

  /**
   * Creates a pending self-service join request (#121 SC-2, AC-2).
   *
   * Distinct actor from {@see self::addMember()}: an organizer/moderator
   * adds someone else (active, immediately); an outsider requests to join
   * themselves (pending, awaiting organizer approval via the existing
   * `ManageMembersForm`'s approve/deny row actions). Only reachable in
   * practice on a `moderated`-visibility group — the route-level
   * `_custom_access` callback
   * (`ManageMembersController::requestJoinAccess()`) and the entity-create
   * access gate (`Drupal\do_group_membership\Hook\GroupAccessHook`) both
   * enforce that in front of this method, so this method itself does not
   * re-check visibility — it stays a pure state-transition, matching this
   * manager's existing cadence (defense-in-depth is the callers'
   * responsibility, not duplicated here).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group being requested to join.
   * @param \Drupal\user\UserInterface $account
   *   The requesting user account.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface
   *   The saved, pending `group_membership` relationship, with no
   *   `group_roles` (AC-2 — no role until an organizer approves).
   *
   * @throws \Drupal\do_group_membership\Exception\DuplicateMembershipException
   *   If the account already has a membership (any status — active,
   *   pending, or blocked — per A-R2-N1: re-requesting while already
   *   pending must be blocked the same way a duplicate active membership
   *   is).
   * @throws \Drupal\do_group_membership\Exception\BlockedAccountException
   *   If the account's Drupal user account is blocked.
   */
  public function requestJoin(GroupInterface $group, UserInterface $account): GroupRelationshipInterface {
    return $this->createMembership($group, $account, self::STATUS_PENDING, []);
  }

  /**
   * Classifies a group's join policy from its `field_group_visibility` value.
   *
   * Thin classifier reused by the route-level access callback
   * ({@see \Drupal\do_group_membership\Controller\ManageMembersController::requestJoinAccess()})
   * and the entity-create access hook
   * ({@see \Drupal\do_group_membership\Hook\GroupAccessHook}), so both stay
   * in lockstep with a single source of truth for the composite
   * `field_group_visibility` -> join-policy mapping (per the brief's
   * "keep the composite field" decision — no two-axis split in this
   * story; that is #134 future work).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   *
   * @return string
   *   One of `'open'` (instant join, #95), `'request'` (moderated —
   *   request + organizer approval, #121), or `'invite'` (invite_only —
   *   no self-service join path, #121). Defaults to `'open'` if the field
   *   is absent/empty, so a group predating this field's introduction
   *   keeps #95's instant-join behavior rather than silently losing its
   *   join path.
   */
  public function joinPolicyFor(GroupInterface $group): string {
    if (!$group->hasField('field_group_visibility') || $group->get('field_group_visibility')->isEmpty()) {
      return 'open';
    }
    $map = [
      'open' => 'open',
      'moderated' => 'request',
      'invite_only' => 'invite',
    ];
    $visibility = (string) $group->get('field_group_visibility')->value;
    return $map[$visibility] ?? 'open';
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
   * Additively grants a group role to an existing membership, if missing.
   *
   * #144 MC-6: the creator's form-created membership already carries
   * `community_group-admin` (via the group type's `creator_roles` setting)
   * by the time `CreateGroupOrganizerHook::groupRelationshipInsert()` calls
   * this. Deliberately NOT a reuse of {@see self::changeRole()}: that
   * method does `->setValue(array_values($roles))`, a wholesale REPLACE of
   * `group_roles` that would erase the Admin role just granted, and it
   * also runs the last-Organizer guard, which is irrelevant to a purely
   * additive grant (per handoff-A.md Q1). This method instead READS the
   * existing `group_roles` values and APPENDS $role_id — never a
   * `set`/replace — and is idempotent: calling it when $role_id is already
   * present is a no-op (no `setValue()` call, no `save()`).
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The membership relationship to grant the role to.
   * @param string $role_id
   *   The group role id to ensure is present (e.g.
   *   {@see self::ORGANIZER_ROLE_ID}).
   */
  public function ensureRole(GroupRelationshipInterface $relationship, string $role_id): void {
    if ($this->hasRole($relationship, $role_id)) {
      return;
    }

    $existing_values = $relationship->hasField('group_roles')
      ? $relationship->get('group_roles')->getValue()
      : [];
    $existing_ids = array_map(
      static fn ($item) => is_array($item) ? ($item['target_id'] ?? $item) : $item,
      $existing_values,
    );
    $existing_ids[] = $role_id;

    $relationship->get('group_roles')->setValue(array_values($existing_ids));
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

  /**
   * Shared membership-creation path for both addMember() and requestJoin().
   *
   * Per A-W1: both public creation verbs delegate here so the
   * blocked-account guard, the duplicate-membership guard (spanning EVERY
   * status — active, pending, or blocked, per A-R2-N1), and the
   * `Group::addMember()` call shape stay unified in one place rather than
   * risking the two verbs drifting apart over time.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to create the membership in.
   * @param \Drupal\user\UserInterface $account
   *   The user account the membership is for.
   * @param string $status
   *   The initial `field_membership_status` value (`active` for
   *   `addMember()`, `pending` for `requestJoin()`).
   * @param string[] $roles
   *   Group role ids to assign the new membership (always empty for a
   *   pending request — AC-2 — no role until approval).
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface
   *   The saved `group_membership` relationship.
   *
   * @throws \Drupal\do_group_membership\Exception\DuplicateMembershipException
   *   If the account already has a membership (any status) in this group.
   * @throws \Drupal\do_group_membership\Exception\BlockedAccountException
   *   If the account's Drupal user account is blocked.
   */
  private function createMembership(GroupInterface $group, UserInterface $account, string $status, array $roles): GroupRelationshipInterface {
    if ($account->isBlocked()) {
      throw new BlockedAccountException('This user\'s site account is blocked.');
    }

    // Spans every status (active, pending, blocked) — A-R2-N1: a user with
    // an existing pending row must not be able to re-request (and a
    // duplicate active/blocked relationship is refused the same way
    // addMember() already refused it before this refactor).
    $existing = $group->getRelationshipsByEntity($account, 'group_membership');
    if (!empty($existing)) {
      throw new DuplicateMembershipException('This user is already a member of this group.');
    }

    $result = $group->addMember($account, [
      'group_roles' => array_values($roles),
      'field_membership_status' => [['value' => $status]],
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

}
