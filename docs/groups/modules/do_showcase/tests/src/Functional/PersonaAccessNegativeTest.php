<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * #120 SC-1 Persona Switcher — the Groups-Moderate persona's negative scope
 * boundary: "cannot reach /admin/config, /admin/people, or module pages"
 * (brief.md AC; brief-amendments.md Amendment 1's scoped `group.role.
 * community_group-groups_moderate.yml` grants ONLY `administer members` +
 * `edit group` at the GROUP-scoped level, with `admin: false` — no site-wide
 * admin surface is reachable; `user.role.groups_moderate.yml` gets ONLY the
 * minimal `access content` at the site level).
 *
 * This test creates the `groups_moderate` role PROGRAMMATICALLY with EXACTLY
 * the target permission set Amendment 1 specifies (`access content` only) —
 * it does not read the shipped YAML (BrowserTestBase's `do_showcase` module
 * dependency does not pull in `docs/groups/config/user.role.groups_moderate.
 * yml` automatically). This is deliberate: it pins the REAL Drupal core
 * permission-check behavior against the amended role's intended final shape,
 * so it is a genuine regression guard against a future over-grant (e.g. if
 * `user.role.groups_moderate.yml` ever accidentally gained `access
 * administration pages` or a config module permission, this test would
 * catch it), not a vacuous assertion about an undefined role.
 *
 * Distinguish from PersonaAccessPositiveTest, which pins the GROUP-scoped
 * positive capability (pending queue / restore) on `group.role.
 * community_group-groups_moderate.yml`.
 *
 * @group do_showcase
 */
final class PersonaAccessNegativeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The user under test: `groups_moderate` role with EXACTLY the amended
   * target permission set (`access content` — Amendment 1).
   *
   * @var \Drupal\user\UserInterface
   */
  private $moderator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $role = Role::create(['id' => 'groups_moderate', 'label' => 'Groups Moderate']);
    $role->grantPermission('access content');
    $role->save();

    $this->moderator = $this->drupalCreateUser([], 'groups_moderate_demo');
    $this->moderator->addRole('groups_moderate');
    $this->moderator->save();
    $this->drupalLogin($this->moderator);
  }

  /**
   * A user holding only the `groups_moderate` global role (site-level:
   * `access content` ONLY) cannot reach `/admin/config`.
   */
  public function testGroupsModerateCannotReachAdminConfig(): void {
    $this->drupalGet('/admin/config');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A user holding only the `groups_moderate` global role cannot reach
   * `/admin/people`.
   */
  public function testGroupsModerateCannotReachAdminPeople(): void {
    $this->drupalGet('/admin/people');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A user holding only the `groups_moderate` global role cannot reach
   * `/admin/modules`.
   */
  public function testGroupsModerateCannotReachAdminModules(): void {
    $this->drupalGet('/admin/modules');
    $this->assertSession()->statusCodeEquals(403);
  }

}
