# Handoff-A: Phase 3 - do_streams engine scaffold (issue #109)  (up-front plan review)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Brief reviewed:** `docs/handoffs/109-do-streams-scaffold/brief.md`   **Reuse map:**
`docs/handoffs/109-do-streams-scaffold/survey.md`   **Wireframe:**
`docs/handoffs/109-do-streams-scaffold/wireframe.html` + `handoff-D.md` (approved)
**Verdict:** PASS

## Summary

The plan is consistent with the module's established patterns. Ranking is correctly scoped as a
`views_query_alter` extension of the `do_group_pin` *pattern* (new module, same technique) rather
than a fork of the object itself, and the plan correctly calls `DoGroupPinHooks::streamCacheTag()`
(verified `public static`) only for the pinned-first case while defining a distinct per-user tag
for membership/following scope ([W-4]) — the right architectural split, not a naming nit. The two
scope plugins are justified new Views plugin infrastructure (no `Plugin/views/` precedent exists
anywhere in this codebase — verified by `find`), matching the issue's own framing. Module shape
(`src/Hook/DoStreamsHooks.php`, `#[Hook]` attributes, `*.services.yml` with `autowire: false` +
`hook_implementations` tag, `.module` as a docblock pointer only) mirrors `do_group_pin` and
`do_discovery` exactly. No layering, dependency-direction, or naming drift found.

## Findings

No `block` findings. Plan is consistent with existing patterns.

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | [B-6] GROUP BY column enumeration | pattern consistency | `DoGroupPinHooks::queryViewsGroupContentStreamAlter()` identifies the relationship-side column to aggregate by a **hardcoded alias string** (`group_relationship_field_data_node_field_data`). The brief's [B-9] membership-scope join is a raw EXISTS subquery (no Views relationship, so no relationship-id column enters the SELECT list at all) — good, this avoids needing that alias-matching trick for scope. But the **following-scope** join (if F implements it via LEFT JOINs per [W-1]'s fallback allowance rather than EXISTS) *would* reintroduce a relationship/join-side column needing the same alias-based aggregate treatment, and do_streams' compiled-query alter will need its own alias(es) for the follow-join table(s) and the hot-score `score` column — none of these aliases are known until F writes the view. Not blocking (T's tests assert results, not aliases), but flag for T/F: the compiled query-alter hook must discover its target columns generically (e.g. by table name membership) rather than copy-pasting `do_group_pin`'s exact alias string, since do_streams' join set differs. | T's kernel tests should include a fan-out case per join type actually implemented (relationship-shaped following-scope, if chosen) so a wrong/missing alias fails loudly rather than silently under-deduping. |
| 2 | warn | Cache tag naming ([W-4]) | naming/pattern consistency | `do_group_pin:group_stream:<gid>` (existing) vs. the brief's proposed `do_streams:user_stream:<uid>` — different module prefix, different scope noun (`group_stream` vs `user_stream`), same shape (`<module>:<scope-noun>:<id>`). This is the correct call (W-4's reasoning is sound: per-group and per-user are genuinely different invalidation scopes and must not share a tag), and the naming pattern itself is consistent with the one existing precedent. No fix needed — noting only because it's a **new** tag namespace, not literally the same string family, so T/F should not be tempted to prefix with `do_group_pin:` for "consistency"; `do_streams:` is correct. | None — confirm F emits `do_streams:user_stream:<uid>` (not `do_group_pin:...`) when writing the cache-tag hook. |
| 3 | warn | [B-2] ranking-as-argument mechanism | contract shape | The brief specifies ranking arrives via "`$view->args` or `$view->exposed_data`" (an either/or, deferred to whichever demo view do_streams ships). `do_group_pin`'s only precedent reads `$view->id()` to gate its hook, not an argument value, so there's no existing precedent in this codebase for a hook branching on a Views argument/exposed-data value to select behavior. This is a reasonable, Views-native mechanism (either is a standard Views API), but since it's genuinely new to this codebase, T's test-first suite should pin down which one (`args` vs `exposed_data`) is actually used, since the brief leaves it open and F could pick either without violating the brief. Not blocking — the brief explicitly defers the exact mechanism to "whichever demo/proof view do_streams ships," so this is a legitimate open implementation choice, not drift. | T should assert against a concrete choice once F/T settle on `args` vs `exposed_data` in the RED phase, so the contract downstream stories (#110-115) consume is unambiguous, not just "either works in the demo." |
| 4 | warn | [B-8] "Trending = Global scope + hot ranking" | abstraction level | Correctly identified as *not* a third scope plugin (no new Plugin class), just a shell-level tab-to-parameter mapping. This keeps the plugin count at exactly 2 scope plugins as the POC-scope guardrail requires. Confirmed no over-build risk here — flagging only as a positive confirmation, not an issue. | None. |

## Notes for O

Not applicable (PASS).

## Patterns referenced

- `docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php` — `views_query_alter` /
  `query_views_<id>_alter` / `views_post_render` + entity-insert/delete cache-tag pattern;
  confirmed `streamCacheTag()` is `public static`.
- `docs/groups/modules/do_group_pin/tests/src/Kernel/PinnedStreamOrderingTest.php` — kernel
  view-execution test precedent (fixture-config install via `FileStorage`, `Views::getView()`
  execute-and-assert-on-`$view->result` pattern) that T's `StreamsEngineTest.php` will mirror.
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` — `views_data` relationship
  exposure for `do_discovery_hot_score`, confirming "hot" ranking is a consume-only join.
- `docs/groups/modules/do_group_pin/do_group_pin.services.yml` + `.info.yml` + `.module` and
  `docs/groups/modules/do_discovery/do_discovery.services.yml` + `.info.yml` — confirmed the
  proposed `do_streams` module scaffold (`src/Hook/DoStreamsHooks.php`, `autowire: false` +
  `hook_implementations` tag service registration, docblock-only `.module` file) matches the
  sibling-module cadence exactly.
- `docs/groups/config/views.view.group_content_stream.yml` — confirmed relationship alias shape
  (`group_relationship_field_data_node_field_data`) that finding #1 above references.
- `config/sync/field.field.node.{page,event,forum,documentation,post}.field_group_tags.yml` +
  `docs/groups/config/flag.flag.follow_{content,user,term}.yml` — confirmed [B-4]'s field-name
  claim and the three flags' distinct `entity_type` targets underlying [B-9]/following-scope.
- `docs/groups/modules/do_tests/tests/src/Kernel/GroupsKernelTestBase.php` — confirmed as the
  correct test base per [B-5]/survey §Testing approach.
- `find . -path "*/src/Plugin/views*" -type d` returned zero results — confirmed the brief's claim
  that do_streams' two scope plugins are genuinely first-of-kind in this codebase, and `find
  docs/groups/modules -maxdepth 3 -type d -name schema` returned zero — confirmed no config schema
  precedent is being skipped (do_streams introduces no new config entity type needing one).
