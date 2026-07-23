<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_showcase\ShowcaseCatalog;

/**
 * Route-level access check for `do_showcase.persona_switch`.
 *
 * Brief-amendments.md Amendment 3/4 (A's finding #4 — "Do NOT rely on an
 * in-controller `if` alone — one refactor slip and the guard is gone"): this
 * is a route-level `access_check`-tagged service, checked BEFORE
 * `PersonaSwitchController::switch()` ever runs, so a crafted POST with an
 * unrecognized or uid-1-resolving persona id is denied without ever calling
 * `user_login_finalize()`.
 *
 * Three rules, in order:
 *   1. `$persona === 'anonymous'` is ALWAYS allowed (the switch-back target,
 *      regardless of the current session's own state).
 *   2. `ShowcaseCatalog::personaSpec($persona)` NULL (not one of the 4
 *      allowlisted ids) is denied.
 *   3. If the persona's `uname` resolves to an existing user account whose
 *      uid is 1, denied — unconditionally, regardless of what allowlist
 *      entry it happens to collide with (brief AC: "uid 1 is unreachable via
 *      any masquerade path"). Per T's handoff-T-red.md recommendation: this
 *      compares by UID, not by uname STRING, because BrowserTestBase's
 *      install-time uid-1 account may have an unpredictable/blank uname —
 *      comparing the resolved account's id() is the robust design either
 *      way.
 */
final class PersonaAccessCheck implements AccessInterface {

  public function __construct(
    private readonly ShowcaseCatalog $showcaseCatalog,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks access for the `do_showcase.persona_switch` route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match (unused directly — accepted so Drupal's access-check
   *   argument resolver can supply it; kept for interface parity with other
   *   `access_check`-tagged services and so a Kernel test can call this
   *   method exactly as the real access-check runner would).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting the switch (unused directly today — the
   *   allowlist/uid-1 guard does not vary per requester, matching the
   *   wireframe's "this never asks for a password" / fully-public-personas
   *   model — accepted for interface parity and forward-compat if a future
   *   story scopes the switcher by requester).
   * @param string $persona
   *   The `{persona}` route parameter — a persona id, or 'anonymous'.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed if $persona is 'anonymous', or an allowlisted persona id whose
   *   resolved account (if any) is not uid 1. Forbidden otherwise.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account, string $persona): AccessResultInterface {
    if ($persona === 'anonymous') {
      // Switch-back is always allowed, regardless of the CURRENT session —
      // every persona must be able to return to Anonymous.
      return AccessResult::allowed()->addCacheContexts(['url.path']);
    }

    $spec = $this->showcaseCatalog->personaSpec($persona);
    if ($spec === NULL) {
      // Not one of the 4 allowlisted ids.
      return AccessResult::forbidden('Unrecognized persona id.')->addCacheContexts(['url.path']);
    }

    $uname = $spec['uname'] ?? NULL;
    if ($uname !== NULL) {
      $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $uname]);
      $target_user = reset($users);
      if ($target_user && (int) $target_user->id() === 1) {
        // The uid-1 guard is unconditional: even if a future allowlist edit
        // accidentally pointed a persona's uname at uid 1, this check
        // forecloses it — never compare by uname string (an install's uid-1
        // account name is not guaranteed/predictable).
        return AccessResult::forbidden('uid 1 is never a valid persona-switch target.')
          ->addCacheableDependency($target_user)
          ->addCacheContexts(['url.path']);
      }
    }

    return AccessResult::allowed()->addCacheContexts(['url.path']);
  }

}
