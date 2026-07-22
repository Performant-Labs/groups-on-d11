# Survey — do_streams engine scaffold (issue #109, epic #108)

## Scope read

Issue #109 (`gh issue view 109`) + epic #108 (`gh issue view 108`). Phase 0 foundation for the
Streams epic — blocks all Phase-1 stories (#110-#115). POC framing: "renders correctly +
demo-credible + doesn't regress the suite," NOT production-hardened. Per the O-109 task, streams
are now sequenced as **beyond-MVP demo vision** (Wave 3) — the engine must be built cleanly but
scoped to what a POC needs, no speculative generality beyond the four rankings and two scope
plugins the issue lists.

## Files/config read (existing patterns this change interacts with)

- `docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php` — the **only** existing
  precedent for parameterized ordering over an existing Views query. Pattern: `#[Hook('views_query_alter')]`
  LEFT JOINs `flagging` onto `node_field_data`, computes a `CASE WHEN ... THEN 1 ELSE 0 END`
  order-by, and **must** `array_unshift` it to the front of `$query->orderby` (the historical #52
  bug: appending it made it a tie-breaker, never a real reorder). A second hook,
  `#[Hook('query_views_<view_id>_alter')]`, rewrites the compiled `SelectInterface` to aggregate
  (`MIN`/`MAX`) and `GROUP BY` the node's own columns so a relationship fan-out doesn't produce
  duplicate rows under `SELECT DISTINCT` (the #56 bug — `distinct: true` does not dedupe a
  relationship-side fan-out because the relationship's own entity id column re-enters the SELECT
  list after query-alter runs). A third hook, `#[Hook('views_post_render')]` +
  `#[Hook('entity_insert')]`/`#[Hook('entity_delete')]` on `flagging`, tags the view's render cache
  with a scoped tag and invalidates it on flag/unflag (the #69 bug — the view's own cache tags
  never covered a pin toggle).
- `docs/groups/modules/do_group_pin/tests/src/Kernel/PinnedStreamOrderingTest.php` — the kernel
  view-execution test pattern: extends `GroupsKernelTestBase`, installs `flagging` schema +
  `flag_counts`, installs the flag config + a **fixture copy** of the view config (not the shipped
  YAML — a fixture with only non-semantic fields stripped) via `FileStorage`, grants an outsider
  group role so a non-member current user can see the stream, executes the view with
  `Views::getView()->preExecute([$gid])->execute()`, and asserts on `$view->result`. This is the
  precedent this story's own kernel tests should follow for scope + ranking assertions.
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` +
  `do_discovery.services.yml` — the hot-score precedent: a `do_discovery_hot_score` table exposed
  to Views via `#[Hook('views_data')]` (adds a `nid`-joined base table with a `relationship` to
  `node_field_data`), recomputed on `#[Hook('cron')]`, seeded to 0 on `#[Hook('node_insert')]`. The
  issue's "hot ranking via do_discovery" means: add a Views **relationship** to
  `do_discovery_hot_score` (already joinable on `nid`) and sort by its `score` column — no new
  scoring logic, do_streams only *consumes* the existing table.
- `docs/groups/modules/do_group_pin/tests/fixtures/config/views.view.group_content_stream.yml` +
  `docs/groups/config/views.view.group_content_stream.yml` (shipped) — base_table
  `node_field_data`, `distinct: true`, a required `group_relationship` relationship, `gid` numeric
  contextual argument (`table: group_relationship_field_data`), `created DESC` sort, `status`
  filter. This is the existing single-group stream view. do_streams' **membership-scope** plugin
  is a generalization of this same relationship pattern but filtered to "any group the current
  user belongs to" rather than one `gid` argument — i.e. it needs the user's group membership list,
  not a single contextual gid.
