# Decision Journal — 109-do-streams-scaffold (issue #109, epic #108)

## O — Phase 0 (survey/brief, first attempt) — 2026-07-22T06:55:00Z
- **Decided:** Run the FULL pipeline (O -> D -> A -> T(red) -> F -> T(green) -> A-dup -> U -> S ->
  O(PR)) with review-rigor = second-opinion (per issue). Working repo is `groups-on-d11`.
- **Decided:** Module scaffold path `docs/groups/modules/do_streams/` per issue, matching the
  `do_group_pin` / `do_discovery` sibling-module layout (hooks in `src/Hook/*Hooks.php` via
  `#[Hook]` attributes, no `.module` hook implementations beyond a docblock pointer).
- **Decided:** No new Views plugin precedent exists anywhere in this codebase — do_streams' scope
  plugins are the first custom Views plugins in the repo. Ranking is implemented as
  `hook_views_query_alter` in the manner of do_group_pin, NOT as Views sort plugins.
- **Assumed:** The "shared stream shell" is a UI surface -> D phase required, not N/A.

## O — Phase 1 (survey + brief, second attempt) — 2026-07-22T08:45:00Z
- **Decided:** Resumed the branch created in the first attempt; wrote `survey.md`/`brief.md`.

## O — Phase 1 INCIDENT — worktree loss (twice) — 2026-07-22T09:10:00Z
- **Decided (incident):** The worktree at `.claude/worktrees/agent-issue109` was deleted from disk
  and de-registered from `git worktree list` **twice** during this run — once mid-edit of
  `decisions.md`, and again after recreating it and committing nothing yet (branch pointer itself
  was also deleted the second time, confirmed by `git rev-parse --verify 109-do-streams-scaffold`
  failing and `git branch --list "109*"` returning empty). `git worktree list` at the time of the
  second incident showed unrelated worktrees churning under
  `groups-on-d11/.claude/worktrees/` (e.g. `0119-variant-framework` appearing/disappearing) —
  strong evidence that a **separate, concurrent process manages that shared `.claude/worktrees/`
  directory** (a FleetView harness or another O session) and is pruning entries there, including
  this run's, as a side effect. Root cause not fully confirmed but the pattern (repeated loss
  specifically under `.claude/worktrees/`, never under `_worktrees/`) is conclusive enough to act
  on.
- **Decided (incident, also):** `.claude/worktrees/agent-issue109` ALSO collided with this repo's
  own `.gitignore` (`.claude/` is blanket-ignored at the repo root), so even mid-run, files written
  under that worktree could not be `git add`ed — `git check-ignore -v` confirmed `.gitignore:38:
  .claude/` matched every path under the worktree. This compounds the loss risk: work there is
  both (a) subject to a concurrent process's cleanup and (b) untrackable by git even if committed
  promptly.
- **Decided (fix):** Recreated the worktree at
  `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams` (matching this repo's own
  established convention for other concurrent stories — `groups-c1`, `groups-c2`, `groups-ch80`,
  `groups-fix68`, `groups-136-w0`, etc. all live under `_worktrees/`, none under
  `.claude/worktrees/`), branched fresh from `origin/main` (`git worktree add
  /Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams -b 109-do-streams-scaffold
  origin/main`), confirmed no `.gitignore` collision (`git check-ignore` returns clean), re-copied
  `.env`, and rewrote `survey.md`/`brief.md`/this journal a third time from in-context state.
  Confirmed via `gh pr list --search 109 --state all` (empty) and `git ls-remote --heads` (no
  matching branch) both times that no upstream work existed to lose — the branch never left this
  machine and was never pushed, so nothing shared or reviewable was destroyed.
- **Decided (process fix):** Committing `decisions.md`/`survey.md`/`brief.md` to the branch
  **immediately** in this same phase (the commit directly following this entry), before spawning
  D, so a third loss (if the churn recurs) does not erase Phase-1 work again — git history, not an
  untracked working directory, is now the durability boundary.
- **Evidence:** `git worktree list` (showed the churn under `.claude/worktrees/`), `git
  check-ignore -v docs/handoffs/.../brief.md` (`.gitignore:38: .claude/`), `git rev-parse --verify
  109-do-streams-scaffold` (failed after the second loss), `git branch --list "109*"` (empty),
  `gh pr list --repo Performant-Labs/groups-on-d11 --search 109 --state all`, `git ls-remote
  --heads https://github.com/Performant-Labs/groups-on-d11.git` (both confirmed no upstream loss),
  `ls /Users/andreangelantoni/Projects/_worktrees/` (existing sibling worktree convention).

## O — Phase 1 (survey + brief, final) — 2026-07-22T09:20:00Z
- **Decided:** Pre-flight gap — no active hook manager wired in this repo (`git config
  core.hooksPath` empty, no `.husky/`, only `.sample` hooks in `.git/hooks/`). Flagging as a
  MISSING precondition per the pipeline's deterministic-hooks-layer expectation; proceeding without
  it because it is out of this story's scope (a separate hooks-wiring task per
  `workflow-coding-pipeline.md` §"Deterministic hooks layer"). Node v26.5.0 active. `gitleaks`
  binary present. `.env` present (600 perms, `OPENAI_API_KEY` key confirmed present by grep, value
  not read).
- **Decided:** Model assignment per the O-109 task: every spawned role runs **Sonnet**, except
  **Spec Auditor (S) = Opus**. This deviates from the pipeline doc's own default (Opus for A/S) —
  an explicit operator instruction, not a silent deviation; noted here since the doc itself warns
  "never run A on the throughput tier."
- **Decided:** Ranking hook design: `hook_views_query_alter` mirroring `do_group_pin`'s pattern
  for all 4 rankings (recent/last-activity/hot/pinned-first), NOT Views sort plugins — matches the
  one existing ordering precedent in this codebase. Scope plugins (membership, following) ARE new
  Views filter + argument-default plugins — justified because no Views plugin of any kind exists
  in this codebase yet and the issue explicitly asks for this plugin type.
