<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\RoleInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * MC-3 (#142): directory location + primary-language exposed filters.
 *
 * Pins the acceptance criteria from
 * `docs/planning/handoffs/142-directory-filters/brief.md` (v2):
 *
 * - `views.view.all_groups.yml`'s default display declares an EXPOSED filter
 *   on `field_group_language` using `plugin_id: language` (core Language
 *   filter), and a second EXPOSED filter `location` on the new
 *   `field_group_location_text` string field using operator `contains`.
 * - The new `field_group_location_text` field storage + instance exist on
 *   group.community_group.
 * - Group access-control is preserved: executing the view as an ANONYMOUS
 *   user (never UID 1, per A's r1 warn #4) excludes an archived
 *   (unpublished) group from the base result set, exactly as the view's
 *   existing `status` filter already enforces for the unfiltered view today.
 *
 * Layer choice: kernel view-execution + entity-schema inspection, mirroring
 * {@see \Drupal\do_group_pin\Kernel\PinnedStreamOrderingTest} (loads a real
 * view via `Views::getView()` and executes it against a live DB) and
 * {@see \Drupal\Tests\do_tests\Kernel\AccessPolicyEnforcementTest} (anonymous
 * / non-privileged current user). `views.view.all_groups.yml` is NOT shipped
 * in any module's `config/install` (it lives in `docs/groups/config/`, parallel
 * to `views.view.group_content_stream`), so it is installed here from a
 * MODULE-LOCAL fixture copy (`tests/fixtures/config/`) — never a
 * source-relative `__DIR__/../../../../../config` path, which passes in the
 * source tree but fails in CI's assembled layout (PROJECT_CONTEXT.md
 * "Fixtures & test authorship"). Likewise the two new location-field config
 * entities (storage + instance) are installed from module-local fixtures,
 * mirrored from F's real, GREEN-state shipped config as of Phase 6 (see the
 * class-level comment on the `field:` key convention below).
 *
 * `field_group_language`'s own storage/instance are NOT fixture-installed:
 * they are the pre-existing baseline field (already on origin/main, owned by
 * `do_group_language`), installed here the same way
 * {@see \Drupal\Tests\do_group_language\Kernel\GroupLanguageNegotiationTest}
 * installs it — direct `FieldStorageConfig`/`FieldConfig::create()` calls,
 * not a fixture. `do_group_language` itself is enabled in `$modules` so its
 * `hook_field_views_data_alter()` (which rewrites the Views-data `filter.id`
 * for `field_group_language`'s dedicated-table column from the generic
 * `string` to `language`) actually runs — without it, `initHandlers()`
 * resolves the language filter to `Broken`, exactly as F's handoff traces.
 * A `ConfigurableLanguage` for French is also created because core's
 * `LanguageFilter::access()` hard-gates on `LanguageManager::isMultilingual()`
 * (more than one configured language) — see F's handoff-F.md "Design
 * decisions" #1 for the equivalent production-site finding.
 *
 * PHASE 6 REPAIR NOTE (T): `testViewDeclaresBothExposedFilters()`'s `field:`
 * assertions originally used the BARE field name (`field_group_language`,
 * `field_group_location_text`). Verified empirically against a real
 * assembled + config-imported site
 * (`\Drupal::service('views.views_data')->get('group__field_group_language')`)
 * that a dedicated-table configurable field's Views-data ONLY exposes a
 * `filter` sub-key under the `_value`-suffixed key
 * (`field_group_language_value`); the bare key has no `filter` sub-key at
 * all, so a filter config using the bare name resolves to
 * `Drupal\views\Plugin\views\filter\Broken` at handler-init time (confirmed
 * live: `get_class($view->filter['field_group_language'])` was `Broken` with
 * the bare name, `LanguageFilter`/`StringFilter` with the suffixed name).
 * F's shipped `docs/groups/config/views.view.all_groups.yml` correctly uses
 * the `_value`-suffixed names — this is what a genuine Drupal "Add filter"
 * UI action itself stores. The assertions and the fixture below were updated
 * to match this working, real-world shape. Also, `testExposedFormIsNonEmpty()`
 * legitimately failed at first repair pass (2 exposed widgets, not 3) because
 * `setUp()` had not yet installed `field_group_language`'s field
 * storage/instance nor enabled `do_group_language` — with the field entirely
 * undefined, the language filter's handler resolved to `Broken` (which is
 * never exposed), masking the real assertion. Fixed by installing the field
 * and module here, matching `GroupLanguageNegotiationTest`'s own convention
 * for this same field.
 *
 * @group do_tests
 * @group views
 */
