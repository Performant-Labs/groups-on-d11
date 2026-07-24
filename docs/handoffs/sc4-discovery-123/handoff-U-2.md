# Handoff-U-2: Phase 11 UI re-walkthrough - #123 SC-4 Discovery three ways

Date: 2026-07-23
Branch: 123-discovery-three-ways
Backend: Playwright (playwright chromium, headless) driven from a throwaway node script against the live DDEV site.
Re-verifies: F's fix1 (Phase 9) for U's blocking finding F-U-1 (discovery.ranking tab click/Enter flipped chrome only; no URL, no swap).

## Verdict

PASS. F-U-1 is genuinely fixed for a real user, verified live via mouse and keyboard. Zero regressions from Phase 8's 15 PASS items. Zero console/pageerror across the entire walk. The `directory.layout` switcher (SC-5) is non-regressed - Compact click still flips the wrapper attribute in place with no navigation and filters preserved. Route to S (Spec Auditor).

One observation (not a blocker, not a wireframe/brief requirement): on a bare `/showcase` revisit (no query) while sessionStorage holds a prior choice, the switcher chrome is restored to that choice but the server-rendered embed is Recent (default) - chrome and embed can disagree on this narrow path. See "Observation U-obs-1" below. Surfaced once for O/F awareness; per the "POC - no follow-ups for merged-story latent debt" convention I do NOT open a follow-up issue.

## Run environment

- Worktree: C:/Users/aange/Projects/_worktrees/groups-sc4-discovery-123
- DDEV project `gm123-discovery` persistently installed from U Phase 8 (not re-installed).
- URL: http://gm123-discovery.ddev.site (admin/admin).
- JS parity: diff docs/groups/modules/do_showcase/js/do_showcase.switcher.js web/modules/custom/do_showcase/js/do_showcase.switcher.js -> identical (source == served, no cache issue).
- Site up: curl -sk -o /dev/null -w '%{http_code}' http://gm123-discovery.ddev.site/showcase -> 200.
- Throwaway walk script at ./.u-walk2.mjs (deleted after run); JSON evidence bundle at scratchpad/u2/findings.json; screenshots in scratchpad/u2/.

## Independent Playwright re-run (not trusting T's counts)

`BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/discovery-compare.spec.ts --reporter=list` -> **11 passed (9.6s)**. Every one of the 11 tests green, including the two that were red at U Phase 8: test 2 (mouse-click swap + URL) and test 9 (keyboard Enter). Independently reproduced.

`BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/directory-toggle.spec.ts --reporter=list` -> **11 passed, 1 skipped (5.6s)**. Shape identical to Phase 6 baseline; the skipped test is the seed-conditional pager-page-2 one.

## Per-control checklist (this phase)

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | GET /showcase (fresh context, no sessionStorage) | Recent selected; embed = chronological | switcher = Recent aria-checked=true tabindex=0 bullet-glyph; embed leads with Governance Town Hall / Thunder Roadmap Discussion / Getting Started with Thunder (chronological Recent view) | PASS |
| 2 | Mouse click Hot | URL becomes ?discovery=hot; chrome flips to Hot; embed changes to Hot content | URL=/showcase?discovery=hot; Hot aria-checked=true; embed differs from Recent (includes Follow-content controls, "By sophie_mueller, 24 July 2026" byline shape) | PASS |
| 3 | Mouse click Promoted | URL becomes ?discovery=promoted; chrome flips; embed = Promoted view (both required seeded titles present) | URL=/showcase?discovery=promoted; chrome flipped; embed lists "Getting Started with Paragraphs" AND "Community Code of Conduct" (hasParagraphs=true, hasCoC=true); embed differs from Hot | PASS |
| 4 | Mouse click Recent (return path) | URL becomes ?discovery=recent | URL=/showcase?discovery=recent; chrome flipped back to Recent | PASS |
| 5 | Keyboard: authored WCAG smoke test (discovery-compare.spec.ts:202) | Focus into discovery switcher, ArrowRight/Down selects next, Enter navigates | Green in the authored spec re-run (test 9, 601ms) - Playwright walks the correct discovery switcher, not my ad-hoc "first radio on page" helper. My helper accidentally hit the top directory.layout switcher (Cards -> Compact -> ?variant=compact) instead of discovery.ranking, so the authored spec is the correct evidence source for the keyboard path. | PASS (via authored spec) |
| 6 | Focus ring on radio (WCAG 2.4.7 / criterion 8) | Distinct visible outline | Computed outline = rgb(77, 163, 255) solid 2px - byte-identical to Phase 8 measurement, unchanged by the JS-only fix | PASS |
| 7 | Non-color status indicator | Selected shows bullet glyph + aria-checked=true, not color alone | Confirmed for every selected state across the walk; on default /showcase: only Recent carries the bullet prefix and aria-checked=true | PASS |
| 8 | Roving tabindex after page reload (now that click causes real navigation) | Exactly one option with tabindex=0 (the selected one); others tabindex=-1 | Verified on default, after-hot, after-promoted, after-recent snapshots: each has exactly one tabindex=0 on the currently-checked option; every other option tabindex=-1 | PASS |
| 9 | Console errors / pageerrors across the entire walk | None | 0 errors across ~12 page loads (login, showcase, deep-links, all-groups, viewport switch, second-context showcase) | PASS |
| 10 | Wrapper tooltip on discovery switcher, count==1 | Existing invariant | Re-checked by authored spec test 7 (415ms, green) - not re-measured here | PASS (via authored spec) |
| 11 | Coexisting switchers on /showcase - both keys work | ?variant=X and ?discovery=Y set each independently | Authored spec test 8 (462ms, green); my walk also observed the directory.layout stub switcher intact on /showcase (Cards default, sessionStorage-restore works for it because CSS keys off the mirrored wrapper attribute) | PASS (via authored spec + walk) |
| 12 | /all-groups directory.layout - Compact click, no navigation, filters preserved | URL unchanged; data-do-directory-variant flips; search=Book retained | Before: variant=compact (sessionStorage restore from earlier in the walk), URL=/all-groups?search=Book. After Compact click: URL unchanged (urlUnchanged: true), variant stays "compact", searchPreserved: true. Confirms F's usesMirrorModel() discriminator correctly routes directory.layout down the preventDefault path. | PASS |
| 13 | New browser context (fresh sessionStorage) on /showcase | Server default (Recent) selected; empty sessionStorage | Second Playwright context: Recent aria-checked=true, sessionStorage={} - server default served correctly on a truly cold page load. Confirms no cross-context leakage. | PASS |
| 14 | 360px mobile viewport spot check | Section stacked, switcher pills wrap cleanly, no overflow | K-360.png confirms - layout identical in shape to Phase 8's showcase-360.png | PASS |
| 15 | Deep-link ?discovery=hot sets Hot chrome | Existing invariant | H0 snapshot: URL=/showcase?discovery=hot resolved cleanly, sessionStorage receives doShowcase.variant.discovery.ranking=hot for later use. | PASS |

