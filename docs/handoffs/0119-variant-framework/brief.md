## Phase 1 — SC-F1: Variant framework (labeled switcher + /showcase tour + POC ribbon)

**Branch:** `0119-variant-framework`
**Review-rigor:** second-opinion (per issue #119 footer; foundation/component-creating story that
blocks 4 downstream stories — matches the pipeline's stated criteria for the second-opinion tier)
**Forward-compat:** done — see `survey.md` §Forward-compat check (one caveat: ST-8/#130's exact
contract inferred, not confirmed against #108's own scope — carried to decisions.md)
**Design (Phase 2):** required — this story has 3 distinct UI surfaces (switcher, /showcase page,
ribbon). Wireframe to be produced by D.

### Objective
Build a reusable, labeled variant-switcher device (new `do_showcase` module), a `/showcase` tour
page listing every planned comparison with the decision it represents, and a site-wide dismissible
"POC demo" ribbon — the Wave-1 framework foundation that SC-4/SC-5/SC-6/ST-8 plug into later
(those stories are themselves beyond-MVP demo vision and are explicitly OUT of scope here; this
story ships only the reusable device + a stub wiring, not the comparisons themselves).

### Codebase survey & Reuse map
Read before writing code: `docs/handoffs/0119-variant-framework/survey.md`
Closest analogous feature: `do_chrome`'s tooltip-surface pattern (HelpText copy store,
page_attachments global-chrome hook, per-surface `#[Hook]` classes) — **new object justified**:
`do_showcase` module (issue's own stated recommendation, corroborated independently in the
survey). Within it: EXTEND `\Drupal\do_chrome\HelpText` (append-only new keys, do not create a
parallel copy store); EXTEND Drupal core `tempstore.private` for per-session persistence (no
existing do_* analog); follow `do_notifications`/`do_discovery`'s `ControllerBase` + `.routing.yml`
pattern for the `/showcase` route.

Key findings:
- `do_chrome/tooltips` library (locally-vendored tippy.js, no CDN) must be reused by every
  switcher tooltip, not re-vendored — `do_showcase` adds no new tooltip engine.
- Playwright specs MUST land in `tests/e2e/` (not root `e2e/`) — the epic doc calls this out as a
  silent no-run trap.
- No existing session/tempstore usage anywhere in `do_*` modules — this is new machinery; keep it
  a thin core-service wrapper, not a custom entity/config object.
- `tests/e2e/nav.spec.ts` and `do_chrome`'s `HelpTextTest.php` are the two existing tests most at
  risk of an unintended break (ribbon DOM insertion; HelpText array-shape assumptions) — T must
  read both and confirm before/after.

### Approved wireframe
Pending — D produces `docs/handoffs/0119-variant-framework/wireframe.*` for: (1) the labeled
variant-switcher device (segmented control, e.g. "Viewing: Compact list | Cards | Map" as the
issue's own example), (2) the `/showcase` tour page layout, (3) the site-wide POC ribbon. Operator
sign-off required before T/F begin.

### Input documents
- [ ] Issue #119 (full text — scope, acceptance, owns, depends-on)
- [ ] Epic #117 (POC framing, dependency graph, program rules — HelpText shared-append-surface
      rule, `tests/e2e/` testDir rule, WCAG 2.2 AA NFR)
- [ ] `docs/playbook/workflow/workflow-coding-pipeline.md` (phase spec)
- [ ] `docs/playbook/workflow/pipeline-conventions.md` (journal/gate mechanics)

### Acceptance criteria
- [ ] Switcher renders, switches, and persists per session on at least one wired demo instance (a
      stub comparison is fine until SC-4/5/6 land).
- [ ] `/showcase` lists all planned comparisons (including not-yet-built ones, marked "coming"),
      each with its one-sentence decision framing (discovery ranking, directory presentation,
      membership models, group-type homepages, stream model, private-group reveal #134) and lists
      the persona switcher (#120) naming all four public personas.
- [ ] Ribbon shows site-wide for anonymous + authenticated; dismissible per session.
- [ ] Tooltips render on the switcher (via `do_chrome/tooltips`, HelpText-sourced copy); existing
      suite (PHPUnit + Playwright) stays green.
- [ ] Ships its HelpText entry (append-only) for the new user-facing surface(s).
- [ ] WCAG 2.2 AA: labels, keyboard operability, visible focus, AA contrast, non-color status.
- [ ] Verified via namespaced throwaway-DB Docker (mirroring `.github/workflows/test.yml`'s e2e
      job recipe: MySQL service container → `composer install` → `scripts/ci/assemble-config.sh`
      → `drush site:install` → `config:import` → enable `do_*` incl. `do_showcase` → seed demo
      data → serve) + local `npx playwright test` (`tests/e2e/showcase.spec.ts`) green; container
      torn down after.
- [ ] `docs/groups/modules/do_showcase/**` is the sole new module; no edits to `do_chrome` beyond
      appending `HelpText` keys.

### Handoff locations
D writes:      `docs/handoffs/0119-variant-framework/handoff-D.md` + `wireframe.*`
T-red writes:  `docs/handoffs/0119-variant-framework/handoff-T-red.md`
F writes:      `docs/handoffs/0119-variant-framework/handoff-F.md`
T-green writes: `docs/handoffs/0119-variant-framework/handoff-T-green.md`
A writes:      `docs/handoffs/0119-variant-framework/handoff-A*.md` (up-front + anti-dup)
U writes:      `docs/handoffs/0119-variant-framework/handoff-U.md`
S writes:      `docs/handoffs/0119-variant-framework/handoff-S.md`

### Operating rules
- Read the survey + Reuse map before writing any code; don't rely on the issue text alone.
- New module `do_showcase` is justified (see Reuse map) — but reuse `do_chrome/tooltips` library
  and `\Drupal\do_chrome\HelpText` (append-only) rather than duplicating either. A parallel
  tooltip/copy mechanism is an anti-duplication BLOCK (Phase 7).
- Implement against the failing tests authored in Phase 4 (test-first) — F writes no tests.
- Playwright specs go in `tests/e2e/` (not `e2e/`) — testDir is `./tests/e2e`.
- No theme/bluecheese skin toggle (epic-wide exclusion) — out of scope entirely.
- Keep POC scope: a stub comparison instance is sufficient; SC-4/5/6's real comparisons are
  explicitly future stories.
- Stage files by explicit path, never `git add .`.
- Models: Sonnet for all roles except Spec Auditor (S), which runs Opus. Do not let S inherit
  Sonnet, do not let any other role inherit Opus.
