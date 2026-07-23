# Handoff-A: Phase 3 — #111 ST-2 Following feed  (up-front plan review)

**Date:** 2026-07-23
**Branch:** 111-stream-following
**Brief reviewed:** docs/handoffs/111-stream-following/brief.md
**Reuse map:** docs/handoffs/111-stream-following/survey.md
**Wireframe:** N/A (leaf route, no shell wiring in this story)
**Verdict:** PASS

## Summary

The plan is a clean clone-with-three-deltas of the analogous `activity_stream` view, correctly consumes the ST-F1 `do_streams_following_scope` filter using the exact wiring already proven in `do_streams_demo.yml:page_following`, and keeps its file boundary disjoint from sibling #110. The high-risk correctness concern (group-access enforcement with EXISTS-based scope) checks out: FollowingScope adds a `WHERE` expression via `addWhereExpression()` on the base `node_field_data` query — it does not disable SQL rewrite — so Views' node-access alter still runs and group grants still apply. One `warn` on the CSS-attachment ambiguity (three options offered; pick one), otherwise nothing to change.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | CSS attachment (Plan step 2) | pattern consistency | Brief offers three options (libraries.yml stylesheets entry, preprocess-hook `#attached`, or a registered `do_streams/following` library attached via view `#attached`). "F picks" is fine for POC but a stylesheets-only entry in `do_streams.libraries.yml` is the module-idiomatic choice already used by neighbors; the preprocess-hook path adds a hook where none is needed. | Prefer registering `do_streams/following` library and attaching it in the view's `display_options.css_class` context via a small `hook_views_pre_render` or, cleaner, directly via `display_options.header`/`display_options.display_options` isn't possible for libraries — so: register library and attach via `hook_preprocess_views_view__following_feed` (single-hook, matches project's existing lightweight-preprocess pattern). Note the choice in handoff-F. |
| 2 | warn | Access model (Plan step 1, delta 3) | cross-cutting: authorization | `access: type: role, role: authenticated` gives 403 for anonymous — Drupal-idiomatic and matches the survey's rationale. It diverges from `activity_stream`'s `perm: access content` intentionally; that divergence is correct (spec-required). No drift, but flag: without also asserting `status = 1` (already present in the cloned filter set) an authenticated user with `view own unpublished content` could see unpublished nodes they follow. The `status` filter from `activity_stream` is preserved in the clone, so this is a non-issue — just make sure F does not drop it when trimming filters. | Preserve `filters.status` block verbatim from `activity_stream.yml:43-56`. |
| 3 | warn | HelpText step (Plan step 4) | scope discipline | Plan already handles this correctly ("if catalog exists, append; if not, defer to SD-6 #133"). No action needed; noting so U/S don't retry-block on a missing HelpText entry. | None. |

## Evaluation against the 7 questions

1. **Reuse map soundness — CLONE is correct.** Extending `activity_stream.yml` with a new display would (a) collide with sibling #110's file ownership contract for parallel stories, (b) force one YAML to carry three different access models (perm/role/role), and (c) make future per-view CSS-class and route ownership non-orthogonal. Adding a `page_following` display to `do_streams_demo.yml` would conflate demo/proof surfaces (paths like `/do-streams/demo/following`) with the user-facing route `/following`. The demo view is explicitly a proof harness — its display already exists at `views.view.do_streams_demo.yml:123-152` and is what F should reference for filter wiring, not extend. Clone is the right call. Agree with survey.

2. **Access model — Drupal-idiomatic.** `access: type: role, options.role.authenticated: authenticated` yields 403 for anonymous and is a Views-native access plugin; no route access requirement or custom check needed. The divergence from `activity_stream`'s `perm: access content` does not create sibling drift — the two views are independent objects and are allowed to differ on access. This is consistent with the epic's per-view access model.

3. **Group-access enforcement — sufficient.** `FollowingScope::query()` at line 95 calls `$this->query->addWhereExpression()` on the existing Views query object; it does NOT set `disable_sql_rewrite` and does NOT bypass the base-table query. Because the view keeps `query.options.disable_sql_rewrite: false` (default), Drupal's node access + `group` module grants alter the base `node_field_data` query as usual. The EXISTS subqueries are filters ON the base node rows; they cannot smuggle in nodes that the base query already stripped. The kernel negative-case test is still worth having (as the plan requires) as a regression net, but the design is correct. No block.

4. **Dedupe — verified.** FollowingScope.php:29-33 comments and the code at lines 69-93 confirm: three EXISTS branches OR'd in a single `addWhereExpression`, no LEFT JOINs. Each contributes at most one row per node regardless of how many follow-branches match. `distinct: true` is defense-in-depth against ranking-join fan-out (per FollowingScope's own [B-6] reference), but scope itself is fan-out-free. Plan's assertion holds.

5. **Seed additions — no conflict with sibling #110.** Sibling #110's brief hasn't landed yet in `_worktrees/groups-stream-110`, so file-content conflict cannot be predicted precisely, but the append-only contract is documented in both stories' briefs (this brief line 8) and #111's three additions are localized (a flag() call per line, wrapped in try/catch). Merge conflict will resolve trivially as long as both agents strictly append. No block. Note: F should append after the current EOF of the follow block (after line 395), not interleave with `follow_content` / `follow_term` / `follow_user` sections, so that a diff review shows "N lines appended" cleanly.

6. **Forward-compat — leaf route, clean.** `/following` is a terminal view. Future shell-tab wiring links to it; no consumer extends the YAML. `css_class: following-feed` gives future stories a stable hook. No shape changes needed for forward compat.

7. **Anti-patterns — none.** No layer violation (config artifact + plugin already-shipped; no PHP business logic added). No duplication in the pejorative sense — clone is deliberate and file-scoped, and the shared logic (scope filter, `stream_card` view mode, empty-state markup convention) is reused via reference, not copied. No hidden coupling: the view depends on `do_streams_following_scope` (declared as filter plugin) — F should verify the `dependencies:` block in the new YAML includes `do_streams` as `enforced.module` or `module`, matching how `do_streams_demo.yml` declares its dependency on the plugin's owner module.

## Notes for O

None. PASS proceeds directly to T (RED).

## Patterns referenced

- `docs/groups/config/views.view.activity_stream.yml` (clone source, lines 1-148)
- `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml` lines 120-152 (filter-wiring pattern)
- `docs/groups/modules/do_streams/src/Plugin/views/filter/FollowingScope.php` lines 59-110 (query contract, EXISTS branches)
- `docs/groups/scripts/step_700_demo_data.php` lines 368-395 (seed-append pattern)

VERDICT: PASS
