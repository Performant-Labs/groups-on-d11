# Handoff-U: Phase 8 - Story 127 SD-2 Card Info Tooltips

Date: 2026-07-23
Branch: 127-card-tooltips
Issue: 127
Handoff-T-green reviewed: docs/planning/handoffs/127-card-tooltips/handoff-T-green.md
Diff-gate round 1 reviewed: docs/planning/handoffs/127-card-tooltips/diff-gate-round1.md (PASS, W-3 flagged role=note semantics -- independently assessed below)

## Run environment

- DDEV project gm127-card-tooltips at https://gm127-card-tooltips.ddev.site (already installed and seeded -- no fresh assembly needed).
- Confirmed up: curl against /all-groups and /stream both returned 200.
- Driven with a throwaway Playwright script (.u-walk*.mjs, deleted after use) against playwright 1.61.1 resolved from the worktree own node_modules. Not the HTMX u-drive.mjs helper -- this project has no SPA/HTMX nav to special-case (plain Drupal page loads), so standard page.goto() plus hover()/.focus() was the correct and sufficient drive mechanism.
- Authenticated persona: ddev drush uli --name=elena_garcia one-time login link, confirmed session cookie persisted across navigation and persona-switcher banner read the browsing-as-Elena-Garcia-Member text.

## Per-control checklist

### Directory cards - /all-groups (anonymous)

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | Load /all-groups | at least 1 .gc-directory-card | 11 cards | PASS |
| 2 | Hover type info-icon (card with a type badge; several seeded cards have none, correctly guarded by an if block) | Tippy tooltip, card.directory.type copy | Tooltip text: What kind of group this is -- Geographical (local user group), Working group (module or initiative), Distribution (Drupal distro), Event planning, or Archive (read-only). Matches HelpText verbatim | PASS |
| 3 | Hover visibility info-icon on an Open card | Tooltip matches visibility.open | Tooltip text: Open: anyone signed in can join instantly, no approval needed. This is live on the demo -- logged-in visitors can join Open groups now. Byte-identical to the reused HelpText key | PASS |
| 4 | Hover members info-icon | card.directory.members copy | Tooltip text: How many people have joined this group. | PASS |
| 5 | Keyboard: focus the type info-icon | Focus lands, visible outline, tippy shows on focus | outlineStyle solid, outlineWidth 2px, outlineColor rgb(0,103,184); tippy became visible on focus | PASS |
| 6 | Hover the visibility badge itself, not the info-icon | No tooltip fires; no native title attribute on the badge | badgeHoverShowsTippy false, badgeHasNativeTitle null -- confirmed via direct twig read, no title attribute anywhere near the badge/icon pair | PASS, no double-tooltip |
| 7 | Visual sanity (desktop 1280px and mobile 360px) | Info-icon placement natural, no grid breakage | Screenshots confirm icon sits as a small inline sibling immediately after each badge/stat; no crowding, no wrapping breakage at 360px | PASS |

### Stream cards - /stream (anonymous)

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 8 | Load /stream | at least 1 .gc-stream-card | 10 cards | PASS |
| 9 | Hover byline info-icon | card.stream.byline copy | Tooltip text: Who posted this and which group it appears in. Click the person to see their profile; click a group to visit it. | PASS |
| 10 | Hover type-badge info-icon | card.stream.type copy | Tooltip text: The kind of post -- Forum (threaded discussion), Documentation (durable reference), Event (something at a set time), Post (quick update), or Page (standalone info). | PASS |
| 11 | Hover comments-footer info-icon | card.stream.comments copy; icon adjacent, not merged into the comments link own aria-label | Tooltip text: How many replies this post has. Click to open the post and read the discussion. The comments link retained its own aria-label of 0 comments, distinct from the icon; confirmed trigger is NOT nested inside the anchor (triggerIsNestedInsideCommentsLink false) | PASS |
| 12 | Keyboard tab and focus on stream card info-icon | Focus plus visible outline | Same outline treatment as directory cards (2px solid, rgb(0,103,184)), tippy visible on focus | PASS |

### A11y independent check

| # | Check | Result |
|---|---|---|
| 13 | aria-label non-empty on all 6 element types | Confirmed non-empty and distinct per element; type/visibility/members/byline/type/comments each carry their own full-sentence copy, never a generic info label |
| 14 | ariaSnapshot on one directory card and one stream card | Each info icon surfaces as a distinct note node with the full copy as its accessible name -- never truncated to a bare icon glyph or empty; full snapshot text captured in raw JSON evidence |
| 15 | Contrast, getComputedStyle on an info-icon trigger, walked up to nearest opaque ancestor background | color rgb(0,103,184) on background rgb(255,255,255). Computed WCAG contrast ratio approx 5.78 to 1 -- passes AA (4.5 to 1) with margin. Close to prior 5.36 to 1 baseline from story 122; both comfortably clear the bar. |

