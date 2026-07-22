<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Functional;

use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\RoleInterface;

/**
 * AC-6, AC-11 — the Manage-members route on a real HTTP request/response,
 * mirroring `do_tests/tests/src/Functional/GroupAccessEnforcementTest.php`'s
 * enforcement-on-the-wire pattern (a Kernel test proves the *calculated*
 * permission; this Functional test proves the route is actually *gated* end
 * to end).
 *
 * Route: `do_group_membership.manage_members` at `/group/{group}/members`
 * (brief [B-2]). Access: `$group->hasPermission('administer members',
 * $account)` OR `$account->hasPermission('administer group')`.
 *
 * @group do_group_membership
 * @group group
 */
class ManageMembersRouteAccessTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'do_group_membership'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The community_group-shaped group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * The group under test.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['administer members'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => [],
    ]);

    $this->group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Access Test Group',
      'status' => 1,
    ]);
  }

  /**
   * AC-6: an Organizer gets 200 on the manage-members route for their group.
   */
  public function testOrganizerCanAccessManageMembers(): void {
    $organizer = $this->drupalCreateUser();
    $this->group->addMember($organizer, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * AC-11: a plain Member gets 403 on the manage-members route.
   */
  public function testPlainMemberGetsAccessDenied(): void {
    $member = $this->drupalCreateUser();
    $this->group->addMember($member, ['group_roles' => ['community_group-member']]);
    $this->drupalLogin($member);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-6: the site-admin escape hatch (`administer group` site permission)
   * grants access even without any group-specific role, mirroring the
   * RUNBOOK's existing `/admin/groups/pending` precedent cited in [B-2].
   */
  public function testSiteAdminEscapeHatchGrantsAccess(): void {
    $admin = $this->drupalCreateUser(['administer group']);
    $this->drupalLogin($admin);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * AC-11: an authenticated user with NEITHER a group role NOR the site
   * escape hatch gets 403 — the baseline negative case (not even logged out,
   * just insufficiently privileged).
   */
  public function testUnprivilegedAuthenticatedUserGetsAccessDenied(): void {
    $this->createRole([], RoleInterface::AUTHENTICATED_ID);
    $outsider = $this->drupalCreateUser();
    $this->drupalLogin($outsider);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403);
  }

}
