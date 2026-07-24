<?php

declare(strict_types=1);

namespace Drupal\do_streams\Hook;

use Drupal\views\ViewExecutable;

/**
 * Stream switcher chrome (#115 ST-6): tabs above the 4 sibling stream pages.
 *
 * Renders Global | My Feed | Following | Trending tabs (survey.md's "one
 * engine with tabs" demo moment) above any view listed in
 * self::ATTACH_VIEW_IDS, via a `preprocess_views_view` handler that prepends
 * a `#theme => 'stream_switcher'` render array to `$variables['header']` and
 * attaches the `do_streams/stream-switcher` library — never rewriting the
 * sibling views' own shipped display config (survey.md "Do NOT rewrite
 * ST-1/ST-2/ST-4 view configs"; brief.md §Plan step 2/§Explicitly out of
 * scope).
 *
 * `group_content_stream` (the per-group `/group/{id}/stream` page) is
 * deliberately ABSENT from self::ATTACH_VIEW_IDS — the group stream keeps
 * its own page identity with no switcher (issue #115 scope; wireframe
 * Screen 4 negative-space control).
 *
 * Mirrors DoStreamsHooks::preprocessViewsView()'s existing
 * view-id-guarded-attach convention (added by #111 for `following_feed`),
 * per handoff-A.md's reuse-discipline finding — the only difference is this
 * class guards on a SET of view ids rather than a single one, because the
 * switcher must attach identically across all 4 sibling surfaces.
 *
 * REWORK (U's Phase 8 handoff, root cause #2): self::preprocessViewsView()
 * does NOT carry a `#[Hook('preprocess_views_view')]` attribute — Drupal's
 * `ModuleHandler::invoke()` throws `LogicException: "Module do_streams
 * should not implement preprocess_views_view more than once"` the moment TWO
 * separate classes in the same module both carry a
 * `#[Hook('preprocess_views_view')]` method, exactly the same class of bug
 * this module already hit (and fixed) for `#[Hook('theme')]` — see
 * DoStreamsHooks::theme()'s docblock. Kernel tests (StreamSwitcherHooksTest)
 * did not catch this because they instantiate `new StreamSwitcherHooks()`
 * directly and call preprocessViewsView() without ever booting the compiled
 * service container the way `ModuleHandler::invoke()` does on a real
 * request — confirmed via a live-site 500 (`LogicException`) on every
 * views-rendering route, reproduced and diagnosed in handoff-U.md.
 *
 * self::preprocessViewsView() therefore stays a public instance method with
 * the SAME name/signature/body the kernel test calls directly (`new
 * StreamSwitcherHooks())->preprocessViewsView($variables)`), but the ACTUAL
 * `#[Hook('preprocess_views_view')]` registration lives solely on
 * {@see \Drupal\do_streams\Hook\DoStreamsHooks::preprocessViewsView()}, which
 * delegates to this class's method (via a fresh instance) for any view in
 * self::ATTACH_VIEW_IDS, in addition to DoStreamsHooks' own following-feed
 * attachment logic. This mirrors the theme() consolidation exactly: one
 * class holds the single hook-tagged method, a sibling class keeps its own
 * helper methods/constants and is invoked from there.
 *
 * The `stream_switcher` theme hook this template needs is registered on
 * {@see \Drupal\do_streams\Hook\DoStreamsHooks::theme()}, NOT here — Drupal
 * throws `LogicException: "Module do_streams should not implement theme
 * more than once"` if two classes in the same module both carry a
 * `#[Hook('theme')]` method (confirmed at F-implementation time via a
 * kernel-test regression; see DoStreamsHooks::theme()'s docblock for the
 * full explanation).
 *
 * @see \Drupal\do_streams\Hook\DoStreamsHooks::getScopeRegistry()
 * @see \Drupal\do_streams\Hook\DoStreamsHooks::preprocessViewsView()
 * @see \Drupal\do_streams\Hook\DoStreamsHooks::theme()
 */
class StreamSwitcherHooks {

  /**
   * Scope id => route path, the ONE place a sibling story's path is named.
   *
   * If a sibling ships at a different path than planned, this is the single
   * line to fix (brief.md §Plan step 2). Order matches
   * DoStreamsHooks::getScopeRegistry() (Global / My Feed / Following /
   * Trending) purely for readability — the tab list's actual order is
   * always driven by iterating the registry, never this map.
   */
  public const ROUTE_MAP = [
    'global' => '/stream',
    'my_feed' => '/my-feed',
    'following' => '/following',
    'trending' => '/trending',
  ];

