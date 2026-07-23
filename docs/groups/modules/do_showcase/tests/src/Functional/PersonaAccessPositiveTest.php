<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * #120 SC-1 Persona Switcher — the Groups-Moderate persona's GROUP-scoped
 * positive capability, and the Maria (Organizer) / Elena (plain Member)
 * contrast (brief.md AC: "Maria (Organizer) can edit a group / manage
 * members; plain Elena (Member) cannot", "Groups-Moderate persona can view
 * pending queue, approve, archive/restore on a group it's not a member of").
 *
 * This suite reconstructs the group roles via the group 4.x storage API
 * (`createGroupRole()`, matching this repo's own `ManageMembersAccessTest`
 * Kernel convention) using EXACTLY the AMENDED (Amendment 1) shape —
 * `admin: false` + the enumerated `edit group` / `administer members` perms —
 * rather than reading the shipped YAML. Because `do_group_membership`'s
 * access-check code (`ManageMembersController::access()`) and the group
 * permission mechanism already exist and are already correct, this suite is
 * NOT the RED anchor for Amendment 1 itself — that is
 * `Drupal\Tests\do_showcase\Unit\GroupsModerateRoleConfigShapeTest`, which
 * reads the REAL on-disk `group.role.community_group-groups_moderate.yml`
 * and fails today because that file still ships the PRE-amendment
 * `admin: true` / `permissions: {}` shape. This Functional suite is an
 * END-TO-END confirmation that the enumerated-perm shape (once F ships it)
 * satisfies the AC on the real route, and a genuine regression guard for the
 * Maria/Elena contrast, which is new to this story (the three personas
 * `maria_chen` / `elena_garcia` / `groups_moderate_demo` as concrete
 * accounts are this story's own scope). Confirmed at authoring time (see
 * handoff-T-red.md) that this suite passes already — it pins real,
 * currently-correct behavior against the SHAPE the config will have once
 * amended, not a not-yet-built code path.
 *
 * @group do_showcase
 * @group do_group_membership
 */
final class PersonaAccessPositiveTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_group_membership', 'do_showcase'];

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);

    // Reconstruct the AMENDED (Amendment 1) group-role shapes: admin:false,
    // enumerated perms — NOT the pre-amendment admin:true bypass.
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => [],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-groups_moderate',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
      'global_role' => 'groups_moderate',
    ]);
  }

  /**
   * Maria (Organizer) gets 200 on the manage-members route for a group she
   * organizes.
   */
  public function testMariaOrganizerCanAccessManageMembers(): void {
    $group = $this->createGroup(['type' => 'community_group', 'label' => 'Maria Group']);
    $maria = $this->drupalCreateUser([], 'maria_chen');
    $group->addMember($maria, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($maria);

    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Plain Elena (Member, no Organizer role) gets 403 on the manage-members
   * route.
   */
  public function testElenaPlainMemberCannotAccessManageMembers(): void {
    $group = $this->createGroup(['type' => 'community_group', 'label' => 'Elena Group']);
    $elena = $this->drupalCreateUser([], 'elena_garcia');
    $group->addMember($elena, ['group_roles' => ['community_group-member']]);
    $this->drupalLogin($elena);

    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The Groups-Moderate persona (synchronized global role, no group
   * membership at all) gets 200 on the manage-members route of a group it
   * has NEVER joined — proves the scoped `administer members` grant, not a
   * membership.
   */
  public function testGroupsModerateCanAccessManageMembersOnUnjoinedGroup(): void {
    $group = $this->createGroup(['type' => 'community_group', 'label' => 'Unjoined Group']);

    $moderator = $this->drupalCreateUser([], 'groups_moderate_demo');
    $moderator->addRole('groups_moderate');
    $moderator->save();
    $this->drupalLogin($moderator);

    // Confirm no relationship exists — access must come purely from the
    // synchronized outsider-scope group role.
    $this->assertFalse((bool) $group->getMember($moderator), 'Groups-Moderate must NOT be a member of the group it moderates.');

    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
  }

}
