<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;

/**
 * Behavioral test for the do_streams shared stream shell preprocess/render.
 *
 * Issue #109 (epic #108), acceptance criterion "Shared stream shell (Twig +
 * preprocess) renders scope tabs ... and a Recent/Hot control from
 * parameters; no hardcoded routes" + brief [B-3] (shell contract) + the
 * approved wireframe's two binding D-gate resolutions (handoff-D.md):
 *  1. Trending's Recent pill is rendered ENABLED (unselected but clickable),
 *     never `disabled`.
 *  2. Per-scope empty-state copy: 4 DISTINCT strings (global/my_feed/
 *     following/trending), not one shared message; Global's copy must NOT
 *     contain a follow-oriented CTA.
 *
 * The theme hook `do_streams_shell` and its preprocess function do not exist
 * yet (no `do_streams.module` docblock-pointer + `DoStreamsHooks::hookTheme()`
 * / `preprocessDoStreamsShell()` implementation, no
 * `templates/do-streams-shell.html.twig`), so `\Drupal::theme()->render()`
 * against the `do_streams_shell` hook throws (theme hook not registered) —
 * this is the intended RED. This suite operates at the render-array/preprocess
 * level (asserting on the variables + rendered markup), not scraping a live
 * page, per survey.md §Testing approach item 7 ("Kernel tests assert query
 * results and render-array shape; Playwright asserts what actually paints").
 *
 * @group do_streams
 * @group do_tests
 */
class StreamsShellTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'do_group_pin',
    'do_discovery',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
    'comment',
    'taxonomy',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * Renders the do_streams_shell theme hook for a given scope/ranking/results.
   *
   * @param string $activeScope
   *   The active scope tab id (`global`, `my_feed`, `following`, `trending`).
   * @param string $activeRanking
   *   The active ranking control id (`recent`, `hot`).
   * @param array $results
   *   A pre-rendered results render array (empty array for the empty state).
   *
   * @return array
   *   The built #theme render array with preprocess variables attached, as
   *   \Drupal::service('renderer')->renderRoot() would consume it.
   */
  protected function buildShellRenderArray(string $activeScope, string $activeRanking, array $results): array {
    return [
      '#theme' => 'do_streams_shell',
      '#active_scope' => $activeScope,
      '#active_ranking' => $activeRanking,
      '#results' => $results,
    ];
  }

  /**
   * The shell exposes `scope_tabs` with all 4 tabs, correct id/label/active.
   *
   * [B-3]: `scope_tabs` is an array of `{id, label, url_or_param, active}` for
   * Global/My Feed/Following/Trending.
   */
  public function testScopeTabsContractAllFourPresentWithCorrectActiveFlag(): void {
    $renderer = $this->container->get('renderer');
    $build = $this->buildShellRenderArray('following', 'recent', []);
    $renderer->renderRoot($build);

    $this->assertArrayHasKey('scope_tabs', $build, 'The rendered build carries a scope_tabs preprocess variable.');
    $ids = array_column($build['scope_tabs'], 'id');
    $this->assertSame(
      ['global', 'my_feed', 'following', 'trending'],
      $ids,
      'scope_tabs contains exactly the 4 tabs in Global/My Feed/Following/Trending order.'
    );

    $activeFlags = array_column($build['scope_tabs'], 'active', 'id');
    $this->assertTrue($activeFlags['following'], 'The Following tab is flagged active when it is the active scope.');
    $this->assertFalse($activeFlags['global'], 'Global is not flagged active when Following is the active scope.');
    $this->assertFalse($activeFlags['my_feed'], 'My Feed is not flagged active when Following is the active scope.');
    $this->assertFalse($activeFlags['trending'], 'Trending is not flagged active when Following is the active scope.');
  }

  /**
   * The shell exposes `ranking_control` with both pills, correct active flag.
   *
   * [B-3]: `ranking_control` is an array of `{id, label, active}` for
   * Recent/Hot.
   */
  public function testRankingControlContractBothPillsPresentWithCorrectActiveFlag(): void {
    $renderer = $this->container->get('renderer');
    $build = $this->buildShellRenderArray('global', 'hot', []);
    $renderer->renderRoot($build);

    $this->assertArrayHasKey('ranking_control', $build, 'The rendered build carries a ranking_control preprocess variable.');
    $ids = array_column($build['ranking_control'], 'id');
    $this->assertSame(['recent', 'hot'], $ids, 'ranking_control contains exactly the Recent and Hot pills.');

    $activeFlags = array_column($build['ranking_control'], 'active', 'id');
    $this->assertTrue($activeFlags['hot'], 'The Hot pill is flagged active when hot is the active ranking.');
    $this->assertFalse($activeFlags['recent'], 'The Recent pill is not flagged active when hot is the active ranking.');
  }

  /**
   * D-gate resolution 1: Trending's Recent pill is enabled, never disabled.
   *
   * handoff-D.md's binding resolution: "Trending's Recent pill = ENABLED
   * (unselected but clickable), NOT disabled/locked." Ranking is orthogonal
   * to scope per [B-2]; Trending only DEFAULTS the ranking to Hot.
   */
  public function testTrendingScopeDoesNotDisableTheRecentRankingPill(): void {
    $renderer = $this->container->get('renderer');
    // Trending defaults ranking to hot (per [B-8]), so active_ranking='hot'
    // under the trending scope is the realistic combination this asserts
    // against.
    $build = $this->buildShellRenderArray('trending', 'hot', []);
    $renderer->renderRoot($build);

    $recentPill = NULL;
    foreach ($build['ranking_control'] as $pill) {
      if ($pill['id'] === 'recent') {
        $recentPill = $pill;
      }
    }
    $this->assertNotNull($recentPill, 'The Recent pill is present under the Trending scope.');
    $this->assertArrayNotHasKey(
      'disabled',
      $recentPill,
      'The Recent pill under Trending carries no `disabled` key (D-gate resolution 1: unselected but clickable).'
    );
    if (array_key_exists('disabled', $recentPill ?? [])) {
      $this->assertFalse($recentPill['disabled'], 'If a disabled key exists at all, it must be false under Trending.');
    }
  }

  /**
   * `empty` is TRUE with zero results and FALSE with non-zero results.
   *
   * [B-3]: `empty` (bool, true when `results` has zero rows).
   */
  public function testEmptyFlagReflectsResultCount(): void {
    $renderer = $this->container->get('renderer');

    $emptyBuild = $this->buildShellRenderArray('global', 'recent', []);
    $renderer->renderRoot($emptyBuild);
    $this->assertTrue($emptyBuild['empty'], 'empty is TRUE when results is empty.');

    $nonEmptyBuild = $this->buildShellRenderArray('global', 'recent', [['#markup' => 'a result row']]);
    $renderer->renderRoot($nonEmptyBuild);
    $this->assertFalse($nonEmptyBuild['empty'], 'empty is FALSE when results is non-empty.');
  }

  /**
   * D-gate resolution 2: all 4 scopes get DISTINCT, scope-appropriate empty copy.
   *
   * handoff-D.md: "F must provide DISTINCT empty-state copy per scope
   * (global / my_feed / following / trending), NOT one shared string ...
   * Global empty must NOT say 'browse groups to follow'."
   */
  public function testEmptyCopyIsDistinctPerScope(): void {
    $renderer = $this->container->get('renderer');
    $copyByScope = [];
    foreach (['global', 'my_feed', 'following', 'trending'] as $scope) {
      $build = $this->buildShellRenderArray($scope, 'recent', []);
      $renderer->renderRoot($build);
      $this->assertArrayHasKey('empty_copy', $build, "The build for scope '$scope' carries an empty_copy variable.");
      $copyByScope[$scope] = $build['empty_copy'];
    }

    $this->assertSame(
      $copyByScope,
      array_unique($copyByScope),
      'All 4 scopes have DISTINCT empty-state copy (no two scopes share the same string).'
    );

    $this->assertStringNotContainsStringIgnoringCase(
      'follow',
      $copyByScope['global'],
      "Global's empty copy must NOT contain a follow-oriented CTA (that CTA only makes sense for My Feed / Following)."
    );
  }

  /**
   * No hardcoded route path strings appear in the rendered tab markup.
   *
   * Acceptance criterion: "no hardcoded routes." The rendered HTML for the
   * scope tabs must not contain a literal href/path string; controls are
   * parameter-driven (per handoff-D.md's "annotated with its
   * scope_tabs[n].id / ranking_control[n].id origin" convention — plain
   * labeled elements, not `<a href="/some/literal/path">`).
   */
  public function testNoHardcodedRoutePathsInRenderedTabMarkup(): void {
    $renderer = $this->container->get('renderer');
    $build = $this->buildShellRenderArray('global', 'recent', []);
    $markup = (string) $renderer->renderRoot($build);

    $this->assertStringNotContainsString(
      'href="/',
      $markup,
      'The rendered shell markup contains no hardcoded absolute route href.'
    );
    $this->assertStringNotContainsString(
      "href='/",
      $markup,
      'The rendered shell markup contains no hardcoded absolute route href (single-quote variant).'
    );
  }

}
