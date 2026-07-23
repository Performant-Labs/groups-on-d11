# Handoff-U: Phase 8 - #120 SC-1 Persona Switcher (UI Walkthrough)

Date: 2026-07-23
Branch: 120-persona-switcher
Issue: #120
Environment: DDEV gm120-groups-on-d11 - http://gm120-groups-on-d11.ddev.site
Driver: raw Playwright (chromium.launch), scripted throwaway walkthrough files
(.u-walk*.mjs, deleted after use) plus T's own committed tests/e2e/persona-switcher.spec.ts
re-run for cross-check.

## Pre-flight note (environment, not a code defect)

On first drive, EVERY page hit a 500 (ArgumentCountError: Too few arguments to function
DoShowcaseHooks::__construct(), 1 passed ... exactly 2 expected). Diffed the served
web/modules/custom/do_showcase/do_showcase.services.yml against the source under docs/groups/ -
byte-identical, correctly wired with F's Phase-6.5 second constructor arg
(do_showcase.showcase_catalog) and both class-name service aliases. Root cause: a stale compiled
service container on the long-running gm120-groups-on-d11 DDEV instance, predating F's Phase-6.5
diff-gate repair. Fixed with 'ddev drush cache:rebuild -y' (not a source change). Flagging so O/S
know the container was already this old at U's start - not something U introduced.

## Checklist results

### Anonymous state
| # | Item | Result | Evidence |
|---|---|---|---|
| 1-2 | Front page loads; "Browse as" dropdown visible in header | PASS | select[name=persona] count=1, visible |
| 3 | label[for=persona-switcher-select] "Browse as" + 4 options (Anonymous/Elena/Maria/Groups-Moderate) | PASS | 4 options in order: anonymous/elena-garcia/maria-chen/moderator, visible text "Anonymous" / "Elena Garcia - Member" / "Maria Chen - Organizer" / "Groups-Moderate" |
| 4 | Each option has non-empty title attribute | PASS | all 4 title attrs present, match wireframe per-option copy verbatim |
| 5 | Real button type=submit (not input type=submit) | PASS | form button[type=submit] count=3 (persona-switcher Go + 2 unrelated search forms); form input[type=submit] count=0 |
| 6 | No persistent banner in DOM when anonymous | PASS | aside[role=status].do-showcase-persona-banner count=0 |
| 7 | Wrapper tooltip trigger present, combined copy | PASS | [data-do-tooltip] count=1; value is combined 4-persona sentence from HelpText/wireframe section 4 |

### Maria Chen (Organizer) full flow
| # | Item | Result | Evidence |
|---|---|---|---|
| 8-9 | Select Maria, auto-submit, land back on same URL | PASS | POST /persona-switch/maria-chen -> 302 -> lands on / (referring page); JS auto-submit fired, no manual Go click needed |
| 10 | Banner aside[role=status][aria-label=Active persona], exact copy, real a switch-back | PASS | banner text: "You're browsing as Maria Chen - Organizer - switch back"; a[href=/persona-switch/anonymous] present |
| 11 | Maria sees Edit/Manage-members affordance on her Organizer group ("DrupalCon Portland 2026", group id 1) a plain member would not | PASS | /group/1 (200) shows local-task tabs "View / Edit / Manage members" plus authenticated nav ("My account" / "Log out") |
| 12 | Keyboard: Tab to switch-back link, Enter restores anonymous | PASS | after .focus() + Enter, banner count=0, select value resets to anonymous |

### Groups-Moderate full flow (the critical bug-history one)
| # | Item | Result | Evidence |
|---|---|---|---|
| 13 | Select Groups-Moderate, auto-submits | PASS | |
| 14 | Banner text EXACTLY "You're browsing as Groups-Moderate - switch back" (not "Moderator") | PASS | confirms F's Phase-5 label-field fix holds |
| 15 | /group/1/members shows pending queue | PASS | 200 status; page text matches pending |
| 16 | /admin/config, /admin/people, /admin/modules all 403 | PASS | all three returned 403 |
| 17 | Switch back to Anonymous via banner link | PASS | banner count=0, select resets to anonymous |

