# Handoff-S: Phase 9 — do_streams scaffold (issue #109)  (spec audit / final quality gate)

# VERDICT: PASS

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold (worktree `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams`)
**Issue:** #109  (epic #108)
**Diff base:** c18f417 → HEAD 1b1928a
**Handoff-T reviewed:** handoff-T-green.md + handoff-T-green-rework.md (+ handoff-T-red.md)
**Handoff-A reviewed:** handoff-A.md + handoff-A-dup.md
**Handoff-F reviewed:** handoff-F.md + handoff-F-rework.md
**Handoff-U reviewed:** handoff-U.md (CANNOT-WALK → N/A, see UI section)
**Operator-facing report:** N/A — non-visual story (inert scaffold; no live rendered UI surface in this diff)

One-line rationale: every acceptance criterion is met in code AND pinned by a behavior-asserting,
break-and-restore-verified test; all precise specs ([B-1/3/4/8/9], [W-2], [A-W1/2/3]) conform to
the code I read directly; POC scope held; both A gates PASS and T reported zero blockers.

## A precondition
Confirmed: A returned **PASS** at Phase 3 (up-front plan review, handoff-A.md) and **PASS** at
Phase 7 (anti-duplication, handoff-A-dup.md). No BLOCK. Proceed.

## T precondition
Confirmed: T reported **zero blocking issues** — 23/23 GREEN (handoff-T-green.md), and the
url_or_param covering-assertion repair landed 23/23 GREEN with 8 new load-bearing assertions
(handoff-T-green-rework.md). Proceed. (I relied on T's GREEN + my static code audit rather than
re-standing-up DDEV; the audit found nothing that would change the GREEN result.)

## Visual-diff results
N/A. Non-visual story. Per O's framing and handoff-U: do_streams ships **inert** — no
route/controller/block/Views display in this diff assembles `#theme => do_streams_shell` into a
browsable page (verified: `git diff c18f417 HEAD --name-only` shows zero routing/controller files;
the three demo page displays use `row: {type: fields}`, a plain table, never the shell). The UI
contract is therefore verified **statically** (handoff-U conformance table + T's 6 Kernel shell
tests), which is correct and sufficient for an inert scaffold — see UI/UX row below.

## AC-to-test traceability

| # | Acceptance criterion | Met in code (file:line) | Pinned by test | Status |
|---|---|---|---|---|
| 1 | Membership-scope plugin returns member-group nodes; no new storage | `MembershipScope::query()` EXISTS shape ([B-9]), no schema | `testMembershipScopeReturnsOnlyMemberGroupsNodes`, `...CoversAllOfTheUsersGroups`, `...IsEmptyForNonMember` (positive + all-groups + negative space) | MET + PINNED |
| 2 | Following-scope ORs follow_content/user/term, deduped | `FollowingScope::query()` 3-EXISTS OR, `field_group_tags` ([B-4]), entity_type-guarded | `testFollowingScope{FollowContent,FollowUser,FollowTerm}Branch` + `testFollowingScopeOrsAndDedupes` (double-match → exactly once) | MET + PINNED |
| 3 | 4 rankings correct: recent/last-activity/hot/pinned-first (deduped, cache-invalidated) | `viewsQueryAlter` + `apply{LastActivity,Hot,Pinned}Ranking` + `frontOfOrderBy` + `queryViewsDoStreamsDemoAlter` + `onFlaggingChange` | `testRecentRanking...`, `testLastActivityRanking{Prefers...,FallsBack...}`, `testHotRanking{OrdersByScoreDesc,IncludesNodesWithNoScoreRow}`, `testPinnedRanking{LeadsAsPrimaryKeyNotTiebreaker,DedupesRelationshipFanOut}`, `testPinToggleInvalidatesUserStreamCacheTagWithoutFlush` | MET + PINNED |
| 4 | Shell renders scope tabs + Recent/Hot control from params; no hardcoded routes; on stream_card | `preprocessDoStreamsShell` + `do-streams-shell.html.twig` (`{{ results }}` wraps stream_card) | `testScopeTabsContract...` (incl. url_or_param), `testRankingControlContract...`, `testTrendingScopeDoesNotDisableTheRecentRankingPill`, `testEmptyFlagReflectsResultCount`, `testEmptyCopyIsDistinctPerScope`, `testNoHardcodedRoutePathsInRenderedTabMarkup` | MET + PINNED |
| 5 | Both scope plugins proven against self-provisioned fixtures (no CI seed assumed) | `StreamsScopeTest::setUp()` self-provisions flags/field/vocab/view/roles | all of `StreamsScopeTest` | MET + PINNED |
| 6 | Zero schema; enables/uninstalls cleanly; suite stays green | no `.install`, reads existing tables only | `testModuleInstallsWithZeroSchemaChanges` (+ plugin/theme-hook discoverability anchor), `testModuleUninstallsCleanly` | MET + PINNED |
| 7 | Rendered DOM in isolated DDEV + Playwright | Deferred — inert scaffold has no routed shell to drive | U's Phase 8 (N/A this diff; re-runs on first #110-#115 that attaches a route) | DEFERRED (correct; not an S-blocker) |

