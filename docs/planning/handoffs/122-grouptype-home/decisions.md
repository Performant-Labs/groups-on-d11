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
