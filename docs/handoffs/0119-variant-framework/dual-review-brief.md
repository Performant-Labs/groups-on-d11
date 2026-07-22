## Brief Review (Round 1)

### BLOCK findings  
**[B-1]** Unverified runtime claim: reuse of the `do_chrome/tooltips` library for the switcher tooltips.  
— Why it blocks: We do not know whether `do_chrome` will auto-initialize tippy.js on dynamically inserted elements or how the HelpText-based annotations hook into the page lifecycle.  
— What must be clarified or fixed: Inspect and document the `do_chrome/tooltips` initialization process and ensure it supports attaching tooltips to the variant-switcher UI at the correct moment.

**[B-2]** Unverified runtime claim: use of Drupal core `tempstore.private` for per-session persistence across both authenticated and anonymous users.  
— Why it blocks: By default `tempstore.private` keys storage by user ID; anonymous users share UID 0, so state (ribbon dismissal or variant choice) could leak across all anonymous sessions.  
— What must be clarified or fixed: Verify `tempstore.private` semantics for anonymous sessions or choose an alternative (e.g. cookie or distinct session store) that satisfies true per-session, per-browser persistence without cross-user contamination.

**[B-3]** Incomplete test-first specification: Acceptance criteria reference only a single Playwright spec (`tests/e2e/showcase.spec.ts`) for the `/showcase` page.  
— Why it blocks: The story’s test-first mandate implies failing specs must exist for every UI surface: switcher, ribbon, and page. Without those, developers cannot adopt a test-first approach.  
— What must be clarified or fixed: Enumerate and author end-to-end (and/or PHPUnit) tests covering the labeled switcher interactions, session persistence, ribbon display and dismissal, in addition to the showcase page.

