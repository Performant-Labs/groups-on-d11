# Brief — #113 ST-4 Trending surface (/trending)

**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st4-trending-113`
**Branch:** `113-trending` (tracks `origin/main`, base `01f49a51`)
**Issue:** `gh issue view 113 --repo Performant-Labs/groups-on-d11`
**Pipeline:** POC lean (O → D → D-gate auto → A → T(RED) → F → T(GREEN) → U → S → rebase-CI → PR → self-merge). Review rigor: **none**.
**DDEV project name (if spun up):** `gm113-trending`.

## Objective (one sentence)
Ship a public **`/trending`** view — nodes ranked by `do_discovery_hot_score` (score DESC, created DESC tiebreak), `stream_card` cards, empty-state `"Nothing trending yet."` — plus wire `drush cron` into both the deployed image entrypoint and the CI e2e job so the seeded site has real scores.

## Survey summary
`survey.md` — full detail. Highlights:
- All infrastructure (do_discovery cron/scoring, do_streams shell copy, do_chrome HelpText mapping for `view.trending.page_1`) is ALREADY on main. Story creates the missing view + CSS + spec, and adds cron trigger blocks.
- Reuse map: clone `views.view.following_feed.yml` shape, copy sort block from `views.view.hot_content.yml`, clone CSS scoping pattern from `css/following.css`, clone e2e login/setup pattern from `tests/e2e/following.spec.ts` (though `/trending` is public — no login for viewing).
- Two demo threads carry 2 comments each → hot score 6.0, will outrank 0-comment nodes after cron. Demo credibility satisfied.
- Sibling collision (#116) on `deploy/entrypoint.sh` + `.github/workflows/test.yml`: distinct BEGIN/END markers make blocks independent; first to merge wins, second rebases append.

## Plan (author-visible steps)

### Step 1 — Create the view
**File:** `docs/groups/config/views.view.trending.yml` (NEW)
- Base: clone `views.view.following_feed.yml` and apply deltas:
  - `id: trending`; `label: 'Trending'`; `dependencies.module`: `[comment, do_discovery, node, user]` (drop `do_streams` — this view does NOT use the shell nor either scope filter).
  - `description: "Popular this week — nodes ranked by hot score."`.
  - `access.type: none` (public — no `role`).
  - Remove the `do_streams_following_scope` filter entirely.
  - Replace `sorts:` with `hot_content.yml`'s two sorts (`score` from `do_discovery_hot_score` DESC, then `created` DESC).
  - `empty.area_text_custom.content`: `<div class="gc-empty"><p class="gc-empty__title">Nothing trending yet.</p></div>` (issue-body copy, verbatim).
  - `css_class: trending-page`.
  - Keep: `use_ajax: true`, `distinct: true`, `row.type: 'entity:node'` with `view_mode: stream_card`, `style.row_class: stream-card-wrapper`, pager `full` items_per_page 10, exposed content-type filter.
  - `page_1.display_options.path: trending`.
  - `page_1.display_options.menu`: none (nav ownership out of scope).
- Confirm the dependency on `do_discovery` triggers hot_score views_data registration (already exposed in `DoDiscoveryHooks::viewsData()`).

### Step 2 — Scoped CSS
**File:** `docs/groups/modules/do_streams/css/trending.css` (NEW)
- Mirror the `following.css` pattern: empty-state spacing only, scoped under `.trending-page`. NO shared stream style edits.
- Content:
  ```css
  .trending-page { margin-top: 1rem; }
  .trending-page .gc-empty { padding: 2rem 1rem; text-align: center; }
  ```

### Step 3 — Register the library
**File:** `docs/groups/modules/do_streams/do_streams.libraries.yml` (EDIT — append)
- Add a `trending:` block sibling to the existing `following:` block, same 1.x version + theme layer, referencing `css/trending.css`.
- Library attach: because the view attaches libraries only when explicitly declared in `display_options.css_class` (Drupal views does NOT auto-attach), attach via a preprocess or via the view's `header/footer` — SIMPLER: use `#[Hook('views_pre_render')]` in `DoStreamsHooks` to attach `do_streams/trending` when `$view->id() === 'trending'`. Author F may prefer to attach via a hook_preprocess_views_view; use whichever is more idiomatic in the existing codebase — the goal is the library attaches on `/trending` and nowhere else.

### Step 4 — Cron trigger — deployed image
**File:** `deploy/entrypoint.sh` (EDIT — append marker block)
- Inside the fresh-install branch (`else` clause), AFTER the `$DRUSH cr` line and BEFORE the closing `fi`, add:
  ```sh
  # --- do_discovery cron BEGIN ---
  # #113 ST-4: recompute do_discovery_hot_score so /trending is non-empty
  # after the fresh install + seed. hook_cron in DoDiscoveryHooks recomputes
  # scores for nodes changed in the last 7 days; the seeded demo dataset
  # sits entirely within that window. Idempotent.
  $DRUSH cron || echo "[entrypoint] WARNING: drush cron returned non-zero (continuing)"
  # --- do_discovery cron END ---
  ```
