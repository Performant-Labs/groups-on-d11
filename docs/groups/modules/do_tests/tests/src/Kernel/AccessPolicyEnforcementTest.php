<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\Core\Session\CalculatedPermissionsInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\PermissionScopeInterface;
use Drupal\user\RoleInterface;

/**
 * RA1 (B1 / #35): access-policy permission enforcement, calculated layer.
 *
 * The flexible-permissions → core Access Policy migration is the central Group
 * v3→v4 behavior change and, per epic #31, had zero behavioral coverage. This
 * kernel test asserts the *calculated* permission set (what the access-policy
 * pipeline resolves) differs correctly across the three scopes on a real
 * `community_group`:
 *
 * - an **outsider** (non-member) resolves only outsider-scope permissions;
 * - an **insider** (plain member) resolves insider-scope permissions and does
 *   *not* inherit the outsider set;
 * - an **individual** grant on a specific membership adds a permission on that
 *   one group that neither a plain outsider nor a plain insider holds.
 *
 * It exercises the real resolution path in two complementary ways:
 * 1. {@see \Drupal\group\Entity\GroupInterface::hasPermission()} →
 *    `group_permission.checker` → `group_permission.calculator`, the
 *    enforcement entry point the access control handlers use; and
 * 2. the raw `access_policy_processor->processAccessPolicies()`, asserting each
 *    of the two tagged services actually contributes items:
 *    `SynchronizedGroupRoleAccessPolicy` (priority -50) feeds the OUTSIDER and
 *    INSIDER scopes, `IndividualGroupRoleAccessPolicy` (priority -100) feeds
 *    the INDIVIDUAL scope. If either service were absent the corresponding
 *    assertions below would fail.
 *
 * The `user.group_permissions` cache context is asserted to vary between an
 * outsider and an insider (its hash must differ), proving cached access varies
 * with the calculated set.
 *
 * @group do_tests
 * @group group
 */
class AccessPolicyEnforcementTest extends GroupsKernelTestBase {

  /**
   * The access policy processor (drives all tagged `access_policy` services).
   *
   * @var \Drupal\Core\Session\AccessPolicyProcessorInterface
   */
  protected $accessPolicyProcessor;

