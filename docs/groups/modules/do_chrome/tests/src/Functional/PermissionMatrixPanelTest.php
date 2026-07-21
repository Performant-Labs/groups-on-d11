<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Functional;

use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\RoleInterface;

/**
 * Functional coverage for the #91 (CH-B4) permission-matrix panel.
 *
 * The Unit test (\Drupal\Tests\do_chrome\Unit\PermissionMatrixTest) pins the
 * matrix DEFINITION; this test proves the panel actually RENDERS on a real HTTP
 * request to the group canonical page — heading, the four actor columns, and a
 * few representative enforced cells.
 *
 * BrowserTestBase installs a FRESH, minimal Drupal per test class with only the
 * modules declared in $modules — it does NOT carry the demo's self-seed (no
 * seeded groups/roles/members, no deploy-time community_group config). So this
 * test SELF-PROVISIONS its fixtures in setUp(): a community_group-shaped group
 * type, an insider role granting `view group` (so a member can reach the gated
 * page), a published group, and a membership. It asserts only what it builds —
 * the panel markup — not the demo's full scoped-role behaviour (that is covered
 * by the Unit test + the E2E path against the seeded image).
 *
 * @group do_chrome
 * @group group
 */
class PermissionMatrixPanelTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'do_chrome'];

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

    // A minimal community_group-shaped group type. The panel injects on any
    // group's full view when do_chrome is enabled; the group type id is not
    // load-bearing for rendering, but we mirror the demo's id for clarity.
    $group_type = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);

    // An INSIDER role carrying `view group`, keyed to the (already-present)
    // authenticated global role, so a logged-in member can reach the canonical
    // group page (its access handler gates on `view group`). This mirrors the
    // demo's insider_view grant; it is the minimum needed to render the page.
    $this->createGroupRole([
      'group_type' => $group_type->id(),
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['view group'],
    ]);

    // Published, so plain `view group` gates it.
    $this->group = $this->createGroup([
      'type' => $group_type->id(),
      'label' => 'Matrix Panel Test Group',
      'status' => 1,
    ]);
  }

  /**
   * The panel renders on the group page with the correct actors and cells.
   */
  public function testPermissionMatrixPanelRenders(): void {
    // A member (self-provisioned) reaches the gated group page.
    $member = $this->drupalCreateUser();
    $this->group->addMember($member);
    $this->drupalLogin($member);

    $this->drupalGet($this->group->toUrl()->toString());
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    // Panel container + heading.
    $assert->elementExists('css', '.do-chrome-perm-matrix');
    $assert->pageTextContains('Who can do what');

    // The four actor columns, in order.
    $assert->elementExists('css', '.do-chrome-perm-matrix__actor[data-actor="anonymous"]');
    $assert->elementExists('css', '.do-chrome-perm-matrix__actor[data-actor="outsider"]');
    $assert->elementExists('css', '.do-chrome-perm-matrix__actor[data-actor="member"]');
    $assert->elementExists('css', '.do-chrome-perm-matrix__actor[data-actor="admin"]');

    // Every action row is present.
    foreach ([
      'View the group',
      'View group content',
      'Join the group',
      'Leave the group',
      'Post content',
      'Edit or remove own content',
      'Invite & manage members',
    ] as $action) {
      $assert->pageTextContains($action);
    }

    // 7 action rows x 4 actors = 28 cells, matching the pinned definition.
    $cells = $this->getSession()->getPage()->findAll('css', '.do-chrome-perm-matrix__cell');
    $this->assertCount(28, $cells, 'The matrix renders one cell per action/actor.');

    // A representative enforced cell: "yes" cells exist (e.g. everyone views the
    // group) and "no" cells exist (e.g. anonymous cannot manage members), so the
    // grid reflects differentiated, not uniform, states.
    $assert->elementExists('css', '.do-chrome-perm-matrix__cell--yes');
    $assert->elementExists('css', '.do-chrome-perm-matrix__cell--no');
    $assert->elementExists('css', '.do-chrome-perm-matrix__cell--n-a');

    // The intro carries the honest footnote as a tooltip trigger, and the
    // standalone footnote is present.
    $assert->elementExists('css', '.do-chrome-perm-matrix__intro[data-do-tooltip]');
    $assert->elementExists('css', '.do-chrome-perm-matrix__footnote');
  }

}
