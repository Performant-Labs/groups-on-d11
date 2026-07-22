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

## T — Phase T(RED round 2) — fix loop, roving-tabindex/arrow-key gap — 2026-07-22

- **Decided:** Authored 4 new PHPUnit methods in the existing `VariantSwitcherTest.php` (Unit
  tier — pure render-array/data-shape contract, matching the file's existing tier) pinning the
  wireframe's roving-tabindex invariant (exactly one option carries `tabindex="0"` at a time, and
  it must be the selected option), plus 4 new Playwright cases in the existing
  `SC-F1 — Variant switcher (#119)` describe block of `showcase.spec.ts` (E2E tier — dynamic
  keyboard/focus behavior a headless test cannot observe) pinning the ArrowRight/ArrowLeft
  selection-move contract and a no-JS-fallback regression guard. Neither test file's existing
  methods/cases were edited.
- **Decided:** Executed the PHPUnit cases to a real RED against the current shipped code, on a
  freshly-recreated isolated tree (full `cp -R` of `web/core` + `vendor`, not symlinks — same
  realpath-bootstrap-trap workaround F/T-green documented). 3/4 new methods failed on the roving-
  tabindex assertion itself (`actual size 2/4 matches expected size 1`), never a bootstrap/class
  error; the 4th correctly passed (an already-true invariant, not a false RED). All 10 pre-existing
  methods stayed green. Isolated-tree marker (`Configuration:` path = the worktree's own copy)
  captured in handoff-T-red2.md. Torn down after (scratch copies were gitignored, untracked —
  `git status --short` confirms only the two intended test-file diffs remain).
- **Decided:** Did NOT stand up a live site to execute the Playwright cases this round — this
  environment lacks PHP 8.4 (T-green's own documented composer-install requirement; only PHP 8.3
  is present here), and standing up the full namespaced-Docker/composer/drush stack purely to
  exercise 3 narrow keyboard-interaction assertions is disproportionate for this scoped fix-loop.
  Marked RED-by-construction instead, per the task's explicit fallback allowance, with precise
  per-case reasoning against the current committed `do_showcase.switcher.js` (confirmed: zero
  occurrences of "Arrow" in the file — no keydown branch for ArrowLeft/ArrowRight exists) and
  `VariantSwitcher.php` (confirmed: `tabindex => $available ? '0' : '-1'`, not roving). Verified
  the spec file is syntactically valid and all 19 cases (15 existing + 4 new) collect cleanly via
  `npx playwright test --list` (against a read-only `node_modules` symlink to the shared checkout,
  removed after).
- **Assumed:** Native-radiogroup arrow-key semantics (WAI-ARIA Authoring Practices radiogroup
  pattern — arrow keys move among enabled/available options only, skipping disabled ones) is the
  correct reading of the wireframe's "matching native radiogroup behavior" phrase, since the
  wireframe does not spell out wrap-vs-clamp verbatim. The stub instance's 2-available-option shape
  means wrap-at-the-end is not independently distinguishable from a hard clamp in this round's
  cases — flagged in handoff-T-red2.md as a note for a future story with 3+ available options, not
  a gap in this round.
- **Hedged:** No Kernel/Functional test added for this gap — the PHPUnit (static contract) +
  Playwright (dynamic interaction) pairing already covers both the render-array shape and the real
  keyboard/focus behavior at their cheapest sufficient tiers; a Kernel test would duplicate the
  PHPUnit case at a more expensive tier for no new coverage, and Functional (browser-less) cannot
  observe `keydown`/focus transfer at all.
- **Evidence:** `handoff-T-red2.md` (this phase's full output — PHPUnit command + isolated-tree
  marker + full RED output, Playwright `--list` output + per-case RED-by-construction reasoning
  quoting the exact absence of Arrow-key handling in `do_showcase.switcher.js`). Files changed:
  `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php`,
  `tests/e2e/showcase.spec.ts` (both mirrored to
  `scratchpad/o119-handoffs/tests-red-round2/`). No mutating command run against the shared
  `groups-on-d11` checkout.

## F — Phase F (round 2) — implement roving-tabindex + arrow-key fix — 2026-07-22

- **Decided:** `VariantSwitcher.php` line 92 changed from `'tabindex' => $available ? '0' : '-1'`
  to `'tabindex' => ($available && $is_selected) ? '0' : '-1'` — exactly one option (the currently
  selected, available one) is now `tabindex="0"`; every other option (available or not) is
  `tabindex="-1"`. One-line change, matches T's own suggested fix in handoff-T-red2.md's "Ready
  for F" section verbatim.
- **Decided:** `do_showcase.switcher.js` gets a new `setRovingTabindex()` helper (mirrors the
  existing `setSelected()` helper's shape) called from inside `select()` so every path that changes
  selection (click, Enter/Space, and the new arrow-key path) also rolls the roving tabindex
  together with `aria-checked` — one single source of truth, not three duplicated update sites.
- **Decided:** Arrow-key navigation (`ArrowLeft`/`ArrowRight`, plus `ArrowUp`/`ArrowDown` as a
  bonus matching full native-radiogroup semantics) is implemented as a `moveSelection(fromOption,
  direction)` closure operating over `availableOptions` only (options pre-filtered by
  `aria-disabled !== 'true'`) — the unavailable "Map" option is structurally excluded from the
  index arithmetic, so it can never become a `moveSelection` target regardless of DOM position or
  direction. Wraps at the ends (`(currentIndex + direction + length) % length`) per the WAI-ARIA
  Authoring Practices radiogroup pattern the wireframe's "matching native radiogroup behavior"
  phrase invokes — T-red2's own wrap/skip semantics note already flagged this reading as correct
  and noted the 2-available-option stub can't independently distinguish wrap from clamp; F's
  implementation wraps, satisfying both readings for this stub's option count.
- **Decided:** Reused the existing `select(id, persist)` function unchanged in signature — arrow
  navigation calls `select(id, true)` (persists to localStorage, same as a click) then explicitly
  calls `.focus()` on the new option, so focus/selection/persistence/roving-tabindex all move
  together in one call, matching the Playwright cases' combined assertion (`toBeFocused()` +
  `aria-checked` + `tabindex` all on the same target).
- **Decided:** No Twig template change — `do-showcase-variant-switcher.html.twig` already emits
  whatever `tabindex` value the render array supplies per option; the fix is entirely in the
  render-array producer (PHP) and the dynamic DOM updater (JS), confirming T-red2's own point 3.
- **Assumed:** None new — implemented exactly per T-red2's "Ready for F" prescription and the
  wireframe's cited lines; no conflict found between the two authored test files and the
  wireframe, so no STOP-and-report was needed.
- **Hedged:** phpstan level 1 run against the single `VariantSwitcher.php` file in isolation
  reports `class.notFound` on `Drupal\do_chrome\HelpText` (line 103, pre-existing/unchanged call,
  not touched by this diff) — confirmed as a scan-scope artifact (no repo-level `phpstan.neon`
  wires up Drupal's module-namespace autoloading for a single-file CLI invocation), not a defect
  introduced by the one-line ternary change; the isolated-tree PHPUnit run (which DOES have full
  autoloading via Drupal's own bootstrap) exercises the same `HelpText::get()` call successfully,
  14/14 green, confirming the class resolves fine at real runtime. phpcs run against the `.js` file
  via an ad-hoc `--extensions=js` CLI invocation flags 5 "TRUE/FALSE/NULL must be uppercase"
  findings — confirmed false positives (Drupal's PHP-only boolean-casing sniff misapplied to
  JavaScript, where uppercase `TRUE`/`FALSE` would be a syntax error) by cross-checking every other
  untouched `.js` file in the module (`do_showcase.ribbon.js`, `do_chrome`'s non-minified JS) trips
  the identical false positive on their own pre-existing lowercase `false`/`true`/`null` — not a
  pattern introduced by this diff, and no real JS linter (ESLint) is configured in this repo to
  give an authoritative JS Tier-1 check.
- **Evidence:** Isolated-tree PHPUnit run (full `cp -R` of `web/core` + `vendor`, not symlinks,
  same realpath-bootstrap-trap workaround as prior phases) — 14/14 green, `Configuration:` path
  confirms the worktree's own copy was exercised, not the shared checkout. phpcs (Drupal +
  DrupalPractice) against `VariantSwitcher.php`: 0 errors. Full command + output in handoff-F2.md.
  Torn down after (`web/core`, `web/modules/custom`, `vendor` removed — `git status --short`
  confirms only the two intended production-file diffs remain). No mutating command run against
  the shared `groups-on-d11` checkout.

## T — Phase T(GREEN round 2) — 2026-07-22

- **Decided:** Ran a genuine `composer install` in the worktree this round (PHP 8.5.6 via
  `/opt/homebrew/opt/php@8.4/bin/php`, which satisfies the repo's `^8.4` lock constraint even
  though no real `php@8.4` keg is installed on this machine) rather than reusing the prior rounds'
  `cp -R`-of-shared-checkout workaround, since a clean worktree had no scratch tree to reuse. This
  produced a REAL, composer-resolved `web/core`/`vendor` — a stronger isolated-tree guarantee than
  the copy-based approach (no risk the copy silently diverged from the committed `composer.lock`).
- **Decided:** Stood up a real namespaced Docker/MySQL + `drush`-served Drupal site
  (`o119t2-mysql`, port 33063; site served on port 38082) and ran the full Playwright suite against
  it in a REAL browser, closing the gap T-red2/F2 both left as traced-not-executed. All 4 named
  arrow-key/roving-tabindex Playwright cases EXECUTED-LIVE-PASS.
- **Decided:** Playwright's own `npx playwright install chromium` failed for revision 1228
  specifically (Azure/Microsoft distribution-gateway error `20012`, confirmed via direct `curl`
  against both `cdn.playwright.dev` and the underlying `playwright.download.prss.microsoft.com`
  host — an upstream infrastructure issue, not a local network/proxy problem, since older chromium
  revisions e.g. 1187 downloaded fine and full TLS handshakes completed instantly). Used a
  pre-existing, complete `chromium-1228` install already cached on this shared machine
  (`~/Library/Caches/ms-playwright/chromium-1228/`, dated 2026-06-28 from an unrelated earlier
  session) instead of vendoring/patching a substitute revision. A manual revision-1187 substitution
  (patching `playwright-core/browsers.json`) was prototyped and then fully reverted once the
  working 1228 cache was found — no version-pin patch shipped in the final run.
- **Decided:** The one Playwright failure (`no-JS ?variant= fallback still works unmodified by the
  arrow-key fix`) is a genuine, newly-surfaced, PRE-EXISTING production defect
  (`ShowcaseController::page()` has no `#cache` context on the `variant` query argument, so
  Drupal's Dynamic Page Cache serves a stale variant selection to any `/showcase?variant=` request
  after a different variant has already been cached for that path) — not a test-authoring error and
  not a regression caused by F2's roving-tabindex/arrow-key diff. Confirmed via direct `curl` +
  `X-Drupal-Dynamic-Cache` header inspection reproducing the exact MISS→HIT sequence, and via
  `git diff --stat 9918fd8 a19686d -- '*Controller*'` showing zero changes to the controller in
  F2's round-2 commit. Did not edit the failing test (correct per contract) or write the
  production fix (T writes no production code) — routed to F as a named blocker.
- **Assumed:** `-d memory_limit=-1` is required for `drush config:import` in this environment (the
  default 128M CLI limit fatals partway through importing the full assembled config set) — not
  previously documented in round-1's recipe; added to this round's handoff for the next round's
  benefit.
- **Hedged:** phpstan level 1 against the real composer-installed tree resolves
  `Drupal\do_chrome\HelpText` cleanly (0 findings on `VariantSwitcher.php`) — cleaner than F2's
  single-file CLI invocation, which reported a `class.notFound` scan-scope artifact on the same
  pre-existing, unchanged line. Confirms F2's own characterization (scan-scope artifact, not a real
  issue) rather than contradicting it.
- **Evidence:** PHPUnit 41/41 green (`Configuration:` path confirms worktree's own real
  composer-installed tree). Mutation spot-check (reverted the roving-tabindex fix, reran, 3/4 new
  methods correctly failed for the assertion reason, restored, reran, 41/41 green again). Playwright
  24/25 passed live against a real served site + real Chromium browser — full per-case table and the
  cache-header reproduction sequence (`X-Drupal-Dynamic-Cache: MISS` then `HIT` across consecutive
  `/showcase` requests with different `?variant=` values) recorded in `handoff-T-green2.md`. Docker
  container + server processes fully torn down; worktree filesystem restored to only the one
  pre-existing untracked file present before this round began. No mutating command run against the
  shared `groups-on-d11` checkout.

## F — Phase F (round 3) — cache-context DEFECT CLASS fix — 2026-07-22

- **Decided:** Fixed the defect class, not just the single reproduced instance. Added
  `$build['#cache']['contexts'][] = 'url.query_args:variant'` to `ShowcaseController::page()` (the
  render array Drupal's Dynamic Page Cache keys on for `/showcase`, and the surface T's live
  `curl`/`X-Drupal-Dynamic-Cache` repro identified). Also added the matching
  `'#cache' => ['contexts' => ['url.query_args:variant']]` to `VariantSwitcher::build()`'s own
  return array — its render-array content (`#options`/`aria-checked`/roving-`tabindex`) is a
  function of the `$current` parameter, which this module's one caller derives from the query
  string, so declaring the context at the source (not relying solely on child-to-parent `#cache`
  bubbling) keeps the contract correct for any future caller of `build()`.
- **Decided:** Used the narrower `url.query_args:variant` context, not the coarser
  `url.query_args` — the module's output depends only on the `variant` parameter; using the
  parameter-scoped form avoids needlessly fragmenting the cache on unrelated query args, keeping
  the anon page cache healthy (per the round's explicit instruction not to disable caching
  wholesale).
- **Decided:** No cache tags added — this is purely a missing-*context* bug (the render array's
  content already correctly derives from `$current`, confirmed by the unchanged 41/41 PHPUnit
  green result); nothing about invalidation-on-entity-change is in play, so tags would be scope
  creep.
- **Assumed:** None new.
- **Hedged:** phpstan level 1 run against both changed files together reports one pre-existing
  `new.static` finding on `ShowcaseController.php` line 42 (inside `create()`, untouched by this
  diff — confirmed via `git diff --stat` showing only 8 added lines inside `page()`, lines 60-67).
  Matches the identical, already-flagged `new.static` pattern on
  `NotificationSettingsController::create()` recorded in this story's own Phase-5 entry. Not fixed
  here — out of scope for this round's defect class, and fixing it would be an unplanned drive-by
  on a method this diff does not otherwise touch.
- **Evidence:** Real `composer install` (PHP 8.5.6 via `/opt/homebrew/opt/php@8.4/bin/php`) +
  `scripts/ci/assemble-config.sh`, matching T-green2's own documented recipe. PHPUnit 41/41 green,
  `Configuration:` path confirms the worktree's own real composer-installed tree, not the shared
  checkout. phpcs (Drupal+DrupalPractice) on both changed files: 0 errors. phpstan level 1 on
  `VariantSwitcher.php` alone: `[OK] No errors`. Full command + output in `handoff-F3.md`. Worktree
  torn down after (composer-installed `web/core`/`vendor`/etc. removed) — `git status --short`
  confirms only the two intended production-file diffs plus the one pre-existing untracked file
  remain. No mutating command run against the shared `groups-on-d11` checkout.

## T — Phase T (GREEN round 3) — cache-context fix VERIFY + covering assertion — 2026-07-22

- **Decided:** Added exactly one new PHPUnit covering assertion
  (`testBuildDeclaresUrlQueryArgsVariantCacheContext`) to `VariantSwitcherTest.php`, pinning that
  `VariantSwitcher::build()`'s render array declares `#cache['contexts']` containing
  `'url.query_args:variant'` — the mechanism, not just the symptom. Placed at Unit tier (the
  cheapest sufficient tier `build()` is reachable at). Did not add a matching assertion for
  `ShowcaseController::page()` because `do_showcase` has no Kernel/Functional test tier and the
  controller requires a real `Request`/DI container to exercise — that surface's coverage is closed
  instead by the live cache-header verification (see below), which is a stronger, full-stack signal
  for a caching defect than a hypothetical new Kernel tier would be for one assertion.
- **Decided:** Verified the new assertion is a genuine covering test, not a tautology — reverted
  F3's `#cache` addition on `VariantSwitcher::build()`, reran: the new test failed for the right
  reason (`#cache` key absent), all 14 other `VariantSwitcherTest` cases (including content-shape
  assertions) still passed under the same mutation, confirming cache-context correctness is an
  independent contract from content correctness. Restored; reran green.
- **Decided:** Ran the live no-JS-fallback Playwright case (case 4,
  `showcase.spec.ts:291`) plus the full `showcase.spec.ts` + `nav.spec.ts` target suite against a
  freshly-provisioned, namespaced Docker environment (`o119t3-mysql`, port 33064; served on
  127.0.0.1:38083) — real Chromium (cached `chromium-1228`), no mocks. Result: 25/25 PASS (was
  24/25 with 1 blocker in T-green2). Directly inspected `X-Drupal-Cache`/`X-Drupal-Dynamic-Cache`
  headers across a 5-request sequence (`/showcase`, `?variant=map`, `?variant=compact`, repeat
  `?variant=map`, repeat `?variant=compact`) — confirmed each `?variant=` value gets its own
  correct cache entry (MISS-then-HIT per distinct URL) with zero cross-variant bleed (the repeat
  `?variant=map` request correctly re-served `compact`, never the earlier-cached `cards`).
- **Assumed:** `dynamic_page_cache` module is genuinely disabled in this repo's config
  (`core.extension.yml: dynamic_page_cache: 0`, confirmed via `drush pm:list --status=enabled`
  showing an empty status column for it) — so `X-Drupal-Dynamic-Cache` reads `MISS` throughout and
  the observable cache layer in this environment is `page_cache` (Internal Page Cache,
  `X-Drupal-Cache` header) instead. Assumed this matches the real deployed config (per F3's own
  note) rather than being an environment misconfiguration, and that `page_cache` is a valid
  observation point for this defect class since it is driven by the same `#cache['contexts']`
  render-array metadata F3 added.
- **Hedged:** Did not independently re-run phpcs/phpstan against the two F3-changed *production*
  files this round (`ShowcaseController.php`, `VariantSwitcher.php`) — accepted F3's own Tier-1
  report at face value since those files are unmodified since F3's self-check. Re-ran phpcs/phpstan
  only against the new/changed *test* file (0 new findings after fixing 2 self-introduced
  doc-comment-style errors during authoring).
- **Hedged:** Flagged (advisory, not blocking) that `ShowcaseController::page()`'s own cache-context
  addition has no dedicated headless/CI-gated PHPUnit assertion — only the live, manually-run
  cache-header check in this handoff covers it. Judged disproportionate to stand up a new Kernel
  test tier for `do_showcase` solely for this one assertion in this round's narrow scope; left as a
  known gap for a future story if the module's controller-level logic grows.
- **Evidence:** Real `composer install` (PHP 8.5.6) + `assemble-config.sh`. PHPUnit 42/42 GREEN
  (`Configuration:` path confirms the worktree's own real composer-installed tree). Mutation
  spot-check output showing the new test fails for the right reason without F3's fix, and passes
  again once restored. Live Playwright 25/25 PASS against a real served site + real Chromium. Full
  5-request `curl` + header + body-content sequence showing correct per-variant caching with no
  stale cross-variant HIT. Docker (`o119t3-mysql`) + runserver process torn down; worktree
  filesystem restored via `git checkout`/`git clean` to only the one intended test-file diff plus
  the one pre-existing untracked file present before this round began. No mutating command run
  against the shared `groups-on-d11` checkout. Full detail in `handoff-T-green3.md`.

## O — diff gate (o4-mini second-opinion) adjudication — 2026-07-22T14:18:00Z
- **Decided:** Ran the diff gate over the full accumulated diff (base origin/main). Verdict BLOCK ×5.
  Adjudicated each against the actual code + T's live evidence (o4-mini sees only the diff/brief, not
  the live runs):
  - **B-1 (ribbon tooltip dead wiring) — REAL → F.** DoShowcaseHooks::pageTop() attaches
    do_chrome/tooltips + sets drupalSettings.doShowcase.ribbonTooltip, but NO ribbon element carries
    `data-do-tooltip` and ribbon.js never consumes ribbonTooltip — so the appended
    `showcase.ribbon` HelpText copy is dead. Fix: render a real `data-do-tooltip` ⓘ trigger on the
    ribbon (do_chrome house pattern) OR remove the unused ribbonTooltip setting + showcase.ribbon key.
    NOTE: the SWITCHER tooltip (the one the issue actually mandates) is correctly implemented —
    twig emits `<span class="do-showcase-info" data-do-tooltip=...>ⓘ</span>` from
    HelpText::get('showcase.switcher.<id>'), T live-verified it. So B-1 is a ribbon-only dead-wiring
    defect, not a switcher gap.
  - **B-2 (localStorage vs "per session") — REAL → F.** Issue/brief say "remembers choice per
    session" + "dismissible per session". localStorage persists across browser restarts; sessionStorage
    (or a session cookie with no max-age) matches the per-session contract. Fix both switcher.js +
    ribbon.js. Keep the graceful-degradation fallback.
  - **B-3 (no positive live-entry deep-link test) — REAL coverage gap → T.** Suite asserts [coming]
    entries have NO link but never asserts a [live] entry (Discovery ranking, route
    do_showcase.showcase) DOES render its deep-link with the right href. Add that assertion.
  - **B-4 (tooltip engine init "unverified") — MAINTAIN-with-evidence (REJECT).** Verified directly:
    do_chrome/js/do_chrome.tooltips.js registers Drupal.behaviors.doChromeTooltips + once() on
    `[data-do-tooltip]` within context — attaches to server-rendered triggers at load and re-attaches
    on AJAX. T live-verified switcher tooltips render. Not a real gap.
  - **B-5 (page_top #[Hook] discovery "unverified") — MAINTAIN-with-evidence (REJECT).**
    `#[Hook('page_attachments')]` is used by 4 sibling modules (do_chrome, do_group_pin,
    do_profile_stats); page_top uses the identical hook_implementations attribute mechanism, and T
    LIVE-verified the ribbon renders on a real served site (T-green2 cases 11-14 PASS). Empirically runs.
  - WARN/NIT: W-1 (anchor role=radio) — worth F's judgment; the roving-tabindex + role=radio +
    arrow-key pattern is already live-verified accessible, but F should confirm the anchor href still
    serves the no-JS fallback (it does — that's the ?variant= link). W-3 (t() outside trait) — phpcs
    already passed per F/T, but F folds the B-1/B-2 fix cleanly. NITs (indentation, trailing comma,
    @file header) — F address opportunistically while in these files.
- **Assumed:** none.
- **Hedged:** B-1 resolution direction (add trigger vs. remove wiring) left to F's judgment against
  the wireframe — the wireframe shows a ribbon with a link + dismiss; it does NOT depict a ribbon ⓘ
  tooltip, so REMOVING the dead ribbonTooltip wiring + the showcase.ribbon key is the likelier-correct
  minimal fix (the tooltip requirement is on switchers). F decides + documents.
- **Evidence:** dual-review-diff.md (o4-mini round 1); do_chrome.tooltips.js source; DoShowcaseHooks.php
  lines 62-104; VariantSwitcher.php + twig lines 37-38 (switcher tooltip correct); ShowcaseCatalog.php
  status/route; showcase.spec.ts lines 407-417; sibling page_attachments hooks; T-green2 cases 11-14.

## F — Phase F (round 4) — diff-gate B-1, B-2 fixes — 2026-07-22

- **Decided:** B-2 — switched both `do_showcase.switcher.js` and `do_showcase.ribbon.js` from
  `window.localStorage` to `window.sessionStorage` (identical `getItem`/`setItem` API, same keys,
  same try/catch graceful-degradation shape) — matches the issue/brief's literal "per session"
  wording exactly; sessionStorage clears at session/tab end, localStorage does not. Followed the
  task brief's pre-specified fix (not a cookie, to avoid touching request headers / anon page
  cache) rather than evaluating alternatives independently.
- **Decided:** B-1 — read `wireframe.md` Surface 3 (lines 204-263) and `handoff-D.md` (lines 33-39)
  first, per the task's explicit instruction. Neither depicts a ⓘ tooltip trigger anywhere on the
  ribbon (only POC text + link + dismiss ✕); the wireframe's cross-surface AA table has no
  tooltip-trigger row for the ribbon (contrast Surface 1, which explicitly lists one). The issue's
  "carries a do_chrome tooltip" requirement names the switcher, which already correctly implements
  it (unchanged this round, live-verified). Concluded the ribbon-tooltip wiring
  (`drupalSettings.doShowcase.ribbonTooltip` + `do_chrome/tooltips` attach in
  `DoShowcaseHooks::pageTop()`, plus the `showcase.ribbon` HelpText key) was dead code from an
  earlier round, not an intentional-but-incomplete feature. **Chose REMOVE** over adding a new
  trigger — the minimal, wireframe-faithful fix per the task's stated default.
- **Decided:** Removed the `showcase.ribbon` key from `\Drupal\do_chrome\HelpText::all()` — the key
  `do_showcase` itself appended in an earlier round of this same story (not another module's key),
  explicitly authorized by the task brief. `showcase.switcher.directory.layout` (the switcher's
  consumed key) and every other module's keys left untouched; confirmed via unmodified
  `ShowcaseHelpTextTest.php` (never asserted `showcase.ribbon`) and unmodified `do_chrome`'s own
  `HelpTextTest.php`, both still 100% green.
- **Assumed:** None new.
- **Hedged:** No live/Playwright browser run was performed in this round (the task scoped Tier 1 to
  headless PHPUnit/phpcs/phpstan + a real `drush site:install`/`config:import` + curl smoke-check,
  explicitly deferring live JS-persistence re-verification to T). Verified instead: careful
  read/diff confirming identical `getItem`/`setItem` API shape across the substitution, a live curl
  check of the server-rendered ribbon markup confirming no `ribbonTooltip` string remains in the
  response body, and the switcher's own tooltip (`data-do-tooltip="..."`) still correctly renders
  post-change — proving the ribbon-side removal did not disturb the switcher's correct tooltip
  wiring. Flagged precisely what T must re-verify live (session-boundary behavior via a fresh
  browser context, not just same-tab navigation) in `handoff-F4.md`.
- **Evidence:** Real `composer install` (PHP 8.5.6 via `/opt/homebrew/opt/php@8.4/bin/php`) +
  `scripts/ci/assemble-config.sh`. PHPUnit 42/42 GREEN (`Configuration:` path confirms the
  worktree's own real composer-installed tree), no test broke from the `showcase.ribbon` key
  removal. phpcs (Drupal+DrupalPractice): 0 new findings on all 4 changed files (confirmed via
  `git stash` before/after diff — identical pre-existing finding sets, same line numbers, both with
  and without this round's changes). phpstan level 1 on `DoShowcaseHooks.php` + `HelpText.php`:
  `[OK] No errors`. Real `drush site:install standard` + `config:import` (after fixing the random
  `config_sync_directory` + `system.site` UUID mismatch) → clean; `drush pm:list --status=enabled`
  confirms both `do_showcase`/`do_chrome` `Enabled`. Live curl smoke-check: `/showcase` → 200,
  ribbon renders with dismiss button, zero `ribbonTooltip` occurrences in body, switcher's
  `data-do-tooltip` still correct; `/` → 200. Full teardown (Docker removed, `git checkout --`/
  `git clean -fd` on `config/sync/`+`web/`, scaffold files removed); `git status --short` after
  shows only the 4 intended production-file diffs. No mutating command run against the shared
  `groups-on-d11` checkout. Full detail in `handoff-F4.md`.
  instruction; the catalog has exactly two `status: 'live'` entries (`discovery-ranking`,
  `persona-switcher`) and both route through the identical `ShowcaseController::page()` link-render
  branch, so one live-entry assertion is sufficient to pin the mechanism without duplicating
  coverage.
- **Decided:** Asserted the exact `href="/showcase"` value (not just link presence) by reading
  `ShowcaseController::page()`'s actual render-array contract (`#url => Url::fromRoute($entry
  ['route'])` where `route = 'do_showcase.showcase'`) and cross-checking `do_showcase.routing.yml`
  (`path: '/showcase'`) rather than guessing — per the task's explicit instruction not to guess the
  href.
- **Decided:** Verified B-2's "per-session" contract using two independent Playwright
  `browser.newContext()` instances (not two tabs/pages in one context) as the live proxy for "a
  fresh session" — Playwright contexts do not share `sessionStorage`/`localStorage`, so this is the
  strongest automatable stand-in for "browser restart" available without literally restarting the
  browser process between test steps.
- **Assumed:** None new.
- **Hedged:** None — both halves of the B-2 contract (same-tab persists, fresh-context reverts) were
  independently confirmed live per the task's explicit gate ("Report GREEN for the sessionStorage/
  per-session behavior ONLY if a real browser confirmed both halves").
- **Evidence:** PHPUnit 42/42 GREEN (277 assertions), run twice (pre- and post-live-verify),
  identical both times; tree marker confirms the worktree's own real, non-symlinked `web/core`.
  phpcs: 0 new findings on the 2 files F4 actually changed (`DoShowcaseHooks.php`: 3 pre-existing
  `t()`-in-class warnings, lines 86/90/99, predate this round; `HelpText.php`: 18 errors/6 warnings
  total, all on lines 21-139, zero in the 150-169 diff region). phpstan level 1 on the 2 changed
  files: `[OK] No errors` (the 1 pre-existing `new.static` finding on `ShowcaseController.php` is
  unrelated/out-of-scope, confirmed unchanged from F3/T-green3's prior reports). Real `drush
  site:install` + `config:import` (config_sync_directory + UUID fixes reused from T-green3's
  recipe) → clean; both `do_showcase`/`do_chrome` `Enabled`. New B-3 Playwright case added,
  mutation-spot-checked (short-circuited the link-render condition in `ShowcaseController::page()`,
  reran alone — failed on the exact missing-locator symptom the diff-gate named; restored, reran —
  passed). Full target-spec Playwright suite (`showcase.spec.ts` + `nav.spec.ts`): 26/26 PASS (25
  prior + 1 new). LIVE sessionStorage re-verify: same-tab persistence (switcher choice + ribbon
  dismissal both survive same-context navigation, confirmed via direct `sessionStorage`/
  `localStorage` key inspection — sessionStorage non-empty, localStorage empty) AND fresh-context
  reversion (a second, independent `browser.newContext()` reverts the switcher to the server default
  "cards" and re-shows the ribbon, with zero inherited sessionStorage keys) both independently
  confirmed in a real Chromium browser. LIVE B-1 re-verify: zero `ribbonTooltip` string in
  server-rendered markup, zero `[data-do-tooltip]` elements inside the ribbon's own DOM, zero
  console/page errors on `/` or `/showcase`, switcher's own tooltip trigger unaffected. New recipe
  note: `php -S` must be started with `web/` as cwd (`cd web && php -S host:port .ht.router.php`),
  not `php -S host:port web/.ht.router.php` from the repo root, or the router's relative
  `index.php` resolution fatals on every request. Full Docker teardown (`o119t4-mysql` removed,
  confirmed empty via `docker ps -a --filter name=o119t4`) + worktree filesystem restore
  (`git checkout --`/`git clean -fd` on `config/sync/`+`web/`, scaffold files/settings.php/vendor/
  node_modules removed); `git status --short` after teardown shows only the 1 intended test-file
  diff (`tests/e2e/showcase.spec.ts`) plus the 2 pre-existing untracked files present before this
  round began. No mutating command run against the shared `groups-on-d11` checkout — all work
  confined to the isolated worktree. Full detail in `handoff-T-green4.md`.

## A — Phase 7 (anti-duplication gate) — 2026-07-22

- **Decided:** PASS. Reviewed the full accumulated diff (origin/main 37e8582...HEAD 38e9d3c) against
  survey.md's Reuse map and handoff-F/F2/F3/F4 across all four implementation rounds. All six
  anti-duplication checks pass: (1) HelpText append-only, net delta is exactly one live key after
  F4's dead-key removal; (2) no second tooltip engine — `do_chrome/tooltips` is the sole mechanism,
  zero vendored tippy/popper in `do_showcase`; (3) `/showcase` route+controller matches
  `do_notifications`/`do_discovery`'s `ControllerBase`+`.routing.yml` pattern exactly; (4) ribbon
  uses `page_top` (correct hook for visible markup) as the same single-global-attach-point shape as
  `DoChromeHooks::pageAttachments()`; (5) `VariantSwitcher` is a plain service, not a duplicate
  `Plugin/Block` — confirmed no `Plugin/Block` directory exists under `do_showcase/src`, and the
  service-vs-block divergence was justified in writing at Phase 3 and documented again in handoff-F;
  (6) no other reimplementation of do_chrome/do_discovery/core functionality found — session
  persistence (sessionStorage) is genuinely new machinery per the Reuse map's own finding that no
  existing do_* analog exists to extend instead.
- **Decided:** The diff-gate's B-1 finding (dead ribbon-tooltip wiring from round 1) is not
  duplication — it was an unused entry point into the correctly-reused single tooltip engine, not a
  second tooltip system. F4's fix removed the dead wiring rather than building a ribbon-specific
  trigger, which is the more reuse-consistent resolution; confirmed the net diff leaves zero
  tooltip-related code in `do_showcase.ribbon.js`.
- **Assumed:** None new.
- **Hedged:** None — all six checks confirmed by direct file inspection (grep/find + full reads),
  not solely by trusting F's own handoff narrative.
- **Evidence:** `docs/groups/modules/do_chrome/src/HelpText.php` (tail, confirms one live appended
  key), `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (HelpText::get call,
  #cache/#tooltip contract), `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (page_top
  hook, no tooltip wiring on ribbon), `docs/groups/modules/do_showcase/do_showcase.libraries.yml`,
  `docs/groups/modules/do_showcase/js/{do_showcase.switcher.js,do_showcase.ribbon.js}` (zero
  tooltip/tippy references), `docs/groups/modules/do_showcase/do_showcase.routing.yml` +
  `src/Controller/ShowcaseController.php` vs. `do_notifications.routing.yml` +
  `NotificationSettingsController.php` (side-by-side pattern comparison), `find
  docs/groups/modules/do_showcase/src -type d` (confirms no Plugin/Block dir), `find ... -iname
  "*tippy*" -o -iname "*popper*"` (zero matches). Full detail in `handoff-A-dup.md`.

## U — Phase 8 (UI Walkthrough) — 2026-07-22

- **Decided:** PASS. Drove all 3 `do_showcase` UI surfaces (variant switcher, `/showcase` tour page,
  POC ribbon) in a real headless Chromium browser (Playwright), on the real navigated path (click
  the ribbon's link into `/showcase`, click switcher options, Tab to the dismiss button), at desktop
  (1280x900) and 360px viewports, plus an axe-core WCAG 2.x A/AA + 2.2 AA pass on each surface. Every
  control in the wireframe's per-surface checklist (switcher click/keyboard/tooltip/no-JS-fallback;
  tour-page live-link/dead-link/badge/persona checks; ribbon show-anon/show-auth/dismiss/persist/
  nav-non-overlap) exercised and confirmed PASS. Zero console/page errors on any page/viewport. Zero
  axe violations on any of the 3 surfaces at either viewport. Re-ran the full 26-case target-spec
  Playwright suite (`nav.spec.ts` + `showcase.spec.ts`) against this round's own freshly-provisioned
  live instance (not just trusting T-green4's prior run) — 26/26 green, confirming no drift since
  T's last GREEN.
- **Decided:** No independent third re-run of the fresh-browser-context ribbon/switcher reversion
  test (T-green4's Case B already covers this directly and recently on identical code) — this
  round's storage-key/cookie inspection corroborates the mechanism (sessionStorage only, zero
  localStorage keys, zero session cookie for anon) rather than duplicating the exact two-context
  experiment a third time. Not treated as a coverage gap.
- **Assumed:** None new — followed T-green4's proven Docker/serve recipe verbatim (namespaced
  `o119u1`, port 33081/38191, distinct from all T rounds), confirming its two documented gotchas
  (must `cd web` before `php -S`; CLI memory_limit must be raised for `config:import`) plus one new
  one this round (`web/sites/default` is installer-locked read-only — `chmod u+w` required before
  teardown can remove `settings.php`/`files`), which is now recorded here for the next round's reuse.
- **Hedged:** None — every checklist item backed by a direct DOM/aria/console/axe observation (raw
  `curl` cross-check for the no-JS fallback, direct bounding-box math for the nav-non-overlap claim,
  direct cookie-jar inspection for the no-server-session claim), not solely by re-stating F/T's prior
  narrative.
- **Evidence:** Full raw results JSON + 4 screenshots at
  `/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/u-walkthrough/`.
  Full detail in `handoff-U.md`.

## S — Phase 9 (spec audit / final quality gate) — 2026-07-22

- **Decided:** **PASS.** All 8 #119 acceptance criteria are backed by non-vacuous tests (AC-to-test
  table in handoff-S.md); implementation conforms to the brief (incl. Brief-gate B-2 sessionStorage =
  genuinely "per session", proven by T-green4 Case B fresh-context reversion), the wireframe, and
  both D-gate resolutions; all five dual-review diff-gate BLOCKs correctly resolved (B-1/B-2/B-3 fixed
  + verified, B-4/B-5 rejected with direct source evidence); access/security sound (real
  `_permission: 'access content'`, no server-session write, no secrets); WCAG 2.2 AA holds live (U
  axe-core 0 violations, 3 surfaces × 2 viewports); scope is exactly the phase scope (SC-4/5/6 real
  comparisons correctly NOT built — copy-only [coming] with NULL routes). A-plan + A-dup gates hold
  under S's own independent read. Ready for O to open the MR.
- **Assumed:** Tier-3 visual pixel-diff is N/A for this story — the wireframe is low-fidelity,
  structure-only, with no color system / hex values (AA contrast explicitly deferred to F's CSS
  pass), so there is no static pixel reference to diff against. U's rendered-DOM + navigated-path +
  axe-core walkthrough (Phase 8, PASS) is the correct visual/WCAG gate and it passed; no report.html
  produced.
- **Hedged:** Two ADVISORY items raised but resolved to PASS (not ADVISORY-HOLD, not REWORK): (1)
  persona `name` fields ("Anonymous"/proper names) are raw strings not t()-wrapped — the catalog test
  consciously scopes t()-wrap to entry title/decision_sentence, proper names correctly aren't
  translated; residual "Anonymous"-is-a-common-noun ambiguity is a one-word i18n nit on a POC, a
  defensible reading of Brief-gate W-3, not a source-of-truth defect. (2) `showcase.spec.ts`
  "lists all six comparison entries" uses a page-level badge assertion inside its per-entry loop
  (proves *a* badge exists, not *this* entry's) — but per-entry status is fully pinned by
  ShowcaseCatalogTest + the B-3 live-deep-link case, so no AC is unbacked; loose-but-not-vacuous,
  advisory only. Also flagged: stale `page_attachments` doc-comment in do_showcase.module (code is
  correctly `page_top`), and the `?variant=` fallback href dropping pre-existing query strings —
  both cosmetic/forward-looking, non-blocking.
- **Evidence:** Read the full diff (origin/main...HEAD; 6629 insertions, one new module + one
  append-only HelpText edit + one e2e spec, no out-of-Owns files); `php -l` clean on all 5 changed
  PHP files; read every source + test file line by line; read O's diff-gate adjudication
  (decisions.md L600-640) confirming all 5 BLOCKs dispositioned. Did not re-provision Docker/DB — T
  verified GREEN twice + U once on this exact HEAD; S's mandate is Tier-3 spec/quality, not re-running
  Tier-1/2. Full detail in handoff-S.md (both worktree handoff dir + scratchpad o119-handoffs/).

## O — PR opened — 2026-07-22T15:00:00Z
- **Decided:** Full pipeline complete, S (Opus) PASS with 3 non-blocking advisories. Per coordinator
  go-ahead (docs-in-PR, matching #147's convention): rebased onto origin/main @ 37e8582 (clean, 18
  commits, no conflict — origin/main confirmed ancestor of HEAD), ran the #109 config-path check
  (do_showcase Unit tests are pure — no config/fixture file reads, nothing source-relative to
  relocate), pushed 0119-variant-framework to origin (GitHub canonical — this repo's PR flow, unlike
  the groupsdrupalorg GitLab fork model), opened PR #148 → main. Assigned aangelinsf; labels
  enhancement + showcase (mirroring issue #119); AI involvement disclosed in the body + Co-Authored-By
  on every commit. MERGEABLE; CI (E2E Playwright, Functional BrowserTestBase, Kernel) pending.
- **Assumed:** none.
- **Hedged:** The 3 S advisories are carried into the PR body as non-blocking (persona names not
  t()-wrapped [defensible]; stale page_attachments doc-comment; ?variant= replaces vs merges query
  string — a note for SC-4/5/6). None gates this PR.
- **Evidence:** PR https://github.com/Performant-Labs/groups-on-d11/pull/148; `git rebase origin/main`
  clean; `gh pr checks 148` (3 pending).
- **Do NOT merge** — a human maintainer merges; the coordinator watches CI to green.
