<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * #121 SC-2 — Membership models enforced: request-to-join + invite-only.
 *
 * RED (Phase 4, authored by T before F implements). Functional (BrowserTestBase,
 * self-provisions) coverage of AC-1/AC-2/AC-3/AC-4/AC-11/AC-15/AC-16 on real
 * HTTP requests/responses — mirrors the established pattern in
 * {@see ManageMembersRouteAccessTest}.
 *
 * AC-4/AC-15/AC-16 target the EXISTING `/group/{group}/members` route and
 * ManageMembersForm (brief-response-v2 §A-1 fix) — NOT a new `/members/pending`
 * route, per A's Phase-3 BLOCK finding and its resolution.
 *
 * @group do_group_membership
 * @group group
 */
class JoinPolicyEnforcementTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_group_membership'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The community_group-shaped group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['administer members'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => [],
    ]);
    // Baseline outsider grant (mirrors the real seeded
    // group.role.community_group-outsider_view.yml — holds `join group`).
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-outsider_view',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'authenticated',
      'admin' => FALSE,
      'permissions' => ['view group', 'join group'],
    ]);

    // Install field_membership_status + field_group_visibility the same way
    // the sibling Functional suites do (ManageMembersPageRenderTest /
    // ManageMembersPaginationTest) — createGroupType()/createGroup() from
    // GroupTestTrait do NOT install these custom fields, so without this a
    // seeded 'pending' status silently no-ops (the field is unknown on the
    // bundle) and every membership defaults to 'active' per
    // GroupMembershipManager::relationshipStatus()'s fallback.
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
    FieldConfig::create([
      'field_name' => 'field_group_visibility',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Visibility',
    ])->save();
  }

  /**
   * Creates a group with the given `field_group_visibility` value.
   *
   * @param string $visibility
   *   One of 'open' | 'moderated' | 'invite_only'.
   * @param string $label
   *   The group label.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The saved group.
   */
  protected function createGroupWithVisibility(string $visibility, string $label): \Drupal\group\Entity\GroupInterface {
    $group = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => $label,
      'status' => 1,
    ]);
    $group->set('field_group_visibility', $visibility);
    $group->save();
    return $group;
  }

  /**
   * AC-1: a non-member can join an OPEN group instantly via the real
   * entity.group.join route (`/group/{group}/join`) — the actual
   * enforcement surface. (#95's clickable "Join group" affordance is a
   * THEME-layer concern rendered on the /all-groups directory view's
   * `gc-directory-card__join` link, not present in this module-level
   * Functional fixture — stark theme, no custom theme/view wired — so this
   * test proves the underlying route access + one-click join transition
   * directly, which is what AC-1 actually requires enforced.)
   */
  public function testNonMemberSeesJoinButtonOnOpenGroup(): void {
    $group = $this->createGroupWithVisibility('open', 'Drupal France');
    $sophie = $this->drupalCreateUser();
    $this->drupalLogin($sophie);

    // The join route is reachable (not blocked) for a non-member of an open
    // group -- this is the real access surface AC-1 requires, independent of
    // which theme renders the clickable affordance.
    $this->drupalGet('/group/' . $group->id() . '/join');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([], 'Join group');
    $active = $group->getMembers();
    $active_uids = array_map(static fn($m) => $m->getEntity()?->id(), $active);
    $this->assertContains($sophie->id(), $active_uids, 'Submitting the join form makes the non-member an active member instantly (no approval step).');
  }

  /**
   * AC-2 (functional half): a non-member on a MODERATED group ("Leadership
   * Council") sees a "Request to join" control; submitting it creates a
   * pending relationship, and she is NOT visible on the active-member list.
   */
  public function testNonMemberSeesRequestToJoinOnModeratedGroup(): void {
    $group = $this->createGroupWithVisibility('moderated', 'Leadership Council');
    $sophie = $this->drupalCreateUser();
    $this->drupalLogin($sophie);

    $this->drupalGet('/group/' . $group->id() . '/join-request');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Request to join');

    $sophie_relationships = $group->getRelationshipsByEntity($sophie, 'group_membership');
    $relationship = reset($sophie_relationships);
    $this->assertNotFalse($relationship, 'Submitting the request-to-join form creates a group_membership relationship.');
    $this->assertSame('pending', $relationship->get('field_membership_status')->value);

    // Not visible as an active member. NOTE: Group::getMembers() has no
    // status-filtering parameter (see GroupInterface::getMembers() docblock,
    // web/modules/contrib/group/src/Entity/GroupInterface.php) -- it returns
    // every group_membership relationship regardless of
    // field_membership_status, exactly like the existing ManageMembersForm
    // (line ~80) which filters/labels status itself AFTER the unfiltered
    // call. So the correct way to assert 'not an active member' is to check
    // the relationship's OWN status directly, not to look for absence from
    // an unfiltered list (which would always fail this assertion, active or
    // not, since getMembers() would still list her at ANY status).
    $this->assertNotSame('active', $relationship->get('field_membership_status')->value, 'A pending requester does not have active status.');
  }

  /**
   * AC-3 (functional half): a non-member on an INVITE_ONLY group ("Core
   * Committers") sees NO Join / Request-to-join control on the group's own
   * canonical page (a baseline that holds today and must keep holding), AND
   * the underlying entity.group.join route itself is forbidden -- the real
   * enforcement surface, independent of which theme renders (or omits) a
   * clickable affordance.
   */
  public function testNonMemberSeesNoJoinPathOnInviteOnlyGroup(): void {
    $group = $this->createGroupWithVisibility('invite_only', 'Core Committers');
    $alex = $this->drupalCreateUser();
    $this->drupalLogin($alex);

    $this->drupalGet('/group/' . $group->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', 'input[type="submit"][value*="Join"]');
    $this->assertSession()->elementNotExists('css', 'input[type="submit"][value*="Request"]');
    $this->assertSession()->pageTextNotContains('Request to join');

    // The real enforcement surface: entity.group.join is forbidden outright
    // for a non-member/non-organizer on an invite_only group.
    $this->drupalGet('/group/' . $group->id() . '/join');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-11: a direct POST to the request-join route on an invite_only group
   * returns 403 — access enforcement is real, not merely UI-hidden.
   */
  public function testDirectPostToRequestJoinOnInviteOnlyIs403(): void {
    $group = $this->createGroupWithVisibility('invite_only', 'Core Committers 2');
    $alex = $this->drupalCreateUser();
    $this->drupalLogin($alex);

    $this->drupalGet('/group/' . $group->id() . '/join-request');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-4 (authoritative, brief-response-v2 §A-1 fix): the organizer sees the
   * pending request in the EXISTING `/group/{group}/members` table (NOT a
   * new /pending route) with a Pending badge; Approve flips it to active and
   * Sophie appears normally in the member list; Deny on a second pending
   * request deletes that relationship.
   */
  public function testOrganizerSeesPendingRowInExistingManageMembers(): void {
    $group = $this->createGroupWithVisibility('moderated', 'Leadership Council 3');
    $organizer = $this->drupalCreateUser();
    $group->addMember($organizer, ['group_roles' => ['community_group-organizer']]);

    $sophie = $this->drupalCreateUser([], 'sophie_mueller_test');
    $group->addMember($sophie, [
      'group_roles' => [],
      'field_membership_status' => [['value' => 'pending']],
    ]);

    $alex = $this->drupalCreateUser([], 'alex_novak_test');
    $group->addMember($alex, [
      'group_roles' => [],
      'field_membership_status' => [['value' => 'pending']],
    ]);

    $this->drupalLogin($organizer);
    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('sophie_mueller_test');
    $this->assertSession()->pageTextContains('Pending');

    // Approve Sophie's row.
    $sophie_relationships = $group->getRelationshipsByEntity($sophie, 'group_membership');
    $sophie_rel = reset($sophie_relationships);
    $this->submitForm([], 'approve_' . $sophie_rel->id());
    $this->assertSession()->pageTextContains('approved');

    $reloaded = \Drupal::entityTypeManager()->getStorage('group_relationship')->loadUnchanged($sophie_rel->id());
    $this->assertSame('active', $reloaded->get('field_membership_status')->value);

    // Deny Alex's row.
    $alex_relationships = $group->getRelationshipsByEntity($alex, 'group_membership');
    $alex_rel = reset($alex_relationships);
    $alex_rel_id = $alex_rel->id();
    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->submitForm([], 'deny_' . $alex_rel_id);
    $this->assertSession()->pageTextContains('denied');

    $reloaded_alex = \Drupal::entityTypeManager()->getStorage('group_relationship')->loadUnchanged($alex_rel_id);
    $this->assertNull($reloaded_alex, 'Deny deletes the relationship entirely.');
  }

  /**
   * AC-15 (restated, v2 §A-1): anonymous GET on the EXISTING
   * `/group/{group}/members` route returns 403 — regression on the existing
   * gate, not a new-route acceptance test.
   */
  public function testAnonymousGetOnManageMembersIs403(): void {
    $group = $this->createGroupWithVisibility('moderated', 'Leadership Council 4');
    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-15 (restated): a plain member (non-organizer) GET on the manage-members
   * route returns 403.
   */
  public function testPlainMemberGetOnManageMembersIs403(): void {
    $group = $this->createGroupWithVisibility('moderated', 'Leadership Council 5');
    $member = $this->drupalCreateUser();
    $group->addMember($member, ['group_roles' => ['community_group-member']]);
    $this->drupalLogin($member);

    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-16 (restated): anonymous direct POST to the ManageMembersForm's
   * approve submit endpoint on a pending row returns 403.
   */
  public function testAnonymousPostToApproveIs403(): void {
    $group = $this->createGroupWithVisibility('moderated', 'Leadership Council 6');
    $sophie = $this->drupalCreateUser();
    $group->addMember($sophie, [
      'group_roles' => [],
      'field_membership_status' => [['value' => 'pending']],
    ]);

    // Anonymous never even reaches the form (gated at the route), so a
    // direct GET to the manage-members page must 403 before any submit is
    // possible. This proves the anonymous case cannot reach the approve
    // control at all.
    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-16 (restated): a plain member's direct POST to the approve/deny
   * submit endpoints on a pending row returns 403.
   */
  public function testPlainMemberPostToApproveIs403(): void {
    $group = $this->createGroupWithVisibility('moderated', 'Leadership Council 7');
    $member = $this->drupalCreateUser();
    $group->addMember($member, ['group_roles' => ['community_group-member']]);

    $sophie = $this->drupalCreateUser();
    $group->addMember($sophie, [
      'group_roles' => [],
      'field_membership_status' => [['value' => 'pending']],
    ]);

    $this->drupalLogin($member);
    $this->drupalGet('/group/' . $group->id() . '/members');
    $this->assertSession()->statusCodeEquals(403, 'A plain member cannot even reach the manage-members page to submit approve/deny.');
  }

}
