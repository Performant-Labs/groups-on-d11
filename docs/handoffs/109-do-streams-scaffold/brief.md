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

### Precise specs (resolved at the review-rigor brief gate, dual-review round 1 — see
`dual-review-brief.md`)

- **[B-1] "Last-activity" ranking = `MAX(comment_entity_statistics.last_comment_timestamp)` with a
  fallback to `node_field_data.changed` when a node has no comments.** Core's
  `comment_entity_statistics` table (already LEFT-JOINed in `do_discovery`'s cron, same table) has
  a `last_comment_timestamp` column that is 0 when there are no comments — use
  `GREATEST(node_field_data.changed, COALESCE(NULLIF(comment_entity_statistics.last_comment_timestamp, 0), node_field_data.changed))`
  or equivalent (a `CASE` is also acceptable), ORDER BY that computed value DESC. Do not sort by
  `changed` alone (that's just "recent" again) or by raw `last_comment_timestamp` alone (a node
  with zero comments would sort as if never active).
- **[B-2] Ranking control mechanism:** a **Views exposed sort** is NOT the mechanism (the four
  rankings are not simple column sorts — hot/pinned-first require joins the exposed-sort UI can't
  express). Instead: the ranking is a **plugin-selected mode passed as a Views argument/parameter**
  that `DoStreamsHooks::viewsQueryAlter()` reads (e.g. a `ranking` value on
  `$view->args` or `$view->exposed_data`, wired by whichever demo/proof view do_streams ships) and
  branches on to build the corresponding ORDER BY. The shell's Recent/Hot control (Phase 2 UI
  surface) posts/links this parameter; the exact HTML control (buttons vs `<select>`) is D's call
  in the wireframe, but the underlying wiring is: shell control -> query parameter/argument ->
  `viewsQueryAlter()` branch. No hardcoded routes (existing acceptance criterion) means the control
  must work via a parameter the shell's own preprocess reads, not a per-ranking URL/route.
