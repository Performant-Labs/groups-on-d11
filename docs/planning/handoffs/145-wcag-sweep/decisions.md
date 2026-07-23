# Decision journal — #145 MC-A11Y sweep

## O — Phase 1 (survey + brief)
- **Decided:** New spec `tests/e2e/a11y-audit.spec.ts`; add `@axe-core/playwright` dev dep.
- **Decided:** Eight surfaces (see survey). RTL + maps captured as documented waivers, not scope expansion.
- **Decided:** D skipped (a11y fixes only — no new UI surface). Lean pipeline order preserved.
- **Assumed:** Seeded site under `gm145-wcag.ddev.site` exposes the surfaces via the same routes as siblings.
- **Hedged:** If a serious/critical violation would require a module refactor, downgrade to documented waiver rather than expand scope — surface to operator if >2 such waivers accumulate.
- **Evidence:** Issue `Cover:` list; `tests/e2e/` inventory; `package.json` deps.

## A — Phase 3 (up-front review)
- **Decided:** PASS. Plan aligns with existing Playwright convention (one spec file per feature, per-test route walk — see `showcase.spec.ts`, `phase4.spec.ts`). NEW `tests/e2e/a11y-audit.spec.ts` is the correct shape; no analogous a11y spec exists to extend. `@axe-core/playwright` is the right dep (first-party Playwright integration; pa11y would require a separate runner outside `test:e2e`).
- **Decided:** Per-route `test(...)` loop is preferred over `describe`-per-surface — matches how `phase4.spec.ts` structures multi-surface runs; keeps waivers visible as `test.skip(..., 'justification')` lines a PR reviewer can grep for.
- **Assumed:** F will resolve the audit-table path inconsistency between brief (`docs/planning/handoffs/145-wcag-sweep/a11y-audit.md`) and survey (`test-results/a11y-audit.md`) — recommend writing to `test-results/a11y-audit.md` at runtime (CI-writable), then committing a copy to the handoff path as a post-run step. Do not have the spec itself write into `docs/`.
- **Hedged:** Sibling stories #116, #111, #124 may touch `/`, `/showcase`, `/streams/*` templates concurrently — merge-order risk, not architectural. Flag to O for rebase discipline; not a block.
- **Hedged:** Scope discipline is written into the brief ("Surfaces (fixed list — do not expand)") and the survey's Fixes envelope enumerates the five expected fix classes. Both are enforceable at Phase 7. No block.
- **Evidence:** `tests/e2e/showcase.spec.ts` (per-test route walk), `tests/e2e/phase4.spec.ts` (multi-surface single spec), `package.json` (@playwright/test ^1.49.1 — axe-core/playwright compatible), brief.md §Surfaces, survey.md §Audit method.

