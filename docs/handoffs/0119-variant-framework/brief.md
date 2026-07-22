## Phase 1 — SC-F1: Variant framework (labeled switcher + /showcase tour + POC ribbon)

**Branch:** `0119-variant-framework`
**Review-rigor:** second-opinion (per issue #119 footer; foundation/component-creating story that
blocks 4 downstream stories — matches the pipeline's stated criteria for the second-opinion tier)
**Forward-compat:** done — see `survey.md` §Forward-compat check (one caveat: ST-8/#130's exact
contract inferred, not confirmed against #108's own scope — carried to decisions.md)
**Design (Phase 2):** required — this story has 3 distinct UI surfaces (switcher, /showcase page,
ribbon). Wireframe to be produced by D.

### Brief-gate resolutions (dual-review round 1, o4-mini — BLOCK ×5, all resolved below)
- **B-1 (tooltip init on dynamically-rendered switcher) — FOLDED as a design constraint.** The
  switcher renders server-side into the initial DOM (it is a Drupal render array, not a
  JS-inserted node), so `do_chrome.tooltips.js` initializing tippy against `[data-do-tooltip]` at
  page load covers it. F/T must verify this against the real `do_chrome.tooltips.js` init path
  (does it scan on `DOMContentLoaded` / `Drupal.behaviors`?) and, if the switcher ever swaps
  options via AJAX, re-attach. See Operating rules + Acceptance.
- **B-2 (tempstore.private + anonymous cross-contamination) — reviewer premise CORRECTED, but a
  real design decision folded.** Verified against core
  `web/core/lib/Drupal/Core/TempStore/PrivateTempStore.php::getOwner()` (lines 220-226):
  anonymous users are NOT keyed by UID 0 — core generates a per-session random owner token
  (`core.tempstore.private.owner`), so there is **no** cross-anonymous leak. HOWEVER, using
  `tempstore.private` (or any server session write) for an anonymous visitor **starts a session
  and busts the anonymous page cache site-wide** — bad for a public demo. **DECISION (amended):**
  persist the variant choice and ribbon-dismissal **client-side** (a first-party cookie /
  `localStorage`, read+applied in the switcher/ribbon JS), NOT server tempstore, for anonymous.
  Authenticated persistence may use the same client-side mechanism for consistency (the demo has
  no requirement to carry the choice across devices). This keeps the anonymous page cache intact
  and satisfies "remembers choice per session / dismissible per session" without a server session.
  See updated Reuse map + Acceptance.
- **B-3 (test-first must cover switcher + ribbon + page, not only showcase.spec) — ACCEPTED,
  brief amended.** T (Phase 4) authors failing tests for all three surfaces (see expanded
  Acceptance + Handoff). `tests/e2e/showcase.spec.ts` is the single new spec file but must contain
  cases for: switcher render+switch+persist, ribbon show(anon+auth)+dismiss+persist, and the
  `/showcase` page listing. Plus a PHPUnit unit/kernel test for the `VariantSwitcher` service
  build() contract and the appended HelpText keys.
- **B-4 (data model for comparison/persona lists) — ACCEPTED, brief amended.** The comparison list
  and persona list are **code constants** in the `do_showcase` module (a typed PHP array /
  small value object returned by a `ShowcaseCatalog` method), each entry `{id, title,
  decision_sentence, status: live|coming, route|null}`. All user-facing strings wrapped in `t()`
  for localization. NOT a config entity or content — POC scope, and it keeps the list versioned
  with the code that consumes it. See Reuse map.
- **B-5 (wireframes pending) — REJECTED as a brief defect (pipeline sequences this by design).**
  The coding pipeline runs D (wireframe) → operator sign-off **before** T/F. "Pending" is the
  correct state of a Phase-1 brief; the wireframe is produced and approved in Phase 2, which is the
  very next step. No code/tests start until sign-off. Not an amendment — this is the pipeline
  working as intended.
- **WARN/NIT:** W-2 (WCAG specifics) + W-3 (i18n) folded into Acceptance/Operating rules below.
  W-4 (split the story) noted but rejected — the epic explicitly scopes SC-F1 as one foundational
  story with a stub instance; SC-4/5/6 are already the split-out consumers. W-5 (regression on
  nav/HelpText tests) already in the brief's Key findings; reinforced in Operating rules. NITs
  addressed in the survey/handoff docs as produced.

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
parallel copy store); persist variant choice + ribbon-dismissal **client-side** (first-party
cookie / `localStorage` read in the switcher/ribbon JS) — **NOT** server `tempstore.private`,
which would start a session and bust the anonymous page cache (see Brief-gate B-2). Follow
`do_notifications`/`do_discovery`'s `ControllerBase` + `.routing.yml` pattern for the `/showcase`
route. Comparison + persona lists are **code constants** (`ShowcaseCatalog` in `do_showcase`,
entries `{id, title, decision_sentence, status, route}`), all strings `t()`-wrapped (Brief-gate
B-4).

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
      stub comparison is fine until SC-4/5/6 land). Persistence is client-side (cookie/localStorage);
      an anonymous visitor's choice survives navigation without starting a server session (page
      cache stays warm). T authors a switcher render+switch+persist case in `showcase.spec.ts`.
