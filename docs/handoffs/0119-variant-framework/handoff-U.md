# Handoff-U: Phase 8 — UI Walkthrough (#119, SC-F1 variant framework)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Issue:** #119
**Worktree:** `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework`
**Docs reviewed first:** `wireframe.md`, `handoff-D.md`, `brief.md` (Brief-gate resolutions B-1..B-5),
`handoff-F4.md`, `handoff-T-green4.md`, `decisions.md`.

## Scope

Drove the LIVE UI in a real headless Chromium browser (Playwright) on the real navigation path for
all 3 `do_showcase` UI surfaces (variant switcher, `/showcase` tour page, POC ribbon), at desktop
(1280x900) and mobile (360x800) viewports, plus an axe-core (WCAG 2.x A/AA + 2.2 AA tag set)
accessibility pass on each surface. Headless only — no visible browser windows, no desktop
screenshots; evidence is a JSON results bundle plus file-based screenshots read via the Read tool.

## Environment (reproducible)

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
/opt/homebrew/opt/php@8.4/bin/php $(which composer) install --no-interaction --no-progress
./scripts/ci/assemble-config.sh
npm ci
npx playwright install chromium
npm install --no-save axe-core

docker run -d --name o119u1-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=drupal \
  -p 33081:3306 mysql:8.0 --default-authentication-plugin=mysql_native_password

php vendor/drush/drush/drush.php site:install standard \
  --db-url=mysql://root:root@127.0.0.1:33081/drupal --account-name=admin --account-pass=admin -y

# fix config_sync_directory (random path -> ../config/sync) in web/sites/default/settings.php
# fix system.site uuid to match config/sync/system.site.yml (c8ebc78c-7e58-4039-8f76-ef9990947333)
php -d memory_limit=512M vendor/drush/drush/drush.php cr
php -d memory_limit=512M vendor/drush/drush/drush.php config:set system.site uuid <uuid> -y
php -d memory_limit=512M vendor/drush/drush/drush.php config:import -y

cd web && PHP_CLI_SERVER_WORKERS=8 /opt/homebrew/opt/php@8.4/bin/php -d memory_limit=512M \
  -S 127.0.0.1:38191 .ht.router.php &

php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  docs/groups/scripts/step_700_demo_data.php --uri=http://127.0.0.1:38191

# admin one-time login link for the authenticated-ribbon check:
php -d memory_limit=512M vendor/drush/drush/drush.php uli --uri=http://127.0.0.1:38191
```

Authenticated via the `drush uli` one-time-login URL (pasted into a fresh Playwright browser context
before navigating to `/`). `BASE_URL=http://127.0.0.1:38191` for all navigation.

**Namespacing:** container `o119u1-mysql` on port 33081, server on port 38191 — distinct from all
prior T rounds' `o119t1`-`o119t4` namespaces, no conflicts observed.

**Recipe note carried forward from T-green4 (confirmed again this round):** must `cd web` before
`php -S ... .ht.router.php` — running from repo root breaks `index.php` path resolution. Also hit
and fixed this round: `web/sites/default` is written read-only (`dr-xr-xr-x`) by Drupal's installer;
teardown requires `chmod u+w web/sites/default` before removing `settings.php`/`files`. And
`config:import`/`drush cr` need `-d memory_limit=512M` — the default 128M CLI limit is exhausted
partway through this site's config import.

## Method

A single throwaway Node/Playwright script (`.u-walk.mjs`, removed after the run) launched Chromium
and, per surface, followed the **real navigated path** first (click the ribbon's link into
`/showcase`, click switcher options, Tab to the dismiss button) rather than only hard-reloading
target URLs — the anon and 360px checks reused the same navigated-path script. A hard-reload
spot-check of `/showcase?variant=compact` was separately confirmed via `curl` (server-rendered,
no-JS-safe path) and an independent `javaScriptEnabled:false` Playwright context. No discrepancy
found between the navigated path and a hard reload for any surface.

