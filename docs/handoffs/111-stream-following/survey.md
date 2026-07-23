# Survey — #111 ST-2 Following feed

## Scope recap
New view `following_feed` at `/following`, following-scope (ST-F1 plugin), recent ranking, `stream_card` cards, deduped, authenticated-only, empty state linking `/stream` + `/tags`. Append-only follow seeds. WCAG 2.2 AA. Playwright rendered-DOM spec.

## Reuse & Analogous-Feature map

**Closest analogous feature: `activity_stream` view at `/stream`** (`docs/groups/config/views.view.activity_stream.yml`). It is the exact pattern to clone: `base_table: node_field_data`, `stream_card` row plugin, empty area_text_custom, `access: perm access content`, pager, `distinct: true`, `row_class: stream-card-wrapper`.

**Extend-vs-new recommendation: NEW file `views.view.following_feed.yml`**, deliberately cloned from `activity_stream.yml`. Justification: the two views differ in three orthogonal ways (route path, access perm, scope filter) and the epic explicitly gives each stream story its own YAML for disjoint file ownership across sibling in-flight stories (#110 `my_feed`, #111 `following_feed`, #112+…). Extending `activity_stream` with additional displays would collide with sibling stories and violate the epic's disjoint-file contract.

**Reuse (unchanged):**
- `Drupal\do_streams\Plugin\views\filter\FollowingScope` (`do_streams_following_scope`) — done in ST-F1 (#109). Instantiate in the view's `filters:`, exactly as the demo view's `page_following` display does at `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml:144-152`.
- `stream_card` view mode (`docs/groups/config/core.entity_view_mode.node.stream_card.yml`) — reuse as-is.
- Empty-state markup convention (`gc-empty__title`, `gc-empty__text`, `gc-button`) — reuse from `activity_stream.yml:108-119`.
- Seed personas + flags already present in `docs/groups/scripts/step_700_demo_data.php` (lines 369-395).

**New (justified):**
- `docs/groups/config/views.view.following_feed.yml` — reason above.
- `docs/groups/modules/do_streams/css/following.css` — spec-owned; small scoped tweaks only.
- `tests/e2e/following.spec.ts` — spec-owned.

## Key implementation notes for F

1. **Access: authenticated-only.** Do NOT reuse `activity_stream`'s `access: perm access content`. Use `access: perm access content` restricted by role, OR simplest: a role check via `perm: 'view own unpublished content'` no — the correct primitive is `access: perm` with `perm: 'access content'` PLUS a role filter, but the cleanest Drupal-idiomatic answer is a **route access requirement on the page display**: set `access: role` and select the `authenticated` role. When an anonymous user hits `/following`, Drupal returns 403 (spec-acceptable per #110's analogous "anonymous /my-feed → login/403").
2. **Scope filter goes on `default` (not just `page_1`)** so the filter applies to every display. Same pattern as `do_streams_demo` uses on `default` for `do_streams_membership_scope`.
3. **Group-access enforcement (spec Q2).** `disable_sql_rewrite: false` (the default) makes Drupal's node access system + the `group` module's grants automatically strip nodes from groups the viewer cannot access. The scope filter does NOT need to enforce this — Views SQL rewrite does. **T must include a coverage case: a user follows a node in a group they cannot access → node MUST NOT appear.**
4. **Dedupe.** `query.options.distinct: true` (same as `activity_stream`) is sufficient because the FollowingScope filter is EXISTS-based (no LEFT JOIN fan-out); the OR of 3 EXISTS branches contributes exactly one row per node. No GROUP BY needed.
5. **Recent ranking.** Use `sorts.created` (or `last_comment_timestamp` as `activity_stream` does). Spec says "Recent ranking" — either meets the acceptance criterion. Recommend `created DESC` for pure recency (predictable in tests); `last_comment_timestamp` would let a stale-but-active thread jump. F picks `created DESC`.
6. **Empty state text.** Per spec: prompts follows and links to `/stream` and `/tags`. Draft copy: "You're not following anything yet. Browse the [Stream](/stream) or explore [Tags](/tags) to find people, content, and topics to follow."
7. **Seed additions (append-only in `step_700_demo_data.php`).** Per spec's Demo-well requirement, the concrete three: Elena follows Maria (follow_user), Elena follows `drupalcon` tag (follow_term), Sophie follows the "Getting Started with Paragraphs" node (follow_content). Append at end of the follow-seeding block; do NOT modify existing lines.

## Files T (RED) must cover

- **Playwright**: `tests/e2e/following.spec.ts`
  - Anonymous → `/following` → 403 or login redirect.
  - Login as elena_garcia → `/following` renders at least one card, includes the Patch Review Process RFC (existing follow_content) and Maria's authored content (new follow_user) and a `core`-tagged node (existing follow_term) and a `drupalcon`-tagged node (new follow_term), each exactly once (dedupe).
  - Login as ravi_patel → `/following` renders Maria-authored content (existing follow_user).
  - Login as sophie_mueller → `/following` renders the Paragraphs tutorial (new follow_content).
  - Login as a persona who follows nothing (create fresh user with no follows) → empty state visible with `/stream` and `/tags` links.
- **Kernel** (optional but recommended, in `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php`): a group-access negative case — user follows a node in group they cannot access → row absent. This one is hard to prove reliably in e2e; kernel is the honest place.

## Forward-compat check
- `/following` is a **leaf** route in the stream family. No later story consumes it as a shared surface (the shell tabs, when wired by a later story, will *link to* `/following`, not extend this view).
- Wireable-tab wiring (future) reads `data-url-or-param` off the shell — `/following` is a valid target for the Following tab. No conflict.

## Existing tests to keep green
`docs/groups/modules/do_streams/tests/src/Kernel/*` (Streams{Install,Ranking,Scope,Shell}Test.php) — unaffected by adding a new view; still expected green.

## Risk
Low. Analogous view exists (`activity_stream`), scope filter is battle-tested, seed additions are additive, no code changes to any shared module surface.
