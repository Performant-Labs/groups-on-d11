<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * ST-8 (#130): `ModelToggleHooks::viewsPreRender()` /
 * `preprocessViewsView()` mount the SC-F1 variant switcher over `/stream`
 * (view `activity_stream`, display `page_1`) and set the
 * `data-do-stream-model` wrapper-attribute contract.
 *
 * Pins the acceptance criteria from
 * `docs/handoffs/st8-model-toggle-130/brief.md` (AC-1, AC-2, AC-3, AC-7,
 * AC-9) + wireframe.md Surface 1/2:
 *
 * - `activity_stream:page_1`, when FULLY rendered through Drupal's Renderer
 *   (not just `$view->element`'s pre-`#pre_render` snapshot — see
 *   `DirectoryTogglePreRenderTest`'s class docblock, reused verbatim here,
 *   for why `renderViewToHtml()` is required to observe `#header`),
 *   produces HTML carrying the SC-F1 variant switcher with exactly two
 *   options in order (Content view / Activity view), Content view carrying
 *   `available: false` (brief.md scope item #5; wireframe.md Surface 1).
 * - The view's own render array declares the `url.query_args:variant` cache
 *   context directly (AC-9), matching #124's precedent.
 * - The view wrapper resolves `data-do-stream-model` from the request's
 *   `?variant=` query arg using `VariantSwitcher::resolveCurrent()`'s
 *   first-available-fallback rule (AC-2/AC-3): no query -> activity (the
 *   only available option); explicit `?variant=activity` -> activity;
 *   unavailable `?variant=content` -> still falls back to activity (first
 *   available), never blank/broken.
 * - `do_showcase/switcher` and `do_streams/model-toggle` libraries are
 *   attached (brief.md scope items #2/#3).
 * - The hook fires ONLY for view id `activity_stream` display `page_1` — a
 *   different view (`all_groups`) must not gain a switcher or the cache
 *   context.
 *
 * Layer choice: kernel view-execution against a real `ViewExecutable`,
 * mirroring `DirectoryTogglePreRenderTest`'s `Views::getView()` + fixture-
 * installed `views.view.activity_stream.yml` pattern — the cheapest tier
 * that can actually invoke `hook_views_pre_render` (a Views-runtime hook), a
 * pure Unit test cannot exercise (no view execution machinery) and a
 * Functional/E2E test would exercise redundantly for what is a server-side
 * render-array/cache-metadata contract, not a click-through interaction
 * (that lives in tests/e2e/model-toggle.spec.ts).
 *
 * `views.view.activity_stream.yml` and `views.view.all_groups.yml` (the
 * second view id used to pin the "ONLY activity_stream.page_1" scope
 * assertion) are installed from MODULE-LOCAL fixtures
 * (`tests/fixtures/config/views.view.activity_stream.yml`,
 * `tests/fixtures/config/views.view.all_groups.yml`) — never a source-
 * relative `__DIR__/../../../../../config` path (PROJECT_CONTEXT.md
 * "Fixtures & test authorship"). Both fixtures are deliberately minimal
 * synthetic views (NOT copies of the real, heavier views) — they exist
 * solely to prove the hook fires for the right view/display and does not
 * fire for a different one, so they use the cheapest possible base table
 * (`node_field_data` + a `fields` row) rather than pulling in
 * `activity_stream`'s real `stream_card` view-mode/comment-module
 * machinery.
 *
 * NOTE (RED, Phase 4): `Drupal\do_streams\Hook\ModelToggleHooks` and
 * `Drupal\do_showcase\VariantSwitcher::streamModelOptions()` do not exist
 * yet. This test is expected to fail at container-build / method-call time
 * until F implements both. See handoff-T-red.md for the exact RED output
 * and implementation hints (hook signatures, service wiring).
 *
 * @group do_streams
 * @group views
 */
class StreamModelTogglePreRenderTest extends GroupsKernelTestBase {

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
    'do_streams',
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
      'view' => 'views.view.activity_stream',
    ] as $storage_id => $config_name) {
      $data = $fixtures->read($config_name);
      $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
      $entity_type_manager->getStorage($storage_id)->create($data)->save();
    }
  }

  /**
   * Pushes a request onto the request stack WITH a mock session attached.
   *
   * Mirrors `DirectoryTogglePreRenderTest::pushRequestWithSession()` exactly
   * — see that method's docblock for why a mock session must be attached
   * before a directly-pushed request, to avoid `KernelTestBase::tearDown()`
   * throwing `SessionNotFoundException`.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to push.
   */
  protected function pushRequestWithSession(Request $request): void {
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Loads the `activity_stream` view and sets its `page_1` display + request.
   *
   * @param string $query_string
   *   (optional) A raw query string (e.g. 'variant=activity') to attach to
   *   the current request before the view is rendered.
   *
   * @return \Drupal\views\ViewExecutable
   *   The built view, display set to page_1, not yet executed/rendered.
   */
  protected function buildActivityStreamView(string $query_string = ''): ViewExecutable {
    $request = Request::create('/stream' . ($query_string ? '?' . $query_string : ''));
    parse_str($query_string, $params);
    $request->query->replace($params);
    $this->pushRequestWithSession($request);

    $view = Views::getView('activity_stream');
    $this->assertNotNull($view, 'The activity_stream view loaded.');
    $view->setDisplay('page_1');
    return $view;
  }

  /**
   * Fully renders a view display so `hook_views_pre_render` actually fires.
   *
   * Mirrors `DirectoryTogglePreRenderTest::renderView()` — see that method's
   * docblock for why this returns the PRE-`#pre_render` snapshot, correct
   * for `#cache`/`#attributes`/`#attached` but NOT for `#header` (use
   * `renderViewToHtml()` for that).
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
   * Mirrors `DirectoryTogglePreRenderTest::renderViewToHtml()` exactly — the
   * only way to correctly observe `ModelToggleHooks::preprocessViewsView()`'s
   * switcher injection into `$variables['header']`.
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
   * exactly two options in order (Content view / Activity view), Content
   * view carrying `available: false` — observed via a FULL render pass.
   */
  public function testSwitcherInjectedWithTwoOptionsInOrder(): void {
    $view = $this->buildActivityStreamView();
    $html = $this->renderViewToHtml($view);

    $this->assertStringContainsString(
      'data-do-showcase-instance="stream.model"',
      $html,
      'The rendered view header carries the stream.model switcher instance (VariantSwitcher::build() via ModelToggleHooks::preprocessViewsView()).'
    );

    // Exactly two options, in order: content, activity.
    preg_match_all('/data-do-showcase-id="([^"]+)"/', $html, $matches);
    $this->assertSame(['content', 'activity'], $matches[1] ?? [], 'Exactly two options, in order: content, activity.');

    // The content option is unavailable: aria-disabled + "(soon)" suffix.
    preg_match('#<a\b[^>]*data-do-showcase-id="content"[^>]*>#s', $html, $content_tag_match);
    $this->assertNotEmpty($content_tag_match, 'The content option\'s opening <a> tag must be found in the rendered HTML.');
    $this->assertStringContainsString(
      'aria-disabled="true"',
      $content_tag_match[0] ?? '',
      'The content option\'s <a> tag must carry aria-disabled="true" (available: false).'
    );
    $this->assertStringContainsString('Content view (soon)', $html, 'The content option\'s visible label must carry the truthful "(soon)" suffix.');
    $this->assertStringContainsString('Activity view', $html, 'The activity option\'s visible label must read "Activity view".');
  }

  /**
   * The view's own render array declares the `url.query_args:variant` cache
   * context directly (AC-9) — matching #124's precedent exactly.
   */
  public function testViewDeclaresUrlQueryArgsVariantCacheContextDirectly(): void {
    $view = $this->buildActivityStreamView();
    $element = $this->renderView($view);

    $contexts = $element['#cache']['contexts'] ?? [];
    $this->assertContains(
      'url.query_args:variant',
      $contexts,
      'The view element must declare url.query_args:variant on its OWN #cache[\'contexts\'] (set inside viewsPreRender()), so the wrapper-attribute decision (made before VariantSwitcher::build() runs) itself carries the varying context.'
    );
  }

  /**
   * No `?variant=` query -> the wrapper resolves to "activity" (the only
   * available option, AC-2).
   */
  public function testNoQueryParamDefaultsWrapperToActivity(): void {
    $view = $this->buildActivityStreamView();
    $element = $this->renderView($view);

    $attribute = $this->wrapperModelAttribute($element);
    $this->assertSame('activity', $attribute, 'With no ?variant= query, the wrapper resolves to "activity" — the only available option (wireframe.md Surface 1 "State: default").');
  }

  /**
   * `?variant=activity` -> the wrapper resolves to "activity" (AC-4, no
   * reload flash — server-resolved before the view renders).
   */
  public function testActivityQueryParamSetsWrapperToActivity(): void {
    $view = $this->buildActivityStreamView('variant=activity');
    $element = $this->renderView($view);

    $attribute = $this->wrapperModelAttribute($element);
    $this->assertSame('activity', $attribute, 'With ?variant=activity, the wrapper resolves to "activity" (explicit deep link).');
  }

  /**
   * `?variant=content` (unavailable) -> falls back to "activity" (first
   * available), NEVER a blank/broken render (AC-3, wireframe.md Surface 1
   * "State: `?variant=content` fallback").
   */
  public function testUnavailableContentQueryParamFallsBackToActivity(): void {
    $view = $this->buildActivityStreamView('variant=content');
    $element = $this->renderView($view);

    $attribute = $this->wrapperModelAttribute($element);
    $this->assertSame('activity', $attribute, 'An unavailable ?variant=content must fall back to the first available option (activity), matching VariantSwitcher::resolveSelection()\'s contract — never blank/broken.');
  }

  /**
   * `?variant=bogus` (unknown id) -> also falls back to "activity" (first
   * available) — the same graceful-fallback contract for an unrecognized
   * value, not just an unavailable one.
   */
  public function testUnknownQueryParamFallsBackToActivity(): void {
    $view = $this->buildActivityStreamView('variant=bogus');
    $element = $this->renderView($view);

    $attribute = $this->wrapperModelAttribute($element);
    $this->assertSame('activity', $attribute, 'An unrecognized ?variant=bogus must fall back to the first available option (activity), same as an unavailable id.');
  }

  /**
   * The `do_showcase/switcher` and `do_streams/model-toggle` libraries are
   * both attached to the rendered view element.
   */
  public function testSwitcherAndModelToggleLibrariesAttached(): void {
    $view = $this->buildActivityStreamView();
    $element = $this->renderView($view);

    $libraries = $element['#attached']['library'] ?? [];
    $this->assertContains('do_showcase/switcher', $libraries, 'The do_showcase/switcher library must be attached so the client-side toggle JS runs.');
    $this->assertContains('do_streams/model-toggle', $libraries, 'The do_streams/model-toggle library (this story\'s new CSS-only library) must be attached.');
  }

  /**
   * The hook is bound ONLY to view id `activity_stream` display `page_1` — a
   * different, unrelated view (`some_other_view`, a synthetic fixture no
   * other hook in this codebase targets) must NOT gain the stream-model
   * switcher or the `do_streams/model-toggle` library.
   *
   * NOTE: `all_groups` is deliberately NOT used as the negative-case view
   * here (unlike DirectoryTogglePreRenderTest's own inverse pair) — #124's
   * ALREADY-SHIPPED `DoShowcaseHooks::viewsPreRender()` legitimately fires
   * for `all_groups:page_1` and sets the SAME shared
   * `url.query_args:variant` cache context for its OWN (directory.layout)
   * switcher instance, which would make an assertion against that shared
   * context a false negative for this hook's OWN scoping — the correct,
   * ModelToggleHooks-specific signal is the ABSENCE of the `switcher` header
   * key and the `do_streams/model-toggle` library, not the cache context
   * (which is legitimately reused across independent switcher instances).
   */
  public function testHookDoesNotFireForADifferentViewId(): void {
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $data = $fixtures->read('views.view.some_other_view');
    $this->assertNotFalse($data, 'The some_other_view fixture exists and is readable.');
    $this->container->get('entity_type.manager')->getStorage('view')->create($data)->save();

    $request = Request::create('/some-other-view');
    $this->pushRequestWithSession($request);

    $view = Views::getView('some_other_view');
    $this->assertNotNull($view, 'The some_other_view view loaded.');
    $view->setDisplay('page_1');
    $element = $this->renderView($view);

    $this->assertArrayNotHasKey(
      'switcher',
      $element['#header'] ?? [],
      'A different view (some_other_view) must NOT gain the stream-model switcher in its #header — the hook is scoped to activity_stream.page_1 only.'
    );
    $libraries = $element['#attached']['library'] ?? [];
    $this->assertNotContains(
      'do_streams/model-toggle',
      $libraries,
      'A different view (some_other_view) must NOT gain the do_streams/model-toggle library — that is specific to the activity_stream.page_1 model-toggle hook.'
    );
  }

  /**
   * Extracts the resolved `data-do-stream-model` value from the rendered
   * view element.
   *
   * Mirrors `DirectoryTogglePreRenderTest::wrapperVariantAttribute()` — see
   * that method's docblock for why several candidate array paths are
   * checked (the KEY NAME is fixed by the wireframe, `data-do-stream-model`;
   * only its exact array-path within the element may vary by
   * implementation choice).
   *
   * @param array $element
   *   The rendered view element.
   *
   * @return string|null
   *   The resolved model attribute value, or NULL if not found anywhere
   *   plausible in the element.
   */
  protected function wrapperModelAttribute(array $element): ?string {
    $candidates = [
      $element['#attributes']['data-do-stream-model'] ?? NULL,
      $element['#content_attributes']['data-do-stream-model'] ?? NULL,
      $element['#view_content_attributes']['data-do-stream-model'] ?? NULL,
    ];
    foreach ($candidates as $value) {
      if ($value !== NULL) {
        return $value;
      }
    }
    return NULL;
  }

}
