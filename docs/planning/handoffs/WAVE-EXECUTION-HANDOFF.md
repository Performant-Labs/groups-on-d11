# groups-on-d11 Demo — Wave Execution Handoff

**For:** the next coordinating agent picking up parallel story execution.
**Written:** 2026-07-22, after 3 foundation stories (#109, #119, #138) shipped & merged.
**Your job:** drive the remaining ~27 demo stories through the coding pipeline, in parallel,
as a *coordinator* — you do project management and gate approvals; role sub-agents write code.

---

## 0. TL;DR

- **Repo you're working in:** `github.com/Performant-Labs/groups-on-d11` (the deployable DEMO site).
  Local checkout: `~/Projects/groups-on-d11`. This is NOT the drupalcode contrib module.
- **Done & merged to `main`:** #109 `do_streams`, #119 `do_showcase`, #138 `do_group_membership`.
- **Remaining:** the rest of 4 open epics (#108, #117, #118, #137) — see §3.
- **How work is built:** the test-first coding pipeline (§4), run by background orchestrator
  agents you spawn and *tick forward* (§5). Sonnet for all roles, Opus only for the final audit.
- **The traps that cost hours this run are in §6 — read them before launching anything.**
- **A human (aangelinsf / André) merges every PR. You never self-merge.**

---

## 1. The two repos — do NOT conflate

- **groups-on-d11** (github.com/Performant-Labs/groups-on-d11) = the **deployable demo site**.
  All the story work below happens here. Normal GitHub flow: branch → push to `origin` →
  `gh pr create` → human merges. (This is a POC — a thing for people to play with, not the
  final product. Favor visible/playable/demo-credible; skip production hardening.)
- **groupsdrupalorg** (git.drupalcode.org, project 57124) = the real Drupal.org **contrib
  module + docs**. The demo *previews* its MVP. You do NOT push demo work there. Different repo,
  different rules (issue-fork/MR, never push to canonical). Leave it alone unless explicitly asked.

**Foundation the whole demo is built on:** Drupal 11 + `drupal/group` 4.0.x-dev — group entities,
group roles (permission mechanism), and a CUSTOMIZED `group_membership` relationship (extended
with a `field_membership_status`: active/pending/blocked). This is the **Group module**, NOT
Organic Groups (`drupal/og` is excluded). The MVP visibility model is **two axes**: visibility
(public/unlisted/private) + join_policy (open/request).

---

## 2. Current state of `main`

Merged (all by the human, aangelinsf):
- `#147` — #109 `do_streams`: shared stream shell (Twig+preprocess theme hook) + 2 Views filter
  plugins (membership scope, following scope) + ranking wiring. Ships **inert** (no route wires
  the shell yet — that's #110–115's job).
- `#148` — #119 `do_showcase`: `VariantSwitcher` service (accessible roving-tabindex radiogroup +
  arrow-key nav, no-JS `?variant=` fallback, per-variant cache context), `ShowcaseCatalog` +
  `/showcase` tour page, site-wide sessionStorage-dismissible **POC ribbon**.
- `#149` — #138 `do_group_membership`: Manage-members UI at `/group/{group}/members` (supersedes
  the stock group_members view), group roles (Organizer/Moderator), Groups-Moderate synchronized
  global role (`scope: outsider`), last-Organizer guard, real pagination.

`origin/main` is current. **Note:** the primary checkout `~/Projects/groups-on-d11` may be on a
stale HEAD — run `git -C ~/Projects/groups-on-d11 fetch origin` and check before using it as a
reference. Treat that checkout as **read-only**; all work happens in per-story worktrees (§8).

---

## 3. Remaining roadmap (4 open epics, ~27 stories)

Epics are all OPEN. None complete. Story numbers per the GitHub issue tracker.

**Epic #108 — Streams** (foundation #109 done): `#110`–`#115` (per-content-type stream stories),
`#116` (activity foundation), `#129` (rendering), `#130` (comparison toggle). #110–115 build on
#109's now-merged shell/engine.

