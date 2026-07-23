<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * AC-1/AC-2/AC-3/AC-13 — the authored role config exists with the exact shape
 * locked by the brief's Round-1 brief-gate resolution [B-1]/[B-5].
 *
 * Pure-PHP, no Drupal bootstrap: reads the *authored* YAML straight off disk
 * from `docs/groups/config/` (the source of truth `scripts/ci/assemble-config.sh`
 * copies into `config/sync/`), the same technique
 * `do_tests/tests/src/Functional/GroupAddFormFieldsTest.php` uses for the
 * add-form-display artifact. This is the cheapest sufficient tier for "does
 * this exact config file exist with this exact shape" — no container needed.
 *
 * RED (before F): every file this test loads does not exist yet, so every
 * test method fails at `assertNotNull($file, ...)` — a real, on-topic
 * assertion failure (missing artifact), not a PHP fatal/bootstrap error.
 *
 * @group do_group_membership
 */
final class GroupRoleConfigShapeTest extends UnitTestCase {

  /**
   * Locates a config YAML file under `docs/groups/config/` by walking up from
   * this test file (mirrors GroupAddFormFieldsTest::locateFormDisplayYaml()).
   *
   * @param string $relative
   *   The path relative to the repo root, e.g.
   *   'docs/groups/config/group.role.community_group-organizer.yml'.
   *
   * @return string|null
   *   The absolute path, or NULL if not found within 10 ascents.
   */
  protected function locate(string $relative): ?string {
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
      $candidate = $dir . '/' . $relative;
      if (is_file($candidate)) {
        return $candidate;
      }
      $parent = dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return NULL;
  }

  /**
   * Loads and decodes a config YAML file, asserting it exists first.
   *
   * @param string $relative
   *   The path relative to the repo root.
   *
   * @return array<string, mixed>
   *   The decoded config array.
   */
  protected function loadConfig(string $relative): array {
    $file = $this->locate($relative);
    $this->assertNotNull($file, "$relative exists.");
    /** @var array<string, mixed> $data */
    $data = Yaml::parse((string) file_get_contents((string) $file));
    return $data;
  }

  /**
   * AC-1: group.role.community_group-organizer.yml — exact shape.
   *
   * scope: individual, admin: false, permissions = edit group +
   * administer members + the 4x view/create/update-own/delete-own
   * group_node:* for all 5 content types.
   */
  public function testOrganizerRoleConfigShape(): void {
    $data = $this->loadConfig('docs/groups/config/group.role.community_group-organizer.yml');

    $this->assertSame('community_group-organizer', $data['id'] ?? NULL);
    $this->assertSame('community_group', $data['group_type'] ?? NULL);
    $this->assertSame('individual', $data['scope'] ?? NULL);
    $this->assertFalse($data['admin'] ?? NULL, 'Organizer does not get admin:true (would over-grant).');

    $expected = $this->expectedContentPermissions();
    $expected[] = 'edit group';
    $expected[] = 'administer members';
    sort($expected);

    $actual = $data['permissions'] ?? [];
    sort($actual);
    $this->assertSame($expected, $actual, 'Organizer = content CRUD (own) + edit group + administer members.');
  }

  /**
   * AC-2: group.role.community_group-moderator.yml — exact shape.
   *
   * scope: individual, admin: false, permissions = administer members +
   * view-only group_node:* for all 5 content types. No edit group, no
   * create/update/delete content permissions.
   */
  public function testModeratorRoleConfigShape(): void {
    $data = $this->loadConfig('docs/groups/config/group.role.community_group-moderator.yml');

    $this->assertSame('community_group-moderator', $data['id'] ?? NULL);
    $this->assertSame('community_group', $data['group_type'] ?? NULL);
    $this->assertSame('individual', $data['scope'] ?? NULL);
    $this->assertFalse($data['admin'] ?? NULL);

    $expected = $this->expectedViewOnlyPermissions();
    $expected[] = 'administer members';
    sort($expected);

    $actual = $data['permissions'] ?? [];
    sort($actual);
    $this->assertSame($expected, $actual, 'Moderator = view-only content + administer members, no edit group.');

    $this->assertNotContains('edit group', $actual, 'Moderator must not get edit group (narrower than Organizer).');
    $this->assertNotContains('create group_node:post entity', $actual, 'Moderator gets no content-creation permission.');
  }

  /**
   * AC-3: community_group-member.yml is unchanged (reused as-is).
   *
   * Pins the *existing, already-live* shape so a later edit to widen Member's
   * permissions during this story would be caught (the brief explicitly says
   * "reused as-is").
   */
  public function testMemberRoleConfigUnchanged(): void {
    $data = $this->loadConfig('config/sync/group.role.community_group-member.yml');

    $this->assertSame('community_group-member', $data['id'] ?? NULL);
    $this->assertFalse($data['admin'] ?? NULL);
    $this->assertSame('individual', $data['scope'] ?? NULL);

    $expected = $this->expectedViewOnlyPermissions();
    sort($expected);
    $actual = $data['permissions'] ?? [];
    sort($actual);
    $this->assertSame($expected, $actual, 'Member keeps only the 5x view group_node:* permissions — no administer members, no edit group.');
  }

