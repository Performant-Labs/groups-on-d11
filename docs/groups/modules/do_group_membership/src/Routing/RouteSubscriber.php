<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds a visibility-aware access requirement to `drupal/group`'s join route.
 *
 * #121 SC-2 (AC-3/AC-11): `entity.group.join` (`/group/{group}/join`, shipped
 * by `drupal/group` contrib — the route #95's instant-join flow lives on) is
 * gated ONLY by two generic route requirements, `_group_permission: 'join
 * group'` and `_group_member: 'FALSE'` (see
 * `web/modules/contrib/group/group.routing.yml`) — neither of which
 * considers a group's `field_group_visibility`. Both requirements are
 * satisfied by EVERY authenticated non-member (the `outsider_view` role's
 * baseline `join group` grant), so without this alter, the route stays
 * reachable (200) on an `invite_only` group, which fails AC-3's "sees NO
 * Join / Request path... direct POST to the request route is 403" — the
 * brief's Objective explicitly requires enforcement "via Group access...
 * not by hiding UI alone," and this is the route where that enforcement is
 * currently missing entirely (`Drupal\group\Form\GroupJoinForm` /
 * `GroupRelationshipForm` never calls `$entity->access('create')` — the
 * relationship's entity-create access, gated by this module's
 * `Drupal\do_group_membership\Hook\GroupAccessHook`, is simply never
 * consulted on this particular route).
 *
 * Symfony's `AccessManager` combines every registered access checker for a
 * route with logical AND, so adding a THIRD requirement here
 * (`_custom_access`) alongside the two `drupal/group`-owned ones narrows
 * access without replacing or duplicating anything `drupal/group` already
 * does — this is the standard, minimal-footprint Drupal idiom for gating a
 * route owned by another module without touching that module's source
 * (which is out of scope/forbidden for this project — `web/modules/contrib`
 * is vendor code).
 *
 * `entity.group.leave` is intentionally left untouched: leaving a group you
 * are already a member of is never gated by join policy.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.group.join');
    if ($route === NULL) {
      // `drupal/group` didn't define this route (e.g. a future contrib
      // version renames or removes it) — nothing to alter. Fail open rather
      // than fatal, matching this module's existing collision-handling
      // posture (do_group_membership.install's guarded strip).
      return;
    }

    $route->setRequirement('_custom_access', '\Drupal\do_group_membership\Controller\ManageMembersController::joinRouteAccess');
  }

}
