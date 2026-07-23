# Brief — #111 ST-2 Following feed (`/following`)

**Issue:** https://github.com/Performant-Labs/groups-on-d11/issues/111
**Branch:** `111-stream-following` (worktree `~/Projects/_worktrees/groups-stream-111`)
**DDEV name:** `gm111-stream` (rename staged in `.ddev/config.yaml`; keep until final teardown)
**Review rigor:** none (POC lean pipeline; skip brief-gate + A-dup + pre-PR hold)
**Depends on:** ST-F1 #109 (merged) — `FollowingScope` filter + `stream_card` view mode.
**Sibling in-flight:** #110 ST-1 (`my_feed` / `/my-feed`). Disjoint files; both edit `step_700_demo_data.php` **append-only**.

## Objective
Ship a following-scoped feed at `/following` — authenticated-only, `stream_card`-rendered, recent-ranked, deduped, WCAG 2.2 AA, with append-only seed additions so each seeded persona has a demo-credible non-empty tab.

## Files owned
- `docs/groups/config/views.view.following_feed.yml` — NEW; clone of `views.view.activity_stream.yml` with three deltas (see plan).
- `docs/groups/modules/do_streams/css/following.css` — NEW; scoped tweaks only. Do NOT modify shared stream styles.
- `tests/e2e/following.spec.ts` — NEW (T authors).
- (Optional/recommended) `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php` — NEW (T authors) for the group-access negative case.
- `docs/groups/scripts/step_700_demo_data.php` — **APPEND-ONLY** additions (see plan). Do NOT touch existing lines.

## Plan (F executes against T's failing tests)

1. **Create `views.view.following_feed.yml`** by cloning `activity_stream.yml` and applying exactly these three deltas:
   - `id: following_feed`, `label: 'Following Feed'`, `description: 'Content the current user follows (nodes, authors, or tags) — reverse-chronological, deduped, authenticated only.'`
   - **Add scope filter** on `default.display_options.filters`:
     ```yaml
     do_streams_following_scope:
       id: do_streams_following_scope
       table: node_field_data
       field: do_streams_following_scope
       relationship: none
       group_type: group
       admin_label: ''
       plugin_id: do_streams_following_scope
       value: ''
     ```
   - **Access: authenticated role.** Replace `access.type: perm` block with:
     ```yaml
     access:
       type: role
       options:
         role:
           authenticated: authenticated
     ```
   - **Route + empty state** on `page_1` display: `path: following`, `menu` block omitted (no nav link — spec does not ask for one; nav is #110's concern).
   - **Empty-state copy** on `default.display_options.empty.area_text_custom.content`:
     ```html
     <div class="gc-empty"><p class="gc-empty__title">You're not following anything yet</p><p class="gc-empty__text">Browse the <a href="/stream">stream</a> or explore <a href="/tags">tags</a> to find people, content, and topics to follow.</p><a class="gc-button gc-button--primary" href="/stream">Browse the stream</a></div>
     ```
   - Sort: `sorts.created` DESC (drop the `last_comment_timestamp` sort from `activity_stream` — recency by publication date is more test-predictable).
   - Keep `query.options.distinct: true` and `query.options.disable_sql_rewrite: false` (default). SQL rewrite + `distinct` handle group-access AND dedupe.
   - `css_class: following-feed` (matches CSS file).

2. **Create `css/following.css`** — a small scoped file for `.following-feed` container tweaks (spacing above the empty-state, if any). Keep minimal; the shared `stream_card` styles carry the card visuals. Add `stylesheets:` entry in `do_streams.libraries.yml` if that file exists, or attach via `#attached` in a preprocess hook only if strictly needed. **Simplest:** attach the CSS via `hook_preprocess_views_view__following_feed()` OR register a `do_streams/following` library and attach it via the view's `#attached`. F picks the cleanest option compatible with the module's existing pattern.

3. **Append-only seed additions in `step_700_demo_data.php`.** After the existing follow-seeding block, append:
   - `$flag_service->flag($follow_user, $maria, $elena);` — Elena follows Maria (author).
   - `$flag_service->flag($follow_term, $drupalcon_term, $elena);` — Elena follows `drupalcon` tag (look up the term the same way `$core_term` is looked up).
   - `$flag_service->flag($follow_content, $paragraphs_tutorial_node, $sophie);` — Sophie follows the "Getting Started with Paragraphs" node (look up by title, same pattern as the existing `Patch Review Process RFC` lookup).
   Each wrapped in the same try/catch + echo pattern as existing lines.

4. **HelpText entry** (per spec: "Ships with its HelpText entry (append-only) for any new user-facing surface"). Check whether the project has a HelpText catalog file (search `docs/groups/config/*helptext*` or `docs/groups/modules/*/config/install/*helptext*`). If it exists, append one entry for `/following`. If it does not exist in the current codebase, note that SD-6 (#133) is the backstop and skip — do not invent a new file.

## Acceptance criteria (each must map to a T-authored test)
- [ ] Anonymous `/following` → 403 or login redirect.
- [ ] elena_garcia sees: Patch Review Process RFC (follow_content, seeded), Maria-authored content (new follow_user), a `core`-tagged node (follow_term, seeded), a `drupalcon`-tagged node (new follow_term). Each exactly once — no duplicates when multiple branches match.
- [ ] ravi_patel sees Maria-authored content (existing follow_user seed).
- [ ] sophie_mueller sees the "Getting Started with Paragraphs" tutorial (new follow_content seed).
- [ ] A fresh authenticated user with no follows → empty state renders, links to `/stream` + `/tags` present and keyboard-accessible.
- [ ] Group-access negative: a user follows a node in a group they cannot access → row absent (kernel test preferred; e2e acceptable if fixture-able).
- [ ] Existing Playwright + kernel suites remain green.
- [ ] WCAG 2.2 AA on `/following`: page has an `<h1>`, empty-state links have visible focus, colour contrast AA, no non-text status.

## Input documents
- `docs/handoffs/111-stream-following/survey.md` (this run)
- `docs/groups/config/views.view.activity_stream.yml` (analogous view)
- `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml` (demo view showing scope filter wiring on `page_following` display)
- `docs/groups/modules/do_streams/src/Plugin/views/filter/FollowingScope.php` (scope filter contract)
- `docs/groups/scripts/step_700_demo_data.php` lines 369–395 (existing follow seeds — append after this block)

## Handoff locations
`docs/handoffs/111-stream-following/`
- `survey.md` (done)
- `brief.md` (this file)
- `decisions.md` (append-only, one entry per phase)
- `handoff-A.md`, `handoff-T-red.md`, `handoff-F.md`, `handoff-T-green.md`, `handoff-U.md`, `handoff-S.md`

## Out of scope
- Nav-menu link to `/following` (a later story or explicit follow-up).
- Wiring the shell's Following tab to link here (later story).
- Notifications-preferences alignment with drupal.org MVP #3578790 (coordination note only; separate work).