- `docs/groups/config/flag.flag.follow_content.yml` (`entity_type: node`, `global: false`),
  `flag.flag.follow_user.yml` (`entity_type: user`), `flag.flag.follow_term.yml`
  (`entity_type: taxonomy_term`) — all three ship in `docs/groups/config/` (site-level, not a
  module's own config/install) AND are duplicated under
  `docs/groups/modules/do_notifications/config/optional/`. do_streams' following-scope plugin
  needs three different join shapes: `follow_content` joins `flagging.entity_id = node.nid`
  directly; `follow_user` joins `flagging.entity_id = <node's author uid>` (author flagged by
  viewer, not the node itself); `follow_term` joins through the node's tag reference field to
  `flagging.entity_id = term.tid`. These are genuinely different join shapes, not one pattern
  repeated three times — the plugin's OR-of-three-joins is real complexity, not just an
  N-times-do_group_pin repeat.
- `docs/groups/modules/do_tests/tests/src/Kernel/GroupsKernelTestBase.php` — the shared kernel
  test base (`community_group` group type, `group_node:<bundle>` relationship types for
  forum/documentation/event/post/page, `addNode()`/`addMember()`/`createGroup()` helpers). Every
  kernel test in this story must extend this, not `EntityKernelTestBase` directly, to inherit the
  self-provisioned fixture setup the epic mandates ("CI seed must not be assumed").
- `docs/groups/config/core.entity_view_mode.node.stream_card.yml` — the existing `stream_card`
  node view mode the issue says the shell "builds on."
- `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig` +
  `groups_chrome_preprocess_node()` in `web/themes/custom/groups_chrome/groups_chrome.theme` — the
  existing per-node stream-card rendering: a `gc_stream` variable (author, groups, snippet,
  comment count, etc.) assembled in `groups_chrome_preprocess_node()` gated on
  `$variables['view_mode'] === 'stream_card'`. **This card-per-node rendering is out of scope** —
  do_streams' shell wraps a *collection* of these cards (tabs + ranking control), it does not touch
  `groups_chrome_preprocess_node()` or the card template.
- No `Plugin/views/` directory exists anywhere in this codebase (`find` returned zero results) —
  do_streams' scope plugins are the **first custom Views plugins** in this codebase. Per the prior
  O session's decision (recorded in `decisions.md`), ranking stays as `hook_views_query_alter`
  (matching the one existing ordering precedent) rather than introducing a new Views sort-plugin
  pattern with zero precedent — but **scope** (membership / following) is a genuine Views
  **filter** concern (which rows appear at all, not their order), so it is implemented as Views
  **filter plugins** + a **contextual-argument default plugin** per the issue's own framing ("Views
  filter/contextual-argument default"), which IS new-precedent but is what the issue explicitly
  asks for and is the standard Views extension point for "which rows" logic.
- `.ddev/config.yaml` — DDEV project `pl-groups-on-d11`, MariaDB 11.8, PHP 8.4, docroot `web`. This
  is the underlying "docker" the epic's "namespaced throwaway-DB docker" verification step refers
  to; the shared dev DDEV instance is tied to the primary checkout
  (`~/Sites/pl-groups-on-d11`), so this run's verification must use an **isolated** DDEV/docker
  instance (distinct project name/DB), not the shared one, then tear down.
- `playwright.config.ts` + `tests/e2e/*.spec.ts` (`phase1-4.spec.ts`, `nav.spec.ts`,
  `directory-cards.spec.ts`) — `testDir: './tests/e2e'`, single worker, `BASE_URL` env override.
  Confirms `npx playwright test` runs against a live served site (DDEV or the isolated instance),
  not a mocked DOM.

## Reuse & Analogous-Feature map

- **Relevant code mapped:** `do_group_pin` (pin ordering + dedupe + cache-tag pattern over
  `views_query_alter`), `do_discovery` (hot-score table + Views `views_data` exposure),
  `views.view.group_content_stream` (the single-group stream view whose relationship/argument
  shape the membership-scope plugin generalizes), the three `follow_*` flags, `stream_card` view
  mode + `groups_chrome_preprocess_node()`/`node--stream-card.html.twig` (the card the shell
  wraps), `GroupsKernelTestBase` (test fixture base).
- **Closest analogous feature:** `do_group_pin` — implemented in
  `docs/groups/modules/do_group_pin/{do_group_pin.module,src/Hook/DoGroupPinHooks.php}`; objects:
  a hook-attribute class in `src/Hook/`, a `#[Hook('views_query_alter')]` method, a
  `#[Hook('query_views_<id>_alter')]` compiled-query rewrite, a `#[Hook('views_post_render')]` +
  entity-insert/delete cache-tag pair, a kernel view-execution test extending
  `GroupsKernelTestBase`.
- **Objects this change would touch:** new module `do_streams` (info/module/services/README); new
  `src/Hook/DoStreamsHooks.php` (ranking `views_query_alter`, following cache-tag invalidation
  mirroring do_group_pin's pattern); new `src/Plugin/views/filter/` (membership scope, following
  scope) and `src/Plugin/views/argument_default/` (membership-scope contextual-argument default,
  per the issue's own wording); a new demo/test Views config (`views.view.streams_demo.yml` or
  similar, since ST-1..6 own their *routes*, not this shell's exercising view — do_streams needs
  its own minimal view to prove scope+ranking work in isolation, matching the issue's acceptance
  criterion "Both scope plugins work in a **test view**"); a Twig template + preprocess for the
  shared shell (tabs + ranking control) parallel to `groups_chrome_preprocess_node()`'s pattern but
  new (no existing "stream shell" object exists to extend — see justification below); kernel tests
  under `docs/groups/modules/do_streams/tests/src/Kernel/` extending `GroupsKernelTestBase`.
- **Extend-vs-new recommendation:**
  - **Ranking wiring:** EXTEND the `do_group_pin` `views_query_alter`/compiled-query-rewrite
    *pattern* (same technique, applied to a new view/module) — not the do_group_pin *object*
    itself, since do_group_pin is scoped to the pin flag and a single shipped view; folding
    ranking into it would violate do_group_pin's own single-responsibility (pin ordering only) and
    couple two unrelated modules. This is "extend the pattern, new module" — justified because
    do_group_pin's `STREAM_VIEW_ID` constant and pin-specific hook names are hardcoded to one view;
    generalizing them to any do_streams-exercising view would require do_group_pin to know about
    do_streams' views, which the issue's "no schema changes / disjoint file ownership" framing does
    not ask for. do_streams reads the SAME pin flag (`pin_in_group`) for its own "pinned-first"
    ranking option, reusing `do_group_pin`'s cache-tag helper *by calling it*, not by duplicating
    its logic (see below).
  - **Pinned-first ranking specifically:** the issue says "reusing the `do_group_pin` pattern —
    mind the #52/#56/#69 findings." do_streams' `views_query_alter` for pinned-first ranking
    should structurally mirror `DoGroupPinHooks::viewsQueryAlter()` (front-of-orderby CASE
    expression) and its dedupe fix (aggregate + GROUP BY), and MAY call
    `DoGroupPinHooks::streamCacheTag()` (public static, already exported) rather than reinventing a
    cache-tag scheme — this is calling the existing object, not duplicating it. Verified
    `streamCacheTag()` is `public static` and safe to call from another module.
  - **Following-scope / membership-scope plugins:** NEW objects (Views filter + argument-default
    plugins) — justified because no Views plugin of any kind exists in this codebase yet; there is
    no "existing filter plugin" to extend, and the issue explicitly specifies this as new plugin
    infrastructure the whole epic is founded on.
  - **Shared stream shell (Twig + preprocess):** NEW template + preprocess function — justified
    because `groups_chrome_preprocess_node()`/`node--stream-card.html.twig` render a single node
    row, not a collection-with-chrome (tabs + ranking control); there is no existing "stream
    collection shell" to extend, and the issue explicitly frames this as new UI scaffold the
    Phase-1 stories attach to. The shell's preprocess/template MUST be built on top of (render
    node collections in) the existing `stream_card` view mode per the issue text, i.e. it reuses
    the card, not the per-node preprocess.

