<?php

declare(strict_types=1);

namespace Drupal\do_group_extras\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Shared `_custom_access` callback for the `do_group_extras.restore` route.
 *
 * Restore is only ever meaningful on a group currently tagged with the
 * "Archive" `field_group_type` term (mirrors the same Archive-detection
 * check {@see \Drupal\do_group_extras\Hook\DoGroupExtrasHooks} uses in its
 * `preprocessGroup()` and `nodeAccess()` methods) — a non-archived group has
 * nothing to restore, so it is denied the same as an unprivileged user
 * (single denial path; a 404 here would leak the group's existence to an
 * unauthorized caller).
 *
 * Privilege is granted by `'edit group'` (the group-settings-scope
 * permission actually held by the `community_group-organizer` role —
 * restore *is* an edit of group settings) OR the site-wide
 * `'administer group'` escape hatch, matching
 * {@see \Drupal\do_group_membership\Controller\ManageMembersController}'s
 * `access()` shape. `Groups-Moderate` (a synchronized `admin: true`
 * outsider role) satisfies via the implicit group-admin path regardless of
 * the specific permission string.
 */
class RestoreGroupAccess {

  /**
   * Access callback for the `do_group_extras.restore` route.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group, upcast from the {group} route parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    $isArchived = $group->hasField('field_group_type')
      && !$group->get('field_group_type')->isEmpty()
      && $group->get('field_group_type')->entity?->label() === 'Archive';

    $access = $isArchived && ($group->hasPermission('edit group', $account) || $account->hasPermission('administer group'));

    return AccessResult::allowedIf($access)
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser();
  }

}