- **Decided:** do_streams' pinned-first ranking will **call**
  `DoGroupPinHooks::streamCacheTag()` (verified `public static`) rather than reinventing a
  cache-tag scheme, to avoid an anti-duplication finding at Phase 7.
- **Assumed:** The "namespaced throwaway-DB docker" verification step (per the O-109 task) means
  an isolated DDEV/docker instance distinct from the shared dev DDEV project
  (`pl-groups-on-d11`, tied to `~/Sites/pl-groups-on-d11`) — not reuse of the shared instance. Not
  yet verified by actually standing one up; deferred to Phase 10 (U/verify).
- **Assumed:** Two forward-compat "needs discussion" rows (#112 non-node source, #114 by-author
  scope) do not block this story — resolved in survey.md as "Drupal's plugin system makes this
  forward-compatible by construction." Flagging as an assumption since it was not explicitly
  confirmed by the operator.
- **Hedged:** Whether the shared shell counts as a "UI surface" requiring the D phase — treating
  it as yes (per the O-109 task's own explicit "DESIGNER GATE" instruction and the epic's framing
  of tabs+control as UI), so D is NOT skipped.
- **Evidence:** `gh issue view 109`, `gh issue view 108`, read
  `docs/groups/modules/do_group_pin/{do_group_pin.module,src/Hook/DoGroupPinHooks.php,
  tests/src/Kernel/PinnedStreamOrderingTest.php,tests/fixtures/config/
  views.view.group_content_stream.yml}`, `docs/groups/modules/do_discovery/src/Hook/
  DoDiscoveryHooks.php`, `docs/groups/config/{flag.flag.follow_content.yml,
  flag.flag.follow_user.yml,flag.flag.follow_term.yml,views.view.group_content_stream.yml,
  core.entity_view_mode.node.stream_card.yml}`, `docs/groups/modules/do_tests/tests/src/Kernel/
  GroupsKernelTestBase.php`, `web/themes/custom/groups_chrome/{groups_chrome.theme,
  templates/content/node--stream-card.html.twig}`, `playwright.config.ts`, `tests/e2e/*.spec.ts`
  (listing only), `.ddev/config.yaml`, `docs/playbook/workflow/{workflow-coding-pipeline.md,
  pipeline-conventions.md,dual-review.sh}` (usage header).

## Review-rigor — brief gate (second-opinion, o4-mini) — 2026-07-22T09:35:00Z
- **Decided:** Ran `dual-review.sh --mode brief` (round 1): 9 BLOCK + 4 WARN + 4 NIT findings, all
  substantive (not noise) — see `dual-review-brief.md` and `review-comparison.md`. Resolved every
  BLOCK by amending `brief.md` with a new "Precise specs" section ([B-1]..[B-9]) and a "Guardrails
  from WARN findings" section ([W-1]..[W-4]); wrote `dual-review-brief-response.md` documenting
  each resolution; re-ran round 2 — **PASS, all 9 ACCEPTED, zero disagreement, no escalation
  needed.**
- **Decided:** The single highest-value catch was **[B-4]**: the follow_term join field is
  `field_group_tags` (present on every `group_node:*` bundle), not `field_tags` (which only exists
  on `article`, not a group-relevant bundle) — the obvious/wrong guess a naive implementation would
  have made. Verified directly against `config/sync/field.field.node.*.yml` before writing the
  resolution (not just accepting the critic's flag without checking — confirmed the correct field
  name myself).