**No AC met-in-code-but-unpinned, and no AC pinned-but-unmet.** The one prior such gap
(`url_or_param` cited in [B-3]/docblock but unasserted) was caught by the o4-mini diff gate, fixed
by F-rework, and closed by T's covering assertions at BOTH the render-array and rendered-markup
levels — verified non-vacuous by an executed break-and-restore (handoff-T-green-rework §Non-vacuity
proof). I re-read the assertions (`StreamsShellTest.php:184-208, 325-342`): they pin exact value
`?scope=<id>`, positively assert non-route-path shape (`assertStringStartsNotWith('/')`), and assert
DOM surfacing (`data-url-or-param="?scope=global"`) + no-empty-attribute. Genuinely load-bearing.

## Precise-spec conformance (audited directly against the code, not handoffs alone)

- **[B-9] membership EXISTS shape** — CONFORMANT. `MembershipScope::query()` (:73-89) emits the
  reference EXISTS: `group_relationship_field_data gr_node` INNER JOIN a second instance on `gid`,
  `plugin_id LIKE 'group_node:%'`, `group_membership`, `entity_id = :current_uid`. Uses
  `storage->get('base_field')` for the node ref (correct for a synthetic filter-only field —
  NIT-4 comment present).
- **[B-4] following 3-branch OR + dedupe via field_group_tags** — CONFORMANT. `FollowingScope::query()`
  ORs three EXISTS branches; follow_term joins `node__field_group_tags.field_group_tags_target_id`
  (NOT field_tags); every branch guards `entity_type` (`node`/`user`/`taxonomy_term`) so the shared
  flagging table never cross-matches. OR-of-EXISTS needs no scope dedupe (per [W-1]).
- **[B-1] last-activity GREATEST** — CONFORMANT. `applyLastActivityRanking()` (:119):
  `GREATEST(node_field_data.changed, COALESCE(NULLIF(do_streams_comment_stats.last_comment_timestamp, 0), node_field_data.changed))` DESC, LEFT-joined. Zero-comment fallback pinned by `...FallsBackToChangedWhenNoComments`.
- **[W-2] hot LEFT JOIN COALESCE** — CONFORMANT. `applyHotRanking()` (:135-152): LEFT join,
  `COALESCE(do_streams_hot_score.score, 0) DESC`; unscored-node inclusion pinned by
  `testHotRankingIncludesNodesWithNoScoreRow`.
- **[A-W1] pinned-first primary-key ordering + generic dedupe** — CONFORMANT. `frontOfOrderBy()`
  `array_unshift`es the pin CASE to the front (#52 fix); `queryViewsDoStreamsDemoAlter()` discovers
  aggregate targets by table-name membership + `do_streams_*_sort` alias suffix, NOT do_group_pin's
  hardcoded alias string. The `@todo` deferring dynamic `getTables()` discovery is a documented,
  justified NIT (only one ranking branch active per request), not a defect.
- **[A-W2] cache tag `do_streams:user_stream:<uid>`** — CONFORMANT. `userStreamCacheTag()` (:50-52)
  emits exactly this; NOT prefixed `do_group_pin:`. Emitted in `viewsPostRender`, invalidated in
  `onFlaggingChange` for the flagger's own tag (the only honestly-derivable affected viewer — see
  test-quality note below).
- **[A-W3] ranking via `$view->args[0]`** — CONFORMANT. `viewsQueryAlter()` reads `$view->args[0]`;
  the shipped + fixture views both carry a `ranking` contextual argument (default `recent`). Contract
  is unambiguous for #110-#115.
- **[B-3] shell contract vars incl. url_or_param** — CONFORMANT. `preprocessDoStreamsShell()` builds
  `scope_tabs[{id,label,url_or_param,active}]`, `ranking_control[{id,label,active}]`, `empty`,
  `empty_copy`; `results` wraps opaque render array.
- **[B-8] + D-gate res 1 (Trending = Global+Hot-default, Recent enabled)** — CONFORMANT. Trending
  is a shell tab→param mapping, not a plugin; the Recent pill carries NO `disabled` key under any
  scope (:452-460 has no disabled branch); pinned by `testTrendingScopeDoesNotDisableTheRecentRankingPill`.
