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
      // #92 archive.* / pin.* / promote.* / follow.*

      // #90 (CH-B3): multi-group "Group Audience" fieldset (do_multigroup
      // cross-posting). Copy is the approved #81 deck, section D. This surface
      // is FULLY BACKED — cross-posting to multiple groups through the node
      // form is wired (do_multigroup; the form-submit path fixed in #68), so
      // the copy is presented as live, not aspirational.
      'audience.fieldset' => 'Post to more than one group at once — the content appears in every group you select and in each group\'s stream. Leave a group unselected to remove it from that group without deleting the content.',

      // #92 archive / pin / promote / follow controls.
      //
      // Copy is authored in the #81 copy deck (section F) and cross-checked
      // against ENFORCED behavior in the deployed modules — only wired controls
      // ship a tooltip here:
      //  - archive.badge : the read-only "Archived" badge. Enforced by
      //    do_group_extras (preprocess_group tags the group `group--archived`;
      //    node_access denies `create` in Archive-typed groups).
      //  - pin.badge     : the "Pinned" badge. Enforced by do_group_pin via the
      //    `pin_in_group` flag (pinned nodes lead group_content_stream).
      //  - promote.control : the `promote_homepage` flag link. Wired — flagged
      //    nodes surface on the "Promoted Content" listing
      //    (views.view.promoted_content, path admin/content/promoted).
      //  - follow.control  : the `follow_content` flag link. Wired — following
      //    a post subscribes you to its notifications (do_notifications).
      //
      // The copy deck's "Flag" (report-to-admins) control is intentionally
      // OMITTED: no report/abuse flag or moderation target exists on the demo
      // (verified — no such flag.flag.* config, no consumer), so shipping that
      // string would describe behavior that is not wired.
      'archive.badge' => 'This group is archived: read-only. Everything stays visible for reference, but no new content can be posted here.',
      'pin.badge' => 'Pinned: this post is kept at the top of the group stream so newcomers see it first, regardless of date.',
      'promote.control' => 'Promote surfaces this post beyond its group, onto the site-wide Promoted Content listing.',
      'follow.control' => 'Follow this post to get notified when it is updated or gets new replies.',
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
