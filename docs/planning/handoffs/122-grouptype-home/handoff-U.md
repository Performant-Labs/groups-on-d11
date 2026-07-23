# Handoff-U: Phase 8 — #122 SC-3 Group-type homepages (UI Walkthrough)

**Date:** 2026-07-22
**Branch:** 122-grouptype-home
**Issue:** #122
**Verdict:** PASS

## Environment

- Drove the running DDEV instance directly: `https://gm122-groups-on-d11.ddev.site`
  (already up from F/T's session; confirmed via `ddev status`).
- Resolved exemplar gids directly via `ddev drush sql-query` against
  `groups_field_data` rather than the polluted `/all-groups` directory (T-green
  flagged ~93 accumulated groups): `gid=1` DrupalCon Portland 2026 (events),
  `gid=3` Core Committers (discussion), `gid=4` Thunder Distribution (docs),
  `gid=2` Drupal France (fallback) — matches T-green's own gids exactly.
- Driven with a throwaway Playwright script (`playwright` resolved from the
  worktree's own `node_modules`) against all 4 `/group/{gid}` pages, anonymous
  session (no login needed — lead section reads public group content, matching
  the spec file's own comment). No `u-drive.mjs` helper exists in this repo (that
  helper is specific to the language-buddy/HTMX stack per the project override);
  this is a standard Drupal full-page-load site with no SPA/HTMX nav on this
  surface, confirmed by clicking the Events tab from `/group/1` and observing a
  genuine full navigation to `/group/1/events` (not a client-side swap).
- Script + JSON output not retained (throwaway, per instructions); screenshots
  retained under `u-screenshots/`.

## Per-page walkthrough

### 1. DrupalCon Portland 2026 (`/group/1`, Event planning → events-first)

**What I saw:** `<h1>DrupalCon Portland 2026</h1>`, then the existing
`.gc-group-header` (badges "Event planning" / "Open" / "6 members", avatar row),
then the new lead section headed "Upcoming events" with a ⓘ trigger, two item
links ("DrupalCon Portland Keynote" → `/node/13`, "Code Sprint: Migrate API" →
`/node/14`, each with a formatted date), "See all events →" → `/group/1/events`,
then the unchanged tab bar (Stream/Events/Members/About), then body content.
Anatomy matches wireframe §1 exactly. (Only 2 items render, not 3 — seed data
has 2 qualifying event nodes; correctly ≤ top-N=3, not a defect.)

- Lead section: PASS — present, correctly positioned, correct heading/items/see-all.
- Tab bar: PASS — same 4 tabs, same order; clicked "Events" tab → genuine full
  navigation to `/group/1/events`, HTTP 200, no console errors.
- Tooltip: PASS — ⓘ has `tabindex="0"`, `role="note"`, non-empty `aria-label`
  matching the approved copy ("This page adapts to the group's type — it leads
  with events, discussion, or documentation depending on how the group is
  categorised."), `data-do-tooltip` with the same copy. Focused it directly:
  visible blue focus ring rendered (screenshot `tooltip-events-1.png`), and
  hover fired a visible dark tooltip popup showing the exact copy.
- Console: PASS — zero console errors/pageerrors.
- Responsive (600px): PASS — lead section stacks legibly: heading+ⓘ on one
  line, each item title/date on its own line, "See all events →" beneath, no
  overlap or horizontal scroll (screenshot `events-1-600px.png`).

### 2. Core Committers (`/group/3`, Working group → discussion-first)

**What I saw:** Same anatomy; badges "Working group"/"Open"/"4 members"; lead
section headed "Recent discussions" with 3 forum-node items ("Patch Review
Process RFC", "Drupal 11 Migration Path", "Weekly Standup Notes"), each showing
a relative "N ago" timestamp, "See all discussions →" linking to the
type-filtered `/group/3/nodes?type=forum` (matches A's Q1 resolution, not the
unfiltered stream). Tab bar unchanged and in order.

- Lead section: PASS.
- Tab bar: PASS.
- Tooltip: PASS — identical attributes/copy/focus-ring behavior verified.
- Console: PASS — zero errors.
- Responsive (600px): PASS — same legible stacking pattern.

### 3. Thunder Distribution (`/group/4`, Distribution → docs-first)

**What I saw:** Badges "Distribution"/"Open"/"3 members"; lead section headed
"Documentation" with the 3 seeded documentation nodes ("Upgrading from Thunder 7
to 8", "Media Library Configuration Guide", "Getting Started with Thunder"),
each with a relative timestamp, "See all documentation →" →
`/group/4/nodes?type=documentation`. Tab bar unchanged.

- Lead section: PASS.
- Tab bar: PASS.
- Tooltip: PASS.
- Console: PASS — zero errors.
- Responsive (600px): PASS.

### 4. Drupal France (`/group/2`, Geographical → fallback/unmapped)

**What I saw:** `<h1>Drupal France</h1>`, `.gc-group-header` with badges
"Geographical"/"Open"/"2 members", avatar row — then **directly** the tab bar
(Stream/Events/Members/About), no lead section anywhere, then body content.
Byte-level DOM check: `.gc-group-lead` count = 0, `.gc-group-lead__help` count =
0, `[data-do-tooltip*="adapts to the group"]` count = 0.

- **CSS payload isolation, verified via curl on the raw aggregated CSS files**
  (not just link href string-matching, since Drupal serves hashed aggregate
  filenames that don't literally contain "group-type-homepage"): the
  `delta=1` aggregate served on `/group/1` (events-first) contains 1 match for
  `gc-group-lead` CSS rules; the `delta=1` aggregate served on `/group/2`
  (fallback) — a **different hash entirely** — contains 0 matches. Confirms
  the library-attach conditional genuinely gates on `lead_items` non-empty,
  not just that the URL string looks different. PASS.
- Tab bar: PASS — same 4 tabs/order, confirmed unchanged.
- Console: PASS — zero errors.
- Responsive (600px): PASS — fallback page renders identically at 600px, no
  stray lead-section remnants.

## Cross-cutting checks

- **Heading hierarchy:** page `<h1>` is the group name on all 4 pages; the
  lead section's `<h2>` (with id `gc-group-lead-heading`) is the only `<h2>`
  present on the 3 lead-section pages, and absent entirely on the fallback
  page — matches wireframe §2/§6.
- **Keyboard tab order:** `.focus()` on the ⓘ trigger correctly moves
  `document.activeElement` to it and produces a visible focus ring — confirmed
  by direct screenshot on the events-first exemplar (representative; same
  markup/class on all 3 lead-bearing pages).
- **No SPA/HTMX nav on this surface** — confirmed by clicking a tab and
  observing a genuine full-page navigation (`page.url()` changes to
  `/group/1/events`), consistent with the project override (standard Drupal
  page renders, no HTMX swap layer on `/group/{id}`).
- **Console/JS errors:** zero across all 4 pages and all viewport sizes tested.

## Findings

None. No behavioral defects found. The only cosmetic pre-existing oddity
noticed (5x duplicated "Group Mission" text block above the header on every
group page, all 4 exemplars) is unrelated to #122's scope (not part of the
wireframe, not touched by F's diff, present identically on the fallback page
too) — flagging for O's awareness only, not blocking this story.

## Evidence

Screenshots saved to
`docs/planning/handoffs/122-grouptype-home/u-screenshots/`:
- `events-1-desktop.png`, `events-1-600px.png`, `tooltip-events-1.png`
- `discussion-3-desktop.png`, `discussion-3-600px.png`, `tooltip-discussion-3.png`
- `docs-4-desktop.png`, `docs-4-600px.png`, `tooltip-docs-4.png`
- `fallback-2-desktop.png`, `fallback-2-600px.png`

## Verdict

**PASS.** All four states (events-first, discussion-first, docs-first,
fallback) render exactly per wireframe §1, the tooltip is keyboard-operable
with a visible focus ring and correct accessible name/copy, the tab bar is
unaffected on every page, the fallback page emits zero new DOM and zero new CSS
payload (verified at the raw-aggregate-file level, not just URL string
matching), the responsive 600px layout stacks legibly, and there are no
console/JS errors anywhere. Ready for S.
