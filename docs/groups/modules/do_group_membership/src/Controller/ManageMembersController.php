<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Shared `_custom_access` callback for every `do_group_membership.*` route.
 *
 * Every action on the Manage-members surface is implemented as a Form
 * (ManageMembersForm, AddMemberForm, ChangeRoleForm, RemoveMemberForm) so
 * that every user-facing control is a real `<button>` submit element
 * (AC-7/AC-15) — this class exists solely to hold the access rule, which
 * every one of those routes shares.
 */
class ManageMembersController {

  /**
   * Access callback for every `do_group_membership.*` route.
   *
   * TRUE if $group->hasPermission('administer members', $account) (covers
   * Organizer, Moderator, and any Groups-Moderate synchronized-role user)
   * OR $account->hasPermission('administer group') (site-admin escape
   * hatch, matching the RUNBOOK's /admin/groups/pending precedent).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group, upcast from the {group} route parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(GroupInterface $group, AccountInterface $account) {
    $access = $group->hasPermission('administer members', $account) || $account->hasPermission('administer group');
    return AccessResult::allowedIf($access)
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheContexts(['url.path']);
  }

}
