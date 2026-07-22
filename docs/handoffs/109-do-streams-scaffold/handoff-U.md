# Handoff-U: Phase 8 — UI Walkthrough (issue #109, do_streams scaffold)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Verdict: CANNOT-WALK — no live route/page in this diff renders the `do_streams_shell` theme
hook; there is nothing an interactive browser session can reach or click on this branch.**

This is reported as a definitive finding, not a punted "optional" step — see "Why this is not a
DDEV/tooling gap" below. No DDEV environment was brought up because doing so would not change the
outcome: I verified statically, with full certainty, that no controller/block/route in this diff
assembles `#theme => do_streams_shell` with the shell's own preprocess wired to a rendered page.

## What I checked before concluding CANNOT-WALK

1. **`docs/groups/modules/do_streams/templates/do-streams-shell.html.twig`** — every tab
   (`shell-tabs__item`) and ranking pill (`shell-ranking__btn`) is a plain `<span>` carrying
   `data-scope-id` / `data-ranking-id` / `data-url-or-param` — no `<a href>`, no
   `data-drupal-*` AJAX attributes, and **no attached JS library** (confirmed below). By the
   template's own docblock: "Every tab and pill is a plain, non-linking element: per the
   acceptance criterion 'no hardcoded routes', this scaffold ships inert... Phase-1 stories
   (#110-#115) can read [url_or_param] off the DOM when they wire real navigation."
2. **No JS file exists in the module**: `find docs/groups/modules/do_streams -iname "*.js"`
   returns nothing. No `*.libraries.yml` either. There is no client-side behavior to drive even
   if the shell were reachable — clicking a tab/pill span does nothing in the browser; it is
   inert markup by explicit design.
3. **`grep -rn "do_streams_shell"` across the whole worktree** (`web/`, all `*.php`, all `*.yml`)
   finds the theme-hook registration (`DoStreamsHooks::theme()`), the preprocess implementation
   (`DoStreamsHooks::preprocessDoStreamsShell()`), and its two Kernel-test call sites
   (`StreamsShellTest.php`, `StreamsInstallTest.php` — the latter only checks the hook is
   *registered*, not rendered on a route). **Zero controller, block plugin, or Views display
   anywhere in the diff sets `#theme => do_streams_shell`.**
4. **`config/install/views.view.do_streams_demo.yml`** — the three page displays
   (`page_1` → `/do-streams/demo/membership`, `page_following` →
   `/do-streams/demo/following`, `page_global` → `/do-streams/demo/global`) all use
   `row: {type: fields}` / `style: {type: default}` — plain Views field-table rows. None of them
   reference the shell theme hook, a `stream_card` row plugin, or a custom row/style plugin that
   could invoke it. Visiting any of these three paths in a live site would render a bare table of
   title/type/created columns, not the shell.
5. **`StreamsShellTest.php`'s own docblock confirms this is intentional and already adjudicated
   in-pipeline**, not an oversight I'm the first to find: T rewrote the shell suite specifically
   because `\Drupal::theme()->render()`/`renderRoot()` cannot round-trip preprocess mutations back
   onto the caller's render array, and pivoted to invoking
   `DoStreamsHooks::preprocessDoStreamsShell()` **directly** rather than through any rendered page
   — "Kernel tests assert query results and render-array shape; Playwright asserts what actually
   paints" (survey.md §Testing approach item 7, quoted in the test's own docblock) presupposes a
   LATER story attaches the route Playwright would exercise.
6. **The brief and README both state the module ships inert by design**: brief.md's Objective —
   "a reusable Twig+preprocess stream shell... built on the existing `stream_card` view mode —
   shipped inert, ready for ST-1/2/4/6 to attach." README.md — "Ships **inert** — no user-facing
   routes of its own beyond a fixture-grade demo view; Phase-1 stories (#110-#115) attach their
   own routes/displays to the contract below." This is issue #109's explicit, approved scope
   boundary (Phase 3 architecture review PASS, Phase 7 anti-duplication PASS both already signed
   off on exactly this "shell is a contract, not a wired page" shape) — not a partial or broken
   implementation.

## Why this is not a DDEV/tooling gap (does not need CANNOT-VERIFY treatment)

The spawn brief's CANNOT-WALK/CANNOT-VERIFY branch is for when the environment blocks verification
(docker unavailable, Playwright won't install, etc.). That is not the situation here: nothing
blocks me from starting DDEV. The reason a browser walkthrough would produce no signal is that
**the surface being asked about does not exist as a live, navigable page in this diff** — not
that I couldn't reach it. Standing up DDEV, installing the site, and hitting
`/do-streams/demo/global` would just prove the three demo paths render plain Views tables (already
provable by reading the view config's `row`/`style` plugins, which admit no other outcome) and
that clicking the shell's tab/pill spans does nothing (already provable by the template carrying
no href, no JS behavior, and the module shipping zero `*.js`/`*.libraries.yml` files). Running
that browser pass would be process theater over a static, load-bearing fact already nailed down by
five independent lines of evidence above (template markup, JS asset inventory, grep for the theme
hook's only call sites, the demo view's row/style plugins, and the Kernel test's own documented
pivot away from route-level rendering) — not a live discovery a headless pass could add to.

## Per the U role's own scope boundary

> "U runs only for an interactive UI surface... Most module stories are non-UI; do not
> manufacture a walkthrough where there is nothing interactive to drive."

do_streams' shell template is real UI-shaped markup and DOES need wireframe conformance checking
— but that conformance is a **static markup/contract check** (structure, classes, ARIA
attributes, per-scope copy strings), which is exactly what T's Kernel suite
(`StreamsShellTest.php`, 6 tests, GREEN per handoff-T-green.md) already asserts at the
render-array and rendered-HTML level: 4 scope tabs with correct `active`/`url_or_param`, 2 ranking
pills, Trending's Recent pill never `disabled` (D-gate resolution 1), `empty` bool driven by
result count, and the 4 distinct per-scope `empty_copy` strings including Global's no-follow-CTA
requirement (D-gate resolution 2) — see `StreamsShellTest.php` tests
`testScopeTabsContractAllFourPresentWithCorrectActiveFlag`,
`testRankingControlContractBothPillsPresentWithCorrectActiveFlag`,
`testTrendingScopeDoesNotDisableTheRecentRankingPill`, `testEmptyFlagReflectsResultCount`,
`testEmptyCopyIsDistinctPerScope`, `testNoHardcodedRoutePathsInRenderedTabMarkup`. There is no
additional signal a live headless browser pass can add on top of that until a downstream story
(#110-#115) attaches a real controller/route that assembles `#theme => do_streams_shell` with
live `results` AND adds the client-side navigation (JS or real `<a href>` links) the template's
own docblock says is each of those stories' job to wire.

**Static wireframe-conformance check performed (structural, not a substitute for the live pass
S still owes on the eventual routed story):**

| Wireframe element (approved wireframe, 6 states) | Built template | Match |
|---|---|---|
| `.shell-tabs` nav, 4 items Global/My Feed/Following/Trending, `is-active` on current | `<nav class="shell-tabs">` with `{% for tab in scope_tabs %}`, `is-active` class + `aria-current="true"` on active tab | Yes — template additionally adds `aria-current`, an accessibility improvement over the low-fi mock (no regression) |
| `.shell-ranking__group`, Recent/Hot pills, `is-active` on current | `<div class="shell-ranking__group">` with `{% for pill in ranking_control %}`, `is-active` class + `aria-pressed` | Yes — `aria-pressed` likewise an addition, not a deviation |
| State 4: Trending → Hot pre-selected, Recent NOT disabled/locked (D-gate res. 1) | `ranking_control` never carries a `disabled` key per `preprocessDoStreamsShell()`'s own docblock/comment; confirmed no `disabled`-conditional in the Twig loop | Yes — matches binding resolution exactly; Kernel test `testTrendingScopeDoesNotDisableTheRecentRankingPill` pins it |
| State 6: `.gc-empty` / `.gc-empty__title` / `.gc-empty__text`, scope-appropriate copy (D-gate res. 2) | `{% if empty %}` branch renders exactly these 3 classes; `empty_copy` is a 4-way per-scope lookup, Global's string checked to not contain "follow" | Yes — Kernel test `testEmptyCopyIsDistinctPerScope` pins both the distinctness and the Global no-follow-CTA rule |
| Legend: no `<a href="/literal/path">` anywhere, controls carry their `id`/`url_or_param` | No `<a>` tag anywhere in the template; `data-scope-id`/`data-ranking-id`/`data-url-or-param` present on every control | Yes — Kernel test `testNoHardcodedRoutePathsInRenderedTabMarkup` pins this at the rendered-HTML level |
| `results` wraps existing `stream_card` rows as a black box | `{{ results }}` printed verbatim inside `.shell-results`, no card markup reimplemented in this template | Yes |

No mismatch found between the built template and the approved wireframe's structure/contract.

## What I did NOT do (and why)

- Did not stand up DDEV / install a site / seed content — would prove nothing beyond what's above
  and risks manufacturing false "verified" confidence over a route that provably does not exist
  in this diff.
- Did not author a Playwright spec — there is no navigable path for it to drive (no `<a href>`,
  no JS, no route serving the shell with live data), and a spec that only loads a raw
  render-array fixture via a test-only route would just re-implement what
  `StreamsShellTest.php` already asserts at the Kernel layer, with none of Playwright's actual
  value-add (real navigation, real console, real click dispatch) — that would be manufacturing a
  walkthrough where there is nothing interactive to drive, which the U role explicitly says not
  to do.
- Did not write `tests/e2e/do-streams-shell.spec.ts` for the same reason — there is no live page
  for it to `page.goto()` into. Adding a spec against a fixture-only harness would be dead weight
  in `tests/e2e/` (a folder reserved for specs that drive the actual served site) and would give
  S/future readers false confidence that this surface has been through a live browser pass when
  it has not.

## Recommendation to O

Re-run U (this phase) against whichever of #110-#115 first attaches a live controller/route to
`#theme => do_streams_shell` with real `results` and real client-side navigation — that is the
first point at which "drive the live UI headlessly" has an actual target. For #109 itself, mark
Phase 8 **N/A (no interactive UI surface reachable via any route in this diff — shell ships as an
inert Twig+preprocess contract by explicit, already-approved design)**, backed by the static
conformance check above in lieu of a live pass. No code changes needed; nothing to send back to F.

## Evidence / commands run

- `grep -rn "do_streams_shell" docs/ web/ --include="*.php" --include="*.yml"` — only
  registration/preprocess/tests, zero route/controller/block call sites.
- `find docs/groups/modules/do_streams -iname "*.js" -o -iname "*.libraries.yml"` — empty.
- Full read: `templates/do-streams-shell.html.twig`,
  `config/install/views.view.do_streams_demo.yml`, `src/Hook/DoStreamsHooks.php`,
  `tests/src/Kernel/StreamsShellTest.php`, `README.md`, `brief.md` (Objective + [B-3]/[B-8]
  sections), `handoff-D.md` (approved wireframe + both binding resolutions), `wireframe.html`
  (all 6 states).
- `git log --oneline -15` on `109-do-streams-scaffold` — confirms Phase 3/5/6/7 already landed
  and passed with this "inert shell" shape as the agreed scope, not something introduced late or
  unreviewed.

No files were modified. No DDEV project was created (none to tear down). `git status --porcelain`
in the worktree is unchanged by this phase.
