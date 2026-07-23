# Decision journal — #122 SC-3 Group-type-driven homepages

Append-only. One entry per phase. O writes the closing Chain Summary.

---

## O — Phase 1 (survey + brief)

**Decided:**
- Extend existing `groups_chrome_preprocess_group()` (theme, not module) instead of adding a
  parallel path. This preprocess already reads `field_group_type` and builds `$gc['tabs']`.
- Add ONE new function: `groups_chrome_theme_suggestions_group_alter()` — story explicitly
  asks for theme suggestions and no existing suggestion hook lives in the theme.
- Content sources: existing `views.view.group_events` for events; `node.type.forum` and
  `node.type.documentation` for discussion/docs (query via group relationships, mirroring
  the last-activity read at lines 456–470 of `groups_chrome.theme`).
- HelpText: append ONE key (`group_type.homepage_adapts`) to
  `docs/groups/modules/do_chrome/src/HelpText.php`.
- Review rigor: **none** per story spec.
- Source of truth for the theme layer = `web/themes/custom/groups_chrome/` (tracked in git,
  not gitignored). The "docs/groups/ only" rule applies to `web/modules/custom/` +
  `config/sync/` (assembled artifacts), not the theme.

**Assumed (needs verification in A / T):**
- `views.view.group_events` renders acceptable data for an "events-first" lead section, OR
  the preprocess can build its own render array of top-N events by querying the group's
  event nodes directly. A will judge which is cleaner.
- The three seeded exemplars still tag as expected on a fresh CI install (verified against
  `step_720_group_types.php` — the mapping is present; assumed the seed still runs in E2E CI).

**Hedged:**
- Whether the "leading section" should replace the tab bar's default view or render as a
  block above it. Wireframe (D) will decide. Prefer above-the-tabs block so tabs stay
  intact and un-mapped types get zero visual change.

**Evidence:**
- `web/themes/custom/groups_chrome/groups_chrome.theme` lines 204–356 (existing preprocess).
- `docs/groups/scripts/step_720_group_types.php` lines 68–102 (terms + exemplar tags).
- `docs/groups/config/node.type.{event,forum,documentation}.yml` exist.
- `docs/groups/config/views.view.group_events.yml` exists.

---
## D — Phase 2 (wireframe)

**Decided:**
- Template strategy: **option (b)** — one base `group--full.html.twig` with an
  `{% if gc_group.lead_items is not empty %}` lead-section block; the three suggestion-target
  twigs (`group--community_group--{events,discussion,docs}-first.html.twig`) are near-empty
  passthroughs that include the base. Chosen over three full duplicate twigs (option a)
  because the variants differ only in preprocess-computed data, not markup — duplicating ~80
  lines of header/tabs markup three times would violate "reuse, don't reinvent" and create
  three places a future header tweak must repeat. The brief explicitly allows this choice.
- Top N = 3 items per lead section (avatar-row/tab-teaser precedent already on this page;
  fits the existing 600px mobile breakpoint without wrapping; named constant
  `LEAD_ITEM_LIMIT` in the preprocess for easy tuning).
- Empty-state: **hide the whole `.gc-group-lead` section** (same DOM as the `null` fallback)
  when the group's leading type has zero qualifying nodes — an empty box duplicating the
  existing tab bar with a "See all" link and no prerequisite guidance was judged worse than no
  section at all. Preprocess exposes `gc_group.lead_items` (empty array when nothing
  qualifies); twig gates on that, not on `leading_section` alone.
