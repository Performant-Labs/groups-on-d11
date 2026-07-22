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
   * scope: insider, admin: true, global_role: groups_moderate.
   */
  public function testGroupsModerateRoleConfigShape(): void {
    $site_role_file = $this->locate('docs/groups/config/user.role.groups_moderate.yml');
    $this->assertNotNull($site_role_file, 'user.role.groups_moderate.yml exists.');
    $site_role = Yaml::parse((string) file_get_contents((string) $site_role_file));
    $this->assertSame('groups_moderate', $site_role['id'] ?? NULL);

    $data = $this->loadConfig('docs/groups/config/group.role.community_group-groups_moderate.yml');
    $this->assertSame('community_group-groups_moderate', $data['id'] ?? NULL);
    $this->assertSame('community_group', $data['group_type'] ?? NULL);
    $this->assertSame('insider', $data['scope'] ?? NULL, 'Groups-Moderate is a synchronized INSIDER-scope role, not individual.');
    $this->assertTrue($data['admin'] ?? NULL, 'Groups-Moderate carries admin:true for the full per-group bypass.');
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