- [ ] The `VariantSwitcher` service exposes a stable `build(string $instance_id, array $options,
      string $current): array` render-array contract (PHPUnit-tested), so SC-4/5/6/ST-8 can call it
      (forward-compat, survey table).
- [ ] `/showcase` lists all planned comparisons (including not-yet-built ones, marked "coming"),
      each with its one-sentence decision framing (discovery ranking, directory presentation,
      membership models, group-type homepages, stream model, private-group reveal #134) and lists
      the persona switcher (#120) naming all four public personas.
- [ ] Ribbon shows site-wide for anonymous + authenticated; dismissible per session (client-side
      persistence — dismissal survives navigation without a server session). T authors a ribbon
      show(anon+auth)+dismiss+persist case in `showcase.spec.ts`.
- [ ] Tooltips render on the switcher (via `do_chrome/tooltips`, HelpText-sourced copy); existing
      suite (PHPUnit + Playwright) stays green.
- [ ] Ships its HelpText entry (append-only) for the new user-facing surface(s).
- [ ] WCAG 2.2 AA (Brief-gate W-2): switcher is a labeled control group (fieldset/legend or
      `role=radiogroup` + `aria-label`), each option keyboard-operable (arrow/Tab per the chosen
      pattern) with a **visible focus ring**, current selection conveyed by more than color
      (`aria-checked`/`aria-current` + a text/shape cue), all text ≥ AA contrast; ribbon dismiss is
      a real `<button>` with an accessible name, keyboard-operable. All user-facing strings
      `t()`-wrapped (Brief-gate W-3/i18n).
- [ ] Verified via namespaced throwaway-DB Docker (mirroring `.github/workflows/test.yml`'s e2e
      job recipe: MySQL service container → `composer install` → `scripts/ci/assemble-config.sh`
      → `drush site:install` → `config:import` → enable `do_*` incl. `do_showcase` → seed demo
      data → serve) + local `npx playwright test` (`tests/e2e/showcase.spec.ts`) green; container
      torn down after.
- [ ] `docs/groups/modules/do_showcase/**` is the sole new module; no edits to `do_chrome` beyond
      appending `HelpText` keys.

### Handoff locations
D writes:      `docs/handoffs/0119-variant-framework/handoff-D.md` + `wireframe.*`
T-red authors (Brief-gate B-3): `tests/e2e/showcase.spec.ts` (cases: switcher render/switch/persist;
               ribbon show anon+auth / dismiss / persist; `/showcase` listing incl. "coming" +
               persona entries) AND a PHPUnit test for `VariantSwitcher::build()` contract + the
               appended `HelpText` keys.
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
- **Persistence is client-side (cookie/localStorage), not `tempstore.private`** — a server session
  write would bust the anonymous page cache (Brief-gate B-2). F verifies `do_chrome.tooltips.js`
  actually initializes tooltips on the server-rendered switcher DOM (Brief-gate B-1); if the
  switcher swaps options via AJAX later, re-attach via `Drupal.behaviors`.
- Comparison + persona lists are `t()`-wrapped code constants in a `ShowcaseCatalog` (Brief-gate
  B-4/W-3) — not config/content.
- Do NOT regress `tests/e2e/nav.spec.ts` or `do_chrome`'s `HelpTextTest.php` (Brief-gate W-5);
  the ribbon adds a fixed element without reshuffling nav DOM; HelpText edits are append-only keys.
- If any membership-request surface is touched: `drupal/grequest` is NOT installed and is
  incompatible with group 4.0.x-dev (needs group ^3.0) — do not assume it exists (per #136).
- Models: Sonnet for all roles except Spec Auditor (S), which runs Opus. Do not let S inherit
  Sonnet, do not let any other role inherit Opus.