Full raw results: `/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o119-handoffs/u-walkthrough/results.json`
Screenshots: `showcase-desktop.png`, `showcase-mobile360.png`, `home-auth-desktop.png`,
`home-auth-mobile360.png` in the same directory.

## Surface 1 — Variant switcher

| Control | Action | Expected (wireframe.md L10-93) | Observed | Result |
|---|---|---|---|---|
| Wrapper | render | `role="radiogroup" aria-label="Viewing"` | `role="radiogroup"`, `aria-label="Viewing"` (confirmed both viewports) | PASS |
| Selected-state cue | inspect default render | `●` glyph + `aria-checked`, not color alone | "Cards" option renders text `"● Cards"`, `aria-checked="true"`; others `"false"` with plain label | PASS |
| Click "Compact list" | click | selection switches to Compact list, others deselect | `aria-checked` moved to `compact` (`true`), `cards`/`map` both `false` — confirmed both directions (compact→cards, cards→compact) | PASS |
| Click "Cards" (back) | click | selection returns to Cards | as above | PASS |
| Keyboard ArrowRight from selected | focus selected option, press ArrowRight | focus+selection move to next AVAILABLE option (skip disabled "Map"); roving tabindex rolls | From "Compact list" (selected) ArrowRight → "Cards" both focused+checked; roving tabindex count stayed exactly 1 throughout all 3 presses | PASS |
| Keyboard ArrowRight (2nd press, at "Cards") | press ArrowRight again | wraps back to "Compact list", skipping disabled "Map" | Observed: focus/selection moved to "compact" — confirms the unavailable "Map" option is correctly skipped by the roving-tabindex cycle (only 2 of 3 options are in the ArrowRight cycle) | PASS |
| Keyboard ArrowLeft | press ArrowLeft | reverse direction, same skip behavior | moved back to "Cards"→"Compact list" as expected reverse of the above | PASS |
| Roving tabindex | inspect after each move | exactly one `[role=radio][tabindex="0"]` at a time | `tabindexZeroCount: 1` after every keyboard move (3/3 checks) | PASS |
| Unavailable "Map (soon)" option | inspect + click attempt | present, `aria-disabled="true"`, label says "(soon)", not a dead click target with no explanation, click is a no-op | present; `aria-disabled="true"`; label text `"Map (soon)"`; forced click produced no `aria-checked` change (`unavailable_click_noop: true`) | PASS |
| Tooltip (ⓘ) hover | hover the ⓘ trigger | `do_chrome/tooltips` shows HelpText copy | tippy root (`[data-tippy-root]`) appeared on hover; `data-do-tooltip` copy = "Compact list favors scanning many groups fast; Cards shows more per-group detail; Map plots groups geographically." | PASS |
| Tooltip (ⓘ) focus | keyboard-focus the ⓘ trigger | same tooltip shows (keyboard-operable, dual-channel) | tippy root appeared on focus | PASS |
| No-JS `?variant=` fallback | `curl`/no-JS Playwright context `GET /showcase?variant=compact` | server-side selects `compact` without JS | Confirmed via raw `curl`: `<a href="?variant=compact" role="radio" aria-checked="true" tabindex="0" data-do-showcase-id="compact">`; options are plain `<a href="?variant=...">` links (real in-page navigation, no JS required); independently reconfirmed via a `javaScriptEnabled:false` Playwright context | PASS |

Console/page errors on `/showcase` (tour+switcher context): **zero**, both viewports.

## Surface 2 — `/showcase` tour page

Reached via **real navigation**: clicked the ribbon's "See what it compares →" link from `/` (not a
hard reload) — `showcase_url_after_click` confirmed `http://127.0.0.1:38191/showcase`.

