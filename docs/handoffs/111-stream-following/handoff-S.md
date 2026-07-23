# Handoff-S: Phase 9 — #111 ST-2 Following feed (final spec audit)

**Date:** 2026-07-23
**Branch:** 111-stream-following
**Issue:** #111 ST-2
**Handoff-A reviewed:** `docs/handoffs/111-stream-following/handoff-A.md` (PASS)
**Handoff-T-red reviewed:** `docs/handoffs/111-stream-following/handoff-T-red.md` (RED_CONFIRMED)
**Handoff-F reviewed:** `docs/handoffs/111-stream-following/handoff-F.md` (+ Phase-8 delta section)
**Handoff-T-green reviewed:** `docs/handoffs/111-stream-following/handoff-T-green.md` (GREEN_CONFIRMED + delta)
**Handoff-U reviewed:** `docs/handoffs/111-stream-following/handoff-U.md` (PASS after Phase-8 delta)
**decisions.md reviewed:** all entries through "U — Phase 8 delta — 2026-07-23"

## Preconditions

- **A precondition:** PASS (`handoff-A.md`, VERDICT: PASS; 3 warns, 0 blockers).
- **T precondition:** GREEN, zero blocking (`handoff-T-green.md` §"Blocking issues": None; delta re-verify same).
- **Visual/browser precondition:** N/A for this pass. U already did the walkthrough (Playwright artefacts under `docs/handoffs/111-stream-following/screenshots/`, `screenshots-delta/`, `playwright-output/`) and re-verified the Phase-8 delta live; the shipping surface is a small config-only view with a text empty state, no visual-regression baseline in this project. Spot-checked screenshots and rendered-HTML dumps in U's handoff.

## Per-acceptance-criterion audit

