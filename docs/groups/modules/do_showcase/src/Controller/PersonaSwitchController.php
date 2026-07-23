<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\do_showcase\ShowcaseCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the `do_showcase.persona_switch` route (#120 SC-1).
 *
 * Brief-amendments.md Amendment 3 (A blocker #3, resolved): NO
 * `drupal/masquerade` dependency — the plan's "always full logout+login"
 * decision bypasses every masquerade guarantee, so the module was dropped
 * entirely rather than enabled with no consumer. This bespoke controller
 * implements exactly that flow:
 *   - `$persona === 'anonymous'`: `user_logout()` the current session (if
 *     authenticated) and redirect back — the switch-back path, allowed on
 *     GET (a plain `<a>` link in the persistent banner).
 *   - Any other persona: MUST be POST (route-level `methods: [GET, POST]`
 *     + this method's own guard returns a 405 on GET — Amendment 4). Loads
 *     the target user by uname (via `ShowcaseCatalog::personaSpec()`),
 *     `user_login_finalize()`s the session into that account, and redirects
 *     back.
 *
 * Access (uid-1 guard + allowlist enforcement) is handled ENTIRELY by the
 * route-level `_persona_access` check (`\Drupal\do_showcase\Access\
 * PersonaAccessCheck`, brief-amendments.md Amendment 4) — this controller
 * never re-implements that guard; by the time `switch()` runs, the persona
 * id is already known-safe.
 *
 * Redirect target: back to the referring page (HTTP Referer), or `<front>`
 * if unavailable — wireframe.md §3/§7 open question #3 resolved: "always
 * redirect to prior URL, let 403 show if it happens" (POC-simplest default;
 * the personas' access is mostly additive relative to each other, so a
 * destination 403 is the rare case).
 *
 * The constructor-injected entity type manager is stored on
 * `$personaEntityTypeManager`, NOT `$entityTypeManager` — `ControllerBase`
 * already declares a NON-readonly protected `$entityTypeManager` property
 * (lazily populated by its own `entityTypeManager()` accessor); a
 * constructor-promoted `readonly` property of the same name on a subclass
 * is a PHP fatal redeclaration error ("Cannot redeclare non-readonly
 * property ... as readonly"), which only surfaces at runtime the first time
 * this controller is instantiated (a Kernel/Unit test that only checks the
 * class exists would not catch it — this was caught by
 * PersonaSwitcherDropdownTest actually invoking the route).
 */
final class PersonaSwitchController extends ControllerBase {

  public function __construct(
    private readonly ShowcaseCatalog $showcaseCatalog,
    private readonly EntityTypeManagerInterface $personaEntityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      new ShowcaseCatalog(),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * Switches the current session to $persona, or back to anonymous.
   *
   * @param string $persona
   *   The `{persona}` route parameter — 'anonymous', or one of the 4
   *   allowlisted persona ids (already verified safe by the route-level
   *   `_persona_access` check before this method runs).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect back to the referring page, or `<front>` if unavailable.
   */
  public function switch(string $persona): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();

    if ($persona === 'anonymous') {
      if ($this->currentUser()->isAuthenticated()) {
        user_logout();
      }
      return $this->redirectBack($request);
    }

    // The route-level access check has already confirmed $persona resolves
    // to a real, non-uid-1 allowlisted persona — this HTTP-method guard is
    // a defense-in-depth belt-and-suspenders on the controller itself
    // (Amendment 4: "a crafted GET must never log a visitor into an
    // account"), matching `PersonaSwitchControllerMethodTest`'s expectation
    // that a GET on a non-anonymous persona 405s even if a future route
    // edit ever loosened the `methods:` requirement.
    if ($request === NULL || !$request->isMethod('POST')) {
      throw new MethodNotAllowedHttpException(['POST'], 'Switching into a persona requires POST.');
    }

    $spec = $this->showcaseCatalog->personaSpec($persona);
    if ($spec === NULL || empty($spec['uname'])) {
      // The route access-check already denies an unrecognized persona id;
      // this is an unreachable-in-practice defensive branch (404, not a
      // silent redirect, so a broken future edit fails loudly).
      throw new NotFoundHttpException('Unrecognized persona.');
    }

    $users = $this->personaEntityTypeManager->getStorage('user')->loadByProperties(['name' => $spec['uname']]);
    $target_user = reset($users);
    if (!$target_user) {
      throw new NotFoundHttpException('The persona account is not seeded on this site.');
    }

    user_login_finalize($target_user);

    return $this->redirectBack($request);
  }

  /**
   * Redirects to the referring page, falling back to `<front>`.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, or NULL if unavailable.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  private function redirectBack(?Request $request): RedirectResponse {
    $referer = $request?->headers->get('referer');
    if (!empty($referer)) {
      return new RedirectResponse($referer);
    }
    return $this->redirect('<front>');
  }

}