| Check | Expected | Observed | Result |
|---|---|---|---|
| All 7 entries present | Discovery ranking, Directory presentation, Membership models, Group-type homepages, Stream model, Private-group reveal, Persona switcher | all 7 present (count 1 each), both viewports | PASS |
| Persona list names all four personas | Anonymous, Elena Garcia, Maria Chen, Moderator | all 4 present in the Persona-switcher entry's nested list | PASS |
| Status badges are non-color text | `[ live ]` / `[ coming ]` literal text | `live_badge_count: 2` (Discovery ranking, Persona switcher), `coming_badge_count: 5` (the other five) — text-based badge, confirmed by DOM text match, not color | PASS |
| Membership models stays `[coming]` | no dead link, "coming" badge | confirmed `[ coming ]` badge; raw-markup check confirms **zero `<a>` tags** anywhere inside the `membership-models` entry container | PASS |
| No dead links on any "coming" entry | zero `<a>` elements rendered for Directory presentation / Membership models / Group-type homepages / Stream model / Private-group reveal | raw-HTML grep of each entry's container: 0 `<a>` tags in all 5 | PASS |
| Live entry (Discovery ranking) deep-link | `<a href="/showcase">` with accessible name "View this comparison", scoped inside `[data-do-showcase-entry="discovery-ranking"]` | present; `href="/showcase"`; clicked it for real — navigated to `http://127.0.0.1:38191/showcase` (200, no error) | PASS |
| Live entry (Persona switcher) deep-link | also `[live]`, should also link | raw markup confirms `<a href="/showcase">View this comparison</a>` inside the persona-switcher entry too | PASS |

Axe pass (`/showcase`, `wcag2a`/`wcag2aa`/`wcag22aa` tags): **0 violations**, both viewports.

## Surface 3 — POC ribbon

| Control | Action | Expected | Observed | Result |
|---|---|---|---|---|
| Renders for anonymous | load `/` fresh | ribbon visible, text "This is a proof-of-concept demo." | visible, text matches exactly | PASS |
| Renders for authenticated | `drush uli` login, load `/` | identical copy/markup, no session-dependent branching | visible; text identical to anon (`ribbon_auth_text_matches_anon: true`) | PASS |
| Link to `/showcase` | inspect | real `<a href="/showcase">` | present, count 1 | PASS |
| Does not cover/reflow nav | inspect bounding boxes | ribbon does not overlap primary `<nav>`, nav position unaffected | ribbon `y:0..43`; `<nav>` `y:76.5..175.5` — no vertical overlap; `<header>` `y:0..181` (ribbon sits inside normal flow, not floating over content) | PASS |
| Dismiss button keyboard reachable | Tab from page load | reaches `<button aria-label="Dismiss demo banner">` | reached within 15 Tab presses on both viewports | PASS |
| Visible focus ring on dismiss | inspect computed style at focus | distinct visible outline | `outlineStyle: solid`, `outlineWidth: 2px` at focus | PASS |
| Dismiss via keyboard (Enter) | Enter while focused | ribbon removed from DOM | `ribbon_removed_after_dismiss: true` | PASS |
| Same-tab persistence | navigate to `/showcase` then back to `/` in the SAME context | ribbon stays dismissed | `ribbon_stays_dismissed_after_nav: true` | PASS |
| No server session cookie on anon dismiss | inspect cookies after dismiss | zero session cookie | `cookies_after_dismiss: []`, `has_session_cookie: false` — confirms Brief-gate B-2's client-side-only persistence holds live | PASS |
| Persistence mechanism | inspect storage after dismiss | `sessionStorage` used, not `localStorage` | `sessionStorage_keys_after_dismiss: ["doShowcase.ribbonDismissed"]`; `localStorage_keys_after_dismiss: []` | PASS |
| Fresh session reverts (not localStorage) | new unrelated context | ribbon reappears | Independently re-confirmed by T-green4's Case B (two independent `browser.newContext()` instances); this round's own storage-key inspection corroborates the mechanism (sessionStorage only) | PASS (corroborated, not re-run standalone this round — see note below) |

Axe pass (`/`, ribbon present, both anon and auth contexts structurally identical): **0 violations**,
both viewports.

