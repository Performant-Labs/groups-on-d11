<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\do_chrome\HelpText;
use Drupal\do_chrome\Hook\PageHelp;
use Symfony\Component\Routing\Route;

/**
 * #126 SD-1 page-level ⓘ tooltips — `PageHelp` route-map + preprocess
 * contract.
 *
 * Pins brief.md's Design section: the route-name => HelpText-key map is the
 * ALLOWLIST (default-deny gate, A warn #1); `preprocessPageTitle()` looks up
 * `$routeMatch->getRouteName()` and returns immediately (no `title_suffix`
 * mutation) for any route not in the map. The five LIVE routes render the
 * exact `infoTrigger()`-shaped markup (do-chrome-info + page-help-info
 * classes, tabindex="0", role="note", aria-label, data-do-tooltip, the ⓘ
 * glyph) into `$variables['title_suffix']`.
 *
 * RED reason: `Drupal\do_chrome\Hook\PageHelp` does not exist yet (F has not
 * created docs/groups/modules/do_chrome/src/Hook/PageHelp.php) — every test
 * below fails with a class-not-found / autoload error at construction time,
 * which is the expected RED (there is no PageHelp code yet to assert
 * anything about).
 *
 * @group do_chrome
 */
final class PageHelpRouteMapTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'do_chrome'];

  /**
   * The 10-entry route => HelpText-key map brief.md §Scope requires: 5 LIVE
   * + 5 W2 pre-registered, exactly — no more, no fewer.
   */
  private const EXPECTED_MAP = [
    'view.activity_stream.page_1' => 'page.stream',
    'view.all_groups.page_1' => 'page.all_groups',
    'view.group_content_stream.page_1' => 'page.group.stream',
    'view.group_events.page_1' => 'page.group.events',
    'view.group_members.page_1' => 'page.group.members',
    'view.my_feed.page_1' => 'page.my_feed',
    'view.following.page_1' => 'page.following',
    'view.trending.page_1' => 'page.trending',
    'view.my_feed_events.page_1' => 'page.my_feed_events',
    'view.profile_stream.page_1' => 'page.profile_stream',
  ];

  /**
   * The route-map, read via a `getRouteMap()` accessor PageHelp must expose
   * (mirrors the class's own allowlist so this test proves the SAME map
   * drives both the lookup and this assertion — not a second, independently
   * hand-maintained copy that could silently drift).
   *
   * NOTE: if F's implementation does not expose a public `getRouteMap()`,
   * this specific test method is the one to adjust (per T's fix-the-test
   * authority) — the contract this test pins is "the map has exactly these
   * 10 entries," not the accessor's exact name. At RED time the class does
   * not exist at all, so this fails on instantiation regardless.
   */
  public function testRouteMapContainsExactlyTenEntries(): void {
    $page_help = new PageHelp(\Drupal::routeMatch());
    $this->assertTrue(method_exists($page_help, 'getRouteMap'), 'PageHelp must expose its route map (getRouteMap()) so this contract is independently verifiable.');
    $this->assertSame(self::EXPECTED_MAP, $page_help->getRouteMap());
  }

  /**
   * A route-match for the LIVE `view.activity_stream.page_1` route (path
   * `/stream`) causes `preprocessPageTitle()` to mutate `$variables
   * ['title_suffix']` into a render array containing the `page.stream` copy.
   */
  public function testPreprocessPageTitleRendersTriggerForLiveStreamRoute(): void {
    $route_match = new RouteMatch('view.activity_stream.page_1', new Route('/stream'));
    $page_help = new PageHelp($route_match);

    $variables = ['title_suffix' => []];
    $page_help->preprocessPageTitle($variables);

    $this->assertNotSame([], $variables['title_suffix'], 'preprocessPageTitle() must add a render element to title_suffix for a mapped route.');
    $rendered = (string) \Drupal::service('renderer')->renderInIsolation($variables['title_suffix']);

    $expected_copy = HelpText::get('page.stream');
    $this->assertNotSame('', $expected_copy, 'page.stream copy must exist for this assertion to be meaningful.');
    $this->assertStringContainsString($expected_copy, $rendered, 'Rendered trigger must carry the page.stream copy.');
  }

  /**
   * A route-match for an UNREGISTERED route (`system.admin`) leaves
   * `$variables['title_suffix']` completely untouched — the default-deny
   * gate (brief.md Design: "the route-name -> helpkey map is the ALLOWLIST").
   */
  public function testPreprocessPageTitleDoesNotMutateForUnregisteredRoute(): void {
    $route_match = new RouteMatch('system.admin', new Route('/admin'));
    $page_help = new PageHelp($route_match);

    $variables = ['title_suffix' => ['existing' => ['#markup' => 'untouched']]];
    $page_help->preprocessPageTitle($variables);

    $this->assertSame(
      ['existing' => ['#markup' => 'untouched']],
      $variables['title_suffix'],
      'An unregistered route must leave title_suffix completely untouched (default-deny).'
    );
  }

  /**
   * The rendered trigger for a live route carries every accessibility/hook
   * attribute the brief's Design section pins: `data-do-tooltip`,
   * `aria-label`, `tabindex="0"`, `role="note"`, and the ⓘ glyph itself
   * (U+24D8 CIRCLED LATIN SMALL LETTER I) — the exact
   * GroupTypeContentHelp::infoTrigger() shape, per brief.md's
   * skip-D justification.
   */
  public function testRenderedTriggerCarriesAllRequiredAttributesAndGlyph(): void {
    $route_match = new RouteMatch('view.all_groups.page_1', new Route('/all-groups'));
    $page_help = new PageHelp($route_match);

    $variables = ['title_suffix' => []];
    $page_help->preprocessPageTitle($variables);

    $rendered = (string) \Drupal::service('renderer')->renderInIsolation($variables['title_suffix']);

    $this->assertStringContainsString('data-do-tooltip', $rendered, 'Trigger must carry data-do-tooltip.');
    $this->assertStringContainsString('aria-label', $rendered, 'Trigger must carry aria-label.');
    $this->assertStringContainsString('tabindex="0"', $rendered, 'Trigger must carry tabindex="0".');
    $this->assertStringContainsString('role="note"', $rendered, 'Trigger must carry role="note".');
    $this->assertStringContainsString('ⓘ', $rendered, 'Trigger must render the ⓘ glyph.');
  }

}
