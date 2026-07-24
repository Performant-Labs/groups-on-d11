# Handoff-F: Phase 5 - #145 MC-A11Y WCAG 2.2 AA audit sweep

**Date:** 2026-07-23
**Branch:** 145-wcag-sweep
**Issue:** #145

## What was done
- `web/themes/custom/groups_chrome/css/tokens.css` — darkened three semantic-badge
  foreground tokens (`--gc-color-success`, `--gc-color-warning`, `--gc-color-info`) so
  `.gc-badge--success` / `.gc-badge--info` (both rendered on the seeded `/all-groups` and
  `/group/{seed}` pages) clear WCAG 2.2 AA 4.5:1 text contrast against their `-050` wash
  backgrounds and against plain white (the only other consumer, `.gc-directory-card__member-note`).
  This is the ONLY production file changed.
- `package.json` / `package-lock.json` — `npm install` resolved T's already-added
  `@axe-core/playwright` devDependency (package.json's one-line diff is T's, from
  Phase 4; package-lock.json's 24-line diff is the mechanical `npm install` output).
- Environment provisioning (no file changes, DDEV/drush actions only): `composer install`,
  `assemble-config.sh`, `drush site:install` + `config:import` (UUID-adopted), ran the four
  seed scripts (`step_700_demo_data.php`, `step_720_group_types.php`, `step_780_nav_menu.php`,
  `step_790_persona_switcher.php`) as uid 1, plus a one-time `config:import --partial`
  of `do_streams`'s own `config/install/views.view.do_streams_demo.yml` (see "Environment
  notes" below — this view's `config/install/` YAML is never picked up by a general
  `config:import`, only by Drupal's module-INSTALL codepath, so `/do-streams/demo/global`
  404'd until this one-time partial import). `web/sites/default/settings.php` (gitignored)
  got the `config_sync_directory` override the RUNBOOK requires.

## Design decisions
- **Darkened tokens, not restructured classes.** `primitives.css`'s own header comment
  contractually forbids editing `.gc-badge` base classes ("EXTEND... do not edit the base
  classes below... so A2–A5 never collide"). The brief's fix envelope explicitly allows
  "Contrast fails → adjust color token," so I changed only the three hex values in
  `tokens.css`, leaving every class selector untouched.
- **De-aliased `--gc-color-info` from `--gc-color-primary`, not `--gc-color-primary` itself.**
  `--gc-color-info` was `var(--gc-color-primary)` (`#0678be`, 4.16:1 on its own `-050` wash —
  fails). `--gc-color-primary` is also used elsewhere as a `background-color`/`border-color`
  (group-page.css, primitives.css, stream.css) — a *non-text* contrast rule that already
  passes there, so touching it would have been unrelated churn with a wider blast radius.
  Giving `--gc-color-info` its own value (`#006eb4`) fixes the one real text-contrast failure
  without touching `--gc-color-primary`'s other three consumers.
- **Fixed `--gc-color-warning` too, though `.gc-badge--warning` renders nowhere in the current
  seed/templates** (confirmed via grep across `web/themes/custom` and `docs/groups/`) so axe
  never flagged it on the 8 crawled surfaces. It shares the identical root cause (a
  foreground/background token pair in the same file, same defect class) as success/info — per
  this project's own retrospective ("fix the defect class, not the cited instance," WAVE-
  EXECUTION-HANDOFF.md §12.7), I fixed it alongside rather than leave a known-failing token
  as latent debt for the next story that reaches for a warning badge. `--gc-color-danger`
  (4.67:1) already passes AA and was left untouched.
- **Margin over exact-edge values.** Computed exact-passing hex values landed at 4.50–4.51:1
  (edge of the threshold); I targeted ~4.7–4.8:1 instead so a different renderer's/font's
  subpixel rounding doesn't flip a pass back to a fail.

## Reuse / extend-vs-new
No new object created. This story is fix-only (per brief: "D skipped — a11y fixes only, no
new UI surface"). The one production edit extends the existing shared `tokens.css` design-
token file in place, which is exactly its intended extension point.

## Architecture notes for A
- Layer touched: CSS custom-property values only (`:root` token declarations). No selectors,
  no markup, no PHP, no config schema.
- `tokens.css` is shared/other-agent-owned per its own header contract ("A-stories: ADD new
  tokens here; do NOT redefine Olivero core variables") — this is a within-contract edit
  (changing an existing token's *value*, not adding/removing tokens or touching Olivero
  variables), not a drive-by restructure.
- No new dependencies beyond T's already-added `@axe-core/playwright` (now installed).

## Deviations from spec / wireframe
None on the code side. See "Tests that look wrong" below for two spec-side issues found
during verification (not fixed — flagged for T per role boundaries).

## Tier 1 self-check (incl. tests now GREEN)
**Important caveat — read before trusting a plain rerun of the real spec:** the real,
unmodified `tests/e2e/a11y-audit.spec.ts` currently reports **all 8 real tests + both waivers
as skipped** (see "Tests that look wrong" — a `test.skip(title, string)` syntax defect, not
an environment problem). I could not "run T's authored tests and see them pass" in the literal
sense until T fixes that syntax, because the file's own bug prevents any test in it from
executing at all right now.

To verify the underlying fixes are correct, I made a **local-only verification copy** (never
staged, deleted before this handoff — confirmed via `diff` against a scratch backup that the
real `tests/e2e/a11y-audit.spec.ts` is byte-identical to what T handed off) with only the two
waiver calls' syntax corrected (`test.skip(title, () => {})` instead of `test.skip(title,
string)`), identical otherwise. That copy is the true state once T's one-line fix lands:

```
Running 10 tests using 1 worker

  ok  1 › / (front page) has no serious/critical axe violations (1.5s)
  ok  2 › /all-groups (directory + card grid + filters) has no serious/critical axe violations (1.2s)
  ok  3 › /group/{seed} (group homepage) has no serious/critical axe violations (1.3s)
  ok  4 › /showcase (variant switcher + POC ribbon) has no serious/critical axe violations (0.9s)
  ok  5 › /personas (persona banner switcher) has no serious/critical axe violations (0.8s)
  ok  6 › /group/{seed}/members (manage-members table) has no serious/critical axe violations (1.1s)
  ok  7 › /group/add/{type} (create-group form) has no serious/critical axe violations (0.8s)
  ok  8 › /do-streams/demo/{scope} (shared stream shell, representative route) has no serious/critical axe violations (1.0s)
  -   9 › RTL toggle audit (skipped, waiver)
  -  10 › Maps surface audit (skipped, waiver)

  2 skipped
  8 passed (9.5s)
```

Before the fix (`tokens.css` unpatched), tests #2 and #3 (`/all-groups`, `/group/{seed}`)
failed with `color-contrast` `serious`-impact violations on `.gc-badge--success` /
`.gc-badge--info`. After the fix, 0 serious/critical violations across all 8 real surfaces
(full table: `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md`).

Reproduction command (once T fixes the waiver syntax):
```
BASE_URL="https://gm145-wcag.ddev.site" npx playwright test tests/e2e/a11y-audit.spec.ts
```

**PHPCS:** no PHP file was touched (only CSS), so `phpcs` is a no-op for this change — confirmed
by `git status` showing exactly one modified file, `tokens.css`. (Repo also has no
`phpcs.xml*` at the root, matching `.github/workflows/test.yml`'s three jobs — kernel,
functional, e2e — which do not include a lint job.)

## Tests that look wrong (for T)

1. **File-wide skip defect (the one that actually matters — please fix first).**
   `tests/e2e/a11y-audit.spec.ts` lines 188–196:
   ```ts
   test.skip(
     'RTL toggle audit',
     'Display-only toggle with no seeded RTL locale in this demo (brief.md waiver) — no automatable surface exists to scan.',
   );
   test.skip(
     'Maps surface audit',
     'No maps surface exists in the demo (brief.md waiver) — nothing to scan.',
   );
   ```
   `test.skip(title, description)` — two strings — is not a valid Playwright overload at
   file scope. I traced this to the runtime source
   (`node_modules/playwright/lib/common/index.js`, `TestTypeImpl._modifier()`,
   ~line 2373–2392): the only two "declare a permanently-skipped named test" shapes are
   `(string, function)` and `(string, object, function)`. When neither matches, at file-load
   time (`currentlyLoadingFileSuite()` truthy), it falls through to treating
   `modifierArgs[0]` (the truthy title string) as a **whole-suite skip condition** and pushes
   `{type: 'skip', description: modifierArgs[1]}` onto the **entire file's root
   `_staticAnnotations`** — i.e. it silently skips every test in the file, not just these two.
   I confirmed this empirically: a scratch copy with only these two calls removed ran and
   passed its one remaining test; restoring them (with the correct `() => {}` callback
   signature instead) let all 10 tests run/skip as intended.
   **Fix:** change the second argument in both calls to a no-op callback:
   `test.skip('RTL toggle audit', () => {});` (move the justification text into a comment
   above, since the description string has no valid slot in this two-arg form) — or use the
   3-arg form `test.skip('RTL toggle audit', { annotation: { type: 'skip', description: '...' } }, () => {})`
   if you want the justification to show up in Playwright's own reporter/annotations. Either
   way, the second positional argument to a 2-arg `test.skip()` must be a function.

2. **`/personas` route does not exist** (T's own decisions.md already flagged this as
   unverified/hedged — confirming it here). `grep`-ing every `*.routing.yml` under
   `docs/groups/modules/` for a `/personas` path finds nothing; `do_showcase.routing.yml`
   defines only `/showcase` and `/persona-switch/{persona}`. `curl` confirms `/personas`
   404s ("Page not found | Drupal Groups"). The persona banner/switcher is embedded UI
   reached via `/` (per `persona-switcher.spec.ts`'s own convention), not a standalone page.
   0 axe violations on this route reflects Drupal's generic 404 page, not a validated
   persona-switcher surface — a false-positive pass, not evidence of accessibility. Needs a
   route correction (e.g. navigate via `/` + the persona-switcher UI, matching
   `persona-switcher.spec.ts`) rather than a direct `page.goto('/personas')`.

I did not edit `tests/e2e/a11y-audit.spec.ts` for either finding — confirmed via `diff`
against a pre-verification backup that it is byte-identical to what I received from T.

## Environment notes (not code fixes — for whoever reruns this)
- `/do-streams/demo/global` 404'd on first boot even though `do_streams` was enabled and its
  YAML (`docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml`)
  correctly defines that path. Root cause: this file lives in the module's own
  `config/install/`, which Drupal only auto-imports during the module's actual
  `ModuleInstallerInterface::install()` codepath — NOT via a general `config:import` from
  `config/sync/`, and NOT when `assemble-config.sh` pre-marks the module "enabled" in
  `core.extension.yml` before `site:install`/`config:import` ever run (which is exactly what
  this project's own assemble script does). One-time fix applied to THIS environment only
  (not a code change): `drush config:import --partial --source=web/modules/custom/do_streams/config/install -y`
  then `drush cr`. Any future rerun of this seed sequence needs the same step, OR (better,
  out of my a11y-only scope to build) `do_streams` could ship a `do_streams.install` with
  `hook_install()` that installs its own config — flagging as a possible follow-up, not
  fixing it here (would be a module code change, outside this story's fix envelope).
- Windows/Git-Bash note for whoever reruns any of this: absolute container paths like
  `/var/www/html/...` passed through `ddev exec`/`ddev drush` get mangled by MSYS path
  conversion unless prefixed with `MSYS_NO_PATHCONV=1`.

## Known issues
- The audit table (`docs/planning/handoffs/145-wcag-sweep/a11y-audit.md`) was generated by
  my local-only verification copy, not by the real spec file (which currently cannot generate
  it at all — see above). Once T's syntax fix lands, rerunning the real spec reproduces this
  exact table (I verified this is deterministic across two full runs).
- 3 waivers-worth of judgment calls, 0 module-refactor waivers needed (well under the brief's
  ">2 such → escalate to O" threshold): the two RTL/maps waivers are exactly as T wrote them
  (untouched), and `/personas` is a route-naming issue, not a module-refactor waiver.

## Files changed
- `web/themes/custom/groups_chrome/css/tokens.css` — darkened `--gc-color-success`,
  `--gc-color-warning`, `--gc-color-info` (de-aliased from `--gc-color-primary`) to clear
  WCAG 2.2 AA 4.5:1 text contrast; staged by explicit path (`git add web/themes/custom/groups_chrome/css/tokens.css`), not committed.
- `package.json` / `package-lock.json` — `npm install` resolution of T's `@axe-core/playwright`
  devDependency addition; not staged by me (T's dep addition, mechanical lockfile byproduct).

---

## Rework: Phase 5 — skip-link occlusion fix (2026-07-23)

**Trigger:** U's Phase-8 REWORK verdict (`docs/handoffs/145-wcag-sweep/handoff-U.md`) —
the "Skip to main content" link is the first Tab stop on every page, but
`.do-showcase-ribbon` (POC demo banner, `position: fixed; z-index: 1000`) occupies the
identical top-left rect (0,0)–(1280,~40) and paints over it while it holds focus. A sighted
keyboard user sees zero focus indication on the first Tab press, across all 4 audited
surfaces (WCAG 2.4.7 Focus Visible).

**Investigation:**
- `docs/groups/modules/do_showcase/css/do_showcase.css:13-27` — `.do-showcase-ribbon`'s
  hard-coded `z-index: 1000` is the true source file (this module's real source lives under
  `docs/groups/`, not `web/modules/custom/`, which is the assembled/gitignored copy).
- `web/core/themes/olivero/css/components/skip-link.css:39-45` (Olivero core, "DO NOT EDIT")
  — `.skip-link.focusable:focus { z-index: 503; ... }`. The ribbon's 1000 clearly beats it.
- `web/themes/custom/groups_chrome/css/tokens.css:102-104` — the project's own z-index
  token scale tops out at `--gc-z-tooltip: 800`. The ribbon's 1000 was an outlier against
  this scale, not a deliberate "must beat everything" value; no defined token reaches 1000.

**Fix applied:** one file, one property value, `docs/groups/modules/do_showcase/css/do_showcase.css`:
```diff
-  z-index: 1000;
+  /* #145: was 1000, which sat above Olivero's .skip-link.focusable:focus
+   * (z-index: 503, web/core/themes/olivero/css/components/skip-link.css --
+   * DO NOT EDIT core file), so the ribbon painted over the focused skip-link
+   * and made the first Tab stop on every page invisible. 499 keeps the
+   * ribbon above normal document flow and above groups_chrome's own
+   * --gc-z-sticky (100) tier, but below Olivero's skip-link focus layer. */
+  z-index: 499;
```
Chose a literal number (`499`), not `var(--gc-z-overlay)`, because `do_showcase`'s `ribbon`
library (`do_showcase.libraries.yml`) has no dependency on `groups_chrome`'s tokens library —
introducing that coupling would be a scope-expanding cross-module dependency change, which the
task explicitly ruled out ("CSS-only... do NOT refactor the ribbon module"). `499` is chosen
to sit one below the token scale's `--gc-z-overlay` (500) in spirit, while staying
self-contained in the one file that needed the fix.

**Environment step:** re-ran `bash scripts/ci/assemble-config.sh` (via `ddev exec`, since this
host has no `php` on PATH — DDEV's container does) to sync the source-side fix into
`web/modules/custom/do_showcase/`, then `ddev drush cr` to bust CSS aggregation.

**Verification (manual, Playwright script mirroring U's method — scratchpad only, deleted
after run):**
- Navigated to `/all-groups`, `/`, and `/group/add/community_group`; pressed `Tab` once;
  read `document.activeElement` + `elementFromPoint` at the skip-link's own screen rect.
- All three surfaces: active element is `<a class="visually-hidden focusable skip-link">`,
  computed `z-index: 503`; `.do-showcase-ribbon`'s computed `z-index: 499`;
  `elementFromPoint` at the skip-link's rect returns the skip-link itself
  (`topElIsActiveOrDescendant: true`) — the exact inversion of U's reported defect.
- Screenshot (`skiplink-fix-verify.png`, scratchpad) shows the skip-link rendered as a solid
  black bar with legible white text "Skip to main content →", fully on top, matching
  Olivero's default focused-skip-link appearance.
- Confirmed the ribbon itself is unaffected functionally (still `display: flex`,
  `visibility: visible`, full text intact, normal rect) when nothing is focused — this is a
  pure stacking-order reorder, not a visibility/removal change.
- Re-ran `BASE_URL="https://gm145-wcag.ddev.site" npx playwright test tests/e2e/a11y-audit.spec.ts`
  → unchanged: `8 passed (13.4s)`, `2 skipped` — no regression from the z-index change.

**Files changed (this rework):**
- `docs/groups/modules/do_showcase/css/do_showcase.css` — `.do-showcase-ribbon` z-index
  `1000` → `499` (+ explanatory comment). Staged by explicit path
  (`git add docs/groups/modules/do_showcase/css/do_showcase.css`), not committed.

No template, markup, or module (PHP/JS) changes. No new tokens added. Scope stayed within
the "CSS-only, ≤5 lines" envelope (1 property-value line changed; comment lines are
non-functional).
