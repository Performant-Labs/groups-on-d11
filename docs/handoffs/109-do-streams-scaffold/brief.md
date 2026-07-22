## Phase 1 — do_streams scaffold: engine scope plugins, ranking wiring, shared stream shell

**Branch:** `109-do-streams-scaffold` (worktree: `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams`, base
`origin/main`)
**Review-rigor:** second-opinion (per issue #109 — foundational, plugin-namespace/views-data/
template contract consumed by 6 downstream stories)
**Forward-compat:** done — see `survey.md` §Forward-compat check (2 "needs discussion" rows
resolved: not blocking, Drupal's plugin system makes the contract open-by-construction)
**Design (Phase 2):** wireframe required — the shared stream shell (scope tabs + ranking control)
is a UI surface per the epic framing. D wireframes it; human sign-off required before T/F.

### Objective

Create the `do_streams` module at `docs/groups/modules/do_streams/`: two Views plugins
(membership-scope filter + contextual-argument default; following-scope filter OR-ing
`follow_content`/`follow_user`/`follow_term`), ranking wiring for recent/last-activity/hot/
pinned-first via `hook_views_query_alter` (mirroring the `do_group_pin` pattern), and a reusable
Twig+preprocess stream shell (scope tabs + Recent/Hot control) built on the existing `stream_card`
view mode — shipped inert, ready for ST-1/2/4/6 to attach.

### Codebase survey & Reuse map

Read before writing code: `docs/handoffs/109-do-streams-scaffold/survey.md`

Closest analogous feature: `do_group_pin` (`docs/groups/modules/do_group_pin/`) — EXTEND its
`views_query_alter` + compiled-query-rewrite + cache-tag-invalidation **pattern** (new module,
same technique) for ranking; **call** its exported `DoGroupPinHooks::streamCacheTag()` static
helper for pinned-first cache-tag reuse rather than reinventing one. Membership-scope and
following-scope plugins are justified NEW objects — no Views plugin (filter or argument-default)
exists anywhere in this codebase yet; this story is the first.

Key findings:
- **#52/#56/#69 findings are mandatory reading for the ranking hook.** Any `views_query_alter`
  order-by MUST `array_unshift` onto `$query->orderby` (front-of-list = primary key), not append
  (append made pin_sort a no-op tie-breaker in the historical #52 bug). Any relationship-side
  fan-out MUST be deduped on the **compiled** query via a `query_views_<id>_alter` hook
  (aggregate + GROUP BY) — `distinct: true` alone does not dedupe a relationship fan-out (#56).
  Cache tags must be added in `views_post_render` and invalidated on the relevant entity
  insert/delete (#69) — a view's own cache tags do not cover a flag toggle.
- **do_discovery_hot_score is already Views-exposed** (`views_data` in
  `DoDiscoveryHooks::viewsData()`) with a `relationship` to `node_field_data` on `nid`. "Hot"
  ranking is a Views relationship + sort-by-`score`, not new scoring logic — do_streams only
  consumes this table, never recomputes it.
- **Following-scope is 3 genuinely different join shapes**, not one pattern x3:
  `follow_content` → `flagging.entity_id = node.nid`; `follow_user` → `flagging.entity_id =
  <node's author uid>`; `follow_term` → via the node's tag field → `flagging.entity_id = term.tid`.
  OR them together and dedupe (a node can match more than one branch).
- **No schema changes.** Reads only existing `group_relationship`, `flagging`,
  `do_discovery_hot_score` tables and the `pin_in_group` flag. Module must enable/uninstall
  cleanly.
- **Existing kernel test base is `GroupsKernelTestBase`** (`do_tests` module) — extend it, don't
  reinvent group/node/membership fixtures. Self-provision every fixture; do not assume the CI/demo
  seed exists (epic rule).
- **The `stream_card` view mode + its card template/preprocess are out of scope** — the shell
  wraps a *collection* of existing stream_card-rendered nodes (tabs + ranking control chrome); it
  does not touch `groups_chrome_preprocess_node()` or `node--stream-card.html.twig`.
- **POC scope discipline:** build exactly 2 scope plugins + 4 rankings + the shell. No by-author
  scope plugin (that's #114's own story), no non-node source, no speculative plugin-manager
  abstraction beyond what Views' own filter/argument-default annotation system provides.

### Approved wireframe

Pending — D produces it this phase. Path: `docs/handoffs/109-do-streams-scaffold/wireframe.*` +
`handoff-D.md`. Scope: the shared stream shell only — scope tabs `[Global | My Feed | Following |
Trending]` + ranking control `[Recent | Hot]`, rendered inert (no live routes; ST-1/2/4/6 wire
routes to it later). States to cover: default (Global/Recent selected), each tab active, each
ranking-control state, empty-result state (shell with zero cards), and the shell wrapping 1-3
`stream_card`-rendered nodes.

### Input documents

- [x] Issue #109 (`gh issue view 109`)
- [x] Epic #108 (`gh issue view 108`) — POC framing, dependency graph, Phase-0 blocking relationship
- [x] `docs/playbook/workflow/workflow-coding-pipeline.md` (this project's copy)
- [x] `docs/playbook/workflow/pipeline-conventions.md`
- [x] `survey.md` (this run)

### Acceptance criteria

- [ ] Membership-scope plugin (Views filter + contextual-argument default) returns nodes with a
      `group_relationship` row (`plugin_id LIKE 'group_node:%'`) in any group the current user
      belongs to; no new storage.
- [ ] Following-scope plugin ORs `follow_content` (flagged nid) + `follow_user` (author flagged by
      viewer) + `follow_term` (followed tag), deduped.
- [ ] All four rankings selectable and correct: recent (`created DESC`), last-activity (comment
      stats / `changed`), hot (join `do_discovery_hot_score` via the relationship `do_discovery`
      already exposes), pinned-first (`pin_in_group`, reusing the `do_group_pin` pattern — pinned
      truly leads/primary sort key, deduped, cache invalidated on toggle — the #52/#56/#69 fixes
      replicated, not re-broken).
- [ ] Shared stream shell (Twig + preprocess) renders scope tabs (Global | My Feed | Following |
      Trending) and a Recent/Hot control from parameters; no hardcoded routes; built on the
      existing `stream_card` view mode.
- [ ] Both scope plugins proven against self-provisioned fixtures in a test/demo view (CI seed
      must not be assumed).
- [ ] Zero schema changes; module enables/uninstalls cleanly; existing suite stays green.
- [ ] Rendered DOM verified in a namespaced, throwaway-DB DDEV/docker instance (isolated from the
      shared dev DDEV project) + local `npx playwright test` green against it (spec added under
      `tests/e2e/`); instance torn down after.

### Handoff locations

D writes:      `docs/handoffs/109-do-streams-scaffold/handoff-D.md` (+ `wireframe.*`)
A writes:      `docs/handoffs/109-do-streams-scaffold/handoff-A.md`
T-red writes:  `docs/handoffs/109-do-streams-scaffold/handoff-T-red.md`
F writes:      `docs/handoffs/109-do-streams-scaffold/handoff-F.md`
T-green writes: `docs/handoffs/109-do-streams-scaffold/handoff-T-green.md`
A-dup writes:  `docs/handoffs/109-do-streams-scaffold/handoff-A-dup.md`
U writes:      `docs/handoffs/109-do-streams-scaffold/handoff-U.md`
S writes:      `docs/handoffs/109-do-streams-scaffold/handoff-S.md`

### Operating rules

- Read the survey + Reuse map before writing any code; don't rely on the issue text alone.
- Extend the `do_group_pin` ranking **pattern** (new module, same technique) and **call**
  `DoGroupPinHooks::streamCacheTag()` for pinned-first cache-tag reuse. Membership/following scope
  plugins are justified new Views plugins per the survey.
- A parallel path duplicating `do_group_pin`'s ordering/dedupe/cache-tag logic instead of mirroring
  its pattern (or reinventing a cache-tag scheme instead of calling the exported helper) is an
  anti-duplication BLOCK at Phase 7.
- Implement against the failing tests authored in Phase 4 (test-first) — F writes no tests.
- Follow project conventions: `#[Hook]` attribute classes in `src/Hook/`, no `.module` hook
  implementations beyond a docblock pointer (per do_group_pin/do_discovery precedent).
- Stage files by explicit path, never `git add .`.
- No new database schema. No user-facing routes (Phase-1 stories own those) — the shell/plugins
  ship inert.
- Model assignment (per O-109 task, strict): every spawned role runs Sonnet, EXCEPT Spec Auditor
  (S) which runs Opus. Never let a role inherit Opus otherwise.