## T — Phase 4 (RED)
- **Decided:** New spec `tests/e2e/a11y-audit.spec.ts` — one `test(...)` per named surface (not describe-per-surface), matching `phase4.spec.ts`'s multi-surface-single-spec shape and A's Phase-3 preference. Shared `auditRoute()` helper runs `AxeBuilder({page}).withTags([...]).analyze()`, filters `serious`/`critical` impact violations, appends a Markdown row to `test-results/a11y-audit.md` (a `beforeAll` writes the header), and asserts the filtered array is empty.
- **Decided:** Route resolution corrections against the real codebase (brief/survey used shorthand that doesn't match actual routes):
  - Brief's `/groups` → actual route is `/all-groups` (confirmed via `nav.spec.ts`, `directory-cards.spec.ts`, `group-links.spec.ts` — no `/groups` route exists in this codebase).
  - Brief's `/streams/{one}` → do_streams ships `do-streams/demo/{membership|following|global}` (per `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml`); no literal `/streams/*` route exists. Picked `/do-streams/demo/global` as the one representative route per survey.md.
  - `/group/{seed}` and `/group/{seed}/members` resolve via the public `/all-groups` directory by card label (`DrupalCon Portland 2026`), mirroring `group-about.spec.ts`/`group-links.spec.ts`'s existing gid-instability workaround — gids are not stable across re-seeds.
  - `/group/add/{type}` uses `community_group`, matching `create-group.spec.ts`/`nav.spec.ts`'s established machine name.
- **Decided:** Waivers (RTL toggle, maps) authored as `test.skip(name, reason)` lines per brief — grep-able, not silently dropped.
- **Assumed:** F will install `@axe-core/playwright` (added to `package.json` devDependencies by this handoff, not installed) and run this spec against the fully seeded `gm145-wcag.ddev.site` per the brief's Phase-6 pipeline.
- **Hedged:** `/personas` route is assumed live per brief/survey naming; not independently re-verified against a routing file (no persona-switcher spec module route table was inspected beyond `persona-switcher.spec.ts`'s own conventions, which navigate via `/` + UI rather than a direct `/personas` goto). If F finds no `/personas` route exists, this is a route-naming fix to the test (T's remit), not a production concern — flag back to T at GREEN if so.
- **Evidence:** `tests/e2e/showcase.spec.ts`, `tests/e2e/manage-members.spec.ts`, `tests/e2e/group-about.spec.ts`, `tests/e2e/group-links.spec.ts`, `tests/e2e/create-group.spec.ts`, `tests/e2e/nav.spec.ts`, `tests/e2e/persona-switcher.spec.ts` (conventions); `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml` (streams route); `playwright.config.ts`, `package.json` (baseURL/deps).

### RED confirmation
Command run from worktree root:
```
npx playwright test tests/e2e/a11y-audit.spec.ts --list
```
Observed output: `Error: Cannot find module '@playwright/test'` (MODULE_NOT_FOUND), thrown while loading `playwright.config.ts` — this worktree has **no `node_modules` at all** yet (confirmed: `ls node_modules` → "No such file or directory"), so even the pre-existing base dependency fails to resolve, not just the newly-added `@axe-core/playwright`. This is the correct RED reason for a brand-new spec in an unprepared environment: a missing-dependency failure, never a false "0 violations" pass and never a syntax/import-path defect in the spec itself.

Independent sanity check (since `--list` cannot get past config load without deps): `node --check tests/e2e/a11y-audit.spec.ts` exits 0 — the spec file itself is syntactically well-formed; the RED is entirely attributable to the missing `node_modules`, not to an authoring defect.

### Path to GREEN (F's remit)
1. `npm install` (picks up the new `@axe-core/playwright` devDependency this handoff added to `package.json`).
2. Assemble + spin up `gm145-wcag.ddev.site`, seed per `WAVE-EXECUTION-HANDOFF.md` §6.6 (site:install → cim → seed → runserver).
3. Run `npx playwright test tests/e2e/a11y-audit.spec.ts` against the seeded site.
4. For any real serious/critical axe violation surfaced: fix at the source (aria/alt/heading-order/focus-visible/contrast per survey.md's "Fixes envelope"), or — if a fix would require a module refactor — downgrade to a documented `test.skip(name, reason)` waiver (>2 such waivers → escalate to O per brief's hedge).
5. If `/personas` (or any other route) 404s against the real site, that is a test-authorship fix for T at GREEN, not a production concern (see Hedged above).

## Ready for F
RED confirmed valid (missing-dependency failure, not a false pass or an authoring defect). F may implement/install against this spec.

## F � Phase 5 (implement against RED)
- **Decided:** Fixed the two real, live serious/critical axe violations (color-contrast on
  `.gc-badge--success` and `.gc-badge--info`, surfaced on `/all-groups` and `/group/{seed}`)
  by darkening `--gc-color-success` and de-aliasing + fixing `--gc-color-info` in
  `web/themes/custom/groups_chrome/css/tokens.css` (token-value-only edit, per survey.md's
  fix envelope "Contrast fails -> adjust color token"; no class/selector changes, respecting
  `primitives.css`'s own "do not edit base classes" contract).
- **Decided:** Also fixed `--gc-color-warning` proactively (same token-pair defect class,
  same file, same one-line pattern) even though `.gc-badge--warning` renders nowhere in the
  current seed/templates and axe never flagged it on the 8 crawled surfaces -- per this
  project's own retrospective ("fix the defect class, not the cited instance",
  WAVE-EXECUTION-HANDOFF.md Sec12.7). `--gc-color-danger` already passes AA and was left alone.
- **Decided:** Did NOT edit `tests/e2e/a11y-audit.spec.ts` despite finding a real defect in it
  (see Hedged below) -- flagged in `handoff-F.md` "Tests that look wrong" for T to fix at
  Phase 6, per F's role boundary (T authors/owns all tests).
- **Decided:** One-time environment-provisioning step (not a code change): partial-imported
  `do_streams`'s own `config/install/views.view.do_streams_demo.yml` directly, since a
  module's `config/install/` is only auto-imported by Drupal's actual module-INSTALL
  codepath, not by a general `config:import` -- and `assemble-config.sh` pre-marks modules
  "enabled" in `core.extension.yml` before `site:install`/`config:import` ever run, so that
  codepath never fires here. `/do-streams/demo/global` 404'd until this one-time
  `drush config:import --partial --source=.../do_streams/config/install -y` + `drush cr`.
- **Assumed:** T's "F will resolve the audit-table path" assumption (A's Phase-3 entry) --
  wrote to `test-results/a11y-audit.md` at runtime, copied to
  `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` as the brief specifies. Confirmed via
  a local-only verification copy (deleted before handoff; `diff` confirms the real spec file
  is byte-identical to what T authored) since the real spec currently cannot generate this
  table at all (see Hedged below).
