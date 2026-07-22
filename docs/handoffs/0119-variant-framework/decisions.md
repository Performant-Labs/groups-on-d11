# Decision journal — 0119-variant-framework (SC-F1)

Issue: https://github.com/Performant-Labs/groups-on-d11/issues/119
Epic: https://github.com/Performant-Labs/groups-on-d11/issues/117

## O — Phase 0 (pre-flight + setup) — 2026-07-22T08:37:00Z
- **Decided:** Orchestrate on `groups-on-d11` (GitHub) rather than the `groupsdrupalorg`
  (GitLab/Drupal.org) session this thread started in — the task targets a different repo. Set up
  an isolated git worktree on branch `0119-variant-framework`, branched from `origin/main`
  (c18f417), per `parallel-agent-coexistence.md` naming (`NNNN-<slug>`, zero-padded issue number).
  Seeded `.env` (gitignored review keys) into the worktree by copy, per task instruction. Ran
  `npm install` in the worktree (separate `node_modules`).
- **Assumed:** The worktree needs its own DB/port only at the Docker-verification step (U/T-live),
  not for static analysis/unit-style checks — no dev server is running yet.
- **Hedged:** `docs/playbook/workflow/orchestrator.md` pre-flight table calls for
  `docs/planning/SPEC.md` / `BUILD_PLAN.md`; this repo has no such docs — the GitHub issue + epic
  are the spec/plan of record for this pipeline instance. Treated as N/A rather than MISSING.
- **Evidence:** `git worktree add ... -b 0119-variant-framework origin/main`; `npm install`
  (silent, no errors); `gh issue view 119`; `gh issue view 117`.

### Mid-run incident: worktree instability, relocated
The first two worktree locations tried
(`groups-on-d11/.claude/worktrees/0119-variant-framework`, twice) proved unstable in this
environment: the directory disappeared entirely once (git also lost its `.git/worktrees/`
metadata for it — a clean-removal shape, not a crash), and on recreation at the same path the
working tree went inconsistent with its own HEAD (`git log`/`git show HEAD:<path>` succeeded but
the same tracked, non-gitignored files were absent on disk, and `git checkout HEAD -- docs/`
reported the path unknown to git from inside that worktree). The primary `groups-on-d11` checkout
and its `.claude/worktrees/agent-issue109` sibling also changed branch/location during this same
window, independent of any command this run issued — indicating environment-level
interference/reconciliation with paths under `groups-on-d11/.claude/worktrees/`, not a bug in this
run's git usage.

**Resolution:** relocated the worktree to `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework`
— the sibling-worktree convention already used by 5 other concurrent stories in this repo
(`groups-c1`, `groups-c2`, `groups-c3`, `groups-ch80`, `groups-fix68`, `groups-136-w0`), which have
persisted stably across this same session. Re-seeded `.env`, re-ran `npm install`, verified
`docs/playbook/workflow/dual-review.sh` present on disk before writing further handoffs. Re-wrote
`survey.md`/`brief.md` (identical research/conclusions carried over in this transcript — only the
files were lost, not the analysis). Per the crash-recovery rule: re-verifying state from disk at
each phase boundary from here on, not trusting a remembered read.

