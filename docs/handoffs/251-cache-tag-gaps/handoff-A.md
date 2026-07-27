# Handoff-A: Phase 3 — #251 Cache-tag gaps (up-front plan review)

**Date:** 2026-07-26
**Branch:** 251-cache-tag-gaps
**Brief reviewed:** `docs/handoffs/251-cache-tag-gaps/brief.md`
**Reuse map:** brief §"Reuse & Analogous-Feature Map" (audit `docs/planning/perf/cache-audit-2026-07-24.md` §4 Rec 1/2/3)
**Wireframe:** N/A (no UI surface)
**Verdict:** PASS

## Summary

Plan is tight, extends-not-parallels, and cites the correct analog for each of the 3 fixes. Fix 1
mirrors `DoActivityHooks::groupRelationshipInsert()`'s `group_membership` plugin-id discrimination
(verified at `do_activity/src/Hook/DoActivityHooks.php:122-157`) and reuses the existing
`Cache::invalidateTags([userStreamCacheTag(...)])` idiom from `DoStreamsHooks.php:474`. Fix 2 adds
a new `#[Hook('comment_insert')]` method on the existing `DoDiscoveryHooks` class following the
established `#[Hook(...)]` attribute pattern in that class. Fix 3 is a 1-line YAML change matching
the exact precedent set by three sibling views (`my_feed.yml:141`, `trending.yml:115`,
`following_feed.yml:118`). One Functional test per gap is correctly right-sized — regression pins,
not architecture.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| — | — | — | — | Plan is consistent with existing patterns. | — |

Notes on the three fixes reviewed:

- **Fix 1 (`/my-feed` invalidation on group join/leave):** Correct hook selection.
  `group_relationship_insert` + `_delete` scoped to `group_membership` plugin id is the exact
  discriminator `DoActivityHooks::groupRelationshipInsert()` already uses (line 142:
  `if ($plugin_id === 'group_membership')`). Reusing `userStreamCacheTag()` and
  `Cache::invalidateTags()` on `DoStreamsHooks` — the class that owns the tag — is textbook layer
  discipline; the tag stays private to its module. `$member->id()` on the returned entity is a user
  id (group_membership relationships have a user as their entity), which is the right cache-tag key
  for `user_stream:{uid}`.
- **Fix 2 (`/trending` invalidation on comment_insert):** Correct hook and extension target.
  `DoDiscoveryHooks` already owns `cron` (hot-score writer) and `node_insert` (score seed) — adding
  `comment_insert` on the same class stays within the module's declared responsibility for
  hot-score state. New `do_discovery:hot_score:{nid}` tag is properly namespaced. The consumption
  side (view render must attach the tag) is the one area to watch — the brief's advisory-hold
  trigger correctly flags "Views handler subclass or new controller wrapper beyond a simple hook +
  Cache::invalidateTags call" as an escalation. Trust the trigger.
- **Fix 3 (`/all-groups` `cache: type: none`):** Config-only, mirrors three existing sibling views.
  Zero architectural risk.
- **Test approach:** One Functional regression per gap (join→post visibility, comment→trending
  reorder, private-group membership→listing update) is the right shape — each gap has a single
  behavioral contract and does not need parametric coverage. Not over-engineered.

## Notes for O

None. Proceed to T(RED).

## Patterns referenced

- `docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php:122-157` — analogous
  `group_relationship_insert` hook with `group_membership` plugin-id discrimination.
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php:465-475` — existing
  `Cache::invalidateTags([userStreamCacheTag(...)])` idiom to mirror in Fix 1.
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` — existing hooks class,
  `#[Hook(...)]` attribute style, extension target for Fix 2.
- `docs/groups/config/views.view.{my_feed,trending,following_feed}.yml` — `cache: type: none`
  precedent for Fix 3.
- `docs/planning/perf/cache-audit-2026-07-24.md` §4 Rec 1/2/3 — fix guidance.

VERDICT: PASS
