<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Kernel;

use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;

/**
 * AC-6, AC-11, AC-12 (+ Handoff-A warn-2): the Manage-members route's access
 * gate, expressed at the `$group->hasPermission()` enforcement layer the
 * `_custom_access` callback is specified (brief [B-2]) to call.
 *
 * Access rule locked by the brief: TRUE if
 * `$group->hasPermission('administer members', $account)` (covers Organizer,
 * Moderator, and any Groups-Moderate synchronized-role user) OR
 * `$account->hasPermission('administer group')` (site-admin escape hatch).
 * This suite pins the FIRST disjunct's three positive cases and the ONE
 * negative case; the site-admin OR-branch is a bare `AccountInterface::
 * hasPermission()` call with no group-specific logic, so it is not
 * re-asserted here (it would just be re-testing Drupal core's own role
 * permission check).
 *
 * @group do_group_membership
 * @group group
 */
class ManageMembersAccessTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_group_membership'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createRole([], RoleInterface::AUTHENTICATED_ID);

    // Install the config-shipped organizer/moderator/groups_moderate group
    // roles via the storage API, mirroring GroupsKernelTestBase's own
    // "reconstruct via 4.x storage APIs, not by reading YAML" convention —
    // these are the exact permission sets locked by AC-1/AC-2/AC-13.
    $this->createGroupRole([
      'id' => 'community_group-organizer',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
    ]);
    $this->createGroupRole([
      'id' => 'community_group-moderator',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['administer members'],
    ]);
    $this->createGroupRole([
      'id' => 'community_group-groups_moderate',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'admin' => TRUE,
      'global_role' => 'groups_moderate',
    ]);
  }

  /**
   * AC-6: an Organizer member holds `administer members` on their own group.
   */
  public function testOrganizerHasAdministerMembers(): void {
    $group = $this->createGroup();
    $organizer = $this->createUser();
    $this->addMember($group, $organizer, ['community_group-organizer']);

    $this->assertTrue($group->hasPermission('administer members', $organizer), 'Organizer can manage members of their own group.');
  }

  /**
   * AC-6: a Moderator member holds `administer members` on their own group.
   */
  public function testModeratorHasAdministerMembers(): void {
    $group = $this->createGroup();
    $moderator = $this->createUser();
    $this->addMember($group, $moderator, ['community_group-moderator']);

    $this->assertTrue($group->hasPermission('administer members', $moderator), 'Moderator can manage members of their own group.');
  }

  /**
   * AC-11: a plain Member (no Organizer/Moderator/Groups-Moderate) does NOT
   * hold `administer members` — the negative case backing the route's
   * access-denied requirement.
   */
  public function testPlainMemberLacksAdministerMembers(): void {
    $this->createGroupRole([
      'id' => 'community_group-member',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group_node:post entity'],
    ]);

    $group = $this->createGroup();
    $member = $this->createUser();
    $this->addMember($group, $member, ['community_group-member']);

    $this->assertFalse($group->hasPermission('administer members', $member), 'A plain Member cannot manage members.');
  }

  /**
   * AC-12 + Handoff-A warn-2 (the residual synchronized-global-role
   * assumption A flagged for empirical closure at GREEN): a user holding the
   * SITE-LEVEL `groups_moderate` role — via
   * group.role.community_group-groups_moderate.yml's `scope: insider` +
   * `global_role: groups_moderate` + `admin: true` — holds
   * `administer members` on a `community_group` group they have NEVER
   * joined (no `group_relationship` entity exists for them at all).
   *
   * This is the exact mechanism the brief's Round-1 [B-5] correction
   * describes and A's Finding #2 asked to be closed empirically: proving the
   * synchronized-role config entity alone (no per-group membership) grants
   * the bypass.
   */
  public function testGroupsModerateUserManagesGroupTheyNeverJoined(): void {
    $this->createRole([], 'groups_moderate');
    $moderator_account = $this->createUser();
    $moderator_account->addRole('groups_moderate');
    $moderator_account->save();

    $group = $this->createGroup();

    // Confirm no relationship exists for this account on this group — the
    // access must come purely from the synchronized global role.
    $relationships = $group->getMember($moderator_account);
    $this->assertFalse((bool) $relationships, 'The Groups-Moderate account is NOT a member of this group (no relationship entity).');

    $this->assertTrue(
      $group->hasPermission('administer members', $moderator_account),
      'A synchronized groups_moderate global role grants administer members on a group never joined.'
    );
  }

}
