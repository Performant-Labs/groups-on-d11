<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\Tests\UnitTestCase;

/**
 * #144 MC-6 — Unit coverage for the NEW `GroupMembershipManager::ensureRole()`
 * method (does not exist yet at RED time).
 *
 * Deliberately a SEPARATE file from the existing
 * `GroupMembershipManagerTest.php` (per T's task instructions: never modify
 * an existing test file — strictly additive). Mirrors that file's mocked
 * dependency-injection conventions (`makeManager()`, `createMock()` of
 * `GroupRelationshipInterface`).
 *
 * Per handoff-A.md's Q1 answer and brief.md's Reuse-map: `ensureRole()` must
 * be ADDITIVE (read-then-append), never a `setValue()`/replace — that is
 * `changeRole()`'s (already-existing) footgun this new method exists
 * specifically to avoid. Because `group_roles` is an entity-reference-like
 * field whose current values must be READ before appending, these tests
 * mock `$relationship->get('group_roles')` to return a field-item-list-like
 * mock exposing `getValue()` (read) and `setValue()` (write) — the same two
 * calls `changeRole()`'s existing Unit test
 * (`GroupMembershipManagerTest::testChangeRoleMutatesOnlyGroupRolesField()`)
 * already mocks, so this suite matches established convention rather than
 * inventing a new mock shape.
 *
 * Does NOT unit-test the last-Organizer guard or any query-backed behavior
 * (out of scope for ensureRole() entirely — it is a pure additive grant, no
 * guard applies to ADDING a role, only to removing/demoting).
 *
 * @group do_group_membership
 */
final class EnsureRoleTest extends UnitTestCase {

  /**
   * Builds a manager with entirely mocked dependencies (mirrors sibling file).
   */
  protected function makeManager(EntityTypeManagerInterface $entityTypeManager): GroupMembershipManager {
    return new GroupMembershipManager($entityTypeManager);
  }

  /**
   * ensureRole() on a membership that does NOT yet have the role: the role
   * is APPENDED to the existing `group_roles` values (additive), and the
   * relationship is saved.
   *
   * The membership already carries `community_group-admin` (simulating the
   * creator_roles-granted Admin role); asserts BOTH admin and organizer are
   * present after ensureRole() — i.e. this is additive, not a replace (the
   * exact footgun `changeRole()`'s `setValue(array_values($roles))` would
   * have caused, per handoff-A.md Q1).
   */
  public function testEnsureRoleAppendsMissingRoleToExistingRoles(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $roles_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getValue', 'setValue'])
      ->getMock();
    // Existing value: only community_group-admin present.
    $roles_field->method('getValue')->willReturn([
      ['target_id' => 'community_group-admin'],
    ]);
    // The append must PRESERVE the existing admin role AND add organizer —
    // never a bare setValue(['community_group-organizer']) replace.
    $roles_field->expects($this->once())
      ->method('setValue')
      ->with($this->callback(function (array $roles): bool {
        $ids = array_map(
          static fn ($role) => is_array($role) ? ($role['target_id'] ?? $role) : $role,
          $roles,
        );
        return in_array('community_group-admin', $ids, TRUE)
          && in_array('community_group-organizer', $ids, TRUE)
          && count($ids) === 2;
      }));

    $relationship->method('hasField')->with('group_roles')->willReturn(TRUE);
    $relationship->method('get')->with('group_roles')->willReturn($roles_field);
    $relationship->expects($this->once())->method('save');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->ensureRole($relationship, 'community_group-organizer');
  }

  /**
   * Idempotency: calling ensureRole() when the role is ALREADY present is a
   * no-op — does not duplicate the role value in `group_roles`, does not
   * call `setValue()` or `save()`, and does not error.
   */
  public function testEnsureRoleIsNoOpWhenRoleAlreadyPresent(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $roles_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['getValue', 'setValue'])
      ->getMock();
    $roles_field->method('getValue')->willReturn([
      ['target_id' => 'community_group-admin'],
      ['target_id' => 'community_group-organizer'],
    ]);
    $roles_field->expects($this->never())->method('setValue');

    $relationship->method('hasField')->with('group_roles')->willReturn(TRUE);
    $relationship->method('get')->with('group_roles')->willReturn($roles_field);
    $relationship->expects($this->never())->method('save');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    // Must not throw.
    $manager->ensureRole($relationship, 'community_group-organizer');
  }

}
