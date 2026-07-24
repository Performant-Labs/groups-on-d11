# Handoff-S: Phase 7 — #182 Seed pipeline: forum bundle needs comment field

**Date:** 2026-07-24
**Branch:** 182-forum-comment-field
**Issue:** #182
**Commits audited:** `80c5145` (F: YAML) + `ddd2697` (T-green: fixture fix)
**Handoff-A reviewed:** `docs/planning/handoffs/182-forum-comment/handoff-A.md` (PASS)
**Handoff-T-red reviewed:** `docs/planning/handoffs/182-forum-comment/handoff-T-red.md`
**Handoff-F reviewed:** `docs/planning/handoffs/182-forum-comment/handoff-F.md`
**Handoff-T-green reviewed:** `docs/planning/handoffs/182-forum-comment/handoff-T-green.md` (0 blocking issues)

## Preconditions
- A precondition: PASS (Phase 3 verdict = PASS, two warns folded into T's design).
- T precondition: PASS (T-green reports 0 blocking issues, 2/2 target GREEN, 53/53 regression suite).
- Visual-tool preconditions: N/A (data/config story, no UI surface per brief).

## Per-AC verdict

| AC | Requirement | Evidence | Verdict |
|---|---|---|---|
| 1 | `docs/groups/config/field.field.node.forum.comment.yml` exists, extends `field.storage.node.comment`, bundle `forum`, `settings.anonymous: 0` | File present. `dependencies.config` includes `field.storage.node.comment` + `node.type.forum`; `bundle: forum`; `field_name: comment`; `field_type: comment`; `settings.anonymous: 0`. New v4 uuid `7f3d9b2a-…`. Matches article template byte-for-byte except uuid, id, bundle, node.type.* dep, and the (correctly-omitted) `_core.default_config_hash`. | PASS |
| 2 | After assemble + config-import, forum bundle has a `comment`-type field definition | Locked by `HotScoreForumCommentTest::testForumBundleHasCommentField` (`getFieldDefinitions('node','forum')` + `array_filter` on `getType()==='comment'` + `assertNotEmpty`). RED→GREEN verified; spot-check confirms it fails on removal. | PASS |
| 3 | Kernel test: forum node + comment + cron → `hot_score > 0` | Locked by `testForumNodeHotScoreIsPositiveAfterCommentAndCron`. Real recompute path (`DoDiscoveryHooks::cron()` via `class_resolver`); reads `do_discovery_hot_score.score` from DB; `assertGreaterThan(0, …)` — expected value 3.0 from formula `(comment_count × 3)`. GREEN with 46 assertions total. | PASS |
| 4 | Seed script Step 740c no longer errors; emits ≥6 comment lines | Verified by direct read of `docs/groups/scripts/step_700_demo_data.php:205-236`. Guard `if (!$comment_field)` uses identical query pattern to AC #2's test (`getFieldDefinitions('node','forum')` + type check). AC #2 proves this returns a comment field, so control flows into the else-branch; the else-branch iterates a hardcoded array of 6 comment records (`Venue Logistics Thread` ×2, `Patch Review Process RFC` ×2, `Getting Started with Paragraphs` ×1, `Community Code of Conduct` ×1) and emits `Comment cid=… on "…"` for each. Transitively satisfied. | PASS (transitive) |
| 5 | phpcs clean | T-green: 0 errors on amended test file (Drupal,DrupalPractice) after LF normalization. YAML is not phpcs-linted. F's YAML lives outside `web/modules/`. | PASS |
| 6 | No regressions | T-green: 53 tests / 1573 assertions / 0 failures across `do_discovery` + `do_streams` Kernel suites. `IcalFeedsTest` unchanged (5/5, matching T-red's baseline). | PASS |

## Issue-intent trace (unblocks #113 /trending hot-score ordering)

Chain verified end-to-end:

1. **Field attached** (AC #1 + #2) → `forum` bundle carries a `comment` field.
2. **Seed script runs** (AC #4) → `comment_entity_statistics.comment_count > 0` for the two `/trending`-critical seeded threads (`Venue Logistics Thread` gets 2 comments, `Patch Review Process RFC` gets 2 comments) plus 2 more (`Getting Started with Paragraphs`, `Community Code of Conduct`). All 6 planned comments seed.
3. **Cron populates** `do_discovery_hot_score` (AC #3 exercises this exact path in Kernel) → commented forum nodes get `score = comment_count × 3 = 6.0` (2 comments × 3).
4. **`/trending` orders by** `do_discovery_hot_score.score DESC` → seeded demo forum threads with comments now outrank the zero-comment kernel/functional/fixture nodes that previously polluted the top of `/trending` via the nid-DESC tiebreak. Issue's headline gap ("commented threads outrank zero-comment nodes") is resolved on the seeded demo.

## Blast-radius audit

- **E2E surface:** grepped `tests/e2e/*.spec.ts` for `forum` — only 5 files match, and none of them submit or edit forum node forms. `demonstrator-seeds.spec.ts` asserts on `/node/add/forum?group={gid}` returning 403 for anonymous (permission check, form widget content irrelevant). `group-type-homepage.spec.ts` asserts on a "Recent discussions" section linking to forum nodes (read-only). `phase3.spec.ts` reference is a code comment. `trending.spec.ts` is the story that motivated #182; its ordering assertion is intentionally deleted per the issue's non-goal. `group-restore.spec.ts` reference is unrelated (restore flow). **Zero regression surface from the new comment form widget on the forum node edit page.**
- **Kernel/Functional surface:** A's blast-radius review (handoff-A.md §"Confirmed non-issues") already established no test asserts on the forum bundle's field-list count or the absence of a comment field. T-green's 53/53 regression run empirically confirms this.
- **Config-import surface:** F's YAML dep chain (`field.storage.node.comment`, `node.type.forum`, module `comment`) is complete; all three exist in shipped config. Assemble + import succeeds (132 files copied vs. T-red's 131 baseline, exactly +1 for the new file).
- **Displays:** No explicit form/view display YAMLs — Drupal auto-generates, matching precedent (`field.field.node.forum.body.yml` and `field.field.node.forum.field_group_tags.yml` also ship without display YAMLs).

## Test-quality audit (`testing/test-quality.md` §7)

- **Per test:** each names one behavior (field attachment / hot-score after comment+cron), sits at Kernel tier (cheapest sufficient — no HTTP/browser layer needed), asserts on behavior (`getFieldDefinitions` output; `do_discovery_hot_score.score`), and fails in isolation for the right reason (RED validated at Phase 4; failure-on-removal spot-check re-validated at Phase 6).
- **Per suite:** 2 tests for 2 ACs and 1 config file — proportionate. No redundant fan-out; no snapshot-everything patterns; no mock-shaped assertions. Distinct from `StreamsRankingTest` (which seeds the score row directly on `page` nodes — no overlap with the comment→cron→score path on `forum`).
- **Smells:** none observed. No tautological or assertion-free tests. No "delete or merge" findings.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| Spec compliance | PASS | All 6 ACs satisfied (5 directly, 1 transitively via AC #2). |
| Architecture gate | PASS | A returned PASS with two warns folded into T's design. |
| Code organization | PASS | Single new YAML; test co-located in `do_discovery/tests/src/Kernel/` per existing convention. |
| Naming consistency | PASS | `field.field.node.forum.comment.yml` matches article-template naming exactly. |
| Test quality | PASS | See rubric above. |
| Security | N/A | No new input surface; `settings.anonymous: 0` preserved (authenticated-only comments). |
| Performance | PASS | One additional cron JOIN over `comment_entity_statistics` for forum nodes — already exercised for other bundles; no new query pattern. |
| Regression | PASS | 53/53 in `do_discovery` + `do_streams`, +2 assertions from the new tests. |

## Scope check

F delivered exactly the phase scope: one new field-instance YAML, no drift into other bundles, no display YAMLs, no seed-script edits, no changes to `DoDiscoveryHooks.php`. T added a proportionate 2-test Kernel suite. No over-delivery, no under-delivery.

## Verdict

**PASS** — ready for PR.

## Advisory notes

- None blocking. F's diagnostic-copy debugging pattern (throwaway test to isolate `setUp()` gaps in a Kernel harness that skips `hook_install()`) is worth preserving in team memory for future stories that touch core state keys or bundle-specific field configs — the two-line fix (`\Drupal::state()->set('comment.maintain_entity_statistics', TRUE)` + explicit `field_config` install) is now an established pattern in this codebase's Kernel suite alongside `IcalFeedsTest`'s precedent.
