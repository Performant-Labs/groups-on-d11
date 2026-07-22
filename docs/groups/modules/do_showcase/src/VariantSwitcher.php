<?php

declare(strict_types=1);

namespace Drupal\do_showcase;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\do_chrome\HelpText;

/**
 * Builds the reusable labeled variant-switcher render array (SC-F1, #119).
 *
 * A plain, no-DI service (do_showcase.variant_switcher) — same shape as
 * `\Drupal\do_chrome\PermissionMatrix` (StringTranslationTrait, pure data/
 * render-array construction, no service dependencies). See
 * docs/handoffs/0119-variant-framework/handoff-A-plan.md finding #2: this is
 * a plain SERVICE, not a Block plugin, because the repo's existing embeddable
 * -render-surface precedent (GroupMissionBlock, ContributionStatsBlock) is
 * context-derived (the group comes from block placement/route context),
 * while the switcher's callers (SC-4/5/6/ST-8) always supply explicit
 * instance_id/options/current parameters and call it inline from a
 * controller/template, not from a placed block region.
 *
 * The rendered markup is a `role="radiogroup"` (roving-tabindex pattern):
 *  - the wrapper carries `role="radiogroup"` + `aria-label` (WCAG 2.2 AA
 *    labeled-control-group requirement),
 *  - each option carries `role="radio"`, `aria-checked`, and (for the
 *    currently-selected option) a leading non-color `●` glyph — selection is
 *    never conveyed by color alone,
 *  - an option flagged `available => FALSE` carries `aria-disabled="true"` +
 *    `tabindex="-1"` (removed from the tab order) and a truthful "(soon)"
 *    suffix on its visible label — never a silent omission or a dead click,
 *  - every option carries a no-JS `?variant=<id>` fallback link so the
 *    control degrades to ordinary navigation without JS,
 *  - the wrapper carries exactly one ⓘ tooltip trigger (do_chrome house
 *    pattern: one per widget wrapper, not one per option), sourced from
 *    `\Drupal\do_chrome\HelpText::get('showcase.switcher.<instance_id>')`.
 *
 * Selection resolution: if $current names an option that does not exist, or
 * one flagged unavailable, the FIRST AVAILABLE option is selected instead —
 * the control never silently renders with nothing selected.
 */
final class VariantSwitcher {

  use StringTranslationTrait;

  /**
   * Builds the switcher render array for one instance.
   *
   * @param string $instance_id
   *   A caller-chosen machine id for this switcher instance (e.g.
   *   'directory.layout'), used to key persistence and the HelpText tooltip
   *   lookup ('showcase.switcher.<instance_id>').
   * @param array<int, array{id: string, label: string, available?: bool}> $options
   *   The ordered option list. Each entry: a machine id, a human label, and
   *   an optional 'available' flag (defaults TRUE).
   * @param string $current
   *   The id of the option that should be selected. Falls back to the first
   *   available option if this id is unknown or unavailable.
   *
   * @return array
   *   A render array: '#type' => 'container', '#attributes' (role/aria-label/
   *   data attributes), '#options' (the resolved per-option data the
   *   switcher template/theme consumes), '#tooltip' (the HelpText-sourced
   *   copy), '#instance_id'.
   */
  public function build(string $instance_id, array $options, string $current): array {
    $normalized = $this->normalizeOptions($options);
    $selected_id = $this->resolveSelection($normalized, $current);

    $items = [];
    foreach ($normalized as $option) {
      $available = $option['available'];
      $is_selected = $option['id'] === $selected_id;

      $label = $option['label'];
      if (!$available) {
        // Truthful copy: append "(soon)" rather than silently hiding the
        // option or rendering a dead click target with no explanation.
        $label = $label . ' (soon)';
      }
      // Non-color selection cue: a leading glyph, aria-hidden (the
      // selection state itself is carried by aria-checked).
      $display_label = $is_selected ? '● ' . $label : $label;

      $items[] = [
        'id' => $option['id'],
        'label' => $display_label,
        'plain_label' => $label,
        'aria_checked' => $is_selected,
        'aria_disabled' => !$available,
        // Roving tabindex (wireframe.md lines 29-31, 271): only the
        // currently-selected AVAILABLE option is in the Tab order; every
        // other option (available or not) is tabindex="-1" and reachable
        // only via Arrow-Left/Right once focus is inside the radiogroup.
        'tabindex' => ($available && $is_selected) ? '0' : '-1',
        'available' => $available,
        'href' => '?variant=' . rawurlencode($option['id']),
      ];
    }

    $tooltip_key = 'showcase.switcher.' . $instance_id;
    $tooltip = HelpText::get($tooltip_key);

    $attributes = [
      'role' => 'radiogroup',
      'aria-label' => (string) $this->t('Viewing'),
      'class' => ['do-showcase-variant-switcher'],
      'data-do-showcase-instance' => $instance_id,
    ];

    return [
      '#theme' => 'do_showcase_variant_switcher',
      '#instance_id' => $instance_id,
      // Kept for the render-array CONTRACT (VariantSwitcherTest pins this
      // shape so SC-4/5/6/ST-8 can inspect it without a full render cycle).
      '#attributes' => $attributes,
      '#wrapper_attributes' => $attributes,
      '#options' => $items,
      '#tooltip' => $tooltip,
      '#tooltip_attributes' => [
        'tabindex' => '0',
        'role' => 'note',
        'aria-label' => $tooltip,
        'data-do-tooltip' => $tooltip,
      ],
      '#attached' => [
        'library' => ['do_showcase/switcher'],
      ],
    ];
  }

  /**
   * Normalizes the caller-supplied option list (fills the 'available' flag).
   *
   * @param array<int, array{id: string, label: string, available?: bool}> $options
   *   The raw caller-supplied options.
   *
   * @return array<int, array{id: string, label: string, available: bool}>
   *   The normalized options, in the same order.
   */
  private function normalizeOptions(array $options): array {
    $out = [];
    foreach ($options as $option) {
      $out[] = [
        'id' => (string) $option['id'],
        'label' => (string) $option['label'],
        'available' => $option['available'] ?? TRUE,
      ];
    }
    return $out;
  }

  /**
   * Resolves which option id should be selected.
   *
   * Falls back to the first available option if $current is unknown or
   * names an unavailable option (wireframe.md: "Selection automatically
   * falls back ... never silently renders nothing selected").
   *
   * @param array<int, array{id: string, label: string, available: bool}> $options
   *   The normalized option list.
   * @param string $current
   *   The caller-requested current selection id.
   *
   * @return string
   *   The id of the option to mark selected.
   */
  private function resolveSelection(array $options, string $current): string {
    foreach ($options as $option) {
      if ($option['id'] === $current && $option['available']) {
        return $current;
      }
    }
    foreach ($options as $option) {
      if ($option['available']) {
        return $option['id'];
      }
    }
    // Defensive: every option unavailable — select the first one anyway so
    // the control never renders with literally nothing selected.
    return $options[0]['id'] ?? $current;
  }

}
