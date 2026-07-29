<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * Tests the "My Groups" page: listing, role badges, and the created filter.
 *
 * Covers `/my-groups`'s listing, its per-card role badges, and the
 * `?created=1` "only groups I created" filter.
 *
 * Mirrors `ManageMembersPageRenderTest`'s BrowserTestBase +
 * GroupTestTrait pattern (real HTTP request/response, real rendered
 * markup — no mocking of the group entity view builder).
 *
 * The controller's zero-groups empty state links to `view.all_groups.page_1`
 * (`Url::fromRoute()`), so that route must exist for ANY request that can
 * reach either empty-state branch — not just the "browse the directory" one.
 * `views.view.all_groups` is installed from a MODULE-LOCAL fixture
 * (`tests/fixtures/config/views.view.all_groups.yml`, copied from
 * do_showcase's own fixture of the same real site view) via the
 * FileStorage + `getStorage('view')->create()->save()` pattern this
 * project's test suites already use (see e.g.
 * `do_group_extras/tests/src/Functional/AllGroupsMembershipCacheInvalidationTest.php`)
 * — never a source-relative `docs/groups/config` path.
 *
 * @group do_group_membership
 * @group group
 */
class MyGroupsControllerTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'do_group_membership', 'field', 'options', 'views'];

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
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['administer members', 'view group'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group'],
    ]);

    // The controller's empty state links to view.all_groups.page_1 — see
    // class docblock. Installed from a module-local fixture, then the
    // router is rebuilt so the newly-saved view's route is resolvable
    // within this same test run.
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $data = $fixtures->read('views.view.all_groups');
    $this->assertNotFalse($data, 'Fixture views.view.all_groups exists and is readable.');
    \Drupal::entityTypeManager()->getStorage('view')->create($data)->save();
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Tests that zero memberships shows the "not joined any groups" state.
   *
   * Not the filter-specific empty state.
   */
  public function testNoMembershipsShowsJoinEmptyState(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/my-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('You have not joined any groups yet');
    $this->assertSession()->elementNotExists('css', '.do-my-groups__card');
  }

  /**
   * Tests that the role badge reflects live membership role, not ownership.
   *
   * The badge reads "Organizer" for a group where the visitor's LIVE
   * membership carries the organizer role, and "Member" for a group where
   * it does not — even though the visitor owns neither group. Proves the
   * badge is derived from the membership's role, not group ownership.
   */
  public function testRoleBadgeReflectsLiveMembershipRoleNotOwnership(): void {
    $owner = $this->drupalCreateUser();
    $account = $this->drupalCreateUser();

    $organizer_group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Organizer Group',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $organizer_group->addMember($account, ['group_roles' => ['community_group-organizer']]);

    $member_group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Member Group',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $member_group->addMember($account, ['group_roles' => ['community_group-member']]);

    $this->drupalLogin($account);
    $this->drupalGet('/my-groups');
    $this->assertSession()->statusCodeEquals(200);

    $badges = $this->getSession()->getPage()->findAll('css', '.do-my-groups__role-badge');
    $this->assertCount(2, $badges);
    $texts = array_map(static fn ($badge) => trim($badge->getText()), $badges);
    sort($texts);
    $this->assertSame(['Member', 'Organizer'], $texts, 'The two cards show distinct role badges reflecting each membership\'s own role.');
  }

  /**
   * Tests that a member promoted to Organizer after joining shows Organizer.
   *
   * The badge reads the CURRENT role, not a role captured at join time.
   */
  public function testRoleBadgeReflectsPromotionAfterJoining(): void {
    $account = $this->drupalCreateUser();
    $group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Promotion Group',
      'status' => 1,
    ]);
    // Group::addMember() returns void (verified against the installed
    // group/src/Entity/Group.php: it calls addRelationship() but returns
    // nothing) — the membership must be re-fetched via getMember() to get
    // an object that can be updated.
    $group->addMember($account, ['group_roles' => ['community_group-member']]);

    $this->drupalLogin($account);
    $this->drupalGet('/my-groups');
    $this->assertSession()->pageTextContains('Member');
    $this->assertSession()->pageTextNotContains('Organizer');

    // Promote after joining. GroupMembership is a shared bundle class for
    // the group_relationship entity itself (extends GroupRelationship), so
    // its own set()/save() persist the role change directly.
    $membership = $group->getMember($account);
    $membership->set('group_roles', ['community_group-organizer'])->save();

    $this->drupalGet('/my-groups');
    $this->assertSession()->pageTextContains('Organizer');
  }

  /**
   * Tests that `?created=1` narrows the listing to groups the visitor OWNS.
   *
   * Leaves out groups they merely belong to.
   */
  public function testCreatedOnlyFilterShowsOnlyOwnedGroups(): void {
    $account = $this->drupalCreateUser();

    $owned_group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Owned Group',
      'uid' => $account->id(),
      'status' => 1,
    ]);
    $owned_group->addMember($account, ['group_roles' => ['community_group-organizer']]);

    $other_owner = $this->drupalCreateUser();
    $joined_group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Joined Group',
      'uid' => $other_owner->id(),
      'status' => 1,
    ]);
    $joined_group->addMember($account, ['group_roles' => ['community_group-member']]);

    $this->drupalLogin($account);

    $this->drupalGet('/my-groups');
    $this->assertSession()->elementsCount('css', '.do-my-groups__card', 2);

    $this->drupalGet('/my-groups', ['query' => ['created' => '1']]);
    $this->assertSession()->elementsCount('css', '.do-my-groups__card', 1);
    $this->assertSession()->pageTextContains('Owned Group');
    $this->assertSession()->pageTextNotContains('Joined Group');
  }

  /**
   * Tests the filter-specific empty state for zero owned groups.
   *
   * `?created=1` matching zero groups (the visitor belongs to groups, just
   * none they own) shows distinct copy from the "you have no memberships
   * at all" case.
   */
  public function testCreatedOnlyFilterEmptyStateIsDistinctFromNoMembershipsState(): void {
    $account = $this->drupalCreateUser();
    $other_owner = $this->drupalCreateUser();

    $joined_group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Joined Only Group',
      'uid' => $other_owner->id(),
      'status' => 1,
    ]);
    $joined_group->addMember($account, ['group_roles' => ['community_group-member']]);

    $this->drupalLogin($account);
    $this->drupalGet('/my-groups', ['query' => ['created' => '1']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains("You haven't created any groups yet");
    $this->assertSession()->pageTextContains('Clear the filter');
    $this->assertSession()->elementNotExists('css', '.do-my-groups__card');
  }

  /**
   * Tests that the filter control is a plain no-JS-safe link.
   *
   * Its `href`/`aria-checked` reflect whether the filter is currently
   * active.
   */
  public function testFilterControlIsNoJsLinkWithCorrectState(): void {
    $account = $this->drupalCreateUser();
    $group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Any Group',
      'uid' => $account->id(),
      'status' => 1,
    ]);
    $group->addMember($account, ['group_roles' => ['community_group-organizer']]);

    $this->drupalLogin($account);

    $this->drupalGet('/my-groups');
    $toggle = $this->assertSession()->elementExists('css', '.do-my-groups__filter-toggle');
    $this->assertSame('a', $toggle->getTagName(), 'The filter control is a plain link, not a JS-only control.');
    $this->assertStringContainsString('created=1', (string) $toggle->getAttribute('href'));
    $this->assertSame('false', $toggle->getAttribute('aria-checked'));

    $this->drupalGet('/my-groups', ['query' => ['created' => '1']]);
    $toggle = $this->assertSession()->elementExists('css', '.do-my-groups__filter-toggle');
    $this->assertSame('true', $toggle->getAttribute('aria-checked'));
    $this->assertStringContainsString('do-my-groups__filter-toggle--active', (string) $toggle->getAttribute('class'));
  }

}
