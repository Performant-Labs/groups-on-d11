<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\do_streams\Hook\DoStreamsHooks;
use Drupal\do_streams\Hook\StreamSwitcherHooks;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\views\Views;

/**
 * Kernel coverage for #115 ST-6's `StreamSwitcherHooks` class.
 *
 * Issue #115 acceptance criteria this suite pins (see
 * docs/handoffs/115-stream-switcher/brief.md):
 *  - The scope registry (shared with DoStreamsHooks::preprocessDoStreamsShell()
 *    per brief step 1) exposes the 4 scopes, in stable order, with ids/labels.
 *  - The tab-list builder returns all tabs whose route currently exists,
 *    filtered by anonymous-allowlist (['global', 'trending']) and by
 *    route-existence tolerance (a scope whose route is not yet registered —
 *    e.g. `my_feed`/`trending` on this branch, before siblings #112/#113 land
 *    — is OMITTED, never rendered disabled; brief.md "Plan" step 2 + the
 *    approved wireframe's single omission rule).
 *  - The active-tab flag is set correctly from the current route's path.
 *  - `preprocess_views_view` attaches the `do_streams/stream-switcher`
 *    library and prepends the switcher render array to `$variables['header']`
 *    ONLY when `$view->id()` is in `StreamSwitcherHooks::ATTACH_VIEW_IDS`
 *    (tested against the real `activity_stream` view), and is a no-op for
 *    `group_content_stream` (group stream shows no switcher — brief
 *    acceptance criterion + wireframe Screen 4 control).
 *
 * `StreamSwitcherHooks` does not exist yet (brief.md "Plan" step 2: NEW file
 * `docs/groups/modules/do_streams/src/Hook/StreamSwitcherHooks.php`) — this is
 * the intended RED. See handoff-T-red.md for the RED verification output.
 *
 * Route-existence assertions in this suite are pinned to THIS branch's real
 * route set at RED time (base 01f49a5 + this story, per brief/survey): only
 * `/stream` (activity_stream), `/following` (following_feed), and `/hot`
 * (hot_content) exist. `/my-feed` and `/trending` do not exist until siblings
 * #112/#113 land — the tab-list builder must therefore omit `my_feed` and
 * `trending` for an AUTHENTICATED user on this branch (not just for anon),
 * which is the story's own route-tolerance acceptance criterion, not a gap.
 *
 * Fixture/setUp mirrors the sibling do_streams Kernel tests (StreamsShellTest,
 * FollowingFeedTest): shipped config is resolved via a directory walk-up
 * (see ::shippedConfigDir(), mirroring
 * \Drupal\Tests\do_streams\Kernel\FollowingFeedTest::shippedConfigDir()),
 * never a source-relative `__DIR__/../../../../config` path — CI's assembled
 * layout differs from the source tree.
 *
 * @group do_streams
 * @group do_tests
 */
class StreamSwitcherHooksTest extends GroupsKernelTestBase {

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

    $entity_type_manager = $this->container->get('entity_type.manager');

    // Install the SHIPPED activity_stream/following_feed views so this test
    // proves the real production views/routes, not scaffolds — mirrors
    // FollowingFeedTest's approach of installing shipped config rather than
    // a fixture stand-in for the view under test. hot_content (/hot) is
    // deliberately NOT installed here: this suite's route-tolerance
    // assertions only concern the 4 switcher scopes (/stream, /my-feed,
    // /following, /trending); the /hot -> /trending redirect behavior is
    // covered by the Playwright suite (tests/e2e/stream-switcher.spec.ts),
    // and hot_content's shipped config carries the SAME pre-existing
    // render-only-field schema gap as group_content_stream (confirmed by
    // inspection: fields.score.settings / fields.created.date_format missing
    // schema) that this suite has no need to work around for a view its own
    // assertions never touch.
    $shipped_config = new FileStorage($this->shippedConfigDir());
    foreach (['activity_stream', 'following_feed'] as $view_id) {
      $config = $shipped_config->read('views.view.' . $view_id);
      $this->assertIsArray(
        $config,
        "views.view.$view_id.yml exists and parses (shipped in docs/groups/config)."
      );
      $entity_type_manager->getStorage('view')->create($config)->save();
    }

