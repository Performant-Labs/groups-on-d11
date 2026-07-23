<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared `_custom_access` callback for every `do_group_membership.*` route.
 *
 * Every action on the Manage-members surface is implemented as a Form
 * (ManageMembersForm, AddMemberForm, ChangeRoleForm, RemoveMemberForm) so
 * that every user-facing control is a real `<button>` submit element
 * (AC-7/AC-15) — this class exists solely to hold the access rule, which
 * every one of those routes shares.
 *
 * #121 SC-2 adds two peer access callbacks:
 *  - `requestJoinAccess()` for the new outsider-facing
 *    `do_group_membership.request_join` route. Per brief-response-v2 §A-2,
 *    this is the ONE surviving new route from the original plan, and it
 *    stays on the same `_custom_access` idiom as every other route in this
 *    module (never `_group_permission`).
 *  - `joinRouteAccess()`, layered onto `drupal/group`'s OWN
 *    `entity.group.join` route (`/group/{group}/join`, the #95 instant-join
 *    flow) via `Drupal\do_group_membership\Routing\RouteSubscriber` — see
 *    that class's docblock for why this alter is necessary (that route's
 *    two stock requirements, `_group_permission`/`_group_member`, never
 *    consider `field_group_visibility`, so without this addition an
 *    `invite_only` group's join route stays reachable for AC-3/AC-11).
 *
 * `_custom_access` callbacks are resolved via
 * `Drupal\Core\Utility\CallableResolver`, which instantiates an
 * un-registered class either through `ContainerInjectionInterface::create()`
 * (if implemented) or a bare `new $class()` otherwise — this class is not
 * registered in `do_group_membership.services.yml`, so it implements
 * `ContainerInjectionInterface` (the same DI pattern already used by every
 * Form class in this module) rather than relying on constructor-argument
 * autowiring that a bare `new` would not satisfy.
 */
class ManageMembersController implements ContainerInjectionInterface {

  public function __construct(
    protected GroupMembershipManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('do_group_membership.manager'),
    );
  }

  /**
   * Access callback for every `do_group_membership.*` organizer route.
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

  /**
   * Access callback for `do_group_membership.request_join` (#121 SC-2).
   *
   * Per brief-response-v2 §A-2: allowed only for an authenticated user who
   * is not already a member of $group (any status — active, pending, or
   * blocked, per A-R2-N1, so a pending requester or a blocked account
   * cannot re-reach the request form — checked via `$group->getMember()`,
   * which resolves a `group_relationship` at ANY status, not just active)
   * AND the group's `field_group_visibility` classifies (via
   * `GroupMembershipManager::joinPolicyFor()`) as `'request'` (i.e.
   * `moderated`). This is the route-level short circuit so an
   * `invite_only` or `open` group returns 403 at the URL itself, not
   * merely at form submit — the entity-create access gate
   * (`Drupal\do_group_membership\Hook\GroupAccessHook`) remains the source
   * of truth at entity-create time (defense in depth).
   *
   * `$group->getMember()` (not `getRelationshipsByEntity()`) is used
   * deliberately: it accepts an `AccountInterface` directly, matching the
   * type this callback actually receives at runtime (an `AccountProxy` for
   * the real HTTP request, not a loaded `User` entity) —
   * `getRelationshipsByEntity()` requires an `EntityInterface` and would
   * throw a `TypeError` if passed the raw account.
   *
   * Per A-R2-W1: `addCacheableDependency($group)` is kept UNCONDITIONAL
   * (not branched on the visibility check), so any mutation to the group
   * entity — including its `field_group_visibility` value — busts this
   * access result's cache via the group's own cache tags, mirroring the
   * existing `::access()` callback's contract exactly.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group, upcast from the {group} route parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function requestJoinAccess(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    $is_anonymous = $account->isAnonymous();
    $already_related = !$is_anonymous && $group->getMember($account) !== FALSE;
    $is_moderated = $this->manager->joinPolicyFor($group) === 'request';

    $access = !$is_anonymous && !$already_related && $is_moderated;

    return AccessResult::allowedIf($access)
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheContexts(['url.path']);
  }

  /**
   * Access callback layered onto `drupal/group`'s `entity.group.join` route.
   *
   * Wired via `Drupal\do_group_membership\Routing\RouteSubscriber`, which
   * adds this as a THIRD `_custom_access` requirement alongside
   * `entity.group.join`'s two stock requirements
   * (`_group_permission: 'join group'`, `_group_member: 'FALSE'`) —
   * Symfony's `AccessManager` combines every requirement with logical AND,
   * so this NARROWS access without replacing anything `drupal/group` does.
   *
   * `#95`'s instant-join flow (AC-1) is the `open`-visibility behavior
   * ONLY: an organizer/holder of `administer members` always bypasses (the
   * organizer's own AddMember path is unaffected regardless of visibility);
   * everyone else is allowed only when the group classifies as `'open'`
   * (`GroupMembershipManager::joinPolicyFor()`). `moderated` is excluded
   * here too (not just `invite_only`) — a moderated group's correct
   * self-service entry point is `/join-request` (`requestJoinAccess()`),
   * never the instant-join route, so a direct `/join` hit on a moderated
   * group is refused the same way an `invite_only` one is (AC-2's "pending,
   * not active" contract would otherwise be bypassable by going straight to
   * the instant-join URL).
   *
   * `addCacheableDependency($group)` stays unconditional, mirroring the
   * cache-metadata contract of every other access callback in this class
   * (A-R2-W1's rationale applies identically here).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group, upcast from the {group} route parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function joinRouteAccess(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    $access = $group->hasPermission('administer members', $account)
      || $this->manager->joinPolicyFor($group) === 'open';

    return AccessResult::allowedIf($access)
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheContexts(['url.path']);
  }

}
