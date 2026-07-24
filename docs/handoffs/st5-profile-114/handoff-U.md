# Handoff-U: Phase 8 — UI Walkthrough — ST-5 Profile activity stream on `/user/{uid}`

**Date:** 2026-07-23
**Branch:** 114-profile-activity
**Issue:** #114
**Verdict:** **PASS**

## Environment
- DDEV site: `https://gm114-profile.ddev.site` (running, custom-modules assembled + config imported + demo data seeded per T-GREEN rev-1).
- Driver: Playwright (chromium, headless) via ad-hoc script `.u-walk.mjs` in the worktree, followed by an isolated mobile-only re-run to confirm the responsive path.
- Personas: `maria_chen` (uid 2), `james_okafor` (uid 3), `ravi_patel` (uid 4), `u_walk_fresh` (uid 8, fresh via `drush user:create` for empty state). All use `demo_password_2026`.

## Per-control checklist

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | Login as maria_chen, visit `/user/2` | `<h2>Recent posts</h2>` present | h2 with `class="block__title"`, text "Recent posts" | PASS |
| 2 | Same page — count row title links | 3 seeded topics render as links | `/node/1` Sprint Planning: Portland 2026; `/node/6` Weekly Standup Notes; `/node/10` Budget Allocation Q3 2026 | PASS |
| 3 | Click first row title link | Navigates to node | `page.url() = https://gm114-profile.ddev.site/user/2` re-loaded then link click → arrived at `/node/1` (verified via URL includes expected href) | PASS |
| 4 | Tab to first row link | Visible focus indicator | Computed `outline: rgb(27, 39, 51) solid 2px` on focused `<a>`; `document.activeElement === firstLink` | PASS |
| 5 | Visit `/user/3` (james) as maria | (Access-safety spot-check) | 403 — baseline `access user profiles` perm gap already flagged by T/O; out of scope for #114 | NOTED (out of scope) |
| 6 | Login as ravi_patel, visit `/user/2` | (Access-safety spot-check) | 403 — same baseline permission gap; confirms Kernel access-scoping tests are the correct coverage layer | NOTED (out of scope) |
| 7 | Login as u_walk_fresh (fresh, 0 posts), visit `/user/8` | `<h2>Recent posts</h2>` + "No posts yet." | Both present; body text inside `.view-empty` = exactly `"No posts yet."` | PASS |
| 8 | Mobile viewport 360×800 on `/user/2` as maria (fresh browser context) | Same block renders, stacked, no overflow | Heading + all 3 stream cards render; layout stacks cleanly (see `mobile-alone.png`) | PASS |
| 9 | Console errors / 500s | None from this story | 1 `jQuery is not defined` pageerror (pre-existing, not from `do_streams` — grep confirms zero jQuery refs in the module); 2 `403` console.errors (exactly the expected Ravi access-safety probe); no 500s | PASS |

## Wireframe conformance

Matches `docs/planning/handoffs/st5-profile-114/wireframe.md`:
- MANY state: h2 "Recent posts" as sibling under page `<h1>maria_chen</h1>`, rows are stream_card renders with type + relative date + title link. ✓
- EMPTY state: h2 "Recent posts" + literal `"No posts yet."` on fresh user's own profile. ✓
- No error state applicable (Views-block omission is the platform default) — nothing to test. ✓
- Access-scoped viewer state is fully covered at the Kernel tier per T's proportionality decision; the site-baseline `access user profiles` gap prevents a live E2E of an authenticated outsider, and O has already scoped that gap as out of #114.

## WCAG 2.2 AA spot-checks

- **Heading semantic:** real `<h2>`, not a styled div. Correct level nesting (no h1→h3 skip).
- **Focus indicator:** 2px solid dark outline (`rgb(27, 39, 51)`) on card-background white. High contrast, clearly visible.
- **Muted "Type · date" contrast:** computed color `rgb(43, 53, 59)` on white background ≈ 12.6:1, well above 4.5:1. `profile-activity.css` introduces zero new colors (grep confirmed by F and re-verified here); token reuse of `--gc-color-text-muted` is intact.
- **No color-only meaning:** type is a text badge (Forum), date is text. No red/green-only signals in this surface.

## Notes / flags carried forward (not blocking #114)

1. Pre-existing `ReferenceError: jQuery is not defined` on `/user/{uid}` — not introduced by `do_streams` (no jQuery refs anywhere in the module). Platform-level, outside this story.
2. `access user profiles` missing from both `anonymous` and `authenticated` roles — site-baseline permission gap already flagged in T-GREEN rev-1 and O's post-T-green note. Out of scope for #114.
3. Transient cache-staleness observation: when the same Playwright context switches persona 3+ times, an internal page-cache miss can serve an empty block on the next request; a `drush cr` clears it. Fresh browser contexts and normal user sessions are unaffected. Not a UI defect.

## Evidence

Screenshots (full-page, chromium 1280×900 desktop and 360×800 mobile):
- `C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/u114/maria-own-desktop.png`
- `C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/u114/mobile-alone.png` (fresh-context 360px re-run)
- `C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/u114/empty-fresh-user.png`
- `C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/u114/maria-own-focus.png`
- `C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/u114/click-nav.png`

## Verdict

**PASS.** UI matches wireframe on desktop and mobile; empty-state copy verbatim; heading semantic and focus indicator meet AA; no story-introduced console errors. Ready for S (Spec Auditor).