class DirectoryFiltersTest extends GroupsKernelTestBase {

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
    'language',
    'do_group_language',
    'do_tests',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['views']);

    // field_group_language: the pre-existing baseline field (already on
    // origin/main, owned by do_group_language) — installed via direct API
    // calls, the same way GroupLanguageNegotiationTest::setUp() installs it,
    // NOT from a fixture (there is nothing new about this field's shape in
    // this story).
    FieldStorageConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'type' => 'language',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Primary language',
    ])->save();

    // Core's LanguageFilter::access() hard-gates on
    // LanguageManager::isMultilingual() (>1 configured language). Without a
    // second language, the exposed language filter is unconditionally hidden
    // from initHandlers() regardless of the view's own config — see F's
    // handoff-F.md "Design decisions" #1 for the identical production-site
    // finding.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Install the shipped `all_groups` view + the two new location-field
    // config entities from module-local fixtures (mirrors
    // PinnedStreamOrderingTest's FileStorage pattern). As of Phase 6 GREEN,
    // these fixtures are synced from F's real, shipped
    // `docs/groups/config/*.yml` (minus two render-only field-formatter keys
    // that require the full entity-field Views integration to resolve their
    // config schema — `fields.label.settings.link_to_entity` and
    // `fields.created.date_format` — which have zero effect on the
    // query/filter behavior under test; see PinnedStreamOrderingTest's
    // identical reduction for `views.view.group_content_stream.yml`).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = $this->container->get('entity_type.manager');

    foreach ([
      'field_storage_config' => 'field.storage.group.field_group_location_text',
      'field_config' => 'field.field.group.community_group.field_group_location_text',
      'view' => 'views.view.all_groups',
    ] as $storage_id => $config_name) {
      $data = $fixtures->read($config_name);
      $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
      $entity_type_manager->getStorage($storage_id)->create($data)->save();
    }

    // The view's base table (groups_field_data) is access-controlled by
    // Group's own query alter, which hides a group from a viewer lacking
    // `view group` regardless of the view's own `status` filter (mirrors the
    // access-safety note in PinnedStreamOrderingTest::setUp()). Grant an
    // OUTSIDER-scope `view group` permission to the ANONYMOUS role so the
    // access-safety test below
    // ({@see self::testAnonymousExecutionExcludesArchivedGroup()}) isolates
    // the archived/unpublished-exclusion behavior specifically, rather than
    // conflating it with "anonymous can see nothing at all.".
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::ANONYMOUS_ID,
      'permissions' => ['view group'],
    ]);
  }

  /**
   * Loads the group directory view under test.
   */
  protected function loadView(): ViewExecutable {
    $view = Views::getView('all_groups');
    $this->assertNotNull($view, 'The all_groups view loaded.');
    return $view;
  }

  /**
   * The view loads and its default display declares both exposed filters.
   *
   * Asserts the config shape directly (no query execution needed for this
   * assertion): the `field_group_language` filter uses `plugin_id: language`
   * and is exposed, and the `location` filter targets
   * `field_group_location_text` with `operator: contains` and is exposed.
   *
   * The `field:` assertions use the `_value`-suffixed Views-data key
   * (`field_group_language_value` / `field_group_location_text_value`), not
   * the bare field name — see the class-level "PHASE 6 REPAIR NOTE" for the
   * empirical trace showing the bare name resolves to a Broken handler.
   */
  public function testViewDeclaresBothExposedFilters(): void {
    $view = $this->loadView();
    $view->setDisplay('default');
    $filters = $view->display_handler->getOption('filters');

    $this->assertIsArray($filters, 'The default display has a filters array.');

    // Language filter: plugin_id `language`, targeting field_group_language,
    // exposed.
    $language_filter = NULL;
    foreach ($filters as $filter) {
      if (($filter['plugin_id'] ?? NULL) === 'language') {
        $language_filter = $filter;
        break;
      }
    }
    $this->assertNotNull($language_filter, 'An exposed filter using plugin_id "language" is declared on the default display.');
    $this->assertTrue($language_filter['exposed'] ?? FALSE, 'The language filter is exposed.');
    $this->assertSame(
      'field_group_language_value',
      $language_filter['field'] ?? NULL,
      'The language filter targets the field_group_language_value Views-data column (the _value-suffixed key is required for a working, non-Broken handler).'
    );

    // Location filter: string "contains" against field_group_location_text,
    // exposed.
    $location_filter = NULL;
    foreach ($filters as $filter) {
      if (($filter['field'] ?? NULL) === 'field_group_location_text_value') {
        $location_filter = $filter;
        break;
      }
    }
    $this->assertNotNull($location_filter, 'An exposed filter on field_group_location_text_value is declared on the default display.');
    $this->assertSame('contains', $location_filter['operator'] ?? NULL, 'The location filter operator is "contains".');
    $this->assertTrue($location_filter['exposed'] ?? FALSE, 'The location filter is exposed.');
  }

  /**
   * The default display's exposed form is non-empty (both controls render).
   *
   * A coarse structural check that the view actually exposes a form section
   * (as opposed to e.g. an admin-only unexposed filter) — the fine-grained
   * per-control assertions live in
   * {@see self::testViewDeclaresBothExposedFilters()}.
   */
  public function testExposedFormIsNonEmpty(): void {
    $view = $this->loadView();
    $view->setDisplay('default');
    $view->initHandlers();

    $exposed_widgets = [];
    foreach ($view->filter as $id => $handler) {
      if ($handler->isExposed()) {
        $exposed_widgets[$id] = $handler;
      }
    }

    $this->assertNotEmpty($exposed_widgets, 'The view has at least one exposed filter widget.');
    $this->assertGreaterThanOrEqual(
      3,
      count($exposed_widgets),
      'The view exposes at least 3 filters: the pre-existing "search" label filter plus the new language and location filters.'
    );
  }

  /**
   * The new field_group_location_text field storage + instance exist.
   */
  public function testLocationTextFieldIsAttachedToGroupBundle(): void {
    /** @var \Drupal\field\FieldStorageConfigInterface|null $storage */
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('field_storage_config')
      ->load('group.field_group_location_text');
    $this->assertNotNull($storage, 'field_group_location_text field storage exists on the group entity type.');
    $this->assertSame('string', $storage->getType(), 'field_group_location_text is a string field.');

    /** @var \Drupal\field\FieldConfigInterface|null $instance */
    $instance = $this->container->get('entity_type.manager')
      ->getStorage('field_config')
      ->load('group.community_group.field_group_location_text');
    $this->assertNotNull($instance, 'field_group_location_text is instantiated on group.community_group.');
  }

  /**
   * Anonymous execution of the view excludes an archived (unpublished) group.
   *
   * Access-safety per A's r1 warn #4: the kernel test must run as ANONYMOUS
   * (never UID 1). Seeds 4 groups per the story's spec — (a) public+English+
   * "Berlin", (b) public+French+"Paris", (c) public+English+"Portland",
   * (d) ARCHIVED (unpublished)+English+"Berlin" — and asserts the archived
   * group (d) never appears in the base (unfiltered) result set for an
   * anonymous visitor, exactly as the view's pre-existing `status` filter
   * enforces today. This isolates the archived-exclusion behavior from the
   * new filters (which are additive) so a regression in either area fails
   * this test independently for the right reason.
   */
  public function testAnonymousExecutionExcludesArchivedGroup(): void {
    // Never UID 1 — explicit anonymous session.
    $this->setCurrentUser(new AnonymousUserSession());

    $groupA = $this->createGroup([
      'label' => 'Berlin Public English',
      'status' => 1,
      'field_group_location_text' => 'Berlin',
      'field_group_language' => 'en',
    ]);
    $groupB = $this->createGroup([
      'label' => 'Paris Public French',
      'status' => 1,
      'field_group_location_text' => 'Paris',
      'field_group_language' => 'fr',
    ]);
    $groupC = $this->createGroup([
      'label' => 'Portland Public English',
      'status' => 1,
      'field_group_location_text' => 'Portland',
      'field_group_language' => 'en',
    ]);
    $groupD = $this->createGroup([
      'label' => 'Berlin Archived English',
      // Archived / unlisted: unpublished.
      'status' => 0,
      'field_group_location_text' => 'Berlin',
      'field_group_language' => 'en',
    ]);

    $view = $this->loadView();
    $view->setDisplay('default');
    $view->execute();

    $ids = array_map(static fn ($row) => (int) $row->id, $view->result);

    $this->assertContains((int) $groupA->id(), $ids, 'Public group A is visible to an anonymous visitor.');
    $this->assertContains((int) $groupB->id(), $ids, 'Public group B is visible to an anonymous visitor.');
    $this->assertContains((int) $groupC->id(), $ids, 'Public group C is visible to an anonymous visitor.');
    $this->assertNotContains(
      (int) $groupD->id(),
      $ids,
      'The archived (unpublished) group D is NOT visible to an anonymous visitor, even though it shares a location with A.'
    );
  }

}
