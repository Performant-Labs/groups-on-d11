# Handoff-A: Phase 3 — #122 SC-3 Group-type homepages (up-front plan review)

**Date:** 2026-07-22
**Branch:** 122-grouptype-home
**Brief reviewed:** `docs/planning/handoffs/122-grouptype-home/brief.md`
**Reuse map:** `docs/planning/handoffs/122-grouptype-home/survey.md`
**Wireframe:** `docs/planning/handoffs/122-grouptype-home/wireframe.md`
**Verdict:** PASS

## Summary

Plan is consistent with existing patterns. It extends `groups_chrome_preprocess_group()` (the analogous seam the survey named), reuses the exact `do_chrome` info-trigger pattern verbatim, and mirrors established library-attach-on-render, BEM-scoped-CSS, and token-based color conventions. Both of D's open questions have definitive answers below — the discussion/docs "See all" links can hit the existing exposed bundle filter on `group_nodes` (no new route needed), and the ⓘ contrast on the plain-white lead-section background is AA-compliant with the existing `.do-chrome-info` color as-is. F receives concrete guidance on the node-query mechanism and cache-metadata contract; no plan amendments required.

## Findings

Plan is consistent with existing patterns.

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| — | info | Node-query mechanism | pattern consistency | Wireframe hedges between entityQuery vs. rendered view. Concrete guidance below (§Node-query mechanism). | Follow §Node-query mechanism. |

## Resolutions to D's open questions

### Q1 — "See all" target for discussion/docs

**Definitive answer:** an existing type-filtered path DOES exist. Use it, do NOT fall back to the unfiltered stream.

Evidence: `docs/groups/config/views.view.group_nodes.yml`
- Line 941: page display path `group/%group/nodes`.
- Lines 756–786: the `type` (bundle) filter on `node_field_data.type` is `exposed: true`, `plugin_id: bundle`, `identifier: type`, `operator: in`.