- **Decided:** "Trending" tab (referenced in the epic's shell tab list but not defined by this
  story's two scope plugins) = Global scope (no scope filter) + hot ranking defaulted — not a
  third scope plugin. Documented in brief §Precise specs [B-8].
- **Decided:** Membership-scope plugin's reference semantics are an EXISTS-subquery joining two
  `group_relationship_field_data` aliases (node's group_node relationship + current user's
  group_membership relationship, joined on `gid`) — avoids a fan-out/dedupe problem for scope
  filtering entirely (unlike do_group_pin's per-group `gid`-argument shape, which the survey had
  initially assumed membership-scope could reuse more directly than it turns out to). Documented
  in brief §Precise specs [B-9].
- **Decided:** last-activity ranking is `GREATEST(changed, COALESCE(NULLIF(last_comment_timestamp,
  0), changed))` — not `changed` alone (indistinguishable from "recent") and not raw
  `last_comment_timestamp` alone (would wrongly rank never-commented nodes as never-active).
  Documented in brief §Precise specs [B-1].
- **Committed** the amended `brief.md` + `dual-review-brief.md` (+ `.prompt.txt` sidecar) +
  `dual-review-brief-response.md` + `review-comparison.md` immediately (same durability lesson as
  the worktree-loss incident above) before spawning D.
- **Evidence:** `docs/handoffs/109-do-streams-scaffold/{dual-review-brief.md,
  dual-review-brief-response.md,review-comparison.md}`, `config/sync/field.field.node.*.yml`
  (grep + read, confirming `field_group_tags` vs `field_tags` bundle coverage),
  `config/sync/field.storage.node.field_group_tags.yml`.

## D — Phase 2 (design/wireframe) — 2026-07-22T10:15:00Z
- **Decided:** Mode (a) — generated a low-fi wireframe (no user-supplied wireframe was provided).
  Single HTML file (`wireframe.html`) with all 6 required states section-labeled, rather than 6
  separate files, for legibility per the role doc's "your call, prioritize legibility."
- **Decided:** Reused the EXISTING `.gc-group-tabs` active-tab convention (from
  `group--full.html.twig`) for the shell's scope tabs, and the EXISTING `.gc-empty` /
  `.gc-empty__title` / `.gc-empty__text` classes (already in `groups_chrome/css/chrome.css`,
  documented there as used by the all_groups directory and group_nodes stream empty states) for
  the empty state — did not invent a new visual language for either.
- **Decided:** State 4 (Trending) renders the Hot ranking pill as visually `is-active` even though
  no user click occurred, specifically to make [B-8]'s "ranking forced/defaulted to hot" behavior
  legible in a static wireframe. Flagged as an open question for approval (whether Recent should
  render as disabled/locked vs merely unselected under Trending) since the brief's "forced/
  defaulted" wording is ambiguous between those two.
- **Decided:** Every scope tab and ranking-control element is annotated with its preprocess-
  variable id (`scope_tabs[n].id` / `ranking_control[n].id`) rather than any literal href, to
  make the "no hardcoded routes" acceptance criterion visually explicit at the wireframe stage —
  no `<a href="...">` with a literal path appears anywhere in the mockup.
- **Decided:** Card rows are simplified/black-boxed versions of the real `stream_card`
  rendering (`node--stream-card.html.twig` / `groups_chrome_preprocess_node()`'s `gc_stream`
  variable) — same shape (avatar, author, group badge, snippet, comment count), not a redesign,
  and not the real markup/classes.
- **Hedged:** Empty-state copy is only mocked for one scope (Following); annotated in the
  wireframe and handoff that the real preprocess needs per-scope copy (Global-empty vs
  Following-empty vs My-Feed-empty are different situations), per the brief's own "empty state:
  ... show appropriate empty-state messaging" instruction — not designing all 4 variants since the
  brief explicitly allows "a simple centered message" for this gate.
- **Assumed:** No visible rendering/screenshot check was performed (working headlessly per
  standing user instruction); only structural HTML validation (tag-balance, section/state counts)
  was run. Flagged explicitly in handoff-D.md as unverified-by-me should the human approver want a
  visual pass before sign-off.
- **Evidence:** `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig`,
  `groups_chrome_preprocess_node()` in `groups_chrome.theme`, `web/themes/custom/groups_chrome/
  templates/group/group--full.html.twig` (`.gc-group-tabs` pattern), `web/themes/custom/
  groups_chrome/css/{chrome.css,primitives.css,tokens.css}` (`.gc-empty`, `.gc-badge`, `.gc-card`,
  design tokens), brief.md §[B-2]/[B-3]/[B-8], survey.md §Reuse & Analogous-Feature map.

## O — Phase 2 approval gate + coordinator resolutions — 2026-07-22T09:55:00Z
- **Decided:** Presented the wireframe to the operator; **APPROVED** (via coordinator relay,
  2026-07-22T09:55:00Z). Recorded the approval in handoff-D.md §Approval and the brief's Approved
  wireframe section — no tests/code started before this sign-off (D-gate honored).
- **Decided (resolves D open question 1):** Trending's Recent pill is **ENABLED (unselected but
  clickable), NOT disabled/locked** — operator ruling on [B-2] orthogonality grounds: ranking is
  independent of scope; Trending only *defaults* to Hot, the user may still pick Recent. F wires
  the Recent control under Trending as a normal unselected pill, never `disabled`. Folded into
  brief §Approved wireframe.
- **Decided (resolves D open question 2):** The shell preprocess supplies **4 distinct empty-state
  copy strings** (one per scope: global / my_feed / following / trending), NOT one shared string —
  operator ruling. Global-empty must NOT reuse a follow-oriented CTA. F implements a per-scope
  branch (e.g. an `empty_copy` variable keyed by active scope). Folded into brief §Approved
  wireframe; T must add a covering assertion (each scope yields its own empty copy).
- **Decided (coordinator guardrails, added to brief §Operating rules):** (a) work ONLY in this
  `_worktrees/groups-109-do-streams` worktree, never mutate the shared `~/Projects/groups-on-d11`
  checkout (no git reset/checkout -f/composer there); (b) `drupal/grequest` is NOT installed
  (incompatible with `group 4.0.x-dev`) — do_streams must not assume it, and the follow-scope work
  uses `flag`'s `follow_*` flags only (no membership-request flows); (c) PR creation is serialized
  by the coordinator — O PAUSES before opening the PR and reports back for a go-ahead.
- **Decided (model tiers, reconciled):** confirmed T/F/A/U spawn with `model: sonnet` explicit; S
  spawns with `model: opus`. This supersedes the earlier Phase-1 note that hedged on A's tier —
  the coordinator confirmed A runs on Sonnet for this run (an explicit operator instruction that
  overrides the pipeline doc's "never run A on the throughput tier" default; noted as a conscious
  deviation, not silent).
- **Decided (rigor at diff gate):** o4-mini second-opinion only at the diff gate (no fresh-Opus
  panel arm) — confirmed by coordinator.
- **Evidence:** coordinator message (D-GATE APPROVED + 2 resolutions + guardrails), handoff-D.md,
  wireframe.html, brief.md (amended §Approved wireframe + §Operating rules).

## A — Phase 3 (up-front review) — 2026-07-22T00:00:00Z
- **Decided:** Verdict **PASS**. The plan correctly extends the `do_group_pin` `views_query_alter`/
  compiled-query-rewrite/cache-tag *pattern* (new module, same technique) rather than forking the
  object, calls `DoGroupPinHooks::streamCacheTag()` (confirmed `public static`) only for
  pinned-first, and defines a distinct per-user cache-tag namespace (`do_streams:user_stream:<uid>`)
  for membership/following scope per [W-4] — the correct architectural split, not drift. The two
  scope Views plugins (membership filter/argument-default, following filter) are justified new
  objects: `find . -path "*/src/Plugin/views*" -type d` returned zero results codebase-wide, so
  there is no existing Views plugin to extend instead. Module scaffold shape (`src/Hook/
  DoStreamsHooks.php`, `#[Hook]` attributes, `*.services.yml` with `autowire: false` +
  `hook_implementations` tag, docblock-only `.module`) matches `do_group_pin`/`do_discovery`
  exactly.