    // group_content_stream is installed from a MODULE-LOCAL fixture (not the
    // shipped config directly): the shipped view carries two RENDER-ONLY
    // field settings (title.settings.link_to_entity, created.date_format)
    // whose config schema is only resolvable with the full entity-field
    // views integration this test doesn't otherwise need — the same
    // pre-existing schema gap \Drupal\Tests\do_group_pin\Kernel\PinnedStreamOrderingTest
    // documents and works around identically. The fixture is byte-identical
    // to the shipped view with only those two keys removed; every
    // query-shaping option (base_table, the gid argument, the page_1 path)
    // is preserved verbatim, so this test still proves the switcher hook is
    // a no-op against the REAL group_content_stream view id/route.
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager->getStorage('view')
      ->create($fixtures->read('views.view.group_content_stream'))
      ->save();

    // Rebuild the router so the /stream, /following, /hot, and
    // group/%group/stream page-display routes above are registered and
    // discoverable via router.route_provider — required for the
    // route-existence-tolerance assertions below.
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Resolves the directory holding do_streams' shipped view configs.
   *
   * Mirrors \Drupal\Tests\do_streams\Kernel\FollowingFeedTest::shippedConfigDir()
   * exactly: walk up from this file until a directory containing the marker
   * YAML is found, checking both the canonical `docs/groups/config` (source
   * worktree) and the assembled `config/sync` (CI layout, after
   * scripts/ci/assemble-config.sh runs) at each level. Fixtures for THIS
   * story's own new module code must stay module-local
   * (tests/fixtures/config/); this walk-up is only for pre-existing SHIPPED
   * config this test installs to exercise the real views/routes.
   *
   * @return string
   *   Absolute path to the directory holding the shipped view configs.
   */
  protected function shippedConfigDir(): string {
    $marker = 'views.view.activity_stream.yml';
    $dir = __DIR__;
    while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR) {
      foreach (['docs/groups/config', 'config/sync'] as $candidate) {
        $path = $dir . '/' . $candidate;
        if (is_file($path . '/' . $marker)) {
          return $path;
        }
      }
      $dir = dirname($dir);
    }
    $this->fail("Could not locate shipped $marker in docs/groups/config or config/sync above " . __DIR__);
  }

  /**
   * The scope registry returns the 4 scopes, in stable order, with labels.
   *
   * Brief step 1: `DoStreamsHooks::getScopeRegistry()` (extracted from
   * preprocessDoStreamsShell()'s local `$scope_labels`) is the SINGLE source
   * of truth both DoStreamsHooks and StreamSwitcherHooks read from.
   */
  public function testScopeRegistryReturnsFourScopesInOrderWithLabels(): void {
    $registry = DoStreamsHooks::getScopeRegistry();

    $this->assertIsArray($registry, 'getScopeRegistry() returns an array.');
    $this->assertSame(
      ['global', 'my_feed', 'following', 'trending'],
      array_keys($registry),
      'The scope registry contains exactly the 4 scopes, in Global/My Feed/Following/Trending order.'
    );

    foreach (['global' => 'Global', 'my_feed' => 'My Feed', 'following' => 'Following', 'trending' => 'Trending'] as $id => $expectedLabel) {
      $this->assertSame(
        $expectedLabel,
        (string) $registry[$id],
        "The '$id' scope's label renders as '$expectedLabel'."
      );
    }
  }

  /**
   * Tab-list builder omits scopes whose route does not exist on this branch.
   *
   * On THIS branch, only /stream, /following, /hot exist — /my-feed and
   * /trending do not (siblings #112/#113 not yet merged). Per brief.md's
   * route-tolerance rule, the tab list for an AUTHENTICATED user must
   * therefore contain ONLY Global + Following (the two scopes whose mapped
   * route currently resolves), never a disabled/greyed entry for the other
   * two. This is the acceptance criterion's own worked example: "Under the
   * plan the switcher would then render only Global + Following."
   */
  public function testTabListOmitsScopesWithoutAnExistingRouteForAuthenticatedUser(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $tabs = StreamSwitcherHooks::buildTabList('/stream');

    $ids = array_column($tabs, 'id');
    $this->assertSame(
      ['global', 'following'],
      $ids,
      'An authenticated user sees only Global + Following on this branch, because /my-feed and /trending routes do not exist yet — the tab list omits them entirely rather than rendering them disabled.'
    );
  }

  /**
   * Anonymous users see only the Global + Trending allowlisted scopes.
   *
   * Since /trending does not exist on this branch either, the anonymous tab
   * list intersects the anon allowlist (['global', 'trending']) with
   * route-existence, leaving only Global. This still proves the anon filter
   * is applied independently of route-existence: /following's route DOES
   * exist, yet it is absent for anon (allowlist-excluded), while /my-feed's
   * route does NOT exist, yet it is also absent for an AUTHENTICATED user
   * (per the previous test) — two different exclusion reasons converging on
   * the same "omitted" outcome.
   */
  public function testTabListOmitsNonAllowlistedScopesForAnonymousUser(): void {
    $this->setCurrentUser(new AnonymousUserSession());

    $tabs = StreamSwitcherHooks::buildTabList('/stream');

    $ids = array_column($tabs, 'id');
    $this->assertSame(
      ['global'],
      $ids,
      'An anonymous user sees only Global — Following is allowlist-excluded even though its route exists, and Trending/My Feed are both route-nonexistent AND (for My Feed) allowlist-excluded.'
    );
    $this->assertNotContains(
      'following',
      $ids,
      'Following is omitted for anonymous users (not in the anon allowlist), even though the /following route exists — proves the anon filter is independent of route-existence.'
    );
  }

  /**
   * The active-tab flag is set from the current route's path.
   */
  public function testActiveTabFlagMatchesCurrentRoutePath(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $tabs = StreamSwitcherHooks::buildTabList('/following');

    $activeFlags = array_column($tabs, 'active', 'id');
    $this->assertTrue($activeFlags['following'], 'The Following tab is flagged active when the current route path is /following.');
    $this->assertFalse($activeFlags['global'], 'Global is not flagged active when Following is the current route.');
  }

  /**
   * The active-tab flag defaults correctly when the current path is Global.
   */
  public function testActiveTabFlagDefaultsToGlobalOnStreamPath(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $tabs = StreamSwitcherHooks::buildTabList('/stream');

    $activeFlags = array_column($tabs, 'active', 'id');
    $this->assertTrue($activeFlags['global'], 'The Global tab is flagged active when the current route path is /stream.');
    $this->assertFalse($activeFlags['following'], 'Following is not flagged active when Global is the current route.');
  }

  /**
   * `preprocess_views_view` attaches the switcher on an ATTACH_VIEW_IDS view.
   *
   * Tested against the real `activity_stream` view (brief.md names it
   * explicitly in ATTACH_VIEW_IDS). Asserts (a) the `do_streams/stream-switcher`
   * library is attached, and (b) a switcher render array is prepended to
   * `$variables['header']`.
   */
  public function testPreprocessViewsViewAttachesSwitcherOnActivityStream(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $view = Views::getView('activity_stream');
    $this->assertNotNull($view, 'The activity_stream view loaded.');
    $view->setDisplay('page_1');

    $variables = [
      'view' => $view,
      'header' => [],
    ];

    $hooks = new StreamSwitcherHooks();
    $hooks->preprocessViewsView($variables);

    $this->assertContains(
      'do_streams/stream-switcher',
      $variables['#attached']['library'] ?? [],
      'The do_streams/stream-switcher library is attached when preprocessing the activity_stream view.'
    );

    $this->assertNotEmpty(
      $variables['header'],
      'A switcher render array is prepended to $variables[\'header\'] for the activity_stream view.'
    );
  }

  /**
   * `preprocess_views_view` is a no-op for `group_content_stream`.
   *
   * Brief acceptance criterion: "Group stream (group/%/stream) shows no
   * switcher." group_content_stream is deliberately absent from
   * ATTACH_VIEW_IDS (survey.md "Do NOT rewrite ST-1/ST-2/ST-4 view configs").
   */
  public function testPreprocessViewsViewIsNoOpForGroupContentStream(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $view = Views::getView('group_content_stream');
    $this->assertNotNull($view, 'The group_content_stream view loaded.');
    $view->setDisplay('page_1');

    $variables = [
      'view' => $view,
      'header' => [],
    ];

    $hooks = new StreamSwitcherHooks();
    $hooks->preprocessViewsView($variables);

    $this->assertArrayNotHasKey(
      'library',
      $variables['#attached'] ?? [],
      'No library is attached when preprocessing the group_content_stream view — group streams show no switcher.'
    );
    $this->assertEmpty(
      $variables['header'],
      'No switcher render array is added to $variables[\'header\'] for the group_content_stream view.'
    );
  }

}
