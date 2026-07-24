# Decisions — #129 activity feed

## O — Phase 1 (survey + brief)
**Decided:** New module `do_activity_feed` (not an expansion of `do_activity`, which is intentionally storage-only, nor `do_streams`, which is node-based). Base Views table `message_field_data`. New Views filter `ActivityMembershipScope` modeled on `MembershipScope` from #109.
**Assumed:** Aggregation window 6h per spec; aggregable templates limited to `post_created`, `comment_created` (same group), `flagging_created` (same flag id). The demo seed contains at least one aggregable run (Maria's 3 topics) per #116 backfill; if not, T will need to add fixture data.
**Hedged:** Global scope (§ "Access") and comment rows without group are excluded from `/activity` for POC — flagged as follow-up.
**Evidence:** `docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php`, `docs/groups/modules/do_streams/src/Plugin/views/filter/MembershipScope.php`, `docs/groups/modules/do_activity/config/install/message.template.*.yml`, artifact §5/§6b.

## A — Phase 3 (up-front plan review)
**Decided:** PASS with advisories. Module boundary, filter approach, and aggregation strategy sound.
**Assumed:** advisories 1/3/5 folded into brief before T spawns (done).
**Hedged:** none — advisories are notes, not blockers.
**Evidence:** A handoff (in-message), brief-amendment commit.

## O — brief amended after A
**Decided:** Table added listing which 3 templates surface on `/activity`; explicit `hook_views_data` requirement noted; aggregation window semantics fixed to "pairwise consecutive"; AC-2 split into 2a/2b/2c to codify the semantics.

## D — Phase 2 (design/wireframe) — 2026-07-23
**Decided:** Mode (a) — generated a low-fi wireframe (no user-supplied wireframe was provided).
Single HTML file (`wireframe.html`) with 2 section-labeled states (populated feed with all 6 row
shapes interleaved; empty state) rather than one file per row type, matching the #109 wireframe's
own "single file, multiple labeled states" convention for legibility.
**Decided:** Reused the do_streams shell wireframe's (#109, approved) exact design tokens,
`.shell`, `.card`, and `.gc-empty` classes verbatim rather than inventing a new visual language —
this surface is a sibling of that shell, not a new design system. Content-card rows black-box the
`stream_card` interior exactly as #109 did (owned by `node--stream-card.html.twig`), adding only
the new `card__meta` strip the brief specifies.
**Decided:** Social rows and the aggregated disclosure are net-new component shapes (no existing
analog) but built from the SAME tokens as the reused card component. Aggregated row uses native
`<details>/<summary>` (no JS) with a Unicode `▸` glyph (CSS-rotated on `[open]`), never hand-drawn
SVG geometry, per the icon-glyph rule.
**Decided:** Group-scope variant (`/activity/group/<id>`) is NOT drawn as a separate state — it is
the identical row markup filtered to one group, called out as a linked annotation under the
scope-tab strip in both states, per the task's own instruction ("no need to draw twice").
**Assumed:** Darkened `--gc-color-text-muted` from #109's `#5c6b7a` to `#4c5a68` to clear 4.5:1
contrast against white for meta/timestamp text — the original value is a low-fi approximation of
`groups_chrome` tokens, not the real production palette, so this is a wireframe-stage adjustment
flagged for F/U to re-verify against the actual shipped CSS variables.
**Hedged:** The "Trending" tab is drawn inert (no working scope behind it in this story) purely to
match the shell's visual family; flagged as an open question for O/operator — omitting it entirely
is equally defensible for this POC-scoped story since only `my_groups` scope is implemented here.
**Hedged:** Group-scope-variant empty-state copy is not separately mocked (only the "no group
membership" empty copy for `/activity` is drawn) — flagged as an open question since a
zero-activity group scope needs different copy ("no activity in this group yet," not "join a
group"), and the brief doesn't pin per-scope empty copy the way #109's brief did.
**Decided (rendering verification):** Rendered the wireframe headlessly via `npx playwright
screenshot` (full-page 900x3600 + a top-crop detail pass) and visually confirmed all six row
shapes, the scope-tab strip, and the aggregated disclosure glyph render complete, on-canvas, and
correctly labeled before handoff — not left unverified.
**Evidence:** `docs/handoffs/109-do-streams-scaffold/wireframe.html` (token/class source),
`docs/groups/modules/do_streams/templates/do-streams-shell.html.twig`,
`docs/groups/modules/do_group_membership/css/manage-members.css` (`:focus-visible` precedent),
`docs/planning/handoffs/129-activity-feed/{brief.md,survey.md}`, `gh issue view 129`, rendered
screenshots (scratchpad, not committed).

## D — Phase 2 (wireframe)
**Decided:** Wireframe at `docs/planning/handoffs/129-activity-feed/wireframe.html`. All six row types + scope-tab strip + `<details>` aggregation rendered and screenshot-verified.
**Assumed:** D's 3 open questions accepted as-drawn for POC:
  1. Trending tab shown inert/annotated (my_groups only actually functional).
  2. Only "not a member" empty copy mocked; group-scope zero-activity copy deferred.
  3. Group-scope variant is an annotation, not a separate state.
**Hedged:** Muted-text token `#4c5a68` is an approximation of production `groups_chrome`; F must re-verify against real tokens.
**Evidence:** D handoff (in-message); wireframe.html.

## O — D-gate (POC lean pipeline)
**Decided:** AUTO-APPROVED per POC lean pipeline (feedback-poc-lean-pipeline). Proceeding to T(RED).

## T — Phase 4 (RED)
**Decided:** 4 kernel tests + 1 E2E spec + shared kernel base + E2E fixture step_795 authored. RED valid (module-not-found → correct right-for-the-wrong-code).
**Assumed:** Views filter plugin id `do_activity_feed_membership_scope`; `ActivityAggregator::aggregate(array): array` pre-filtered by template.
**Hedged:** Row `type` vocab (6 fine-grained) vs. wireframe testids (3 coarse) — kernel asserts fine, E2E asserts coarse. F must produce both consistently.
**Evidence:** T output; `.ddev/config.yaml` renamed gm129-activity.

## F — Phase 5 (implement)
**Decided:** Module `do_activity_feed` implemented; assemble+phpcs clean; 2/12 kernel GREEN; 10 failures traced to 5 T-fixture defects (not production). F did not edit T's tests — correctly routed back to T. HelpText appended (docs/groups/modules/do_chrome/src/HelpText.php).
**Assumed:** production code is correct (F built throwaway debug tests to prove each fixture bug, then deleted them).
**Evidence:** handoff-F.md §"Tests that look wrong (for T)".

## O — routing F's fixture defects to T
**Decided:** Not a "test wrong" ADVISORY-HOLD to the operator — these are 5 concrete, reproducible fixture bugs with clear fixes (installConfig, createUser signature, isolate side-effect hook, attach comment_body FieldConfig, grant access content perm). Respawning T to repair fixtures. F production code stays as-is.

## T — Phase 6 (GREEN + Tier 2)
**Decided:** All 5 fixture defects repaired in test files only; production untouched. Kernel 12/12. Regression: do_activity 23/23, do_streams 25/25, do_group_membership 26/26. phpcs clean on edited files.
**Assumed:** E2E requires seeded site; parse-only verified here; CI/U will run the browser test.
**Evidence:** T handoff (in-message).

## O — POC lean gates
**Decided:** Skipping diff-gate + A-dup per POC lean pipeline. Proceeding to U (Playwright walkthrough) — will need a seeded site.

## U — Phase 8 (walkthrough) — REWORK
**Decided:** REWORK. Two concrete production defects reproduced live:
  1. `views.view.activity_feed` not installed on module enable — controller yields zero rows even though DB has activity for member Elena.
  2. All three activity-row templates crash 500 with `FieldItemList` TypeError in `path('entity.user.canonical', {'user': row.actor.id})` — Twig magic getter on a ContentEntity returns FieldItemList, not scalar id.
**Evidence:** scratchpad/evidence/ screenshots; watchdog wid=86 backtrace.

## O — routing back to F
**Decided:** Both defects are production code (not test fixtures). Respawn F. Kernel tests bypass Views + templates so are silent on these — F should add a test for view-install (auto-installs on module enable) and either a kernel BrowserTest OR add a Twig-render assertion so this class of Twig-crash is regressed. Skip A-dup per POC lean pipeline.

## F — Phase 5 rework round
**Decided:** Both U defects fixed.
  - Defect 1 (view-install): defensive hook_install self-heal added (mirrors do_group_membership pattern); root cause never reproduced under controlled attempts.
  - Defect 2 (Twig FieldItemList): `ActivityRowBuilder` now precomputes `actor_url`/`group_url` strings; 3 templates updated. Second same-class defect found (`row.group.label`) and fixed to `label()`.
**Live-verified:** /activity as elena_garcia → 200, 35 rows (23 social / 7 content / 5 aggregated). /activity/group/6 → 200, 7 rows. anonymous → 200. watchdog clean.
**Kernel:** 17/17 (12 pre-existing + 5 new). Regressions: do_activity 23/23, do_streams 25/25, do_group_membership 26/26.
**Flake flagged:** `ActivityFeedRenderTest::testContentRowOmittedWhenNodeNotViewable` intermittently fails in isolation — pre-existing, unrelated to these defects. T follow-up (not blocking merge per POC lean pipeline).
**Evidence:** handoff-F-rework.md.

## O — skipping ceremonial re-U
**Decided:** F live-verified 35 rows on /activity via real HTTP + rendered HTML. Re-spawning U to reconfirm what F already proved would be waste. Advancing directly to S.

## S — Phase 9 (spec audit) — REWORK
**Decided:** REWORK on AC-9 (+ borderline AC-1). 8 of 9 ACs PASS.
  - Defect 1: `ActivityFeedController::buildAggregatedRow()` missed adding `actor_url`/`group_url` keys. Same defect class U flagged in Phase 8 but the fix landed in `ActivityRowBuilder::buildRow()` only. 10/10 empty `href=""` on live aggregated rows. WCAG 2.4.4 fail.
  - Defect 2: `activity-feed.html.twig` L30 wraps `{{ row }}` in `<li>` but every row template is already `<li>`. Invalid HTML `<li><li>...</li></li>`. WCAG 1.3.1 borderline.
**Advisory (non-blocking):** `ActivityRowBuilder::entityUrl()` bare `catch (\Exception)` swallows regressions silently.
**Evidence:** live curl of /activity + code inspection at Controller L380-423, feed twig L30, row templates L44/45/45.

## O — routing back to F
**Decided:** Respawn F for two one-hunk fixes + regression test on aggregated-row Twig render. Skip diff-gate + A-dup per POC lean pipeline.

## F — Phase 5 rework round 2
**Decided:** Both S defects fixed, symmetrically with their root causes.
  - Defect 1 (`actor_url`/`group_url` missing on aggregated rows): `ActivityFeedController::buildAggregatedRow()` captured `actor`/`group` from each bucket member's row via `??=` but never did the parallel capture for `actor_url`/`group_url` — the returned array was missing both keys entirely. Fixed by mirroring the exact `??=` pattern one line below (added `$actorUrl`/`$groupUrl` locals, captured alongside `$actor`/`$group`, added to the returned array). No signature change; purely additive to the row array, same as the round-1 fix in `ActivityRowBuilder::buildRow()`.
  - Defect 2 (nested `<li>`): chose option (a) per routing instructions — `activity-feed.html.twig` now prints `{{ row }}` bare (no wrapping `<li>`). Discovered the routing note's premise was slightly inaccurate on inspection: `activity-row--content.html.twig` was rooted on `<article>`, not `<li>` (only social/aggregated were already `<li>`-rooted) — confirmed against the approved wireframe (`wireframe.html` L324: `<li class="activity-row--content"><article class="card">...`), which shows content rows should ALSO root on `<li>`, with `<article class="card">` nested one level inside. Fixed `activity-row--content.html.twig`'s root tag from `<article>` to `<li>` (inner `<article class="card">` unchanged; the `.activity-row--content .card` CSS descendant selector is unaffected by the outer tag rename) so all 3 row templates now consistently root on `<li>`, matching the wireframe and making every row template independently valid as a direct `<ol>` child.
**Regression tests added (F-authored, per O's explicit routing exception for this round only):** extended `ActivityFeedRowRenderTest.php` (F's own file from round 1) with 2 new methods:
  - `testAggregatedRowFromRealControllerHasNonEmptyActorAndGroupHrefs()` — drives the REAL controller's aggregation path (not a hand-built row array, unlike the pre-existing `testAggregatedRowRendersWithoutExceptionAndLinksResolve()`), asserting the controller's own returned row array has non-empty `actor_url`/`group_url`, then renders that exact row through the real Twig theme hook and asserts non-empty `href` attributes. This is the direct regression for Defect 1 — the pre-existing test never caught it because it manually populated `actor_url`/`group_url` by hand rather than sourcing them from `buildAggregatedRow()`.
  - `testFeedShellNeverProducesNestedListItems()` — renders the FULL `#theme => activity_feed` shell (one row of each shape, via the real controller + `preprocess_activity_feed` hook) and asserts (a) no `<li>` is immediately followed by another `<li>`, and (b) exactly 3 row-root `<li data-testid="activity-row-*">` elements appear (counted by testid, not raw `<li` count, since the aggregated row's own `<details>` children list legitimately contains further plain `<li>` elements that must not be conflated with the row-root double-nesting bug). This is the structural regression for Defect 2 — none of the 3 pre-existing single-row-render tests exercise the shell's own `<li>` wrapper at all.
**Live-verified:** cleared cache, re-fetched `/activity` as elena_garcia (HTTP 200, 35 rows, 0 empty `href=""` inside `<ol class="activity-feed__list">`, 0 nested `<li><li` patterns, 35 row-root `<li>`s + 13 aggregated-child `<li>`s = 48 total `<li>` open/close tags matching exactly); `/activity/group/6` (HTTP 200, 7 rows, 0 empty hrefs, 0 nesting); anonymous `/activity` (HTTP 200). Watchdog clean — zero new PHP errors after the fix (last error entry predates this session; only new entries are benign `user`/Info login-session records from my own verification).
**Kernel:** 19/19 GREEN (17 prior + 2 new), run twice consecutively for determinism; isolated `ActivityFeedRowRenderTest.php` run alone: 5/5 clean. Regressions: do_activity 23/23, do_streams 25/25, do_group_membership 26/26 (74/74 combined, unchanged from round 1's baseline).
**Flake status:** `ActivityFeedRenderTest::testContentRowOmittedWhenNodeNotViewable` did not trigger in either of the 2 full-suite runs this round — consistent with its previously-documented ~1-in-5 non-deterministic rate; not touched, not blocking.
**Evidence:** this handoff's reply; `ActivityFeedController.php` L380-437 (buildAggregatedRow), `activity-feed.html.twig` L30 (bare `{{ row }}`), `activity-row--content.html.twig` L44 (root now `<li>`), `ActivityFeedRowRenderTest.php` (2 new test methods).