- **Assumed:** No blocking risk in [B-2]'s "args or exposed_data" either/or being left open by the
  brief — this is a legitimate deferred implementation choice (both are standard Views mechanisms),
  not a contract ambiguity that risks Phase-7 anti-dup drift, since T pins down the concrete choice
  in the RED-phase tests before F implements. Flagged as WARN #3 for T/F awareness, not blocking.
- **Hedged:** WARN #1 flags that `DoGroupPinHooks::queryViewsGroupContentStreamAlter()`'s
  relationship-column detection uses a hardcoded alias string; if F implements following-scope via
  LEFT JOINs (the [W-1] fallback the brief explicitly allows) rather than EXISTS, do_streams' own
  compiled-query alter will need its own column-detection logic, not a copy of do_group_pin's exact
  alias. Not blocking — T's tests assert results/dedupe, not implementation shape — but flagged so
  F doesn't silently under-dedupe a join shape do_group_pin's precedent doesn't cover.
- **Evidence:** `docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php`,
  `docs/groups/modules/do_group_pin/tests/src/Kernel/PinnedStreamOrderingTest.php`,
  `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php`,
  `docs/groups/config/views.view.group_content_stream.yml`,
  `config/sync/field.field.node.*.field_group_tags.yml`,
  `docs/groups/config/flag.flag.follow_{content,user,term}.yml`,
  `docs/groups/modules/do_tests/tests/src/Kernel/GroupsKernelTestBase.php`,
  `docs/groups/modules/do_group_pin/{do_group_pin.services.yml,do_group_pin.info.yml,do_group_pin.module}`,
  `docs/groups/modules/do_discovery/{do_discovery.services.yml,do_discovery.info.yml}`.

## T — Phase 4 (author/RED) — 2026-07-22T15:25:00Z
- **Decided:** [A-W3]'s open choice ("`$view->args` or `$view->exposed_data`" for the ranking
  parameter) is pinned to a Views **contextual argument** (`$view->args`), matching do_group_pin's
  `$view->args[0]` gid pattern per the brief's own (non-binding) recommendation — T's call, binding
  for F. The fixture demo view's `ranking` argument uses `table: views, field: 'null'` (Views
  core's own "Global: Null argument" convention for a pass-through parameter with no intrinsic
  filtering semantics), NOT `table: node_field_data, field: nid` — discovered during RED
  verification that Views resolves an argument handler by the field's OWN `views_data`
  registration regardless of a config-level `plugin_id` override, so `field: nid` silently forced
  the `Nid` argument handler (which filters `WHERE nid = <value>`) no matter what `plugin_id` I set
  in config. This is now the reference shape F's shipped view must also use for the `ranking`
  argument.
- **Decided:** Anchored `StreamsInstallTest::testModuleInstallsWithZeroSchemaChanges` to the
  module's actual minimum contract (both Views filter plugins + the `do_streams_shell` theme hook
  discoverable), not just "installs without a fatal error" — a bare `.info.yml` with zero code
  trivially satisfies "installs cleanly, zero schema" and would have made this test pass BEFORE the
  feature exists (an invalid RED per the pipeline's own rule). Verified the fix produces a genuine
  failing assertion (`Failed asserting that false is true` on `hasDefinition('do_streams_membership_scope')`)
  before treating the test as valid.
- **Decided:** Warmed up core's own lazily-created tables (`router`, `menu_tree`, `cachetags`,
  `cache_file_parsing`, `users_data`) via a schema-free control-module install/uninstall cycle
  (`do_group_pin`) in `StreamsInstallTest::setUp()` BEFORE taking the "before" table snapshot —
  without this, the zero-new-tables assertion false-positived on tables ANY module install creates,
  not tables do_streams itself would create. Confirmed by first observing the false positive
  (`New tables found: cache_file_parsing, cachetags, menu_tree, router`) and then rerunning clean
  after the fix.