- **[B-3] Shared shell contract, explicit:**
  - Template: `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` (theme hook
    `do_streams_shell`, defined via `hook_theme()` in `DoStreamsHooks.php` or the module's
    `.module` docblock-pointer file).
  - Preprocess variables: `scope_tabs` (array of `{id, label, url_or_param, active: bool}` for
    Global/My Feed/Following/Trending), `ranking_control` (array of `{id, label, active: bool}`
    for Recent/Hot), `results` (a pre-rendered Views output render array, `#type => view` or the
    executed view's render array — the shell does not know how the results were queried, it only
    wraps them), `empty` (bool, true when `results` has zero rows, for the empty state D
    wireframes).
  - The proof/demo view do_streams ships (see B-7) has machine name `do_streams_demo`, display id
    `default`; its `gid`-style contextual argument (for the demo's membership-scope exercise) is
    named `uid` (current-user-relative, since membership scope is "the current user's groups," not
    a single group) — no `gid` argument on this view (unlike `group_content_stream`, which is
    per-group; do_streams' scope is per-viewing-user).
- **[B-4] follow_term join uses `field_group_tags`, NOT `field_tags`.** Verified in
  `config/sync/`: `field.field.node.{post,event,forum,documentation,page}.field_group_tags.yml`
  (entity_reference to `taxonomy_term`, vocabulary `group_tags`, cardinality unlimited) exists on
  every group-relevant bundle (`GroupsKernelTestBase::NODE_BUNDLES`); `field_tags` only exists on
  `article`, which is not a `group_node:*` bundle. The following-scope plugin's `follow_term`
  branch joins `node__field_group_tags.field_group_tags_target_id = flagging.entity_id` (join
  condition also constrained to `flagging.flag_id = 'follow_term'` and
  `flagging.entity_type = 'taxonomy_term'`). If a future bundle lacks this field, the join simply
  contributes no rows for that bundle — no error, since it's a LEFT JOIN in the OR.
- **[B-5] Test ownership/plan:** T (Tester, Phase 4/6) owns and writes ALL kernel tests per
  survey.md §Testing approach (7 numbered scenarios) — F writes zero tests, per the pipeline's
  standing rule (not a new rule this brief introduces, restating for clarity since the critic
  flagged ambiguity). U (Phase 8) owns the Playwright DOM-level spec under `tests/e2e/`. The kernel
  test file lives at
  `docs/groups/modules/do_streams/tests/src/Kernel/StreamsEngineTest.php` (mirroring
  `PinnedStreamOrderingTest.php`'s naming convention: `<Capability>Test.php`).
- **[B-6] GROUP BY columns, explicit:** mirroring `DoGroupPinHooks::queryViewsGroupContentStreamAlter()`
  exactly — group by every plain (non-aggregated) NODE column already in the SELECT list
  (`node_field_data.nid`, `node_field_data.created`, `node_field_data.changed`, and any other
  node-table field columns the demo view selects), and wrap every relationship-side / join-derived
  column in an aggregate: the `group_relationship` id in `MIN(...)`, the pin_sort CASE expression
  in `MAX(...)` (exactly as do_group_pin does), and the following-scope join's matched-flag
  indicator (if selected as a column) in `MAX(...)` too. The hot-score `score` column is NOT
  grouped/aggregated when hot ranking is active — it participates in the ORDER BY as
  `MAX(do_discovery_hot_score.score)` for the same ONLY_FULL_GROUP_BY reason as pin_sort (a node's
  hot score is a single value, so MAX is a safe passthrough, not a real aggregation choice).
- **[B-7] Test/demo view + E2E environment, explicit:**
  - The Kernel-level "test view" is a **fixture YAML** under
    `docs/groups/modules/do_streams/tests/fixtures/config/views.view.do_streams_demo.yml`
    (installed via `FileStorage` in test `setUp()`, exactly as `PinnedStreamOrderingTest` installs
    its `group_content_stream` fixture) — NOT shipped config, matching the do_group_pin precedent
    of a fixture-only view for Kernel proof.
  - The **shipped inert scaffold view** (what ST-1/2/4/6 attach routes to) DOES ship as
    `config/install/views.view.do_streams_demo.yml` in the module itself (per the issue's "Ships
    inert; ST-1/2/4/6 attach it") — same view definition, shipped for real so `npx playwright test`
    can render it via a **temporary, do_streams-owned test route** (`/do-streams/demo`, gated
    behind a permission or environment check, or simply left as a bare Views page display with no
    menu link — the issue says "no hardcoded routes" refers to the SHELL's parameterization, not
    to "the demo view may never have a page display"; a demo page display is exactly what lets U
    verify rendered DOM without ST-1..6 existing yet). **F decides between a page display vs a
    controller-rendered do_streams_shell; either is acceptable as long as it's inert (no nav link,
    no production route table entry beyond the demo path itself) and removable without breaking
    ST-1..6, which attach their OWN routes/displays, not this one.**
  - **E2E environment:** an isolated DDEV project distinct from the shared `pl-groups-on-d11` dev
    instance — O (this run, Phase 10/U) stands it up via `ddev config --project-name
    <namespaced-name>` in this worktree, `ddev start`, `ddev composer install`, `ddev drush
    site:install` (or restores a minimal fixture DB), enables `do_streams` + deps, runs
    `BASE_URL=<ddev-url> npx playwright test tests/e2e/do-streams-demo.spec.ts`, then `ddev stop
    --unlist && ddev delete -O` to tear down. This is an operational step for O/U, not something F
    or T need to script beyond the Playwright spec file itself.
- **[B-8] "Trending" tab = scope `global` (no scope filter) + ranking forced/defaulted to `hot`.**
  Per the epic's shell tab list `[Global | My Feed | Following | Trending]`: **Global** = no scope
  restriction (all published content, any ranking selectable) — this is a third, trivial "scope"
  value alongside membership/following: literally "apply no scope filter," which needs no new
  plugin (it's simply omitting the membership/following filter from the view). **Trending** = the
  same "no scope filter" base with the ranking control's `hot` option pre-selected/default (not a
  distinct scope plugin — it's Global scope + hot ranking, matching the epic's "Trending — 'Popular
  this week'" framing in story #113, which do_streams does not itself own but must make expressible
  with the parameters this scaffold defines). No new plugin work beyond documenting this
  scope/ranking combination in the shell's tab-to-parameter mapping.
- **[B-9] Membership-scope join chain, explicit:** `node_field_data` (base) INNER JOIN
  `group_relationship_field_data` ON `group_relationship_field_data.entity_id =
  node_field_data.nid AND group_relationship_field_data.plugin_id LIKE 'group_node:%'` INNER JOIN
  a second `group_relationship_field_data` instance (aliased, e.g. `group_relationship_field_data_membership`)
  ON `...gid = group_relationship_field_data.gid AND ...plugin_id = 'group_membership'` AND
  `...entity_id = <current user id>`. In Views terms: the membership-scope filter plugin adds (in
  `query()`) a subquery/EXISTS condition — `EXISTS (SELECT 1 FROM group_relationship_field_data
  gr_node JOIN group_relationship_field_data gr_member ON gr_member.gid = gr_node.gid WHERE
  gr_node.entity_id = node_field_data.nid AND gr_node.plugin_id LIKE 'group_node:%' AND
  gr_member.plugin_id = 'group_membership' AND gr_member.entity_id = :current_uid)` — is the
  simplest correct shape (avoids a fan-out multi-JOIN entirely, so no GROUP BY dedupe is even
  needed for scope filtering, only for ranking joins per B-6). F may implement via Views'
  relationship+filter combinator instead of a raw EXISTS if it produces the same semantics and
  passes T's tests; the EXISTS form is the reference semantics T's tests assert against.

### Guardrails from WARN findings (non-blocking, but F must not ignore)

- **[W-1]** Following-scope's 3-way OR: prefer `EXISTS`/subquery per branch (mirroring the B-9
  EXISTS shape) over three LEFT JOINs + `OR` in the WHERE clause where reasonable — avoids the
  fan-out/dedupe complexity entirely for scope (dedupe is still needed for the SELECT-list
  distinctness Views itself adds, but not for a join-caused row multiplication). If F finds JOINs
  clearer to implement correctly against T's tests, that's acceptable too — T's tests assert
  results, not implementation shape.
- **[W-2]** Hot ranking must LEFT JOIN `do_discovery_hot_score` (not INNER) — a node with no
  computed score yet (e.g. freshly published, before the next cron run) must still appear, sorted
  as if score 0 (`COALESCE(do_discovery_hot_score.score, 0)`), not excluded.
- **[W-3]** After adding any GROUP BY (B-6), manually verify pagination still returns correct
  total/page counts (do_group_pin's `query_views_<id>_alter` tag fires for both the main and count
  query per its own docblock — confirm do_streams' equivalent hook does too).
- **[W-4]** Cache tags must be scope-aware: the pinned-first cache tag do_streams emits/consumes
  should be `do_streams:<scope>:<scope_arg>:<view_id>` shaped (or reuse
  `DoGroupPinHooks::streamCacheTag()`'s per-group tag ONLY when scope is a specific group context;
  for membership/following scope, which is per-viewing-user, a per-user cache tag is needed
  instead — e.g. `do_streams:user_stream:<uid>`). Do not reuse a single global tag across all
  scopes, or a pin toggle in one user's following-scope stream would appear to also invalidate an
  unrelated user's membership-scope stream cache (over-broad) or, worse, under-invalidate if scopes
  share no tag at all.

### Approved wireframe

**APPROVED 2026-07-22T09:55:00Z** (operator, via coordinator). Path:
`docs/handoffs/109-do-streams-scaffold/wireframe.html` + `handoff-D.md`. Covers all 6 states,
reuses the existing `.gc-group-tabs` (scope tabs) and `.gc-empty` (empty state) patterns, and
annotates every control with its `scope_tabs[n].id` / `ranking_control[n].id` preprocess origin.

**Two D-gate resolutions F MUST implement (binding):**
- **Trending's Recent pill = ENABLED (unselected but clickable), NOT disabled/locked.** Ranking is
  orthogonal to scope ([B-2]); Trending only *defaults* the ranking to Hot — the user may still
  switch to Recent (yielding global+recent, harmless). Do NOT render the Recent control as
  `disabled` under the Trending tab; render it as a normal unselected-but-clickable pill.
- **Per-scope empty-state copy (4 distinct strings, not one shared).** The preprocess must branch
  on scope to supply scope-appropriate empty copy + CTA for `global` / `my_feed` / `following` /
  `trending`. The wireframe's Following copy is the model; F writes the other three. Critically:
  the Global empty state must NOT say "browse groups to follow" (that CTA only makes sense for
  My Feed / Following) — each scope gets truthful, scope-appropriate copy. Bake this into the
  shell preprocess as a per-scope branch feeding the `empty` render (e.g. an `empty_copy` variable
  keyed by active scope).

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
- **Worktree isolation (coordinator guardrail):** work ONLY in this worktree
  (`/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams`). NEVER mutate the shared
  `~/Projects/groups-on-d11` checkout — no `git reset` / `git checkout -f` / `composer` operations
  there. Commit early and often on this branch.
- **`drupal/grequest` is NOT installed** (incompatible with `group 4.0.x-dev`). do_streams must
  not assume it exists. The follow-scope work here uses the `flag` module's `follow_*` flags only
  — it does NOT touch group-membership *request* flows. If any part of the design appears to need
  grequest, STOP and raise it — do not add the dependency.
- **PR creation is serialized by the coordinator across the wave.** O PAUSES before opening the
  PR and reports back for a go-ahead — the pipeline runs T(RED) -> F -> T(GREEN) -> A-dup -> U -> S
  fully, then HOLDS at the PR step until the coordinator grants it.
