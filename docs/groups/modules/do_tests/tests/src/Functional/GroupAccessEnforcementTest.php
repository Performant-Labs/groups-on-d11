<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Functional;

use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\RoleInterface;

/**
 * RA1 (B1 / #35): access-policy enforcement on a real HTTP request.
 *
 * The kernel test proves the *calculated* permission sets differ per scope;
 * this functional test proves the access policy is actually *enforced* end to
 * end: an unprivileged non-member is refused (403) and a member is allowed
 * (200) on the very same permission-gated group surface — the canonical group
 * view route (`/group/{group}`), which the group access control handler gates
 * on the `view group` permission
 * (see \Drupal\group\Entity\Access\GroupAccessControlHandler::checkAccess()).
 *
 * Only an INSIDER-scope role carries `view group`; there is deliberately no
 * OUTSIDER-scope `view group` grant, so membership is the sole difference
 * between the 403 and the 200. This closes the outstanding functional item
 * from #6.
 *
 * @group do_tests
 * @group group
 */
class GroupAccessEnforcementTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A community_group-shaped group type.
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

    // The synchronized access policy matches an account's *global* roles; the
    // authenticated role already exists in a functional install, so the insider
    // role (below, keyed to it) applies to any logged-in member.
    //
    // A minimal community_group-shaped group type with an INSIDER role that
    // carries `view group`. There is deliberately no OUTSIDER role granting
    // `view group`, so a non-member cannot view the group — membership is the
    // sole difference between the 403 and the 200 below.
    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['view group'],
    ]);

    // Published, so `view group` (not `view any unpublished group`) gates it.
    $this->group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Enforcement Test Group',
      'status' => 1,
    ]);
  }

  /**
   * A non-member is refused (403) and a member is allowed (200) on the group.
   */
  public function testMemberSeesGroupNonMemberIsForbidden(): void {
    $url = $this->group->toUrl()->toString();

    // Non-member (authenticated, but not in the group) is forbidden.
    $non_member = $this->drupalCreateUser();
    $this->drupalLogin($non_member);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Member of the group is allowed — same URL, same permission surface.
    $member = $this->drupalCreateUser();
    $this->group->addMember($member);
    $this->drupalLogin($member);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    // The canonical route was actually served (not a redirect to login/403),
    // and the group's own label is present in the rendered response — proving
    // the member reached the gated group page, not merely any 200.
    $this->assertSession()->addressEquals($url);
    $this->assertSession()->responseContains($this->group->label());
  }

}