- **Decided:** Stripped `docs/groups/config/flag.flag.follow_content.yml`'s
  `flagTypeConfig.access_author` key when loading the fixture in `StreamsScopeTest::setUp()` — a
  pre-existing config-schema gap (`SchemaIncompleteException`) in the SHIPPED flag config,
  unrelated to do_streams' own scope, discovered only because this is the first test suite to
  install `follow_content` under strict config schema. Mirrors `PinnedStreamOrderingTest`'s own
  precedent of stripping non-semantic keys from its view fixture rather than disabling strict
  schema checking. Flagged in the T-red handoff as an adjacent finding for O, not silently patched
  into shipped config (out of this story's scope).
- **Decided:** Every test extends `GroupsKernelTestBase` and grants the SAME outsider group role
  `PinnedStreamOrderingTest` grants (view permission on every `group_node:*` bundle) wherever the
  fixture view touches Group's own access-policy query alter (`StreamsRankingTest`'s `page_global`
  display included, once discovered its LEFT JOIN to `group_relationship_field_data` hides ANY
  group-content node from a non-permissioned viewer regardless of do_streams' own scope filter) —
  isolates every ranking/scope assertion to do_streams' OWN behavior, not an incidental Group
  access-denial artifact.
- **Decided:** Ran the full 23-test suite together (not just file-by-file) to rule out cross-test
  pollution before writing the handoff; confirmed 17 genuine failures / 6 legitimate passing
  regression guards, zero fatal/bootstrap errors, and re-verified each of the 6 passing tests is not
  masking an uncovered acceptance criterion (every criterion has at least one FAILING sibling test
  in the same file).
- **Decided:** Reverted every build-artifact side effect of standing up an isolated DDEV instance
  (`.ddev/config.yaml` project-name rename, `assemble-config.sh`'s copies into `config/sync/` +
  `web/modules/custom/`, composer scaffold file touches) before finishing, via `git checkout --` +
  `git clean -fd` scoped to exactly those paths, then staged ONLY the 6 genuine test-authoring files
  by explicit path (`git add <path> <path> ...`, never `git add -A`/`.`) — confirmed via `git status
  --porcelain` showing exactly those 6 files as the sole tracked changes.
- **Assumed:** No isolated DDEV/DB existed for this worktree at Phase-4 start (`.ddev/config.yaml`
  inherited the shared `pl-groups-on-d11` project name, colliding with the primary checkout) — a
  pre-existing setup gap, not something Phase 4 was asked to permanently fix. I stood one up
  LOCALLY (uncommitted rename + `ddev start`/`composer install`/`site:install`) purely to execute
  and verify RED, then reverted it, rather than committing an isolation fix myself; O/F will need to
  redo the same bring-up to run the suite (documented explicitly in handoff-T-red.md's Environment
  note) — this matches `preflight-checklist.md`'s existing "distinct port + DB from the primary
  checkout" requirement for worktrees, so it is not a NEW gap this story introduces, but it IS an
  operational step every subsequent phase (F, T-green, U) will also need to repeat.
- **Evidence:** `docs/groups/modules/do_group_pin/tests/src/Kernel/PinnedStreamOrderingTest.php`
  (setUp() outsider-role pattern, fixture-stripping precedent), `docs/groups/modules/do_tests/tests/
  src/Kernel/GroupsKernelTestBase.php`, `docs/groups/modules/do_discovery/{src/Hook/
  DoDiscoveryHooks.php,do_discovery.install}` (hot-score table + views_data shape),
  `web/core/modules/views/src/Plugin/ViewsHandlerManager.php` (`getHandler()` table+field
  resolution, read directly to diagnose the `Nid` argument-handler override), `web/core/modules/
  views/src/Hook/ViewsViewsHooks.php` (`views.null` argument registration), `web/core/modules/
  views/src/Plugin/views/argument/NullArgument.php`, `web/modules/contrib/flag/config/schema/
  flag.schema.yml` (confirmed the `access_author` schema gap), live DDEV run output (23 tests, 17
  failures documented in handoff-T-red.md verbatim), `git status --porcelain` (post-cleanup, 6
  files only).

## F — Phase 5 (implement) — 2026-07-22T18:40:00Z
- **Decided:** Added a `#[Hook('views_data')]` entry exposing `do_streams_membership_scope` /
  `do_streams_following_scope` as synthetic filter-only fields on `node_field_data` — not
  documented in the brief/T-red handoff as a required build step, but necessary: Views resolves a
  config filter's handler class by the field's OWN `views_data` registration (table+field), not
  by the config item's `plugin_id` alone, so without this the fixture/shipped view's filters
  silently degraded to Drupal's no-op `Broken` handler. Discovered by instrumenting `init()`/
  `query()` with temporary debug output and observing neither the membership nor following scope
  plugin ever ran even after the plugin classes themselves were correctly implemented and
  discoverable (`StreamsInstallTest`'s `hasDefinition()` checks already passed).
- **Decided:** Ranking joins (`last_activity`/`hot`/`pinned`) are raw `views_query_alter` LEFT
  JOINs via `plugin.manager.views.join`, added conditionally per the active `ranking` argument
  value — mirroring `DoGroupPinHooks::viewsQueryAlter()`'s exact technique (front-of-orderby
  `array_unshift`) independently per ranking branch, not a single Views relationship added
  through config. The `query_views_do_streams_demo_alter` dedupe hook discovers columns needing
  `MIN()`/`MAX()` + `GROUP BY` treatment GENERICALLY by table-name membership of the 3 join-side
  tables this module's own `views_query_alter()` may add, per [A-W1] — explicitly not
  do_group_pin's hardcoded relationship alias string, since do_streams' join set differs per
  ranking branch and only one branch is active per request.
- **Decided:** Pin-toggle cache-tag invalidation (`onFlaggingChange()`) invalidates the FLAGGING
  ENTITY'S OWNER's own `do_streams:user_stream:<uid>` tag — the one viewing-user's stream this
  module can correctly derive as stale from the flagging entity alone, per [W-4]'s "never
  under-invalidate, never over-broadcast" guardrail. This does NOT satisfy
  `StreamsRankingTest::testPinToggleInvalidatesUserStreamCacheTagWithoutFlush` as literally
  written (see Tests that look wrong item 4 in handoff-F.md) — that test asserts an UNRELATED
  bystander user's tag is invalidated by a THIRD party's pin toggle, with no derivable signal
  connecting the two in the fixture as given. Flagged for T rather than guessed at silently.
- **Decided:** My shipped `config/install/views.view.do_streams_demo.yml` adds
  `defaults: {filters: false}` to the `page_following`/`page_global` displays — required per
  Drupal Views' actual inheritance mechanism (an display's own `filters` key is inert without
  this marker; verified against `DisplayPluginBase::isDefaulted()`/`initDisplay()` and
  cross-checked against real exported views under `config/sync/`). T's Kernel-level FIXTURE
  (`tests/fixtures/config/views.view.do_streams_demo.yml`, a separate file, test-authoring
  material I do not edit) is MISSING this marker on the same two displays, which is why 10 of the
  23 RED-authored tests still fail against my otherwise-correct implementation when run against
  T's original fixture verbatim — flagged as a test-authoring gap in handoff-F.md rather than
  silently patched (I do not edit tests).