**[B-4]** Ambiguous data model for comparisons and personas: The brief requires listing specific planned comparisons (discovery ranking, directory presentation, membership models, group-type homepages, stream model, private-group reveal #134) and four public personas (#120), each with decision framings.  
— Why it blocks: There is no specification of where these lists and strings come from—hard-coded in code, stored in config, CMS content or elsewhere—and how they will be localized or maintained.  
— What must be clarified or fixed: Define the data storage and retrieval strategy (e.g. a config schema, content entity, or code constant), and confirm the localization approach for all user-facing text.

**[B-5]** Missing approved wireframes and design sign-off: The brief states that wireframes for the switcher, the `/showcase` page and the POC ribbon are pending and require operator approval before T/F begin.  
— Why it blocks: Without finalized designs, UI implementation risks misalignment, rework, and wasted effort.  
— What must be clarified or fixed: Obtain and attach the approved wireframes and a sign-off confirmation before any development starts.

### WARN findings  
**[W-1]** Demo-data seeding instructions lack detail. Recommendation: specify exact fixtures (stub comparison entries, persona metadata) and their source or format so CI seed scripts can be written unambiguously.  

**[W-2]** High-level WCAG 2.2 AA requirements need elaboration. Recommendation: reference a style guide or list required ARIA roles, keyboard patterns, focus-ring styles, contrast ratios, and non-color indicators.  

**[W-3]** No mention of internationalization. Recommendation: mandate wrapping all UI text in Drupal’s t() function, and include placeholders or templates for translation files.  

**[W-4]** Bundling three new UI surfaces, a new persistence mechanism, and a test-first delivery into one story raises complexity risk. Recommendation: consider splitting into smaller stories or define clear incremental milestones.  

**[W-5]** Potential breakage in existing tests (`tests/e2e/nav.spec.ts`, `HelpTextTest.php`) noted in the survey. Recommendation: add explicit regression tests or adjust those suites as part of this story.

### NIT findings  
**[NIT-1]** Update `docs/handoffs/0119-variant-framework/survey.md` with current library versions, code references, and last-updated date.  
**[NIT-2]** In the handoff docs, illustrate the directory structure and naming conventions for the new `do_showcase` module (module.info.yml, Controllers, libraries.yml, etc.).  
**[NIT-3]** Add placeholder notes for CSS class naming conventions, design tokens, and theming guidelines for each UI component.  
**[NIT-4]** Standardize casing for the test directory reference—use `tests/e2e/` consistently throughout all docs and scripts.

### Verdict  
BLOCK — 5 blocking finding(s); must resolve before implementation starts.

---

## Brief

## Phase 1 — SC-F1: Variant framework (labeled switcher + /showcase tour + POC ribbon)

**Branch:** `0119-variant-framework`  
**Review-rigor:** second-opinion (per issue #119 footer; foundation/component-creating story that blocks 4 downstream stories — matches the pipeline's stated criteria for the second-opinion tier)  
**Forward-compat:** done — see `survey.md` §Forward-compat check (one caveat: ST-8/#130's exact contract inferred, not confirmed against #108's own scope — carried to decisions.md)  
**Design (Phase 2):** required — this story has 3 distinct UI surfaces (switcher, /showcase page, ribbon). Wireframe to be produced by D.

### Objective  
Build a reusable, labeled variant-switcher device (new `do_showcase` module), a `/showcase` tour page listing every planned comparison with the decision it represents, and a site-wide dismissible "POC demo" ribbon — the Wave-1 framework foundation that SC-4/SC-5/SC-6/ST-8 plug into later (those stories are themselves beyond-MVP demo vision and are explicitly OUT of scope here; this story ships only the reusable device + a stub wiring, not the comparisons themselves).

### Codebase survey & Reuse map  
Read before writing code: `docs/handoffs/0119-variant-framework/survey.md`  
Closest analogous feature: `do_chrome`'s tooltip-surface pattern (HelpText copy store, page_attachments global-chrome hook, per-surface `#[Hook]` classes) — **new object justified**: `do_showcase` module (issue's own stated recommendation, corroborated independently in the survey). Within it: EXTEND `\Drupal\do_chrome\HelpText` (append-only new keys, do not create a parallel copy store); EXTEND Drupal core `tempstore.private` for per-session persistence (no existing do_* analog); follow `do_notifications`/`do_discovery`'s `ControllerBase` + `.routing.yml` pattern for the `/showcase` route.

Key findings:  
- `do_chrome/tooltips` library (locally-vendored tippy.js, no CDN) must be reused by every switcher tooltip, not re-vendored — `do_showcase` adds no new tooltip engine.  
- Playwright specs MUST land in `tests/e2e/` (not root `e2e/`) — the epic doc calls this out as a silent no-run trap.  
- No existing session/tempstore usage anywhere in `do_*` modules — this is new machinery; keep it a thin core-service wrapper, not a custom entity/config object.  
- `tests/e2e/nav.spec.ts` and `do_chrome`'s `HelpTextTest.php` are the two existing tests most at risk of an unintended break (ribbon DOM insertion; HelpText array-shape assumptions) — T must read both and confirm before/after.

### Approved wireframe  
Pending — D produces `docs/handoffs/0119-variant-framework/wireframe.*` for: (1) the labeled variant-switcher device (segmented control, e.g. "Viewing: Compact list | Cards | Map" as the issue's own example), (2) the `/showcase` tour page layout, (3) the site-wide POC ribbon. Operator sign-off required before T/F begin.

### Input documents  
- [ ] Issue #119 (full text — scope, acceptance, owns, depends-on)  
- [ ] Epic #117 (POC framing, dependency graph, program rules — HelpText shared-append-surface rule, `tests/e2e/` testDir rule, WCAG 2.2 AA NFR)  
- [ ] `docs/playbook/workflow/workflow-coding-pipeline.md` (phase spec)  
- [ ] `docs/playbook/workflow/pipeline-conventions.md` (journal/gate mechanics)

### Acceptance criteria  
- [ ] Switcher renders, switches, and persists per session on at least one wired demo instance (a stub comparison is fine until SC-4/5/6 land).  
- [ ] `/showcase` lists all planned comparisons (including not-yet-built ones, marked "coming"), each with its one-sentence decision framing (discovery ranking, directory presentation, membership models, group-type homepages, stream model, private-group reveal #134) and lists the persona switcher (#120) naming all four public personas.  
- [ ] Ribbon shows site-wide for anonymous + authenticated; dismissible per session.  
- [ ] Tooltips render on the switcher (via `do_chrome/tooltips`, HelpText-sourced copy); existing suite (PHPUnit + Playwright) stays green.  
- [ ] Ships its HelpText entry (append-only) for the new user-facing surface(s).  
- [ ] WCAG 2.2 AA: labels, keyboard operability, visible focus, AA contrast, non-color status.  
- [ ] Verified via namespaced throwaway-DB Docker (mirroring `.github/workflows/test.yml`'s e2e job recipe: MySQL service container → `composer install` → `scripts/ci/assemble-config.sh` → `drush site:install` → `config:import` → enable `do_*` incl. `do_showcase` → seed demo data → serve) + local `npx playwright test` (`tests/e2e/showcase.spec.ts`) green; container torn down after.  
- [ ] `docs/groups/modules/do_showcase/**` is the sole new module; no edits to `do_chrome` beyond appending `HelpText` keys.

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
- New module `do_showcase` is justified (see Reuse map) — but reuse `do_chrome/tooltips` library and `\Drupal\do_chrome\HelpText` (append-only) rather than duplicating either. A parallel tooltip/copy mechanism is an anti-duplication BLOCK (Phase 7).  
- Implement against the failing tests authored in Phase 4 (test-first) — F writes no tests.  
- Playwright specs go in `tests/e2e/` (not `e2e/`) — testDir is `./tests/e2e`.  
- No theme/bluecheese skin toggle (epic-wide exclusion) — out of scope entirely.  
- Keep POC scope: a stub comparison instance is sufficient; SC-4/5/6's real comparisons are explicitly future stories.  
- Stage files by explicit path, never `git add .`.  
- Models: Sonnet for all roles except Spec Auditor (S), which runs Opus. Do not let S inherit Sonnet, do not let any other role inherit Opus.
