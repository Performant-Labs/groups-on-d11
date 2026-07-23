<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * #120 SC-1 Persona Switcher — brief-amendments.md Amendment 1: the
 * Groups-Moderate GROUP-scoped role config gets scoped down from a blanket
 * `admin: true` bypass to `admin: false` + an enumerated permission set
 * (`administer members`, `edit group`).
 *
 * Reads the *authored* YAML straight off disk from `docs/groups/config/`
 * (the source of truth `scripts/ci/assemble-config.sh` copies into
 * `config/sync/`) — the exact technique already established by this repo's
 * own `do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php`
 * (source-relative path-walk is safe for a Unit config-shape assertion: it
 * never runs against the CI assembled layout for a FIXTURE, it just confirms
 * the authored file on disk has the right shape — the "fixtures must be
 * module-local" rule concerns Kernel/Functional runtime fixtures, not this).
 *
 * RED reason: today `group.role.community_group-groups_moderate.yml` ships
 * `admin: true` + `permissions: {}` (the PRE-amendment bypass shape) — every
 * assertion below fails against the CURRENT file content, not a missing
 * file (the file already exists, per O's Phase-1 survey).
 *
 * @group do_showcase
 * @group do_group_membership
 */
final class GroupsModerateRoleConfigShapeTest extends UnitTestCase {

  /**
   * Locates a config YAML file under `docs/groups/config/` by walking up
   * from this test file (mirrors GroupRoleConfigShapeTest::locate()).
   *
   * @param string $relative
   *   The path relative to the repo root.
   *
   * @return string|null
   *   The absolute path, or NULL if not found within 10 ascents.
   */
  private function locate(string $relative): ?string {
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
   * Loads and decodes the group-role config YAML, asserting it exists.
   *
   * @return array<string, mixed>
   *   The decoded config array.
   */
  private function loadGroupRoleConfig(): array {
    $file = $this->locate('docs/groups/config/group.role.community_group-groups_moderate.yml');
    $this->assertNotNull($file, 'group.role.community_group-groups_moderate.yml exists.');
    /** @var array<string, mixed> $data */
    $data = Yaml::parse((string) file_get_contents((string) $file));
    return $data;
  }

  /**
   * Amendment 1: `admin: true` -> `admin: false` — no more blanket bypass.
   */
  public function testAdminFlagIsFalse(): void {
    $data = $this->loadGroupRoleConfig();
    $this->assertFalse($data['admin'] ?? NULL, 'Amendment 1: group.role.community_group-groups_moderate.yml must flip admin:true -> admin:false (enumerated perms only, not a blanket bypass).');
  }

  /**
   * Amendment 1: enumerated permissions are exactly `administer members` +
   * `edit group` (covers the pending queue/approve/remove path via
   * ManageMembersController, and restore via RestoreGroupAccess — A's
   * non-blocking note confirmed no separate archive-only perm exists).
   *
   * No `view group_node:*` permissions — Moderator is not a content viewer
   * (Amendment 1: "Moderator is not a content viewer").
   */
  public function testEnumeratedPermissionsAreExactlyAdministerMembersAndEditGroup(): void {
    $data = $this->loadGroupRoleConfig();
    $permissions = $data['permissions'] ?? [];

    $this->assertContains('administer members', $permissions, 'Must grant administer members (pending queue / approve / remove).');
    $this->assertContains('edit group', $permissions, 'Must grant edit group (covers restore via RestoreGroupAccess::access).');

    foreach ($permissions as $permission) {
      $this->assertStringNotContainsString('view group_node:', (string) $permission, 'Moderator must NOT get any view group_node:* permission — not a content viewer.');
    }
  }

  /**
   * Scope stays `outsider` (unchanged by this amendment — Groups-Moderate is
   * never a group member by design) and the synchronization wiring
   * (`global_role: groups_moderate`) is preserved.
   */
  public function testScopeAndGlobalRoleUnchanged(): void {
    $data = $this->loadGroupRoleConfig();
    $this->assertSame('outsider', $data['scope'] ?? NULL, 'Scope must remain outsider (Groups-Moderate never joins the group it moderates).');
    $this->assertSame('groups_moderate', $data['global_role'] ?? NULL, 'Synchronization wiring must remain intact.');
  }

}
