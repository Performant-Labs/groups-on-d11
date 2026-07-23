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
      // #88 (CH-B1) / #121 (SC-2): per-option help on the
      // `field_group_visibility` radios (options_buttons: Open / Moderated /
      // Invite Only) on the group add/edit form. Copy is the #81 deck
      // (section A), corrected by #121 SC-2 once join-policy enforcement went
      // live (request-to-join for Moderated; the create-access gate for
      // Invite Only):
      //  - Open is ENFORCED (#95) — a logged-in non-member holds `join group`
      //    (community_group-outsider_view), so they can join an Open group
      //    instantly. Present as live.
      //  - Moderated is NOW ENFORCED (#121) — a non-member sees "Request to
      //    join"; submitting creates a pending `group_membership` relationship
      //    that an organizer approves (-> active) or denies (-> deleted) from
      //    the existing Manage-members page. The copy says so plainly.
      //  - Invite Only is NOW ENFORCED (#121) — the group stays publicly
      //    VIEWABLE (readable), but direct joining is closed to everyone
      //    except an organizer adding someone via Add member. "Visible but
      //    closed to joining", NOT hidden — hidden/unlisted is Private
      //    (#134), a distinct, not-yet-built value.
      // The field-level intro stays honest about the view/join distinction:
      // every group is still readable regardless of this value; only
      // *joining* is what this value controls.
      'visibility.field' => 'Sets who can join this group and how. Every group stays readable (viewable) to anyone; this value controls whether joining is instant, request-based, or invite-only.',
      'visibility.open' => 'Open: anyone signed in can join instantly, no approval needed. This is live on the demo — logged-in visitors can join Open groups now.',
      'visibility.moderated' => 'Moderated: people request to join, and an organizer approves or denies each request. This is live on the demo — a request creates a pending membership until an organizer approves it.',
      'visibility.invite_only' => 'Invite Only: the group stays visible to everyone, but only an organizer can add members — there is no direct join or request path. This is live on the demo.',

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
      //
      // #121 SC-2 correction: the footnote previously read "...are planned but
      // not yet enabled on the demo," which became stale the moment #121
      // shipped request-to-join (Moderated) + the invite-only create-access
      // gate — both are now live and enforced, exactly like every other claim
      // in this file. Updated to name the mechanism (organizer approval /
      // denial from the existing Manage-members page) instead of describing
      // it as a future promise.
      'permissions.panel.intro' => 'What each kind of person can do in this group, based on the roles actually enforced on this demo.',
      'permissions.panel.footnote' => 'A group admin holds every management capability. Members can read, join, post, and remove their own posts; managing members stays admin-only. Moderated groups add a request-to-join step, reviewed (approved or denied) by an organizer from the Manage-members page — this is live on the demo, not a future plan.',

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

      // Note: the site-wide POC ribbon (Surface 3) does NOT carry a ⓘ
      // tooltip trigger — wireframe.md depicts only the POC text + link +
      // dismiss ✕, so no 'showcase.ribbon' key is appended here (diff-gate
      // #119 B-1: a prior revision added this key with no consuming markup;
      // removed as dead wiring rather than adding an unplanned ⓘ trigger the
      // approved wireframe doesn't call for).

      // --- #122 (SC-3): group-type-driven homepages --------------------------
      // Appended per the append-only HelpText contract — groups_chrome (a
      // THEME, not a module) reads this key via
      // groups_chrome_preprocess_group() and renders it on the new
      // `.gc-group-lead` section's ⓘ trigger, reusing
      // the exact GroupTypeContentHelp::infoTrigger() markup pattern verbatim
      // (span + do-chrome-info class + tabindex="0" + role="note" + aria-label
      // + data-do-tooltip).
      //
      // Copy names the three concrete lead-section variants (events /
      // discussion / documentation) so a first-time reader immediately
      // understands what "adapts" means, per the wireframe's own improvement
      // over the brief's vaguer placeholder wording (wireframe.md §3).
      'group_type.homepage_adapts' => 'This page adapts to the group\'s type — it leads with events, discussion, or documentation depending on how the group is categorised.',

      // --- #120 (SC-1): persona switcher — persona.* keys. -----------------
      // Appended per the append-only HelpText contract. This is the single
      // copy source for BOTH the header dropdown's per-option native
      // `title=` attributes AND the widget's one wrapper-level combined ⓘ
      // tooltip (wireframe.md §1/§4) — `PersonaSwitcher::build()` reads
      // these same 4 keys for both surfaces so they never drift apart.
      // Trimmed to <= 140 chars each (brief-amendments.md Amendment 7) so
      // every value fits cleanly inside a native <option title="..."> hover
      // attribute. Each is honest about POC scope boundaries (Maria's is
      // scoped to "a seeded group"; Moderator's explicitly states the limit
      // of its scope — no user administration, no site configuration).
      'persona.anonymous' => 'The logged-out visitor view (default). No session, no persona.',
      'persona.elena' => 'Elena Garcia is an active Member across several groups. Plain-member view: can post and join, cannot manage members.',
      'persona.maria' => 'Maria Chen holds the Organizer role on a seeded group. Can edit the group and manage its members.',
      'persona.moderator' => 'Groups-Moderate is site moderation. Reviews the pending-join queue and approves, archives, or restores any group. Nothing else.',
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