### Pre-flight checklist result (`docs/playbook/workflow/preflight-checklist.md`)
| Check | Result |
|---|---|
| Node version | v26.5.0 active (no `.nvmrc`/`engines` pin in this repo) — OK |
| Native deps / `npm run local` boots | No `npm run local` script; repo serves via Docker/drush runserver (CI pattern) — N/A, verified via Docker boot at U instead |
| gitleaks installed | `gitleaks version` → 8.30.1 — OK |
| Husky/lefthook hooks active | **MISSING** — `git config core.hooksPath` empty, no `.husky/`, no `lefthook.yml`. Pre-existing repo-wide gap, not introduced by this story. **Waived** — flagged, not blocking (no story-introduced regression, no hook infra to wire into on this branch). |
| `OPENAI_API_KEY` present | Present in `.env` (seeded from primary checkout) — OK for `second-opinion` gates |
| Role subagents resolve to project's `.claude/agents/` | No `.claude/agents/` dir in this repo — role docs live at `docs/playbook/workflow/*.md` as templates. Harness-registered generic pipeline `subagent_type`s briefed explicitly with this repo's conventions (Drupal 11 module code, Playwright `tests/e2e/`, Docker/drush — not groupsdrupalorg's DDEV). |
| `dual-review.sh` real interface | Confirmed at `docs/playbook/workflow/dual-review.sh`: `--mode <brief|diff> --brief <path> --out <path> [--base <ref>] [--handoff <path>]`, env `DUAL_REVIEW=1` (set in `.env`), `OPENAI_API_KEY` (present), `DUAL_REVIEW_MODEL=o4-mini` (set), `PIPELINE_CRITIC_PROVIDER=litellm`. Matches task instruction. |
| Worktree hygiene | Branch/worktree/handoff-dir stem `0119-variant-framework` used throughout, per `naming.md`. Relocated to `_worktrees/groups-0119-variant-framework` after instability (see above); `git worktree list` re-checked clean after relocation. |
| Handoff durability | `docs/handoffs/` is **not** gitignored (only `.env` is). Journal/survey/wireframe will reach the PR. |

### Two anomalous/boilerplate docs found and disregarded
- `docs/playbook/agent/naming.md` opens with an unrelated "OpenCloud" / "Contextual Nomenclature"
  section (prohibited generic filenames, `voting-app` examples) mismatched to this repo's domain.
  The real convention below it (`NNNN-<slug>`) is coherent and matches existing worktrees on disk
  — followed that, disregarded the OpenCloud section as noise.
- Repo-root `TESTING.md` is entirely Go-language testing conventions (`_test.go`, `testify`, build
  tags) — this is a PHP/Drupal + Playwright/TypeScript repo. Disregarded as stale/mismatched, not
  followed. Both flagged here rather than silently ignored.

### Models (per task instruction)
- Orchestrator (this session) and all downstream role subagents run Sonnet, **except** the Spec
  Auditor (S), which must run Opus. Recorded so O does not let S inherit Sonnet by default, and
  does not let any other role inherit Opus.

## O — Phase 1 brief gate (dual-review, second-opinion) — 2026-07-22T08:58:00Z
- **Decided:** Ran the o4-mini brief gate (`dual-review.sh --mode brief`, DUAL_REVIEW=1). Verdict
  **BLOCK ×5**. Resolved all five before advancing to D/A:
  - B-1 (tooltip init) — folded as a design constraint (switcher is server-rendered; F/T verify
    `do_chrome.tooltips.js` init path).
  - B-2 (tempstore.private anonymous cross-contamination) — reviewer premise **corrected** against
    core source: `PrivateTempStore::getOwner()` (lines 220-226) generates a per-session random owner
    for anonymous, so NO cross-anon leak. BUT a real issue remains: any server session write busts
    the anonymous page cache. **Amended the design to client-side persistence (cookie/localStorage)
    instead of tempstore** — better for a public demo, satisfies per-session semantics.
  - B-3 (test surface) — accepted; brief now enumerates switcher+ribbon+page e2e cases + a PHPUnit
    `VariantSwitcher::build()` contract test.
  - B-4 (data model) — accepted; comparison/persona lists are t()-wrapped code constants
    (`ShowcaseCatalog`), not config/content.
  - B-5 (wireframes pending) — **rejected as a brief defect**: the pipeline produces + signs off the
    wireframe in Phase 2 (D), the very next step; "pending" is the correct Phase-1 state.
  - WARN/NIT: W-2 (WCAG specifics) + W-3 (i18n) folded into Acceptance/Operating rules; W-4 (split
    story) rejected (epic scopes SC-F1 as one foundation story; SC-4/5/6 are the splits); W-5
    reinforced in Operating rules.
- **Assumed:** Client-side persistence is acceptable for the demo's "remembers per session"
  requirement (no cross-device carry needed). A/D can revisit if the wireframe implies otherwise.
- **Hedged:** B-1's AJAX re-attach path is only relevant if a future consumer swaps switcher options
  without a full page load — not this story's stub, so noted as a forward-looking constraint, not
  built now.
- **Evidence:** `dual-review-brief.md` (o4-mini round 1, full findings); core
  `web/core/lib/Drupal/Core/TempStore/PrivateTempStore.php` lines 106-226 read to correct B-2.

### Infra note (repeated worktree reaping)
The isolated worktree keeps being reaped by a concurrent process (coordinator confirmed: sibling
story agents + stray-worktree cleanup churning `.git/worktrees/`). Mitigation adopted per coordinator
guardrail #1: (a) durable artifacts mirrored to the session scratchpad
(`scratchpad/o119-handoffs/`), (b) recreate-worktree + populate + commit done inside single atomic
Bash calls so the reaper can't strike mid-sequence, (c) commit early/often so work survives as git
objects on the branch even if the working dir is reaped. NEVER run mutating git/composer against the
shared `groups-on-d11` checkout (that churn is what reaps worktrees).

## D — Phase 2 (Design / wireframe) — 2026-07-22

- **Decided:** Generated (mode a) a low-fi ASCII wireframe covering all three UI surfaces named in
  the brief — the labeled variant-switcher device, the `/showcase` tour page, and the site-wide POC
  ribbon — plus their required states (switcher: default/focus/unavailable/no-JS; showcase page:
  default-many/empty/error; ribbon: default/focus/dismissed). Reused `do_chrome`'s existing tooltip
  dual-channel pattern (`data-do-tooltip` + `#description` fallback, one ⓘ per widget wrapper) and
  `DoChromeHooks`'s single global `page_attachments` attach point as the ribbon's direct analog,
  rather than inventing new chrome mechanics. Selection/status states are conveyed by glyph + text
  + ARIA attribute, never color alone, per the brief's WCAG 2.2 AA constraint.
- **Decided:** The `/showcase` page's seven required entries (six comparisons + persona switcher)
  are listed in the brief's own acceptance-criteria enumeration order — an arbitrary but stable
  choice; F may reorder for narrative flow as long as all seven remain present with truthful
  `[ live ]`/`[ coming ]` status.
- **Assumed:** Ribbon placement is fixed-top (matches the issue's "fixed banner" framing); the
  brief allows "fixed top or corner" so this is flagged as an open question for the operator rather
  than asserted as final — F may implement a corner placement instead without a wireframe redo, as
  long as nav DOM/structure stays unchanged (nav.spec.ts non-regression constraint).
- **Assumed:** No ribbon re-entry point after dismissal is needed (the brief's acceptance criteria
  don't require one; `/showcase` stays reachable via normal nav). Flagged as an open question, not
  built as a requirement, since adding one later is a small, non-breaking addition if the operator
  wants it.
- **Hedged:** The disabled-option ARIA pattern for the switcher's "unavailable" state
  (`aria-disabled` + removed from tab order) is the wireframe's stated default, but explicitly
  defers to whatever disabled-control pattern already exists elsewhere in the codebase, if any — F
  to confirm during implementation rather than the wireframe inventing a new one unchecked.
- **Evidence:** `docs/groups/modules/do_chrome/src/Hook/VisibilityTooltip.php`,
  `docs/groups/modules/do_chrome/src/HelpText.php`,
  `docs/groups/modules/do_notifications/src/Controller/NotificationSettingsController.php` (read
  for house style/reuse patterns); brief.md + survey.md (acceptance criteria, forward-compat table,
  Brief-gate B-1/B-2/B-4 resolutions). No mutating command run against the shared `groups-on-d11`
  checkout — read-only reference only, per guardrail.

## O — Phase 2 D-gate sign-off — 2026-07-22T09:05:00Z
- **Decided:** Wireframes for all three surfaces (variant switcher, /showcase tour, POC ribbon)
  APPROVED by the coordinator (acting for the operator) — explicit sign-off received, gate passes.
  Approved specifics: roving-tabindex radiogroup, non-color selection/status cues, real
  `<button aria-label>` dismiss, no-JS `?variant=` fallback, truthful [live]/[coming] framing with
  no dead links, decision-per-entry framing on /showcase.
- **Three open questions from handoff-D resolved (proceed on these):**
  1. Ribbon re-entry after dismissal: NOT built — none required for POC. /showcase stays reachable
     via normal nav.
  2. Ribbon placement: fixed-top (F's call), MUST NOT cover primary nav or reflow nav DOM — keep
     `nav.spec.ts` green.
  3. Disabled-option ARIA: `aria-disabled` + `tabindex="-1"`, deferring to any existing
     disabled-control precedent F finds in the codebase.
- **Consistency note (not a change):** /showcase "Membership models — open/request/invite" entry
  stays `[coming]` — request-to-join has no off-the-shelf module on group 4.0.x (grequest
  incompatible, per #146); #121 builds it bespoke. The tour page must not imply it is live.
- **Assumed:** none new.
- **Hedged:** none.
- **Evidence:** coordinator D-GATE APPROVED message; `handoff-D.md`, `wireframe.md`.

## O — autonomy grant — 2026-07-22T09:05:00Z
- **Decided:** Coordinator granted autonomous run through A → T(RED) → F → T(GREEN) → o4-mini diff
  gate → A-dup → U → S with NO per-phase go-aheads. The ONE mandatory stop is the pre-PR hold:
  after S returns PASS (or a reconciled ADVISORY-HOLD), STOP before `gh pr create` and report a
  one-shot summary for the serialized PR go-ahead. Surface early only for a genuine unresolvable
  blocker. Guardrails unchanged: isolated worktree only, Sonnet for A/T/F/U, Opus only for S,
  o4-mini second-opinion (no fresh-Opus arm).

## A — Phase 3 (up-front plan review) — 2026-07-22T09:10:00Z
- **Decided:** PASS. Plan reviewed against `do_chrome` (HelpText append-only store,
  `page_attachments` global-chrome pattern, `PermissionMatrix` as a direct shape analog for
  `ShowcaseCatalog`), `do_notifications`/`do_discovery` (ControllerBase + routing.yml), and the
  repo's two existing `Plugin/Block` implementations (`do_group_mission`, `do_profile_stats`) as
  the comparison baseline for the `VariantSwitcher` service-vs-block question. No block findings.
- **Assumed:** The plan's divergence from the Block-plugin pattern (a plain
  `do_showcase.variant_switcher` service instead) is deliberate and correct — the switcher is
  always called with explicit caller-supplied parameters (instance_id/options/current), not
  derived from block placement/region context the way `GroupMissionBlock` derives its group from
  route/context. Not drift; flagged as warn #2 asking F to state this reasoning in handoff-F for
  Phase-7 traceability, not a plan change.
- **Hedged:** Warn #1 — `survey.md`'s Reuse map still states the pre-Brief-gate-B-2 "EXTEND
  tempstore.private" line verbatim, while brief.md/wireframe.md correctly carry the corrected
  client-side-persistence decision. Doc-hygiene only; does not block Phase 4 start since brief.md
  is authoritative and already correct.
- **Evidence:** `handoff-A-plan.md` (this phase's output); read in full:
  `do_chrome/src/PermissionMatrix.php`, `do_chrome/src/Hook/{DoChromeHooks,VisibilityTooltip}.php`,
  `do_chrome/src/HelpText.php`, `do_chrome.{module,info.yml,services.yml,libraries.yml}`,
  `do_notifications/{do_notifications.routing.yml,src/Controller/NotificationSettingsController.php}`,
  `do_discovery/do_discovery.routing.yml`, `do_group_mission/src/Plugin/Block/GroupMissionBlock.php`,
  `playwright.config.ts`. No mutating command run against the shared `groups-on-d11` checkout.

## T — Phase 4 (author tests / RED) — 2026-07-22T12:35:00Z
- **Decided:** Authored the full Phase-4 suite per Brief-gate B-3: three PHPUnit Unit test files
  (`VariantSwitcherTest.php` — 10 methods pinning the `VariantSwitcher::build()` render-array
  contract incl. fallback/disabled/no-JS/arbitrary-option-count behavior; `ShowcaseCatalogTest.php`
  — 9 methods pinning the seven-entry `ShowcaseCatalog` code-constant shape, live/coming truthful
  routing, and t()-wrapping; `ShowcaseHelpTextTest.php` — 4 methods pinning the specific appended
  HelpText keys + an append-only non-regression check) under
  `docs/groups/modules/do_showcase/tests/src/Unit/`, plus one Playwright spec
  `tests/e2e/showcase.spec.ts` (15 cases across switcher/ribbon/showcase-page test.describe blocks)
  in the correct `tests/e2e/` location (not the root `e2e/` silent no-run trap the brief warns
  about). All three Unit tiers picked as cheapest-sufficient (pure data/render-array construction,
  same shape as the existing `PermissionMatrix`/`PermissionMatrixTest` and `HelpText`/`HelpTextTest`
  precedents — no container/DB/config needed); the e2e tier picked for the switcher/ribbon/page
  because those behaviors (real click → ARIA state, client-side persistence across a real
  navigation, cross-surface DOM non-interference) are invisible to a headless PHPUnit run.
- **Decided:** Ran all three PHPUnit files against the shared checkout's vendored `phpunit` binary
  (read-only invocation targeting worktree test-file paths — no mutating command run against the
  shared `groups-on-d11` checkout) to confirm a real RED: 23/23 test methods failed with
  `Class ... not found` (VariantSwitcher / ShowcaseCatalog / do_chrome's own HelpText, the latter
  because no `do_*` module is on the PSR-4 autoloader outside a full `assemble-config.sh` +
  `composer install` assembly — confirmed as a pre-existing, harness-level fact by running the
  existing `do_chrome/tests/src/Unit/HelpTextTest.php` the identical way first and observing the
  identical `Class "Drupal\do_chrome\HelpText" not found` error, 10/10, zero assertion failures).
  Zero assertion-level failures anywhere — every RED is class/feature absence, the right reason.
- **Decided:** Playwright `showcase.spec.ts` cases are RED-by-construction (documented precisely per
  case against the real, current `DoChromeHooks.php` contents and the confirmed absence of any
  `/showcase` route) rather than executed, because no Drupal site is running in this environment
  (confirmed: DDEV default URL 404s, localhost:8080 refuses). `npx playwright test --list` confirms
  the file is syntactically valid and lands in the correct `testDir` — all 15 cases collected, zero
  errors. Real Playwright execution (RED at F-start would be wrong to expect anyway since no site
  exists to run against; GREEN confirmation) happens at T-GREEN against the namespaced Docker per
  brief.md's own Acceptance criterion, mirroring `.github/workflows/test.yml`'s `e2e` job recipe.
- **Assumed:** The nav-non-regression Playwright case
  (`ribbon does not cover or reflow primary nav`) is NOT itself a RED case — it currently passes
  (nav is unaffected because nothing has touched it yet) and exists purely as the guard T-GREEN
  re-runs to prove F's ribbon addition doesn't break `nav.spec.ts`'s own assertions. Flagged
  explicitly in handoff-T-red.md so it isn't miscounted as "should currently fail."
- **Assumed:** The switcher's wired demo instance is hosted on `/showcase` for e2e-testing purposes
  (brief.md only requires "at least one wired demo instance" without naming a page) — `/showcase`
  is the one route this story guarantees exists, so it is the deterministic, brief-compliant choice
  for where the e2e suite asserts the switcher renders. F may additionally place the switcher
  elsewhere; the e2e suite's assumption is only that /showcase is ONE guaranteed location.
- **Hedged:** The stub instance's exact option set (Compact list / Cards / Map, Map unavailable) is
  taken directly from wireframe.md's own worked example, not independently re-derived — if F's stub
  wiring differs in option count/labels, the e2e suite's literal text matches (`Compact list`,
  `Cards`, `Map (soon)`) would need updating; the PHPUnit `VariantSwitcherTest` covers the
  *general* contract (arbitrary option count) independent of this specific label choice, so the
  service contract itself is not coupled to this hedge.
- **Evidence:** `docs/groups/modules/do_chrome/tests/src/Unit/{HelpTextTest,PermissionMatrixTest}.php`,
  `docs/groups/modules/do_chrome/src/{HelpText,PermissionMatrix}.php`,
  `docs/groups/modules/do_chrome/src/Hook/DoChromeHooks.php` (read to confirm no ribbon method
  exists yet), `docs/groups/modules/do_notifications/src/Controller/NotificationSettingsController.php`,
  `docs/groups/modules/do_discovery/do_discovery.routing.yml`, `tests/e2e/nav.spec.ts`,
  `tests/e2e/directory-cards.spec.ts`, `tests/e2e/phase1.spec.ts`, `playwright.config.ts`
  (testDir confirmation), `scripts/ci/assemble-config.sh`, `.github/workflows/test.yml` (confirmed
  CI's kernel/functional jobs run assemble-config.sh before phpunit, matching this run's RED
  reasoning); direct PHPUnit + `npx playwright test --list` runs (output captured in
  handoff-T-red.md). No mutating command run against the shared `groups-on-d11` checkout.

## F — Phase 5 (implement / drive to GREEN) — 2026-07-22

- **Decided:** Implemented the `do_showcase` module against T's authored RED suite exactly as
  written — no test edited. `VariantSwitcher::build()` returns a `#theme`-based render array
  (`do_showcase_variant_switcher`, a new Twig template) rather than a raw nested-container array, so
  the roving-tabindex/ARIA radiogroup markup is real presentation code (matching
  `PermissionMatrixPanel`'s `#theme` pattern), while still exposing `#attributes`/`#options`/
  `#tooltip` at the top level so the PHPUnit contract test can assert the render-array shape without
  a full render cycle.
- **Decided:** Used `hook_page_top` (not `hook_page_attachments`) for the ribbon, since the ribbon
  is visible markup (`<button>`/`<a>`) and `page_attachments` can only carry `#attached`
  (library/settings). Kept `DoChromeHooks::pageAttachments()`'s "single global attach point" shape
  for the *pattern*, just the correct hook for actual chrome content.
- **Decided:** `VariantSwitcher` is a plain service (`do_showcase.variant_switcher`), not a Block
  plugin — one-sentence rationale recorded in handoff-F.md per A's Phase-3 action item: the repo's
  only embeddable-render-surface precedent (`GroupMissionBlock`/`ContributionStatsBlock`) is
  context-derived (group from block placement), while the switcher's callers always supply explicit
  params and call it inline.
- **Decided:** Appended exactly two new keys to `\Drupal\do_chrome\HelpText::all()`
  (`showcase.switcher.directory.layout`, `showcase.ribbon`) — verified append-only by re-running
  `do_chrome`'s own pre-existing `HelpTextTest.php` after the append (14/14 still green, no existing
  key's assertion changed).
- **Decided:** Client-side-only persistence (`localStorage`, keyed per switcher `instance_id` for
  the switcher and a single flag for ribbon dismissal) — no `tempstore.private`, no session write
  anywhere in new code, per Brief-gate B-2 and the corrected brief.md/wireframe.md (not the stale
  survey.md Reuse-map line A already flagged as non-blocking doc-hygiene).
- **Assumed:** A `?variant=` query param on the URL always wins over a stored (localStorage) choice
  — not spelled out verbatim in the wireframe, but implied by the no-JS-fallback needing to work
  deterministically even after a prior visit stored a different choice.
- **Hedged:** Module install/enable-clean and the Playwright e2e suite were NOT run against a live
  Drupal site in this environment (no groups-on-d11 DDEV/Docker site running here; this environment
  is explicitly scoped to read-only reference against the shared vendor). Verified instead: `php -l`
  on every new PHP file, YAML parse-validity on every new `.yml`, `node --check` on both new JS
  files, and a real PHPUnit run proving the module's classes autoload correctly under Drupal's own
  module-discovery bootstrap (the same PSR-4 mechanism `drush en` relies on). Full install +
  `npx playwright test tests/e2e/showcase.spec.ts` against the namespaced Docker is T-GREEN's job
  (Phase 6), per the brief's own Acceptance criterion — not a gap I'm closing here, an explicit
  phase boundary.
- **Hedged:** While reproducing T's PHPUnit RED locally to verify GREEN, discovered that a
  **symlinked** `web/core` inside the worktree silently redirects PHPUnit's `bootstrap=` resolution
  back to the SHARED checkout (Drupal core resolves the bootstrap path relative to the *realpath* of
  `phpunit.xml.dist`'s directory) — producing misleading `Class ... not found` errors even after the
  module was correctly implemented. Fixed by using a full copy of `web/core` (not a symlink) for
  local verification only; torn down completely afterward (confirmed via `git status` — worktree
  clean of anything but the intended production files). Recorded here in case T-GREEN or a future
  phase hits the same trap.
- **Evidence:** `docs/groups/modules/do_chrome/src/Hook/{DoChromeHooks,GroupTypeContentHelp,
  ArchivePinHooks}.php`, `do_chrome/src/{HelpText,PermissionMatrix}.php`,
  `do_chrome/templates/do-chrome-permission-matrix.html.twig`,
  `do_notifications/src/Controller/NotificationSettingsController.php`,
  `do_discovery/do_discovery.routing.yml`, `tests/e2e/nav.spec.ts` (NAV selector reuse, confirmed
  non-regression), all three T-red PHPUnit test files + `showcase.spec.ts` read in full before
  implementing. PHPUnit run: 37/37 green (23 new do_showcase + 14 pre-existing do_chrome
  non-regression) — full command + output in handoff-F.md. phpcs (Drupal+DrupalPractice): 0 errors
  across all new production PHP files. phpstan level 1: 1 finding (`new.static` on
  `ShowcaseController::create()`), confirmed identical to the pre-existing finding on
  `NotificationSettingsController::create()` — not a defect introduced here. No mutating command run
  against the shared `groups-on-d11` checkout at any point.

## Phase 6 — T (GREEN) + Tier 2

- **Decided:** Verified PHPUnit against a full copy of `web/core` (not a symlink), matching F's own
  documented workaround for the realpath-bootstrap trap. Confirmed the isolated tree was actually
  exercised via PHPUnit's own printed `Configuration:` path (the worktree path, not the shared
  checkout). 37/37 green (23 new do_showcase + 14 pre-existing do_chrome non-regression).
- **Decided:** Stood up a real namespaced Docker (mysql:8) + composer install (PHP 8.4 via Homebrew
  — the default system PHP 8.3 fails composer's lock-file platform check) + assemble-config.sh +
  drush site:install + config:import + demo-data seed + `drush runserver`, mirroring
  `.github/workflows/test.yml`'s e2e job, and ran `npx playwright test tests/e2e/showcase.spec.ts`
  for real. 15/15 green (after fixing one test locator — see below). Also ran nav.spec.ts (6/6, ribbon
  non-regression confirmed) and the broader phase1-4/directory-cards regression suite (18/18) for
  extra confidence given a mid-run infra casualty (see Hedged below). Full teardown after (Docker
  container removed, server killed, worktree filesystem restored to only the one intentional test
  fix via git checkout/clean).
- **Decided (test fix, not a silent change):** `showcase.spec.ts`'s "lists the persona switcher
  naming all four public personas" case failed on a REAL run — not because of a production defect,
  but because its own `page.getByText('Anonymous', { exact: false })` locator (case-insensitive by
  default) collided with the persona-switcher catalog entry's legitimate decision_sentence text
  ("...one generic **anonymous** view..."), a strict-mode violation. Fixed by scoping the assertion
  to the entry's own `<ul>` via the `data-do-showcase-entry="persona-switcher"` attribute F's DOM
  contract already documents. This is T fixing its own test per the pipeline's own rule ("F writes
  no tests... if a test is wrong, T fixes it") — not F's code being wrong, and not silently
  papered over: full root-cause + before/after re-run recorded in handoff-T-green.md.
- **Found (BLOCKER, routed to F+T, not fixed here):** the approved wireframe (`wireframe.md` lines
  29-31, 271) explicitly specifies roving-tabindex + Arrow-Left/Right keyboard navigation for the
  switcher ("matching native radiogroup behavior"). Shipped code gives every available option
  `tabindex="0"` (not roving) and implements no arrow-key handler — Tab-only, one stop per option.
  Neither my Phase-4 PHPUnit nor Playwright suite tested this specific wireframe-committed behavior
  either — a genuine hole in the RED I authored, flagged against myself, not just F. See
  handoff-T-green.md's BLOCKER section for the full precise citation and routing.
- **Hedged:** Mid-run, the throwaway MySQL Docker container was reaped by a concurrent process on
  this shared machine (matching the task's own stated risk), producing 11 apparent regression
  failures (login timeouts, one 403→500) partway through a broader (non-required) regression pass.
  Diagnosed precisely (container gone from `docker ps -a`, `/user/login` itself 500ing — a server/DB
  casualty, not a do_showcase code path) rather than reported as a false blocker. Recreated the
  container + site fresh on a new port with a single-worker `runserver` and reran everything to a
  clean, uninterrupted 18/18 + 21/21. Flagged as an advisory note for O — this machine's
  worktree/process reaping is a recurring operational hazard for any phase doing live-environment
  verification, not specific to this story.
- **Evidence:** `handoff-T-green.md` (this phase's full output — PHPUnit command+output, phpcs/
  phpstan cross-check against F's report, Playwright per-case table, Tier 2 findings, acceptance-
  criteria table). Mutation spot-check (temporarily broke `VariantSwitcher::resolveSelection()`'s
  fallback, confirmed 2 tests fail for the right reason, restored, confirmed 10/10 green again) —
  proves the suite pins behavior, not implementation. `git status --short` in the worktree after
  teardown: only `tests/e2e/showcase.spec.ts` (the one intentional fix) plus one pre-existing
  untracked file not touched by me.
