# Brief — #251 Cache-tag gaps (PERF-3 audit fixes)

**Issue:** Performant-Labs/groups-on-d11#251
**Branch:** `251-cache-tag-gaps` (worktree `~/Projects/_worktrees/groups-cache-tag-gaps-251`)
**DDEV:** `gm251-cache`
**Review-rigor:** `none` (POC lean pipeline)
**Pipeline:** O → A → T(RED) → F → T(GREEN) → diff-gate → S → PR → self-merge (POC lean; skip D/U/A-dup/brief-gate)

## Objective

Close 3 cache-tag invalidation gaps identified by static audit
`docs/planning/perf/cache-audit-2026-07-24.md` (PR #249) in **ONE PR**.

## Survey — the audit report IS the survey

`docs/planning/perf/cache-audit-2026-07-24.md` §1 (coverage table), §2 Scenarios 2/3/4 (gap
evidence), §4 Recommendations 1/2/3 (fix guidance). No re-investigation needed; audit was
comprehensive and evidence-cited to file:line.

## Reuse & Analogous-Feature Map

**Extend, do not create new patterns.** Every fix has a direct analog already in the codebase:

| Fix | Analogous object to extend | File:line |
|---|---|---|
| **Fix 1: `/my-feed` on group join/leave** | `DoStreamsHooks` (already owns `userStreamCacheTag()` + `Cache::invalidateTags()` idiom at pin-toggle site). Analogous hook: `do_activity/src/Hook/DoActivityHooks.php:122-157` (`groupRelationshipInsert()` scoped to `group_membership` plugin id) | `do_streams/src/Hook/DoStreamsHooks.php:474` (existing invalidation call); `do_activity/src/Hook/DoActivityHooks.php:122-157` (analogous hook shape) |
| **Fix 2: `/trending` on comment_create** | `DoDiscoveryHooks` (already has `#[Hook('cron')]` + `#[Hook('node_insert')]`). Add `#[Hook('comment_insert')]` following the same class structure. Cache tag pattern: new `do_discovery:hot_score:{nid}` tag, invalidated by hook + consumed by view render. | `do_discovery/src/Hook/DoDiscoveryHooks.php` (existing hooks class); audit §4 Rec 2(a) |
| **Fix 3: `/all-groups` private-group filter** | `all_groups.yml` view YAML — audit §4 Rec 3(a) explicitly recommends **cache: type: none** as smallest-diff fix mirroring existing precedent in `my_feed.yml:141`, `trending.yml:115`, `following_feed.yml:118`. **Config-only change.** | `docs/groups/config/views.view.all_groups.yml`; precedents `my_feed.yml:141-143`, `trending.yml:115-117`, `following_feed.yml:118-120` |

**Reference pattern for defense-in-depth (audit §4 Rec 6):** `do_showcase` persona/variant contexts,
declared on both child render AND wrapping controller.

**Extend-vs-new recommendation:** All three fixes EXTEND existing objects. No new classes/services.
- Fix 1: new `#[Hook('group_relationship_insert')]` + `#[Hook('group_relationship_delete')]` methods on existing `DoStreamsHooks` class.
- Fix 2: new `#[Hook('comment_insert')]` method on existing `DoDiscoveryHooks` class + new tag consumed by view render (may need small controller wrap — advisory-hold if it needs a Views handler subclass).
- Fix 3: 1-line YAML change in `all_groups.yml` (`cache: type: none`).

## Acceptance criteria (from issue #251)

- [ ] Fix 1: `/my-feed` invalidates on group join/leave for that user (`do_streams:user_stream:{uid}` tag).
- [ ] Fix 2: `/trending` reflects new comment without waiting for cron.
- [ ] Fix 3: `/all-groups` reflects membership change for private groups.
- [ ] One regression test per fix (Functional):
  - my-feed: user joins group → new post in that group → appears without cache clear.
  - trending: forum node position N → add comment → position changes.
  - all-groups: private group membership change → listing updates.
- [ ] Assemble script passes; PHPCS clean.
- [ ] Kernel + Functional CI green on PR.

## Advisory-hold triggers

Pause and report to operator if:
- Fix 2 requires a Views handler subclass or new controller wrapper beyond a simple hook + `Cache::invalidateTags` call.
- Fix 3 requires a `hook_entity_predelete` addition or query-alter refactor beyond the YAML `cache: type: none` change recommended by audit §4 Rec 3(a).
- Any fix requires touching more than the analogous objects named above.

Per-gap split may be warranted if scope explodes.

## Handoff locations

`docs/handoffs/251-cache-tag-gaps/{brief,decisions,handoff-A,handoff-T-red,handoff-F,handoff-T-green,handoff-S}.md`