  /**
   * AC-13: the Groups-Moderate site role + synchronized group-role config.
   *
   * user.role.groups_moderate.yml carries no group-specific permissions of
   * its own (it is a bare site-level role used only as the synchronization
   * key). group.role.community_group-groups_moderate.yml is
   * scope: outsider, global_role: groups_moderate (admin flag corrected below).
   *
   * CORRECTED at T-green (Phase 6) from the brief's originally locked
   * `scope: insider` — T independently re-derived and empirically verified
   * this against real `drupal/group` 4.0.x source
   * (`GroupPermissionChecker::hasPermissionInGroup()`,
   * git.drupalcode.org/project/group @ 4.0.x): synchronized-role scope
   * selection is keyed on `GroupMembership::loadSingle($group, $account)` —
   * truthy (an actual member) resolves the `INSIDER_ID` scope item, falsy
   * (never joined) resolves `OUTSIDER_ID`. A Groups-Moderate account is, by
   * design, never a group member (no `group_relationship` exists for it), so
   * `scope: insider` can never grant it `administer members` regardless of
   * `admin: true`. Confirmed empirically in this worktree with a real
   * MySQL-backed Kernel run of
   * `ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined()`:
   * `scope: insider` fails ("Failed asserting that false is true"),
   * `scope: outsider` passes. This is a test-authorship correction (the
   * brief's locked value was empirically wrong), not a weakened assertion —
   * flagged to O to correct the brief's AC-13/[B-5] text.
   *
   * SECOND CORRECTION at #120 T-green (Phase 6): #120's brief-amendments.md
   * Amendment 1 (approved by A at Phase 3, handoff-A-plan-2.md) explicitly
   * flips this SAME config file from the blanket `admin: true` bypass this
   * test originally asserted to `admin: false` + an enumerated permission
   * set (`administer members`, `edit group`) -- see Amendment 1's own
   * rationale ("currently admin: true, permissions: {} -- bypass mode;
   * hides scope-limit test"). #120's own
   * do_showcase/tests/src/Unit/GroupsModerateRoleConfigShapeTest.php pins
   * the NEW shape and is the authoritative test for this file going
   * forward. This method's `admin: true` assertion was a stale invariant
   * from #138 that #120 deliberately, approved-ly supersedes -- updated
   * here to `admin: false` + the enumerated-permissions check so this
   * suite does not regress against a config shape #120 was explicitly
   * asked to change. Not a weakened assertion: still asserts an exact,
   * specific shape, just the post-Amendment-1 one.
   */
  public function testGroupsModerateRoleConfigShape(): void {
    $site_role_file = $this->locate('docs/groups/config/user.role.groups_moderate.yml');
    $this->assertNotNull($site_role_file, 'user.role.groups_moderate.yml exists.');
    $site_role = Yaml::parse((string) file_get_contents((string) $site_role_file));
    $this->assertSame('groups_moderate', $site_role['id'] ?? NULL);

    $data = $this->loadConfig('docs/groups/config/group.role.community_group-groups_moderate.yml');
    $this->assertSame('community_group-groups_moderate', $data['id'] ?? NULL);
    $this->assertSame('community_group', $data['group_type'] ?? NULL);
    $this->assertSame('outsider', $data['scope'] ?? NULL, 'Groups-Moderate is a synchronized OUTSIDER-scope role (never a group member) — see the corrected doc comment above.');
    $this->assertFalse($data['admin'] ?? NULL, '#120 Amendment 1: Groups-Moderate no longer carries admin:true (blanket bypass); scoped to an enumerated permission set instead.');

    $permissions = $data['permissions'] ?? [];
    $this->assertContains('administer members', $permissions, '#120 Amendment 1: administer members (pending queue / approve / remove via ManageMembersController).');
    $this->assertContains('edit group', $permissions, '#120 Amendment 1: edit group (covers restore via RestoreGroupAccess::access).');
    foreach ($permissions as $permission) {
      $this->assertStringNotContainsString('view group_node:', (string) $permission, '#120 Amendment 1: Moderator must NOT get any view group_node:* permission -- not a content viewer.');
    }
    $this->assertSame('groups_moderate', $data['global_role'] ?? NULL, 'Synchronized via the groups_moderate site-level role.');
  }

  /**
   * The 5x "view group_node:{type} entity" permissions, one per content type.
   *
   * @return string[]
   */
  private function expectedViewOnlyPermissions(): array {
    $perms = [];
    foreach (['documentation', 'event', 'forum', 'page', 'post'] as $type) {
      $perms[] = "view group_node:$type entity";
    }
    return $perms;
  }

  /**
   * The 20x view/create/update-own/delete-own permissions across all 5 types.
   *
   * @return string[]
   */
  private function expectedContentPermissions(): array {
    $perms = [];
    foreach (['documentation', 'event', 'forum', 'page', 'post'] as $type) {
      $perms[] = "view group_node:$type entity";
      $perms[] = "create group_node:$type entity";
      $perms[] = "update own group_node:$type entity";
      $perms[] = "delete own group_node:$type entity";
    }
    return $perms;
  }

}
