<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * AC-14 (+ [W-1]) — Unit coverage for `do_group_membership`'s manager service
 * core API, with mocked dependencies, per the mandatory Services-over-Hooks
 * playbook pattern (`docs/playbook/frameworks/drupal/best-practices.md`
 * §"Custom Module Architecture: Services over Hooks").
 *
 * This test file defines the manager's public CONTRACT (constructor args,
 * method names/signatures) as the brief's Round-1 resolution locks it:
 * addMember(), changeRole(), changeStatus(), removeMember(), approvePending(),
 * denyPending(). Class `Drupal\do_group_membership\GroupMembershipManager`
 * does not exist yet — RED here is a "class not found" style failure that IS
 * the expected RED for the FIRST Unit test in a brand-new module (there is no
 * production code at all yet); every method below still asserts real
 * behavior against mocks once F creates the class, so the contract, not just
 * the class's existence, is pinned.
 *
 * Status transitions under test mirror [B-3] exactly:
 *   - changeStatus(pending -> active) = approve path (also exercised via the
 *     explicit approvePending() convenience method)
 *   - changeStatus(pending -> deleted) = deny path (approvePending's sibling,
 *     denyPending(), deletes the relationship entity, not merely sets a
 *     status value)
 *   - changeStatus(active <-> blocked) is symmetric
 *   - removeMember() deletes the relationship entity at any status
 *
 * The last-Organizer guard (AC-9) is deliberately NOT unit-tested here: it
 * requires "how many active Organizers does this group have," a real
 * query/count over sibling relationships that would need such an elaborate
 * mock chain (entity query builder, condition(), count(), execute()) that the
 * mock would encode the manager's *implementation* rather than its
 * *behavior* — exactly what T must avoid asserting. AC-9 is covered
 * end-to-end at the Kernel tier instead (real entities, real query), which is
 * both cheaper to keep correct and a stronger assertion.
 *
 * @group do_group_membership
 */
final class GroupMembershipManagerTest extends UnitTestCase {

  /**
   * Builds a manager with entirely mocked dependencies.
   *
   * The real service is expected to depend on (at minimum) the entity type
   * manager (to load/save `group_relationship` entities) — additional
   * constructor args F introduces (e.g. a logger, string translation) are
   * fine as long as entityTypeManager remains injectable/mockable; this test
   * only asserts the entity-type-manager-mediated behavior, which is stable
   * regardless of what else the constructor accepts.
   */
  protected function makeManager(EntityTypeManagerInterface $entityTypeManager): GroupMembershipManager {
    return new GroupMembershipManager($entityTypeManager);
  }

  /**
   * addMember() creates an active `group_relationship` (default status).
   *
   * Per [B-6]: an organizer/moderator directly adding someone defaults to
   * `active` status (not `pending` — that's the self-service join-request
   * territory, out of scope here).
   */
  public function testAddMemberCreatesActiveRelationship(): void {
    // CORRECTED at T-green (Phase 6): mocking $account as the looser
    // AccountInterface fails with a TypeError regardless of how
    // GroupMembershipManager::addMember() is implemented, because the REAL
    // GroupInterface::addMember(UserInterface $account, $values = [])
    // (confirmed against drupal/group 4.0.x source,
    // git.drupalcode.org/project/group @ 4.0.x, src/Entity/GroupInterface.php)
    // declares a strict UserInterface parameter type, and PHPUnit's mock
    // enforces the real method's type declaration on every call. This is a
    // test-authorship fix (T's own mock too loose), not a production-code
    // defect.
    $group = $this->createMock(GroupInterface::class);
    $account = $this->createMock(UserInterface::class);

    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->expects($this->once())->method('save');

    $group->expects($this->once())
      ->method('addMember')
      ->with($account, $this->callback(function (array $values): bool {
        // The manager must set field_membership_status => active and pass
        // through the requested group_roles.
        return ($values['group_roles'][0] ?? NULL) === 'community_group-member'
          && ($values['field_membership_status'][0]['value'] ?? NULL) === 'active';
      }))
      ->willReturn($relationship);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $result = $manager->addMember($group, $account, ['community_group-member']);
    $this->assertSame($relationship, $result);
  }

