<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Kernel;

use Drupal\do_group_membership\Exception\BlockedAccountException;
use Drupal\do_group_membership\Exception\DuplicateMembershipException;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_group_membership\Exception\LastOrganizerGuardException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;

/**
 * AC-5, AC-8, AC-9, AC-10 — the manager service's behavior against REAL
 * `group_relationship` entities (not mocks), proving the Unit-tier contract
 * (see GroupMembershipManagerTest) is backed by genuine field-storage
 * persistence and that the last-Organizer guard (AC-9) — which needs a real
 * "count active Organizers on this group" query — actually refuses at the
 * service layer, the authoritative backstop the wireframe's Screen 6
 * describes.
 *
 * Reuses `GroupsKernelTestBase` (do_tests) rather than reinventing group-type
 * bootstrap, per T's task instructions. The `group_membership` relationship
 * (an account's membership) is looked up via
 * `GroupInterface::getRelationshipsByEntity($account, 'group_membership')` —
 * the same real, already-proven API the base class uses for node
 * relationships (`getRelationshipsByEntity($node, 'group_node:...')`) —
 * rather than a speculative/unconfirmed convenience method, since
 * `Group::getMember()` returns a `GroupMembershipInterface` wrapper, not the
 * underlying `GroupRelationshipInterface` entity this suite needs to mutate.
 *
 * @group do_group_membership
 * @group group
 */
