<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\do_chrome\HelpText;
use Drupal\group\Entity\GroupInterface;

/**
 * Tooltip surfaces for the archive / pin / promote / follow controls (#92).
 *
 * Owns disjoint surfaces (its own #[Hook] methods + its own copy keys in
 * \Drupal\do_chrome\HelpText), so it is parallel-safe with the other B-stories
 * (#88-#91). It renders `data-do-tooltip` triggers that the shared
 * do_chrome/tooltips behavior (already attached globally by DoChromeHooks)
 * initializes — this class attaches no new library.
 *
 * Every tooltip here matches ENFORCED behavior (verified against the deployed
 * modules in the #81 spike):
 *  - Archive badge — do_group_extras marks Archive-typed groups `group--archived`
 *    and denies node `create`; this class renders the visible "Archived" badge as
 *    a real element carrying the read-only-meaning tooltip.
 *  - Pin badge — do_group_pin renders a "Pinned" badge for `pin_in_group`-flagged
 *    nodes; this class adds the explanatory tooltip to that badge.
 *  - Promote / Follow — the `promote_homepage` and `follow_content` flag links are
 *    wired (Promoted Content listing / notifications). The flag module renders
 *    them with stable CSS classes, so their copy is exposed to JS as a
 *    selector => text map (see self::pageAttachments) and the tooltips behavior
 *    binds it wherever those links appear.
 *
 * The copy deck's "Flag" (report-to-admins) control is deliberately NOT here:
 * no report/abuse flag or moderation target exists on the demo, so there is
 * nothing truthful to describe.
 */
class ArchivePinHooks {

  /**
   * Renders the "Archived" group badge as a real, tooltip-bearing element.
   *
   * do_group_extras' preprocess_group draws an "Archived" chip via the
   * `group--archived` class's CSS ::before. A pseudo-element cannot carry a
   * tooltip, so this method emits a real `<span data-do-tooltip>` badge via the
   * group's title_suffix. The companion do_chrome CSS hides the ::before chip
   * when this real badge is present, so exactly one "Archived" badge shows.
   *
   * Archived is detected the same way do_group_extras enforces it — the group's
   * `field_group_type` term is named "Archive" — rather than by reading
   * `$variables['archived']`, so this hook does not depend on running AFTER
   * do_group_extras' preprocess_group (OOP hook order is not guaranteed).
   */
  #[Hook('preprocess_group')]
  public function preprocessGroup(array &$variables): void {
    if (!$this->isArchived($variables['group'] ?? NULL)) {
      return;
    }
    $variables['attributes']['class'][] = 'group--archived-chrome';
    $variables['title_suffix']['do_chrome_archived_badge'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => t('Archived'),
      '#attributes' => [
        'class' => ['group__archived-badge'],
        'tabindex' => '0',
        'data-do-tooltip' => HelpText::get('archive.badge'),
      ],
      '#attached' => ['library' => ['do_chrome/tooltips']],
      '#weight' => -100,
    ];
  }

  /**
   * Adds the explanatory tooltip to the "Pinned" node badge.
   *
   * do_group_pin's preprocess_node sets `$variables['pinned']` and renders a
   * `<span class="pin-badge">Pinned</span>` in title_suffix. This method attaches
   * `data-do-tooltip` to that existing badge markup (rather than a second badge),
   * keeping the copy in HelpText and one badge per node. It must run AFTER
   * do_group_pin's preprocess_node (which creates the badge), so it is ordered
   * accordingly — OOP hook execution order is otherwise unspecified.
   */
  #[Hook('preprocess_node', order: new OrderAfter(modules: ['do_group_pin']))]
  public function preprocessNode(array &$variables): void {
    if (empty($variables['pinned'])) {
      return;
    }
    $badge = $variables['title_suffix']['pin_badge'] ?? NULL;
    if (is_array($badge) && isset($badge['#markup'])) {
      // do_group_pin renders the badge as a raw #markup span. Re-render it as an
      // html_tag so we can attach data-do-tooltip as a real attribute.
      $variables['title_suffix']['pin_badge'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => t('Pinned'),
        '#attributes' => [
          'class' => ['pin-badge'],
          'tabindex' => '0',
          'data-do-tooltip' => HelpText::get('pin.badge'),
        ],
        '#weight' => $badge['#weight'] ?? -100,
      ];
    }
  }

  /**
   * Exposes the promote / follow flag-link tooltips to the shared behavior.
   *
   * The `promote_homepage` and `follow_content` flag links are rendered by the
   * flag module (not by markup this repo controls), but with stable CSS classes.
   * Rather than guess each link's DOM shape, this passes a selector => copy map
   * to JS; the do_chrome/tooltips behavior binds a tooltip to any matching link
   * wherever the flag module places it. Only WIRED flags are listed.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['drupalSettings']['doChrome']['controlTooltips'] = [
      // Flag module link wrappers carry `flag-<flag-id-with-dashes>`.
      '.flag-promote-homepage a' => HelpText::get('promote.control'),
      '.flag-follow-content a' => HelpText::get('follow.control'),
    ];
  }

  /**
   * Whether a group is archived (its group type term is named "Archive").
   *
   * Mirrors the enforcement check in do_group_extras so the tooltip badge shows
   * exactly when the archive behavior (node-create denial) applies.
   *
   * @param mixed $group
   *   The group entity from the preprocess variables (or NULL).
   *
   * @return bool
   *   TRUE when the group is Archive-typed.
   */
  private function isArchived(mixed $group): bool {
    if (!$group instanceof GroupInterface || !$group->hasField('field_group_type')) {
      return FALSE;
    }
    $term = $group->get('field_group_type')->entity;
    return $term !== NULL && $term->getName() === 'Archive';
  }

}
