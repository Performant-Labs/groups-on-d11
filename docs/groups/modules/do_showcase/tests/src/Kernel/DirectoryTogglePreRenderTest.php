<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * #124 SC-5: `DoShowcaseHooks::viewsPreRender()` mounts the SC-F1 variant
 * switcher over `/all-groups` and sets the wrapper CSS-variant contract.
 *
 * Pins the acceptance criteria from `docs/handoffs/0124-directory-toggle/`
 * brief.md + wireframe.md Surface 1/3 + the fallback-behavior section:
 *
 * - The `all_groups` view's `page_1` display, when FULLY rendered (through
 *   Drupal's Renderer, not just `$view->element`'s pre-`#pre_render`
 *   snapshot — see `testSwitcherInjectedWithThreeOptionsInOrder()`'s
 *   docblock for why), produces HTML carrying the SC-F1 variant switcher
 *   with exactly three options in order (compact / cards / map), map
 *   `available: false` (brief.md "three options, not two"; wireframe.md
 *   Surface 1).
 * - The view's own render array declares the `url.query_args:variant` cache
 *   context directly (A-advisory #1 — NOT relying solely on the switcher's
 *   own child #cache metadata).
 * - The view wrapper resolves `data-do-directory-variant` from the request's
 *   `?variant=` query arg using the SAME first-available-fallback rule
 *   `VariantSwitcher::resolveSelection()` already implements (wireframe.md
 *   "Fallback behavior" section): no query -> cards (page default); explicit
 *   compact -> compact; unavailable/unknown (map, bogus) -> falls back to
 *   compact (first available), never blank/broken.
 * - `do_showcase/switcher` and `do_showcase/directory-compact` libraries are
 *   attached (brief.md "Owned files" + "Also touched").
 * - The hook fires ONLY for view id `all_groups` display `page_1` — a
 *   different view (or a different display on the same view) must not gain
 *   a switcher or the cache context (brief.md scope: this story does not
 *   touch any other view).
 *
 * Layer choice: kernel view-execution against a real `ViewExecutable`,
 * mirroring `DirectoryFiltersTest`'s `Views::getView()` + fixture-installed
 * `views.view.all_groups.yml` pattern — the cheapest tier that can actually
 * invoke `hook_views_pre_render` (a Views-runtime hook), which a pure Unit
 * test cannot exercise (no view execution machinery) and a Functional/E2E
 * test would exercise redundantly at a much higher cost for what is a
 * server-side render-array/cache-metadata contract, not a click-through
 * interaction (that lives in tests/e2e/directory-toggle.spec.ts).
 *
 * `views.view.all_groups.yml` and `views.view.activity_stream.yml` (the
 * second view id used to pin the "ONLY all_groups.page_1" scope assertion)
 * are installed from MODULE-LOCAL fixtures
 * (`tests/fixtures/config/views.view.all_groups.yml`,
 * `tests/fixtures/config/views.view.activity_stream.yml`) — never a
 * source-relative `__DIR__/../../../../../config` path (PROJECT_CONTEXT.md
 * "Fixtures & test authorship"; DirectoryFiltersTest's own class docblock
 * repeats this rule). The activity_stream fixture is a deliberately minimal
 * synthetic view (NOT a copy of the real, heavier `entity:node`/comment-
 * dependent activity_stream) — it exists solely to prove the hook does not
 * fire for a different view id, so it uses the cheapest possible base table
 * (`node_field_data` + a `fields` row) rather than pulling in the real
 * view's `stream_card` view-mode/comment-module machinery.
 *
 * Phase 6 (T-green) repair: `testSwitcherInjectedWithThreeOptionsInOrder()`
 * originally inspected `$view->element['#header']` directly after
 * `$view->preview()`, without ever running the returned render array through
 * Drupal's Renderer. That snapshot is NOT what a real page render produces:
 * `DisplayPluginBase::render()` queues a `#pre_render` callback
 * (`elementPreRender()`) on the returned element that UNCONDITIONALLY
 * overwrites `#header` with `renderArea('header', …)` (empty, since
 * `views.view.all_groups.yml` declares no header area handlers) — and that
 * callback runs strictly AFTER `hook_views_pre_render` but strictly BEFORE
 * `hook_preprocess_views_view()`, which is where
 * `DoShowcaseHooks::preprocessViewsView()` actually injects the switcher
 * (see that hook's class docblock for the full Views-core trace). So the
 * ONLY way to observe the switcher the way a real page load does is to push
 * the render array all the way through `\Drupal::service('renderer')
 * ->renderRoot()`, which forces both the `#pre_render` callback AND the
 * `preprocess_views_view` theme hook to fire in their real order, and then
 * assert against the resulting HTML string — never against the pre-render
 * array. `renderViewToHtml()` below does exactly this, mirroring the
 * existing `PersonaSwitcherRenderTest`'s `renderInIsolation()` pattern
 * (this test needs the FULL page-render pipeline including `#pre_render`
 * callbacks, so `renderRoot()` is used instead — `renderInIsolation()` does
 * not run queued `#pre_render` callbacks the same way at the sub-tree the
 * bubbling-cache test needs, whereas `renderRoot()` on the FULL element
 * matches a real request end to end).
 *
 * @group do_showcase
 * @group views
 */
class DirectoryTogglePreRenderTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'views',
    'field',
    'text',
    'block',
    'do_chrome',
    'do_showcase',
    'do_tests',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['views']);

    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = $this->container->get('entity_type.manager');

    foreach ([
      'view' => 'views.view.all_groups',
    ] as $storage_id => $config_name) {
      $data = $fixtures->read($config_name);
      $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
      $entity_type_manager->getStorage($storage_id)->create($data)->save();
    }
  }

  /**
   * Pushes a request onto the request stack WITH a mock session attached.
   *
   * `KernelTestBase::tearDown()` unconditionally calls
   * `$request_stack->getSession()` whenever a current request exists (to
   * clean up "the mock session started in DrupalKernel::preHandle()") — a
   * request pushed directly in a test (bypassing the kernel's normal
   * preHandle() cycle) has no session attached, which makes that tearDown()
   * call throw `SessionNotFoundException` instead of letting the test's own
   * assertions run to completion. Attaching a session up front here mirrors
   * what a real request cycle already does, so tests can safely `push()` a
   * request without a control-flow-relevant side effect.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to push.
   */
  protected function pushRequestWithSession(Request $request): void {
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Loads the `all_groups` view and sets its `page_1` display + request.
   *
   * @param string $query_string
   *   (optional) A raw query string (e.g. 'variant=compact') to attach to
   *   the current request before the view is rendered, mirroring how
   *   `viewsPreRender()` must read `?variant=` off the active request per
   *   the wireframe's "Fallback behavior" contract.
   *
   * @return \Drupal\views\ViewExecutable
   *   The built view, display set to page_1, not yet executed/rendered.
   */
  protected function buildAllGroupsView(string $query_string = ''): ViewExecutable {
    $request = Request::create('/all-groups' . ($query_string ? '?' . $query_string : ''));
    parse_str($query_string, $params);
    $request->query->replace($params);
    $this->pushRequestWithSession($request);

    $view = Views::getView('all_groups');
    $this->assertNotNull($view, 'The all_groups view loaded.');
    $view->setDisplay('page_1');
    return $view;
  }

  /**
   * Fully renders a view display so `hook_views_pre_render` actually fires.
   *
   * `$view->execute()` alone does not invoke `hook_views_pre_render` (that
   * hook fires from the render pipeline, `ViewExecutable::render()` /
   * `buildRenderable()` -> `View::PreRender` event), so callers use this
   * helper rather than `execute()` when the hook's side effects
   * (`#cache`, `#attributes`, `#attached` — the keys that survive
   * `DisplayPluginBase::buildRenderable()`'s direct pass-through of
   * `$view->element` unmodified) are under test at the ARRAY level.
   *
   * NOTE: this returns the PRE-`#pre_render` snapshot of `$view->element`.
   * It is correct for `#cache`/`#attributes`/`#attached`, which
   * `elementPreRender()` never touches, but it must NOT be used to inspect
   * `#header` (see `renderViewToHtml()` and this class's docblock for why).
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The prepared view (display already set).
   *
   * @return array
   *   The view's render array (`$view->element` after `preview()`).
   */
  protected function renderView(ViewExecutable $view): array {
    $view->preview();
    return $view->element ?? [];
  }

  /**
   * Fully renders a view display THROUGH Drupal's Renderer, returning HTML.
   *
   * Unlike `renderView()`, this forces the queued `#pre_render` callback
   * (`DisplayPluginBase::elementPreRender()`) AND the `preprocess_views_view`
   * theme hook to run, in the same order a real page request produces them —
   * the only way to correctly observe `DoShowcaseHooks::preprocessViewsView()`
   * ’s switcher injection into `$variables['header']` (see class docblock).
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The prepared view (display already set).
   *
   * @return string
   *   The fully rendered HTML markup for this view display.
   */
  protected function renderViewToHtml(ViewExecutable $view): string {
    $element = $view->buildRenderable();
    $this->assertNotNull($element, 'The view display built a renderable array.');
    return (string) \Drupal::service('renderer')->renderRoot($element);
  }

  /**
   * The switcher is injected into the rendered view's header region with
   * exactly three options in order (compact / cards / map), map carrying
   * `available: false` — observed via a FULL render pass (see class
   * docblock: only `renderRoot()` forces both `elementPreRender()`'s
   * `#header` overwrite AND the subsequent `preprocess_views_view` injection
   * to run in their real order, matching a live page request).
   */
  public function testSwitcherInjectedWithThreeOptionsInOrder(): void {
    $view = $this->buildAllGroupsView();
    $html = $this->renderViewToHtml($view);

    $this->assertStringContainsString(
      'data-do-showcase-instance="directory.layout"',
      $html,
      'The rendered view header carries the directory.layout switcher instance (VariantSwitcher::build() via DoShowcaseHooks::preprocessViewsView()).'
    );

    // Exactly three options, in order: compact, cards, map. Each option
    // link carries data-do-showcase-id="<id>" (do-showcase-variant-switcher
    // .html.twig line 32) — assert both presence and left-to-right order.
    preg_match_all('/data-do-showcase-id="([^"]+)"/', $html, $matches);
    $this->assertSame(['compact', 'cards', 'map'], $matches[1] ?? [], 'Exactly three options, in order: compact, cards, map.');

    // The map option is unavailable: aria-disabled + "(soon)" suffix, never
    // a silently omitted option (VariantSwitcher::build() contract).
    preg_match('#<a\b[^>]*data-do-showcase-id="map"[^>]*>#s', $html, $map_tag_match);
    $this->assertNotEmpty($map_tag_match, 'The map option\'s opening <a> tag must be found in the rendered HTML.');
    $this->assertStringContainsString(
      'aria-disabled="true"',
      $map_tag_match[0] ?? '',
      'The map option\'s <a> tag must carry aria-disabled="true" (available: false) — SC-6 flips this later.'
    );
    $this->assertStringContainsString('Map (soon)', $html, 'The map option\'s visible label must carry the truthful "(soon)" suffix.');

    // Correct labels for the other two options.
    $this->assertStringContainsString('Compact list', $html, 'The compact option\'s visible label must read "Compact list".');
    $this->assertStringContainsString('Cards', $html, 'The cards option\'s visible label must read "Cards".');
  }

  /**
   * The view's own render array declares the `url.query_args:variant` cache
   * context directly (A-advisory #1) — not relying solely on the switcher
   * child render array's own #cache metadata bubbling up.
   */
  public function testViewDeclaresUrlQueryArgsVariantCacheContextDirectly(): void {
    $view = $this->buildAllGroupsView();
    $element = $this->renderView($view);

    $contexts = $element['#cache']['contexts'] ?? [];
    $this->assertContains(
      'url.query_args:variant',
      $contexts,
      'The view element must declare url.query_args:variant on its OWN #cache[\'contexts\'] (set inside viewsPreRender(), per handoff-A-plan.md advisory #1), so the wrapper-attribute decision (made before VariantSwitcher::build() runs) itself carries the varying context.'
    );
  }

  /**
   * No `?variant=` query -> the wrapper resolves to "cards" (page default).
   */
  public function testNoQueryParamDefaultsWrapperToCards(): void {
    $view = $this->buildAllGroupsView();
    $element = $this->renderView($view);

    $attribute = $this->wrapperVariantAttribute($element);
    $this->assertSame('cards', $attribute, 'With no ?variant= query, the wrapper resolves to "cards" — the page default (wireframe.md Surface 1 "State: default").');
  }

  /**
   * `?variant=compact` -> the wrapper resolves to "compact".
   */
  public function testCompactQueryParamSetsWrapperToCompact(): void {
    $view = $this->buildAllGroupsView('variant=compact');
    $element = $this->renderView($view);

    $attribute = $this->wrapperVariantAttribute($element);
    $this->assertSame('compact', $attribute, 'With ?variant=compact, the wrapper resolves to "compact" (wireframe.md "Fallback behavior": server-resolved, no JS required).');
  }

  /**
   * `?variant=map` (unavailable) -> falls back to "compact" (first
   * available), NEVER a blank/broken map render (wireframe.md Surface 1
   * "State: map selected via URL fallback while unavailable").
   */
  public function testUnavailableMapQueryParamFallsBackToCompact(): void {
    $view = $this->buildAllGroupsView('variant=map');
    $element = $this->renderView($view);

    $attribute = $this->wrapperVariantAttribute($element);
    $this->assertSame('compact', $attribute, 'An unavailable ?variant=map must fall back to the first available option (compact), matching VariantSwitcher::resolveSelection()\'s contract — never blank/broken.');
  }

  /**
   * `?variant=bogus` (unknown id) -> also falls back to "compact" (first
   * available) — the same graceful-fallback contract for an unrecognized
   * value, not just an unavailable one.
   */
  public function testUnknownQueryParamFallsBackToCompact(): void {
    $view = $this->buildAllGroupsView('variant=bogus');
    $element = $this->renderView($view);

    $attribute = $this->wrapperVariantAttribute($element);
    $this->assertSame('compact', $attribute, 'An unrecognized ?variant=bogus must fall back to the first available option (compact), same as an unavailable id.');
  }

  /**
   * The `do_showcase/switcher` and `do_showcase/directory-compact`
   * libraries are both attached to the rendered view element.
   */
  public function testSwitcherAndDirectoryCompactLibrariesAttached(): void {
    $view = $this->buildAllGroupsView();
    $element = $this->renderView($view);

    $libraries = $element['#attached']['library'] ?? [];
    $this->assertContains('do_showcase/switcher', $libraries, 'The do_showcase/switcher library must be attached so the client-side toggle JS runs.');
    $this->assertContains('do_showcase/directory-compact', $libraries, 'The do_showcase/directory-compact library (the new CSS-only library, brief.md "Also touched") must be attached.');
  }

  /**
   * The hook is bound ONLY to view id `all_groups` display `page_1` — a
   * different view (activity_stream) must NOT gain a switcher or the
   * variant cache context, even if it happens to share a display id name.
   */
  public function testHookDoesNotFireForADifferentViewId(): void {
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $data = $fixtures->read('views.view.activity_stream');
    $this->assertNotFalse($data, 'The activity_stream fixture exists and is readable.');
    $this->container->get('entity_type.manager')->getStorage('view')->create($data)->save();

    $request = Request::create('/activity-stream');
    $this->pushRequestWithSession($request);

    $view = Views::getView('activity_stream');
    $this->assertNotNull($view, 'The activity_stream view loaded.');
    $view->setDisplay('page_1');
    $element = $this->renderView($view);

    $this->assertArrayNotHasKey(
      'switcher',
      $element['#header'] ?? [],
      'A different view (activity_stream) must NOT gain the directory-toggle switcher in its #header — the hook is scoped to all_groups.page_1 only.'
    );
    $contexts = $element['#cache']['contexts'] ?? [];
    $this->assertNotContains(
      'url.query_args:variant',
      $contexts,
      'A different view (activity_stream) must NOT gain the url.query_args:variant cache context — that is specific to the all_groups.page_1 directory-toggle hook.'
    );
  }

  /**
   * Extracts the resolved `data-do-directory-variant` value from the
   * rendered view element.
   *
   * The wireframe's Surface 3 contract places the attribute on the view's
   * `.view-content` wrapper, which `hook_views_pre_render` annotates via
   * `$view->element['#attributes']` (the ONE element the hook can reliably
   * annotate per the wireframe — see Surface 3 "Contract"). This helper
   * checks both the top-level `#attributes` (the most direct seam
   * `$view->element` exposes to a kernel-level inspection) and, as a
   * fallback, a nested `#content_attributes` / `#view_content_attributes`
   * key, in case F's implementation surfaces it differently — either way
   * the KEY NAME is fixed by the wireframe (`data-do-directory-variant`),
   * only its exact array-path within the element may vary by
   * implementation choice.
   *
   * @param array $element
   *   The rendered view element.
   *
   * @return string|null
   *   The resolved variant attribute value, or NULL if not found anywhere
   *   plausible in the element.
   */
  protected function wrapperVariantAttribute(array $element): ?string {
    $candidates = [
      $element['#attributes']['data-do-directory-variant'] ?? NULL,
      $element['#content_attributes']['data-do-directory-variant'] ?? NULL,
      $element['#view_content_attributes']['data-do-directory-variant'] ?? NULL,
    ];
    foreach ($candidates as $value) {
      if ($value !== NULL) {
        return $value;
      }
    }
    return NULL;
  }

}
