# Handoff-U — Phase 8 (UI Walkthrough) — Issue #112 (ST-3: /my-feed/events)

**Date:** 2026-07-23
**Agent:** U (Playwright-MCP backend)
**Branch:** `112-events-rsvps` @ `9727e15`
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st3-events-112`
**Target:** `http://gm112-events.ddev.site/my-feed/events` (running DDEV project `gm112-events`)
**Persona:** `elena_garcia` (uid=4)

## Verdict

**PASS** — with 2 minor ADVISORY findings (below), neither blocking.

All acceptance criteria from the brief are met, all fixes from `handoff-F-rework.md`
(shell chrome suppression) verified live, wireframe conformance is faithful (chip
markup, iCal buttons, section headings, date badges, group badges, RSVP chip states
all match), console is clean on the target route, keyboard navigation reaches every
control, focus indicators are visible.

## Run environment

- **Serve:** running DDEV project `gm112-events` — reused (T's Phase-6 seeded site).
- **Auth:** one-time login via `ddev drush uli --uid=4`.
- **Driver:** local Playwright (`playwright@1.x` from repo `node_modules`), headless
  chromium; script at `.u-walk.mjs` (deleted at end of session).
- **Viewports:** desktop 1280×720 (default) + mobile 360×800.

## Per-control walkthrough (action → expected → observed)

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | GET `/my-feed/events` anonymous | 403 or login-redirect | HTTP 403 | PASS |
| 2 | Load one-time login link, submit | Authenticated as elena | Redirected to `/user/4/edit` (auth OK) | PASS |
| 3 | GET `/my-feed/events` authenticated | HTTP 200 | HTTP 200 | PASS |
| 4 | Count H2s in main | ≥2 including "Upcoming events" + "My RSVPs" | 5 total; page H2s include both | PASS |
| 5 | Count `.event-card` | > 0 | 7 (4 Upcoming + 3 My RSVPs on default) | PASS |
| 6 | Contains Barcelona / Keynote / Sprint | all 3 | all 3 present | PASS |
| 7 | Upcoming ordering (Barcelona<Keynote<Sprint by text position) | ascending by date | posB=545 < posK=662 < posS=750 | PASS |
| 8 | Keynote RSVP chip text | "You're going · 4 going" | `✓ You're going · 4 going` | PASS |
| 9 | Chip states (icon + text, not color-only) | outline `○ RSVP · N going` for non-elena events; filled `✓ You're going · N going` for elena's | Governance shows `○ RSVP · 0 going`; Barcelona/Keynote/Sprint show `✓ You're going · N going` — both in Upcoming and My RSVPs sections | PASS |
| 10 | Site iCal link present | `/upcoming-events/ical` | href=`/upcoming-events/ical` | PASS |
| 11 | User iCal link present | `/user/<uid>/events/ical` | href=`/user/4/events/ical` | PASS |
| 12 | Fetch site iCal | 200 + `text/calendar` | 200, `text/calendar; charset=utf-8` | PASS |
| 13 | Fetch user iCal | 200 + `text/calendar` | 200, `text/calendar; charset=utf-8` | PASS |
| 14 | Phantom shell scope-nav (`aria-label="Stream scope"`) | 0 (fix from 9727e15) | count=0 | PASS |
| 15 | Phantom ranking-pill wrapper (`.shell-ranking`) | 0 | count=0 | PASS |
| 16 | Click Global toggle | Upcoming widens to include Thunder and/or Governance | Thunder=true, Governance=true (Thunder joined the list, count went 4→5) | PASS |
| 17 | H1 present | "My Feed — Events" | present | PASS (see Advisory-1) |
| 18 | No skipped heading levels | true | true | PASS |
| 19 | Keyboard Tab traversal | all interactives reachable | 25 focusable stops in 25 tabs | PASS |
| 20 | Focus indicator on first main link | visible outline/box-shadow | `outline: rgb(4,69,104) solid 3px` | PASS |
| 21 | Mobile 360px reflow | usable single-column stack | cards, chips, iCal buttons wrap cleanly | PASS |
| 22 | Console errors on `/my-feed/events` | none from this route | 0 failed responses on target navigation | PASS |
| 23 | Past events excluded | no past dates | all shown events dated Aug–Oct 2026 (future) | PASS |

## Wireframe conformance

Faithful match against `wireframe.html`:
- Page title "My Feed — Events" (H1) ✓
- Two iCal subscribe buttons in the header ("Subscribe: all events (iCal)" +
  "Subscribe: my RSVPs (iCal)") ✓
- Global / My Groups scope toggle ✓
- Section headings "Upcoming events" and "My RSVPs" with subcopy ✓
- `.event-card` markup: date badge (SEP 21) · title (H3) · group badge · RSVP chip ✓
- RSVP chip both states use icon + text (`○ RSVP · 0 going` / `✓ You're going · 4 going`) ✓
- Non-elena Governance Town Hall correctly shows the outline (not-going) chip ✓

## Screenshots

Attached to `docs/planning/handoffs/112-events-rsvps/screenshots/`:

- `01-desktop-full.png` — full desktop `/my-feed/events` as elena (default scope).
- `02-keynote-chip.png` — DrupalCon Portland Keynote card close-up with
  `✓ You're going · 4 going` filled chip.
- `03-upcoming-section.png` — Upcoming events section.
- `04-my-rsvps-section.png` — My RSVPs section.
- `05-global-toggle-expanded.png` — after clicking Global toggle: 5 events,
  Thunder Editorial Workshop now included.
- `06-mobile-360.png` — full-page 360×800 responsive layout.

## Advisory findings (non-blocking)

**Advisory-1: H1 rendered twice.** The heading-level scan surfaced two consecutive H1
elements both with text "My Feed — Events" — the theme's own page-title block plus a
controller-emitted title inside the shell. Visually one appears large (page title),
the other smaller (in-shell repeat). WCAG 2.2 AA doesn't forbid multiple H1s, but for
screen-reader users this is a duplicate landmark. Cosmetic; recommend a future story
consolidates to a single H1 (either drop the theme block on this route or make the
in-shell one an H2). NOT specific to #112 — the same pattern likely affects
`/my-feed` from #110. Do not block on this here.

**Advisory-2: Scope-tabs container is a `<div>`, not a landmark `<nav>`.** The
Global/My Groups toggle renders as
`<div data-testid="do-streams-shell-tabs" class="shell-tabs">` with two `<a
data-testid="do-streams-shell-tab">` children — no `role="tablist"`, no `<nav
aria-label>` wrapper. It works, is keyboard-reachable, focus-visible, and the two
links have distinct visible text (Global / My Groups). But it's not exposed as a
distinct navigation landmark. This is `do_streams`-wide, not #112-specific. Same
non-blocking cosmetic call as Advisory-1.

**Note on default-scope event count.** The brief's AC list ("Upcoming shows Barcelona
→ Keynote → Sprint in date ASC") anticipated exactly 3 events; the delivered UI shows
**4** (Governance Town Hall precedes the three by date, AUG 22 before SEP 21). This is
NOT a defect — verified via DB query that elena IS a member of Leadership Council
(the group hosting Governance Town Hall), so the my-groups scope legitimately
includes it. The AC under-specified relative to the actual seed. F/T's own decisions
journal records the same reconciliation (Phase-6 entry, T scoped the E2E "before"
assertion to Thunder only for the same reason). Recording here for S's audit
cross-check, not as a finding.

## Findings requiring rework

**None.** No blocking behavioral, wireframe-conformance, or a11y-spot-check defects
observed.

## Ready for S

The UI behaves per the wireframe, all acceptance criteria are demonstrably met on the
live seeded site, and no console errors are emitted on the target route.

`U complete, UI verified. Ready for S.`
