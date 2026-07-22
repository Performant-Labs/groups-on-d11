<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Functional;

use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Route-collision regression coverage (Phase 8 / U-REWORK, issue #138).
 *
 * U's live UI walkthrough caught that `do_group_membership.manage_members`
 * (`/group/{group}/members`) and the pre-existing config
 * `views.view.group_members` (`page_1` display, also claiming
 * `group/%group/members`) BOTH claimed the identical path. Drupal's router
 * resolved the View for every GET request, so this story's entire
 * steady-state Manage-members surface (status badges, Approve/Deny/Unblock,
 * per-row Role/Remove buttons, the last-Organizer guard) never rendered —
 * and no test in the suite asserted route RESOLUTION (as opposed to access:
 * `ManageMembersRouteAccessTest` only proves 200-vs-403, which the shadowing
 * View also legitimately returns 200 for, so it could not have caught this).
 *
 * This test closes that gap: it asserts the response actually executes
 * `ManageMembersForm` (a unique DOM marker the View never emits) AND that
 * the OLD view's own markup (its `views-view-table` render style + its
 * "Operations"/dropbutton column, which uses the string "View member" as a
 * dropdown link label) is ABSENT — i.e. the module route, not the View,
 * owns the path. It also asserts the "Manage members" local task points at
 * the do_group_membership route, not some other destination.
 *
 * Fix (concurrent, disjoint from this file): F deleted the `page_1` display
 * from `docs/groups/config/views.view.group_members.yml` (and its
 * `config/sync/` mirror), leaving only the pathless `default` (Master)
 * display — this test does not touch that config, only asserts the
 * resulting behavior.
 *
 * @group do_group_membership
 * @group group
 */
class ManageMembersRouteResolutionTest extends BrowserTestBase {

  use GroupTestTrait;
  use BlockCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'do_group_membership', 'field', 'options', 'views', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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

    $group_type = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $group_type->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['administer members', 'view group'],
    ]);
    $this->createGroupRole([
      'group_type' => $group_type->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => [],
    ]);

    $this->group = $this->createGroup([
      'type' => $group_type->id(),
      'label' => 'Route Resolution Test Group',
      'status' => 1,
    ]);

    // The `stark` default theme carries no `page.html.twig` / block layout
    // of its own — a fresh BrowserTestBase install places zero blocks. Place
    // the core local-tasks block so `testLocalTaskNavigatesToNewRoute()` can
    // assert against a real rendered "Manage members" tab link, matching how
    // a themed site actually renders the tab (this is page-chrome test
    // setup, not a gap in the module under test).
    $this->placeBlock('local_tasks_block');
  }

  /**
   * The router resolves the shared path to the module route, not a View.
   *
   * This is the most direct, framework-level assertion of the defect class:
   * ask the actual router which route wins for the exact path, independent
   * of what gets rendered. Mirrors U's own diagnostic
   * (`router.route_provider`/`router.no_access_checks` service calls).
   */
  public function testRouterResolvesToModuleRoute(): void {
    /** @var \Drupal\Core\Routing\RouteProviderInterface $route_provider */
    $route_provider = \Drupal::service('router.route_provider');
    $path = '/group/' . $this->group->id() . '/members';

    $candidates = $route_provider->getRoutesByPattern($path)->all();
    $this->assertArrayHasKey(
      'do_group_membership.manage_members',
      $candidates,
      'The module route must be a candidate for this path.'
    );

    // The actual match a real request resolves to — this is what a browser
    // hitting the URL actually gets served, not merely a listed candidate.
    $request = Request::create($path);
    $matched = \Drupal::service('router.no_access_checks')->matchRequest($request);
    $this->assertSame(
      'do_group_membership.manage_members',
      $matched['_route'],
      'The router must resolve GET ' . $path . ' to the new Manage-members route, not a same-path View display.'
    );
  }

  /**
   * The served page is the new ManageMembersForm, not the old View.
   *
   * Asserted via a marker the shadowing View can never emit (the module's
   * own CSS class on the table, the "+ Add member" primary-action link, and
   * a real `<form>` element — the View's page display is a controller
   * render with no `<form>` at all) AND the absence of the old View's own
   * markers (its `views-view-table` style class, its `view-group-members`
   * wrapper, and its "View member" dropdown link text).
   */
  public function testPageServesNewFormNotOldView(): void {
    $organizer = $this->drupalCreateUser();
    $this->group->addMember($organizer, [
      'group_roles' => ['community_group-organizer'],
    ]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);

    // Unique markers of the NEW Manage-members UI.
    $this->assertSession()->elementExists('css', 'form table.do-group-membership__table');
    $this->assertSession()->elementExists('css', 'table.do-group-membership__table');
    $this->assertSession()->linkExists('+ Add member');

    // Markers of the OLD `views.view.group_members` page display, which
    // must be ABSENT — proving the View no longer owns this path.
    $page = $this->getSession()->getPage();
    $this->assertStringNotContainsString(
      'views-view-table',
      $page->getContent(),
      'The old group_members View table style must not render on this path.'
    );
    $this->assertSession()->elementNotExists('css', '.view-group-members, .views-element-container');
    $this->assertSession()->linkNotExists('View member');
  }

  /**
   * The "Manage members" local task tab navigates to the new form.
   *
   * Mirrors U's own navigated-path check (as opposed to a direct GET),
   * since that is how the defect actually manifested live: the tab itself
   * resolved to the shadowed View.
   */
  public function testLocalTaskNavigatesToNewRoute(): void {
    $organizer = $this->drupalCreateUser();
    $this->group->addMember($organizer, [
      'group_roles' => ['community_group-organizer'],
    ]);
    $this->drupalLogin($organizer);

    $this->drupalGet($this->group->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/group/' . $this->group->id() . '/members');
    $this->assertSession()->linkExists('Manage members');

    // Following the tab must land on the SAME new-form surface, not a
    // silently-different destination.
    $this->clickLink('Manage members');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'table.do-group-membership__table');
  }

}