- Lead section position: new `<section class="gc-group-lead">` sibling BETWEEN
  `.gc-group-header` and `.gc-group-tabs`, inside the existing `{% if gc_group %}` branch.
  Heading level `<h2>` (page `<h1>` stays the group name, rendered upstream in
  content_above — confirmed by the existing twig's own comment explaining why the header
  doesn't repeat an `<h1>`).
- Tooltip: reuse the EXACT existing `do_chrome` info-trigger pattern (ⓘ span,
  `tabindex="0"`, `role="note"`, `aria-label` + `data-do-tooltip`, class `do-chrome-info`) —
  no new pattern invented. `do_chrome/tooltips` JS is already attached globally by
  `DoChromeHooks::pageAttachments()`; `groups_chrome` renders only the data attribute, no new
  library attach for the tooltip itself.
- HelpText copy for `group_type.homepage_adapts` improved over the brief's placeholder to name
  the three concrete variants (events/discussion/documentation) rather than staying vague.
- CSS: new `css/group-type-homepage.css` + new `group_type_homepage` library entry, attached
  ONLY inside the same conditional that populates non-empty `gc_group.lead_items` — verified
  as an explicit fallback-contract assertion (no `group-type-homepage.css` reference on
  unmapped/empty pages).
- "See all" links reuse existing per-group view paths only; events uses the existing
  `/group/{id}/events` tab URL already built in the preprocess.

**Assumed (needs verification in A):**
- Discussion/docs "See all" target: no dedicated filtered route exists today, so the wireframe
  proposes linking to the unfiltered `/group/{id}/stream` with descriptive link text unless A
  finds an existing type-filtered path in `group_nodes`/`hot_content` views config.
- ⓘ trigger AA contrast in this new page background: reusing `do-chrome-info` styling
  as-is, but I have not rendered the live page to visually re-confirm contrast in this
  specific context (prior use was a form row) — flagged for F/A to spot-check.

**Hedged:**
- Top N = 3 stated as firm recommendation; only 2 and 4 were considered as alternatives and
  rejected (thin content vs. mobile wrap risk).

**Evidence:**
- `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` (existing header/
  tabs markup + comment on why no `<h1>` repeats here).
- `web/themes/custom/groups_chrome/groups_chrome.theme` lines 204–356 (existing preprocess,
  avatar-limit-capped-loop pattern, last-activity date-formatter usage to reuse for item meta).
- `docs/groups/modules/do_chrome/src/Hook/GroupTypeContentHelp.php` (`infoTrigger()` — the
  exact tooltip-trigger pattern reused verbatim).
- `docs/groups/modules/do_chrome/src/Hook/DoChromeHooks.php` (confirms `do_chrome/tooltips` is
  attached globally via `page_attachments`, not per-surface).
- `docs/groups/modules/do_chrome/src/HelpText.php` (append-only copy-key convention).
- `web/themes/custom/groups_chrome/css/group-page.css` + `groups_chrome.libraries.yml`
  (existing CSS/library isolation + attach-on-render convention for `group_page`).

---

## A — Phase 3 (up-front)

**Decided:**
- Verdict: PASS. Plan extends `groups_chrome_preprocess_group()` correctly; no parallel-path risk.
- Q1 (see-all target): USE existing `/group/{gid}/nodes?type=forum|documentation` — `group_nodes` view has an exposed bundle filter on the page display (path `group/%group/nodes`, filter identifier `type`, `plugin_id: bundle`, `operator: in`, `exposed: true`). NO unfiltered stream fallback needed. Events uses `/group/{gid}/events` unchanged.
- Q2 (contrast): AA satisfied with `.do-chrome-info` as-is. `#0067b8` on `#ffffff` (`--gc-color-bg`) ≈ 5.36:1 (> 4.5 AA normal-text threshold). F does NOT need a color override — margin/padding-only BEM class on `.gc-group-lead__help` is fine.
- Node-query mechanism: mirror the neighboring last-activity block's `$group->getRelationships('group_node:{bundle}')` iteration (theme.php:456–470), NOT a new entityQuery, NOT a rendered View. Keeps abstraction consistent with the file.
- Cache metadata contract: F MUST attach `$group->getCacheTags()`, `node_list:{bundle}` for the resolved section, each rendered node's tags, and contexts `user.node_grants:view` + `user.group_permissions`. Max-age inherits (`-1`).
- Theme suggestion name shape confirmed: underscores in the suggestion string (`events_first`); Drupal converts to hyphens for filenames (`events-first.html.twig`).
- Node access: filter items via `$node->access('view', $current_user)` inside the loop (defensive — the top-3 curation warrants an explicit check).
- Sort: events → prefer upcoming `field_event_date` ASC; fallback to `created` DESC if the field is absent. Discussion/docs → `created` DESC.

**Assumed (needs verification in T/F):**
- Exact query-string form for the exposed bundle filter (`?type=forum` vs. `?type[]=forum`) — F should verify against the exposed form's actual URL emission once. Either is acceptable if it filters correctly.
- `field_event_date` (or an analog) exists on `node.type.event`. F confirms at implementation time; if absent, `created` DESC is the accepted degraded semantic.

**Hedged:**
- None — both of D's open questions resolved definitively.

**Evidence:**
- `docs/groups/config/views.view.group_nodes.yml` lines 756–786 (exposed bundle filter), line 941 (page path).
- `docs/groups/modules/do_chrome/css/do_chrome.css` lines 71–88 (`.do-chrome-info` color/focus).
- `web/themes/custom/groups_chrome/css/tokens.css` line 29 (`--gc-color-bg: #ffffff`).
- `web/themes/custom/groups_chrome/groups_chrome.theme` lines 204–356 (preprocess to extend), lines 455–481 (relationship-iteration pattern to mirror).
- `docs/groups/modules/do_chrome/src/Hook/GroupTypeContentHelp.php` lines 136–150 (verbatim `infoTrigger()` pattern).
- Recently-merged waves inspected: no `do_streams`/`do_showcase`/`do_group_membership` primitive for a "group lead section" exists — no duplication risk.

---

## T — Phase 4 (RED)

**Decided:**
- Authored `tests/e2e/group-type-homepage.spec.ts` (10 tests, 5 describe blocks) as the primary
  suite — this is a UI-surface story, so E2E carries the acceptance-criteria weight per A's own
  "Test-writability" section (which lists only E2E-level assertions as the coverage plan).
- Extended `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` with ONE targeted
  test (`testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants`) after VERIFYING the task's
  "HelpTextTest auto-covers the appended key" claim is FALSE — `HelpText::all()` is a fixed
  literal array and the only "all-keys" test only checks value *types*, not key *presence*.
  Every other key gets its own named test in this file; the new key needed the same treatment.
- No Kernel test authored for `groups_chrome_preprocess_group()` — no existing Kernel harness
  targets any `groups_chrome.theme` procedural function, and A's own "Test-writability" section
  names only E2E assertions as sufficient. Building fresh theme-preprocess Kernel infrastructure
  would duplicate what the E2E suite already pins. Documented per the brief's own hedge.
- Stood up a real seeded DDEV site (`gm122-groups-on-d11`, namespaced per this story's own
  container guardrail) to get genuine RED evidence rather than reasoning about expected
  failures — no `vendor`/`php`/`composer` were available on the host shell.
- Left the `gm122-groups-on-d11` DDEV project running/installed/seeded for F/T-green to reuse.

**Assumed (needs verification in F/T-green):**
- "See all" query-string form asserted permissively (`type=forum` OR `type[]=forum`) per A's own
  hedge that F should verify the exact form Views emits.
- Item link target asserted generically (`/node/\d+`), not a specific node id — the sort/top-N
  selection is F's implementation detail per A's guidance.

**Hedged:**
- The Thunder Distribution (Distribution-type) exemplar test is a KNOWN WEAK RED: no
  documentation-type node exists anywhere in the current seed data
  (`docs/groups/scripts/step_700_demo_data.php` seeds forum/event nodes only), so per the
  wireframe's own empty-state contract, this exemplar's page is indistinguishable from the
  fallback case both before AND after F implements correctly. This test currently passes
  vacuously and will continue to pass at GREEN — it does not independently prove the docs-first
  rendering path ever works end-to-end. Flagged for F/U; not silently claimed as full coverage.
  Fixing this properly requires adding seed content, which is outside this story's file-
  ownership list.
- Full `@axe-core/playwright` WCAG scan NOT automated — dependency absent from `package.json`,
  same documented gap `manage-members.spec.ts` already established. A stub test pins the gap
  itself (self-flags for replacement if the dependency is ever added) plus a keyboard/focus/
  aria-label test covers what a headless browser can prove without axe. The standalone
  `axe-check.cjs` tool remains available for a manual U pass.

**Evidence:**
- E2E RED: `BASE_URL="http://gm122-groups-on-d11.ddev.site" npx playwright test tests/e2e/group-type-homepage.spec.ts`
  → 7 passed, 3 failed (the 3 genuine feature-dependent failures on `.gc-group-lead` not
  existing; see `handoff-T-red.md` for full output).
- PHPUnit RED: `ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php`
  → 10 passed (pre-existing), 1 failed (new test, correct assertion: unknown-key default `''`).
- Sanity checks: `directory-cards.spec.ts` (existing suite) 3/3 green against the same seed;
  `curl .../all-groups` confirms all four exemplar labels render.
- `docs/groups/scripts/step_700_demo_data.php` (verified: no `"type" => "documentation"` node
  create call anywhere — the Thunder Distribution coverage gap above).

---
