<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Url;
use Drupal\do_chrome\HelpText;

/**
 * Hook implementations for do_showcase (SC-F1, #119).
 *
 * Ships the site-wide "POC demo" ribbon via `hook_page_top` — the same
 * "single global attach point" shape as `DoChromeHooks::pageAttachments()`
 * (site-wide chrome injected once, not re-attached per page). `page_top` is
 * used rather than `page_attachments` because the ribbon is VISIBLE markup
 * (a real `<button>` + `<a>`), not just a library/settings attachment.
 *
 * The ribbon shows identically for anonymous and authenticated users (no
 * session-dependent branching) and does not reshuffle nav DOM — it is a
 * fixed-position element rendered before the page region, never inserted
 * into the nav block itself (keeps `tests/e2e/nav.spec.ts` green).
 *
 * Dismissal is CLIENT-SIDE (cookie/localStorage, `do_showcase/ribbon`
 * library) — Brief-gate B-2: a server session write would bust the
 * anonymous page cache. The ribbon markup always renders server-side; the
 * ribbon JS hides it on load if the client-side dismiss flag is set, and
 * removes it on a real button click.
 */
class DoShowcaseHooks {

  /**
   * Registers the variant-switcher Twig template.
   *
   * @see \Drupal\do_showcase\VariantSwitcher::build()
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return [
      'do_showcase_variant_switcher' => [
        'variables' => [
          'instance_id' => '',
          'wrapper_attributes' => [],
          'options' => [],
          'tooltip' => '',
          'tooltip_attributes' => [],
        ],
        'template' => 'do-showcase-variant-switcher',
      ],
    ];
  }

  /**
   * Injects the site-wide POC demo ribbon.
   *
   * Identical copy/markup for anonymous and authenticated visitors. Links to
   * `/showcase`; the dismiss control is a real
   * `<button aria-label="Dismiss demo banner">`, keyboard-operable,
   * independently reachable from the `/showcase` link.
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    $page_top['do_showcase_ribbon'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'do-showcase-ribbon',
        'class' => ['do-showcase-ribbon'],
        'data-do-showcase-ribbon' => 'true',
      ],
      '#attached' => [
        'library' => [
          'do_chrome/tooltips',
          'do_showcase/ribbon',
        ],
        'drupalSettings' => [
          'doShowcase' => [
            'ribbonTooltip' => HelpText::get('showcase.ribbon'),
          ],
        ],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => (string) t('This is a proof-of-concept demo.'),
      ],
      'link' => [
        '#type' => 'link',
        '#title' => t('See what it compares →'),
        '#url' => Url::fromRoute('do_showcase.showcase'),
      ],
      'dismiss' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '✕',
        '#attributes' => [
          'type' => 'button',
          'aria-label' => (string) t('Dismiss demo banner'),
          'class' => ['do-showcase-ribbon-dismiss'],
          'data-do-showcase-dismiss' => 'true',
        ],
      ],
    ];
  }

}