### Elena Garcia (plain Member) flow
| # | Item | Result | Evidence |
|---|---|---|---|
| 18 | Banner "You're browsing as Elena Garcia - Member - switch back" | PASS | exact match |
| 19 | Elena does NOT see Edit/Manage-members on group 1 (Maria's Organizer group) | PASS | link list on /group/1 as Elena contains only "Home / Leave group" (no Edit/Manage members) |
| 20 | Switch back to Anonymous | PASS | banner count=0 |

### uid 1 guard / route method enforcement
| # | Item | Result | Evidence |
|---|---|---|---|
| 21 | Direct POST /persona-switch/admin (uid 1 real uname on this install) | PASS - 403 | curl -X POST -> 403 |
| 22 | POST /persona-switch/nonexistent | PASS - 403 | curl -X POST -> 403 |
| 23 | GET /persona-switch/maria-chen | PASS - 405 | curl -X GET -> 405 |
| 24 | GET /persona-switch/anonymous | PASS - 302 | curl -X GET -> 302 |

### WCAG 2.2 AA spot-checks
| # | Item | Result | Evidence |
|---|---|---|---|
| 25 | Visible focus on select, Go button, switch-back link | PASS | computed style on focus: select -> outline 2px solid rgb(77, 163, 255) (matches #4da3ff design token); Go button -> outline-width 2px solid; switch-back link -> outline-width 2px solid |
| 26 | SR semantics: label association, role=status, non-color glyph | PASS | label[for=persona-switcher-select] present, select id matches; banner is aside[role=status] with leading glyph (decorative, text carries meaning) |
| 27 | Contrast (eyeball) | PASS (no concerns) | Banner reuses ribbon dark/light pairing per wireframe (already AA-checked); no readability issues observed |
| 28 | Keyboard-only full switch flow | PASS | Confirmed via T's own E2E keyboard-only test (re-run below, PASS) and independently via my own scripted keyboard-only pass |

### Console + network
| # | Item | Result | Evidence |
|---|---|---|---|
| 29 | Console errors across full walkthrough | PASS - zero JS errors | zero entries collected across the full multi-persona round trip (post cache-rebuild) |
| 30 | Network 500s on persona-switch or any visited route | PASS - zero 500s | filtered for status>=500 across full round trip: empty |

## Cross-check against T's own E2E suite

Re-ran tests/e2e/persona-switcher.spec.ts against the correct base URL (env default points at a
different DDEV project, groups-on-d11-build.ddev.site:8493 - must override):

    BASE_URL=http://gm120-groups-on-d11.ddev.site npx playwright test tests/e2e/persona-switcher.spec.ts --reporter=list

Result: 4/4 PASS (Groups-Moderate full-switch 2.7s, Maria Chen full-switch 2.0s, keyboard-only
1.4s, visible-focus 1.1s). Matches T's Phase 6-followup report exactly; confirms my independent
manual walkthrough findings are consistent with the authored suite, not just my own script's
interpretation.

## SPA-nav rule note

This story is standard Drupal full-page navigation (no HTMX/SPA swap region), so the "reach every
page via SPA nav, not hard reload" rule from the U contract does not have a distinct swap-path vs.
hard-reload path to compare - confirmed via an explicit hard-reload spot check on the front page
after a full persona round-trip: select still present, banner correctly absent post-switch-back.
No discrepancy.

## Findings

None are code defects. One environment-only finding, already resolved during this walkthrough
(see Pre-flight note): the gm120-groups-on-d11 DDEV container's compiled service container was
stale relative to F's Phase-6.5 diff-gate fix; a drush cache-rebuild resolved it. No source file
was touched. Recommend O/S note this for any other agent reusing this same long-running container.

## Verdict

**VERDICT: PASS**

All 30 checklist items PASS. Banner copy for all three personas matches the wireframe/AC exactly
(including the previously-buggy Groups-Moderate case, confirmed fixed). uid-1 guard, allowlist
denial, and HTTP-method enforcement (403/403/405/302) all behave exactly per Amendment 4. Negative
scope test (Groups-Moderate blocked from /admin/config, /admin/people, /admin/modules) holds.
Positive/negative organizer-vs-member capability split (Maria sees Edit/Manage members on her group;
Elena does not) holds. WCAG spot-checks (label association, keyboard operability, visible focus
2px solid #4da3ff, non-color status glyph, role=status) all pass. Zero console errors, zero
500s across the full multi-persona round trip. T's own committed E2E suite independently
corroborates: 4/4 PASS.

Ready for S (Spec Auditor).