**Epic #117 — Showcase** (foundation #119 done): `#120` (persona switcher — uses `drupal/masquerade`,
already in composer), `#121` (membership models enforced — **see grequest gotcha §6**), `#122`
(group-type homepages), `#123`–`#125` (discovery/directory variants incl. geo map — geofield +
geofield_map already in composer), and note `#134` (private group SC-7, visibility=private via
Group access). #120–125 build on #119's now-merged variant framework.

**Epic #118 — Self-describing demo** (NOTHING started): `#126`–`#128` (page ⓘ / card tooltips /
demonstrator seeds), `#131` (streams help), `#132` (showcase help), `#133` (**FINAL honesty
sweep — the ONLY story allowed to edit copy; everything else appends**).

**Epic #137 — MVP conformance** (one story #138 done): `#139` (multilingual + RTL), `#140` (Links),
`#141` (About), `#142` (directory location/language filters), `#143` (archive-restore), `#144`
(create→auto-organizer), `#145` (WCAG a11y audit — near-final).

**Wave/dependency ordering** (from the epic bodies):
- **W1 (foundation, most now unblocked):** #120, #121, #122, #126, #127, #128, #134. Note
  **#121 edits shared HelpText and should merge before other HelpText-touching stories** (or
  those stories must rebase after it). #126–128 (help) touch shared HelpText too — sequence or
  rebase to avoid HelpText.php merge conflicts.
- **W2 (MVP must-haves):** #110–#115, #139–#144.
- **W3 (beyond-MVP, gated):** #116, #123–#125, #129, #130, #132.
- **W4:** #131. **Final:** #133 (honesty sweep) + #145 (WCAG audit) — these go LAST.
- **Build-time rule on every feature story:** help/tooltips ship WITH the feature.
- **Shared-file contention:** `do_chrome/src/HelpText.php` is append-only and touched by many
  stories → serialize or rebase HelpText-touching stories; don't run 5 of them blind-parallel.

Verify each story's real acceptance criteria with `gh issue view <n> --repo Performant-Labs/groups-on-d11`
before building — the roadmap above is orientation, the issue is the spec.

---

## 4. The coding pipeline (how ONE story is built)

Reference doc: `~/Projects/playbook/workflow/workflow-coding-pipeline.md` (+ `pipeline-conventions.md`).
Also vendored in the repo under `docs/playbook/workflow/`.

Phase order (an orchestrator agent runs this per story):
```
O → D(UI stories only) → A(up-front plan review) → T(author RED) → F(implement) →
T(verify GREEN) → o4-mini diff gate → A-dup(anti-duplication) → U(UI walkthrough, UI stories) →
S(spec audit) → (pre-PR hold) → PR
```
- **Test-first:** T authors the failing suite and proves RED *before* F writes code.
- **Model tiers (the user is token-constrained — enforce this):** O, D, A, T, F, U = **Sonnet**
  (spawn with `model: "sonnet"` explicitly). **Only S (Spec Auditor) = Opus.** Nothing inherits
  the session's Opus by default.
- **Review rigor = second-opinion:** `docs/playbook/workflow/dual-review.sh` runs **o4-mini** at
  the brief gate and the diff gate. `OPENAI_API_KEY` is in the repo `.env` / environment.
  Do NOT add a fresh-Opus panel arm (too costly) — single o4-mini is the rung here.
- **Agent types available** (Agent tool `subagent_type`): `orchestrator`, `designer`,
  `architecture-reviewer`, `tester`, `feature-implementor`, `playwright-ui-walkthrough`,
  `spec-auditor`. Spawn one `orchestrator` per story; it spawns the rest.
- **Two human/coordinator gates** per story: (1) the **D-gate** — review the wireframe before
  T/F; (2) the **pre-PR hold** — review the one-shot summary before the PR is opened. You (the
  coordinator) fill these; for a POC you can approve sound wireframes yourself and surface only
  genuinely user-facing design choices.

