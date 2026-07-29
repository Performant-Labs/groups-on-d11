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