**F instructions:**
- Discussion "See all" URL: `/group/{gid}/nodes?type[]=forum` (or `?type=forum` — the bundle filter uses `operator: in` with identifier `type`; a scalar value is accepted by Views' bundle filter in single-select mode. F should verify the exact query-string form by hitting the exposed form once on a seeded install and matching what Views emits.)
- Docs "See all" URL: `/group/{gid}/nodes?type[]=documentation` (or `?type=documentation`, same caveat).
- Events "See all" URL: `/group/{gid}/events` (unchanged from D's plan — existing per-group events view path).
- Link text: "See all discussions", "See all documentation", "See all events" — no "in the stream" hedge needed since the target IS type-filtered.

If for any reason the query-string form on `group_nodes` misbehaves in practice (e.g. the filter default of empty array excludes everything before the parameter arrives — verify at F time), the acceptable fallback is the unfiltered `/group/{gid}/nodes` with descriptive link text; A does NOT require inventing a new route or view display.

### Q2 — ⓘ trigger AA contrast on `.gc-group-lead` background

**Definitive answer: AA is satisfied with the existing `.do-chrome-info` color. No override needed.**

Evidence:
- `.do-chrome-info` color: `#0067b8` (docs/groups/modules/do_chrome/css/do_chrome.css:74).
- `.gc-group-lead` background: `--gc-color-bg` = `#ffffff` (web/themes/custom/groups_chrome/css/tokens.css:29). D confirmed the lead section sits on the plain page background (wireframe §5, §6), not a subtle-panel background.
- Contrast ratio `#0067b8` on `#ffffff` ≈ 5.36:1. WCAG 2.2 AA requires ≥ 4.5:1 for normal text and ≥ 3:1 for UI components. Both thresholds are exceeded.
- Focus ring: `outline: 2px solid #0067b8; outline-offset: 2px` (do_chrome.css:85–88) — 2px solid outline satisfies 2.4.7 Focus Visible and 2.4.11 Focus Not Obscured on a white background.

F does **not** need to add a color override in `group-type-homepage.css`. The `.gc-group-lead__help` selector D specified for BEM spacing is fine (margin/padding only, no color) — leave `.do-chrome-info`'s color inherited as-is.

## F guidance (constraints/contracts to observe)

### Node-query mechanism — chosen: entityQuery via `group_content` relationships

Use the same mechanism the neighboring `last_activity` block uses (theme.php lines 456–470): iterate `$group->getRelationships()`, filter by `group_node:` plugin prefix, and cap the results. This is the established pattern in this file — a fresh entityQuery on `node` filtered by `gnode` join would be a parallel path.

Concrete shape for F (adapt, don't copy verbatim — F may extract a helper):

```php
$LEAD_ITEM_LIMIT = 3;
$plugin_suffix = match ($leading_section) {
  'events' => 'event',
  'discussion' => 'forum',
  'docs' => 'documentation',
  default => NULL,
};
if ($plugin_suffix === NULL) {
  // fallback — no lead section
}
$nodes = [];
foreach ($group->getRelationships('group_node:' . $plugin_suffix) as $rel) {
  $node = $rel->getEntity();
  if ($node instanceof \Drupal\node\NodeInterface && $node->isPublished()) {
    $nodes[] = $node;
  }
}
// Sort: events → soonest upcoming first (needs field_event_date or created fallback);
//       discussion/docs → newest created first.
```

**Why entityQuery-via-relationships over a rendered View:**
1. `views.view.group_events` exists but rendering it here would double-render (the Events tab already targets it) and require a new custom display with its own cache metadata.
2. The last-activity block right below already uses `$group->getRelationships()` — using the same mechanism keeps this file's abstraction level consistent.
3. Direct entity access lets F reuse `$date_formatter` / `formatTimeDiffSince` already resolved in the neighboring block.

**Sort key for events:** if `node.type.event` carries a `field_event_date` (or similar), use it and filter `>= now` for "upcoming". If no such field or the value is absent, fall back to `created` DESC (matches discussion/docs behavior — "recent" is a reasonable degraded semantic). Confirm at F time by inspecting `docs/groups/config/field.field.node.event.*` or seeded event data; do NOT invent a new field.

**Access:** the loop must respect node access — use `$node->access('view', $current_user)` inside the filter, matching how the last-activity block silently omits inaccessible content (this is implicit in it running as the current user's request, but for a curated "top 3" the explicit check prevents a private node leaking a title).

### Cache metadata contract (mandatory)

Because the preprocess builds a render array whose contents depend on group + per-bundle node lists, F MUST attach the following cache metadata to `$variables` (via `CacheableMetadata::createFromRenderArray()` merge or by writing directly into `#cache`):

- **Cache tags:**
  - `$group->getCacheTags()` — invalidate when the group changes (esp. `field_group_type`).
  - `node_list:event`, `node_list:forum`, `node_list:documentation` — only the ones matching the resolved `leading_section` (`node_list:{bundle}` invalidates when any node of that bundle is created/updated/deleted).
  - Every rendered node's own `$node->getCacheTags()` — merged in via the loop (fold `CacheableMetadata::createFromObject($node)` for each item).
- **Cache contexts:**
  - `user.node_grants:view` — access-filtered list depends on the viewer's node grants.
  - `user.group_permissions` — same reason for group-scoped access.
  - `route.group` is implied by the request path; not required to add manually here.
- **Max-age:** inherit from the merged objects (do not set an explicit max-age; default `-1` is correct).

The existing preprocess does NOT currently attach cache metadata for the last-activity block — that is a latent bug in neighboring code, but out of scope for #122. F should not fix it drive-by. F SHOULD attach the metadata for the new lead-section render because it is new work and the correct pattern.

### Theme suggestion name shape — CONFIRMED

`group__community_group__events_first` (double-underscores between the entity-type/bundle segments, single-underscores within a segment) is correct. Drupal's suggestion→filename conversion replaces `__` and `_` with `-` in filenames, so the twig files land at:

- `group--community-group--events-first.html.twig`
- `group--community-group--discussion-first.html.twig`
- `group--community-group--docs-first.html.twig`

D's wireframe §4 lists these filenames correctly. F: confirm the suggestion strings in the alter hook use underscores (`events_first`) and the filenames use hyphens (`events-first`) — Drupal does the conversion, don't hyphenate the suggestion string.

### Extend-vs-parallel-path — enforced expectations

F MUST:
- Add new logic INSIDE `groups_chrome_preprocess_group()` (the existing function at theme.php:204). No new preprocess function for the same hook.
- Extend the existing `$gc` array with new keys (`leading_section`, `lead_label`, `lead_items`, `lead_help_copy`, `lead_see_all_url`, `lead_see_all_text`) — do NOT introduce a parallel `$variables['gc_lead']` twin.
- Attach the new `groups_chrome/group_type_homepage` library INSIDE the same conditional that populates non-empty `lead_items` (D's §5 wiring). The existing `groups_chrome/group_page` attach at line 216 stays unconditional; the new library is conditional.
- Extend `group--full.html.twig` in place (do NOT create `group--full--adaptive.html.twig` or similar). The three suggestion twigs are near-empty include passthroughs per D's §4.

The ONE new hook function — `groups_chrome_theme_suggestions_group_alter()` — is a genuinely new object (no existing suggestion hook in this theme) and is explicitly justified by the story's AC.

### Downstream compatibility

Verified no collision with recently-merged waves:
- **#109 do_streams** — shell + Views filters; does not decorate the group full-page render.
- **#119 do_showcase** — variant switcher + tour; scoped to `/showcase`, doesn't touch `groups_chrome_preprocess_group()`.
- **#138 do_group_membership** — Manage-members UI + Groups-Moderate; scoped to member management routes, doesn't touch the group full page.
- **#127** (twig-template-only, upcoming) — sole editor of a different template file per the wave handoff; will layer on top cleanly.

No parallel "lead section" primitive exists in any Do module.

### Test-writability

Plan is testable without ambiguity. T can:
- Assert `.gc-group-lead` presence + heading text + first-item title on each of the three exemplar groups (DrupalCon Portland 2026, Core Committers, Thunder Distribution — all seeded).
- Assert `.gc-group-lead` count === 0 on an unmapped-typed group (Geographical/Archive/unset) and count === 0 of `link[href*="group-type-homepage"]`.
- Assert tab bar text + order unchanged (D's §7 provides the exact assertion).
- Assert tooltip ⓘ presence, focusability (`tabindex="0"`), and accessible name (`aria-label`).
- Run axe against all four states.

## Patterns referenced

- `web/themes/custom/groups_chrome/groups_chrome.theme` lines 204–356 (preprocess to extend) and 455–481 (relationship-iteration pattern to mirror).
- `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` (template to extend).
- `web/themes/custom/groups_chrome/groups_chrome.libraries.yml` (library entry convention).
- `docs/groups/config/views.view.group_nodes.yml` lines 756–786, 941 (exposed bundle filter on existing per-group nodes path — resolves Q1).
- `docs/groups/modules/do_chrome/src/Hook/GroupTypeContentHelp.php` lines 136–150 (`infoTrigger()` — verbatim reusable pattern).
- `docs/groups/modules/do_chrome/css/do_chrome.css` lines 71–88 (`.do-chrome-info` styling — resolves Q2 contrast).
- `web/themes/custom/groups_chrome/css/tokens.css` line 29 (`--gc-color-bg: #ffffff` — resolves Q2 background).