  /**
   * Views IDs the switcher attaches to.
   *
   * `activity_stream` (#109, ships with this story's own dependency) and
   * `following_feed` (#111 ST-2, already merged — confirmed via
   * `docs/groups/config/views.view.following_feed.yml`) are real, installed
   * view ids on this branch.
   *
   * `my_feed` and `trending` are best-guess sibling view ids for #110 (ST-1)
   * and #113 (ST-4), which have not merged yet at F-implementation time on
   * this branch — CONFIRMED (not merely guessed) by cross-referencing both
   * sibling issues' own "Owns" sections
   * (`docs/groups/config/views.view.my_feed.yml`,
   * `docs/groups/config/views.view.trending.yml`) and
   * `DoChromeHooks\PageHelp::getRouteMap()`'s own W2 pre-registered entries
   * (`view.my_feed.page_1`, `view.trending.page_1`) — three independent
   * sources agree, so `trending` (NOT the brief's placeholder
   * `trending_content` guess) is used here. #112 (ST-3, `my_events` at
   * `/my-feed/events`) is a DIFFERENT surface from My Feed's own
   * `/my-feed` — its view id is deliberately not in this list.
   *
   * If either sibling ships under a different view id after all, the tab
   * list still degrades gracefully (the scope is simply omitted via the
   * route-existence check in self::buildTabList() until the id here is
   * corrected) — a one-line fix, never a broken render.
   */
  public const ATTACH_VIEW_IDS = [
    'activity_stream',
    'following_feed',
    'my_feed',
    'trending',
  ];

  /**
   * The anonymous-viewer scope allowlist (brief.md acceptance criterion).
   *
   * Hardcoded per survey.md's Key finding #3: the spec is explicit ("Tabs
   * render only for routes the viewer can access (anonymous: Global +
   * Trending)"), and a per-scope permission would over-engineer POC scope.
   */
  public const ANONYMOUS_ALLOWLIST = ['global', 'trending'];

  /**
   * Builds the switcher's tab list for the current viewing user.
   *
   * Filters DURING registry iteration (handoff-A.md finding §3: pin to
   * DoStreamsHooks::getScopeRegistry() order, filter each id against the
   * allowlist/route-existence checks as it is visited, rather than building
   * every tab then subtracting) — a single iteration pattern, one source of
   * truth for order.
   *
   * A scope is OMITTED (never rendered disabled) when EITHER:
   *  - the viewer is anonymous AND the scope is not in
   *    self::ANONYMOUS_ALLOWLIST; or
   *  - the scope's mapped route (self::ROUTE_MAP) does not currently exist
   *    (queried live via the `router.route_provider` service, so a sibling
   *    story landing later activates its tab with no code change here).
   *
   * @param string $currentPath
   *   The current request's path (e.g. '/following'), used to flag the
   *   active tab. Defaults to '/stream' (Global) — matching the Global
   *   scope's own route, so a caller that omits this argument gets the
   *   same active-tab result as an actual `/stream` request.
   *
   * @return array
   *   A list of tabs, each `['id' => string, 'label' =>
   *   \Drupal\Core\StringTranslation\TranslatableMarkup, 'route' => string,
   *   'active' => bool]`, in DoStreamsHooks::getScopeRegistry() order.
   */
  public static function buildTabList(string $currentPath = '/stream'): array {
    $is_authenticated = \Drupal::currentUser()->isAuthenticated();
    /** @var \Drupal\Core\Routing\RouteProviderInterface $route_provider */
    $route_provider = \Drupal::service('router.route_provider');

    $tabs = [];
    foreach (DoStreamsHooks::getScopeRegistry() as $id => $label) {
      if (!$is_authenticated && !in_array($id, self::ANONYMOUS_ALLOWLIST, TRUE)) {
        continue;
      }

      $route_path = self::ROUTE_MAP[$id] ?? NULL;
      if ($route_path === NULL || $route_provider->getRoutesByPattern($route_path)->all() === []) {
        continue;
      }

      $tabs[] = [
        'id' => $id,
        'label' => $label,
        'route' => $route_path,
        'active' => $route_path === $currentPath,
      ];
    }

    return $tabs;
  }

  /**
   * Attaches the switcher above any view listed in self::ATTACH_VIEW_IDS.
   *
   * A no-op for every other view — most importantly `group_content_stream`
   * (group stream shows no switcher; brief.md acceptance criterion). The
   * render array is PREPENDED to `$variables['header']` (built as a new
   * array with the switcher first, followed by whatever the view's own
   * header area already produced) so it renders strictly ABOVE the view's
   * results, never wrapping the card markup (`/stream` cards look
   * unchanged — brief.md acceptance criterion; neither
   * `activity_stream.yml` nor `following_feed.yml` configures a header
   * area, so in practice `$variables['header']` is empty when this hook
   * runs, but the merge is written to be safe regardless).
   *
   * NOT `#[Hook('preprocess_views_view')]`-tagged (see class docblock's
   * REWORK note) — invoked by
   * {@see \Drupal\do_streams\Hook\DoStreamsHooks::preprocessViewsView()},
   * which holds the single, module-wide hook registration for this hook
   * name, and directly by StreamSwitcherHooksTest's kernel coverage.
   */
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'] ?? NULL;
    if (!$view instanceof ViewExecutable || !in_array($view->id(), self::ATTACH_VIEW_IDS, TRUE)) {
      return;
    }

    $current_path = \Drupal::service('path.current')->getPath();
    $tabs = self::buildTabList($current_path);

    $switcher = [
      '#theme' => 'stream_switcher',
      '#tabs' => $tabs,
    ];

    $variables['header'] = ['do_streams_stream_switcher' => $switcher] + ($variables['header'] ?? []);
    $variables['#attached']['library'][] = 'do_streams/stream-switcher';
  }

}
