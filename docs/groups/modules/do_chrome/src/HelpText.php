<?php

declare(strict_types=1);

namespace Drupal\do_chrome;

/**
 * Centralized, editable tooltip copy source for the Groups demo.
 *
 * This is the single place community-chrome tooltip text lives. Each tooltip
 * surface (epic #78 / B-stories #88-#92) adds ONE entry here keyed by a surface
 * id, then reads it back via HelpText::get(). No B-story edits another's entry,
 * so the surfaces stay parallel-safe.
 *
 * Copy is authored/approved in #81 (the copy deck). Keep values plain text —
 * do_chrome.tooltips.js renders them with allowHTML disabled.
 *
 * Extension pattern for a new surface (e.g. #88 visibility):
 *   1. Add a 'visibility.public' => '...' entry to the array below.
 *   2. In a new #[Hook] method on DoChromeHooks, render the trigger element
 *      with `#attributes['data-do-tooltip'] => HelpText::get('visibility.public')`
 *      and attach the `do_chrome/tooltips` library.
 */
final class HelpText {

  /**
   * Returns all tooltip copy, keyed by surface id.
   *
   * @return array<string, string>
   *   A map of surface id => plain-text tooltip copy.
   */
  public static function all(): array {
    return [
      // Foundation demo surface (CH-F1, #79). Proves the library loads; the
      // real schema surfaces are added by #88-#92.
      'demo.foundation' => 'do_chrome is active: this tooltip is served by the locally-bundled tippy.js library (no CDN).',

      // --- B-story tooltip copy is appended below (one key per surface). ---
      // #88 visibility.*      (per-option visibility help)
      // #89 group_type.* / content_type.*
      // #90 audience.*        (multi-group audience help)
      // #91 permissions.*     (who-can-do-what matrix)
      // #92 archive.* / pin.* / promote.* / flag.*

      // #90 (CH-B3): multi-group "Group Audience" fieldset (do_multigroup
      // cross-posting). Copy is the approved #81 deck, section D. This surface
      // is FULLY BACKED — cross-posting to multiple groups through the node
      // form is wired (do_multigroup; the form-submit path fixed in #68), so
      // the copy is presented as live, not aspirational.
      'audience.fieldset' => 'Post to more than one group at once — the content appears in every group you select and in each group\'s stream. Leave a group unselected to remove it from that group without deleting the content.',
    ];
  }

  /**
   * Returns the tooltip copy for a single surface id.
   *
   * @param string $key
   *   The surface id (e.g. 'demo.foundation').
   *
   * @return string
   *   The tooltip copy, or an empty string if the key is unknown.
   */
  public static function get(string $key): string {
    return self::all()[$key] ?? '';
  }

}
