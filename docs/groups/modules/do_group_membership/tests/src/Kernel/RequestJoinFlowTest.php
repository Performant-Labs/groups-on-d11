<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_group_membership\Exception\DuplicateMembershipException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;

/**
 * #121 SC-2 — Membership models enforced: request-to-join + invite-only.
 *
 * RED (Phase 4, authored by T before F implements). Pins the kernel-level
 * behavior of the request-to-join flow against REAL `group_relationship`
 * entities (not mocks), mirroring the pattern established in
 * {@see GroupMembershipManagerKernelTest}.
 *
 * This suite deliberately does NOT commit to a specific hook name for the
 * invite_only create-access gate (per brief-response-v2 §A-W2 note): it
 * asserts on BEHAVIOR — that creating a pending `group_membership`
 * relationship on an invite_only group is forbidden — via the entity
 * access API (`$entityTypeManager->getAccessControlHandler('group_relationship')
 * ->createAccess(...)`), which is the correct vantage point regardless of
 * whether F implements the gate as `hook_group_relationship_create_access`
 * or falls back to `hook_entity_create_access` (A-W2's documented fallback).
 *
 * Composite `field_group_visibility` (open/moderated/invite_only) is
 * authoritative per the brief; this suite does not assume or test a
 * two-axis split (that is #134 future work).
 *
 * @group do_group_membership
 * @group group
 */
class RequestJoinFlowTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_group_membership'];

  /**
   * The manager service under test.
   *
   * @var \Drupal\do_group_membership\GroupMembershipManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createGroupRole([
      'id' => 'community_group-organizer',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
    ]);
    $this->createGroupRole([
      'id' => 'community_group-member',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group_node:post entity'],
    ]);
    // The outsider scope carries the baseline `join group` grant on the real
    // config (docs/groups/config/group.role.community_group-outsider_view.yml)
    // — reconstructed here via the storage API so this Kernel suite's
    // non-member access checks reflect the real seeded permission shape.
    $this->createGroupRole([
      'id' => 'community_group-outsider_view',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'authenticated',
      'admin' => FALSE,
      'permissions' => ['view group', 'join group'],
    ]);

    // Install field_membership_status the same way the sibling Kernel suite
    // does (GroupMembershipManagerKernelTest) — required for any relationship
    // in this suite to carry a status value.
    FieldStorageConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'active' => 'Active',
          'pending' => 'Pending',
          'blocked' => 'Blocked',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'bundle' => 'community_group-group_membership',
      'label' => 'Status',
    ])->save();

    $this->manager = $this->container->get('do_group_membership.manager');
  }

  /**
   * Creates a group with the given `field_group_visibility` value.
   *
   * @param string $visibility
   *   One of 'open' | 'moderated' | 'invite_only'.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The saved group.
   */
  protected function createGroupWithVisibility(string $visibility): GroupInterface {
    $group = $this->createGroup();
    if ($group->hasField('field_group_visibility')) {
      $group->set('field_group_visibility', $visibility);
      $group->save();
    }
    else {
      // field_group_visibility is not yet installed on this bundle in the
      // Kernel fixture — install storage + instance the same way
      // field_membership_status is installed above, so the test can still
      // exercise the visibility-dependent behavior. This mirrors the
      // established pattern in this test base rather than assuming the
      // field pre-exists on a freshly created group type.
      if (!FieldStorageConfig::loadByName('group', 'field_group_visibility')) {
        FieldStorageConfig::create([
          'field_name' => 'field_group_visibility',
          'entity_type' => 'group',
          'type' => 'list_string',
          'settings' => [
            'allowed_values' => [
              'open' => 'Open',
              'moderated' => 'Moderated',
              'invite_only' => 'Invite Only',
            ],
          ],
        ])->save();
      }
      if (!FieldConfig::loadByName('group', static::GROUP_TYPE_ID, 'field_group_visibility')) {
        FieldConfig::create([
          'field_name' => 'field_group_visibility',
          'entity_type' => 'group',
          'bundle' => static::GROUP_TYPE_ID,
          'label' => 'Visibility',
        ])->save();
      }
      $this->entityTypeManager->clearCachedDefinitions();
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
      $group = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
      $group->set('field_group_visibility', $visibility);
      $group->save();
    }
    return $group;
  }

  /**
   * Returns the `group_membership` relationship backing an account's
   * membership in a group, or NULL if none exists.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface|null
   *   The relationship, or NULL.
   */
  protected function membershipRelationship(GroupInterface $group, AccountInterface $account): ?GroupRelationshipInterface {
    $relationships = $group->getRelationshipsByEntity($account, 'group_membership');
    $relationship = reset($relationships);
    return $relationship instanceof GroupRelationshipInterface ? $relationship : NULL;
  }

  /**
   * AC-2 (kernel half): requestJoin() on a MODERATED group creates a
   * `group_membership` relationship with `field_membership_status = pending`
   * and NO `group_roles`.
   */
  public function testRequestJoinCreatesPendingRelationship(): void {
    $group = $this->createGroupWithVisibility('moderated');
    $sophie = $this->createUser();

    $this->manager->requestJoin($group, $sophie);

    $relationship = $this->membershipRelationship($group, $sophie);
    $this->assertInstanceOf(GroupRelationshipInterface::class, $relationship, 'requestJoin() creates a group_membership relationship.');
    $this->assertSame('pending', $relationship->get('field_membership_status')->value, 'The new relationship is pending, not active.');
    $this->assertTrue($relationship->get('group_roles')->isEmpty(), 'A pending request carries no group_roles.');
  }

  /**
   * AC-4 (extended, brief-response §4 / v2 §A-1): approving a pending
   * request flips status to active AND assigns NO group_roles — the
   * approved membership's baseline access comes from the outsider→member
   * grant implicit in having the relationship, not an explicit role.
   */
  public function testApprovePendingFlipsToActiveWithNoRoles(): void {
    $group = $this->createGroupWithVisibility('moderated');
    $sophie = $this->createUser();
    $this->manager->requestJoin($group, $sophie);
    $relationship = $this->membershipRelationship($group, $sophie);

    $this->manager->approvePending($relationship);

    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship->id());
    $this->assertSame('active', $reloaded->get('field_membership_status')->value, 'Approval flips status to active.');
    $this->assertTrue($reloaded->get('group_roles')->isEmpty(), 'Approval MUST NOT assign any group_roles (brief-response §4).');
  }

  /**
   * AC-4 (second half): denying a pending request DELETES the relationship
   * entirely (consistent with the existing denyPending() "never joined"
   * contract — see GroupMembershipManagerKernelTest::testDenyPendingDeletesTheRelationship()).
   */
  public function testDenyPendingDeletesRelationship(): void {
    $group = $this->createGroupWithVisibility('moderated');
    $sophie = $this->createUser();
    $this->manager->requestJoin($group, $sophie);
    $relationship = $this->membershipRelationship($group, $sophie);
    $relationship_id = $relationship->id();

    $this->manager->denyPending($relationship);

    $reloaded = $this->entityTypeManager->getStorage('group_relationship')->loadUnchanged($relationship_id);
    $this->assertNull($reloaded, 'Denying a pending request deletes the relationship entity entirely.');
    $this->assertEmpty($group->getMember($sophie), 'The denied account is no longer a member at all.');
  }

  /**
   * AC-5: a second requestJoin() call for the same (group, user) throws
   * DuplicateMembershipException — the same guard shape addMember() already
   * uses (any existing relationship, any status, blocks a second one).
   */
  public function testDuplicateRequestJoinThrows(): void {
    $group = $this->createGroupWithVisibility('moderated');
    $sophie = $this->createUser();
    $this->manager->requestJoin($group, $sophie);

    $this->expectException(DuplicateMembershipException::class);
    $this->manager->requestJoin($group, $sophie);
  }

  /**
   * AC-3 (kernel half) + AC-11 (kernel half): creating a `group_membership`
   * relationship on an INVITE_ONLY group, as a non-member/non-organizer, is
   * forbidden at the entity-create access layer.
   *
   * Asserts on BEHAVIOR (the entity-create access check returns forbidden),
   * not on which hook implements the gate — per brief-response-v2 §A-W2, T
   * must not commit to a specific hook name in RED tests.
   */
  public function testRequestJoinOnInviteOnlyGroupIsForbidden(): void {
    $group = $this->createGroupWithVisibility('invite_only');
    $alex = $this->createUser();
    $this->setCurrentUser($alex);

    $access_handler = $this->entityTypeManager->getAccessControlHandler('group_relationship');
    $relationship_type_id = $this->entityTypeManager
      ->getStorage('group_relationship_type')
      ->getRelationshipTypeId(static::GROUP_TYPE_ID, 'group_membership');

    $access = $access_handler->createAccess($relationship_type_id, $alex, [
      'group' => $group,
    ], TRUE);

    $this->assertInstanceOf(AccessResultInterface::class, $access);
    // Assert isForbidden() specifically (not merely !isAllowed()) � a
    // NEUTRAL access result (the default with no create-access gate
    // implemented yet) also fails isAllowed(), which would make this
    // assertion trivially true before F writes any code. isForbidden()
    // is only TRUE once a hook actively returns AccessResult::forbidden(),
    // which is the real behavior this story requires.
    $this->assertTrue($access->isForbidden(), 'A non-member/non-organizer is forbidden (not merely un-allowed) from creating a group_membership relationship on an invite_only group.');
  }

  /**
   * AC-1 (kernel half): on an OPEN group, joinPolicyFor() classifies the
   * group as 'open' and the existing addMember() path still works
   * unmodified (no regression to #95's instant-join flow).
   */
  public function testRequestJoinOnOpenGroupUsesJoinPath(): void {
    $group = $this->createGroupWithVisibility('open');
    $sophie = $this->createUser();

    $this->assertSame('open', $this->manager->joinPolicyFor($group));

    $this->manager->addMember($group, $sophie, []);
    $relationship = $this->membershipRelationship($group, $sophie);
    $this->assertInstanceOf(GroupRelationshipInterface::class, $relationship);
    $this->assertSame('active', $relationship->get('field_membership_status')->value, 'addMember() on an open group still creates an active membership.');
  }

  /**
   * joinPolicyFor() returns the correct classifier string for each of the
   * three composite `field_group_visibility` values.
   */
  public function testJoinPolicyForReturnsCorrectStringPerVisibility(): void {
    $open = $this->createGroupWithVisibility('open');
    $moderated = $this->createGroupWithVisibility('moderated');
    $invite_only = $this->createGroupWithVisibility('invite_only');

    $this->assertSame('open', $this->manager->joinPolicyFor($open));
    $this->assertSame('request', $this->manager->joinPolicyFor($moderated));
    $this->assertSame('invite', $this->manager->joinPolicyFor($invite_only));
  }

}
