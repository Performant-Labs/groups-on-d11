# Handoff-D: Phase 2 - /my-feed/events (Events + My RSVPs) wireframe

**Date:** 2026-07-23
**Branch:** `112-events-rsvps`
**Mode:** (a) generated low-fi
**Wireframe:** `docs/planning/handoffs/112-events-rsvps/wireframe.html`

## Screens & states covered

1. **Populated (elena_garcia, My-groups scope)** — page header (title + two iCal
   links), Global/My-groups toggle (reusing #109/#110's `shell-tabs` markup/testids,
   `?scope=` query-param convention, two tabs instead of the full four-scope set),
   then two sections inside ONE shell: **Upcoming events** (Barcelona -> Keynote ->
   Sprint, date ASC) and **My RSVPs** (Elena's three flaggings, date ASC). Every
   event card carries an RSVP chip: outline "RSVP . N going" (not attending) vs.
   filled "You're going . N going" (attending) — icon + text in both states, never
   color-only. Keynote's chip reads "You're going . 4 going" per the acceptance bar.
2. **Empty (0 memberships, 0 RSVPs)** — same header/toggle chrome; Upcoming's empty
   copy names the prerequisite (join a group) and offers a same-page escape hatch
   (switch to Global); My RSVPs' empty copy points at the OTHER section on the same
   page instead of repeating a join-a-group CTA (RSVPing needs an event to exist,
   not a group per se) — two genuinely distinct strings, not a shared generic one.
   Only Upcoming carries a CTA button; My RSVPs deliberately does not (redundant
   otherwise).
3. **Anonymous (403 / login-redirect)** — annotation only, no visual: route access
   gate (`_user_is_logged_in: 'TRUE'`, per ST-1's verified Drupal-11 pattern) means
   no shell renders; either a 403 or a login redirect to
   `/user/login?destination=/my-feed/events` is acceptable — this is an
   access-control decision, not a design one, and the wireframe does not mandate
   one over the other.

## Existing components/patterns reused

- `.shell.do-streams-shell` / `nav.shell-tabs` / `.shell-tabs__item` — verbatim
  from #109/#110's approved shell chrome (classes, `data-testid`s, focus-visible
  styling), only the tab SET differs (Global/My-groups here vs. the full
  Global/My Feed/Following/Trending set) — no new toggle mechanism, same
  `data-url-or-param="?scope=<id>"` contract.
- `.gc-empty` / `.gc-empty__title` / `.gc-empty__text` / `.gc-empty__cta-link` —
  verbatim from #109/#110's empty-state pattern, just given per-section
  `data-testid`s (`upcoming-events-empty` / `my-rsvps-empty`) since this page can
  have either section empty independently (unlike the single-display shell's
  all-or-nothing empty flag).
- `.card`-family visual language (border/radius/shadow/badge treatment) extended
  into a new `.event-card` partial (date badge + title + group badge + RSVP chip)
  — same tokens (`--gc-color-*`, `--gc-radius-*`, `--gc-space-*`) as #109/#110, no
  new color system.
- iCal links are plain `<a href>` to the real, existing `do_discovery.ical_site`
  and `do_discovery.ical_user` routes — not reimplemented, per the brief's
  non-goal.

## Open questions for approval

- **Q-D1** (RSVP chip interactivity): wireframe renders the chip as a clickable
  `<button>`. The issue's Reuse map only calls for a READ-time chip (going-count +
  viewer-state display), not a new toggle interaction on this page. Assumed the
  button affordance is harmless and F may render it as inert markup (e.g. a
  `<span>`) if wiring a live toggle here is out of scope — needs operator
  confirmation before F commits to either.
- **Q-D2** (missing-date defensive fallback, #60 risk): survey.md notes that if a
  seeded/future event lacks `field_date_of_event`, the chip's date badge should be
  hidden rather than causing a 500. All 5 seeded events have dates today, so this
  degraded state isn't wireframed as a 4th state — noted as a defensive fallback
  (omit the `.event-card__date` block; flex-gap collapses cleanly) for F to
  implement only if #60 actually surfaces.

## Approval

[To be filled by O: "Approved by operator <ISO timestamp>"]
