<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

/**
 * Phase 2 behavioral tests — Group creation & membership models.
 *
 * The original Phase 2 suite only asserted assembled YAML *ships* (the
 * group_type / group_tags vocabularies, field_group_type, the all_groups /
 * pending_groups views, and `do_group_extras` in core.extension). Those are
 * config-artifact smoke checks, not behavior; they are retired here in favor of
 * behavioral coverage of what Phase 2 is really about — how membership is
 * modeled on a community_group. The view/vocabulary/field artifacts belong to
 * the clean-room config:import gate and Wave C per-module tests, not the
 * integration base.
 *
 * The headline Group 4.x membership change gets real coverage here: creator
 * auto-membership is now **form-only** (CR 2026-04-24). A programmatic
 * Group::create()->save() no longer auto-adds the creator as a member; only the
 * create *form* does. Silent-regression trap — asserted directly below.
 *
 * @group do_tests
 */
class Phase2Test extends GroupsKernelTestBase {

  /**
   * A programmatic group save does NOT auto-add the creator as a member.
   *
   * This is the CR 2026-04-24 form-only behavior: contrast with addMember(),
   * the explicit API path the base provides.
   */
  public function testProgrammaticSaveDoesNotAutoAddCreator(): void {
    $creator = $this->getCurrentUser();
    $group = $this->createGroup(['label' => 'No auto member', 'uid' => $creator->id()]);

    // getMember() returns FALSE (not NULL) when the account is not a member.
    $this->assertEmpty(
      $group->getMember($creator),
      'Group::create()->save() must NOT auto-add the creator (form-only in 4.x).'
    );
    $this->assertCount(0, $group->getMembers(), 'Programmatic save creates no memberships.');

    // The explicit API path adds the membership.
    $this->addMember($group, $creator);
    $this->assertNotNull($group->getMember($creator), 'addMember() adds the creator explicitly.');
  }

  /**
   * addMember() can grant group roles at membership creation.
   */
  public function testAddMemberWithRoles(): void {
    $role = $this->createGroupRole([
      'group_type' => self::GROUP_TYPE_ID,
      'scope' => \Drupal\group\PermissionScopeInterface::INDIVIDUAL_ID,
      'permissions' => [],
    ]);

    $group = $this->createGroup(['label' => 'Roled group']);
    $account = $this->createUser();
    $this->addMember($group, $account, [$role->id()]);

    $membership = $group->getMember($account);
    $this->assertNotNull($membership, 'Membership was created.');
    $role_ids = array_map(
      static fn ($r) => $r->id(),
      $membership->getRoles()
    );
    $this->assertContains($role->id(), $role_ids, 'Granted group role is present on the membership.');
  }

}
