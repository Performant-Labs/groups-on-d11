# Survey — #113 ST-4 Trending surface (/trending)

## Story restated
Add a **Trending** view at **`/trending`**: ranked by `do_discovery_hot_score` (LEFT JOIN, score DESC, created DESC tiebreak), using `stream_card` view mode, empty-state "Nothing trending yet.", public access (no login required). Coordinated: append `drush cron` trigger to BOTH `deploy/entrypoint.sh` and `.github/workflows/test.yml` so scores exist on both surfaces. Add one Playwright spec. Do NOT touch `views.view.hot_content.yml`.

## Infrastructure already on main (verified)

### do_discovery (module)
- `do_discovery_hot_score` schema (`nid` PK, `score` float, `computed` int) — `do_discovery.install` line 27+.
- `#[Hook('cron')]` recomputes score = `(comment_count × 3) + (view_count × 0.5)` for nodes changed in last 7 days — `DoDiscoveryHooks.php:29`.
- `#[Hook('views_data')]` exposes the table to Views with `join.node_field_data` and `score` field/filter/sort — `DoDiscoveryHooks.php:71`.
- `#[Hook('node_insert')]` seeds score=0 for new published nodes → new nodes appear even before cron runs.

### do_streams (shell)
- Scope registry (`preprocessDoStreamsShell`) already includes `'trending' => Trending`, tab URL param `?scope=trending`, empty-copy `"Nothing is trending right now. Check back soon."` — `DoStreamsHooks.php:463,499`. **Note discrepancy** (see §Conflicts).
- Ranking control includes `'hot' => Hot` (no `disabled` key) — `DoStreamsHooks.php:483`.
- `#[Hook('views_query_alter')]` `hot` branch adds LEFT JOIN + `COALESCE(do_streams_hot_score.score, 0) DESC` — `DoStreamsHooks.php:138-163`.

### do_chrome (HelpText)
- `page.trending` HelpText string EXISTS: `"Posts drawing the most engagement across the site right now."` — `HelpText.php:231`.
- Route mapping `'view.trending.page_1' => 'page.trending'` EXISTS — `PageHelp.php:79`.
- Unit tests already assert both — `HelpTextPageKeysTest.php:61,98`; kernel `PageHelpRouteMapTest.php:52`.
- **Implication:** HelpText criterion is satisfied by the mere existence of `view.trending.page_1` as a route. No help edits required.

### Analogous views (choose one to clone)
- **`views.view.following_feed.yml`** — canonical stream card view: `use_ajax: true`, `distinct: true`, `stream_card` row_class + view_mode, `css_class`, empty area_text_custom. Access: `role: authenticated`. **Best clone base.**
- **`views.view.hot_content.yml`** — has `do_discovery_hot_score.score DESC` sort + `created DESC` tiebreak. Copy those two `sorts:` entries.

### Existing seed data (verified in step_700_demo_data.php)
- Line 141: "Venue Logistics Thread" (drupalcon, logistics tags).
- Line 143: "Patch Review Process RFC" (core, process, roadmap tags).
- Lines 216-218: each thread receives 2 comments. Post-cron hot score = 2 × 3 = 6.0 (view_count 0). Other nodes have 0 comments → score 0.0 seeded via `node_insert`. Ordering credibility: **satisfied**.

### CI + deployed cron status
- `grep 'drush cron|hot_score' deploy/entrypoint.sh .github/workflows/test.yml` → **no matches**. Neither pipeline runs cron. New: append `$DRUSH cron` in entrypoint after `$DRUSH cr` (last step in fresh-install branch), append `php vendor/drush/drush/drush.php cron` in test.yml after the last step_7xx and before `cache:rebuild`. Both idempotent.

## Files to create (all `docs/groups/…`)

1. `docs/groups/config/views.view.trending.yml` — NEW.
2. `docs/groups/modules/do_streams/css/trending.css` — NEW.
3. `tests/e2e/trending.spec.ts` — NEW.

## Files to edit (append-only, marker blocks)

4. `docs/groups/modules/do_streams/do_streams.libraries.yml` — add `trending:` block sibling to `following:`.
5. `deploy/entrypoint.sh` — append `# --- do_discovery cron BEGIN --- / END ---` marker block calling `$DRUSH cron` inside the fresh-install branch (after `$DRUSH cr`).
6. `.github/workflows/test.yml` — append same marker block calling `php vendor/drush/drush/drush.php cron` after the do_activity backfill block and before the `cache:rebuild`.

## Conflicts / drift to raise

- The shell (`DoStreamsHooks.php:499`) uses the empty-copy string `"Nothing is trending right now. Check back soon."`, but the issue body specifies `"Nothing trending yet."`. **These are different strings.** Ownership: the view's own `empty:` area renders when the view has zero rows (public / new-database case). The shell's `empty_copy` renders inside `do_streams_shell` theme — which the trending view is NOT built around (this view is a plain views page, not a shell-driven route). **Recommendation:** use the ISSUE-BODY copy verbatim (`"Nothing trending yet."`) in the view's `empty:` `area_text_custom`. The shell's copy is unreachable from `/trending` because this view is not a shell consumer. Flag for A's decision; a small copy discrepancy between two surfaces of two different renderers is acceptable POC drift.
- The view is **public** (`access: type: none` or `perm: 'access content'`). The issue does not restrict it to authenticated users, and "Popular this week" is a discovery surface. Following_feed's `authenticated` role gate is following-specific (per-user follows) and does NOT apply.

## Test surfaces

- **E2E (`tests/e2e/trending.spec.ts`):** anonymous GET /trending → 200; page contains the H1/label; after seed+cron, "Venue Logistics Thread" and "Patch Review Process RFC" render among the top rows. Cross-check hot_content view untouched (fetch `/hot` still 200 with `Hot Content` label). WCAG-relevant: H1 present, focusable pager links, contrast (delegated to `#145` backstop; spec asserts H1 + role=main only).
- **No kernel test required** — no PHP code changes; config-only + CSS + workflow.

## Risk / drift budget
- **Sibling collision risk on entrypoint.sh/test.yml with #116**: distinct marker names → merge-order determines only which one appears first in each file. Auto-resolvable rebase.
- **Trending copy drift** flagged above; A to rule.
- **Base drift** during wave (8 sibling orchestrators): rebase gate at Phase 10 catches integration bugs.

## Definition of done (from issue AC + POC bar)
1. `/trending` renders (public), 200 OK, stream_card cards.
2. Post-seed + cron: two 2-comment threads outrank zero-comment nodes.
3. Empty state ("Nothing trending yet.") renders when view is empty (pre-cron or truly empty).
4. `views.view.hot_content.yml` unmodified.
5. `tests/e2e/trending.spec.ts` green; existing suite stays green.
6. HelpText entry exists (already satisfied — verify only).
7. WCAG 2.2 AA minimum (H1, focus, keyboard).
8. Both cron triggers landed (marker blocks in both files).