- **Hedged (the important one):** `tests/e2e/a11y-audit.spec.ts`'s two waiver calls
  (`test.skip('RTL toggle audit', 'reason-string')` / `test.skip('Maps surface audit',
  'reason-string')`) use an invalid Playwright overload -- `test.skip(title, string)` at file
  scope is not one of the two "declare a named skipped test" shapes
  (`(string,function)` / `(string,object,function)`); traced to
  `node_modules/playwright/lib/common/index.js` `_modifier()`, which falls through to
  pushing a whole-file `_staticAnnotations` skip entry, silently skipping ALL 8 real tests
  plus both waivers -- zero assertions ever execute against the real file as authored.
  Confirmed empirically (scratch repro: removing the two calls let the remaining test run and
  pass; restoring them with the correct `() => {}` callback form let all 10 run/skip
  correctly). This is a genuine test-authorship defect, not an environment or code problem --
  flagged to T in `handoff-F.md`, not fixed here. The underlying a11y fixes ARE verified
  correct (0 serious/critical across all 8 real surfaces) via a local-only verification copy,
  never staged, deleted before this handoff.
- **Hedged:** `/personas` genuinely 404s (T's own Phase-4 hedge confirmed true) -- no
  `/personas` route exists anywhere in `docs/groups/modules/*/*.routing.yml`; only
  `/showcase` and `/persona-switch/{persona}` (do_showcase.routing.yml). 0 axe violations on
  this route reflects Drupal's generic 404 page, not a validated persona-switcher surface --
  flagged to T as a route-naming fix (navigate via `/` + the switcher UI, matching
  `persona-switcher.spec.ts`'s own convention), not edited here.
- **Evidence:** `node_modules/playwright/lib/common/index.js` (`_modifier()` source, lines
  ~2373-2392); scratch reproduction runs (removed-vs-restored waiver calls); `curl` + `grep`
  across `docs/groups/modules/*/*.routing.yml` for `/personas`; axe-core violation JSON for
  `.gc-badge--success`/`.gc-badge--info` pre-fix; WCAG relative-luminance contrast
  calculations for all 4 semantic token pairs (success/warning/danger/info) both pre- and
  post-fix; `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml`
  + Drupal's ConfigInstaller behavior for the do-streams provisioning gap;
  `web/themes/custom/groups_chrome/css/primitives.css` header contract ("do not edit base
  classes"); `grep` confirming zero other `--gc-color-warning`/`.gc-badge--warning`
  consumers and exactly one other `--gc-color-success` consumer (`directory.css:120`,
  text-on-white, unaffected negatively by darkening).

## Ready for T (Phase 6)
Real a11y fixes verified GREEN via local-only copy (never staged; real spec confirmed
byte-identical to T's Phase-4 handoff via diff). T must fix the `test.skip(title, string)`
syntax defect (2 calls, lines 188-196) before the real spec can execute at all, and should
also correct the `/personas` route per its own Phase-4 hedge. Full detail in
`docs/handoffs/145-wcag-sweep/handoff-F.md`.

## T — Phase 6 (GREEN + Tier 2)
- **Decided:** The prior "GREEN" (F's local-only-copy verification, 8/8 pass 0/0/0) was a
  **false pass** — the real `tests/e2e/a11y-audit.spec.ts` as authored at Phase 4 never
  executed any assertion at all, because `test.skip('name', 'reason-string')` at file scope is
  an invalid Playwright overload that silently skips the ENTIRE file (all 10 declarations), not
  just the two intended waivers. This run is the first real execution of this suite.
- **Decided:** Fixed both waivers by converting each into its own `test(...)` calling
  `test.skip(true, reason)` in the test body — matching the in-test-body waiver shape already
  established in `manage-members.spec.ts` (`test.skip(!pendingRowExists, '...')`). This is a
  valid 2-arg `(condition, reason)` overload; verified it cannot leak file-wide by confirming
  all 8 real tests execute (not skip) in the rerun below.
- **Decided:** Fixed `/personas` (confirmed 404 by both T's Phase-4 hedge and F's Phase-5
  re-verification) by replacing the direct `page.goto('/personas')` test with a test that
  navigates to `/` and scopes the axe scan via `AxeBuilder.include('form.do-showcase-persona-switcher-form')`
  — matching `persona-switcher.spec.ts`'s convention that the switcher is embedded UI, not a
  standalone route. Chose to keep this as a real, non-redundant 8th test (scoped to the widget)
  rather than drop it, since the brief's acceptance criterion names 8 surfaces.
- **Decided:** `auditRoute()` gained an optional `includeSelector` param (typed `string`,
  passed through to `AxeBuilder.include()`) to support the scoped persona-switcher scan; no
  other production/test logic changed.
- **Assumed:** None new — F's re-confirmation of both bugs (via runtime source tracing and
  routing.yml grep) was taken as sufficient without independently re-deriving the Playwright
  source-level root cause.
- **Evidence:** Rerun of `tests/e2e/a11y-audit.spec.ts` against seeded `gm145-wcag.ddev.site`
  (`BASE_URL="https://gm145-wcag.ddev.site" npx playwright test tests/e2e/a11y-audit.spec.ts`)
  → `8 passed (9.2s)`, `2 skipped`; regenerated `test-results/a11y-audit.md` (8 rows, all
  0/0/0), copied to `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` replacing F's
  placeholder copy; `git diff --cached --name-only` confirms only `tokens.css` staged (phpcs
  no-op); `manage-members.spec.ts:155` (existing in-test-body `test.skip` convention referenced).

### GREEN confirmation
```
Running 10 tests using 1 worker

  ok  1 › / (front page) has no serious/critical axe violations (1.3s)
  ok  2 › /all-groups (directory + card grid + filters) has no serious/critical axe violations (1.2s)
  ok  3 › /group/{seed} (group homepage) has no serious/critical axe violations (1.3s)
  ok  4 › /showcase (variant switcher + POC ribbon) has no serious/critical axe violations (897ms)
  ok  5 › persona-switcher widget (embedded on /, no standalone /personas route) has no serious/critical axe violations (652ms)
  ok  6 › /group/{seed}/members (manage-members table) has no serious/critical axe violations (1.1s)
  ok  7 › /group/add/{type} (create-group form) has no serious/critical axe violations (840ms)
  ok  8 › /do-streams/demo/{scope} (shared stream shell, representative route) has no serious/critical axe violations (935ms)
  -   9 › RTL toggle audit (waived)
  -  10 › Maps surface audit (waived)

  2 skipped
  8 passed (9.2s)
```

## Ready for U (Phase 6 complete)
No blocking issues. UI surface exists (a11y sweep touches multiple rendered pages) — handing
off to U for the keyboard-walk acceptance criterion (Tab traversal, visible focus) across
directory / group-home / manage-members / create-group, plus a live look at the
persona-switcher widget this Phase-6 fix scoped its axe scan to.

## O — Diff-Gate (dual-review, second-opinion, round 1)
- **Decided:** Reviewer (o4-mini) verdict was BLOCK/6 but triage found only 1 real finding.
- **Accepted:**
  - **B-3 (route drift):** Brief said `/groups`/`/personas`; codebase routes are `/all-groups` and a persona-switcher block on `/`. Fixed by aligning the brief (and noting T's earlier T-RED correction was correct all along). Also renamed `/streams/{one}` → `/do-streams/demo/global`.
- **Rejected as reviewer misreads (with evidence):**
  - **B-1** "`@axe-core/playwright` missing": `grep` confirms it IS in `package.json` (`^4.10.0`).
  - **B-2** "`test:e2e` script missing": `grep` confirms `"test:e2e": "playwright test"` exists.
  - **B-4** "`includeSelector` unguarded": `a11y-audit.spec.ts:137` already has `if (includeSelector)` guard.
  - **B-5** "audit report not committed": `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` IS staged. `test-results/` is Playwright's runtime output dir — not tracked by convention.
  - **B-6** "`test.skip(true, reason)` in-body untested": T empirically confirmed 8 passed / 2 skipped in Phase 6. Matches existing convention in `tests/e2e/manage-members.spec.ts:155`.
  - **W-1**: same misread as B-6.
  - **NIT-1** "contrast-ratio comments missing on tokens": `tokens.css:37-51` already has detailed comments with before/after values and ratio failures. Reviewer didn't read them.
  - **NIT-2** rename param: cosmetic; defer.
- **Evidence:** dual-review-diff.md; a11y-audit.spec.ts:130-140; tokens.css:37-51; package.json.
- **Assumed:** Round 2 with the aligned brief will PASS or drop the accepted rejections. If o4-mini re-raises B-1/B-2/B-4/B-6 with the same misreads, treat as reviewer noise (documented rejections stand).

## O — Diff-Gate round 2: PASS
- Reviewer accepted all 6 responses. Gate cleared. Proceeding to U.

## U — Phase 8 (UI walkthrough)
- **Decided:** REWORK. Manual keyboard walk across all four required surfaces
  (`/all-groups`, `/group/1`, `/group/1/members`, `/group/add/community_group`)
  found the "Skip to main content" link is completely covered by the fixed
  `.do-showcase-ribbon` (POC demo banner, `position: fixed; z-index: 1000`)
  when the skip-link receives keyboard focus — both occupy screen rect
  (0,0)–(1280,~40) and `elementFromPoint` at that location returns the
  ribbon's text, not the skip-link, while it is the active element. First
  tab stop on every page is effectively invisible to a sighted keyboard user.
- **Decided:** Everything else PASSES — full tab reachability, logical order,
  no focus traps (Shift+Tab confirmed from multiple points), at least one
  Enter-key activation confirmed per surface (nav link, breadcrumb, member
  role-filter "Go" submit), and non-color status-cue badges on `/all-groups`
  (Open/Archive/Geographical/Event planning/Distribution/Working group/
  Moderated all carry readable text, not color-only).
- **Assumed:** Playwright MCP tools (`mcp__playwright__browser_*`) were not
  registered in this environment; drove the walk with a direct Playwright
  Node script instead (same Chromium engine, same keyboard-driven method,
  screenshots + JSON traces as evidence), deleted after the run per the
  no-throwaway-scripts-committed rule.
- **Hedged:** An initial `getComputedStyle` probe also flagged the six
  primary-nav links (Groups/Home/Activity/Stream/Search/Log in) as
  `outline: none`, which would have been a second REWORK-class finding —
  resolved as a false negative by direct screenshot confirmation
  (`nav-focus-stop10..14.png` all show a clear blue focus rectangle). Only
  the skip-link defect is real; do not re-flag the primary nav.
- **Evidence:** `docs/handoffs/145-wcag-sweep/handoff-U.md`; screenshots/JSON
  traces in session scratchpad
  (`...\scratchpad\u145\skiplink-zoom.png`, `nav-focus-stop10..14.png`,
  `all-groups-badges.png`, `*-tabwalk.json`).

## F — Phase 5 rework (skip-link z-index)
- **Decided:** Root cause is `.do-showcase-ribbon`'s hard-coded `z-index: 1000` in
  `docs/groups/modules/do_showcase/css/do_showcase.css:18` (source-side; the assembled
  copy under `web/modules/custom/do_showcase/` is a gitignored build artifact). Olivero's
  own (uneditable, "DO NOT EDIT" header) `.skip-link.focusable:focus` sits at
  `z-index: 503` (`web/core/themes/olivero/css/components/skip-link.css:39-45`) — the
  ribbon's 1000 beats it and paints over the focused skip-link, exactly as U reported.
  `groups_chrome`'s own z-index token scale (`tokens.css:102-104`) tops out at
  `--gc-z-tooltip: 800`; the ribbon's 1000 was an outlier against that scale, not an
  intentional "beat everything" value.
- **Decided:** Fixed by lowering `.do-showcase-ribbon`'s `z-index` from `1000` to `499` —
  one property-value change, same file, no template/markup/module change, no new
  dependency. Chose a literal number over `var(--gc-z-overlay)` (500) because
  `do_showcase`'s `ribbon` library (`do_showcase.libraries.yml`) has no dependency on
  `groups_chrome`'s tokens library; introducing that cross-module coupling would exceed
  the rework's explicit CSS-only/no-refactor scope cap. `499` sits one below
  `--gc-z-overlay` in spirit while staying self-contained in the one file needing the fix.
- **Decided:** Re-ran `bash scripts/ci/assemble-config.sh` via `ddev exec` (host has no
  `php` on PATH in this shell; DDEV's container does) to sync the fix into
  `web/modules/custom/do_showcase/`, then `ddev drush cr` to bust CSS aggregation.
- **Assumed:** Confirming Olivero's skip-link is genuinely fixed at `503` (not itself
  affected by the ribbon fix) was sufficient verification that no *further* stacking
  conflict exists above the skip-link's own layer — did not additionally audit every other
  `position: fixed`/`sticky` element in the chrome for values between 499 and 503, since
  none were reported as occluding by U and the brief scoped this rework to the one named
  defect.
- **Evidence:** Manual Playwright verification (scratchpad script, deleted after run) across
  `/all-groups`, `/`, `/group/add/community_group`: post-fix, `document.activeElement` after
  one `Tab` press is the skip-link (`z-index: 503` computed), `.do-showcase-ribbon` computed
  `z-index: 499`, and `elementFromPoint` at the skip-link's own rect returns the skip-link
  itself (`topElIsActiveOrDescendant: true`) on all three surfaces — the exact inversion of
  U's defect. Screenshot `skiplink-fix-verify.png` (scratchpad) shows the skip-link rendered
  as a solid black bar with legible white text, fully on top. Ribbon confirmed still
  functionally intact (`display: flex`, `visibility: visible`, full banner text) when
  unfocused — a pure stacking reorder, not a visibility change. Re-ran
  `BASE_URL="https://gm145-wcag.ddev.site" npx playwright test tests/e2e/a11y-audit.spec.ts`
  → unchanged `8 passed (13.4s)`, `2 skipped`, confirming no regression from the CSS change.

## Ready for T / U (rework re-verification)
Skip-link occlusion fixed at the source (`do_showcase.css`, one property value + comment).
Axe suite still 8 passed / 2 skipped (no regression). T should re-run Tier 2 if desired; U
should re-run the manual keyboard walk on the same four surfaces to confirm the skip-link is
now visibly focused before closing out Phase 8. Full detail in
`docs/handoffs/145-wcag-sweep/handoff-F.md` ("Rework: Phase 5 — skip-link occlusion fix").

## U — Phase 8 rerun

**Verdict: PASS.** Re-verified F's z-index fix (`.do-showcase-ribbon` 1000 → 499 in
`docs/groups/modules/do_showcase/css/do_showcase.css`) via a throwaway Playwright
script against `https://gm145-wcag.ddev.site/`. On all four required surfaces
(`/all-groups`, `/group/1`, `/group/1/members`, `/group/add/community_group`), the
first `Tab` press lands on the skip-link, computed `z-index: 503` beats the ribbon's
`499`, and `elementFromPoint` at the skip-link's own rect returns the skip-link itself
(previously returned the ribbon). Screenshot confirms the familiar solid black bar
with legible "Skip to main content →" text. Spot-checked 3 additional Tab stops per
surface (POC-banner link, dismiss button, info tooltip) — all show visible focus
outlines, no regression introduced by the stacking-order change. Full evidence in
`docs/handoffs/145-wcag-sweep/handoff-U.md` ("Rerun verification (#145 rework)").
Story ready for S.

## S — Phase 9 (spec audit)
- **Verdict:** PASS. All 8 issue acceptance criteria demonstrably met by the handoff chain; waivers (RTL, Maps) consistent with the issue's own "documented waivers for POC-acceptable" clause; staged file set matches intent (spec + tokens.css + do_showcase.css + 3 handoff docs) with no unrelated churn.
- **Triage evaluation:** The 5-of-6 dual-review rejections in Phase 7 were sound (B-1/B-2/B-4 verifiable by grep; B-5 correct on `test-results/` convention; B-6 matches `manage-members.spec.ts:155` in-body waiver shape and was empirically confirmed 8-pass/2-skip). B-3 (route drift) was rightly accepted and fixed. Round 2 PASS stands.
- **Advisory (non-blocking, no follow-up):** do_streams `config/install/` gap surfaced by F — assemble-script pre-marks modules enabled, so Drupal's ConfigInstaller codepath never runs. Latent debt; per POC memory guidance, do not file as follow-up. `--gc-color-warning` proactive fix accepted per "fix the defect class" retro.
- **Evidence:** `docs/handoffs/145-wcag-sweep/handoff-S.md`; `git diff --cached --stat`; `a11y-audit.md`; T-GREEN run output; U rerun verification.

## Ready for rebase + PR
Story ready for Phase 10 (rebase onto `main`, push, `gh pr create`). Paste `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` into PR body.