- Existing-database branch stays untouched (cron runs on schedule there anyway).

### Step 5 — Cron trigger — CI e2e
**File:** `.github/workflows/test.yml` (EDIT — append marker block)
- Locate the "Seed full demo data..." step in the `e2e` job.
- AFTER the `# --- do_activity step_7xx END ---` block and BEFORE the trailing `php vendor/drush/drush/drush.php cache:rebuild -y` line, add:
  ```yaml
          # --- do_discovery cron BEGIN ---
          # #113 ST-4: run cron so do_discovery_hot_score is populated for
          # /trending's e2e spec (hook_cron recomputes for nodes changed in
          # the last 7 days). Runs AFTER seeds so the two 2-comment threads
          # score 6.0 and outrank the 0-comment ones.
          php vendor/drush/drush/drush.php cron
          # --- do_discovery cron END ---
  ```
- **Do NOT edit any other line** of test.yml (per project rules: workflow file is settled).

### Step 6 — E2E spec
**File:** `tests/e2e/trending.spec.ts` (NEW)
- Public — no login required for the primary path.
- Assertions:
  1. Anonymous `GET /trending` → 200; page has an `<h1>` matching `/Trending/i` (or the view label rendered as the view's `title`).
  2. At least one card with `.stream-card-wrapper` class OR `role=article` renders.
  3. Card list contains `"Venue Logistics Thread"` AND `"Patch Review Process RFC"` — both must appear in the FIRST 10 rendered card titles (score 6.0 → top of the list). Use `page.getByRole('link', { name: 'Venue Logistics Thread', exact: true })`.
  4. `/hot` still responds 200 with the label "Hot Content" (regression guard: hot_content.yml unmodified).
  5. WCAG-adjacent: exactly one `<h1>`; the pager's Next link has an accessible name if present.
- Clone the "login helper + SEEDED_PASSWORD" preamble from `tests/e2e/following.spec.ts` only if needed; primary path uses no login.

### Step 7 — HelpText verification (no edit)
- `docs/groups/modules/do_chrome/src/Hook/PageHelp.php:79` and `HelpText.php:231` already carry `view.trending.page_1 → page.trending`. No edit; T verifies mapping still resolves.

## Acceptance criteria (checklist for S)

- [ ] `/trending` renders 200 for anonymous with `stream_card` cards.
- [ ] Post-seed + cron: "Venue Logistics Thread" and "Patch Review Process RFC" appear in the first 10 cards.
- [ ] Empty state renders "Nothing trending yet." when no rows.
- [ ] `docs/groups/config/views.view.hot_content.yml` unchanged (`git diff` shows no delta).
- [ ] `tests/e2e/trending.spec.ts` green in CI; other e2e specs still green.
- [ ] `do_chrome`'s HelpText mapping for `view.trending.page_1` resolves (verified by existing `PageHelpRouteMapTest`).
- [ ] Both cron triggers landed (BEGIN/END markers present in `deploy/entrypoint.sh` AND `.github/workflows/test.yml`).
- [ ] WCAG 2.2 AA minimums: exactly one H1; keyboard-focusable pager (if present); no color-only status conveyed.
- [ ] Rebase onto latest `origin/main` clean (or with only trivial marker-block conflict vs #116); CI green on rebased head.

## Owned files
Create: `docs/groups/config/views.view.trending.yml`, `docs/groups/modules/do_streams/css/trending.css`, `tests/e2e/trending.spec.ts`.
Edit (append-only marker blocks / new keys): `docs/groups/modules/do_streams/do_streams.libraries.yml`, `deploy/entrypoint.sh`, `.github/workflows/test.yml`.
Untouched (regression guard): `docs/groups/config/views.view.hot_content.yml`, `docs/groups/modules/do_chrome/**`.

## Explicit non-scope
- No changes to `/hot` or `views.view.hot_content.yml` (ST-6 decides that).
- No changes to the do_streams shell (`DoStreamsHooks.php`) — the trending view is a plain views page, not a shell consumer.
- No nav-menu entry (out of scope; nav ownership sits elsewhere).
- No new module, service, plugin, or PHP hook (aside from the OPTIONAL 1-line `views_pre_render` library attach; F may choose the simplest wiring).

## Assumptions surfaced (to be validated during pipeline)
- The shell's empty-copy string mismatch (`"Nothing is trending right now. Check back soon."` vs issue's `"Nothing trending yet."`) is acceptable because `/trending` is NOT a shell consumer — its empty area is rendered by the view directly. If A rejects this rationale, options: (a) update shell empty-copy for `trending` too (widens blast radius) or (b) restate the copy in issue terms in the view only. Default recommendation stands.
- The Playwright spec against the CI-served site can rely on the seed + newly-added cron step to have populated scores before the browser hits `/trending`.
