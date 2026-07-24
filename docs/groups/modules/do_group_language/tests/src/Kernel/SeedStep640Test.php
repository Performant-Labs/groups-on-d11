<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_language\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reproduces + pins the three-layer step_640 seed cascade (#191).
 *
 * Diagnosis: docs/planning/handoffs/139-multilang-rtl/park-note-r2.md.
 * Brief: docs/planning/handoffs/191-seed-cascade-fix/brief.md.
 *
 * `docs/groups/scripts/step_640.php` (Multilingual infrastructure) fatals on
 * a fresh CI install because the assembled `core.extension.yml` already
 * lists the `language` module BEFORE `drush config:import` runs. Per
 * ModuleInstaller::install()'s own contract, `installDefaultConfig()` is only
 * invoked for modules being newly enabled during that call — a module
 * already present in the active `core.extension.yml` at config-import time
 * never gets its `config/install/*` re-applied. Three fatals cascade from
 * that single root cause:
 *
 * - Layer 1: if `language` were never enabled at all, plain
 *   `ConfigurableLanguage::save()` would fatal in
 *   `ConfigurableLanguageManager::updateLockedLanguageWeights()` calling
 *   `setWeight()` on the (entirely absent) locked entities. Not reproduced
 *   directly here because in the CI scenario `language` IS enabled (that is
 *   the whole problem) — Layer 2 is the actual failure this test drives.
 * - Layer 2 (main repro): `language`'s `config/install/language.entity.und
 *   .yml` and `language.entity.zxx.yml` (the two locked language entities)
 *   never install, because `language` was already active at config-import
 *   time. Any `ConfigurableLanguage::save()` after that still fatals in
 *   `updateLockedLanguageWeights()` — `setWeight()` on `null` — because it
 *   loads `und`/`zxx` by id and finds nothing.
 * - Layer 3: step_640 writes bare
 *   `third_party_settings.content_translation.enabled = TRUE` directly onto
 *   the `language.content_settings.node.$type` config object instead of
 *   going through the `ContentLanguageSettings` entity API. That produces a
 *   config record with no `target_entity_type_id` / `target_bundle`, which
 *   `ContentLanguageSettings::__construct()` rejects
 *   (`ContentLanguageSettingsException`) the next time anything loads it as
 *   an entity (surfaced downstream in step_795 saving a Message entity that
 *   touches a translatable bundle).
 *
 * Layer choice: a lightweight `KernelTestBase` (not `GroupsKernelTestBase` —
 * step_640 has nothing to do with groups). `language` + `content_translation`
 * are declared in static::$modules and enabled via KernelTestBase's own
 * container boot (a real module-install path, same mental model as
 * `ActivityFeedViewInstallTest`'s precedent of exercising the real
 * `config:install/` application), so the fixture matches "language already
 * listed in the assembled core.extension.yml." The gap CI's `config:import`
 * additionally leaves — `und`/`zxx` never installed because `language` was
 * already active — is reproduced explicitly below by deleting those two
 * locked entities after setUp() seeds them.
 *
 * The CI precondition ("language already in core.extension.yml, but its
 * config/install/ locked entities were skipped") is simulated by installing
 * `language` for real (so it behaves exactly as "already enabled" for the
 * rest of setUp/test) and then deleting the `und`/`zxx` locked entities that
 * install seeded — reproducing the exact gap `config:import` leaves when a
 * module is already active.
 *
 * @group do_group_language
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class SeedStep640Test extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'language',
    'content_translation',
  ];

  /**
   * Node bundles step_640 enables content translation for.
   */
  protected const NODE_BUNDLES = ['forum', 'documentation', 'event', 'post', 'page'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['field', 'node', 'language']);
    $this->installSchema('node', ['node_access']);

    // Create the five node bundles step_640 targets, so
    // ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle) has a
    // real bundle-config dependency to attach to (calculateDependencies()
    // resolves the bundle's config entity).
    foreach (static::NODE_BUNDLES as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => ucfirst($bundle)])->save();
    }

    // --- Simulate the CI cascade precondition -----------------------------
    // `language` is already a real, fully-enabled module by this point
    // (declared in static::$modules, enabled by KernelTestBase's own
    // container boot). That matches "language already listed in the
    // assembled core.extension.yml" for the rest of this test.
    //
    // What CI's config:import additionally skips (because language was
    // already active) is installDefaultConfig() for language's own
    // config/install/language.entity.und.yml and
    // config/install/language.entity.zxx.yml. installConfig(['language'])
    // above DOES install those (installConfig() always applies
    // config/install for the given module, regardless of enablement order)
    // — so we explicitly delete them here to reproduce the exact gap
    // `config:import` leaves in CI, where they are never installed at all.
    $storage = \Drupal::entityTypeManager()->getStorage('configurable_language');
    foreach (['und', 'zxx'] as $locked_id) {
      $entity = $storage->load($locked_id);
      $this->assertNotNull($entity, "Precondition: $locked_id starts out present (installConfig seeded it) before this test removes it to simulate the CI gap.");
      $entity->delete();
    }
    $this->assertNull($storage->load('und'), 'Precondition: und locked language entity is now absent, simulating the CI config-import gap.');
    $this->assertNull($storage->load('zxx'), 'Precondition: zxx locked language entity is now absent, simulating the CI config-import gap.');
  }

  /**
   * Runs the real step_640.php seed script in-process.
   *
   * Executes the actual script file so the test exercises the real code
   * path (per A's Phase-3 recommendation), not a reimplementation of its
   * logic.
   */
  private function runStep640(): void {
    // Capture the script's own echo output rather than letting it hit
    // PHPUnit's "risky: printed unexpected output" guard — the assertions
    // below are what pin behavior, not the script's progress messages.
    ob_start();
    require \Drupal::root() . '/../docs/groups/scripts/step_640.php';
    ob_end_clean();
  }

  /**
   * step_640 completes and backfills und/zxx, despite language being "already enabled".
   *
   * Pins Layer 1 + Layer 2: on current `main`, this fatals with
   * "Call to a member function setWeight() on null" inside
   * ConfigurableLanguageManager::updateLockedLanguageWeights(), because the
   * first `ConfigurableLanguage::create(['id' => 'de'])->save()` call
   * triggers a locked-language-weight recalculation that loads `und`/`zxx`
   * and finds nothing (deleted in setUp() to simulate the CI gap).
   */
  public function testStep640BackfillsLockedLanguagesAndCompletesWithoutFatal(): void {
    $this->runStep640();

    $storage = \Drupal::entityTypeManager()->getStorage('configurable_language');
    $this->assertNotNull($storage->load('und'), 'und locked language entity is backfilled by step_640.');
    $this->assertNotNull($storage->load('zxx'), 'zxx locked language entity is backfilled by step_640.');
  }

  /**
   * All 14 custom languages are created by step_640.
   */
  public function testStep640AddsAllFourteenCustomLanguages(): void {
    $this->runStep640();

    $expected = ['de', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt-br', 'ru', 'tr', 'uk', 'zh-hans', 'ar'];
    $storage = \Drupal::entityTypeManager()->getStorage('configurable_language');
    foreach ($expected as $langcode) {
      $this->assertNotNull($storage->load($langcode), "Custom language '$langcode' exists after step_640.");
    }
  }

  /**
   * language.types negotiation config is populated by step_640.
   */
  public function testStep640ConfiguresLanguageNegotiation(): void {
    $this->runStep640();

    $config = \Drupal::config('language.types');
    $enabled = $config->get('negotiation.language_interface.enabled');
    $this->assertIsArray($enabled, 'language.types negotiation.language_interface.enabled is populated.');
    $this->assertArrayHasKey('language-url', $enabled, 'The language-url negotiation method is configured.');
  }

  /**
   * content_translation module is installed by step_640 if not already.
   */
  public function testStep640InstallsContentTranslationModule(): void {
    $this->runStep640();

    $this->assertTrue(
      \Drupal::moduleHandler()->moduleExists('content_translation'),
      'content_translation module is enabled after step_640.'
    );
  }

  /**
   * Each target bundle gets a well-formed ContentLanguageSettings entity.
   *
   * Pins Layer 3: on current `main`, step_640 writes bare
   * `third_party_settings.content_translation.enabled = TRUE` directly to
   * the `language.content_settings.node.$type` config object, WITHOUT
   * target_entity_type_id / target_bundle keys. Loading that as an entity
   * via ContentLanguageSettings::loadByEntityTypeBundle() constructs a NEW
   * (unsaved, default) entity in that situation instead of the malformed
   * saved one — so this test also asserts the raw active-config-storage
   * record directly has target_entity_type_id/target_bundle set, which is
   * the only way to actually observe the Layer-3 defect (a defect
   * `loadByEntityTypeBundle()` alone masks by design — see its docblock:
   * "Otherwise, returns default values").
   */
  public function testStep640ContentLanguageSettingsAreWellFormedPerBundle(): void {
    $this->runStep640();

    $active_storage = \Drupal::service('config.storage');
    foreach (static::NODE_BUNDLES as $bundle) {
      $config_name = "language.content_settings.node.$bundle";
      $this->assertTrue(
        $active_storage->exists($config_name),
        "$config_name exists in active config storage after step_640."
      );
      $raw = $active_storage->read($config_name);
      $this->assertSame('node', $raw['target_entity_type_id'] ?? NULL, "$config_name has target_entity_type_id = 'node' (not a bare config write missing this key).");
      $this->assertSame($bundle, $raw['target_bundle'] ?? NULL, "$config_name has target_bundle = '$bundle'.");

      $settings = ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle);
      $this->assertSame('node', $settings->getTargetEntityTypeId());
      $this->assertSame($bundle, $settings->getTargetBundle());
      $this->assertTrue(
        (bool) $settings->getThirdPartySetting('content_translation', 'enabled'),
        "Content translation is enabled for bundle '$bundle'."
      );
    }
  }

  /**
   * Running step_640 a second time is a no-op: no fatals, no duplicate languages.
   */
  public function testStep640IsIdempotent(): void {
    $this->runStep640();
    $this->runStep640();

    $storage = \Drupal::entityTypeManager()->getStorage('configurable_language');
    $all = $storage->loadMultiple();
    $expected_custom = ['de', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt-br', 'ru', 'tr', 'uk', 'zh-hans', 'ar'];
    foreach ($expected_custom as $langcode) {
      $matches = array_filter(array_keys($all), fn ($id) => $id === $langcode);
      $this->assertCount(1, $matches, "Language '$langcode' exists exactly once after running step_640 twice (no duplicates).");
    }

    // Re-verify content language settings are still well-formed (not
    // clobbered/duplicated) after the second run.
    foreach (static::NODE_BUNDLES as $bundle) {
      $settings = ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle);
      $this->assertTrue((bool) $settings->getThirdPartySetting('content_translation', 'enabled'), "Bundle '$bundle' content translation still enabled after second run.");
    }
  }

}
