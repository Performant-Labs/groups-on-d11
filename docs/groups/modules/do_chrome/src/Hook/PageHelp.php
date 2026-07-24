<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\do_chrome\HelpText;

/**
 * #126 (SD-1): page-level "what am I looking at" ⓘ tooltips.
 *
 * Adds a single ⓘ trigger after the H1 on each of 5 covered pages
 * (site-wide stream, all-groups directory, and a group's Stream / Events /
 * Members tabs) plus pre-registers 5 W2 route/key pairs as inert map entries
 * (their routes do not exist yet; the point of pre-registering them now is
 * that a future W2 story does not need to edit do_chrome — see
 * self::getRouteMap()).
 *
 * The route-name => HelpText-key map returned by self::getRouteMap() IS the
 * allowlist (A warn #1, absorbed into brief.md's Design section): unless the
 * current route is a literal key in that map, preprocessPageTitle() returns
 * immediately without touching $variables['title_suffix']. This is a
 * default-deny gate, not a denylist, so the ⓘ can never leak onto an
 * unregistered route (/admin/*, node edit forms, /user/login, etc.) simply
 * because no one thought to exclude it.
 *
 * Injection point is `hook_preprocess_page_title`, which hooks into core's
 * `page-title.html.twig` `title_suffix` slot — no template override needed,
 * and the trigger lands immediately after the H1 by that template's own
 * convention.
 *
 * The rendered trigger reuses the exact
 * \Drupal\do_chrome\Hook\GroupTypeContentHelp::infoTrigger() shape (span +
 * `do-chrome-info` class + tabindex="0" + role="note" + aria-label +
 * data-do-tooltip + the ⓘ U+24D8 glyph), with an added `page-help-info`
 * class for this surface. Per brief.md's Reuse map, this duplicates that
 * tiny private method rather than extracting a shared helper — every
 * existing B-story surface (#88/#89/#90) ships its own trivial
 * `infoTrigger()`, and matching that established convention beats a
 * cross-cutting refactor outside this story's scope.
 *
 * @see \Drupal\do_chrome\Hook\GroupTypeContentHelp::infoTrigger()
 * @see \Drupal\do_chrome\HelpText
 */
class PageHelp {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Returns the 10-entry route-name => HelpText-key allowlist.
   *
   * 5 LIVE entries (must render now, per brief.md §Scope, verified against
   * the assembled `config/sync/views.view.*.yml` page-display ids) + 5 W2
   * pre-registered entries (their views/routes do not exist yet; entries
   * whose route never resolves at request time simply never get matched by
   * preprocessPageTitle(), so they render nothing — no ⓘ, no error).
   *
   * This is the SAME map preprocessPageTitle() looks up, not a second,
   * independently hand-maintained copy that could drift.
   *
   * @return array<string, string>
   *   A map of route name => HelpText surface-id key.
   */
  public function getRouteMap(): array {
    return [
      // --- LIVE now (brief.md §Scope "Covered now") --------------------
      'view.activity_stream.page_1' => 'page.stream',
      'view.all_groups.page_1' => 'page.all_groups',
      'view.group_content_stream.page_1' => 'page.group.stream',
      'view.group_events.page_1' => 'page.group.events',
      'do_group_membership.manage_members' => 'page.group.members',
      // --- W2 pre-registered (inert until the route exists) ------------
      'view.my_feed.page_1' => 'page.my_feed',
      'view.following_feed.page_1' => 'page.following',
      'view.trending.page_1' => 'page.trending',
      'view.my_feed_events.page_1' => 'page.my_feed_events',
      // #112 (ST-3): the /my-feed/events route is a hand-authored controller
      // route (do_streams.my_events, see do_streams/do_streams.routing.yml),
      // not a Views page display — its route name differs from the
      // 'view.<id>.page_1' convention above, so it needs its own entry to
      // resolve to the same page.my_feed_events HelpText copy.
      'do_streams.my_events' => 'page.my_feed_events',
      // #110 (ST-1): identical shape to the #112 my_events aliasing above —
      // /my-feed is a hand-authored controller route (do_streams.my_feed,
      // see do_streams/do_streams.routing.yml), not the Views-page-display
      // route name the 'view.my_feed.page_1' W2 entry above anticipates.
      // Without this alias the page-level ⓘ never renders on /my-feed (the
      // pre-registered 'view.my_feed.page_1' key stays inert exactly as
      // that entry's own docblock says an unresolved pre-registered route
      // should) — SD-4's streams-help.spec.ts:69 asserts the ⓘ is present
      // on /my-feed regardless of ROUTE NAME, so we alias the actual route
      // to the SAME 'page.my_feed' HelpText copy, mirroring the my_events
      // pattern above rather than editing the W2 entry itself (which stays
      // a documented placeholder for a possible future Views-page display).
      'do_streams.my_feed' => 'page.my_feed',
      'view.profile_stream.page_1' => 'page.profile_stream',
    ];
  }

  /**
   * Adds a page-level ⓘ trigger to `title_suffix` on covered routes only.
   *
   * Default-deny: if the current route name is not a literal key in
   * self::getRouteMap(), this returns immediately without mutating
   * $variables in any way. If the mapped key resolves to empty copy (e.g. a
   * key that does not yet exist in HelpText — should not happen once this
   * story ships all 10, but guards the same way HelpText::get()'s contract
   * already guards an unknown key), this also returns immediately, silently.
   *
   * @param array $variables
   *   The page_title preprocess variables, passed by reference. On a match,
   *   `$variables['title_suffix']['do_chrome_page_help']` is set to the
   *   trigger render array.
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    $route_name = $this->routeMatch->getRouteName();
    $map = $this->getRouteMap();
    if ($route_name === NULL || !isset($map[$route_name])) {
      return;
    }

    $copy = HelpText::get($map[$route_name]);
    if ($copy === '') {
      return;
    }

    $variables['title_suffix']['do_chrome_page_help'] = $this->infoTrigger($copy);
  }

  /**
   * Builds a render array for a hoverable page-level "ⓘ" tooltip trigger.
   *
   * Identical shape to
   * \Drupal\do_chrome\Hook\GroupTypeContentHelp::infoTrigger(), with an
   * additional `page-help-info` class distinguishing this surface. The
   * shared do_chrome/tooltips behaviour (already attached globally by
   * DoChromeHooks::pageAttachments()) binds tippy.js to `data-do-tooltip`,
   * so no library is attached here.
   *
   * @param string $copy
   *   The plain-text tooltip copy (allowHTML is disabled downstream).
   *
   * @return array
   *   A render array for an inline "ⓘ" trigger span.
   */
  private function infoTrigger(string $copy): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      // U+24D8 CIRCLED LATIN SMALL LETTER I — the "ⓘ" info glyph.
      '#value' => 'ⓘ',
      '#attributes' => [
        'class' => ['do-chrome-info', 'page-help-info'],
        'tabindex' => '0',
        'role' => 'note',
        'aria-label' => $copy,
        'data-do-tooltip' => $copy,
      ],
    ];
  }

}
