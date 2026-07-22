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
