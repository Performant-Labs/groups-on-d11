<?php
/**
 * Step 640: Multilingual infrastructure (languages + negotiation + content translation).
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_640.php
 */

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;

// Ensure the language module is enabled before creating ConfigurableLanguage
// entities. Without it, ConfigurableLanguage::save() invokes
// ConfigurableLanguageManager::updateLockedLanguageWeights() and fatals.
// Idempotent: no-op when language is already enabled.
$module_installer = \Drupal::service('module_installer');
if (!\Drupal::moduleHandler()->moduleExists('language')) {
  $module_installer->install(['language']);
}
// content_translation is a dependency of the enable-translation-per-bundle
// block below; install it if not already.
if (!\Drupal::moduleHandler()->moduleExists('content_translation')) {
  $module_installer->install(['content_translation']);
}

// Ensure the locked language entities (und, zxx) exist. In CI the language
// module is already enabled via the assembled core.extension.yml at
// config:import time, but `drush config:import` does NOT run
// installDefaultConfig() for modules already listed in the ACTIVE
// core.extension.yml (established elsewhere in this codebase — see the
// do_activity_feed workaround in .github/workflows/test.yml). So the und/zxx
// locked-language entities that language/config/install/language.entity.*.yml
// ships are never installed, and any subsequent ConfigurableLanguage::save()
// fatals on updateLockedLanguageWeights() calling setWeight() on null.
$storage = \Drupal::entityTypeManager()->getStorage("configurable_language");
foreach (["und" => "Not specified", "zxx" => "Not applicable"] as $lc => $label) {
  if (!$storage->load($lc)) {
    $storage->create([
      "id" => $lc,
      "label" => $label,
      "direction" => "ltr",
      "locked" => TRUE,
      "weight" => ($lc === "und" ? 2 : 3),
    ])->save();
    echo "Created locked language: $lc\n";
  }
}

// Add 14 languages
echo "=== Adding languages ===\n";
$langs = ["de","es","fr","it","ja","ko","nl","pl","pt-br","ru","tr","uk","zh-hans","ar"];
foreach ($langs as $langcode) {
  if (!$storage->load($langcode)) {
    // ConfigurableLanguage::createFromLangcode() (not $storage->create(['id' =>
    // $langcode])) populates direction/name/label from Drupal core's
    // predefined-language table. $storage->create() only sets the ID, leaving
    // direction defaulted to LTR and label empty — which silently makes
    // Arabic (and every other RTL language) resolve to LTR on a fresh seed.
    ConfigurableLanguage::createFromLangcode($langcode)->save();
    echo "Added: $langcode\n";
  } else {
    echo "Exists: $langcode\n";
  }
}
echo "Total languages: " . count($storage->loadMultiple()) . "\n";

// Configure language negotiation
echo "\n=== Configuring language negotiation ===\n";
$config = \Drupal::configFactory()->getEditable("language.types");
$config->set("negotiation.language_interface.enabled", [
  "language-user" => -10,
  "language-group" => -5,
  "language-url" => -4,
  "language-selected" => 0,
]);
$config->set("negotiation.language_interface.method_weights", [
  "language-user" => -10,
  "language-group" => -5,
  "language-url" => -4,
  "language-selected" => 0,
]);
$config->save();
echo "Language negotiation configured\n";

// Enable content translation for group-postable node bundles.
//
// Use the ContentLanguageSettings entity API rather than writing the config
// object directly. Writing bare `third_party_settings.content_translation
// .enabled=TRUE` on `language.content_settings.node.$type` produces a config
// record without `target_entity_type_id` or `target_bundle`, which entity load
// then rejects with:
//   "Attempt to create content language settings without a target_entity_type_id."
// (Drupal\language\Entity\ContentLanguageSettings::__construct, line 108).
// That exception surfaces at the first later save touching a translatable
// bundle — e.g. step_795 creating group_content_message rows.
echo "\n=== Enabling content translation ===\n";
foreach (["forum", "documentation", "event", "post", "page"] as $type) {
  $settings = ContentLanguageSettings::loadByEntityTypeBundle("node", $type);
  $settings->setThirdPartySetting("content_translation", "enabled", TRUE);
  $settings->save();
  echo "Enabled content translation for: $type\n";
}
