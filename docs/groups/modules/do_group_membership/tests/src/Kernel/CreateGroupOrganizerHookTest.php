<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Kernel;

use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\Core\Session\AccountInterface;

/**
 * #144 MC-6 — RED (Phase 4, authored by T before F implements).
 *
 * Pins TWO things against real `group_relationship` entities (not mocks),
 * mirroring `GroupMembershipManagerKernelTest`'s established pattern:
 *
 * 1. `GroupMembershipManager::ensureRole()`'s real, field-storage-backed
 *    additive behavior (AC-1's kernel half) — a membership that already
 *    carries `community_group-admin` (simulating `creator_roles`) ends up
 *    with BOTH admin and organizer after `ensureRole()`.
 * 2. The NEW `CreateGroupOrganizerHook::groupRelationshipInsert()` method
 *    (class does not exist yet at RED time) — modeled on the shipped
 *    `DoNotificationsHooks::groupRelationshipInsert()` precedent
 *    (`group_relationship_insert`, exact signature match). Per the brief's
 *    reuse map: fires AFTER Group 4.x's own form-save has created the
 *    creator's membership (with `community_group-admin` already set via
 *    `creator_roles`), filters to `group_membership` relationships whose
 *    member-uid equals the group's owner id, then calls
 *    `GroupMembershipManager::ensureRole()` to ADD Organizer.
 *
 * IMPORTANT (per brief's "IMPORTANT — creator_wizard: true" section): Kernel
 * tests in this suite cannot submit the real MULTI-STEP wizard form
 * (`creator_wizard: true` on the assembled `community_group` type turns
 * `/group/add/community_group` into a multi-step wizard — no form-submission
 * API exists at the Kernel tier). This suite therefore does NOT attempt to
 * drive the wizard; instead, per T's task instructions, it directly invokes
 * entity creation with the SAME field shape `CreateFormEnhancer` would
 * produce (a group_membership relationship created with
 * `group_roles: [community_group-admin]` already set, simulating "Group's
 * form-save already ran") and asserts the hook's OWN logic in isolation:
 * that inserting such a relationship triggers the Organizer grant, and that
 * a DIFFERENT (non-owner) user's membership inserted in the SAME test run
 * does NOT receive it (AC-8).
 *
 * The REAL wizard-driven end-to-end path (multi-step form submission through
 * to the final save/redirect) is proven by the FUNCTIONAL test
 * `CreateGroupWizardOrganizerTest` (BrowserTestBase, real request stack) —
 * see that file's docblock for the explicit division of labor between the
 * two tiers.
 *
 * @group do_group_membership
 * @group group
 */
class CreateGroupOrganizerHookTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_group_membership'];

  /**
   * The manager service under test.
   *
   * @var \Drupal\do_group_membership\GroupMembershipManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createGroupRole([
      'id' => 'community_group-admin',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => TRUE,
      'permissions' => [],
    ]);
    $this->createGroupRole([
      'id' => 'community_group-organizer',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
    ]);

    $this->manager = $this->container->get('do_group_membership.manager');
  }

  /**
   * Returns the `group_membership` relationship backing an account's
   * membership in a group.
   */
  protected function membershipRelationship(GroupInterface $group, AccountInterface $account): ?GroupRelationshipInterface {
    $relationships = $group->getRelationshipsByEntity($account, 'group_membership');
    $relationship = reset($relationships);
    return $relationship instanceof GroupRelationshipInterface ? $relationship : NULL;
  }

  /**
   * AC-1 (kernel half of ensureRole()'s real-entity contract): a membership
   * that already carries `community_group-admin` (the `creator_roles` grant)
   * ends up with BOTH admin AND organizer after `ensureRole()` — additive,
   * not a replace, proven against real field storage (not mocks).
   */
  public function testEnsureRoleIsAdditiveOnRealRelationship(): void {
    $group = $this->createGroup();
    $creator = $this->createUser();
    $this->addMember($group, $creator, ['community_group-admin']);
    $relationship = $this->membershipRelationship($group, $creator);
    $this->assertNotNull($relationship, 'Precondition: the admin membership exists.');

    $this->manager->ensureRole($relationship, 'community_group-organizer');

    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship->id());
    $role_ids = array_column($reloaded->get('group_roles')->getValue(), 'target_id');
    $this->assertContains('community_group-admin', $role_ids, 'The pre-existing Admin role is preserved (additive, not replaced).');
    $this->assertContains('community_group-organizer', $role_ids, 'The Organizer role has been added.');
    $this->assertCount(2, $role_ids, 'Exactly two roles: no duplication, no loss.');
  }

  /**
   * Idempotency (real-entity half): calling `ensureRole()` a second time
   * when the role is already present does not duplicate the role value.
   */
  public function testEnsureRoleIsIdempotentOnRealRelationship(): void {
    $group = $this->createGroup();
    $creator = $this->createUser();
    $this->addMember($group, $creator, ['community_group-admin']);
    $relationship = $this->membershipRelationship($group, $creator);

    $this->manager->ensureRole($relationship, 'community_group-organizer');
    // Second call: role already present, must be a no-op (no duplication).
    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship->id());
    $this->manager->ensureRole($reloaded, 'community_group-organizer');

    $final = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship->id());
    $role_ids = array_column($final->get('group_roles')->getValue(), 'target_id');
    $this->assertCount(2, $role_ids, 'No duplicate Organizer role value after a second, idempotent call.');
    $this->assertCount(1, array_keys($role_ids, 'community_group-organizer', TRUE), 'Organizer appears exactly once.');
  }

  /**
   * AC-1 (insert-hook half): creating a `group_membership` relationship with
   * the SAME field shape `CreateFormEnhancer` produces for a creator (i.e.
   * `group_roles: [community_group-admin]` already set at creation time,
   * simulating "Group's own form-save already ran and applied
   * `creator_roles`") — the NEW `CreateGroupOrganizerHook::
   * groupRelationshipInsert()` (`#[Hook('group_relationship_insert')]`) must
   * fire on insert and ADD the Organizer role, so the creator's membership
   * ends up with BOTH `community_group-admin` AND `community_group-organizer`.
   *
   * This directly invokes the storage `create()`+`save()` path (not
   * `Group::addMember()`, whose values shape differs slightly) so the
   * relationship's owner-uid equals the group's owner id, matching the real
   * creator scenario the hook's filter checks for.
   */
  public function testInsertHookGrantsOrganizerToCreatorMembership(): void {
    $creator = $this->createUser();
    $group = $this->createGroup(['uid' => $creator->id()]);

    $relationship_type_id = $this->entityTypeManager
      ->getStorage('group_relationship_type')
      ->getRelationshipTypeId(static::GROUP_TYPE_ID, 'group_membership');

    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $relationship = $storage->create([
      'type' => $relationship_type_id,
      'gid' => $group->id(),
      'entity_id' => $creator->id(),
      // The exact initial shape CreateFormEnhancer::enhanceGroupForm()
      // applies from GroupType::getCreatorRoleIds() (brief's survey.md §5).
      'group_roles' => ['community_group-admin'],
    ]);
    $relationship->save();

    $reloaded = $storage->loadUnchanged($relationship->id());
    $this->assertNotNull($reloaded, 'The relationship was saved.');
    $role_ids = array_column($reloaded->get('group_roles')->getValue(), 'target_id');
    $this->assertContains('community_group-admin', $role_ids, 'Admin (creator_roles) is preserved.');
    $this->assertContains(
      'community_group-organizer',
      $role_ids,
      'CreateGroupOrganizerHook::groupRelationshipInsert() must add Organizer to the CREATOR (owner-uid-matching) membership on insert.',
    );
  }

  /**
   * AC-8 (handoff-A.md finding #2): in the SAME test run/request, a
   * DIFFERENT (non-owner) user's `group_membership` relationship added to
   * the SAME group must NOT receive the Organizer role — guards the
   * insert-hook's owner-equality filter's precision (it must not misfire on
   * a batch-added member created in the same request as the creator's own
   * membership).
   */
  public function testInsertHookDoesNotGrantOrganizerToNonOwnerMembership(): void {
    $creator = $this->createUser();
    $group = $this->createGroup(['uid' => $creator->id()]);
    $other_member = $this->createUser();

    $relationship_type_id = $this->entityTypeManager
      ->getStorage('group_relationship_type')
      ->getRelationshipTypeId(static::GROUP_TYPE_ID, 'group_membership');
    $storage = $this->entityTypeManager->getStorage('group_relationship');

    // The creator's own membership (owner-uid matches) — should get Organizer.
    $creator_relationship = $storage->create([
      'type' => $relationship_type_id,
      'gid' => $group->id(),
      'entity_id' => $creator->id(),
      'group_roles' => ['community_group-admin'],
    ]);
    $creator_relationship->save();

    // A DIFFERENT user's membership created in the same request — owner-uid
    // does NOT match this relationship's member — must NOT get Organizer.
    $other_relationship = $storage->create([
      'type' => $relationship_type_id,
      'gid' => $group->id(),
      'entity_id' => $other_member->id(),
      'group_roles' => [],
    ]);
    $other_relationship->save();

    $reloaded_creator = $storage->loadUnchanged($creator_relationship->id());
    $creator_role_ids = array_column($reloaded_creator->get('group_roles')->getValue(), 'target_id');
    $this->assertContains('community_group-organizer', $creator_role_ids, 'The creator (owner) membership DOES receive Organizer.');

    $reloaded_other = $storage->loadUnchanged($other_relationship->id());
    $other_role_ids = array_column($reloaded_other->get('group_roles')->getValue(), 'target_id');
    $this->assertNotContains(
      'community_group-organizer',
      $other_role_ids,
      'AC-8: a non-owner membership created in the same request must NOT receive the Organizer role — guards the owner-equality filter precision.',
    );
  }

}