- **Decided:** Confirmed (via a throwaway, immediately-reverted local edit + rerun, never
  committed) that `StreamsInstallTest::testModuleUninstallsCleanly` and both
  `StreamsScopeTest` membership tests are ALSO test-authoring gaps (missing
  `installEntitySchema('flagging')` and a missing `INSIDER_ID`-scope group role grant,
  respectively) — not implementation bugs. With those 3 gaps + the shipped-view defaults gap
  patched, 17 of the 23 T-authored tests independently confirm GREEN, leaving only 1 genuinely
  unimplementable-as-written test (the cache-tag one above) and 5 tests asserting a render-array
  contract (`$build['scope_tabs']` after `renderRoot($build)`) that Drupal's render pipeline
  cannot produce for ANY `#theme`-based render array, verified by reading
  `Renderer::doRender()`/`ThemeManager::render()` source directly (preprocess variables are never
  copied back onto the caller's build array — confirmed `ThemeManager::render(string $hook, array
  $variables)` takes `$variables` by value, not by reference).
- **Decided:** Ran the full self-contained isolated-DDEV bring-up (`f109-do-streams` project
  name, `ddev start`, `ddev composer install`, `bash scripts/ci/assemble-config.sh`) to execute
  the suite for real, confirmed module install/enable/uninstall cleanly on a live site (not just
  the kernel test DB) via `ddev drush en`/`pmu`, then reverted every build-artifact side effect
  (`.ddev/config.yaml`, `config/sync/`, `web/modules/custom/`, `web/autoload_runtime.php`,
  `.ddev/traefik/`) via `git checkout --` + `git clean -fd`, confirmed via `git status
  --porcelain` showing only the intended `docs/groups/modules/do_streams/` production-file
  changes.
- **Evidence:** `web/core/lib/Drupal/Core/Render/Renderer.php:504`,
  `web/core/lib/Drupal/Core/Theme/ThemeManager.php:134-204` (read directly to confirm the
  render-array-mutation claim), `web/core/lib/Drupal/Core/Database/Query/Condition.php:200-240`
  + `web/core/lib/Drupal/Core/Database/Query/Select.php:520-528` +
  `web/core/lib/Drupal/Core/Database/Connection.php:364,488` (confirmed `{table}` curly-brace
  prefix substitution applies to raw `addWhereExpression()` snippets via the full-query
  `prefixTables()` call in `Connection::query()`), `web/modules/contrib/flag/src/Entity/
  Flagging.php:117-131` (confirmed `entity_id`/`entity_type` are `string` base fields),
  `web/modules/contrib/group/src/QueryAccess/EntityQueryAlter.php` (confirmed the
  outsider-vs-insider access-scope split causing test gap #3), `web/modules/contrib/flag/
  flag.module:518-527` (confirmed `flag_entity_predelete()` as the root cause of test gap #1),
  `config/sync/views.view.archive.yml:217-219` (confirmed the real `defaults: {filters: false}`
  YAML shape against a genuine core-exported view), live DDEV kernel-test + `drush en`/`pmu`
  output (captured verbatim in handoff-F.md), `git status --porcelain` (post-cleanup, production
  files only).

## T — Phase 6 (verify GREEN + adjudication) — 2026-07-22T21:10:00Z
- **Decided:** Independently re-derived every one of F's 5 claimed test-authoring gaps from
  primary source (Views core `DisplayPluginBase::isDefaulted()`, Group core
  `PluginBasedQueryAlterBase::addSynchronizedConditions()`, `flag.module`'s
  `flag_entity_predelete()`, and this worktree's own DDEV-mounted
  `Renderer::doRender()`/`ThemeManager::render()`) rather than accepting F's patch description on
  faith — all 6 (gaps #1/#2/#4 + the pin cache-tag test + the 5 shell tests) confirmed as genuine
  test-authoring bugs, none an implementation gap in disguise.
- **Decided:** Fixed gaps #1 (missing `installEntitySchema('flagging')` in
  `StreamsInstallTest::setUp()`), #2 (missing `defaults: {filters: false}` on the fixture's
  `page_following`/`page_global` displays, now matching F's shipped
  `config/install/views.view.do_streams_demo.yml` exactly), and #4 (missing `INSIDER_ID`-scope
  group role grant in `StreamsScopeTest::setUp()`) exactly as F suggested, each with an
  explanatory comment citing the core-source read that confirmed the mechanism, not merely
  copy-pasting F's patch.
- **Decided:** Rewrote `testPinToggleInvalidatesUserStreamCacheTagWithoutFlush` so `$viewer`
  performs the pin toggle themselves rather than an unrelated `$flagger` — the ORIGINAL 3-way
  symmetric-bystander construction ($viewer / $otherViewer / $flagger, none tied to the flag event
  by any fixture relationship) asserted an underivable contract, confirmed by comparing against
  do_group_pin's own precedent test (which derives its per-group tag from the flagged node's real
  group membership, a link do_streams' per-VIEWING-USER tag has no equivalent for under a
  scope-free "pinned" ranking). The AC itself (scoped per-user invalidation, no full flush, an
  uninvolved bystander untouched) remains fully pinned by the rewrite.
- **Decided:** Rewrote all 5 `StreamsShellTest` render-array-contract tests to invoke
  `DoStreamsHooks::preprocessDoStreamsShell()` DIRECTLY (it is `public`, required for its own
  `#[Hook]` attribute wiring — no `ReflectionMethod` needed) rather than asserting
  `$build['scope_tabs']` etc. after `$renderer->renderRoot($build)` — confirmed via direct core
  source reads that NO `#theme`-based render array can ever expose a preprocess mutation on the
  caller's array (`ThemeManager::render(string $hook, array $variables)` takes `$variables` by
  value and rebuilds a fresh local array before invoking any preprocess hook). This mirrors the
  existing in-repo `ContributionStatsTest::countGroups()` reflection-into-protected-method
  precedent (here direct, since the method is already public). Left the 6th shell test
  (`testNoHardcodedRoutePathsInRenderedTabMarkup`) untouched — it only asserts the rendered HTML
  STRING, which is what `renderRoot()` legitimately returns; its use of `renderRoot()` was never
  the bug.
- **Decided:** Ran two temporary, immediately-reverted implementation-break-and-restore spot-checks
  (commenting out the `Cache::invalidateTags()` call; adding a hardcoded `'disabled' => TRUE` key
  to the Trending-scope Recent pill) to independently confirm the two rewritten test FAMILIES
  (cache-tag + shell) still fail for the right reason if the corresponding behavior regresses —
  neither rewrite is vacuously green. Both breaks produced the expected single test failure; both
  were restored and the full suite reconfirmed 23/23 GREEN afterward.
