<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\do_streams\Hook\DoStreamsHooks;
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
 * T's Phase-6 adjudication note (T-green): the ORIGINAL versions of the 5
 * contract tests below (testScopeTabsContract.../testRankingControlContract...
 * /testTrendingScopeDoesNotDisable.../testEmptyFlagReflectsResultCount/
 * testEmptyCopyIsDistinctPerScope) asserted `$build['scope_tabs']` etc. were
 * populated on the CALLER's render array AFTER `$renderer->renderRoot($build)`
 * returns. This is incompatible with Drupal's render pipeline: confirmed by
 * reading web/core/lib/Drupal/Core/Render/Renderer.php:504
 * (`$elements['#children'] = $this->theme->render($elements['#theme'],
 * $elements);` — only the rendered STRING is written back onto $elements) and
 * web/core/lib/Drupal/Core/Theme/ThemeManager.php:134
 * (`public function render($hook, array $variables)` — $variables is taken BY
 * VALUE, not by reference, and is rebuilt into a FRESH local array from
 * `#`-prefixed element properties before any preprocess hook runs against it;
 * preprocess mutations are never copied back onto the caller's $build). A
 * correctly-written `preprocess_do_streams_shell` hook (mutating $variables
 * by reference, exactly as DoStreamsHooks::preprocessDoStreamsShell() does)
 * can therefore NEVER populate `$build['scope_tabs']` via `renderRoot($build)`
 * regardless of implementation — the original tests' premise was
 * incompatible with the render API they exercised, a test-authoring bug, not
 * an implementation gap.
 *
 * Rewritten to invoke DoStreamsHooks::preprocessDoStreamsShell() DIRECTLY
 * (it is a public method, tagged #[Hook('preprocess_do_streams_shell')]) and
 * assert on ITS OWN $variables output — bypassing the theme render pipeline
 * entirely, mirroring the existing in-repo precedent of
 * do_profile_stats/tests/src/Kernel/ContributionStatsTest.php's countGroups()
 * pattern (there via ReflectionMethod on a protected method; here direct,
 * since preprocessDoStreamsShell() is already public, as required for its own
 * #[Hook] attribute wiring). Every one of the 5 ACs (4 scope tabs + active
 * flag; 2 ranking pills + active; Trending's Recent pill NOT disabled; empty
 * bool; 4 distinct per-scope empty_copy) remains asserted, now against a
 * reachable contract. testNoHardcodedRoutePathsInRenderedTabMarkup is left
 * AS-IS (a genuine `renderRoot()` + rendered-HTML assertion — no render-array
 * mutation is asserted there, only the returned markup string, which IS what
 * renderRoot() legitimately returns).
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
   * Invokes DoStreamsHooks::preprocessDoStreamsShell() directly.
   *
   * Bypasses the theme render pipeline (see class docblock's T-green
   * adjudication note: ThemeManager::render() takes $variables by value, so a
   * preprocess hook's mutations are never visible on the caller's
   * `#theme => do_streams_shell` render array after `renderRoot()` returns).
   * This asserts the preprocess CONTRACT directly against its own $variables
   * output, exactly as the hook is invoked by the real render pipeline
   * (`$variables[$name] = $element["#$name"]` for every `#`-prefixed
   * property declared in `hook_theme()`), without depending on an API that
   * cannot expose it.
   *
   * @param string $activeScope
   *   The active scope tab id.
   * @param string $activeRanking
   *   The active ranking control id.
   * @param array $results
   *   A pre-rendered results render array (empty array for the empty state).
   *
   * @return array
   *   The $variables array as populated by preprocessDoStreamsShell().
   */
  protected function preprocessShellVariables(string $activeScope, string $activeRanking, array $results): array {
    $variables = [
      'active_scope' => $activeScope,
      'active_ranking' => $activeRanking,
      'results' => $results,
      'scope_tabs' => [],
      'ranking_control' => [],
      'empty' => TRUE,
      'empty_copy' => '',
    ];
    $hooks = new DoStreamsHooks();
    $hooks->preprocessDoStreamsShell($variables);
    return $variables;
  }

  /**
   * The shell exposes `scope_tabs` with all 4 tabs, correct id/label/active.
   *
   * [B-3]: `scope_tabs` is an array of `{id, label, url_or_param, active}` for
   * Global/My Feed/Following/Trending.
   */
  public function testScopeTabsContractAllFourPresentWithCorrectActiveFlag(): void {
    $variables = $this->preprocessShellVariables('following', 'recent', []);

    $this->assertArrayHasKey('scope_tabs', $variables, 'The preprocessed variables carry a scope_tabs key.');
    $ids = array_column($variables['scope_tabs'], 'id');
    $this->assertSame(
      ['global', 'my_feed', 'following', 'trending'],
      $ids,
      'scope_tabs contains exactly the 4 tabs in Global/My Feed/Following/Trending order.'
    );

    $activeFlags = array_column($variables['scope_tabs'], 'active', 'id');
    $this->assertTrue($activeFlags['following'], 'The Following tab is flagged active when it is the active scope.');
    $this->assertFalse($activeFlags['global'], 'Global is not flagged active when Following is the active scope.');
    $this->assertFalse($activeFlags['my_feed'], 'My Feed is not flagged active when Following is the active scope.');
    $this->assertFalse($activeFlags['trending'], 'Trending is not flagged active when Following is the active scope.');

    // Diff-gate [B-1]: every scope_tabs entry must also carry `url_or_param`,
    // a query-PARAMETER mapping derived purely from the tab's own `id`
    // (`?scope=<id>`) — NOT a hardcoded route path. This was the field the
    // brief's [B-3] contract cited but the original assertion never pinned,
    // letting F ship the field missing without any test catching it (found
    // only by the o4-mini diff gate). Would FAIL against the pre-rework
    // shape (no `url_or_param` key in the array at all).
    $urlOrParamByScope = array_column($variables['scope_tabs'], 'url_or_param', 'id');
    foreach (['global', 'my_feed', 'following', 'trending'] as $scopeId) {
      $this->assertArrayHasKey(
        $scopeId,
        $urlOrParamByScope,
        "The '$scopeId' scope_tabs entry carries a url_or_param key."
      );
      $this->assertSame(
        '?scope=' . $scopeId,
        $urlOrParamByScope[$scopeId],
        "The '$scopeId' scope_tabs entry's url_or_param is the query-parameter mapping '?scope=$scopeId'."
      );
      $this->assertStringStartsNotWith(
        '/',
        $urlOrParamByScope[$scopeId],
        "The '$scopeId' scope_tabs entry's url_or_param is not a hardcoded route path (must not start with '/')."
      );
    }
  }

  /**
   * The shell exposes `ranking_control` with both pills, correct active flag.
   *
   * [B-3]: `ranking_control` is an array of `{id, label, active}` for
   * Recent/Hot.
   */
  public function testRankingControlContractBothPillsPresentWithCorrectActiveFlag(): void {
    $variables = $this->preprocessShellVariables('global', 'hot', []);

    $this->assertArrayHasKey('ranking_control', $variables, 'The preprocessed variables carry a ranking_control key.');
    $ids = array_column($variables['ranking_control'], 'id');
    $this->assertSame(['recent', 'hot'], $ids, 'ranking_control contains exactly the Recent and Hot pills.');

    $activeFlags = array_column($variables['ranking_control'], 'active', 'id');
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
    // Trending defaults ranking to hot (per [B-8]), so active_ranking='hot'
    // under the trending scope is the realistic combination this asserts
    // against.
    $variables = $this->preprocessShellVariables('trending', 'hot', []);

    $recentPill = NULL;
    foreach ($variables['ranking_control'] as $pill) {
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
    $emptyVariables = $this->preprocessShellVariables('global', 'recent', []);
    $this->assertTrue($emptyVariables['empty'], 'empty is TRUE when results is empty.');

    $nonEmptyVariables = $this->preprocessShellVariables('global', 'recent', [['#markup' => 'a result row']]);
    $this->assertFalse($nonEmptyVariables['empty'], 'empty is FALSE when results is non-empty.');
  }

  /**
   * D-gate resolution 2: all 4 scopes get DISTINCT, scope-appropriate empty copy.
   *
   * handoff-D.md: "F must provide DISTINCT empty-state copy per scope
   * (global / my_feed / following / trending), NOT one shared string ...
   * Global empty must NOT say 'browse groups to follow'."
   */
  public function testEmptyCopyIsDistinctPerScope(): void {
    $copyByScope = [];
    foreach (['global', 'my_feed', 'following', 'trending'] as $scope) {
      $variables = $this->preprocessShellVariables($scope, 'recent', []);
      $this->assertArrayHasKey('empty_copy', $variables, "The preprocessed variables for scope '$scope' carry an empty_copy key.");
      $copyByScope[$scope] = $variables['empty_copy'];
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

    // Diff-gate [B-1]: the rendered markup surfaces each tab's url_or_param
    // as a `data-url-or-param` attribute (per the template's
    // `data-url-or-param="{{ tab.url_or_param }}"`), and that value is a
    // query-parameter mapping (`?scope=<id>`), never a literal route path —
    // reinforcing the "no hardcoded routes" criterion at the rendered-HTML
    // level, not just the render-array level. Would FAIL against the
    // pre-rework template/preprocess (no `url_or_param` in $variables meant
    // Twig would render `data-url-or-param=""`, not the expected value).
    $this->assertStringContainsString(
      'data-url-or-param="?scope=global"',
      $markup,
      "The rendered Global tab surfaces its url_or_param as a data-url-or-param attribute with value '?scope=global'."
    );
    $this->assertStringNotContainsString(
      'data-url-or-param=""',
      $markup,
      'No tab renders an empty data-url-or-param attribute (the field must be populated for every tab).'
    );
  }

}
