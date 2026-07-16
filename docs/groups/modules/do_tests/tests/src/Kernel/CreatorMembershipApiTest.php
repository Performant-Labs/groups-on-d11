<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\group\Entity\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * RA2 (#36 / B2) — creator auto-membership is form-only: the API contrast.
 *
 * Group 4.x moved creator auto-membership into the create *form* only (CR
 * 2026-04-24). The programmatic entity-API path — `Group::create()->save()` —
 * no longer adds the creator as a member, even when the group type declares
 * `creator_membership: TRUE`. Any code that builds a group programmatically and
 * expects the creator to be a member is silently broken: the group is memberless
 * until `addMember()` is called explicitly.
 *
 * A3's Phase2Test::testProgrammaticSaveDoesNotAutoAddCreator already proves the
 * negative path via the base's community_group-defaulting createGroup() helper.
 * This is a focused, self-contained regression test that pins the *contrast*
 * crisply in one place and does NOT depend on the base helper's defaults: it
 * builds the group from raw storage values (creator_membership honoured, uid
 * set) so the assertion cannot be masked by a helper change, then shows that
 * addMember() — and only addMember() — establishes the membership.
 *
 * The form half of the contrast (the add form DOES add the creator) lives in the
 * functional CreatorMembershipFormTest, which needs the real request stack.
 *
 * Silent-regression surface (grep-backed, do_* modules, non-test code):
 * - No `do_*` module creates a Group *entity* programmatically — every
 *   `getStorage('group')` call in the modules is a `->load()`
 *   (do_group_language LanguageNegotiationGroup, do_group_mission
 *   GroupMissionBlock, do_multigroup DoMultigroupHooks). So none is directly
 *   exposed to the memberless-creator trap today.
 * - The only module that *creates* group relationships programmatically is
 *   `do_multigroup` (DoMultigroupHooks::nodeFormSubmit, ~L197:
 *   `getStorage('group_relationship')->create([type,gid,entity_id])->save()`),
 *   and it creates group_node (content) relationships for cross-posting — never
 *   memberships — so it neither needs nor omits creator membership.
 * - Therefore the regression is a *future* trap: any new code that does
 *   `Group::create()->save()` and assumes the creator is a member must call
 *   `addMember()` explicitly. This test is the guard for that behavior.
 *
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class CreatorMembershipApiTest extends GroupsKernelTestBase {

  /**
   * Group::create()->save() adds no creator membership, even with the flag set.
   *
   * Builds the group straight from storage (not the base createGroup() helper)
   * with an explicit creator uid, on a group type whose `creator_membership` is
   * TRUE, and asserts the group is memberless. This is the API path the form
   * path lacks — the whole point of RA2.
   */
  public function testApiSaveCreatesNoCreatorMembership(): void {
    // Precondition: the group type really does declare creator_membership TRUE,
    // so we are testing the form-only behavior and not a disabled feature.
    $this->assertTrue(
      (bool) $this->groupType->creatorGetsMembership(),
      'The community_group type declares creator_membership: TRUE.',
    );

    $creator = $this->getCurrentUser();
    $group = Group::create([
      'type' => self::GROUP_TYPE_ID,
      'label' => 'API-created group',
      'uid' => $creator->id(),
    ]);
    $group->save();

    // getMember() returns FALSE (not NULL) for a non-member.
    $this->assertFalse(
      (bool) $group->getMember($creator),
      'Group::create()->save() must NOT auto-add the creator (form-only in 4.x).',
    );
    $this->assertCount(
      0,
      $group->getMembers(),
      'A programmatically saved group has zero memberships.',
    );
  }

  /**
   * addMember() is what establishes the creator membership on the API path.
   *
   * The other half of the contrast: once addMember() is called explicitly, the
   * creator IS a member and the count rises to one. This is the fix a
   * programmatic caller must apply.
   */
  public function testAddMemberEstablishesCreatorMembership(): void {
    $creator = $this->getCurrentUser();
    $group = Group::create([
      'type' => self::GROUP_TYPE_ID,
      'label' => 'API-created group needing explicit membership',
      'uid' => $creator->id(),
    ]);
    $group->save();

    // Guard: still memberless before the explicit call.
    $this->assertCount(0, $group->getMembers(), 'Memberless before addMember().');

    $this->addMember($group, $creator);

    $this->assertNotNull(
      $group->getMember($creator),
      'addMember() adds the creator as a member on the API path.',
    );
    $this->assertCount(
      1,
      $group->getMembers(),
      'Exactly one membership exists after the explicit addMember() call.',
    );
  }

}
