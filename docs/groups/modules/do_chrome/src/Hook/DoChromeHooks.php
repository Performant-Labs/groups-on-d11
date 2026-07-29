<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\do_chrome\HelpText;

/**
 * Hook implementations for do_chrome.
 *
 * FOUNDATION (CH-F1, #79) for the epic #78 tooltip surfaces. Provides:
 *  - `page_attachments`: attaches the locally-bundled tooltip library
 *    (do_chrome/tooltips) so every B-story surface can render a
 *    `data-do-tooltip` trigger without re-attaching the library.
 *  - one trivial demonstration attachment that proves the asset loads.
 *
 * EXTENSION POINT for #88-#92: add ONE new #[Hook] method per surface (e.g. a
 * `form_alter` that decorates a visibility option, a `preprocess_*` that adds a
 * trigger to a template variable). Each new method:
 *   1. reads its copy from \Drupal\do_chrome\HelpText::get('<surface.id>'),
 *   2. renders a trigger element carrying
 *      #attributes['data-do-tooltip'] => <that copy>.
 * The library is already attached globally here, so surfaces do not each need
 * to attach it. Methods are self-contained, so the five B-stories are
 * parallel-safe (no shared method to edit).
 */
class DoChromeHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Attaches the locally-bundled tooltip library on every page.
   *
   * This is the single attach point epic #78 depends on. Attaching in
   * `page_attachments` (rather than per-form) keeps each future surface to a
   * single self-contained hook method — it only has to emit the
   * `data-do-tooltip` attribute; the library is already present.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'do_chrome/tooltips';

    // --- Trivial demonstration attachment (CH-F1 proof the asset loads) -----
    // Exposes the foundation copy to JS so a quick manual check can confirm
    // tippy.js initialized from the local bundle. B-stories render real
    // `data-do-tooltip` triggers in markup instead of relying on this.
    $attachments['#attached']['drupalSettings']['doChrome']['demo'] =
      HelpText::get('demo.foundation');
  }

  /**
   * Declares mobile-style navigation up front, killing a ~200ms nav flicker.
   *
   * Olivero's `nav-resize.js` watches the primary nav with a ResizeObserver
   * and force-switches to the mobile (hamburger) navigation whenever the
   * desktop nav wraps to a second line, by adding `is-always-mobile-nav` to
   * `<body>`. On this site that check ALWAYS ends up true at desktop widths:
   * the header carries site branding plus a secondary community menu and an
   * account menu, leaving the primary-nav <ul> narrower than its own items
   * need, so it wraps and Olivero collapses it.
   *
   * The problem is purely one of TIMING, not of final appearance: that
   * observer cannot run until the footer-aggregated JS has loaded, so the
   * desktop nav paints, sits visible for ~200ms, and then vanishes — the
   * user-reported "menu flickers on refresh". Measured at 1440px/1280px/
   * 1200px: visible from ~54-101ms, collapsed by ~256-317ms.
   *
   * Adding the class server-side means the mobile nav is what CSS renders
   * from the very first paint. The settled appearance is byte-identical to
   * what the JS produced anyway — this only removes the visible intermediate
   * state. Olivero's own JS is unaffected and idempotent here: its
   * `transitionToDesktopNavigation()` re-adds the class after its own wrap
   * re-check, so behaviour on resize is unchanged.
   *
   * If a future story slims the header enough for the desktop nav to
   * genuinely fit on one line, delete this hook — the flicker it works
   * around only exists while the nav is guaranteed to collapse.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $variables['attributes']['class'][] = 'is-always-mobile-nav';
  }

  // ---------------------------------------------------------------------------
  // B-story tooltip surfaces (#88-#92) are added below, one #[Hook] method each.
  // Template:
  //
  //   #[Hook('form_alter')]
  //   public function visibilityHelp(array &$form, FormStateInterface $fs, string $form_id): void {
  //     if ($form_id !== 'group_community_group_add_form') { return; }
  //     $form['field_visibility']['#attributes']['data-do-tooltip'] =
  //       HelpText::get('visibility.public');
  //   }
  // ---------------------------------------------------------------------------

}