## Forward-compat check (this story creates a shared contract for #110-#115)

do_streams' engine contract `(scope, scope_arg?, sources[], ranking, presentation, page_size)` is
consumed by six downstream stories. Extracting each consumer's required capability from the epic
body:

| Consumer | Required capability | Satisfied by this scaffold? |
|---|---|---|
| #110 My Feed | scope=membership, ranking=recent/last-activity, presentation=shell+stream_card | yes — membership scope + recent/last-activity rankings + shell are all in this story's scope |
| #111 Following | scope=following (all 3 flag branches, deduped), presentation=shell | yes — following scope plugin explicitly required to dedupe across the 3 branches |
| #112 Events+RSVPs | a distinct **source** (event content + RSVP data), not just scope/ranking | **needs discussion** — the issue's `sources[]` parameter is named in the engine contract but this story's acceptance criteria only exercise `node` as a source (membership/following scope over nodes). #112 needs the engine to accept a non-default source cleanly. Flagging as an open forward-compat item (below). |
| #113 Trending | scope=global (all content, no scope filter) + ranking=hot | partially — "hot" ranking (do_discovery join) is in scope; a "no scope filter / global" mode must be a legal `scope` value alongside membership/following (the shell's tab list is literally `[Global \| My Feed \| Following \| Trending]`, so "Global" = no scope restriction is implicitly required) |
| #114 Profile activity | scope=by-author (a specific user's own content), not membership/following | **needs discussion** — a third scope shape (single-author) is not in this story's two named scope plugins. Likely satisfied by #114 supplying its own scope plugin later (this story does not need to build it), but the engine's `scope` parameter must be an extensible plugin type, not a hardcoded enum, for #114 to add a third value without modifying do_streams itself. |
| #115 Switcher chrome | consumes the shell's tabs/ranking-control markup/hooks directly (merge-gated on #110/#111/#113) | yes, as long as the shell's tab/control markup is stable and parameterized (not routes) per the issue's "no hardcoded routes" requirement |

