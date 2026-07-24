# Handoff-A: Phase 3 — #182 Seed pipeline: forum bundle needs comment field  (up-front plan review)

**Date:** 2026-07-24
**Branch:** 182-forum-comment-field
**Brief reviewed:** docs/planning/handoffs/182-forum-comment/brief.md
**Reuse map:** brief §"Reuse & Analogous-Feature map" + survey.md
**Wireframe:** N/A (data/config, no UI)
**Verdict:** PASS

## Summary
Plan is right-sized and consistent with existing patterns. The extend-vs-new call — clone `field.field.node.article.comment.yml` into `docs/groups/config/field.field.node.forum.comment.yml`, adjust `bundle` + `node.type.*` dep, keep `settings.anonymous: 0` — is the correct application of the reuse map. Comment module, storage, and type are all in place; the seed-script fallthrough is already coded and idempotent. Deferring explicit form/view displays to Drupal's auto-generation matches the existing convention (no other `docs/groups/config/core.entity_form_display.node.forum.default.yml` exists; `node.forum.body` and `node.forum.field_group_tags` were added the same way). Two `warn`-level items below are worth surfacing to T/F but are not amendments to the plan.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | AC bullet #3 | naming | AC references `do_discovery_recalculate_hot_scores()`; no such function exists — hot-score recalc lives only in `DoDiscoveryHooks::cron()` (`#[Hook('cron')]`). | T should invoke the Hook class method (or run cron) in the kernel test; no plan amendment required — an AC wording fix in the F/T handoffs is enough. |
| 2 | warn | seed step ordering | cross-cutting | `hot_score` is written by `nodeInsert` (score=0) and updated by `cron`; simply seeding comments will not by itself update `hot_score` until cron runs OR the test calls the cron hook explicitly. `comment_entity_statistics` IS updated automatically on comment save by core comment module, so the JOIN will yield correct counts — but the score row itself needs the merge to run. | T should call `DoDiscoveryHooks::cron()` in the assertion path; no change to F's scope. |

## Confirmed non-issues
- **Config integrity:** cloned YAML's dep chain (`field.storage.node.comment`, `node.type.forum`, module `comment`) is complete. `comment.type.comment` is transitively required only by the storage, which already declares its own deps — no need to add it to the field-instance YAML (article's clone doesn't declare it either). Assemble script + config-import will succeed.
- **Blast radius:** no kernel/functional test asserts on the forum bundle's field list count or the absence of a comment field. `GroupsKernelTestBase::NODE_BUNDLES` and the wizard test enumerate node type machine names only; `MultigroupCardinalityTest` iterates bundles but does not touch field defs; `DoNotificationsHooks` / `DoMultigroupHooks` / `do_chrome` reference `'forum'` as a bundle string, not as a field-set. No regression surface.
- **Reuse map correctness:** correct. The only extendable analogous object is `field.field.node.article.comment.yml`; there is no forum-side comment field to fold into.
- **Auto-generated displays:** matches precedent — the existing `field.field.node.forum.body.yml` and `field.field.node.forum.field_group_tags.yml` also ship without corresponding `core.entity_form_display.node.forum.*.yml` in `docs/groups/config/`.
- **`comment_entity_statistics` in kernel test env:** `do_activity`, `do_activity_feed`, `do_streams` kernel bases already install this schema — T has three working precedents to copy if the hot_score assertion runs at Kernel tier.

## Notes for O
None. PASS — proceed to T-red.

## Patterns referenced
- `config/sync/field.field.node.article.comment.yml` (template)
- `config/sync/field.storage.node.comment.yml`, `config/sync/comment.type.comment.yml`
- `docs/groups/config/field.field.node.forum.body.yml` (precedent for adding a field to forum without a display YAML)
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` (actual hot_score wiring)
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamsRankingTest.php` (comment_entity_statistics install pattern)