class GroupMembershipManagerKernelTest extends GroupsKernelTestBase {

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
      'id' => 'community_group-organizer',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
    ]);
    $this->createGroupRole([
      'id' => 'community_group-member',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group_node:post entity'],
    ]);

    // Install the field_membership_status field storage + instance via the
    // API (mirrors the field.storage/field.field YAML shape pinned by
    // MembershipStatusFieldConfigShapeTest) so relationship entities in this
    // suite can actually carry a status value.
    FieldStorageConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          ['value' => 'active', 'label' => 'Active'],
          ['value' => 'pending', 'label' => 'Pending'],
          ['value' => 'blocked', 'label' => 'Blocked'],
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'bundle' => 'community_group-group_membership',
      'label' => 'Status',
    ])->save();

    $this->manager = $this->container->get('do_group_membership.manager');
  }

  /**
   * Returns the `group_relationship` entity backing an account's membership.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The member account.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface
   *   The relationship entity.
   */
  protected function membershipRelationship(GroupInterface $group, AccountInterface $account): GroupRelationshipInterface {
    $relationships = $group->getRelationshipsByEntity($account, 'group_membership');
    $relationship = reset($relationships);
    $this->assertInstanceOf(GroupRelationshipInterface::class, $relationship, 'A group_membership relationship exists for this account.');
    return $relationship;
  }

  /**
   * AC-5: pending -> active (approvePending) on a real relationship.
   */
  public function testApprovePendingTransitionsToActive(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, ['community_group-member']);
    $relationship = $this->membershipRelationship($group, $account);
    $relationship->set('field_membership_status', 'pending');
    $relationship->save();

    $this->manager->approvePending($relationship);

    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship->id());
    $this->assertSame('active', $reloaded->get('field_membership_status')->value);
  }

  /**
   * AC-5: pending -> <deleted> (denyPending) — the relationship entity is
   * REMOVED, not merely re-flagged, per [B-3]'s "deny means never joined"
   * answer (distinct from `blocked`).
   */
  public function testDenyPendingDeletesTheRelationship(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, ['community_group-member']);
    $relationship = $this->membershipRelationship($group, $account);
    $relationship_id = $relationship->id();
    $relationship->set('field_membership_status', 'pending');
    $relationship->save();

    $this->manager->denyPending($relationship);

    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship_id);
    $this->assertNull($reloaded, 'Denying a pending request deletes the relationship entity entirely.');
    $this->assertEmpty($group->getMember($account), 'The denied account is no longer a member at all (getMember() is falsy).');
  }

  /**
   * AC-5: active <-> blocked is symmetric and never deletes the entity.
   */
  public function testBlockAndUnblockAreSymmetricAndPreserveRelationship(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, ['community_group-member']);
    $relationship = $this->membershipRelationship($group, $account);
    $relationship->set('field_membership_status', 'active');
    $relationship->save();
    $relationship_id = $relationship->id();

    $this->manager->changeStatus($relationship, 'blocked');
    $blocked = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship_id);
    $this->assertNotNull($blocked, 'Blocking does not delete the relationship.');
    $this->assertSame('blocked', $blocked->get('field_membership_status')->value);

    $this->manager->changeStatus($blocked, 'active');
    $unblocked = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship_id);
    $this->assertNotNull($unblocked, 'Unblocking does not delete the relationship.');
    $this->assertSame('active', $unblocked->get('field_membership_status')->value);
  }

  /**
   * AC-5: "Change role" mutates group_roles only; field_membership_status is
   * untouched — the orthogonality [B-3] requires ("edit access flows from
   * the role, not a field").
   */
  public function testChangeRoleDoesNotTouchMembershipStatus(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, ['community_group-member']);
    $relationship = $this->membershipRelationship($group, $account);
    $relationship->set('field_membership_status', 'active');
    $relationship->save();

    $this->manager->changeRole($relationship, ['community_group-organizer']);

    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship->id());
    $this->assertSame('active', $reloaded->get('field_membership_status')->value, 'Status field is untouched by a role change.');
    $role_ids = array_column($reloaded->get('group_roles')->getValue(), 'target_id');
    $this->assertSame(['community_group-organizer'], $role_ids);
  }

  /**
   * AC-9: removeMember() on the group's LAST remaining Organizer is refused
   * — the real, end-to-end query-backed guard (the Unit suite deliberately
   * does not mock this query chain; this Kernel test is where it's proven).
   */
  public function testRemoveMemberRefusesLastOrganizer(): void {
    $group = $this->createGroup();
    $organizer = $this->createUser();
    $this->addMember($group, $organizer, ['community_group-organizer']);
    $relationship = $this->membershipRelationship($group, $organizer);
    $relationship->set('field_membership_status', 'active');
    $relationship->save();

    $this->expectException(LastOrganizerGuardException::class);
    $this->manager->removeMember($relationship);

    // The membership must still exist after the refused attempt.
    $this->assertNotEmpty($group->getMember($organizer), 'The sole Organizer is still a member after the guarded attempt.');
  }

  /**
   * AC-9: removing an Organizer is ALLOWED when a second active Organizer
   * remains — the guard is specifically "last remaining," not "any."
   */
  public function testRemoveMemberAllowedWhenAnotherOrganizerRemains(): void {
    $group = $this->createGroup();
    $organizer_a = $this->createUser();
    $organizer_b = $this->createUser();
    $this->addMember($group, $organizer_a, ['community_group-organizer']);
    $this->addMember($group, $organizer_b, ['community_group-organizer']);
    $relationship_a = $this->membershipRelationship($group, $organizer_a);
    $relationship_a->set('field_membership_status', 'active');
    $relationship_a->save();
    $this->membershipRelationship($group, $organizer_b)->set('field_membership_status', 'active')->save();

    $this->manager->removeMember($relationship_a);

    $this->assertEmpty($group->getMember($organizer_a), 'Organizer A was removed.');
    $this->assertNotEmpty($group->getMember($organizer_b), 'Organizer B (the remaining one) is untouched.');
  }

  /**
   * AC-9: demoting the group's last Organizer away from the Organizer role
   * (via changeRole) is ALSO refused — the guard applies to "remove or
   * demote," not only outright removal.
   */
  public function testChangeRoleRefusesToDemoteLastOrganizer(): void {
    $group = $this->createGroup();
    $organizer = $this->createUser();
    $this->addMember($group, $organizer, ['community_group-organizer']);
    $relationship = $this->membershipRelationship($group, $organizer);
    $relationship->set('field_membership_status', 'active');
    $relationship->save();

    $this->expectException(LastOrganizerGuardException::class);
    $this->manager->changeRole($relationship, ['community_group-member']);
  }

  /**
   * AC-10: approving an already-resolved (concurrently deleted) pending
   * relationship id is a no-op, not a fatal error — the manager's
   * NULL-relationship no-op contract (Unit-tested with a mock) exercised
   * here via a genuinely vanished entity id.
   */
  public function testApprovePendingRaceIsNoOp(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, ['community_group-member']);
    $relationship = $this->membershipRelationship($group, $account);
    $relationship_id = $relationship->id();
    $relationship->set('field_membership_status', 'pending');
    $relationship->save();

    // Simulate a second organizer's concurrent deny already having deleted
    // the relationship before this call resolves.
    $relationship->delete();

    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $vanished = $storage->load($relationship_id);
    $this->assertNull($vanished, 'Precondition: the relationship really is gone.');

    $this->assertFalse($this->manager->approvePending($vanished), 'Approving a vanished (already-resolved) request is a no-op.');
  }

  /**
   * AC-8: addMember() rejects (does not create a second relationship for) a
   * user who already has ANY membership (active, pending, or blocked) to the
   * same group.
   */
  public function testAddMemberRejectsExistingMembershipAnyStatus(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, ['community_group-member']);
    $this->membershipRelationship($group, $account)->set('field_membership_status', 'blocked')->save();

    $this->expectException(DuplicateMembershipException::class);
    $this->manager->addMember($group, $account, ['community_group-member']);
  }

  /**
   * AC-8: addMember() rejects a Drupal-blocked user account.
   */
  public function testAddMemberRejectsBlockedUserAccount(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $account->block();
    $account->save();

    $this->expectException(BlockedAccountException::class);
    $this->manager->addMember($group, $account, ['community_group-member']);
  }

}
