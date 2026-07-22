# Handoff-A-dup: Phase 7 - do_streams engine scaffold (issue #109)  (anti-duplication gate)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Diff base:** c18f417   **Diff head:** 39f7370 (includes 71a1ebc rework fix + e6850ad/39f7370 T-GREEN)
**Reuse map:** `docs/handoffs/109-do-streams-scaffold/survey.md` §"Reuse & Analogous-Feature map"
**Verdict:** PASS

## Summary

F extended the `do_group_pin` *pattern* exactly as the Reuse map called for — new module, same
technique, no fork of the `do_group_pin` object — and reused its one legitimately-shareable
artifact (`DoGroupPinHooks::PIN_FLAG_ID`, a public const) by direct reference rather than copying
the flag-id string. The ordering/dedupe/cache-tag *logic* is independently re-implemented against
do_streams' own tables/aliases, which is correct: `do_group_pin`'s hooks are hardcoded to
`STREAM_VIEW_ID = 'group_content_stream'` and a single relationship alias
(`group_relationship_field_data_node_field_data`), so they structurally cannot be called for a
different view with a different join set without first refactoring `do_group_pin` into a
view-agnostic service — out of scope, not requested by the brief, and would touch
single-owner code the brief did not authorize touching. The two Views filter plugins are
justified-new (first Views plugins in the codebase, confirmed no prior art). The shell wraps,
does not reimplement, `stream_card`. No parallel path found; no drive-by edits outside
`do_streams`' own directory.

## Findings

| # | Severity | File:line | Finding | Suggested fix |
|---|---|---|---|---|
| — | — | — | No duplication found. | — |

No `block` findings.

**Reuse-map item verification:**

1. **Ranking wiring — EXTEND the do_group_pin pattern, new module (not the object).** Verified.
   `DoStreamsHooks::viewsQueryAlter()` / `queryViewsDoStreamsDemoAlter()` /
   `frontOfOrderBy()` (`docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php:63-278`)
   structurally mirror `DoGroupPinHooks::viewsQueryAlter()` /
   `queryViewsGroupContentStreamAlter()` (front-of-orderby `array_unshift`, aggregate+GROUP BY
   dedupe) but operate on do_streams' own view id (`do_streams_demo`), its own join-side table
   aliases (`do_streams_comment_stats`, `do_streams_hot_score`, `do_streams_pin_flagging`), and
   are discovered generically by table-name membership rather than copying `do_group_pin`'s
   hardcoded `group_relationship_field_data_node_field_data` alias string — this is the [A-W1]
   requirement, honored. This is pattern-reuse, not duplication: `do_group_pin`'s hooks gate on
   `$view->id() !== self::STREAM_VIEW_ID` and cannot fire for `do_streams_demo` at all; there is
   no shared object here to call into without a `do_group_pin` refactor the brief did not ask
   for.
2. **Pinned-first ranking calls, not duplicates, `DoGroupPinHooks::PIN_FLAG_ID`.** Verified —
   `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php:171` (`applyPinnedRanking()`) and
   `:334` (`onFlaggingChange()`) both reference `DoGroupPinHooks::PIN_FLAG_ID` directly (a
   `public const`), never redeclaring the `'pin_in_group'` string. This is the one genuine
   cross-module call the Reuse map named, and it is used as specified. `streamCacheTag()` is
   deliberately NOT called (do_streams defines its own `userStreamCacheTag()`, per-user rather
   than per-group) — this was already reviewed and approved at Phase 3 ([A-W2]/finding #2 in
   `handoff-A.md`), a deliberate, reviewed split, not drift.
3. **Membership/following scope Views filter plugins — justified NEW.** Verified.
   `find . -path "*/src/Plugin/views*" -type d` returns only the two new do_streams plugin
   directories; no prior Views plugin existed anywhere in the codebase before this story, as the
   Phase-3 review already confirmed and this diff does not contradict.
4. **Following-scope's 3-branch OR-of-EXISTS is genuinely new join-shape logic**, not an
   N-times repeat of any existing pattern (`follow_content` direct entity match,
   `follow_user` via node author, `follow_term` via `field_group_tags` — three different join
   shapes) — matches the survey's own characterization of this as real complexity, not
   duplication of anything existing.
5. **Hot ranking consumes, does not recompute, `do_discovery_hot_score`.**
   `applyHotRanking()` (`DoStreamsHooks.php:135-153`) is a single LEFT JOIN against
   `do_discovery_hot_score.score`, `COALESCE(...,0) DESC` — no scoring formula duplicated (the
   `($row->comment_count * 3) + ($row->view_count * 0.5)` computation stays solely in
   `DoDiscoveryHooks::cron()`). Correct consume-only relationship.
6. **Shared shell wraps, does not reimplement, `stream_card`.** Verified —
   `do-streams-shell.html.twig` renders `{{ results }}` as an opaque pre-rendered render array
   and its docblock states explicitly it does not redesign the card
   (`node--stream-card.html.twig` owns that). `groups_chrome.theme` /
   `groups_chrome_preprocess_node()` / `node--stream-card.html.twig` are untouched by this diff
   (confirmed: zero references to those files outside do_streams' own doc comments, and the
   full diff stat shows zero files changed outside `docs/groups/modules/do_streams/` and the
   `docs/handoffs/109-do-streams-scaffold/` planning tree).
7. **No drive-by edits to other agents'/other modules' owned code.** `git diff c18f417 HEAD
   --stat` confirms every changed production/test file is under
   `docs/groups/modules/do_streams/`; `do_group_pin`, `do_discovery`, and `groups_chrome` source
   are read-only references in this diff, never modified.
8. **Rework commit (71a1ebc, diff-gate [B-1] fix) stayed inside the same objects** — added a
   `url_or_param` key to the existing `scope_tabs` array in the existing
   `preprocessDoStreamsShell()` method and a `data-url-or-param` attribute on the existing Twig
   template; no new object introduced, no drift.

## Notes for F

Not applicable (PASS). One non-blocking observation carried forward for O/T, not a duplication
finding: F's handoff flags 5 tests in `StreamsShellTest.php` (render-array-key assertions
against `$build` post-`renderRoot()`) as architecturally unsatisfiable given Drupal's render
pipeline, and 1 test (`testPinToggleInvalidatesUserStreamCacheTagWithoutFlush`) as asserting an
undecidable bystander-vs-flagger scenario. These are T-owned test-correctness questions (already
disclosed in `handoff-F.md`'s "Tests that look wrong"), not architecture/duplication issues — no
action needed from A.

## Decision-journal entry appended

`docs/handoffs/109-do-streams-scaffold/decisions.md` — see below.