  /**
   * The group permission hash generator (backs the cache context).
   *
   * @var \Drupal\group\Access\GroupPermissionsHashGeneratorInterface
   */
  protected $hashGenerator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The synchronized policy matches an account's *global* roles, so the
    // authenticated role must exist for outsider/insider roles to apply.
    $this->createRole([], RoleInterface::AUTHENTICATED_ID);
    $this->accessPolicyProcessor = $this->container->get('access_policy_processor');
    $this->hashGenerator = $this->container->get('group_permission.hash_generator');
  }

  /**
   * Outsider, insider and individual scopes resolve distinct permission sets.
   *
   * @covers \Drupal\group\Access\SynchronizedGroupRoleAccessPolicy::calculatePermissions
   * @covers \Drupal\group\Access\IndividualGroupRoleAccessPolicy::calculatePermissions
   */
  public function testScopesResolveDistinctCalculatedPermissions(): void {
    // Three group roles, one per scope, each carrying a *distinct* permission
    // so the resolved sets are unambiguously attributable to a single scope.
    $this->createGroupRole([
      'id' => 'cg_outsider',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['view group'],
    ]);
    $this->createGroupRole([
      'id' => 'cg_insider',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['edit group'],
    ]);
    $individual_role = $this->createGroupRole([
      'id' => 'cg_individual',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'permissions' => ['delete group'],
    ]);

    $group = $this->createGroup();

    $outsider = $this->createUser();
    $insider = $this->createUser();
    $individual = $this->createUser();

    // Plain member (insider only).
    $this->addMember($group, $insider);
    // Member additionally carrying the individual-scope role on this group.
    $this->addMember($group, $individual, [$individual_role->id()]);

    // Enforcement path: Group::hasPermission() (checker -> calculator).
    // OUTSIDER: has only the outsider permission; not the insider/individual.
    $this->assertTrue($group->hasPermission('view group', $outsider), 'Outsider holds the outsider-scope permission.');
    $this->assertFalse($group->hasPermission('edit group', $outsider), 'Outsider lacks the insider-scope permission.');
    $this->assertFalse($group->hasPermission('delete group', $outsider), 'Outsider lacks the individual-scope permission.');

    // INSIDER (plain member): has the insider permission; the outsider role
    // does NOT apply to a member, and it has no individual grant.
    $this->assertTrue($group->hasPermission('edit group', $insider), 'Insider holds the insider-scope permission.');
    $this->assertFalse($group->hasPermission('view group', $insider), 'Insider does not inherit the outsider-scope permission.');
    $this->assertFalse($group->hasPermission('delete group', $insider), 'Insider lacks the individual-scope permission.');

    // INDIVIDUAL member: gains the individual permission (which neither a plain
    // outsider nor a plain insider holds) on top of the insider permission.
    $this->assertTrue($group->hasPermission('delete group', $individual), 'Individual member gains the individual-scope permission.');
    $this->assertTrue($group->hasPermission('edit group', $individual), 'Individual member still holds the insider-scope permission.');

    // The individual grant is group-specific: a *second* group must not leak
    // it.
    $other_group = $this->createGroup();
    $this->addMember($other_group, $individual);
    $this->assertFalse($other_group->hasPermission('delete group', $individual), 'Individual grant does not leak to another group.');

    // Prove the three sets are pairwise distinct (outsider/insider/individual).
    $outsider_perms = $this->permissionsFor($outsider, $group);
    $insider_perms = $this->permissionsFor($insider, $group);
    $individual_perms = $this->permissionsFor($individual, $group);
    $this->assertNotEquals($outsider_perms, $insider_perms, 'Outsider and insider permission sets differ.');
    $this->assertNotEquals($insider_perms, $individual_perms, 'Insider and individual permission sets differ.');
    $this->assertNotEquals($outsider_perms, $individual_perms, 'Outsider and individual permission sets differ.');
    $this->assertContains('view group', $outsider_perms);
    $this->assertContains('edit group', $insider_perms);
    $this->assertContains('delete group', $individual_perms);
    $this->assertContains('edit group', $individual_perms);
    $this->assertNotContains('delete group', $insider_perms);
  }

  /**
   * Both tagged access_policy services actually feed the calculated result.
   *
   * The individual grant can only come from IndividualGroupRoleAccessPolicy and
   * the synchronized (outsider/insider) grants can only come from
   * SynchronizedGroupRoleAccessPolicy; asserting items appear under each scope
   * confirms both services are registered and processed.
   */
  public function testBothAccessPolicyServicesContribute(): void {
    $individual_role = $this->createGroupRole([
      'id' => 'cg_individual2',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'permissions' => ['delete group'],
    ]);
    $this->createGroupRole([
      'id' => 'cg_insider2',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['edit group'],
    ]);

    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account, [$individual_role->id()]);

    // SynchronizedGroupRoleAccessPolicy (priority -50) feeds the INSIDER scope,
    // keyed by group *type*.
    $insider = $this->accessPolicyProcessor->processAccessPolicies($account, PermissionScopeInterface::INSIDER_ID);
    $insider_by_scope = $this->itemsByScope($insider);
    $this->assertArrayHasKey(PermissionScopeInterface::INSIDER_ID, $insider_by_scope, 'SynchronizedGroupRoleAccessPolicy produced an INSIDER item.');
    $this->assertContains('edit group', $insider_by_scope[PermissionScopeInterface::INSIDER_ID][static::GROUP_TYPE_ID] ?? []);

    // IndividualGroupRoleAccessPolicy (priority -100) feeds the INDIVIDUAL
    // scope, keyed by group *id*.
    $individual = $this->accessPolicyProcessor->processAccessPolicies($account, PermissionScopeInterface::INDIVIDUAL_ID);
    $individual_by_scope = $this->itemsByScope($individual);
    $this->assertArrayHasKey(PermissionScopeInterface::INDIVIDUAL_ID, $individual_by_scope, 'IndividualGroupRoleAccessPolicy produced an INDIVIDUAL item.');
    $this->assertContains('delete group', $individual_by_scope[PermissionScopeInterface::INDIVIDUAL_ID][$group->id()] ?? []);
  }

  /**
   * The user.group_permissions cache context varies with the resolved set.
   *
   * An outsider and an insider must hash differently, otherwise a cached render
   * would be served across the access boundary.
   */
  public function testGroupPermissionsCacheContextVaries(): void {
    $this->createGroupRole([
      'id' => 'cg_insider3',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['edit group'],
    ]);
    $this->createGroupRole([
      'id' => 'cg_outsider3',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => ['view group'],
    ]);

    $group = $this->createGroup();
    $outsider = $this->createUser();
    $insider = $this->createUser();
    $this->addMember($group, $insider);

    $outsider_hash = $this->hashGenerator->generateHash($outsider);
    $insider_hash = $this->hashGenerator->generateHash($insider);

    $this->assertNotEmpty($outsider_hash);
    $this->assertNotEmpty($insider_hash);
    $this->assertNotSame($insider_hash, $outsider_hash, 'The user.group_permissions hash differs between an insider and an outsider, so cached access varies.');
  }

  /**
   * Flattens a calculated-permissions object to [scope][identifier => perms].
   *
   * @param \Drupal\Core\Session\CalculatedPermissionsInterface $calculated
   *   The calculated permissions.
   *
   * @return array<string, array<int|string, string[]>>
   *   Permissions keyed by scope then identifier (group id or group type id).
   */
  protected function itemsByScope(CalculatedPermissionsInterface $calculated): array {
    $by_scope = [];
    foreach ($calculated->getItems() as $item) {
      $by_scope[$item->getScope()][$item->getIdentifier()] = $item->getPermissions();
    }
    return $by_scope;
  }

  /**
   * Returns the effective permission list an account has on a specific group.
   *
   * Merges the INDIVIDUAL item for the group id with the INSIDER/OUTSIDER item
   * for the group type, mirroring how {@see GroupPermissionChecker} decides.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   *
   * @return string[]
   *   The effective permission strings.
   */
  protected function permissionsFor($account, $group): array {
    $calculated = $this->container->get('group_permission.calculator')->calculateFullPermissions($account);
    $perms = [];

    $individual = $calculated->getItem(PermissionScopeInterface::INDIVIDUAL_ID, $group->id());
    if ($individual) {
      $perms = array_merge($perms, $individual->getPermissions());
    }

    $member = GroupMembership::loadSingle($group, $account);
    $sync_scope = $member ? PermissionScopeInterface::INSIDER_ID : PermissionScopeInterface::OUTSIDER_ID;
    $sync = $calculated->getItem($sync_scope, $group->bundle());
    if ($sync) {
      $perms = array_merge($perms, $sync->getPermissions());
    }

    return array_values(array_unique($perms));
  }

}
