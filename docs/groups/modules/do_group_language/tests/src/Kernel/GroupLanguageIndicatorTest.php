<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_language\Kernel;

use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_group_language's language-indicator render hook.
 *
 * Issue #139 (MC-4). Exercises `GroupLanguageIndicatorHooks::entityView()`
 * (a `#[Hook('entity_view')]` implementation) by rendering a real saved
 * `community_group` group through the entity view builder at view mode
 * `full`, then asserting on the raw rendered HTML.
 *
 * Unlike {@see GroupLanguageNegotiationTest}, which declares
 * `field_group_language` as a plain `string` field (the negotiation plugin
 * only reads ->value), this test declares the field as `type: language` —
 * the production shape — because the render pipeline for a `language`-typed
 * field (langcode resolution, direction lookup) behaves differently than a
 * bare string.
 *
 * Suppression-branch coverage map (see GroupLanguageIndicatorHooks::entityView()
 * doc comment for the authoritative list of branches):
 * (a) empty/`und`/`zxx` sentinel value -> testNoIndicatorForUndefinedLangcode()
 * (b) uninstalled/bogus langcode -> testNoIndicatorForUninstalledLangcode()
 * (c) resolved langcode equals the site's current default language ->
 *     testNoIndicatorWhenLangcodeIsSiteDefault() (this is what
 *     `createGroup()` with no explicit `field_group_language` key actually
 *     exercises — see that test's doc comment)
 * (d) a genuinely empty field (zero field-item-list values, no default-value
 *     back-fill) -> testNoIndicatorWhenFieldIsTrulyUnset()
 * (e) real, non-default, installed langcode -> emits the indicator ->
 *     testRendersRtlIndicatorForArPrimaryGroup(),
 *     testRendersLtrIndicatorForFrPrimaryGroup()
 *
 * @group do_group_language
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupLanguageIndicatorTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'language',
    'do_group_language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Attach field_group_language as a `language`-typed field — the
    // production shape (field.storage.group.field_group_language.yml),
    // NOT the plain `string` field GroupLanguageNegotiationTest declares
    // for its narrower purpose.
    FieldStorageConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'type' => 'language',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Group language',
    ])->save();

    // Install ar (RTL) and fr (LTR) as configurable languages so
    // \Drupal::languageManager()->getLanguage() can resolve their direction.
    ConfigurableLanguage::createFromLangcode('ar')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Renders a group at the given view mode and returns the raw HTML.
   */
  private function renderGroup(GroupInterface $group, string $view_mode = 'full'): string {
    $view_builder = $this->container->get('entity_type.manager')->getViewBuilder('group');
    $build = $view_builder->view($group, $view_mode);
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Core-default sanity check: ar resolves to RTL, fr to LTR.
   *
   * Not a behavioral test of the module under test — a guard against a
   * misconfigured fixture masking a real failure below.
   */
  public function testLanguageDirectionFixtureSanity(): void {
    $language_manager = $this->container->get('language_manager');
    $this->assertSame(
      LanguageInterface::DIRECTION_RTL,
      $language_manager->getLanguage('ar')->getDirection(),
    );
    $this->assertSame(
      LanguageInterface::DIRECTION_LTR,
      $language_manager->getLanguage('fr')->getDirection(),
    );
  }

  /**
   * An ar-primary group renders the indicator with lang="ar" dir="rtl".
   */
  public function testRendersRtlIndicatorForArPrimaryGroup(): void {
    $group = $this->createGroup(['field_group_language' => 'ar']);

    $html = $this->renderGroup($group);

    $this->assertStringContainsString('class="do-group-language"', $html);
    $this->assertStringContainsString('lang="ar"', $html);
    $this->assertStringContainsString('dir="rtl"', $html);
  }

  /**
   * An fr-primary group renders the indicator with lang="fr" dir="ltr".
   */
  public function testRendersLtrIndicatorForFrPrimaryGroup(): void {
    $group = $this->createGroup(['field_group_language' => 'fr']);

    $html = $this->renderGroup($group);

    $this->assertStringContainsString('class="do-group-language"', $html);
    $this->assertStringContainsString('lang="fr"', $html);
    $this->assertStringContainsString('dir="ltr"', $html);
  }

  /**
   * A group whose langcode resolves to the site default renders no
   * indicator (the site-default-language suppression branch).
   *
   * `createGroup()` with no explicit `field_group_language` key does NOT
   * leave the field empty: core's own
   * {@see \Drupal\Core\Field\Plugin\Field\FieldType\LanguageItem::applyDefaultValue()}
   * runs unconditionally whenever the field is absent from the values passed
   * to `Group::create()`, back-filling the site's default language (`en` in
   * this project). What this test actually pins is
   * `GroupLanguageIndicatorHooks::entityView()`'s explicit suppression when
   * the resolved langcode equals `LanguageManagerInterface::getDefaultLanguage()`
   * — NOT a "field is empty" branch. (Renamed from the T-red-authored
   * `testNoIndicatorWhenFieldEmpty`, whose docblock described a different
   * semantic than what it actually exercised — flagged by F in
   * decisions.md's Phase 6 entry. See testNoIndicatorWhenFieldIsTrulyUnset()
   * below for the branch this test's old name implied.)
   */
  public function testNoIndicatorWhenLangcodeIsSiteDefault(): void {
    $group = $this->createGroup();

    $this->assertSame(
      'en',
      $group->get('field_group_language')->value,
      'Fixture sanity: createGroup() with no explicit language back-fills the site default (en), it does not leave the field empty.',
    );

    $html = $this->renderGroup($group);

    $this->assertStringNotContainsString('do-group-language', $html);
  }

  /**
   * A group with a genuinely empty `field_group_language` (zero field-item
   * values) renders no indicator.
   *
   * This is the "truly unset" branch `testNoIndicatorWhenLangcodeIsSiteDefault()`
   * above cannot reach, because `applyDefaultValue()` only fires when the
   * field key is absent from the values passed to `create()` — once a group
   * is saved, the field already holds a value (the back-filled site
   * default). To reach a genuinely empty field-item list we explicitly
   * `set()` it to an empty array and save; `applyDefaultValue()` is a
   * create()-time-only hook and does not re-fire on an explicit empty
   * `set()` + `save()` of an already-saved entity, so this reliably produces
   * `isEmpty() === TRUE` on the loaded field.
   */
  public function testNoIndicatorWhenFieldIsTrulyUnset(): void {
    $group = $this->createGroup(['field_group_language' => 'fr']);
    $group->set('field_group_language', [])->save();

    $this->assertTrue(
      $group->get('field_group_language')->isEmpty(),
      'Fixture sanity: explicit set([]) + save() produces a truly empty field-item list.',
    );

    $html = $this->renderGroup($group);

    $this->assertStringNotContainsString('do-group-language', $html);
  }

  /**
   * The und / zxx / empty sentinels render no indicator.
   */
  public function testNoIndicatorForUndefinedLangcode(): void {
    foreach (['und', 'zxx', ''] as $sentinel) {
      $group = $this->createGroup(['field_group_language' => $sentinel]);

      $html = $this->renderGroup($group);

      $this->assertStringNotContainsString(
        'do-group-language',
        $html,
        sprintf('Sentinel langcode %s renders no indicator.', var_export($sentinel, TRUE)),
      );
    }
  }

  /**
   * A bogus/uninstalled langcode renders no indicator (null-language guard).
   *
   * LanguageManager()->getLanguage('xx-fake') returns NULL because 'xx-fake'
   * is not installed as a ConfigurableLanguage — the hook must suppress the
   * indicator entirely rather than emit lang="xx-fake" dir="" or fatal.
   */
  public function testNoIndicatorForUninstalledLangcode(): void {
    $group = $this->createGroup(['field_group_language' => 'xx-fake']);

    $html = $this->renderGroup($group);

    $this->assertStringNotContainsString('do-group-language', $html);
  }

}
