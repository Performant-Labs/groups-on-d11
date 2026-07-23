# Handoff-F: Phase 5 - #111 ST-2 Following feed (`/following`)

**Date:** 2026-07-23
**Branch:** 111-stream-following
**Issue:** #111 ST-2

## What was done

- `docs/groups/config/views.view.following_feed.yml` (NEW) — clone of `views.view.activity_stream.yml` with the deltas specified in brief.md §Plan step 1: `id`/`label`/`description`, `do_streams_following_scope` filter added to `default.display_options.filters` (wired exactly as `do_streams_demo.yml`'s `page_following` display), `access.type: role`/`authenticated`, `page_1.display_options.path: following` (no `menu:` block), `sorts.created` DESC only (dropped `last_comment_timestamp`), `css_class: following-feed`, `filters.status` preserved verbatim. **Plus one correction beyond the brief's literal deltas** — see "Design decisions" below (`cache.type: none`).
- `docs/groups/modules/do_streams/css/following.css` (NEW) — small scoped `.following-feed`/`.following-feed .gc-empty` rules, per brief.
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (NEW) — registers the `following` library (`css: theme: css/following.css`), matching the module's neighbors' idiom (e.g. `do_group_pin.libraries.yml`).
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (MODIFIED, append-only) — added `FOLLOWING_FEED_VIEW_ID` constant and a new `#[Hook('preprocess_views_view')]` method (`preprocessViewsView()`) that attaches `do_streams/following` when, and only when, `$view->id() === 'following_feed'`. Matches A's preferred mechanism (handoff-A.md finding §1) and the class's existing single-hook, view-id-guarded convention (`viewsQueryAlter()`, `viewsPostRender()`).
- `docs/groups/scripts/step_700_demo_data.php` (MODIFIED, append-only, new "Step 751" block after the existing Step 750 flags) —
  - 3 brief-specified new flags: Elena→Maria (follow_user), Sophie→"Getting Started with Paragraphs" (follow_content), Elena→`drupalcon` (follow_term, on the correct-vocabulary term — see deviation below).
  - `field_group_tags` backfill (per the operator's Phase-5 fold-in instruction) on 3 nodes: "Patch Review Process RFC" and "Drupal 11 Migration Path" (both → `group_tags` vocabulary "core" term), "Venue Logistics Thread" (→ `group_tags` vocabulary "drupalcon" term). Auto-creates the two `group_tags` terms if absent (idempotent, matches the file's existing `loadByProperties`/create-if-absent idiom).
  - **One additional flag beyond the fold-in's literal instructions**: a corrective `follow_term` flag, Elena → the `group_tags`-vocabulary "core" term (not touching the pre-existing `tags`-vocabulary `$core_term` flag at all). Necessary — see "Design decisions" below.
  - No HelpText entry added — see "Design decisions" below (catalog exists but already has a pre-registered, inert entry for this surface).

## Design decisions

1. **CSS attachment mechanism (brief step 2, A's warn #1).** Chose A's preferred option: register `do_streams/following` in a new `do_streams.libraries.yml` and attach it via a single `hook_preprocess_views_view` guarded on `$view->id() === 'following_feed'`. Matches the module's existing lightweight, view-id-guarded preprocess convention exactly (no new hook type introduced).

2. **`$drupalcon_term` and the `field_group_tags` backfill's terms are looked up/created in the `group_tags` vocabulary, NOT the `tags` vocabulary the brief's literal text points at ("look up the term the same way `$core_term` is looked up").** `FollowingScope::query()`'s follow_term EXISTS branch joins `node__field_group_tags` (`field_group_tags_target_id`), and `field.field.node.*.field_group_tags.yml`'s `handler_settings.target_bundles` restricts that field to the `group_tags` vocabulary only (verified in the shipped config and the kernel test's own fixture). A `tags`-vocabulary term id can never appear in that column, so flagging one — which is what a literal reading of the brief produces — would look plausible but could never satisfy the follow_term e2e assertions regardless of implementation. Fully documented in the seed script's own comment block (Step 751).

3. **A SECOND, unplanned corrective flag was required and added: Elena → the `group_tags`-vocabulary "core" term (follow_term).** Discovered by live-running the suite, not by static reasoning. The brief characterizes Elena's "core"-tag follow as "existing follow_term, seeded" and the operator's Phase-5 fold-in explicitly named this as a case my seed work needed to make work — but the *pre-existing* follow_term seed line (`step_700_demo_data.php` ~line 380-388) flags `$core_term` from the `tags` vocabulary, which — for the exact same structural reason as point 2 — can never satisfy `FollowingScope`'s EXISTS branch. Populating `field_group_tags` on the target nodes (per the fold-in instruction) is necessary but not sufficient without also having *a* flag on the correct-vocabulary term. Verified via live view execution before/after: "Drupal 11 Migration Path" only started appearing for Elena once this second flag was appended. The pre-existing wrong-vocabulary flag is left completely untouched (append-only) — it is harmless dead seed data with respect to `FollowingScope`, not something this story rewrites.

4. **Cache plugin changed from `type: tag` (brief-cloned, matching `activity_stream.yml`) to `type: none`.** This is the one deviation from the brief's literal Plan step 1 that isn't explicitly pre-flagged in brief.md or handoff-A.md, discovered only through live, repeated Playwright execution (not static review): `FollowingScope::query()` reads `\Drupal::currentUser()->id()` to build its per-user EXISTS clauses but never declares a corresponding cache context (no `getCacheContexts()` override returning `['user']`). Views does not automatically infer a per-uid cache context from a custom filter plugin's internal use of the current user — the render array's aggregated `#cache['contexts']` for `following_feed` only ever contained role/permission-level contexts (`user.roles`, `user.permissions`, `user.node_grants:view`, etc.), none of which vary per-uid. With Drupal's standard `dynamic_page_cache` module active (present on the `standard` install profile), this let one authenticated user's rendered `/following` page get served to a *different* authenticated user — confirmed directly: elena_garcia's page intermittently rendered the empty state (another user's cache entry) instead of her own follows, reproduced across multiple full-suite runs, and resolved by setting `cache: type: none` on the view. `FollowingScope.php` is a story #109/ST-F1 artifact, explicitly out of my files-owned list for #111 and not something I edited (would be a drive-by on shared, other-story-owned code) — the fix is entirely config-scoped, inside the one file I own (`following_feed.yml`), and does not touch the plugin. Verified stable across 10 repeated Playwright runs (`--repeat-each=2` plus 2 standalone runs) with zero recurrence of cross-user leakage after the change. **Flagging this as a real, upstream (#109/FollowingScope) gap worth a follow-up ticket** — any other view built on `do_streams_following_scope` (or `do_streams_membership_scope`, which likely has the identical gap — not verified, out of scope here) would need the same `cache: type: none` workaround, or better, `FollowingScope`/`MembershipScope` should declare `getCacheContexts(): array { return ['user']; }` at the plugin level so every consuming view is safe by default without needing to know about this.

5. **Access model, sort, dedupe, group-access reliance** — implemented exactly as specified in brief.md / handoff-A.md, no further deviations. `filters.status` preserved verbatim per A's finding #2.

6. **HelpText (brief step 4).** Found the catalog (`docs/groups/modules/do_chrome/src/HelpText.php`) — it already carries a pre-registered, inert `'page.following' => '...'` entry (from already-shipped story #126), so per the brief's own instruction ("if it exists, append one entry... if it does not exist, skip"), no new entry was needed; one already exists. **Noted for O, not acted on** (do_chrome is out of my files-owned list and I must not drive-by edit it): the pre-registered entry maps route name `view.following.page_1` in `PageHelp::getRouteMap()`, but this story's view id is `following_feed` (per brief.md's explicit spec), so the actual generated route is `view.following_feed.page_1` — a mismatch means `PageHelp`'s ⓘ tooltip will never fire on `/following`. This is a #126-side bug (its route-key guess predates this story landing), not something #111's brief asked me to fix, and `PageHelpRouteMapTest.php` pins the map by exact string match (`assertSame`) — untouched by my changes (verified: it does not execute my view or depend on any route existing at all; still passes as before). Route to O as a possible follow-up ticket against do_chrome/#126.

## Reuse / extend-vs-new

Extended per the brief/survey's Reuse map: cloned `views.view.activity_stream.yml` into a NEW `views.view.following_feed.yml` (survey.md's justification: disjoint file ownership across sibling in-flight stories #110/#111/etc., and the demo view `do_streams_demo.yml` is explicitly a proof harness, not a production surface to extend). Reused as-is: `do_streams_following_scope` (`FollowingScope.php`, ST-F1/#109), `stream_card` view mode, the empty-state markup convention (`gc-empty__title`/`gc-empty__text`/`gc-button`), the seeded personas/flags already present in `step_700_demo_data.php`. No new PHP business-logic object was created — only a config artifact (view), a scoped CSS file, one library registration, and one small preprocess hook consuming an already-shipped plugin.

## Architecture notes for A

- Layers touched: Views config (new artifact), a small CSS asset + library registration (new), one `#[Hook('preprocess_views_view')]` addition to an existing hooks class (guarded, no behavior change for any other view), and append-only demo-data seed additions (no schema changes).
- No new dependencies added beyond what's already declared (`do_streams` module dependency added to `following_feed.yml`'s `dependencies.module`, matching `do_streams_demo.yml`'s own convention).
- No shared/other-agent-owned PHP was modified. `FollowingScope.php` (do_streams, #109) was read but not edited, despite the cache-context gap identified there (see Design decision #4) — the fix was kept entirely inside my own owned file (`following_feed.yml`'s `cache: type: none`), specifically to avoid a drive-by edit on shared, already-shipped code. Recommend A/O consider a follow-up ticket against `FollowingScope`/`MembershipScope` to add `getCacheContexts(): ['user']` at the plugin level (see point 4) so future consumers of these filters don't need to independently rediscover and work around the same gap.
- `do_chrome`'s `PageHelp` route-map/route-name mismatch (point 6) is also flagged as a possible follow-up, not touched.

## Deviations from spec / wireframe

No wireframe applies (leaf route, no shell wiring per brief). Deviations from the brief's literal text, both discovered through live test execution rather than static review, both documented above:
1. `views.view.following_feed.yml`'s `cache` plugin is `type: none`, not `type: tag` as the brief's plan literally specified (matching the `activity_stream.yml` clone source) — required to prevent cross-authenticated-user render-cache leakage given `FollowingScope`'s missing cache-context declaration (Design decision #4).
2. The seed script's new `follow_term` line targets a `group_tags`-vocabulary term (auto-created if absent), not a `tags`-vocabulary term as a literal reading of "look up the term the same way `$core_term` is looked up" would produce (Design decision #2) — and a second, not-originally-planned corrective `follow_term` flag on the `group_tags` "core" term was added for the same structural reason (Design decision #3).

## Tier 1 self-check (incl. tests now GREEN)

**Config assemble:** `bash scripts/ci/assemble-config.sh` — exits 0 on the host copy steps (104 config files copied, 13 modules copied); the `core.extension.yml` patch step requires `php`, run via `ddev exec bash scripts/ci/assemble-config.sh` — also exits 0 cleanly (`==> assemble-config: done`).

**PHP syntax:** `php -l` clean on both modified files:
```
No syntax errors detected in docs/groups/scripts/step_700_demo_data.php
No syntax errors detected in docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php
```

**YAML parses** (post-assemble, via Symfony YAML through the vendor autoloader):
```
OK top-level keys: uuid, langcode, status, dependencies, id, label, module, description, tag, base_table, base_field, display
id=following_feed
page_1 path=following
access={"type":"role","options":{"role":{"authenticated":"authenticated"}}}
has status filter=yes
has scope filter=yes
sort=["created"]
css_class=following-feed
```
(cache.type confirmed `none` in a later, standalone check after the correction.)

**phpcs** (`--standard=Drupal,DrupalPractice`, explicit — no project `phpcs.xml` exists so the bare `phpcs` command falls back to a non-Drupal default and is misleading):
- `DoStreamsHooks.php`: 0 errors, 4 warnings — all 4 are pre-existing `\Drupal::service()` static-call warnings at lines 125/154/186/303, none inside my additions (constant at line 46, hook method at lines 391-406).
- `step_700_demo_data.php`: 276 errors/6 warnings total vs. a **240-error/6-warning pre-existing baseline** (confirmed via `git show HEAD:...` compared against the same standard) — my append added 36 new violations, all attributable to continuing this file's own established compact single-line-brace style (used throughout the entire pre-existing file, e.g. `if ($node) { echo "..."; continue; }`), consistent with — not a regression from — the file's existing (non-Drupal-standard) convention. This runbook script has never conformed to Drupal coding standards and multiple prior merged stories (#121, #135, #140-142) have appended to it in the same style.

**Kernel tests — live DDEV run, `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php`:**
```
Tests: 2, Assertions: 78, Failures: 1, Deprecations: 21, PHPUnit Deprecations: 3.
```
- `testFollowedNodeInAccessibleGroupIsIncluded`: **PASS.**
- `testFollowedNodeInInaccessibleGroupIsExcluded`: **FAIL — test-authorship defect, not a production bug.** See "Tests that look wrong" below.

**Regression sweep — all related kernel suites, live DDEV run:**
```
StreamsScopeTest + StreamsInstallTest + StreamsRankingTest + StreamsShellTest + PinnedStreamOrderingTest
Tests: 29, Assertions: 872, Deprecations: 23, PHPUnit Deprecations: 34.
```
All 29 green (deprecations only, zero failures) — confirms no regression from the new `preprocess_views_view` hook or the seed-script append.

**Playwright — `tests/e2e/following.spec.ts`, live DDEV run** (full assembled site: `site:install standard` + `config:import` + `step_700_demo_data.php`, matching the project's CI recipe in `.github/workflows/test.yml`):
```
4 passed, 1 failed (10 total runs across 3 separate invocations, incl. --repeat-each=2 — stable, same single failure every time)
```
- `anonymous visiting /following gets 403 or a login redirect`: **PASS.**
- `elena_garcia sees all 4 following-scope branches, each exactly once`: **PASS** (after the cache-context fix — see Design decision #4).
- `ravi_patel sees Maria-authored content via the existing follow_user seed`: **PASS.**
- `sophie_mueller sees the Paragraphs tutorial via the NEW follow_content seed`: **PASS.**
- `a user with no follows sees an accessible empty state`: **FAIL — test-authorship defect, not a production bug.** See below.

**Playwright — regression sweep** (`demonstrator-seeds.spec.ts` + `phase1.spec.ts`): 12/12 pass, confirming no regression from the seed-script append or the view/hook additions. (Note: an environment-only artifact was hit and self-corrected during this sweep — `drush php:script`/`drush php:eval` ran as uid 0 in this specific verification environment, which `do_group_extras`'s pre-existing `entity_presave` hook defaults every seed-created group to unpublished for non-admin actors; fixed via a direct SQL `UPDATE groups_field_data SET status = 1` for verification purposes only — this is NOT a production code change and touches no file in this handoff's scope. Purely an artifact of how I invoked the seed script for manual verification in this session, not something that will recur under the project's documented CI recipe.)

**Git status** confirms exactly the intended file set — my production files (view YAML, CSS, libraries.yml, hooks class, seed script) plus T's already-authored test files (`following.spec.ts`, `FollowingFeedTest.php`, both correctly untouched by me and still showing as untracked pending T's own commit) — and `.ddev/config.yaml` still shows the `gm111-stream` rename staged, untouched.

## Tests that look wrong (for T)

**1. `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php::testFollowedNodeInInaccessibleGroupIsExcluded` — the test's own `setUp()` defeats its stated premise.**

The test's `setUp()` (lines 132-154) grants BOTH an outsider-scope AND an insider-scope `group_role` at the `community_group` **group-type** level (via `$this->createGroupRole([... 'scope' => PermissionScopeInterface::OUTSIDER_ID, 'global_role' => RoleInterface::AUTHENTICATED_ID, ...])`), copied verbatim from `StreamsScopeTest.php`. `createGroupRole()` (per `GroupTestTrait`) creates a policy applied to **every group of that type**, not one specific group instance. The comment claims this is "required so that a non-member's EXCLUSION is proven to come from Drupal's node-access alter consulting Group's actual per-group grants" — but `StreamsScopeTest.php` needs this grant for the OPPOSITE reason: it's proving `MembershipScope`'s OWN filter excludes non-members, so it deliberately grants blanket outsider view access first, so any exclusion it observes can only be `MembershipScope`'s doing (not node access incidentally hiding the row). `FollowingFeedTest`'s negative case is testing the OPPOSITE thing — that node-access/Group's OWN grants (not `FollowingScope`) exclude a followed node in an inaccessible group — and by granting blanket outsider access to every authenticated user on every group, it removes the very "inaccessible group" its own test name and premise require. Confirmed empirically:
- The raw follow_content EXISTS branch alone (and the full compiled view SQL) DOES return the node.
- Direct entity-level access check (`$node->access('view', $viewer, TRUE)`) returns **Allowed** for the "inaccessible" group's node, because the outsider-scope role in `setUp()` grants it.
- `testFollowedNodeInAccessibleGroupIsIncluded` (the sanity companion, same file) passes correctly — confirming my `following_feed.yml`/`FollowingScope` implementation is NOT the problem; only the negative case's fixture setup is.

**Suggested fix for T:** remove the `OUTSIDER_ID`-scope `createGroupRole()` call from `setUp()` (keep the `INSIDER_ID` one, so the accessible-group sanity companion still has a real grant for members), so a non-member genuinely has no view grant on `$inaccessibleGroup` and the negative assertion is non-vacuous. This does NOT require touching `following_feed.yml` or `FollowingScope.php` — it is purely a test-fixture correction.

**2. `tests/e2e/following.spec.ts::'a user with no follows sees an accessible empty state'` — ambiguous locator against the brief's OWN approved copy.**

Line 207's `emptyState.getByRole('link', { name: /stream/i })` matches **two** links inside the brief's exact, approved empty-state HTML (brief.md §Plan step 1, byte-for-byte reproduced in my `following_feed.yml`): the inline `<a href="/stream">stream</a>` (inside the paragraph text) AND `<a class="gc-button gc-button--primary" href="/stream">Browse the stream</a>` (the button) — both have accessible names matching `/stream/i`. Playwright throws a strict-mode violation ("resolved to 2 elements") the instant `.toBeVisible()`/`.toHaveAttribute()`/`.focus()` is called on this locator (used 4 times total in the test, lines 209/211/214/215). This is not a production bug — verified the rendered HTML matches the brief's copy exactly (`drush php:eval` dump of the view's stored `empty.area_text_custom.content` matches brief.md line 47 character-for-character), and the two links are both intentional per the approved copy.

**Suggested fix for T:** disambiguate the locator — e.g. `emptyState.getByRole('link', { name: 'stream', exact: true })` (matches only the inline link, since `exact: true` won't match "Browse the stream"), or scope to the paragraph specifically (`emptyState.locator('.gc-empty__text').getByRole('link', { name: 'stream' })`), or use `.first()` if either link satisfying the assertion is acceptable. The `/tags/i` locator on the following line is NOT ambiguous (only one `/tags` link exists) and needs no change.

## Assumptions made

- `alex_novak` as the "no follows" persona (T's choice, confirmed by grep, not something I needed to change).
- `drupalcon` and `paragraphs`/`tutorial` tags already existed in the seed's `tags` vocabulary list — confirmed, used as-is for the pre-existing `field_tags` values on the target nodes (unrelated to my `field_group_tags` backfill, which uses the SEPARATE `group_tags` vocabulary).
- The specific 3 nodes needing `field_group_tags` backfill were exactly the ones T's e2e assertions and design notes name: "Patch Review Process RFC", "Drupal 11 Migration Path" (both `core`), "Venue Logistics Thread" (`drupalcon`) — confirmed sufficient; no other node needed backfilling for the current test suite to pass.
- Assumed the operator's Phase-5 fold-in instruction implicitly authorized whatever seed-level correction was necessary to make "the existing core-tag Elena-sees-RFC case" actually work (per its own wording), which is why I added the second corrective `follow_term` flag beyond the literal 3-line list — flagged prominently in this handoff and in the seed script's own comments for full transparency, not silently folded in.

## Known issues

None beyond the two test-authorship defects documented above (both non-blocking for GREEN once T applies the suggested one-line fixes) and the two follow-up-ticket-worthy upstream gaps noted in "Architecture notes for A" (FollowingScope's missing cache context; do_chrome's PageHelp route-name mismatch) — neither blocks this story's own acceptance criteria, both are pre-existing/other-story-owned and out of my files-owned scope to fix directly.

## Files changed

- `docs/groups/config/views.view.following_feed.yml` (NEW)
- `docs/groups/modules/do_streams/css/following.css` (NEW)
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (NEW)
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (MODIFIED — append-only)
- `docs/groups/scripts/step_700_demo_data.php` (MODIFIED — append-only)

(No test files listed — `tests/e2e/following.spec.ts` and `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php` are T's, untouched by me.)

STATUS: READY_FOR_T_GREEN

## F — Phase 8 delta (drop /tags link) — 2026-07-23

**Context:** Per O's Phase-8 ADVISORY resolution (`decisions.md`, "O — Phase 8 ADVISORY resolution"), U flagged that the empty-state copy's `/tags` link 404s on this build (only `views.view.tags_aggregation.yml` exists, and it requires a term-slug argument — `path: tags/%` — not a bare `/tags` landing). Operator chose option (b): drop the `/tags` link from the empty-state copy, keep `/stream` only. No follow-up issue filed (POC posture — `/tags` landing is a future story's scope, not latent debt from this one).

**Scope:** One string, one file, no other changes. No test files touched (T's Phase-8 delta separately drops the `/tags`-link assertion from `following.spec.ts`'s empty-state test).

### Before / after

**File:** `docs/groups/config/views.view.following_feed.yml`, `display.default.display_options.empty.area_text_custom.content` (line 131).

Before:
```
<div class="gc-empty"><p class="gc-empty__title">You're not following anything yet</p><p class="gc-empty__text">Browse the <a href="/stream">stream</a> or explore <a href="/tags">tags</a> to find people, content, and topics to follow.</p><a class="gc-button gc-button--primary" href="/stream">Browse the stream</a></div>
```

After:
```
<div class="gc-empty"><p class="gc-empty__title">You're not following anything yet</p><p class="gc-empty__text">Browse the <a href="/stream">stream</a> to find people, content, and topics to follow.</p><a class="gc-button gc-button--primary" href="/stream">Browse the stream</a></div>
```

Diff: removed the clause `" or explore <a href=\"/tags\">tags</a>"` from the sentence (the `/tags` anchor and its two surrounding "or explore ... to" fragments), so the sentence now reads "Browse the stream to find people, content, and topics to follow." The `/stream` inline link and the `gc-button--primary` "Browse the stream" button are both unchanged, byte-for-byte.

Applied via a scripted exact-string replace (Python, `content.count(before) == 1` asserted before replacing) rather than a manual line edit, to guarantee no incidental whitespace/quoting drift in this YAML-embedded, single-quote-escaped HTML string (note the `You''re` YAML-escaped apostrophe on the same line — untouched).

### Self-check

- `grep -c "/tags"` on both `docs/groups/config/views.view.following_feed.yml` (source) and `config/sync/views.view.following_feed.yml` (assembled) → `0` in both. No `/tags` substring remains anywhere in this file.
- `bash scripts/ci/assemble-config.sh`, run inside the `gm111-stream` DDEV `web` container (per this project's documented constraint — `php` is not on the host PATH; the script's `core.extension.yml` patch step requires `vendor/autoload.php`/`php`): **exit 0**, `==> assemble-config: done`. Ran twice (once to observe output, once solely to capture the exit code) — both exit 0.
- Assembled copy (`config/sync/views.view.following_feed.yml`) confirmed to carry the new text verbatim (`grep -o "gc-empty__text.\{0,220\}"` output matches the "after" string above exactly).
- `git status --short`: captured a full-repo snapshot immediately before the edit and diffed it byte-for-byte against a snapshot taken immediately after (including after running `assemble-config.sh`) — **zero difference** in the file list either time. `docs/groups/config/views.view.following_feed.yml` was already untracked (`??`) from my own Phase-5 delivery (never staged/committed since), so this delta changes its *content* only, not its tracked/untracked status; every other path in the tree (config/sync/*, web/modules/custom/*, .ddev/config.yaml, etc.) is untouched, matching the pre-existing state from Phase 5/6/8. This is the practical form the task's "exactly one modified file" check takes here, since the target file has no prior committed baseline in this worktree to `git diff` against (confirmed via `git diff --no-index /dev/null <file>`, which shows the whole file as new-content, as expected for an untracked file).
- No file outside `docs/groups/config/views.view.following_feed.yml` was opened for writing. Tests, CSS, seed script, and hooks class are all unchanged from Phase 5/6.

STATUS: READY_FOR_T_GREEN_DELTA
