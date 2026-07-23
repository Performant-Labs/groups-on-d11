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
 * Phase 6.5 (diff-gate B-2 repair): the raw Referer header is
 * attacker-controlled (a request can carry any `Referer:` value it likes),
 * so redirecting to it unconditionally is an open redirect. `redirectBack()`
 * now only trusts the Referer when its scheme+host+port matches the CURRENT
 * request's own scheme+host+port — an external Referer falls back to
 * `<front>` instead of being followed.
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
   * Phase 6.5 (diff-gate B-2 repair): the Referer header is
   * attacker-controlled, so it is only trusted when it is same-origin with
   * the current request (see {@see self::isSameOriginReferer()}) — an
   * off-site (or malformed) Referer falls back to `<front>` rather than
   * being followed, closing the open-redirect vector a raw
   * `new RedirectResponse($referer)` would otherwise expose.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request, or NULL if unavailable.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response.
   */
  private function redirectBack(?Request $request): RedirectResponse {
    $referer = $request?->headers->get('referer');
    if ($request !== NULL && !empty($referer) && $this->isSameOriginReferer($referer, $request)) {
      return new RedirectResponse($referer);
    }
    return $this->redirect('<front>');
  }

  /**
   * Determines whether $referer is same-origin with the current $request.
   *
   * Compares scheme, host, and port as separate parsed components against
   * the current request's own `getScheme()`/`getHost()`/`getPort()` — never
   * a raw string prefix/substring check, so a Referer that merely happens to
   * start with the site's domain as a substring (e.g.
   * `https://example.com.attacker.test/`) is correctly rejected, and default
   * ports (80 for http, 443 for https) compare equal to an implicit/absent
   * port on either side.
   *
   * @param string $referer
   *   The raw `Referer` header value.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE if $referer is same-origin with the current request.
   */
  private function isSameOriginReferer(string $referer, Request $request): bool {
    $referer_scheme = parse_url($referer, PHP_URL_SCHEME);
    $referer_host = parse_url($referer, PHP_URL_HOST);

    if (!is_string($referer_scheme) || $referer_scheme === '' || !is_string($referer_host) || $referer_host === '') {
      // No scheme/host at all (e.g. a relative Referer, or an unparsable
      // value) — never trust it; the caller falls back to `<front>`.
      return FALSE;
    }

    if (strtolower($referer_scheme) !== strtolower($request->getScheme())) {
      return FALSE;
    }

    if (strtolower($referer_host) !== strtolower($request->getHost())) {
      return FALSE;
    }

    $referer_port = parse_url($referer, PHP_URL_PORT);
    $default_port = ($referer_scheme === 'https') ? 443 : 80;
    $referer_effective_port = $referer_port ?? $default_port;
    $request_effective_port = $request->getPort() ?? $default_port;

    return (int) $referer_effective_port === (int) $request_effective_port;
  }

}
