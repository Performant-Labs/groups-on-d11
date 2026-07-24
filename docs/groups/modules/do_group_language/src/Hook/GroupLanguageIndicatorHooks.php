<?php

declare(strict_types=1);

namespace Drupal\do_group_language\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Issue #139 (MC-4): the group primary-language indicator.
 *
 * Renders `field_group_language` (existing field, REUSED per survey.md's
 * Reuse map — do NOT create field_group_primary_language) as a small
 * `<span class="do-group-language" lang="{code}" dir="{direction}">` on a
 * group's Full canonical view, using core's own
 * {@see \Drupal\Core\Language\LanguageInterface::getDirection()} rather
 * than a hardcoded `dir="rtl"` — the same rule
 * {@see \Drupal\do_group_language\Plugin\LanguageNegotiation\LanguageNegotiationGroup}
 * already follows for the und/zxx/empty sentinels.
 *
 * Placement mirrors do_chrome's PermissionMatrixPanel precedent (same
 * `entity_view` hook, same GroupInterface + `full`-view-mode-only guard,
 * same `#[Hook]`-attribute / zero-services.yml-registration style) — this
 * is the current project convention for entity-view injections, adopted
 * here in place of the brief's literal "hook_entity_view() in a procedural
 * .module file" instruction (see handoff-F.md "Deviations").
 *
 * `all_groups`'s directory listing is NOT reached by this hook: that view
 * uses `row: type: fields` (Views field rendering, not entity teasers), so
 * a teaser-mode branch of this hook could never fire there — per A's
 * round-1 BLOCK finding, the directory instead gets `field_group_language`
 * as a Views field (see views.view.all_groups.yml). This hook only ever
 * targets view_mode `full`.
 *
 * The directory CARD's actual rendering (Amendment v4, Bug #2) is a custom
 * row template that never loops over the raw Views `fields` array — see
 * `groups_chrome_preprocess_views_view_fields__all_groups()` in
 * `web/themes/custom/groups_chrome/groups_chrome.theme`. That preprocess
 * function calls {@see self::resolveDisplayLanguage()} below — the SAME
 * resolve-and-suppress method this hook calls — so the entity-view
 * indicator and the directory-card badge are provably two renderings of
 * one decision, not two independent (and possibly drifting) copies of the
 * four suppression branches.
 *
 * @see \Drupal\do_group_language\Plugin\LanguageNegotiation\LanguageNegotiationGroup
 */
class GroupLanguageIndicatorHooks {

  /**
   * Sentinel langcode values that never render an indicator.
   *
   * Mirrors the exact sentinel set LanguageNegotiationGroup::getLangcode()
   * already treats as "no language configured".
   */
  private const NO_LANGUAGE_SENTINELS = ['und', 'zxx', ''];

  /**
   * Resolves the group's primary language, or NULL to suppress it.
   *
   * This is the SINGLE point of decision for "does this group have a
   * primary language worth displaying, and if so, what is it" — both
   * {@see self::entityView()} (the group Full-page indicator) and
   * `groups_chrome_preprocess_views_view_fields__all_groups()` (the
   * `/all-groups` directory-card badge, Amendment v4 Bug #2) call this one
   * method rather than each re-implementing the four suppression branches,
   * so the two renderings can never drift out of sync with each other.
   *
   * Returns NULL when:
   * - the entity has no `field_group_language` field, or the field is
   *   empty (zero field-item-list values);
   * - the langcode is one of the `und` / `zxx` / empty-string sentinels;
   * - `\Drupal::languageManager()->getLanguage($langcode)` returns NULL
   *   (a bogus/uninstalled langcode) — the null-language guard A's
   *   advisory #4 required;
   * - the resolved langcode equals the site's CURRENT DEFAULT language.
   *
   * On that last point: a `type: language` field cannot actually be left
   * "unset" once a group is saved through the normal entity API.
   * {@see \Drupal\Core\Field\Plugin\Field\FieldType\LanguageItem::applyDefaultValue()}
   * runs unconditionally on every `Group::create()` call that does not
   * explicitly pass `field_group_language` (verified against
   * `ContentEntityStorageBase::initFieldValues()`, which calls
   * `$entity->get($name)->applyDefaultValue()` for every field absent from
   * the passed `$values`) and back-fills the site's default language
   * (`en` in this project's `system.site.yml`) — there is no reachable
   * "truly empty" state for this field short of a raw DB write bypassing
   * the entity API. Since every pre-existing seeded group (DrupalCon
   * Portland, Core Committers, etc. — created in step_730, before
   * step_760 sets any explicit language) never explicitly sets this
   * field, an indicator with no default-language suppression would put an
   * "English" pill on every English-default group on the site — a UX
   * regression this story never intended (the whole point of the
   * indicator is flagging NON-default-language groups; see
   * survey.md/brief.md's framing of the field as "the group's PRIMARY
   * language" set only for the fr/de/ar internationalized groups).
   * Suppressing when the resolved language matches the site default is
   * the correct behavior for both the "never explicitly set" case and a
   * (rare, indistinguishable at the field-value level) group explicitly
   * set to the site's own default language — showing the default
   * language back to users already viewing the site in that language
   * carries no information either way.
   */
  public static function resolveDisplayLanguage(GroupInterface $group): ?LanguageInterface {
    if (!$group->hasField('field_group_language') || $group->get('field_group_language')->isEmpty()) {
      return NULL;
    }

    $langcode = $group->get('field_group_language')->value;
    if (in_array($langcode, self::NO_LANGUAGE_SENTINELS, TRUE)) {
      return NULL;
    }

    $language_manager = \Drupal::languageManager();

    $language = $language_manager->getLanguage($langcode);
    if ($language === NULL) {
      // Bogus/uninstalled langcode — suppress rather than emit a broken
      // lang="" / dir="" attribute or fatal.
      return NULL;
    }

    if ($langcode === $language_manager->getDefaultLanguage()->getId()) {
      // See the method-level doc comment: the site default is the
      // unavoidable auto-populated value for a never-explicitly-set
      // language field, so it is never worth flagging as a "primary
      // language" on its own.
      return NULL;
    }

    return $language;
  }

  /**
   * Injects the group-language indicator into the group's full view.
   *
   * All suppression logic lives in {@see self::resolveDisplayLanguage()};
   * this method only turns a resolved language into the render array.
   */
  #[Hook('entity_view')]
  public function entityView(
    array &$build,
    EntityInterface $entity,
    EntityViewDisplayInterface $display,
    string $view_mode,
  ): void {
    if (!$entity instanceof GroupInterface || $view_mode !== 'full') {
      return;
    }

    $language = self::resolveDisplayLanguage($entity);
    if ($language === NULL) {
      return;
    }

    $build['language_indicator'] = [
      '#type' => 'inline_template',
      '#template' => '<span class="do-group-language" lang="{{ code }}" dir="{{ direction }}">{{ native_name }}</span>',
      '#context' => [
        'code' => $language->getId(),
        'direction' => $language->getDirection(),
        'native_name' => $language->getName(),
      ],
      '#attached' => [
        'library' => ['do_group_language/indicator'],
      ],
      '#cache' => [
        'tags' => $entity->getCacheTags(),
        'contexts' => [
          'languages:language_interface',
          'languages:language_content',
        ],
      ],
      '#weight' => -10,
    ];
  }

}
