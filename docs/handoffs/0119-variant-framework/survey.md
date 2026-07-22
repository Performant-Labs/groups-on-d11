# Survey — 0119-variant-framework (SC-F1: variant framework)

Issue #119 (epic #117). Character: reusable UI foundation. Review-rigor: **second-opinion**.

## Scope read (issue #119 body, verbatim key points)

1. **Variant switcher UI** — reusable component; recommend a small `do_showcase` module so
   `do_chrome` stays untouched (final call in-issue — **decision: do `do_showcase`**, see below).
   Labeled segmented control, route/query-param driven, remembers choice per session, graceful
   when a variant is unavailable.
2. **`/showcase` tour page** — lists every comparison + the decision it represents, deep-links to
   each pre-switched. Also lists the persona switcher (#120) as a showcase device.
3. **Site-wide "POC demo" ribbon** — fixed banner, links to `/showcase`.
4. Every switcher instance carries a `do_chrome` tooltip explaining what differs.

**Acceptance (from issue):**
- [ ] Switcher renders, switches, persists per session on ≥1 wired demo instance (a stub
      comparison is fine until SC-4/5/6 land).
- [ ] `/showcase` lists all planned comparisons (incl. not-yet-built, marked "coming"), each with
      a one-sentence decision framing.
- [ ] Ribbon shows site-wide for anon + authenticated; dismissible per session.
- [ ] Tooltips render on the switcher; existing suite stays green.
- [ ] Ships HelpText entry (append-only) for the new user-facing surface.
- [ ] WCAG 2.2 AA: labels, keyboard operability, visible focus, AA contrast, non-color status.
- [ ] Delivery: branch → namespaced-docker rendered-DOM check + local `npx playwright test` green
      → PR → merge on green CI.

**Owns (disjoint files, per issue):**
- `docs/groups/modules/do_showcase/**` (new module — sole owner)
- `tests/e2e/showcase.spec.ts` (new)
- HelpText copy keys (additions only — coordinate with SC-2/SD-1/SD-2, which also touch
  `do_chrome/src/HelpText.php`; this story **appends**, never edits an existing key)

**Depends on:** nothing. **Blocks:** SC-4 (#123), SC-5 (#124), SC-6 (#125), cross-epic ST-8 (#130).

## Files read

- `docs/groups/modules/do_chrome/do_chrome.module` — empty/near-empty hook file; actual hooks live
  under `src/Hook/*.php` as `#[Hook(...)]`-attributed classes registered via `do_chrome.services.yml`.
- `docs/groups/modules/do_chrome/do_chrome.info.yml` — `core_version_requirement: ^10 || ^11`,
  `package: Custom`. No `dependencies:` key (do_chrome has none).
- `docs/groups/modules/do_chrome/do_chrome.libraries.yml` — one library `tooltips`
  (locally-vendored tippy.js v6.3.7 + popper.js v2.11.8, **no external CDN** — epic #78 constraint
  carries forward; `do_showcase` must not add a CDN dependency either).
- `docs/groups/modules/do_chrome/do_chrome.services.yml` — each hook surface gets its own
  `autowire: false` service tagged `hook_implementations`; per-surface classes stay parallel-safe
  (no shared file two stories edit).
- `docs/groups/modules/do_chrome/src/HelpText.php` — single static copy source,
  `HelpText::all()` returns `[surface.id => copy]`, `HelpText::get($key)` reads one. Extension
  pattern is explicit in the class doc comment: "each B-story tooltip surface adds ONE entry...
  keyed by a surface id." This is the append-only contract the issue also names.
- `docs/groups/modules/do_chrome/src/Hook/VisibilityTooltip.php` — per-surface `#[Hook('form_alter')]`
  class; decorates elements with `data-do-tooltip` (JS tooltip) + `#description` (no-JS fallback,
  also gives Playwright/axe a stable assertable text node — directly relevant to the WCAG AA
  acceptance criterion: the pattern already produces a non-JS-dependent, accessible description).
- `docs/groups/modules/do_chrome/src/Hook/DoChromeHooks.php` — `#[Hook('page_attachments')]`
  attaches the `do_chrome/tooltips` library **globally, once**; explicit doc comment marks this as
  "the single attach point" so per-surface code doesn't re-attach. Direct analog for the **POC
  ribbon**: a site-wide `page_attachments`/`page_top` hook is the idiomatic way to inject
  always-present chrome, exactly like the ribbon.
- `docs/groups/modules/do_notifications/do_notifications.routing.yml` +
  `src/Controller/NotificationSettingsController.php` — canonical route+controller pattern:
  `_controller` returning a render array from a `ControllerBase` subclass, `_permission` gate,
  DI via `create(ContainerInterface)`. Direct analog for the `/showcase` tour page route.
- `docs/groups/modules/do_discovery/do_discovery.routing.yml` — second routing.yml example,
  confirms the convention (`module.route_name`, `_permission: 'access content'` for public pages).
- Checked for any existing session/tempstore persistence pattern across all `do_*` modules
  (`grep -rl "tempstore\|PrivateTempStore\|session()"`) — **none found**. Per-session "remembers
  choice" has no existing analog in this codebase; Drupal core's `tempstore.private` /
  `\Drupal\Core\TempStore\PrivateTempStoreFactory` service (session-scoped, no schema/entity
  needed) is the idiomatic core service for this, not a new custom object.
- `TESTING.md` (repo root) — Go-language conventions, does not apply to this PHP/Drupal repo;
  disregarded (see decisions.md).
- `tests/e2e/*.spec.ts` (phase1-4, nav, directory-cards) + `playwright.config.ts` — `testDir:
  './tests/e2e'`, single chromium project, `workers: 1`, `BASE_URL` env override, `trace:
  'retain-on-failure'`. `showcase.spec.ts` must land in `tests/e2e/` (not a root `e2e/` dir — the
  epic doc calls this out explicitly as a silent-no-run trap).
- `.github/workflows/test.yml` (e2e job, lines ~365-500) — the exact recipe for a from-scratch
  Drupal boot: MySQL 8 service container → `composer install` → `scripts/ci/assemble-config.sh`
  → `drush site:install standard` → `config:set system.site uuid` (match assembled config) →
  `config:import` → `drush en` the `do_*` modules → seed demo data via
  `docs/groups/scripts/step_700_demo_data.php` / `step_720_group_types.php` /
  `step_780_nav_menu.php` → serve via `drush runserver`. This is the exact recipe to mirror for
  the namespaced throwaway-DB Docker verification (own container name, own MySQL port, own
  `BASE_URL`, torn down after).
- `Dockerfile` / `deploy/entrypoint.sh` / `deploy/docker-compose.spiderman.yml` — confirms the
  deployed-image path assembles `config/sync` + `web/modules/custom` via
  `scripts/ci/assemble-config.sh` at build time; local verification should use the same script
  rather than hand-assembling config.
- `docs/playbook/workflow/{workflow-coding-pipeline.md, pipeline-conventions.md,
  parallel-agent-coexistence.md, preflight-checklist.md, dual-review.sh}` — pipeline spec, journal
  format, worktree protocol, dual-review interface (confirmed matches task's `--mode brief|diff
  --brief --out` usage).

## Reuse & Analogous-Feature map

- **Relevant code mapped:** `do_chrome` (tooltip library, HelpText copy store, page_attachments
  global-chrome pattern, per-surface Hook classes); `do_notifications` / `do_discovery`
  (routing.yml + Controller pattern for a standalone page); Drupal core `tempstore.private`
  service (session persistence — no existing custom analog in this repo).
- **Closest analogous feature:** `do_chrome`'s tooltip-surface pattern — implemented in
  `do_chrome.services.yml` (one service per surface, tagged `hook_implementations`),
  `src/Hook/*.php` (one `#[Hook]` class per surface), `src/HelpText.php` (append-only copy
  store). Objects: `HelpText::all()`/`get()`, `#[Hook('page_attachments')]`,
  `#[Hook('form_alter')]`, the `do_chrome/tooltips` library.
- **Objects this change would touch:**
  - `do_chrome/src/HelpText.php` — **append** new tooltip-copy keys for each switcher instance
    (e.g. `showcase.switcher.<instance-id>`) and the ribbon copy. Read-only otherwise; per the
    issue's disjoint-ownership note, this story appends, SC-2/SD-1/SD-2 also append elsewhere in
    the same file — no two stories touch the same key.
  - `do_chrome.libraries.yml` — **read-only reference**, not touched. `do_showcase` gets its own
    `do_showcase.libraries.yml` for switcher/ribbon CSS+JS (segmented-control behavior, ribbon
    dismiss-per-session AJAX/localStorage call). No new CDN dependency (mirrors the epic #78
    locally-vendored constraint even though this is a different epic — consistent house style).
  - New: `do_showcase` module (routing, controller, a `VariantSwitcher` render-element/service,
    a `PocRibbon` page_attachments hook, `showcase.spec.ts`).
- **Extend-vs-new recommendation:**
  **NEW `do_showcase` module** — justified in writing (issue itself states this explicitly, and
  the survey independently confirms it): `do_chrome` is scoped to *tooltip/help chrome* (one
  library, one copy store, hook classes that only ever add a `data-do-tooltip` attribute to an
  existing form/render element). The variant switcher is a **new interactive control with its own
  state machine** (route/query-param read, per-session persistence via tempstore, a new render
  pattern reused by multiple future stories) and the `/showcase` page is a **new route+controller**
  — neither fits `do_chrome`'s existing object shapes without turning it into a grab-bag. Folding
  a stateful UI control and a full page route into a module whose docblock says "Foundation module
  ... locally-bundled tooltip library" would itself be the kind of undocumented scope creep the
  reuse-first default exists to prevent. The one exception: **do NOT create a new copy-store** —
  `do_showcase` calls `\Drupal\do_chrome\HelpText::get()` (extends the existing HelpText object by
  appending new keys, not by creating a parallel copy mechanism), and reuses `do_chrome/tooltips`
  library rather than vendoring a second tooltip engine. So: new module, but its two most
  duplication-prone surfaces (tooltip copy, tooltip JS) explicitly reuse `do_chrome`, not
  reinvent it.
  - **Session persistence:** EXTEND Drupal core's `tempstore.private` service (no existing
    do_* module analog to extend instead) rather than a new custom entity/config/state key.
  - **Route/controller:** follow the `do_notifications`/`do_discovery` `ControllerBase` +
    `.routing.yml` pattern exactly (no new pattern needed).

## Forward-compat check (required — this story is the named foundation for SC-4/#123, SC-5/#124,
SC-6/#125, and cross-epic ST-8/#130)

| Consumer story | Required capability from SC-F1 | Satisfied by this design? |
|---|---|---|
| SC-4 Discovery three ways (#123) | Plug a 3-option switcher (Recent / Hot / Promoted) into an existing view/listing; needs the switcher to be embeddable inline above a view, not full-page | **Yes** — `VariantSwitcher` designed as a render element/service callable from any `#pre_render`/`#build` or a block/template, not tied to `/showcase`; labels are caller-supplied, not hardcoded to a fixed option set |
| SC-5 Directory list/cards (#124) | 2-option switcher (Compact list / Cards), persists per session, must not fight `views.view.all_groups.yml` pager state | **Yes** — persistence key is namespaced per switcher instance id (caller-chosen, e.g. `directory.layout`), so SC-5's key is independent of this story's stub instance; switcher only sets/reads a tempstore value + a route/query param, does not touch view config |
| SC-6 Directory map (#125) | Needs a 3rd option addable to the *same* switcher instance SC-5 creates (list/cards/map), after #124 merges | **Yes** — switcher takes an arbitrary ordered option list per instance, not hardcoded to N=2; SC-6 extends SC-5's instance definition, doesn't need a new switcher type |
| ST-8 Streams both-models (#130, cross-epic #108) | Needs the same switcher device usable from Epic #108's stream engine context, which lands independently (Wave 1) | **Yes, with a caveat** — the switcher's public contract (a render array + a small PHP service `do_showcase.variant_switcher` with a `build(string $instance_id, array $options, string $current): array` shape) has no dependency on `do_showcase`-specific routes, so #108's code can call the service directly. **Caveat, recorded not resolved:** #108/ST-8 was not read as part of this survey (out of this story's owned-files scope and not yet landed) — if its actual needs differ from this inferred contract, that surfaces as a BLOCK when #130's own survey runs, not here. Flagged in decisions.md as an open assumption. |
| #120 Persona switcher (listed as a showcase device on `/showcase`, not a technical consumer of the switcher component itself) | `/showcase` must list it with its 4 personas | **Yes** — `/showcase` page content includes a "coming" or "live" entry for #120 per the issue text; no code dependency, just a content/copy line |

**Forward-compat: done — see table above.** One caveat carried to decisions.md (ST-8/#130 contract
inferred, not confirmed against #108's own scope).

## Existing test coverage that touches this area

- `tests/e2e/nav.spec.ts` — asserts the site nav; a site-wide ribbon must not break existing nav
  assertions (ribbon should not shift nav DOM structure, only add a new fixed-position element).
- `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` — unit-tests `HelpText::all()`/
  `get()`. Appending new keys must keep this test green (it likely asserts array shape, not an
  exhaustive key list — T (red/green) must confirm by reading it, not assume).
- `docs/groups/modules/do_chrome/tests/src/Functional/PermissionMatrixPanelTest.php` — unrelated
  surface, unaffected by this story's scope.
- No existing Playwright spec touches ribbons, switchers, or a `/showcase` route.

## Decision: `do_showcase` vs. extending `do_chrome` (issue said "final call in-issue")

Recommendation stands as **new `do_showcase` module**, matching the issue's own stated
recommendation and the Reuse map above. No maintainer comment on the issue overrides this, so
proceeding with `do_showcase` as the default the issue itself named — not treated as an open
question requiring an operator escalation (issue text already resolved it; the Reuse map
independently corroborates rather than contradicts it).
