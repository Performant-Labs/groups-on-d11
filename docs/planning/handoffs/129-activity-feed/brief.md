# Brief — #129 ST-7 Activity feed rendering

## Story
`#129 ST-7: Activity feed rendering — interleaved social rows + render-time aggregation`. Full spec:
`gh issue view 129 --repo Performant-Labs/groups-on-d11` + artifact §5/§6b.

## Foundation (already merged, do not rebuild)
- `do_activity` module (#116): six message templates + `field_referenced_entity_{type,id}` + optional `field_group_id`.
- `do_streams` module (#109): shell + Views scope pattern (source of an EXISTS-shape template we replicate for messages).

## Reuse & Analogous-Feature map
See `survey.md`. Highlights:
- **NEW module** `do_activity_feed` — cleaner boundary than expanding `do_activity` (which is intentionally storage-only per its brief) or `do_streams` (node-focused).
- **NEW Views filter** `ActivityMembershipScope` modeled on `MembershipScope` but keyed on `message_field_data.field_group_id`.
- **NEW view** `activity_feed` (base = `message_field_data`).
- **REUSE** `node.stream_card` view mode for content rows; **REUSE** persona-switcher for E2E persona.

## Owns (disjoint files)
- `docs/groups/modules/do_activity_feed/**` — entire new module (see survey for file list).
- `tests/e2e/activity-feed.spec.ts`.

Nothing outside these paths.

## Which templates surface on `/activity` (A-advisory #1 — explicit)

`/activity` (my_groups scope) uses the `field_group_id` EXISTS filter. Only 3 of the 6 message templates carry `field_group_id`:

| Template | `field_group_id`? | Surfaces on `/activity`? |
|---|---|---|
| `activity_post_created` | yes | **yes** — content-card row |
| `activity_comment_created` | yes | **yes** — social comment row |
| `activity_membership_created` | yes | **yes** — social join row |
| `activity_flagging_created` (RSVP, follow_user) | **no** | **no** — deferred; POC skip |
| `activity_pin_toggled` | no | no |
| `activity_group_created` | no | no |

**Consequence for AC-1 / AC-6:** the "social row" MUST be a `activity_membership_created` (join). The "aggregated row" is a run of ≥2 `activity_post_created` by same actor in same group within 6h. The "content card" is a single `activity_post_created`. Do NOT seed flaggings for the E2E — they will not appear.

Follow-up: RSVP/follow/pin/group-created rows on a "Trending" / global tab is a later story.

## `hook_views_data` registration (A-advisory #3 — required)

The `ActivityMembershipScope` synthetic filter field MUST be registered via `hook_views_data` on the `message_field_data` base table — mirror `DoStreamsHooks::viewsData()` at `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (~L368). Add a `DoActivityFeedHooks::viewsData()` OO hook. Without this, the filter plugin cannot be attached in the view config.

## Row model + aggregation

Six row `type` values plus `aggregated`. Aggregation groups by `(actor, template, group)`.

**Window semantics (A-advisory #5 — explicit): pairwise consecutive.** Iterate rows in `created DESC`; fold row `n` into the current bucket only if `(actor, template, group)` matches the bucket AND `|current.created − previous_row_in_bucket.created| ≤ 6h`. A chain t=0 / t=5h / t=10h therefore folds into ONE bucket (both 5h gaps qualify). A gap > 6h opens a new bucket. Kernel test AC-2b codifies this.

Aggregable templates: `activity_post_created`, `activity_comment_created` (bucket key includes `field_group_id`), and — reserved for a later story — `activity_flagging_created` (bucket key includes flag id). Since `activity_flagging_created` is deferred (see table above), the POC aggregation exercises only `activity_post_created` and `activity_comment_created`.

## Route surface
- `GET /activity` — my_groups scope for current viewer.
- `GET /activity/group/{group}` — single-group scope (respects group access).
- Both return the shared feed render array from `ActivityFeedController::renderFeed()`.
- No new admin surface. No settings page.

## Presentation
- Content rows: `activity-row--content.html.twig` wraps `stream_card` render output with a compact meta strip ("posted by … in group X, 2h ago").
- Social rows: `activity-row--social.html.twig` with actor avatar + one-line sentence + timestamp.
- Aggregated rows: `activity-row--aggregated.html.twig` with a collapsible `<details>` disclosure — the `<summary>` is the aggregated sentence (e.g. "Maria Chen posted 3 topics"), the body lists the titles as links.
- CSS `activity-feed.css`: one vertical stack, alternating row types share the same left gutter; social rows are visually compact vs. content cards.

## Access
- The `field_group_id` scope filter is the primary gate.
- Content-row builder additionally checks `$node->access('view')` before rendering the stream_card; on deny, drop the row (feed skips it).
- `activity_group_created` and `activity_comment_created` for public-context comments (no group) are only surfaced in global scope (out of POC scope — omit for now; if a row has no `field_group_id`, exclude from `/activity`).

## Accessibility (WCAG 2.2 AA — MVP NFR #3578793)
- Every row's actor + timestamp reachable in reading order (semantic list).
- Aggregated rows use `<details>/<summary>` for native disclosure keyboard operability.
- Focus visible on the disclosure and on any link inside a row.
- Non-color status: aggregated marker is text ("3 topics"), not color-only.
- Contrast AA for meta text (≥ 4.5:1).

## Acceptance criteria (each backed by a T-authored test)
1. **Kernel — feed renders interleaved rows.** Seed a group Elena belongs to; create: (a) one `activity_membership_created` (Elena joined), (b) one `activity_post_created` (Alex, standalone), (c) a run of three `activity_post_created` by Maria in the same group ≤5h apart. Call `ActivityFeedController::renderFeed('my_groups')`; assert render array contains at least one `activity_row_social`, one `activity_row_content`, and one `activity_row_aggregated` (the Maria run, count=3).
2a. **Kernel — aggregation folds within window.** Two `post_created` by same actor 5h apart in same group aggregate (count=2).
2b. **Kernel — aggregation opens new bucket past window.** Three `post_created` at t=0, t=5h, t=13h — first two fold (count=2), third stands alone.
2c. **Kernel — aggregation chains within pairwise window.** Three `post_created` at t=0, t=5h, t=10h — all three fold (count=3), proving pairwise-consecutive semantics.
3. **Kernel — access scoping.** User in Group A only sees rows whose `field_group_id` is A; user in no groups sees empty result on `/activity`.
4. **Kernel — content-row access.** Message references node the viewer cannot view → row omitted (not "access denied" placeholder).
5. **Kernel — comment snippet.** Comment row includes truncated body text (≤ 180 chars, tags stripped).
6. **E2E (`activity-feed.spec.ts`)** — Elena persona at `/activity` shows ≥1 social row (a `membership_created` join), ≥1 aggregated row, ≥1 content card. Group-scope variant at `/activity/group/<some-gid>` shows only that group's rows. If the seeded demo data doesn't naturally produce these, the test's fixtures step (or `step_7xx_backfill_activity.php` companion + a small pre-test seed hook) MUST create them — do NOT flake on incidental seed data.
7. **Existing suite green** (`phpunit` module suites + full E2E) — no regression to `do_activity`, `do_streams`, `do_group_membership`.
8. **HelpText entry** appended for the new `/activity` surface (SD-6 backstop, do NOT block on SD-6).
9. **WCAG 2.2 AA** met (labels / keyboard / focus / contrast / non-color) — U walkthrough asserts.

## Advisories from A (implementation notes for F)
- `ActivityMembershipScope`'s `query()` docblock MUST show the concrete SQL shape (like `MembershipScope` does): EXISTS on `{message__field_group_id}` joined to `{group_relationship_field_data}` for `plugin_id = 'group_membership'` and `entity_id = current uid`.
- `activity_membership_created` may leave `field_referenced_entity_type` empty (the user IS the referenced entity); row builder must default to `'user'` when empty and read the id from `uid` or `field_referenced_entity_id` — whichever the #116 hook populated (confirm with a `Message::load` in a kernel fixture; do NOT guess).

## Review rigor
`none` (per issue). No brief-gate. No diff-gate. Skip A-dup (POC lean pipeline).

## DDEV rename
`.ddev/config.yaml` `name: gm129-activity` — commit as part of work if needed for local; unaffected by CI.

## Concurrency context
Wave-execution parallel: 8 concurrent orchestrators. Siblings on other stories won't touch `do_activity_feed/` paths. Rebase before PR to catch integration bugs.