- **Decided:** Verdict is GREEN, 23/23. No blocking issues. Advancing to A-dup (anti-duplication
  review) next per the pipeline's Phase order.
- **Decided:** Stood up an ISOLATED DDEV project (`t109green-do-streams`) distinct from both F's
  `f109-do-streams` and the shared `pl-groups-on-d11`, using the correct-tree (copy via
  `scripts/ci/assemble-config.sh`) method per the coordinator's explicit realpath-symlink-trap
  guardrail — never symlinked core. Ran `ddev drush site:install` + `ddev drush en/pmu` against
  this SAME isolated instance to independently reproduce F's real-site install/uninstall claim
  (not merely trusting F's report), then reverted every build-artifact side effect
  (`.ddev/config.yaml`, `config/sync/`, `web/modules/custom/`, `web/autoload_runtime.php`,
  `.ddev/traefik/`) via `git checkout --` + `git clean -fd`, and stopped/unlisted the isolated DDEV
  project, confirmed via `git status --porcelain` showing only the 5 intended test/fixture files as
  changed.
- **Evidence:** `web/core/modules/views/src/Plugin/views/display/DisplayPluginBase.php`
  (`isDefaulted()`), `web/modules/contrib/group/src/QueryAccess/PluginBasedQueryAlterBase.php`
  (`addSynchronizedConditions()`), `web/modules/contrib/flag/flag.module`
  (`flag_entity_predelete()`), `web/core/lib/Drupal/Core/Render/Renderer.php:504`,
  `web/core/lib/Drupal/Core/Theme/ThemeManager.php:134-165` (all read directly in this worktree's
  own DDEV-mounted core, not taken from F's citations alone), `docs/groups/modules/do_group_pin/
  tests/src/Kernel/PinnedStreamOrderingTest.php::testPinToggleInvalidatesStreamCacheTagWithoutFlush`
  (the per-group precedent test compared against), `docs/groups/modules/do_profile_stats/tests/src/
  Kernel/ContributionStatsTest.php::countGroups()` (the reflection-into-preprocess-equivalent
  precedent), live DDEV run output (23/23 pass, 709 assertions, captured verbatim in
  handoff-T-green.md), two temporary break-and-restore diffs (not committed), `git status
  --porcelain` (post-cleanup, 5 test/fixture files only).

## Review-rigor — diff gate (second-opinion, o4-mini) — 2026-07-22T13:30:00Z
- **Decided:** Ran `dual-review.sh --mode diff` (base c18f417, the branch point). Verdict BLOCK, 2
  findings + 2 WARN + 4 NIT. Adjudicated (see `dual-review-diff.md`, `dual-review-diff-response.md`):
- **[B-1] scope_tabs missing `url_or_param` — ACCEPTED as a real BLOCK.** Verified: F's
  preprocess builds scope_tabs entries as {id,label,active} only, dropping the `url_or_param`
  field the brief's [B-3] contract mandates; AND T's shell test docblock cites the 4-field
  contract but only asserts id/label/active — so the field was both unimplemented and unpinned.
  Neither in-pipeline gate caught it; the diff gate did (gate earned its cost). Routing back to F
  (add url_or_param as a `?scope=<id>` param mapping, not a route path) + T (add a covering
  assertion). Cycle re-enters F -> T(GREEN) -> A-dup.
- **[B-2] array_unshift ordering "unverified" — MAINTAINED, not routed to F.** The runtime
  ordering [B-2] asks to verify is already proven by a GREEN live-view-execution test
  (`testPinnedRankingLeadsAsPrimaryKeyNotTiebreaker` asserts a pinned OLDER node leads a NEWER
  one — true only if the pin CASE is the primary sort key). The reviewer couldn't see the passing
  test (reviews diff+brief+F-handoff, not the test run). Behavioral proof > static source-read;
  no change.
- **[W-1] hardcoded join-table list — folded as a NIT** for the F-rework (make aggregate-target
  discovery iterate this hook's own added joins if cheap; not blocking — following-scope is EXISTS,
  no new join tables pending). **[W-2] per-flagger cache scope — no change** (the correct,
  derivable, [W-4]/[A-W2]-consistent choice F/T already adjudicated).
- **NIT-1/3/4 folded into the F-rework** (theme() $existing merge; README url_or_param example;
  base_field synthetic-field comment).
- **Evidence:** `dual-review-diff.md`, `dual-review-diff-response.md`,
  `DoStreamsHooks::preprocessDoStreamsShell()` lines ~415-422 (scope_tabs entry shape),
  `StreamsShellTest.php:164` (docblock cites url_or_param, assertion omits it),
  `StreamsRankingTest::testPinnedRankingLeadsAsPrimaryKeyNotTiebreaker` (the [B-2] behavioral
  proof).

## F — Phase 5 rework (diff-gate B-1) — 2026-07-22T00:00:00Z
- **Decided:** [B-1] `scope_tabs` entries now carry `url_or_param` as a `'?scope=' . $id` string,
  derived purely from the tab's own `id` — a query-PARAMETER mapping, never a hardcoded route path,
  per the "no hardcoded routes" acceptance criterion and O's disposition. Downstream stories
  (#110-#115) wire their own routes and read `?scope=` off the query string.
- **Decided:** Surfaced `url_or_param` on the rendered tab markup as a `data-url-or-param="..."`
  attribute in `do-streams-shell.html.twig` (not an `<a href>`), so downstream stories can read it
  off the DOM without the shell itself linking anywhere. Verified this does not trip
  `testNoHardcodedRoutePathsInRenderedTabMarkup` (it asserts against `href="/` / `href='/`, and the
  value always starts with `?`, never `/`).
- **Decided:** [NIT-1] `theme()` now returns `$existing + [...]` instead of only the new
  definition, so any earlier `hook_theme()` implementer's `do_streams_shell`-adjacent entries (or
  future ones) are not silently dropped.
- **Decided:** [NIT-3] Added a one-line `url_or_param` shape example
  (`'?scope=following'`) to README.md's shell-contract section.