The pipeline caught real defects every run (wrong field names, decorative pagers, dead routes,
WCAG gaps, cache bugs, a11y defects). It works. Trust it; don't skip the diff gate, U, or S.

---

## 5. Coordinator operating model (CRITICAL — this is what went wrong early)

You spawn each story's orchestrator as a **background agent** (`Agent` tool,
`run_in_background: true`, `subagent_type: "orchestrator"`, `model: "sonnet"`). Then:

**Background orchestrators PARK after every sub-agent and do NOT self-resume.** They need a wake
message from you (`SendMessage` to their `agentId`) to advance. The two notification kinds:
- **"parked, waiting for <child>"** → the child (T/F/etc.) is still running. **Do nothing.**
- **child-completion notification** (e.g. "F — implement … finished") → **wake the parent**
  (`SendMessage` to the orchestrator's agentId) so it commits that phase and launches the next.

An orchestrator's `agentId` == its task-id (they match). Resume with:
`SendMessage({ to: "<agentId>", message: "<instructions>" })`.

**The failure mode to avoid:** treating child-completion notifications as "no action needed."
Early this run I did that and **3 pipelines stalled parked for ~3 hours.** Symptom: no new commit
+ no heartbeat for a long stretch. If you suspect a stall, check the worktree's last commit age
and its handoff dir; if a child clearly finished but the parent is parked, wake it.

**Tell each orchestrator up front:** "run autonomously to the pre-PR hold; after each sub-agent,
commit that phase and immediately launch the next, and post a one-line 'parked after X, launching
Y' so I can tick you forward promptly."

**You own the PR loop end-to-end** (this is non-negotiable per the user's global instructions):
after an orchestrator opens a PR, **poll its CI to green yourself, read every finding (not just
the pass/fail), and act** — don't hand back "PR opened, CI running." When CI fails, read the log,
route the fix, re-verify on the real path. Only surface to the user when it's actually merge-ready.
(Bash calls are hard-capped at ~2 min here — poll CI in short `run_in_background` bursts under
that cap, or re-check opportunistically between agent notifications. There is no PR-review bot on
this repo; CI = build.yml + test.yml only.)

---

## 6. Hard-won gotchas — READ THESE (each cost real time this run)

1. **CI runs tests from the ASSEMBLED layout, not source.** Modules live in
   `docs/groups/modules/do_*` (source of truth); `scripts/ci/assemble-config.sh` copies them to
   `web/modules/custom/do_*` and CI runs PHPUnit from THERE. A test that reads shipped config by a
   source-relative path (e.g. `new FileStorage(__DIR__ . '/../../../../../config')`) passes locally
   but fails in CI (`create(false)` TypeError). **Fixtures must be module-local
   (`tests/fixtures/config/`).** Always verify a suite by running phpunit from the assembled
   layout, not the source tree.
2. **Edit ONLY `docs/groups/` source.** `web/modules/custom/` and `config/sync/` are regenerated
   build artifacts (gitignored, never committed in feature commits). F editing the assembled copy
   = fix ships nowhere and gets overwritten. Feature commits are source-only (matches #91/#84/#85).
3. **`drupal/grequest` is UNUSABLE on Group 4.0.x** (it requires group ^3.0; no 4.x port). So the
   MVP `join_policy = request` / "request-to-join" (story #121) has **no off-the-shelf module** —
   it must be built bespoke on the customized group_membership relationship (status pending→approved).
   Invite-only is native Group. Don't assume grequest exists.
4. **New group routes collide with `drupal/group` contrib optional config.** #138's
   `/group/{group}/members` was silently served by the stock `views.view.group_members.page_1`
   (shipped in `web/modules/contrib/group/config/optional/`), so the whole new UI was dead. A fresh
   `drush en group` (what BrowserTestBase / CI Functional does) re-materializes it. Fix pattern:
   delete the page display from site config AND a `hook_install()` + `hook_modules_installed()`
   that strips the display programmatically (guarded by `moduleExists('views')`), AND call
   `router.builder->rebuild()` **in the same request after the strip** (ModuleInstaller rebuilds
   the router before hook_modules_installed fires). **Watch for this in any story that adds a route
   overlapping a Group-provided view/path.**
5. **"env-blocked / core bug" is usually a masked test-authorship bug — diagnose, don't accept it.**
   #138's "14 env-blocked tests / core list_string bug" was actually the tests passing the on-disk
   YAML shape `[{value,label}]` into `FieldStorageConfig::create()`, which wants the simple
   `[value => label]` shape. Fixing it turned 14 ERROR→PASS with zero skips AND surfaced a real
   `<th scope="col">` a11y defect. Push past "CI will verify" hand-waving; a red/errored CI job is
   never "done." Prefer fixing over `markTestSkipped`.
6. **E2E runs against the FULL SEEDED demo site.** Playwright specs must self-provision or work
   against the demo seed (`docs/groups/scripts/step_700_demo_data.php`, `step_720_group_types.php`,
   `step_780_nav_menu.php`), not an isolated fixture. An isolated U pass is NOT representative of
   the seeded E2E job. Verify E2E specs against a seeded site (assemble → site:install →
   config:import → seed → runserver → playwright), mirroring `.github/workflows/test.yml`.
7. **Docker hygiene:** each story's agents must namespace their containers (e.g. `gm138-*`) and
   **NEVER `docker rm` a container they didn't create.** One F force-removed a sibling story's
   MySQL container mid-run. Tell every agent this.
8. **Worktree isolation:** do NOT use the Agent tool's `isolation: "worktree"` — it worktrees the
   *session's* repo (wrong repo). Each orchestrator must create its OWN groups-on-d11 worktree:
   `git -C ~/Projects/groups-on-d11 worktree add ~/Projects/_worktrees/groups-<slug> -b <branch>
   origin/main`, then verify `git remote get-url origin` contains "groups-on-d11". Commit early/
   often + mirror handoffs to scratchpad (concurrent agents have reaped worktrees before).
9. **Minor:** Drupal `#type => submit` renders `<input type="submit">`, not `<button>` — e2e
   locators that expect `button` miss real form-submit controls. `getByLabel(/regex/)` can strict-
   mode-collide on a seeded page (tighten to `{exact:true}` or scope to the form).
10. **Multiple concurrent Claude app sessions touch this repo** — coordinate; don't assume
    exclusive ownership of worktrees or containers.

---

## 7. Non-negotiables / guardrails

- **A human merges every PR. Bots NEVER self-merge.** Hold at the pre-PR summary; open the PR;
  drive CI green; surface for the human's merge.
- **Never push branches to the drupalcode canonical repo.** (Not relevant to the github demo repo,
  but don't wander there.)
- **Source-only feature commits** (`docs/groups/…`); no `config/sync`/`web/modules/custom` artifacts.
- **Model tiers:** Sonnet everywhere, Opus only for S. The user is token-constrained.
- **Disclose AI involvement** in PR descriptions; `Co-Authored-By` on commits; assign PR to
  `aangelinsf`; add scoped labels (enhancement + component) mirroring the issue.
- **WCAG 2.2 AA** on every UI story; the diff gate + U (axe) + S enforce it.
- **Wait for the user's go-ahead** before launching a new wave if there's any ambiguity; but the
  user has already asked for parallel execution of the remaining stories.

---

## 8. Environment & mechanics

- **Worktree convention:** `~/Projects/_worktrees/groups-<slug>` per story, branch `<n>-<slug>`
  off `origin/main`. (Stale/merged ones from this run were cleaned; `groups-c1/c2/c3`, `ch80`,
  `fix68`, `fix60`, `agent-d1-e2e` predate this session — leave them.)
- **assemble-config:** `scripts/ci/assemble-config.sh` copies `docs/groups/config/*` → `config/sync/`
  (minus 7 env-specific excludes) and `docs/groups/modules/do_*` → `web/modules/custom/`, and
  patches `core.extension.yml`. Run it before any assembled-layout verification.
- **CI:** `.github/workflows/test.yml` — 3 jobs: **Kernel (do_tests)**, **Functional
  (BrowserTestBase)**, **E2E (Playwright)**. `build.yml` builds/pushes the ghcr image on merge to
  main. Kernel/Functional run the custom module suites; E2E runs `tests/e2e/*.spec.ts` against a
  seeded served site. (BrowserTestBase self-installs clean — no demo seed — so functional tests
  must self-provision; E2E runs against the full seed.)
- **Deploy:** merge to main → build.yml → `ghcr.io/performant-labs/groups-on-d11:latest` → Coolify
  on Uranus pulls it → `https://groups.performantlabs.com` (self-seeding, publicly browsable).
- **Keys:** `OPENAI_API_KEY` (o4-mini review) in repo `.env` + env. `DEEPSEEK_API_KEY` in
  1Password "DeepSeek API Key" (for optional cross-vendor review; local .env copies go stale).
- **Scoped group-view roles** live in `config/sync` (`group.role.community_group-{anon,outsider,
  insider}_view.yml`), assembled from `docs/groups/config/`. Group-type seeding is a shared
  `docs/groups/scripts/step_720_group_types.php`.

---

## 9. Concrete next actions (recommended launch order)

1. **Confirm the user still wants the full parallel push** (they asked for it; confirm scope/width).
2. **First parallel batch — now-unblocked, low shared-file contention:** #120 (persona switcher),
   #122 (group-type homepages), #134 (private group), #139 (multilingual+RTL), #140 (Links),
   #141 (About), #143 (archive-restore), #144 (create→auto-organizer). Each: spawn an
   `orchestrator` (Sonnet) in its own worktree with the §5 autonomy instructions + §6 guardrails.
3. **Serialize the HelpText-touching stories** (#121, #126, #127, #128, #132) — run #121 first (it
   edits shared HelpText and per the epic should merge first), then the others rebase after it, OR
   run them one-at-a-time to avoid `HelpText.php` merge conflicts.
4. **#121 (membership models):** design the request-to-join flow bespoke (no grequest — gotcha §6.3).
5. **W2 stream stories (#110–115)** now that #109's shell is merged — they attach routes to the
   shell (and are the first real live UI for it, so U applies).
6. **Save for last:** #133 (honesty sweep — only story allowed to edit copy) and #145 (WCAG audit).
7. **Cap concurrency** at a handful of pipelines at once (this run showed ~3–4 concurrent is
   controllable; a 10-wide blind launch destabilized). Widen as you gain confidence; keep
   HelpText-touching ones serialized.

---

## 10. Open non-blocking follow-ups (captured, not urgent)

- #138 brief AC-13/[B-5] text says `scope: insider`; correct/shipped value is `scope: outsider`
  (synchronized global roles grant on non-member groups only via OUTSIDER scope). Fix the doc text.
- #138 A-3: optionally promote the manager's protected count helper to `public` to drop a UI-only
  duplicate read.
- #109: 4 advisories aimed at downstream stream stories (#110–115) — in the #147 PR body.
- No PR got a formal PR-level review (only in-pipeline diff-gate/A-dup/S + CI). A cross-vendor
  DeepSeek review is available if wanted (user deferred it for now).

---

## 11. Memory pointers (this project's persistent memory)

`~/.claude/projects/-Users-andreangelantoni-Projects-groupsdrupalorg/memory/` (index: `MEMORY.md`):
- `project_groups_demo_deploy.md` — demo topology, MVP realignment, wave plan.
- `project_grequest_incompatible_group4.md` — the grequest gotcha.
- `project_ci_assembled_layout_tests.md` — the assembled-vs-source CI gotcha.
- `feedback_fork_even_as_maintainer.md`, `feedback_mr_assignee_and_labels.md` — contribution rules.
- `~/.claude/memory/feedback_avoid_honest_genuine.md` — user style: don't pad with "honest/genuine".

---

## 12. Coordinator tips (hard-won this run — the meta-lessons)

These are the judgment calls, not the mechanics. They're what actually determined whether time
was spent or wasted.

1. **Cap concurrency at ~3–4 pipelines to start; widen only once stable.** The single most
   expensive mistake this run was an initial ~10-wide blind launch: nested orchestrators collided
   over worktree paths, one hard-reset the shared checkout mid-work, and I couldn't cleanly stop
   them. Three concurrent pipelines is controllable and still fast. Add more as you gain
   confidence — and keep HelpText-touching stories serialized regardless.

2. **Wake orchestrators on every child-completion; that IS your job as coordinator.** They park
   and won't self-advance (§5). The instinct to answer a completion notification with "no action
   needed" is wrong — that's the agent asking to be ticked forward. Missing this stalled 3
   pipelines for ~3 hours. Distinguish "parked waiting for child" (leave it) from "child finished"
   (wake the parent) on every notification.

3. **Verify on the REAL path, never a proxy — and make your agents do the same.** "Green locally"
   repeatedly meant "green in the wrong environment": source tree vs CI's assembled layout; an
   isolated fixture vs the seeded E2E site; a per-process drush check vs a same-request install; a
   PHPUnit call vs the cache layer. Every one hid a real defect. When an agent reports GREEN, ask
   *which* environment — if it's not the one CI/production uses, it isn't verification.

4. **Don't accept "env-blocked / core bug / CI will verify" — diagnose it.** That framing masked a
   test-authorship bug that was itself hiding a real accessibility defect. Push agents past it; a
   red or errored CI job is never "done," and a skip is a last resort, not a fix.

5. **Read every CI finding and every review finding, not just pass/fail.** A green score is not a
   verdict. The o4-mini diff gate returned "BLOCK×5" runs where 2–3 were real and the rest were
   refutable-with-evidence — you have to adjudicate each against the actual code, not rubber-stamp
   either direction. Same for "the tests are wrong" claims from F: make T independently confirm.

6. **Own the loop to done; never hand the user an open loop.** After a PR opens, drive its CI to
   green yourself, route fixes on failure, and only surface it when it's actually merge-ready.
   "Pushed the fix, CI running, I'll report back" is not done. The user should never have to ask
   "is it green yet?"

7. **Fix the defect CLASS, not the cited instance.** When the diff gate flagged one missing cache
   context, the fix swept every variant-dependent render surface. When one route collided, we
   checked whether any other new route did. A reviewer flagging X means you check X's siblings.

8. **Be straight about scope in status reports.** "Wave-1 complete" was misleading shorthand for
   "the 3-story batch I launched" — it read as "the epic's done." Name what's actually true:
   N stories of M, which epics, what's merged vs. in-flight. Precision here is cheap; a wrong
   impression is expensive.

9. **Enforce the model tiers actively.** Every role sub-agent spawned with `model: "sonnet"`
   explicitly; only S on Opus. It's easy for a spawn to silently inherit the session's Opus —
   check. The user is token-constrained and this is why.

10. **Treat the shared checkout and sibling containers as untouchable.** Never mutate
    `~/Projects/groups-on-d11` (read-only reference); never `docker rm` a container you didn't
    create. Cross-story collisions (a hard-reset checkout, a force-removed sibling DB) cost real
    recovery time. Isolation is per-story worktrees + namespaced containers, full stop.

---

**Bottom line:** 3 foundation stories are merged and green; the 4 epics they seed are ~10% done.
The next agent should run the remaining stories in parallel using the pipeline (§4), the
coordinator wake-cadence (§5), and — above all — the gotchas (§6), which are the difference
between "green locally" and "actually merged and working."
