# Handoff-U: Phase 8 — #140 MC-1 Links & Resources — UI Walkthrough

**Date:** 2026-07-23
**Branch:** 140-links
**Issue:** #140
**Verdict:** **PASS**

## Environment

- DDEV project `gm140-groups-links`, already running (installed + seeded during T's GREEN pass, reused per instructions — no re-seed performed).
- Live site: `http://gm140-groups-links.ddev.site`
- Verified up: `curl -s -o /dev/null -w '%{http_code}' http://gm140-groups-links.ddev.site/all-groups` -> `200`
- Driven with a throwaway Playwright script (`.u-walk*.mjs`, deleted after use — this is standard Drupal server-rendered HTML, no HTMX/SPA swap path to special-case per the project override note) against real Chromium, plus one authenticated pass via `ddev drush uli --uid=1`.
- Screenshots: `docs/planning/handoffs/140-links/screens/`

## Scenario results

| # | Scenario | Result |
|---|---|---|
| 1 | Navigate `/all-groups` -> click "DrupalCon Portland 2026" card | PASS — lands on `/group/1` |
| 2 | "Links & Resources" section visible with "Conference schedule" + "Sponsorship info" | PASS — both links visible, exact seeded titles |
| 3 | Screenshot of section | PASS — `screens/links-section.png` |
| 4 | Hover a link; href = seeded URL | PASS — `href1=https://events.drupal.org/portland2026/schedule`, `href2=.../sponsors` |
| 5 | Keyboard-only Tab navigation reaches the link; focus outline visible | PASS — real `Tab` keypresses (22 tabs from page top) landed on "Conference schedule"; computed style `outline: 2px solid rgb(27,154,228)` (not `none`/`0`). Screenshot: `screens/keyboard-focus-conference-schedule.png` |
| 6 | DOM inspect: external links carry `rel="noopener"` + `target="_blank"` | PASS — both links: `rel="noopener"`, `target="_blank"`, confirmed via `querySelectorAll('.field--name-field-group-links a[href^="http"]')` |
| 7 | Group WITHOUT links ("Leadership Council", "Camp Organizers EMEA") shows NO "Links & Resources" heading | PASS — confirmed absent on both; screenshot `screens/no-links-group.png` shows only "Group Mission" + "Visibility" fields, no Links section, no bare header |
| 8 | Console free of new errors | PASS — zero `console.error` / `pageerror` events across all navigations (directory browse, group view via SPA-style click-nav, hard `goto`, keyboard nav, login, edit-form load) |
| 9 (optional) | Authenticated (uid=1, via `drush uli`) `/group/1/edit` shows Links & Resources widget with "Add another item" | PASS — widget renders 2 populated URL/Link-text delta rows + 1 empty extra row + "Add another item" button; screenshot `screens/edit-form-links-widget.png` |
| 10 (optional) | Add/remove a test link | Skipped — read-only inspection of the edit form already fully confirms widget rendering per acceptance criteria; declined to mutate seed data unnecessarily |

## Additional cross-checks (beyond T's 2 E2E tests — exceeding automated coverage)

- Re-verified the same group page reached via **both** a directory-card click (server-rendered link nav) and a direct hard `goto('/group/1')` — identical DOM output, no discrepancy (expected: Drupal has no client-side swap here, so this is a non-issue for this stack, confirmed rather than assumed).
- Verified a **third** group with links, "Thunder Distribution" via seed script read (not re-driven live, redundant with #1/#2 given both already-passing E2E + this walkthrough's own DrupalCon pass) — seed script confirms `Thunder homepage` / `Thunder repo` set at `docs/groups/scripts/step_700_demo_data.php:501-504`.
- Confirmed **heading hierarchy** on the group page via full `<h1>`-`<h6>` sweep: `Links & Resources` renders as `<div class="field__label">`, not a real heading tag — see WCAG note below.
- Confirmed via raw HTML (`curl`) that the field wrapper class T's E2E test scopes to (`.field--name-field-group-links`) matches production output exactly.

## WCAG / UX notes (advisory, non-blocking — informational for S)

1. **Heading semantics:** `<div class="field__label">Links &amp; Resources</div>` is a `<div>`, not an `<h2>`/`<h3>`. This is **not a regression introduced by #140** — it is Drupal core's default `label: above` field-label markup, byte-identical in structure to the sibling `Visibility` field label immediately above it on the same page, and matches A's Phase-3 plan decision ("H2 source = field's own `label: above` setting" — the plan used "H2" as a conceptual/visual descriptor, not a literal tag requirement). No other field label on this group page (Description, Visibility) uses a real heading tag either, so this section is visually and structurally consistent with its neighbors. Flagging for S's WCAG verdict since a screen-reader user cannot jump directly to "Links & Resources" via heading navigation, but this is a pre-existing site-wide pattern, not new debt from this story.
2. **Link text = seeded title in all cases** — no "click here" / raw-URL link text anywhere. Confirmed both for accessible-name compliance and visually.
3. **Focus outline is clearly visible** (2px solid blue) on both mouse-independent `.focus()` and real keyboard `Tab` traversal — WCAG 2.4.11 satisfied for this control.
4. **Contrast spot-check:** link text `rgb(27,154,228)` on `rgb(246,248,248)` background, 16px — this is Olivero's standard link-blue token, used site-wide; not re-litigated here (pre-existing theme choice, out of this story's scope).
5. **Empty state:** confirmed on 2 separate groups (Leadership Council, Camp Organizers EMEA) — no heading, no wrapper markup, no visual gap/placeholder. Matches the "render nothing" acceptance criterion exactly.

## Console / error log

Zero `console.error` and zero `pageerror` events across the entire walkthrough (anonymous directory browse and group view, keyboard navigation pass, authenticated login + edit-form load).

## Verdict

**PASS.** All required scenarios (1-8) confirmed against the live seeded DDEV site, exceeding T's 2-test automated E2E coverage (which only checks DrupalCon Portland's section-visible + rel="noopener" on one group). This walkthrough additionally verified: real keyboard-only reachability with a visible focus ring, DOM-level rel/target on both seeded links, exact empty-state suppression on two different unlinked groups (not just kernel-test isolation), and the authenticated organizer edit-form widget. No behavioral defects found. One advisory (non-blocking) WCAG note on heading semantics is recorded above for S, consistent with a pre-existing site-wide pattern rather than a regression.

No code changes needed. Ready for S.
