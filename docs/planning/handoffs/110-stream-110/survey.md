# Survey — #110 ST-1 My Feed at `/my-feed`

## What the story asks for
- New Views view `my_feed` at path `/my-feed` restricted to `authenticated`.
- Membership scope (uses #109's `do_streams_membership_scope` filter plugin).
- Recent ranking (default `created DESC`; no ranking-argument wiring needed for MVP).
- Uses `stream_card` view mode row renderer.
- Pager (full type).
- Friendly empty state ("Join a group to build your feed" → `/all-groups`).
- Wrap results in the shared `do_streams_shell` (theme hook from #109) with `active_scope='my_feed'`, `active_ranking='recent'`.
- Nav link "My Feed" for authenticated users only, appended in `docs/groups/scripts/step_780_nav_menu.php`.
- Anonymous `/my-feed` → login/403 (view access = `role: authenticated`).
- HelpText entry appended (append-only) for the new surface.
- Playwright e2e: login as `elena_garcia`, assert an in-scope title ("Sprint Planning: Portland 2026" — pinned, in DrupalCon Portland 2026) present; assert an out-of-scope title (Thunder Distribution / Drupal Deutschland content) absent; assert nav link exists.
- WCAG 2.2 AA on new UI.
- Front page config UNTOUCHED (Q1 open).

## Reuse & Analogous-Feature map (extend-first)

| Concern | Reuse source (extend) | Notes |
|---|---|---|
| Shared shell chrome (tabs + ranking) | `#[Hook('theme')] do_streams_shell` + `preprocess_do_streams_shell` in `DoStreamsHooks` | Caller sets `#active_scope`, `#active_ranking`, `#results`. NEW: no controller currently uses it. |
| Membership scope | `Drupal\do_streams\Plugin\views\filter\MembershipScope` (registered as `do_streams_membership_scope` via `viewsData()` on `node_field_data`) | Just reference it in the view's `filters:` block, same shape as `activity_stream` uses `type`/`status`. |
| View template & rows | `docs/groups/config/views.view.activity_stream.yml` | Copy structure: same base_table (`node_field_data`), same row type (`entity:node` / `stream_card`), same bundle filter, same pager. Diff: change display path to `/my-feed`, add `do_streams_membership_scope` filter, restrict access to `authenticated` role, remove `use_ajax: true` if it complicates the shell wrap (verify). |
| Nav-link seeding | `step_780_nav_menu.php` — appends `menu_link_content` entries idempotently keyed on `description` | EXTEND: append a fifth entry with a NEW key `st1-nav-my-feed`, weight 1.5 to sit between Activity and My Groups (or after Activity; wireframe decides ordering). Auth-only visibility: `menu_link_content` doesn't natively hide per role — options: (a) `access_check` route requirement on the target route (auth-only view → link hidden by menu_link.access.default) OR (b) use `condition: user.roles: authenticated` via block visibility. Cleanest: rely on Drupal's built-in menu link access filter which hides links whose route access denies the user. The view display's access plugin (`role: authenticated`) will make anonymous users get 403, and menu link access filtering will hide the link. |
| Shell wrap over a view | NEW pattern (no existing example) | Choices: (A) custom Controller that calls the view via `views_embed_view()` and returns a `#theme => do_streams_shell` array wrapping the render; own route `/my-feed`; drop the view's page display. (B) Views display attachment / area handler wrapping. (C) `hook_views_pre_render` on view id `my_feed` that swaps the built output into shell variables. **Recommendation: (A)** — cleanest, testable, and matches "controller sets `#active_scope`, `#active_ranking`, `#results`" contract in `DoStreamsHooks::theme()`. The view still owns its filter/sort/row-render logic and stays reusable as a block or elsewhere. |
| HelpText | `Drupal\do_chrome\HelpText::get()` | Append a new entry, keyed e.g. `stream.my_feed`, with a short copy string. Zero edits to existing entries. |
| Empty state | Shell already renders `empty_copy['my_feed']` via preprocess when `$results` is empty | Controller passes an empty `#results` array when the view returns zero rows; shell handles it. Optional: augment with a link to `/all-groups` — either extend `empty_copy['my_feed']` (edit) OR render extra markup in the controller. **Story wants a CTA "→ `/all-groups`"** so we need a link. Cleanest: controller layers a small render array on top of the shell's own empty state, OR add a new optional `empty_cta` variable to the shell theme hook (small extension, forward-compat for #111-#115). RECOMMEND: extend the shell theme hook with an optional `empty_cta` variable (backward compat: default null → renders nothing). This is a legitimate shell extension #111-#115 will also want. |

## Key findings
- `activity_stream` (unwired currently in nav → but nav's "Activity" points at `/stream`, its page display) still works as the anonymous fallback. Story says "anonymous keeps global `/stream`" — no change needed there.
- Elena's 5 groups per seed: DrupalCon Portland 2026, Core Committers, Leadership Council, Camp Organizers EMEA, Drupal France — all present in seed and contain forum/event/post/page content. "Sprint Planning: Portland 2026" is seeded and pinned. Membership filter should produce a demonstrably rich feed.
- Thunder Distribution / Drupal Deutschland → confirm both exist as OUT-OF-SCOPE groups Elena is NOT in. Seed file has them (verified via grep earlier).
- CI runs from assembled layout; new view YAML in `docs/groups/config/views.view.my_feed.yml` gets copied by `scripts/ci/assemble-config.sh` → `config/sync/`. Verify assemble script doesn't exclude that file.
- Route collision: `/my-feed` is not a Group-shipped path, no risk of the #138-style collision.
- Testing: functional test can install do_streams + create a user in 1 group + non-member group + assert view results; e2e runs against seeded site so `elena_garcia` route works out of the box.

## Forward-compat check (for shared shell extension)
- If we add `empty_cta` to the shell theme hook + preprocess: verify #111-#115 (per-content-type streams) all benefit / none conflict. Reading their issue titles: `#111` (Following), `#112` (Trending), `#113` (Global), `#114-115` (per-CT). All will hit empty states; a CTA slot is universally useful. Safe to add as optional.

## Recommendation summary
- **New files**: `docs/groups/config/views.view.my_feed.yml`, `docs/groups/modules/do_streams/css/my-feed.css`, `docs/groups/modules/do_streams/src/Controller/MyFeedController.php`, `docs/groups/modules/do_streams/do_streams.routing.yml`, `docs/groups/modules/do_streams/do_streams.links.menu.yml` (optional; menu link seeded via step_780 instead), `tests/e2e/my-feed.spec.ts`, kernel/functional test file if useful.
- **Extend**: `DoStreamsHooks::theme()` — add optional `empty_cta` variable (default `[]`). Shell template — render `empty_cta` inside `gc-empty` when non-empty. `HelpText.php` — append `stream.my_feed`. `step_780_nav_menu.php` — append 5th link.
- **Route**: `do_streams.my_feed` → path `/my-feed`, requirement `_role: authenticated`, controller `MyFeedController::render`.
- **View**: page display OFF (or omit); default display exposed so `views_embed_view('my_feed', 'default')` works; membership filter always-on; pager 10; row `stream_card`.