- **D-gate res 2 (4 distinct per-scope empty_copy, Global no follow CTA)** — CONFORMANT. Four
  distinct strings (:463-467); Global's is "…explore groups to find something new" — contains no
  "follow"; pinned by `testEmptyCopyIsDistinctPerScope` (distinctness + `assertStringNotContainsStringIgnoringCase('follow', global)`).
- **No hardcoded routes** — CONFORMANT. No `<a href>` in the template; no `*.routing.yml` in the
  module. url_or_param is a `?scope=` param mapping. Pinned by `testNoHardcodedRoutePathsInRenderedTabMarkup`.
- **Zero schema changes / clean install-uninstall** — CONFORMANT. No `.install`; `StreamsInstallTest`
  proves zero-new-tables + clean uninstall; T verified real-site `drush en`/`pmu` success.

## Wireframe conformance (static — U is legitimately N/A)

The static verification is **genuinely sufficient** for this inert shell, and nothing about the UI
contract requires a live check that is being improperly skipped:

- The shell theme hook is assembled into a page by NO route/controller/block in this diff (verified
  by name-only diff + U's grep). There is no rendered surface a live browser could reach; a
  Playwright pass would only re-prove render-array facts already asserted at the Kernel layer.
- Every binding wireframe element maps to a Kernel assertion: 4 tabs + active flag, 2 pills + active,
  Trending-Recent-not-disabled (res 1), `empty` bool, 4 distinct per-scope copy + Global-no-follow
  (res 2), no-hardcoded-route markup, url_or_param DOM surfacing. The template additionally adds
  `aria-current` / `aria-pressed` (accessibility improvement over the low-fi mock, no regression).
- I independently confirmed the shell contract vars ([B-3]) and both D-gate resolutions are covered
  by tests that fail on regression (break-and-restore evidence in handoff-T-green §spot-check +
  handoff-T-green-rework). The static UI verification actually covers the UI contract — no gap.

The live pass legitimately transfers to whichever of #110-#115 first attaches a real routed
controller assembling `#theme => do_streams_shell` with live results + real navigation.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| Access / security (Drupal) | PASS | No routes/permissions shipped (inert, by design) — no naked `_access: 'TRUE'`. Data-access correctness enforced by 2 scope filter plugins, both inclusion+exclusion proven. All SQL is parameterized (`:do_streams_*` placeholders); no interpolated user input. User-facing strings are `TranslatableMarkup`. No secrets. |
| Config / schema | PASS | One new config entity (`views.view.do_streams_demo`) uses core `views.view.*` schema — no new schema needed; T confirmed it round-trips (`drush config:get`). |
| Error handling | PASS | LEFT-JOIN/COALESCE guards (no-comment, no-score-row) handle the edge cases; EXISTS branches degrade to zero-rows, not error, for absent fields ([B-4]). |
| UI/UX match to spec | PASS (static) | Inert shell; wireframe conformance verified statically per section above — appropriate for a scaffold with no routed surface. |
| Accessibility | PASS | Template adds `aria-current`/`aria-pressed`/`aria-label`/`role="group"` beyond the mock; no regression. (Full a11y re-checks at the routed downstream story.) |
| Architecture gate | PASS | A PASS (Phase 3) + A-dup PASS (Phase 7). |
| Code organization | PASS | `#[Hook]` attribute class in `src/Hook/`, docblock-only `.module`, `autowire: false` + `hook_implementations` service tag — mirrors do_group_pin/do_discovery. No dead code; the one `@todo` is a justified, documented deferral, not a stray TODO. |
| Docs (Keystatic-editable, links) | N/A | No `docs/` Astro/Starlight content changed; README.md is module-internal and accurate to the code. |
| Naming consistency | PASS | `do_streams:` namespace (tag, plugin ids `do_streams_membership_scope`/`do_streams_following_scope`, view `do_streams_demo`, `DEMO_VIEW_ID`) consistent with Drupal + sibling-module conventions; deliberately NOT `do_group_pin:` for the per-user tag ([A-W2]). |
| Test quality | PASS | See below. |

**Test-quality audit (rubric over T's 23 tests):**
- **Per test:** each names one behavior, sits at the cheapest sufficient tier (all Kernel — the
  scope/ranking behavior is entirely in compiled SQL; the shell in a preprocess function), and
  asserts behavior (result-row membership/order, variable values) not implementation shape. The
  ranking tests deliberately invert created-order-vs-target-order (e.g. `testHotRankingOrdersByScoreDesc`
  gives the newer node the lower score) so a "param silently ignored" regression fails loudly rather
  than passing by created-DESC coincidence — a strong non-vacuity design.
- **Two rewrites I scrutinized for weakening:**
  - *5 shell tests → direct `preprocessDoStreamsShell()` calls.* Verified the rewrite is FORCED by
    the render API (ThemeManager::render() takes `$variables` by value; a preprocess mutation can
    never appear on the caller's `$build` post-`renderRoot()`) — T re-derived this from core source.
    The direct-invocation form asserts the exact contract the real pipeline feeds the template, and
    each still pins its AC. Not weaker — it's the only reachable form. `testNoHardcodedRoutePaths...`
    correctly stayed on `renderRoot()` (it asserts the returned STRING, which renderRoot legitimately
    yields) and thus also exercises the real Twig template end-to-end.
  - *Pin cache-tag test → flagger toggles their own tag.* The original asserted an underivable
    third-bystander contract (nothing ties an arbitrary viewer to a global pin reorder). The rewrite
    asserts what IS derivable (flagger's own tag invalidated, an unrelated bystander's untouched,
    no full flush) — this still pins the real AC ("scoped per-user invalidation, no blanket flush")
    and matches the implementation's honest capability. Confirmed the rewrite is non-vacuous via
    T's commented-out-`invalidateTags` break (test FAILED). Not weaker.
- **Per suite:** 23 tests for a 2-plugin + 4-ranking + shell scaffold is proportionate, not padded.
- **Smells:** none found — no assertion-free, tautological, unreachable-outcome, duplicate-signal,
  or mock-shaped tests. No "delete or merge this test" finding. `testNoHardcodedRoutePathsInRenderedTabMarkup`
  (flagged vacuous-against-empty-markup at RED) now runs against real rendered markup and is a
  meaningful guard (assertion count rose RED→GREEN, confirming new paths exercised).

## Scope check

F delivered **exactly** the phase scope: 2 scope filter plugins + 4 rankings + the shell + shipped
inert demo view. No over-delivery (no by-author scope — that's #114; no non-node source — that's
#112; no speculative plugin-manager abstraction; no third "Trending" plugin — it's a tab→param
mapping per [A-W4]). No under-delivery (every AC met). `git diff c18f417 HEAD --name-only` confirms
every changed file is under `docs/groups/modules/do_streams/` or the handoff tree — zero drive-by
edits to do_group_pin / do_discovery / groups_chrome (A-dup verified reuse-first; I spot-checked and
confirm).

## ADVISORY-HOLD check

No source-of-truth defect found. The brief/wireframe are internally consistent and consistent with
Drupal conventions (real access via scope filter plugins rather than a naked bypass; per-user cache
tag correctly distinguished from the per-group precedent; the inert-shell contract is a legitimate,
already-reviewed scaffold shape; the approved wireframe's controls carry proper ARIA). Nothing to
surface. No ADVISORY-HOLD.

## Advisory notes (non-blocking — for future stories, not this commit)

1. **Shipped install view carries the membership-scope filter on its `default`/`page_1` display.**
   In a live site `/do-streams/demo/membership` renders a plain field table scoped to the current
   user's groups — inert and harmless (no menu link), exactly as [B-7] permits. When #110-#115
   attach real displays, they own their own filter config; they should not inherit this demo view's
   `default` filter accidentally. Not a defect here.
2. **`use_ajax: true` on the demo view's `default` display** is inherited by the page displays but
   drives no shell behavior (the shell ships no JS/library). Harmless for the scaffold; the routed
   downstream story wiring real navigation should decide its own AJAX posture deliberately.
3. **The `\Drupal::` DI-pattern phpcs/phpstan warnings (6 findings)** are pre-existing convention
   debt shared with do_group_pin (3 findings on its own hook class), not a regression. A future
   cross-module DI-cleanup ticket could eliminate the class, if the team wants.
4. **The `@todo` in `queryViewsDoStreamsDemoAlter()`** (derive aggregate targets from
   `$query->getTables()` instead of a static name list) is a sound robustness improvement to fold in
   whenever a downstream story adds a new ranking join — correctly deferred here (only one branch is
   ever active per request, so the static list is presently exhaustive).

## Verdict

**PASS** — all acceptance criteria met and pinned by sound, non-vacuous tests; every precise spec
([B-1/3/4/8/9], [W-2], [A-W1/2/3]) and both binding D-gate resolutions conform to the code I read
directly; POC scope held with zero drive-by edits; both A gates PASS; T reported zero blockers.
The inert-shell UI is verified STATICALLY, which is correct and sufficient for a scaffold with no
routed surface in this diff. Ready for O to proceed to the (serialized) PR step.
