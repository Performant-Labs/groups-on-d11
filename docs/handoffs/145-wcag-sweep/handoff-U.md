# Handoff-U: Phase 8 — #145 MC-A11Y WCAG 2.2 AA audit sweep

**Date:** 2026-07-23
**Branch:** 145-wcag-sweep
**Issue:** #145
**Site:** https://gm145-wcag.ddev.site/ (running DDEV, seeded)

## Verdict: REWORK

One confirmed, reproducible manual-a11y defect found on all four required
surfaces: the "Skip to main content" link is visually invisible when it
receives keyboard focus, because the fixed-position `.do-showcase-ribbon`
(POC demo banner, `position: fixed; z-index: 1000`) occupies the identical
top-left screen region (0,0–1280×43) and paints over it. `elementFromPoint`
at that location returns the ribbon's text span, not the skip-link, while
the skip-link is focused — a sighted keyboard user tabbing to the very first
stop on every page sees no indication focus moved at all. This is not a
computed-style false read: confirmed by screenshot (`skiplink-zoom.png`)
and by z-index/rect comparison (ribbon 0,0,1280×43 z-index 1000 vs.
skip-link 0,0,1280×40, no elevated z-index of its own).

Everything else passed. Full per-surface findings below.

## Method
Playwright MCP tools were unavailable in this environment
(`mcp__playwright__browser_*` not registered), so I drove the site with a
throwaway Playwright Node script (`chromium.launch` direct, not via MCP) —
same underlying engine, same keyboard-driven walk, same evidence shape.
Script and evidence images were written to the session scratchpad and the
script was deleted after the run (not committed).

Per surface: navigated fresh, then pressed `Tab` up to 50x capturing
`document.activeElement` (tag/text/href/outline/box-shadow/rect) after each
press, ran a `Shift+Tab` reachability check, and exercised `Enter` on at
least one control. Desktop viewport only (1280×720) — the brief's manual
walk criteria (reachability, visible focus, no traps, one activation, badge
text) do not call out a 360px pass, and none of F's/T's evidence flagged a
mobile-only issue.

## Per-surface findings

**/all-groups (directory)** — FAIL (skip-link only)
- 50 tab stops reached: skip-link → POC banner controls → primary nav
  (Groups/Home/Activity/Stream/Search/Log in) → info tooltips → filters →
  card links/badges → footer nav, ending cleanly at body.
- Focus visible at every stop **except** the skip-link (see verdict). Initial
  computed-style probe flagged the 6 primary-nav items too (`outline: none`
  in `getComputedStyle`), but screenshots (`nav-focus-stop10..14.png`)
  prove a clear blue focus rectangle renders on each — a false negative
  from the style probe, not a real defect. Confirmed visually one by one.
- Shift+Tab reachability: confirmed, focus moves backward correctly.
- Enter activation: pressed on "Groups" nav link, page state consistent
  (already on /all-groups).
- Non-color status cues: `all-groups-badges.png` — "Open" (green), "Archive"/
  "Geographical"/"Event planning"/"Distribution"/"Working group" (blue type
  badges), "Moderated" (amber) all carry readable text labels; contrast
  reads clean post-F's tokens.css fix.

**/group/1 (DrupalCon Portland 2026 — group homepage)** — FAIL (skip-link only)
- 36 tab stops, same nav pattern, ends cleanly.
- Shift+Tab reachability confirmed.
- Enter activation: tabbed to "Home" breadcrumb/footer link (href="/"),
  pressed Enter, page correctly navigated to `/`.

**/group/1/members (manage-members table)** — FAIL (skip-link only)
- 26 tab stops: skip-link → POC banner → nav → role-filter `<select>` → "Go"
  submit button → sticky-header toggle (role=switch) → table content →
  footer links.
- Shift+Tab reachability confirmed.
- Enter activation: tabbed to role-filter "Go" submit button, pressed
  Enter, page reloaded on `/group/1/members` (filter form submitted).

**/group/add/community_group (create-group form)** — FAIL (skip-link only)
- 25 tab stops: skip-link → POC banner → nav → form fields → footer links.
- Shift+Tab reachability confirmed.
- Reached form fields in logical top-to-bottom order; did not submit the
  form (out of scope — reachability/focus was the target).