### Regression sanity

| # | Check | Result |
|---|---|---|
| 16 | /showcase | 200 OK. This page is the comparison and tour catalog, not a rendering of directory or stream card templates (0 .gc-directory-card and .gc-stream-card present, by design; confirmed via template grep, no card template is included on this route). Two unrelated page-level info-icon triggers present (view-switcher help, story 126 page-level mechanism) render fine, no console errors. Not a regression -- the brief scope was strictly the two card templates, and /showcase does not render them. |
| 17 | Authenticated persona, Elena Garcia member, on /all-groups and /stream | Session confirmed live (cookie persists, persona-switcher banner text present, no Log In text in body once truly authenticated -- verified in a dedicated cookie-tracking check). Cards render identically with tooltips intact: 2 triggers on the type-less first-sorted directory card, 3 on the first stream card. Hover-while-authenticated confirmed working. |

### Console and network

| # | Check | Result |
|---|---|---|
| 18 | Console errors during all hover and focus interactions across both surfaces, both personas | Zero console.error or pageerror events captured across the entire walkthrough |
| 19 | do_chrome tooltips library actually loads | window.Drupal.behaviors.doChromeTooltips present and registered (confirmed via page.evaluate); aggregated CSS and JS asset requests present in the network log (Drupal asset aggregation masks the individual tooltips.js filename, but the behavior key confirms it initialized) |

## Independent assessment of diff-gate W-3 (role=note)

W-3 flagged that role=note is passive and screen readers may not announce it as interactive. Judging this from the live-browser, user-visible angle (U remit, not S formal WCAG audit):

- The element is independently reachable by keyboard (tabindex=0 confirmed via real focus() plus document.activeElement), carries a full-sentence aria-label, and a visible focus ring -- so a keyboard user can find and read it even if some assistive tech does not announce note as an affordance hint.
- role=note is honest about what the element is, a passive annotation, not a button that performs an action -- it does not misrepresent behavior. Unlike a real interactive control, focusing it does not navigate or submit; the tippy tooltip is supplementary, not the element entire purpose being hidden.
- This matches the pattern already shipped and presumably already S-approved in stories 89, 122, and 126 (same DOM shape) -- not a new risk introduced by this story.
- Verdict from U chair: not a UI-behavior blocker. The element is discoverable, focusable, and its content is announced with a real name -- the semantic-correctness question, whether role=note versus role=button versus aria-describedby is the most correct pattern, is squarely S WCAG audit call, not a behavioral defect. Flagging forward to S per the diff-gate own routing, not re-litigating here.

## Findings

None blocking. No behavioral defects found across either card surface, either viewport, or either persona.

Soft observations, non-blocking:
- /showcase naturally has no directory or stream cards to check -- confirmed by design, not a gap.
- The W-3 ARIA-semantics question is real but is S call, not a UI-behavior regression; flagged above for S attention rather than re-investigated here.

## Evidence

Screenshots and JSON evidence bundles were captured to a session-scratch directory and are not part of the repo (ephemeral, per U remit -- not committed). Key visual confirmations:
- Directory card, type info-icon hover: tippy box shows full sentence, positioned above the trigger, no card-grid disruption.
- Directory card, keyboard-focus state: 2px solid blue outline clearly visible around the focused info icon, tippy also visible (tippy default focus trigger).
- Stream card, byline info-icon hover and comments-footer info-icon hover: both show correct copy; comments link own 0-comments accessible name unaffected.
- Mobile 360px /all-groups: all info icons wrap cleanly next to their badges and stats, no overlap, no clipped tooltip triggers.
- Authenticated Elena Garcia member /stream: renders identically to anonymous, tooltips intact.

## Verdict

PASS -- ready for S.

All acceptance criteria from the brief are behaviorally confirmed live: correct tooltip copy on hover for all 6 elements across both card surfaces, no double-tooltip, single-sourced visibility reuse (byte-identical to visibility.open), full keyboard reachability with a visible focus indicator, non-empty and distinct accessible names, AA-passing contrast (approximately 5.78 to 1), zero console errors, confirmed library load, and no regression on /showcase or the authenticated member view.
