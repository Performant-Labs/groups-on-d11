# Survey — #114 ST-5 Profile activity stream

**Story:** Render authored, published, viewer-access-scoped nodes on `/user/{uid}`
as a compact "Recent posts" list, newest first. Playwright rendered-DOM proof
on the seeded site.

## Files this run OWNS (per issue "Owns" list)

- `docs/groups/config/views.view.user_activity.yml` (new)
- `docs/groups/modules/do_streams/css/profile-activity.css` (new)
- `docs/groups/config/block.block.do_streams_user_activity.yml` (new — chosen: block placement)
- `tests/e2e/profile-activity.spec.ts` (new)
- Optional: `docs/groups/modules/do_streams/tests/src/Kernel/UserActivityViewTest.php` (own file)

## Reuse & Analogous-Feature map (extend/refactor by default)

| Concern | Closest existing analogue | Recommendation |
|---|---|---|
| Stream view definition | `views.view.group_content_stream.yml` (nodes; group context) → same base (`node_field_data`), same status=1 filter, same created DESC sort | **EXTEND pattern**: new view `user_activity` with author (`uid`) contextual argument instead of `gid`. Block display (not page — `/user/{uid}` is a user canonical route). |
| Group-access scope | do_group content is exposed through Group's `group_relationship` join used by `group_content_stream`, plus core `group_content_access` when viewing groups | **REUSE** the same `group_relationship` relationship + `status=1` filter; `gnode` grants act at entity access time so a plain `node_access` filter (`plugin_id: node_access`) on the view enforces viewer-scoped visibility without needing a new plugin. Ungrouped nodes are visible normally. |
| Author scoping | Views core has a `uid` argument on `node_field_data` (author) | **REUSE**: contextual filter on `node_field_data.uid`, `default_argument_type: user`, `use path = true` so the {user} in `/user/{uid}` provides it via a block context. Block placement: user page context. |
| Card presentation | `stream_card` view mode already exists (`core.entity_view_mode.node.stream_card.yml`), used by do_streams | **REUSE stream_card** in the row (row plugin `entity:node`, view_mode `stream_card`). CSS scopes visual tweaks with `.do-streams-profile-activity` wrapper class. |
| Block placement | Existing block YAMLs in do_streams config path (following_feed etc.) | **REUSE convention**: `block.block.do_streams_user_activity.yml` places the views block on user pages via visibility condition `request_path` = `/user/*` (matches existing profile blocks like `do_contribution_stats`). |
| Empty state / demo | Every persona must show ≥1 item | **VERIFY only** — seed step_700 already gives Maria 1 topic; other personas author events/docs. Do NOT edit seed unless a demo persona's page is empty. |

**Extend-vs-new decision:** ALL EXTEND / REUSE. No new plugins, no new hooks, no new PHP required for the base story. Existing views core + do_streams contract satisfy it.

## Forward-compat check

No downstream story consumes `user_activity` as a contract. #116 (activity foundation) is merged; ST-F2 (out of scope here) would be a *different* view (message-based social actions). Safe.

## Key findings / constraints

- Author `uid` argument at `node_field_data.uid` with `default_argument_type: user` (User from URL) and `path: user/%` matches `/user/{uid}`.
- `node_access` filter (`plugin_id: node_access`, op = 'view') is the CORRECT way to enforce viewer-scoped visibility including gnode's group-membership grants. Do NOT re-implement.
- `status=1` filter on `node_field_data` enforces "published never renders unpublished".
- Section header string: literally `"Recent posts"` (per issue: "reads honest, not broken").
- Section MUST be present on every `/user/{uid}` page even when empty — E2E asserts header present; when Maria's profile is visited, asserts her three items in newest-first order.
- WCAG 2.2 AA: heading level (block title = h2 under user page), sufficient contrast, no color-only status, keyboard-operable links (stream_card links already are), visible focus (core theme handles). CSS additions must not weaken contrast.
- Playwright: hit `/user/{maria_uid}` — pull uid via `drush user:information maria_chen --format=json` inside container-seeded site, OR resolve via login-then-navigate-to-account-link. Existing e2e patterns (see `tests/e2e/*`) will inform.

## Acceptance mapping (each ACs → verification vehicle)

| AC | Verified by |
|---|---|
| Renders `/user/{maria_uid}` with Maria's 3 topics, newest first | Playwright (rendered-DOM assertion on titles + order) |
| Outsider viewer cannot see private-group content | Kernel test (recommended): install view, create private group + node, assert view result set excluded for anon viewer. If skipped: assert in Playwright with anon session — noting node_access enforcement is the mechanism. |
| Unpublished never renders | Kernel: create unpublished node, assert absent from view (already covered by filter status=1). |
| Playwright green + existing suite green | CI run |
| HelpText entry (SD-6 backstop) | Append to `do_chrome/src/HelpText.php` a "Profile activity" section — 1 short entry. |
| WCAG 2.2 AA | Playwright axe scan (existing pattern; else manual note in decisions) |
| Seed demo-well: all six personas non-empty | Read step_700 to confirm each persona authors ≥1 node; append-only tweak only if not. |

## Risks

- Block-context on user pages: verify `block.block.do_streams_user_activity.yml` picks up `@user.current_user_context:current_user` isn't right — we need the URL user, not current viewer. Use views block context = "User from URL" (built-in). Block visibility + views default arg do the work; the block itself is placed in a region and its own context (from view arg) handles it.
- Alternate: local task/tab at `user/{user}/activity`. Rejected: issue wording favors "block or local-task tab"; block is simpler and matches the neighboring `do_contribution_stats` pattern.