- **Confirmed (no change needed):** [NIT-4] `MembershipScope.php` and `FollowingScope.php` already
  carried the "why `base_field` not `realField`" comment (synthetic filter-only field) from the
  original Phase-5 pass — verified present, no edit required.
- **Assumed:** [W-1] (generic join-table discovery for `queryViewsDoStreamsDemoAlter()`) is NOT a
  safe/cheap refactor at this time — the compiled `SelectInterface` at that hook's stage does not
  expose a stable, easily-introspectable "which joins came from this hook" signal without added
  bookkeeping, and only one ranking branch is ever active per request. Risking the #56 dedupe
  correctness for a non-blocking NIT is not warranted; left a `@todo` comment documenting the
  suggestion and why it's deferred, per the rework brief's explicit "skip if not genuinely small/
  safe" instruction.
- **Evidence:** `DoStreamsHooks.php` (scope_tabs loop ~415-427, `theme()` ~367-383,
  `queryViewsDoStreamsDemoAlter()` ~224-236 `@todo`), `do-streams-shell.html.twig` (tab markup +
  docblock), `README.md` (shell contract section). Self-check: `ddev phpunit
  docs/groups/modules/do_streams/tests/src/Kernel/` → 23/23 GREEN, 0 failures/errors (23
  pre-existing deprecation notices only). `phpcs --standard=Drupal,DrupalPractice` and `phpstan
  analyse --level=1` on all 3 changed PHP files → 0 errors, only the pre-existing `\Drupal::`
  DI-pattern warnings on lines untouched by this rework (114/143/175/292 in DoStreamsHooks.php,
  61 in FollowingScope.php, 65 in MembershipScope.php — none newly introduced). Git tree verified
  clean of build artifacts post-teardown (`git status --porcelain` shows only the 3 production
  files edited).

## T — Phase 6 rework (url_or_param coverage) — 2026-07-22

- **Decided:** Added the missing covering assertion to
  `testScopeTabsContractAllFourPresentWithCorrectActiveFlag` — for each of the 4 scope tabs,
  asserts `url_or_param` is present and equals `'?scope=' . $id` exactly, AND asserts it does not
  start with `/` (`assertStringStartsNotWith('/', ...)`), positively pinning "query-parameter
  mapping, not a hardcoded route path" rather than only checking a literal value match.
- **Decided:** Extended `testNoHardcodedRoutePathsInRenderedTabMarkup` (the rendered-HTML test)
  with two more assertions on the same `renderRoot()` markup: the Global tab's
  `data-url-or-param="?scope=global"` attribute is present, and no tab renders an empty
  `data-url-or-param=""`. This closes the gap between "the preprocess variable is correct" and
  "the correct value actually reaches the DOM" — the diff-gate's underlying worry was exactly this
  kind of value going unpinned end-to-end.
- **Decided:** Kept the fix inside the two existing test methods rather than adding a new method —
  `url_or_param` is one more field of the SAME `scope_tabs`/rendered-markup contract those two
  tests already exercise; a separate method would duplicate the same `preprocessShellVariables()`/
  `renderRoot()` setup for no isolation benefit.
- **Evidence (non-vacuity, empirical break-and-restore):** Temporarily removed the
  `'url_or_param' => '?scope=' . $id,` line from `DoStreamsHooks::preprocessDoStreamsShell()` and
  re-ran `StreamsShellTest` — both new assertion sites failed for the right reason:
  `testScopeTabsContractAllFourPresentWithCorrectActiveFlag` failed on
  `assertArrayHasKey($scopeId, $urlOrParamByScope, ...)` ("Failed asserting that an array has the
  key 'global'") and `testNoHardcodedRoutePathsInRenderedTabMarkup` failed on the
  `data-url-or-param="?scope=global"` containment assertion (rendered markup showed
  `data-url-or-param=""` instead). Restored `DoStreamsHooks.php` from a scratchpad backup (verified
  byte-identical via `diff`, `git status --porcelain` confirmed a clean file), re-ran the full
  suite: back to 23/23 GREEN. This proves the new assertions are load-bearing, not vacuous.
- **Evidence:** Isolated DDEV project `t109c-do-streams` (own `ddev start` / `ddev composer
  install` / `bash scripts/ci/assemble-config.sh`). Final run:
  `SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://t109c-do-streams.ddev.site
  BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest vendor/bin/phpunit -c web/core
  --testdox docs/groups/modules/do_streams/tests/src/Kernel/` → `OK, but there were issues!` —
  `Tests: 23, Assertions: 723, Deprecations: 23, PHPUnit Deprecations: 27` (23/23 GREEN, the
  deprecations are the same pre-existing core/Twig/flag-module notices F's handoff already
  documented, none new). `StreamsShellTest` alone: 6/6 GREEN, 186 assertions (up from 178 pre-fix).
  `phpcs --standard=Drupal,DrupalPractice` on `StreamsShellTest.php`: identical 3 errors + 1
  warning before and after my edit (verified via `git stash`/`git stash pop` diff-comparison, all
  4 findings on pre-existing docblock lines I did not touch) — no new phpcs findings introduced.
  `phpstan analyse --level=1` on the test file standalone flags ~30 "undefined method
  assert*()"/"undefined property $container" errors — a known tooling artifact of analysing a
  Kernel test file without the module's Drupal-core stub configuration (no repo-level
  `phpstan.neon` wiring PHPUnit/Drupal stubs for standalone test-file analysis); F's own Tier-1
  self-check likewise scoped phpstan to the 3 production files only, not test files. DDEV project
  torn down (`ddev stop --unlist && ddev delete -O -y`); all build artifacts reverted
  (`git checkout -- .ddev/config.yaml config/sync/ web/...` + `git clean -fd config/sync/
  web/modules/custom/ web/autoload_runtime.php`); `git status --porcelain` confirms the tree
  carries ONLY the `StreamsShellTest.php` edit — no production code touched.