**Note on fresh-session reversion:** T-green4 already live-verified this exact behavior with two
independent Playwright browser contexts (Case B, `handoff-T-green4.md` lines 166-191) immediately
prior to this round, on the identical committed code. This round's own results corroborate the
*mechanism* (confirmed `sessionStorage`-only, zero `localStorage` keys, zero session cookie) rather
than re-running the two-context reversion test a third time, since T's evidence is direct and recent
and the underlying code is unchanged since that verification. Not treated as an independent gap.

## Console / network findings

Zero console errors, zero page errors, across all pages visited (`/`, `/showcase`, authenticated
`/`), both viewports. No blocking overlay/dialog intercepted any viewport-center click at any point.

## Axe-core summary (all 3 surfaces x 2 viewports)

| Surface | Desktop violations | Mobile 360 violations |
|---|---|---|
| Switcher context (`/showcase`) | 0 | 0 |
| `/showcase` tour page | 0 | 0 |
| Ribbon (`/`) | 0 | 0 |

No WCAG 2.x A/AA or WCAG 2.2 AA violations detected by axe-core on any surface/viewport combination.
No advisory/cosmetic contrast findings surfaced either (low-fi demo CSS did not trip any axe rule in
this pass).

## Discrepancies between navigated path and hard reload

None found. The navigated-path check (click-through from `/` → `/showcase` via the ribbon link, and
click-through on the switcher's live DOM) and the hard-reload/no-JS check
(`curl`/`javaScriptEnabled:false` on `/showcase?variant=compact`) produced identical selection state
and DOM shape — no one-time-init-only-fires-on-first-load class of bug observed.

## Full regression re-check

Re-ran the full target-spec Playwright suite against this round's freshly stood-up live environment
(not just trusting T-green4's prior run): `npx playwright test tests/e2e/nav.spec.ts
tests/e2e/showcase.spec.ts --reporter=list` → **26/26 PASS**, 0 failed, 8.3s. Confirms `nav.spec.ts`
(Brief-gate W-5 non-regression) still green in this independently-provisioned instance.

## Teardown confirmation

```
$ docker rm -f o119u1-mysql
o119u1-mysql
$ docker ps -a --filter name=o119u1 --format '{{.Names}}'
(empty)
```
`php -S` server process killed (confirmed no process bound to 38191). Worktree filesystem restored:
`git checkout -- config/sync/ web/.htaccess web/example.gitignore web/index.php web/robots.txt
web/update.php`, `git clean -fd config/sync/ web/`, removed `web/core`, `vendor`, `node_modules`,
`test-results`, `playwright-report`, `web/autoload_runtime.php`; `chmod u+w web/sites/default` (it
is installer-locked read-only) then removed `web/sites/default/settings.php` and
`web/sites/default/files`. Throwaway scripts (`.u-walk.mjs`, `.u-walk-navcheck.mjs`,
`.u-walk-out/`) removed. `git status --short` after teardown shows only the two pre-existing
untracked files present at this round's start (`dual-review-brief.md.prompt.txt`,
`dual-review-diff.md.prompt.txt`) — untouched, no residual state, no mutation of the shared checkout
(all work confined to the isolated worktree).

## Verdict

**PASS**

All 3 `do_showcase` UI surfaces (variant switcher, `/showcase` tour page, POC ribbon) behave exactly
per the approved wireframe on the real navigated path, at both desktop and 360px viewports:
click-to-select, keyboard arrow navigation with correct skip-disabled + roving-tabindex semantics,
tooltip hover/focus, no-JS `?variant=` fallback, truthful `[live]`/`[coming]` badges with zero dead
links, working live deep-links, ribbon show/dismiss/persist for both anonymous and authenticated
visitors with confirmed client-side-only (sessionStorage, zero cookies) persistence, and no nav
DOM interference. Zero console/page errors observed anywhere. Axe-core found zero WCAG A/AA/2.2-AA
violations on any of the 3 surfaces at either viewport. Full 26/26 target-spec Playwright suite
re-confirmed green in this independently-provisioned live environment.

Routes to: **S** (Spec Auditor — visual/WCAG verdict).