| # | Acceptance criterion (issue #111 + brief.md) | Implemented at | Test file : test name | T(GREEN) result | U observed | Notes |
|---|---|---|---|---|---|---|
| 1 | Renders `stream_card` cards; recent-ranked; deduped | `views.view.following_feed.yml` line 98-102 (`row.type: entity:node`, `view_mode: stream_card`), line 33-44 (`sorts.created DESC`), line 106-108 (`query.options.distinct: true`); `FollowingScope.php` (already-shipped, ST-F1) provides EXISTS-based OR combine, no LEFT JOIN fan-out | `tests/e2e/following.spec.ts::"elena_garcia sees all 4 following-scope branches, each exactly once"` — asserts each of 4 nodes exactly-once via `countCardsWithTitle` | PASS (5 e2e green ×3 invocations = 20/20) | scenario 2 (screenshots/02-elena-following.png): `.stream-card-wrapper` markup present; Patch Review Process RFC renders 1 card despite matching two scope branches | Dedupe verified live |
| 2 | Authenticated-only (anonymous → 403 or login) | `views.view.following_feed.yml` line 113-117 (`access.type: role`, `authenticated: authenticated`) | `tests/e2e/following.spec.ts::"anonymous visiting /following gets 403 or a login redirect"` | PASS | scenario 1 (screenshots/01-anonymous.png): well-formed HTTP 403 Access denied page, no WSOD | `curl` re-check in U's Phase-8 delta re-verify: 403 |
| 3 | `follow_content`, `follow_user`, `follow_term` OR-combined via `do_streams_following_scope` filter | `views.view.following_feed.yml` line 79-87 (scope filter wired identically to `do_streams_demo.yml:page_following`); `filter_groups.operator: AND` (line 88-91) — scope is one filter internally OR'ing 3 EXISTS branches | Same "elena_garcia sees all 4 branches" test proves the 4 nodes from 3 branches all render (implicitly proves OR-combine); kernel `FollowingFeedTest::testFollowedNodeInAccessibleGroupIsIncluded` proves follow_content branch specifically works via the view | PASS (kernel 2/2; e2e 5/5) | scenario 2: all 4 seeded branch-matches visible | A finding §3 verified via `FollowingScope::query()` `addWhereExpression()` on base table |
| 4 | Group-access enforced (viewer sees nothing from inaccessible groups) | Inherited: `query.options.disable_sql_rewrite: false` (line 106) + Group module's node-access grants; not disabled by `FollowingScope` | Kernel `FollowingFeedTest::testFollowedNodeInInaccessibleGroupIsExcluded` (setUp() intentionally omits `OUTSIDER_ID` scope grant per T-green fix so non-membership genuinely blocks access); companion `testFollowedNodeInAccessibleGroupIsIncluded` proves the negative is non-vacuous | PASS (kernel 2/2 = 78 assertions, 0 failures) | Kernel-tier by brief's own preference ("kernel test preferred; e2e acceptable if fixture-able") | T-green defensive doc-comment added to prevent future copy-paste of `StreamsScopeTest`'s opposite pattern |
| 5 | Demo-well: each seeded persona has non-empty Following tab; Elena's shows RFC and `core`-tagged content exactly once each | `step_700_demo_data.php` Step 751 (line 397-538): 3 new flags (elena→maria, sophie→paragraphs-tutorial, elena→drupalcon-group_tags-term), `field_group_tags` backfill on 3 nodes (RFC, "Drupal 11 Migration Path", "Venue Logistics Thread"), + corrective elena→core-group_tags-term flag | Persona tests: `elena_garcia sees all 4 branches` (incl. RFC once), `ravi_patel sees Maria-authored` (existing seed), `sophie_mueller sees Paragraphs tutorial` (new seed) | PASS (3 persona e2e tests all green ×3 invocations) | scenarios 2, 3, 4 (screenshots/02, 03, 04): all personas have non-empty feeds; Elena's RFC + core-tagged "Drupal 11 Migration Path" both render exactly once | Two documented deviations (see "F deviations audit" below) — both hold up under scrutiny |
| 6 | Empty state renders, prompts follows, links to `/stream` (`/tags` dropped per Phase-8 ADVISORY resolution) | `views.view.following_feed.yml` line 121-131 (`empty.area_text_custom.content`, post-delta: `/stream` inline link + "Browse the stream" button only, no `/tags`) | `tests/e2e/following.spec.ts::"a user with no follows sees an accessible empty state"` (post Phase-8 delta: asserts `/stream` inline link via `exact: true`, its href, focusability; no `/tags` assertion) | PASS (post fix + post delta, all 3 runs) | scenario 5 delta (screenshots-delta/05-empty-state-delta.png): heading + 2 `/stream` CTAs, no `/tags` link, both focusable with visible outline | ADVISORY resolution documented in `decisions.md`; PR body will note "/tags landing route deferred; empty-state links to /stream only" per O's Phase-8 instruction |
| 7 | WCAG 2.2 AA: h1, labels, keyboard, visible focus, AA contrast, non-color status | Empty-state markup (`.gc-empty__title` renders as `<p>` inside a Views heading region; Views own `<h1>` from view title "Following Feed"); button/link labels present in copy; focus is browser-default `outline: solid 2px` (verified live by U) | `following.spec.ts` empty-state test: `.toBeVisible()`, `.toHaveAttribute('href', /\/stream/)`, `.focus() → .toBeFocused()` on `/stream` inline link | PASS | U's WCAG spot-checks table: single meaningful `<h1>` (both populated and empty pages), all links have accessible names, focus visible on interactive elements, no color-only status, empty-state text/button contrast sufficient | Late-wave AA sweep #145 remains the backstop per epic |
| 8 | Playwright rendered-DOM spec exists; existing suite stays green | `tests/e2e/following.spec.ts` (5 tests) | The file itself | PASS. Regression sweep (T-green Tier 2): `directory-cards`, `demonstrator-seeds`, `group-links`, `phase1` = 13/13 GREEN | scenarios 6-7 regression cross-checks: `/stream` 200 with `<h1>` (no visual regression); `/my-feed` 404 (#110 unmerged, no leak) | Also all 25/25 do_streams kernel tests green, all 6/6 do_group_pin kernel tests green |
| 9 | HelpText entry (append-only) for new user-facing surface | `PageHelp` (do_chrome, from #126) already pre-registers `'page.following' => '...'` entry — no new append needed per brief.md's "if catalog exists, append; else defer to SD-6 #133" instruction | N/A (no test targets the tooltip surface for this story) | N/A | Not surfaced in U | See "Latent debt surfaced" §1 below — pre-existing #126 route-map mismatch means tooltip won't fire; not #111's job to fix |

**All 9 criteria: PASS.**

## Anti-duplication (light) audit

F cloned `activity_stream.yml`. This is the right call, ratified by A finding §1 and the epic's parallel-story disjoint-file contract:

- Extending `activity_stream.yml` with a new display would collide with sibling #110's file ownership.
- Extending `do_streams_demo.yml` would conflate proof-harness paths (`/do-streams/demo/following`) with a production surface (`/following`).
- The clone shares no PHP business logic — the reused primitive (`FollowingScope` filter, `stream_card` view mode, empty-state markup convention) is referenced, not copied.
- Nothing in the clone should have been extracted into a shared abstraction — a view YAML is intrinsically per-view; the truly shared filter plugin is already shared.

Verdict: clone is idiomatic Drupal Views hygiene here, not duplication in the pejorative sense.

## Disjoint-file contract with sibling #110

- `step_700_demo_data.php`: F's append is a self-contained block delimited `// ===== Step 751:` (line 397) between the pre-existing Step 750 (line 340) and Step 780 (line 564). `git diff HEAD` on this file shows ONLY appended lines starting at line 395; no pre-existing lines rewritten. Strict-append preserved.
- Step ordering respected: 751 sits between 750 (existing flags) and 780 — sibling #110 (ST-1 my_feed), if/when it appends its own block, will get either 752 or another slot after 751 (or before if it lands first), with the same append-only mechanism. No file-line collision surface.
- `following_feed.yml` is a new file — orthogonal to sibling #110's `my_feed.yml` (verified: sibling worktree not yet in flight; `/my-feed` returns 404 in U's scenario 7 as expected).

## F deviations from brief — audit

Both deviations are documented in `handoff-F.md` §"Design decisions" AND ratified in `decisions.md` under "O — Phase 6 handoff to T(GREEN)". Both hold up under scrutiny:

**1. `cache.type: none` (vs. brief's cloned `type: tag`).** Necessary because `FollowingScope::query()` reads `\Drupal::currentUser()->id()` but never declares a `user` cache context. Without this override, `dynamic_page_cache` served one authenticated user's rendered `/following` to a different authenticated user (F reproduced live across multiple runs; verified stable across 10 repeated runs post-fix). F's fix stays entirely inside the one file F owns (`following_feed.yml`), avoiding a drive-by on shared ST-F1 code. Correct engineering call; correct scope discipline. Route to O as a follow-up-ticket candidate on `FollowingScope`/`MembershipScope` (see "Latent debt surfaced" §2).

**2. `group_tags` vocabulary (not `tags`) for the new `follow_term` seeds, PLUS a corrective `elena → core (group_tags)` flag.** Necessary because `FollowingScope`'s follow_term EXISTS branch joins `node__field_group_tags`, whose target_bundles is restricted to the `group_tags` vocabulary only. The brief's literal wording ("look up the term the same way $core_term is looked up") pointed at the `tags` vocabulary, which the branch structurally cannot ever see. Discovered by F via live view execution, not static reasoning. The pre-existing wrong-vocabulary `$core_term` flag is left untouched (append-only discipline preserved) — it's inert dead seed data w.r.t. `FollowingScope`. The corrective second flag is what makes Elena's already-seeded core-tag acceptance case actually satisfiable end-to-end. Confirmed by U's scenario 2 screenshot: "Drupal 11 Migration Path" (`core`-tagged) renders for Elena as required.

**Both deviations are surface-preserving and improvement-only** (nothing regressed; two previously non-functional acceptance criteria became functional). Fully documented in the seed script's own comment block (Step 751, lines 398-437) so a future reader sees the reasoning at the site of the code.

## CSS attachment mechanism (A's warn #1)

F chose A's preferred option: registered `do_streams/following` in a NEW `do_streams.libraries.yml` (module had none prior — first-time creation is idiomatic), attached via a single `#[Hook('preprocess_views_view')]` method on `DoStreamsHooks` (guarded on `$view->id() === 'following_feed'`). Matches the module's existing view-id-guarded preprocess convention (`preprocessDoStreamsShell()` sits alongside). Verified attachment works via U's scenario 2 screenshot (empty-state padding + centered text visible = `.gc-empty` scoped CSS applied under `.following-feed`).

## `filters.status` preserved verbatim (A's warn #2)

Byte-for-byte `diff` of the `filters.status` block between `views.view.activity_stream.yml` and `views.view.following_feed.yml`: empty output. `status: value: '1'` preserved — guards against authenticated users with `view own unpublished content` seeing followed unpublished nodes.

## Test-quality rubric (§7)

Reviewed both test files against `testing/test-quality.md` §7 rubric:

| Test | Behavior named? | Fails in isolation for right reason? | Cheapest sufficient tier? | Asserts behavior not implementation? |
|---|---|---|---|---|
| e2e: anonymous 403/redirect | Yes (route-level access) | Yes (T-red: `status !== 403 && no redirect` when route absent) | Yes (route access only observable end-to-end) | Yes (HTTP status / redirect) |
| e2e: elena 4-branch dedupe | Yes (union + dedupe) | Yes (T-red: login fails on unseeded site; post-fix, catches removal of any branch) | Yes (only e2e exercises real rendered dedupe across all branches) | Yes (rendered card count per title) |
| e2e: ravi follow_user | Yes | Yes | Yes | Yes |
| e2e: sophie follow_content | Yes | Yes | Yes | Yes |
| e2e: empty state | Yes (empty-state markup + focus semantics) | Yes (T-green post-fix: `exact: true` disambiguates without hiding true bugs) | Yes (focus behavior needs rendered DOM) | Yes (link name, href, focusability) |
| kernel: inaccessible group excluded | Yes (group-access negative) | Yes (T-green post-fix: removed `OUTSIDER_ID` grant made the assertion non-vacuous; verified via F's diagnostic that access flip changes result) | Yes (kernel-preferred per brief for this criterion) | Yes (`$view->result` nid presence) |
| kernel: accessible group included | Yes (sanity companion, required so exclusion is non-vacuous) | Yes | Yes | Yes |

Suite proportionality: 5 e2e + 2 kernel for a story with 9 acceptance criteria and 3 discovered edge cases (dedupe, group-access, cache-context). No 1:1 duplication across tiers (e2e proves rendering; kernel proves query/access-layer). No test smells (no tautology, no snapshot-everything, no mock-shaped, no unreachable-outcome). No "delete or merge" findings.

## Regression sanity

| Check | Command | Result |
|---|---|---|
| Config assemble (host copy steps) | `bash scripts/ci/assemble-config.sh` on host | Exits non-zero on host (`php not found`) — expected/documented project constraint (php not on host PATH). Host copy steps succeeded: `104 file(s), excluded 7`, `13 custom module(s)`. F verified full run via `ddev exec bash scripts/ci/assemble-config.sh` = exit 0 (`==> assemble-config: done`). Also verified by T-red, T-green, and T-green delta. Not a regression. |
| `git status --short` scope | (see raw output in Bash tool history) | Intended production files present: `docs/groups/config/views.view.following_feed.yml` (??), `docs/groups/modules/do_streams/css/` (??), `docs/groups/modules/do_streams/do_streams.libraries.yml` (??), `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (M), `docs/groups/scripts/step_700_demo_data.php` (M). Test files: `tests/e2e/following.spec.ts` (??), `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php` (??). `.ddev/config.yaml` (M) = intended `gm111-stream` rename only (diff shows only `name:` line). `config/sync/*` and `web/modules/custom/*` entries are build artifacts from `assemble-config.sh` — expected, per project override. Untracked handoff dir under `docs/handoffs/111-stream-following/` — expected. |
| `git diff --stat origin/main...HEAD` | Empty output (no commits ahead of origin/main) | Expected — S was instructed not to commit; F/T/U all confirmed they made no commits either. All changes remain uncommitted in the worktree, ready for O to stage/commit before PR. |
| `.ddev/config.yaml` verified | `git diff HEAD -- .ddev/config.yaml` | Only the `name: pl-groups-on-d11` → `name: gm111-stream` line differs. No other config touched. |
| Empty-state copy delta reached assembled config | `grep -c "/tags"` on both source and `config/sync/` copy of `views.view.following_feed.yml` | Both `0`; both `/stream` links present (inline + button). Phase-8 delta fully propagated. |

## Latent debt surfaced (route to O for follow-up decisions — do NOT file GH issues per POC memory)

Per `feedback_poc_no_follow_ups.md` memory: **surface once, drop it**. Recording here for O's awareness only; do not expand into follow-up tickets.

1. **do_chrome `PageHelp` route-name mismatch (from #126).** Pre-registered `page.following` entry maps route `view.following.page_1` in `PageHelp::getRouteMap()`, but this story's actual generated route is `view.following_feed.page_1` (view id is `following_feed`). Tooltip won't fire on `/following`. Pre-existing #126 bug, not #111's to fix; F correctly did not drive-by.
2. **`FollowingScope`/`MembershipScope` missing `getCacheContexts(): ['user']` (from #109 ST-F1).** Any consumer view built on these plugins needs the `cache: type: none` workaround F applied here, or the same cross-authenticated-user render-cache leak will recur. Adding the override at the plugin level would let consumers safely use `type: tag` again. Pre-existing #109 gap, not #111's to fix.

## Verdict

**VERDICT: PASS.**

All 9 acceptance criteria map to a live-verified test AND (where UI-observable) to U's live walkthrough. Both of F's brief-deviations are documented, ratified by O in Phase 6, and correct engineering calls (surface-preserving improvements to make previously non-satisfiable criteria satisfiable). T-green's two test-fixture repairs are correct (kernel setUp() over-grant, e2e locator ambiguity). Phase-8 ADVISORY resolution (drop `/tags` link) is fully applied to production config, tests, and U's live re-verify. Disjoint-file contract with sibling #110 preserved (strict-append only). `.ddev/config.yaml` intact (only intended rename). Regression sweep clean (all sibling kernel + e2e suites green). Ready for O to rebase (if needed), commit, push, and open PR.

**PR body one-liner** (per O's Phase-8 instruction): "/tags landing route deferred; empty-state copy now links to /stream only."
