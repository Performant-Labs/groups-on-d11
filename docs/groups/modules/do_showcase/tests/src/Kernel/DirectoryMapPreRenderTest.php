<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * #125 SC-6: `DoShowcaseHooks::viewsPreRender()` mounts the now-LIVE `map`
 * variant over `/all-groups` — attaching `do_showcase/directory-map` and
 * resolving the wrapper's `data-do-directory-variant` to `"map"` when
 * `?variant=map` is requested.
 *
 * Pins the acceptance criteria from `docs/handoffs/0125-directory-map/`
 * brief.md + wireframe.md Surface 3 "Contract":
 *
 * - `?variant=map` resolves the wrapper's `data-do-directory-variant` to
 *   `"map"` (NOT a fallback to `"cards"`/`"compact"`, unlike the pre-#125
 *   behavior `DirectoryTogglePreRenderTest::testUnavailableMapQueryParamFallsBackToCompact()`
 *   pins for the OLD/unavailable state — that test remains a valid
 *   regression guard for as long as `map` was unavailable; #125 changes the
 *   underlying availability, so THIS test pins the NEW resolved-to-"map"
 *   behavior once `VariantSwitcher::directoryLayoutOptionIds()`'s `map`
 *   entry flips to available).
 * - The `do_showcase/directory-map` library (new, brief.md "Owned files")
 *   is attached to the view's render array alongside the existing
 *   `do_showcase/switcher` + `do_showcase/directory-compact` attaches —
 *   mirrors `DirectoryTogglePreRenderTest::testSwitcherAndDirectoryCompactLibrariesAttached()`'s
 *   pattern for the peer library.
 *
 * Layer choice: kernel view-execution against a real `ViewExecutable`,
 * identical rationale to `DirectoryTogglePreRenderTest`'s own class
 * docblock — the cheapest tier that can actually invoke
 * `hook_views_pre_render` (a Views-runtime hook a pure Unit test cannot
 * exercise), without redundantly re-testing the click-through interaction
 * that belongs in `tests/e2e/directory-map.spec.ts`.
 *
 * `views.view.all_groups.yml` is installed from the SAME module-local
 * fixture `DirectoryTogglePreRenderTest` already uses
 * (`tests/fixtures/config/views.view.all_groups.yml`) — never a
 * source-relative `__DIR__/../../../../../config` path (PROJECT_CONTEXT.md
 * "Fixtures & test authorship").
 *
 * @group do_showcase
 * @group views
 */
class DirectoryMapPreRenderTest extends \Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase {

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
   * Mirrors `DirectoryTogglePreRenderTest::pushRequestWithSession()` — see
   * that method's docblock for the full `SessionNotFoundException`
   * rationale.
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
   *   (optional) A raw query string (e.g. 'variant=map') to attach to the
   *   current request before the view is rendered.
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
   * Fires `hook_views_pre_render` and returns the view's render array.
   *
   * Mirrors `DirectoryTogglePreRenderTest::renderView()` — the pre-
   * `#pre_render` snapshot of `$view->element`, correct for
   * `#cache`/`#attributes`/`#attached` (the keys under test here), which
   * `elementPreRender()` never touches.
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
   * Extracts the resolved `data-do-directory-variant` value from the
   * rendered view element. Mirrors
   * `DirectoryTogglePreRenderTest::wrapperVariantAttribute()`.
   *
   * @param array $element
   *   The rendered view element.
   *
   * @return string|null
   *   The resolved variant attribute value, or NULL if not found.
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

  /**
   * `?variant=map` now resolves the wrapper's `data-do-directory-variant`
   * to `"map"` — NOT a fallback to `"compact"`/`"cards"` — because #125
   * flips `map`'s `available` flag to TRUE in
   * `VariantSwitcher::directoryLayoutOptionIds()`.
   *
   * RED reason: `VariantSwitcher::directoryLayoutOptionIds()` (source line
   * 85) still hardcodes `['id' => 'map', 'available' => FALSE]`, so
   * `resolveCurrent()` falls back to the first available option
   * ("compact") exactly as `DirectoryTogglePreRenderTest::testUnavailableMapQueryParamFallsBackToCompact()`
   * already pins for the pre-#125 state. This assertion (`'map'`) fails
   * against that unchanged source, which currently resolves to `'compact'`.
   */
  public function testMapQueryParamResolvesWrapperToMap(): void {
    $view = $this->buildAllGroupsView('variant=map');
    $element = $this->renderView($view);

    $attribute = $this->wrapperVariantAttribute($element);
    $this->assertSame(
      'map',
      $attribute,
      '#125 (SC-6): with ?variant=map, the wrapper must now resolve to "map" — the option is live, not a fallback candidate. Currently fails because VariantSwitcher.php line 85 still hardcodes available => FALSE for the map entry, so resolveCurrent() falls back to "compact".'
    );
  }

  /**
   * The `do_showcase/directory-map` library (brief.md "Owned files": new
   * library entry in `do_showcase.libraries.yml`, dependency on
   * `do_showcase/leaflet` + `do_showcase/switcher`) is attached to the
   * rendered view element when the `map` variant is active — mirroring how
   * `directory-compact` is unconditionally attached today (survey.md:
   * "Attached by the same hook").
   *
   * RED reason: `do_showcase/directory-map` does not exist as a library key
   * in `do_showcase.libraries.yml` yet, AND `viewsPreRender()` does not
   * attach it — this assertion fails because the library string is simply
   * absent from `$element['#attached']['library']`.
   */
  public function testDirectoryMapLibraryAttachedWhenMapVariantActive(): void {
    $view = $this->buildAllGroupsView('variant=map');
    $element = $this->renderView($view);

    $libraries = $element['#attached']['library'] ?? [];
    $this->assertContains(
      'do_showcase/directory-map',
      $libraries,
      '#125 (SC-6): the do_showcase/directory-map library must be attached so the Leaflet init JS + map CSS load. Currently fails: the library entry does not exist in do_showcase.libraries.yml and viewsPreRender() does not attach it.'
    );
  }

  /**
   * The pre-existing `do_showcase/switcher` and `do_showcase/directory-
   * compact` attaches are unaffected by this story (non-regression) —
   * `do_showcase/directory-map` is an ADDITION, not a replacement.
   */
  public function testExistingLibrariesRemainAttachedAlongsideDirectoryMap(): void {
    $view = $this->buildAllGroupsView('variant=map');
    $element = $this->renderView($view);

    $libraries = $element['#attached']['library'] ?? [];
    $this->assertContains('do_showcase/switcher', $libraries, 'do_showcase/switcher must remain attached (non-regression).');
    $this->assertContains('do_showcase/directory-compact', $libraries, 'do_showcase/directory-compact must remain attached (non-regression) — #125 adds a library, it does not remove one.');
  }

}