  /**
   * changeRole() mutates group_roles only, independent of
   * field_membership_status — per [B-3]'s explicit orthogonality rule.
   */
  public function testChangeRoleMutatesOnlyGroupRolesField(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $roles_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['setValue'])
      ->getMock();
    $roles_field->expects($this->once())->method('setValue')->with(['community_group-moderator']);

    $status_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['setValue'])
      ->getMock();
    $status_field->expects($this->never())->method('setValue');

    $relationship->method('get')->willReturnMap([
      ['group_roles', $roles_field],
      ['field_membership_status', $status_field],
    ]);
    $relationship->expects($this->once())->method('save');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->changeRole($relationship, ['community_group-moderator']);
  }

  /**
   * changeStatus(active -> blocked) sets field_membership_status to blocked
   * and saves — the relationship entity itself is NOT deleted (symmetric
   * with unblock, per [B-3]).
   */
  public function testChangeStatusBlocksActiveMember(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $status_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['setValue'])
      ->getMock();
    $status_field->expects($this->once())->method('setValue')->with('blocked');

    $relationship->method('get')->with('field_membership_status')->willReturn($status_field);
    $relationship->expects($this->once())->method('save');
    $relationship->expects($this->never())->method('delete');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->changeStatus($relationship, 'blocked');
  }

  /**
   * changeStatus(blocked -> active) — the symmetric unblock path.
   */
  public function testChangeStatusUnblocksMember(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $status_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['setValue'])
      ->getMock();
    $status_field->expects($this->once())->method('setValue')->with('active');

    $relationship->method('get')->with('field_membership_status')->willReturn($status_field);
    $relationship->expects($this->once())->method('save');
    $relationship->expects($this->never())->method('delete');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->changeStatus($relationship, 'active');
  }

  /**
   * removeMember() deletes the relationship entity outright, at any status
   * — per [B-3]'s "<any> -> <relationship deleted>" transition.
   */
  public function testRemoveMemberDeletesRelationship(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->expects($this->once())->method('delete');
    $relationship->expects($this->never())->method('save');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->removeMember($relationship);
  }

  /**
   * approvePending() moves a pending relationship to active (pending ->
   * active), not deleted.
   */
  public function testApprovePendingSetsActiveStatus(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $status_field = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['setValue'])
      ->getMock();
    $status_field->expects($this->once())->method('setValue')->with('active');

    $relationship->method('get')->with('field_membership_status')->willReturn($status_field);
    $relationship->expects($this->once())->method('save');
    $relationship->expects($this->never())->method('delete');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->approvePending($relationship);
  }

  /**
   * denyPending() DELETES the relationship entity — per [B-3]'s explicit
   * "deny means never joined" answer (distinct from `blocked`, which means
   * "was in, now banned"). This is the exact behavior that answers the
   * o4-mini's original brief-gate question.
   */
  public function testDenyPendingDeletesRelationship(): void {
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->expects($this->once())->method('delete');
    $relationship->expects($this->never())->method('save');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $manager->denyPending($relationship);
  }

  /**
   * AC-10: approving an already-resolved (no-longer-pending) request is a
   * no-op — it must NOT throw, and must not call save()/delete() on an
   * entity the manager itself is told (via a NULL relationship) no longer
   * exists.
   *
   * The manager's contract: approvePending()/denyPending() accept a nullable
   * relationship (the caller re-loads the relationship by id before acting,
   * and may find nothing — e.g. after a concurrent deny) and return a bool
   * indicating whether the action was actually applied, so the Form layer
   * can render "This request was already handled." instead of a fatal error.
   */
  public function testApprovePendingOnAlreadyResolvedRequestIsNoOp(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $this->assertFalse($manager->approvePending(NULL), 'A NULL (already-resolved / vanished) relationship is a no-op, not a fatal error.');
  }

  /**
   * AC-10, deny side: same no-op contract for denyPending().
   */
  public function testDenyPendingOnAlreadyResolvedRequestIsNoOp(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $manager = $this->makeManager($entityTypeManager);

    $this->assertFalse($manager->denyPending(NULL), 'A NULL (already-resolved / vanished) relationship is a no-op, not a fatal error.');
  }

}
