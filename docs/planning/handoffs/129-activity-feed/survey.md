# Survey — #129 ST-7 Activity feed rendering

## Foundation (present on main, do NOT rebuild)
- **`do_activity`** (#116 merged) — logs six activity types as `message` entities:
  - Templates: `activity_post_created`, `activity_comment_created`, `activity_membership_created`, `activity_flagging_created`, `activity_group_created`, `activity_pin_toggled`.
  - Fields on every message: `uid` (actor, core), `field_referenced_entity_type` (string), `field_referenced_entity_id` (int), `field_group_id` (entity_ref → group, may be NULL for group-less templates), `created` (core).
  - Backfill script `docs/groups/scripts/step_7xx_backfill_activity.php` populates historical rows in seeded environments.
  - Class: `docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php`.
- **`do_streams`** (#109 merged) — provides two Views scope filters plus the shared shell theme hook:
  - `do_streams_membership_scope` (Views filter on `node_field_data`) — restricts nodes to groups the current viewer belongs to. **Node-only** — cannot be reused as-is for `message` rows.
  - `do_streams_following_scope` — flag-driven follow scope (unused here).
  - Shell theme: `do_streams_shell` (see `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig`) — currently `stream_card`-oriented; adjacent to but not a hard dep for this story.

## Reuse & Analogous-Feature map

| Need | Existing analog | Extend vs new |
|------|-----------------|---------------|
| Scope-aware Views filter for `message` rows (viewer's groups) | `do_streams\Plugin\views\filter\MembershipScope` (EXISTS-shape on `node_field_data`) | **NEW** — new filter targeting `message_field_data` via `field_group_id`. Different base table + different join key. Modeled directly on MembershipScope's EXISTS pattern. |
| Feed view config | `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml` | **NEW** view — `activity_feed`. Base table `message_field_data`. Cannot extend the demo view (different base table + purpose). |
| Row templates | none for activity | **NEW** — `activity-row--social.html.twig`, `activity-row--content.html.twig`, `activity-row--aggregated.html.twig`. |
| Content-card render for content-created rows | `node.stream_card` view mode already exists (config `core.entity_view_mode.node.stream_card.yml`) | **EXTEND** — activity-row for `activity_post_created` renders the referenced node in `stream_card` view mode. |
| Route/controller | none | **NEW** — `ActivityFeedController` returning a rendered view result; scope + optional group_id come from route params. |
| Aggregation | none | **NEW** — render-time collapse; a small PHP service `ActivityAggregator` in the module. |
| CSS | none for activity | **NEW** `activity-feed.css`. |
| Persona/access | Group + core node grants + `field_group_id` filter carry the access story; no new access plugin. | REUSE existing. |

## Feed row model (source of truth: rendered `message` rows)

For each message loaded by the feed view we compute a **row model** at render time:

- `type` — one of `content_card | social_join | social_rsvp | social_comment | social_group_created | social_pin | aggregated`.
- `actor` — user entity (loaded from `uid`).
- `group` — group entity (from `field_group_id`) when present.
- `referenced_entity` — loaded from `(field_referenced_entity_type, field_referenced_entity_id)`.
- `snippet` — first ~180 chars of comment body for comment rows.
- `count` — for aggregated rows: number of collapsed messages.
- `children` — for aggregated rows: list of titles/links from underlying messages.
- `created` — timestamp of the newest message in the group (aggregated → most recent).

## Aggregation rule (per spec §5/§6b)

Collapse consecutive same-type messages by `(actor_uid, template, group_id)` within a **6-hour window** where all rows are of an aggregable template. Aggregable templates: `activity_post_created` (Maria's 3 topics), `activity_comment_created` on the same commented entity's group, `activity_flagging_created` for the same flag id (e.g. 3 RSVPs). The other three templates (`membership_created`, `group_created`, `pin_toggled`) render as-is.

Algorithm:
1. Query fetches ordered messages (created DESC, LIMIT ~50 with scope filters applied).
2. Iterate; when current row's `(actor, template, group)` matches previous and `abs(created_delta) <= 6h`, fold into the same bucket. Otherwise start new bucket.
3. Buckets with `count >= 2` render as `aggregated` type; count 1 renders as its underlying row type.

## Owned files (disjoint)

- **New module namespace: `do_activity_feed`** under `docs/groups/modules/do_activity_feed/`.
  - `do_activity_feed.info.yml` (deps: `drupal:do_activity`, `drupal:do_streams`, `drupal:views`, `drupal:message`, `drupal:group`, `drupal:node`, `drupal:comment`, `drupal:user`).
  - `do_activity_feed.module` (theme_hooks for `activity_row_social`, `activity_row_content`, `activity_row_aggregated`, `activity_feed`; preprocess helpers).
  - `do_activity_feed.routing.yml` (`/activity`, `/activity/group/{group}`).
  - `do_activity_feed.services.yml` (`ActivityAggregator`, `ActivityRowBuilder`).
  - `do_activity_feed.libraries.yml` (activity-feed css).
  - `src/Controller/ActivityFeedController.php`.
  - `src/Service/ActivityAggregator.php`.
  - `src/Service/ActivityRowBuilder.php`.
  - `src/Plugin/views/filter/ActivityMembershipScope.php` — new filter on `message_field_data` (EXISTS-shape on `field_group_id` → viewer's memberships).
  - `config/install/views.view.activity_feed.yml` — base table `message_field_data`, exposes `?scope=&group=` via contextual args, has one display but we render via controller-driven executable.
  - `templates/activity-row--social.html.twig`, `activity-row--content.html.twig`, `activity-row--aggregated.html.twig`, `activity-feed.html.twig`.
  - `css/activity-feed.css`.
  - `tests/src/Kernel/ActivityFeedRenderTest.php` (loads foundation, seeds messages, invokes controller, asserts row types + aggregation).
  - `tests/src/Kernel/ActivityMembershipScopeTest.php` (access — user in group X sees only X's activity).
- **New E2E test:** `tests/e2e/activity-feed.spec.ts` — asserts (as Elena persona) at `/activity` that at least one social row, one aggregated row, and one content card render, and that a group-scope variant shows only its group's rows.

## Forward-compat check

ST-8 (`#130`) mounts the feed on other stream surfaces. Contract: `ActivityFeedController::renderFeed(string $scope, ?GroupInterface $group = NULL): array` returns a render array. ST-8 can call this same builder — no downstream conflict.

## Key findings / risks

- **The `activity_membership_created` message has NO `field_referenced_entity_type` value** because the referenced entity IS the user (see #116 kernel test) — but `field_referenced_entity_id` is populated. Row builder must special-case: `type='user'` inferred. (Check `field.field.message.activity_membership_created.field_referenced_entity_type.yml`; if the field allows empty, the row builder must default.)
- **Aggregation is render-time only.** Storage remains raw — required per spec.
- **Message `bundle` = template id.** Views base table is `message_field_data`; filtering by template uses the `template` column.
- **Access:** the `field_group_id` EXISTS filter alone is not full node-level access. For content rows we additionally check `$node->access('view')` in the row builder before rendering the stream_card; skip the message row if not viewable. Membership/group-created rows are entirely governed by the scope filter (viewer already in the group).
- **Comment snippet:** load comment via `(field_referenced_entity_type='comment', id)`, take `comment_body.value`, strip tags, truncate to 180 chars.
- **Persona `elena`:** already exists in seed (`step_770.php` region). Playwright switches persona via existing `?persona=` switcher (#120 merged).
- POC efficiency waiver applies — no caching heroics; query LIMIT 50; iterate in PHP.
