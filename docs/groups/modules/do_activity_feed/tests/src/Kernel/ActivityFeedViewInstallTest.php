<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Confirms `views.view.activity_feed` installs on a real module install.
 *
 * Issue #129 ST-7, U's Phase 8 rework report ("Defect 1"): U observed
 * `Views::getView('activity_feed')` return NULL against a seeded site after
 * `drush en do_activity_feed`, yielding zero rows on `/activity` for a
 * member with qualifying activity in the DB.
 *
 * Root-cause finding: `config/install/views.view.activity_feed.yml` is
 * REQUIRED default config with no schema violations and no unmet
 * dependency at install time — `ModuleInstaller::install()`'s own
 * `installDefaultConfig()` call creates it unconditionally, in dependency
 * order, same as any other module's `config/install/*`. This did not
 * reproduce under three independent controlled attempts (manual
 * `drush pmu`/`drush en` cycling twice, and this test's own real
 * `module_installer->install()` call). do_activity_feed_install()'s
 * defensive hook_install() (do_activity_feed.install) is a belt-and-
 * suspenders self-heal for whatever transient/environment condition
 * produced U's observation (most plausibly a stale
 * `web/modules/custom/do_activity_feed/config/install/` copy at the exact
 * moment a real `drush en` ran — `assemble-config.sh`'s own rm-then-copy
 * per module is not atomic).
 *
 * Layer choice: a lightweight `KernelTestBase` (not `GroupsKernelTestBase`)
 * using the REAL `module_installer` service — deliberately NOT
 * `enableModules()` + `installConfig()` (the pattern
 * `ActivityFeedKernelTestBase::setUp()` uses for every OTHER test in this
 * suite), because `enableModules()` explicitly skips `hook_install()` and
 * the full default-config-install path (see its own docblock: "This method
 * does not install modules fully... hook_install() is not invoked"). Only
 * a real `ModuleInstallerInterface::install()` call exercises the exact
 * path `drush en` uses in production — anything less would not have caught
 * Defect 1 (nor prove `do_activity_feed_install()`'s self-heal actually
 * self-heals). Mirrors do_streams' own `StreamsInstallTest`
 * (docs/groups/modules/do_streams/tests/src/Kernel/StreamsInstallTest.php),
 * which uses the identical `module_installer->install()` pattern for the
 * same reason.
 *
 * @group do_activity_feed
 * @group do_tests
 */
class ActivityFeedViewInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'comment',
    'field',
    'text',
    'filter',
    'group',
    'gnode',
    'flag',
    'message',
    'message_notify',
    'views',
    'do_activity',
    'do_streams',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Warm up lazily-created core tables an install/uninstall cycle needs,
    // matching do_streams' own StreamsInstallTest::setUp() precedent
    // exactly (confirmed empirically here too — both throw SQLSTATE[42S02]
    // without the corresponding table):
    // - `users_data`: UserHooks' own modules_uninstalled hook deletes
    //   per-module user-data rows on ANY module uninstall.
    // - `flagging`: flag_entity_predelete() (core `flag` module hook,
    //   unrelated to do_activity_feed) queries this table whenever ANY
    //   config entity is deleted (e.g. the view, during uninstall).
    // Neither is a do_activity_feed production defect — both are missing
    // fixture-setup calls this test needs because it uninstalls a module,
    // which none of the OTHER tests in this suite do.
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('flagging');
  }

  /**
   * Asserts `views.view.activity_feed` exists in active config post-install.
   *
   * Asserts against the ACTIVE CONFIG STORAGE directly (not merely that the
   * `view` entity loads) — U's own repro used `drush config:get
   * views.view.activity_feed`, so this test pins the identical observation
   * point.
   */
  public function testActivityFeedViewInstallsOnRealModuleInstall(): void {
    /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
    $installer = $this->container->get('module_installer');
    $installed = $installer->install(['do_activity_feed'], TRUE);
    $this->assertTrue($installed, 'do_activity_feed installs without a fatal error.');
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('do_activity_feed'),
      'do_activity_feed is enabled after install.'
    );

    $activeStorage = $this->container->get('config.storage');
    $this->assertTrue(
      $activeStorage->exists('views.view.activity_feed'),
      'views.view.activity_feed exists in active config storage after a real module_installer->install() call (the same path `drush en` uses).'
    );

    /** @var \Drupal\views\ViewEntityInterface|null $view */
    $view = $this->container->get('entity_type.manager')->getStorage('view')->load('activity_feed');
    $this->assertNotNull($view, 'The activity_feed View entity loads.');

    $executable = $view->getExecutable();
    $this->assertNotNull($executable, 'Views::getView(activity_feed)\'s underlying executable resolves — the controller\'s Views::getView() call would not return NULL.');
  }

  /**
   * Uninstall + reinstall (U's exact repro sequence) still installs the view.
   *
   * U's repro command was `drush pmu do_activity_feed -y && drush en
   * do_activity_feed -y` — pins that exact cycle, not merely a single fresh
   * install, in case module re-installation onto residual state behaves
   * differently than a first install.
   */
  public function testActivityFeedViewSurvivesUninstallReinstallCycle(): void {
    /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
    $installer = $this->container->get('module_installer');
    $installer->install(['do_activity_feed'], TRUE);
    $installer->uninstall(['do_activity_feed'], TRUE);

    $activeStorage = $this->container->get('config.storage');
    $this->assertFalse(
      $activeStorage->exists('views.view.activity_feed'),
      'Precondition: uninstalling do_activity_feed removes its view config.'
    );

    $installer->install(['do_activity_feed'], TRUE);
    $this->assertTrue(
      $activeStorage->exists('views.view.activity_feed'),
      'Re-installing do_activity_feed after an uninstall re-creates views.view.activity_feed (U\'s exact pmu/en repro sequence).'
    );
  }

}
