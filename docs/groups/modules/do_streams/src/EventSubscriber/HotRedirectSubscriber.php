<?php

declare(strict_types=1);

namespace Drupal\do_streams\EventSubscriber;

use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects `/hot` to `/trending` (#115 ST-6) once the `/trending` route ships.
 *
 * "Hot" becomes a RANKING, not a destination (#113 ST-4 brief's framing):
 * this subscriber recommends `/hot` visitors to `/trending` instead, without
 * deleting or altering `views.view.hot_content.yml` (brief.md §Plan step 6;
 * survey.md Key finding #2). Guarded so it is a NO-OP — `/hot` falls through
 * to its existing `hot_content` view page exactly as it does today — until
 * sibling #113 merges and registers a real `/trending` route.
 *
 * Implemented as a `KernelEvents::REQUEST` subscriber (not a route alter)
 * per handoff-A.md's notes on the `/hot` redirect layer: altering
 * `hot_content`'s own page-display route would cross ownership into sibling
 * ST-4 territory (survey.md "Do NOT rewrite ST-1/ST-2/ST-4 view configs"),
 * whereas a subscriber lives entirely inside `do_streams` and touches no
 * sibling YAML.
 *
 * Priority 33 — one step ABOVE Symfony's `RouterListener`
 * (`KernelEvents::REQUEST => ['onKernelRequest', 32]`), so this subscriber's
 * response short-circuits the request (via
 * `RequestEvent::setResponse()`/`stopPropagation()`) before Drupal's own
 * router attempts to resolve `/hot` at all.
 */
class HotRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The `/hot` path this subscriber matches on.
   */
  protected const HOT_PATH = '/hot';

  /**
   * The path `/hot` redirects to, once it exists.
   */
  protected const TRENDING_PATH = '/trending';

  public function __construct(
    protected readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 33],
    ];
  }

  /**
   * Redirects `/hot` to `/trending` if-and-only-if `/trending` now exists.
   *
   * A sub-request (e.g. a block/form AJAX callback) is never redirected —
   * only the main request a browser navigation produces.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $path = rtrim($event->getRequest()->getPathInfo(), '/');
    if ($path !== self::HOT_PATH) {
      return;
    }

    if ($this->routeProvider->getRoutesByPattern(self::TRENDING_PATH)->all() === []) {
      // `/trending` (#113 ST-4) has not merged yet on this branch — fall
      // through to the existing `/hot` (hot_content) page, unchanged.
      return;
    }

    $event->setResponse(new RedirectResponse(self::TRENDING_PATH, 302));
  }

}