**Resolution:** the two "needs discussion" rows (#112 source extensibility, #114 third scope
plugin) do not block this story — the issue's acceptance criteria only require membership +
following scope plugins and node-shaped sources; #112/#114 own their own scope/source work as
their own Phase-1 stories per the epic's dependency graph (they depend on #109 but are not asked
to be *satisfied* by #109 alone). The engine's plugin-manager-based design (Views filter/
argument-default plugins, discoverable via annotation/attribute — the standard Drupal plugin
extension point) is inherently open to a #112/#114 story adding a new scope or source plugin
without touching do_streams' own code, so the contract is forward-compatible **by construction**
(Drupal's plugin system), not by this story pre-building unused generality. No conflict found; no
halt needed.

## POC-scope guardrails (explicit, since "engine cleanly but keep POC scope" is a stated constraint)

- Build exactly the 2 scope plugins + 4 rankings + shell the issue lists. Do NOT pre-build a
  by-author scope plugin, a non-node source, or a plugin-manager abstraction beyond what Views'
  own filter/argument-default plugin annotation system already provides for free.
- No new database schema (issue + epic both state this explicitly; do_streams reads existing
  `group_relationship`, `flagging`, and the existing `do_discovery_hot_score`/`pin_in_group`
  tables/flags only).
- The demo/test view do_streams ships to prove the plugins work is inert scaffold (per the issue:
  "Ships inert; ST-1/2/4/6 attach it") — it is a fixture-grade proof view, not a user-facing route.

## Testing approach

Kernel view-execution tests (matching `PinnedStreamOrderingTest`'s pattern) extending
`GroupsKernelTestBase`, self-provisioning fixtures (multiple groups, memberships, follow flags,
hot-score rows) rather than assuming CI/demo seed data — required by both the issue's acceptance
bar and the epic's "CI seed must not be assumed" rule. Each acceptance criterion needs its own
assertion:
1. Membership scope returns only nodes in groups the current user belongs to (and excludes
   non-member groups' nodes).
2. Following scope covers `follow_content`, `follow_user`, `follow_term` each independently AND
   ORed together, deduped (no node appears twice even if it matches two branches).
3. Each of the 4 rankings produces the documented order (recent = created DESC, last-activity =
   changed/comment-stats DESC, hot = do_discovery_hot_score DESC, pinned-first = pin leads +
   dedupe, mirroring PinnedStreamOrderingTest's own assertions).
4. Pinned-first truly leads (not tie-breaker) and produces no duplicate rows — same #52/#56
   assertions PinnedStreamOrderingTest already makes, replicated against do_streams' own ranking
   hook.
5. Cache invalidation on pin toggle (mirrors `testPinToggleInvalidatesStreamCacheTagWithoutFlush`).
6. Module install/uninstall cleanly (standard Drupal module test, e.g. via
   `ModuleInstallUninstallTestTrait` or a simple `ModuleInstaller` kernel check) with zero schema
   changes.
7. Shell rendering: a preprocess/template unit-level test (or a Kernel test rendering the shell's
   render array and asserting the scope-tab and ranking-control markup appears, parameterized —
   no hardcoded route strings in the output).

DOM-level rendered verification (per the O-109 task) happens separately in the isolated
DDEV/docker + Playwright pass in Phase 10, not as a Kernel test — Kernel tests assert query
results and render-array shape; Playwright asserts what actually paints.