## Observation U-obs-1 (non-blocking, informational)

**Path:** bare /showcase revisit after having navigated to /showcase?discovery=promoted (or hot/recent) earlier in the same session.

**Observed:** switcher chrome shows bullet-Promoted, aria-checked=true, tabindex=0 (restored from sessionStorage doShowcase.variant.discovery.ranking=promoted). The embed however is the SERVER-RENDERED default (Recent) - chrome and embed disagree.

**Why:** do_showcase.switcher.js lines 354-366 restore a persisted choice on attach when the URL does not carry this group's own query key. For the mirror-driven directory.layout instance this works correctly because CSS keys off the mirrored data-do-directory-variant wrapper attribute - restoring the switcher also swaps the visible variant. For the navigation-driven discovery.ranking instance there is no mirror and no client-side swap mechanism, so restoring the switcher chrome does not update the server-rendered embed underneath it.

**Same failure class as F-U-1:** "chrome says X, content shows Y." But triggered on a much narrower path (bare page reload while sessionStorage holds a prior choice) than the primary click/keyboard interaction F-U-1 was about. The primary interactive path (click Hot -> URL becomes ?discovery=hot -> embed updates) is fully fixed and IS the path the wireframe + brief actually mandate.

**Not in the brief or wireframe:** the persistence contract is a self-imposed feature of the shared switcher library, inherited for parity with directory.layout - no acceptance criterion of #123 SC-4 requires session persistence for discovery.ranking. The docblock (lines 10-19) states "remembers choice per session" - which is honored for the switcher chrome; whether it should ALSO trigger a re-navigation to keep the embed in sync (or conversely SUPPRESS the restore when there is no mirror wiring, matching the fact that a click-through would have set the URL param) is a product/design judgment call.

**Not blocking:** per project memory feedback_poc_no_follow_ups.md I surface this once here and do not file a GH issue. If S judges this a wireframe deviation I have not spotted, U will re-open. Two plausible fix shapes if it is ever pursued:
1. In the on-attach restore block, skip select(stored, false) for navigation-driven instances (!isMirrorDriven) - chrome stays whatever the server rendered, which by definition matches the embed.
2. Or: for navigation-driven instances, if stored differs from the server's selected option AND !params.has(queryKey), window.location.assign(matchingOption.href) - re-navigate to the persisted URL so the embed matches. More aggressive; risks unexpected navigation on a bookmarked/typed URL.

## Findings

**None blocking.** F-U-1 is fixed. U-obs-1 documented above, not routed as REWORK.

## Non-findings (verified, unchanged from Phase 8)

- Promoted view lists all published nodes not just promote_homepage-flagged (pre-existing latent gap; A-plan Risk 3 mandates embed AS-IS; both required seeded titles present).
- Wireframe's per-tab meta styling (promoted badge, bullet-hot glyph on top row, created X ago) not visible because story reused existing views AS-IS.
- Site-wide invisible "Skip to main content" focus outline - pre-existing theme concern unrelated to this story.

## Constraints honored

- Read code, ran browser, wrote report only - no fixes to production or test files.
- Independent Playwright re-run (not trusting T's counts) plus a live eyes-on-glass browser walk with distinct evidence.
- Walk script deleted (rm -f .u-walk2.mjs) after run.

## Evidence

- Evidence bundle (full JSON): C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/u2/findings.json
- Screenshots (scratchpad/u2/):
  - A-default.png - bare /showcase, Recent selected + Recent embed
  - B-hot.png - after mouse-click Hot, URL=?discovery=hot, Hot embed
  - C-promoted.png - after mouse-click Promoted, URL=?discovery=promoted, seeded Paragraphs + Code of Conduct titles visible
  - E-kbd.png - keyboard scenario (my helper landed on the directory.layout switcher; discovery keyboard verified via authored spec)
  - J0-all-groups.png - /all-groups?search=Book before Compact click
  - J2-compact.png - after Compact click, URL unchanged, data-do-directory-variant=compact
  - K-360.png - 360px mobile viewport on /showcase

## Handoff to next role

**S (Spec Auditor).** U verifies the interactive UI now matches the wireframe click/keyboard contract and passes non-regression on the sibling directory switcher; S owns the wireframe-conformance verdict + WCAG axe pass + brief conformance sign-off. If S also PASSes, the story routes to commit/PR prep (or self-merge per feedback_uranus_wider_autonomy.md standing rule, if CI-green + mergeable).

Nothing to hand back to F.
