<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\PermissionScopeInterface;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * #142 MC-3: directory location + primary-language exposed filters.
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
 * "Fixtures & test authorship"). Likewise the two new field config entities
 * (storage + instance) are installed from module-local fixtures, since F has
 * not yet authored `docs/groups/config/field.storage.group.field_group_location_text.yml`
 * at RED time.
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
    'do_tests',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['views']);

    // Install the shipped `all_groups` view + the two new location-field
    // config entities from module-local fixtures (mirrors
    // PinnedStreamOrderingTest's FileStorage pattern). These fixtures are
    // maintained by T as a byte-identical copy of what F is expected to ship
    // in docs/groups/config/ — until F lands, the field fixtures below are
    // T's OWN best-effort authoring of the acceptance-criteria shape, so a
    // failure here may legitimately be "the fixture doesn't match what F
    // ships" rather than "F's view is wrong"; see handoff-T-red.md.
    $fixtures = new \Drupal\Core\Config\FileStorage(__DIR__ . '/../../fixtures/config');
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
    // access-safety test below ({@see self::testAnonymousExecutionExcludesArchivedGroup()})
    // isolates the archived/unpublished-exclusion behavior specifically,
    // rather than conflating it with "anonymous can see nothing at all."
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
  protected function loadView(): \Drupal\views\ViewExecutable {
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
      'field_group_language',
      $language_filter['field'] ?? NULL,
      'The language filter targets field_group_language.'
    );

    // Location filter: string "contains" against field_group_location_text,
    // exposed.
    $location_filter = NULL;
    foreach ($filters as $filter) {
      if (($filter['field'] ?? NULL) === 'field_group_location_text') {
        $location_filter = $filter;
        break;
      }
    }
    $this->assertNotNull($location_filter, 'An exposed filter on field_group_location_text is declared on the default display.');
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
