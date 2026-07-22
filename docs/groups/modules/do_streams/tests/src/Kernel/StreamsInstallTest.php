<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Confirms do_streams enables and uninstalls cleanly with zero schema changes.
 *
 * Issue #109 (epic #108), acceptance criterion "Zero schema changes; module
 * enables/uninstalls cleanly; existing suite stays green." do_streams reads
 * only existing tables (`group_relationship_field_data`, `flagging`,
 * `do_discovery_hot_score`, `comment_entity_statistics`, `node_field_data`)
 * per the brief/survey — it must not install ANY schema of its own (no
 * `do_streams.install` hook_schema()).
 *
 * Layer choice: a lightweight KernelTestBase (not GroupsKernelTestBase) —
 * this test needs only the module installer, not the full Group fixture
 * base, matching the "standard Drupal module test" survey.md item 6 calls
 * for.
 *
 * Baseline warm-up note: several CORE tables (`router`, `menu_tree`,
 * `cachetags`, `cache_file_parsing`, `users_data`, ...) are created lazily by
 * unrelated core subsystems (router rebuild, users_data first write, etc.)
 * the FIRST time ANY module is installed/uninstalled in a kernel test,
 * regardless of that module's own schema. To isolate the assertion to
 * do_streams' OWN schema contribution, this test warms those lazy tables up
 * by installing and uninstalling a schema-free "control" module
 * (`do_group_pin`, whose zero-new-schema behavior is not itself in question
 * here) BEFORE taking the "before" snapshot for do_streams.
 *
 * @group do_streams
 * @group do_tests
 */
class StreamsInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'datetime',
    'views',
    'flag',
    'comment',
    'taxonomy',
    'group',
    'gnode',
    'options',
    'do_discovery',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);

    // Warm up router/menu/cache tables + any other lazily-created core
    // tables that ANY module install/uninstall cycle triggers, using a
    // schema-free control module (do_group_pin, per the class docblock) so
    // the do_streams-specific tests below see a stable baseline.
    $installer = $this->container->get('module_installer');
    $installer->install(['do_group_pin'], TRUE);
    $installer->uninstall(['do_group_pin'], TRUE);
  }

  /**
   * do_streams installs cleanly and creates no new database tables.
   */
  public function testModuleInstallsWithZeroSchemaChanges(): void {
    $tablesBefore = $this->listAllTables();

    /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
    $installer = $this->container->get('module_installer');
    $installed = $installer->install(['do_streams'], TRUE);
    $this->assertTrue($installed, 'do_streams installs without error.');
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('do_streams'),
      'do_streams is enabled after install.'
    );

    $tablesAfter = $this->listAllTables();
    $newTables = array_diff($tablesAfter, $tablesBefore);
    $this->assertSame(
      [],
      array_values($newTables),
      'Enabling do_streams creates ZERO new database tables beyond the warmed-up baseline (it reads only existing tables, per the brief\'s no-schema-changes acceptance criterion). New tables found: ' . implode(', ', $newTables)
    );

    // A bare .info.yml with no plugins/hooks would trivially satisfy
    // "installs cleanly with zero schema changes" above, which would make
    // this test pass vacuously before the feature exists. Anchor "installs
    // cleanly" to the module's OWN minimum contract per the brief: the two
    // Views scope plugins are discoverable, and the shared shell theme hook
    // is registered.
    $filterManager = $this->container->get('plugin.manager.views.filter');
    $this->assertTrue(
      $filterManager->hasDefinition('do_streams_membership_scope'),
      'The do_streams_membership_scope Views filter plugin is discoverable after install.'
    );
    $this->assertTrue(
      $filterManager->hasDefinition('do_streams_following_scope'),
      'The do_streams_following_scope Views filter plugin is discoverable after install.'
    );

    $themeRegistry = $this->container->get('theme.registry')->get();
    $this->assertArrayHasKey(
      'do_streams_shell',
      $themeRegistry,
      'The do_streams_shell theme hook is registered after install.'
    );
  }

  /**
   * do_streams uninstalls cleanly, leaving no residual tables or config.
   */
  public function testModuleUninstallsCleanly(): void {
    /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
    $installer = $this->container->get('module_installer');
    $installer->install(['do_streams'], TRUE);

    $tablesBeforeUninstall = $this->listAllTables();
    $uninstalled = $installer->uninstall(['do_streams'], TRUE);
    $this->assertTrue($uninstalled, 'do_streams uninstalls without error.');
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('do_streams'),
      'do_streams is no longer enabled after uninstall.'
    );

    $tablesAfterUninstall = $this->listAllTables();
    $this->assertSame(
      $tablesBeforeUninstall,
      $tablesAfterUninstall,
      'Uninstalling do_streams leaves the table set unchanged (it owned no tables to begin with).'
    );
  }

  /**
   * Lists every table currently in the test database.
   *
   * @return string[]
   *   Sorted table names.
   */
  protected function listAllTables(): array {
    $connection = $this->container->get('database');
    $tables = $connection->schema()->findTables('%');
    sort($tables);
    return $tables;
  }

}
