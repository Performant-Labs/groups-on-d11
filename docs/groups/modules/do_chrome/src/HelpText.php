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
      // #88 (CH-B1): per-option help on the `field_group_visibility` radios
      // (options_buttons: Open / Moderated / Invite Only) on the group
      // add/edit form. Copy is the #81 deck (section A), reconciled with the
      // CH-F4 (#95) update comment on #81:
      //  - Open is now ENFORCED — a logged-in non-member holds `join group`
      //    (community_group-outsider_view), so they can join an Open group
      //    instantly. Present as live.
      //  - Moderated / Invite Only remain UNENFORCED labels — no request/
      //    approval flow, and Invite Only groups are still publicly viewable.
      //    The copy says so plainly so the demo never over-claims.
      // The field-level intro also stays honest: only *joining* (not *viewing*)
      // is gated today; every group is still readable regardless of this value.
      'visibility.field' => 'Sets who can find and join this group. On this demo, joining is what visibility controls; every group stays readable to anyone.',
      'visibility.open' => 'Open: anyone signed in can join instantly, no approval needed. This is live on the demo — logged-in visitors can join Open groups now.',
      'visibility.moderated' => 'Moderated: the intent is that people request to join and an admin approves each request. Not yet enforced on this demo — shown to illustrate the model.',
      'visibility.invite_only' => 'Invite Only: the intent is a hidden group people join only when an admin invites them. Not yet enforced on this demo — the group is still publicly viewable.',

      'archive.badge' => 'This group is archived: read-only. Everything stays visible for reference, but no new content can be posted here.',
      'pin.badge' => 'Pinned: this post is kept at the top of the group stream so newcomers see it first, regardless of date.',
      'promote.control' => 'Promote surfaces this post beyond its group, onto the site-wide Promoted Content listing.',
      'follow.control' => 'Follow this post to get notified when it is updated or gets new replies.',

      // #89 (CH-B2): Group Type + content-type field help.
      //
      // A <select> can't carry a per-<option> tooltip, so each surface ships
      // ONE field-level ⓘ that names every choice and what it means. Copy is
      // authored to match ENFORCED / SEEDED reality (CH-F4 / #95 seeded the 5
      // group_type terms and tagged all demo groups), so nothing here is
      // aspirational:
      //  - group_type.field : the 5 seeded group_type terms, using their
      //    seeded term descriptions (Geographical / Working group /
      //    Distribution / Event planning / Archive — see step_200.php). Archive
      //    is enforced read-only by do_group_extras.
      //  - content_type.field : the 5 group_node content types wired on the demo
      //    (Forum / Documentation / Event / Post / Page), per the #81 copy deck
      //    section C. Every type is a real group_node relationship, so a member
      //    can create each one.
      'group_type.field' => 'Categorises the group so members know what to expect. '
        . 'Geographical: local user groups by city or region. '
        . 'Working group: module, feature, or initiative coordination. '
        . 'Distribution: Drupal distribution projects. '
        . 'Event planning: DrupalCon and camp organising. '
        . 'Archive: inactive groups, read-only — existing content stays visible but no new posts can be added.',
      'content_type.field' => 'Pick the kind of post — each type is shaped for a different job. '
        . 'Forum: threaded discussion; start a conversation and let members reply. '
        . 'Documentation: durable reference material — guides, how-tos, and specs that stay useful over time. '
        . 'Event: something happening at a set time; add the date so members can plan around it. '
        . 'Post: a quick update, announcement, or link to share with the group. '
        . 'Page: a standalone page for lasting information, like an about or guidelines page.',

      // #91 (CH-B4): "Who can do what" permission-matrix panel.
      //
      // Copy is authored in the #81 copy deck (section E) and RE-DERIVED from
      // the ENFORCED roles after CH-F4 (#95) + #100 landed, verified against the
      // deploy-time role config (docs/groups/config/group.role.community_group-
      // {anon,outsider,insider}_view.yml + .community_group-admin.yml):
      //  - Anonymous  (anon_view / scope outsider, global anonymous):
      //      view group + view all group content. No join, no post.
      //  - Outsider   (outsider_view / scope outsider, global authenticated):
      //      view group + view content + JOIN group. No post (create is an
      //      insider grant, verified FALSE for outsiders in #95).
      //  - Member     (insider_view / scope insider, global authenticated):
      //      view + create / update-own / delete-own for all 5 group_node types
      //      + LEAVE group. No member management (no `administer members`).
      //  - Admin      (community_group-admin, admin: true): implicit ALL group
      //      permissions (bypass) — the only actor that manages members.
      //
      // The panel intro + footnote are served from here; per-cell labels live in
      // the render hook / template (they are structural, not prose copy).
      'permissions.panel.intro' => 'What each kind of person can do in this group, based on the roles actually enforced on this demo.',
      'permissions.panel.footnote' => 'A group admin holds every management capability. Members can read, join, post, and remove their own posts; managing members stays admin-only. Finer-grained roles (moderation, request-to-join) are planned but not yet enabled on the demo.',

      // --- do_showcase (SC-F1, #119): variant-switcher framework, the ------
      // /showcase tour page, and the site-wide POC ribbon. Appended here per
      // the append-only HelpText contract — do_showcase does NOT create a
      // parallel copy store.
      //
      // 'showcase.switcher.<instance_id>' is the ⓘ tooltip copy for a
      // switcher instance (one entry per wired instance; this story ships
      // the one stub instance, 'directory.layout'). Copy describes what
      // differs between the variants (the issue's own phrasing), matching
      // the wireframe's own example options (Compact list / Cards / Map).
      'showcase.switcher.directory.layout' => 'Compact list favors scanning many groups fast; Cards shows more per-group detail; Map plots groups geographically.',

      // 'showcase.ribbon' is the ⓘ tooltip for the site-wide POC demo
      // ribbon, explaining what the ribbon is for.
      'showcase.ribbon' => 'This ribbon marks the site as a proof-of-concept demo. Dismissing it is remembered on this device only; it does not affect other visitors.',
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
