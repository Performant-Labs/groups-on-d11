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