## Evidence (scratchpad, not committed)
Screenshots and JSON tab-walk traces at:
`C:\Users\aange\AppData\Local\Temp\claude\C--Users-aange-Projects-groups-on-d11\3f8c6656-8990-47c8-9917-3ecdcd64c1ce\scratchpad\u145\`
- `skiplink-zoom.png` — the defect, skip-link focused but POC ribbon
  covers it completely.
- `nav-focus-stop10.png` … `nav-focus-stop14.png` — primary-nav focus rings,
  confirmed visible (refutes the computed-style false negative).
- `all-groups-badges.png` — non-color status-cue evidence.
- `*-tabwalk.json` — full per-stop trace for all four surfaces.

## Recommended fix (for F)
Give the skip-link a higher stacking context than `.do-showcase-ribbon`
when focused (e.g. `.skip-link:focus { z-index: 1001; }` or reposition the
ribbon to not occupy row 0 when a skip-link is focused), OR move the
skip-link's focused position below the ribbon's height. Scope stays within
this story's a11y-fix envelope (CSS-only, no markup/module change implied
unless the simplest fix needs a template tweak to reorder DOM/stacking).

## Acceptance-criteria status (brief.md)
- [x] Tab traversal reaches every interactive control on all four surfaces.
- [ ] Visible focus at every stop — **fails** for the skip-link on all four
  surfaces (primary-nav false negative resolved by visual confirmation).
- [x] Focus order logical, no traps (Shift+Tab confirmed).
- [x] At least one Enter/Space activation confirmed per surface.
- [x] Non-color status cues confirmed on `/all-groups` badges.
- [ ] No visual regression — N/A finding is pre-existing (ribbon/skip-link
  z-index collision), unrelated to F's tokens.css contrast fix, but still
  blocks the manual-a11y bar this phase owns.

## Next step
Report REWORK to O. F should add a small z-index/positioning fix for the
skip-link vs. `.do-showcase-ribbon`; re-run T then U on the corrected build.

---

## Rerun verification (#145 rework)

**Date:** 2026-07-23
**Verdict: PASS**

Re-ran a throwaway Playwright script (direct `chromium.launch`, MCP tools still not
registered in this environment) against `https://gm145-wcag.ddev.site/`, run from
`C:\Users\aange\Projects\_worktrees\groups-wcag-145` so `playwright` resolved from the
worktree's `node_modules`. Script deleted after the run (not committed).

Per surface (`/all-groups`, `/group/1`, `/group/1/members`,
`/group/add/community_group`): fresh navigation, one `Tab` press, then
`document.activeElement` + `elementFromPoint` at the focused element's own screen rect,
plus a screenshot clip of the top 60px.

| Surface | Active el on Tab 1 | z-index (skip-link / ribbon) | elementFromPoint hits skip-link | Verdict |
|---|---|---|---|---|
| `/all-groups` | `a.skip-link` "Skip to main content" | 503 / 499 | yes | PASS |
| `/group/1` | `a.skip-link` "Skip to main content" | 503 / 499 | yes | PASS |
| `/group/1/members` | `a.skip-link` "Skip to main content" | 503 / 499 | yes | PASS |
| `/group/add/community_group` | `a.skip-link` "Skip to main content" | 503 / 499 | yes | PASS |

All four surfaces: `topElIsActiveOrDescendant: true` — the exact inversion of the
originally reported defect (previously the ribbon's text span was returned at that
point while the skip-link held focus). Screenshot
(`all-groups-skiplink-focus.png`, scratchpad) shows the familiar solid black bar with
legible white "Skip to main content →" text, matching Olivero's default focused
appearance, no ribbon bleed-through.

**Regression spot-check:** 3 additional `Tab` presses per surface after the skip-link
stop landed on the POC-banner "See what it compares" link, the banner's dismiss (✕)
button, and an info tooltip (ⓘ) — all three showed a clearly visible focus outline
(`2px solid` blue or `1px auto` browser default) on every surface, matching the
first-pass walk. No new dead stops, no traps, no occlusion introduced by the z-index
change.

**Evidence (scratchpad, not committed):**
`C:\Users\aange\AppData\Local\Temp\claude\C--Users-aange-Projects-groups-on-d11\3f8c6656-8990-47c8-9917-3ecdcd64c1ce\scratchpad\u145-rework\`
- `all-groups-skiplink-focus.png`, `group-1-skiplink-focus.png`,
  `group-1-members-skiplink-focus.png`, `group-add-skiplink-focus.png` — top-60px
  clips, skip-link visible on all four.

## Next step
Report PASS to O. Story #145 ready for S (Spec Auditor).
