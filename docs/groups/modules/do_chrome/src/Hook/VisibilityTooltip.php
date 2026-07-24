<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\do_chrome\HelpText;

/**
 * #88 (CH-B1): per-option help on the `field_group_visibility` radios.
 *
 * The community_group add/edit form renders `field_group_visibility` with the
 * `options_buttons` widget — a `#type => radios` element with three options:
 * Open / Moderated / Invite Only (see
 * docs/groups/config/core.entity_form_display.group.community_group.default.yml).
 * This hook decorates each of the three radios with a hover tooltip AND an
 * inline `#description`, so a group creator sees what each option means at the
 * point of decision.
 *
 * Honesty (#133 SD-6 sweep — all three visibility values are enforced live):
 *  - Open is ENFORCED (#95) — grants `join group` to the authenticated
 *    `community_group-outsider_view` role, so a logged-in non-member can join
 *    an Open group instantly.
 *  - Moderated is ENFORCED (#121) — a non-member sees "Request to join";
 *    submitting creates a pending `group_membership` relationship that an
 *    Organizer approves or denies from the Manage-members page.
 *  - Invite Only is ENFORCED (#121) — the group stays fully visible, but no
 *    direct join or request path exists; only an Organizer can add members.
 *  - The separate Privacy field (#134, `field_group_privacy`) controls who
 *    can VIEW the group at all — a distinct axis from the join-policy values
 *    above. The field-level intro names both axes explicitly.
 *
 * Kept as its own small #[Hook] class (rather than editing the shared
 * DoChromeHooks) so the #78 B-story surfaces stay parallel-safe — no two
 * stories edit the same method or file. The tooltip library is already
 * attached globally by DoChromeHooks::pageAttachments(), so this surface only
 * emits `data-do-tooltip` attributes.
 *
 * Per-radio children are expanded by \Drupal\Core\Render\Element\Radios during
 * the element `#process` phase, which runs AFTER hook_form_alter. So this hook
 * registers an `#after_build` callback on the widget element and decorates the
 * expanded radio children there, when they actually exist.
 *
 * @see \Drupal\do_chrome\Hook\DoChromeHooks::pageAttachments()
 */
class VisibilityTooltip {

  /**
   * Maps each visibility option value to its HelpText surface id.
   *
   * Keyed by the stored `field_group_visibility` allowed value.
   */
  private const OPTION_COPY_KEYS = [
    'open' => 'visibility.open',
    'moderated' => 'visibility.moderated',
    'invite_only' => 'visibility.invite_only',
  ];

  /**
   * Registers the visibility decorator on the group add/edit forms.
   *
   * Only the community_group add and edit forms carry the visibility widget;
   * this returns early on any other form. It never re-implements the widget —
   * it decorates the element the options_buttons widget already built.
   */
  #[Hook('form_alter')]
  public function formAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    if (!in_array($form_id, [
      'group_community_group_add_form',
      'group_community_group_edit_form',
    ], TRUE)) {
      return;
    }

    if (!isset($form['field_group_visibility']['widget']) || !is_array($form['field_group_visibility']['widget'])) {
      return;
    }

    // Field-level help on the widget wrapper (the "Visibility" label row is the
    // natural hover target). options_buttons builds a `radios` element under
    // ['widget']; attach the intro there.
    $intro = HelpText::get('visibility.field');
    if ($intro !== '') {
      $form['field_group_visibility']['widget']['#attributes']['data-do-tooltip'] = $intro;
    }

    // Defer per-radio decoration to #after_build: the individual radio children
    // (['open'], ['moderated'], ['invite_only']) are created by Radios' own
    // #process, which has not run yet at form_alter time.
    $form['field_group_visibility']['widget']['#after_build'][] = [static::class, 'decorateOptions'];
  }

  /**
   * Decorates each expanded radio child with its per-option help.
   *
   * Runs after Radios::processRadios() has created a child element per allowed
   * value, so `$element['open']`, `$element['moderated']`,
   * `$element['invite_only']` exist here. For each present child it sets both a
   * `data-do-tooltip` attribute (hover tooltip) and a plain `#description`
   * (visible without JS and a stable, assertable DOM text node). Unknown or
   * absent options are skipped — it never invents a radio the widget did not
   * build.
   *
   * @param array $element
   *   The expanded radios element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The decorated element.
   */
  public static function decorateOptions(array $element, FormStateInterface $form_state): array {
    foreach (self::OPTION_COPY_KEYS as $option => $copyKey) {
      if (!isset($element[$option]) || !is_array($element[$option])) {
        continue;
      }
      $copy = HelpText::get($copyKey);
      if ($copy === '') {
        continue;
      }
      $element[$option]['#attributes']['data-do-tooltip'] = $copy;
      $element[$option]['#description'] = $copy;
    }
    return $element;
  }

}
