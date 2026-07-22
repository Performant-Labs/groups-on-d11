# Handoff-U: Phase 8 - Issue #138 (MC-7) UI Walkthrough

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Issue:** #138
**Verdict: REWORK**

## TL;DR

Route-path collision: **the new `do_group_membership.manage_members` route and a
pre-existing config `views.view.group_members` (`page_1` display) both claim the identical
path `/group/{group}/members`**, and Drupal's router resolves `view.group_members.page_1`,
never `do_group_membership.manage_members`, for every GET request on that path — including
the "Manage members" local-task-tab navigation itself. This means **the entire steady-state
Manage-members surface this story built (status badges, Approve/Deny/Unblock buttons,
per-row Role/Remove buttons, the last-Organizer disable-before-attempt guard, the
`do-group-membership__*` CSS) never renders in the live UI, for any user, on any path** —
users instead see an unrelated core Views listing with a generic "Roles"/"Operations"
column and a "View member" dropdown. The satellite routes (`/members/add`,
`/members/{id}/role`, `/members/{id}/remove`) do NOT collide and were verified working
correctly end to end, and the manager service's `approvePending()`/`denyPending()` work
correctly at the service layer (drush-verified) — so this is a pure UI-reachability defect
introduced by an un-caught route-path collision, not a broken manager service or bad
Form code. It fully blocks AC-7 (badges/actions), AC-9's discoverable guard, AC-10 (approve/
deny UI), and AC-15 (axe/keyboard verification of the intended surface) as currently shipped.

## Run environment (reproducible)

- **Serve:** raw `drush site:install` + `config:import` + `drush en` + `drush runserver`
  (mirrors `.github/workflows/test.yml`'s `e2e` job exactly, NOT DDEV — DDEV project
  `pl-groups-on-d11` exists for this worktree but was left untouched per hygiene rules; a
  fresh install was faster and self-contained).
  ```
  cd /Users/andreangelantoni/Projects/_worktrees/groups-0138-mc7-manage-members
  bash scripts/ci/assemble-config.sh
  docker run -d --name gm138-mysql -e MYSQL_DATABASE=drupal -e MYSQL_ROOT_PASSWORD=root -p 13306:3306 mysql:8
  php -d memory_limit=-1 vendor/drush/drush/drush.php site:install standard \
    --db-url="mysql://root:root@127.0.0.1:13306/drupal" --account-name=admin --account-pass=admin -y
  php -d memory_limit=-1 vendor/drush/drush/drush.php config:set system.site uuid "<config/sync uuid>" -y
  echo "\$settings['config_sync_directory'] = '../config/sync';" >> web/sites/default/settings.php
  php -d memory_limit=-1 vendor/drush/drush/drush.php config:import -y
  php -d memory_limit=-1 vendor/drush/drush/drush.php en -y do_group_membership do_tests do_group_extras ...
  # seed demo data (docs/groups/scripts/step_700_demo_data.php, step_720, step_780) as uid 1
  php -d memory_limit=-1 vendor/drush/drush/drush.php --root="$PWD/web" runserver --no-browser 127.0.0.1:8080
  ```
- **URL:** `http://127.0.0.1:8080` (group under test: group id 1, "DrupalCon Portland 2026",
  8 seeded members before I added test personas).
- **Auth:** created 6 dedicated test-persona users via `drush php:eval`
  (`u_organizer` — added to group 1 as `community_group-organizer`; `u_plainmember` — added as
  `community_group-member`, no admin permission; `u_groupsmoderate` — site role
  `groups_moderate`, deliberately never added to group 1; `u_addtarget`,
  `u_pendingapprove`/`u_pendingdeny` — seeded with `field_membership_status = pending` via
  direct entity API for the approve/deny walkthrough; 40× `u_pag_N` to push group 1 to 55
  total memberships for pagination) and logged in via the standard `/user/login` form
  (username/password), not `drush uli`, since scripted repeated logins across personas were
  simpler with a fixed password.
- **Browser:** Playwright (Chromium), fully headless, ad-hoc `npm install --no-save
  playwright @axe-core/playwright` in a scratch dir (no repo `package.json` change). Script:
  `.u-walk.mjs` (throwaway, deleted after the run per instructions).
- **Docker hygiene:** created only `gm138-mysql`; removed only `gm138-mysql` on teardown.
  `docker ps -a` confirmed before/after: `o119t3-mysql` and every `ddev-*` container
  untouched.

## THE FINDING (blocks the story)

**Route-path collision at `/group/{group}/members`.**

```
$ drush php:eval '$r = Drupal::service("router.route_provider")->getRoutesByPattern("/group/1/members");
  foreach ($r->all() as $name => $route) { echo $name . " => " . $route->getPath() . "\n"; }'
do_group_membership.manage_members => /group/{group}/members
view.group_members.page_1 => /group/{group}/members
```

```
$ drush php:eval '$router = Drupal::service("router.no_access_checks");
  $req = Symfony\Component\HttpFoundation\Request::create("/group/1/members");
  echo Drupal::service("router.no_access_checks")->matchRequest($req)["_route"];'
view.group_members.page_1
```

`config/sync/views.view.group_members.yml` (path `group/%group/members`, tab "Members",
weight 20) is a **pre-existing baseline config** — `git log` confirms it has been present
since the repo's initial commit (`7bcb6d9`) and is untouched by this story's diff
(`git diff origin/main...HEAD -- config/sync/views.view.group_members.yml` is empty). The
brief's own §B-2 locked the path `/group/{group}/members` without checking for this
collision; neither F's implementation nor A's anti-duplication review (Phase 7) caught it —
A's review checked for logic/pattern duplication, not a literal route-path collision with a
pre-existing config entity, which is a different class of check.

**Impact — confirmed live, not inferred:**

- Navigating to the group canonical page and clicking the "Manage members" local task
  (real navigated path, not a direct URL) lands on `view.group_members.page_1`'s rendered
  output: a `views-table` with columns User/Roles/Updated/Joined/Operations, a "View member"
  dropdown per row — **no Status column, no badges, no Approve/Deny/Unblock, no Role/Remove
  buttons, no last-Organizer guard note.** Confirmed via `grep -c 'do-group-membership'
  <rendered HTML>` = 0, and no `<form>` element on the page at all (the Views page is a
  controller render, not `ManageMembersForm`).
- A hard reload of `/group/1/members` produces the identical Views output (no
  navigated-vs-reload discrepancy — the collision is deterministic, not a one-time-init bug).
- Every redirect this story's own Forms issue back to `do_group_membership.manage_members`
  (successful add-member, role-change, remove, and — would-be — approve/deny) lands the user
  back on the SAME shadowed Views page, so even the success message ("u_addtarget has been
  added to this group.") is shown immediately above the wrong table.
- The two path-distinct satellite routes do **not** collide (the View's page display only
  claims the bare `/members` path) and were verified working correctly end to end when
  reached directly:
  - `GET /group/1/members/add` → F's real `AddMemberForm` (title "Add member", user
    autocomplete, Member/Moderator/Organizer checkboxes, Member pre-checked) — confirmed
    correct rendering (screenshot `evidence-U/16-real-add-member-form-direct.png`) and a
    real submit with real autocomplete selection succeeded: "u_addtarget has been added to
    this group." (screenshot `18-real-add-member-result.png`).
  - `GET /group/1/members/{id}/role` → F's real `ChangeRoleForm` — checked Moderator, saved,
    got "The role has been updated." (screenshot `19-change-role-direct.png`).
  - `GET /group/1/members/{id}/remove` → F's real `RemoveMemberForm`, a genuine `ConfirmFormBase`
    step: "Are you sure you want to remove u_plainmember from DrupalCon Portland 2026? This
    deletes their membership...", confirmed NOT instant-fire; confirming produced "u_plainmember
    has been removed from this group." (screenshots `20`, `21`).
  - Last-Organizer guard **server-side backstop** verified working: attempting to remove the
    group's sole Organizer via the direct remove route produced "A group must have at least
    one Organizer." — the relationship was NOT deleted (screenshot `22`). This is only the
    backstop; the wireframe's Screen-6 disable-before-attempt UI treatment is unreachable
    (it only renders inside the shadowed `ManageMembersForm`).
  - `GroupMembershipManager::approvePending()` verified working at the service layer via
    `drush php:eval` (pending → active transition confirmed on a real relationship) — the
    approve/deny UI itself is unreachable (Approve/Deny/Unblock are `#submit` callbacks on
    `ManageMembersForm` itself, which never renders, since they have no standalone route).
- Reading the actual `ManageMembersForm.php` source confirms the intended markup is
  correctly authored (badge = `<span aria-hidden="true">glyph</span> Label` +
  `data-state="…"` modifier class; disabled guard buttons carry
  `aria-describedby="do-group-membership-guard-{id}"`) — this is a **reachability** defect,
  not a markup/implementation defect in the shadowed code itself.

**AC impact:**
- **AC-7** (table/badges/actions on the actual `/group/{group}/members` page): FAIL as
  shipped — the intended page never renders there.
- **AC-9** (last-Organizer guard): server-side backstop PASS; disable-before-attempt UI FAIL
  (unreachable).
- **AC-10** (approve/deny): service-layer PASS (drush-verified); UI FAIL (unreachable).
- **AC-6** (route/access): the access gate itself is correctly implemented and independently
  provable (see AC-11/AC-12 below used the SAME `_custom_access` callback via the satellite
  routes, which are unaffected by the collision) — but the primary `manage_members` route's
  OWN access callback is moot in practice since GET requests never reach it.
- **AC-8, AC-14** (add-member validation/E2E): PASS — verified live via the working satellite
  route.
- **AC-11, AC-12** (access control): PASS — see below (verified against the satellite/shadow
  combination; the `_custom_access` logic itself is proven correct independent of the
  collision).
- **AC-15**: axe-clean on the two reachable satellite forms (see below); the steady-state
  table + last-Organizer disabled-state axe pass **could not be performed against the real
  route** (it never renders) — only a static source-code read of the badge/guard markup,
  which matches the wireframe's non-color-alone + `aria-describedby` requirements.

## Per-control checklist

| # | Control | Action | Expected | Observed | Verdict |
|---|---|---|---|---|---|
| 1 | Login | log in as u_organizer | redirect to /user/N | `/user/8?check_logged_in=1` | PASS |
| 2 | "Manage members" tab | navigate group/1 canonical, click tab | tab visible, navigates | tab visible + navigated to `/group/1/members` | PASS (tab renders; destination is the collision) |
| 3 | Table on navigated page | inspect markup | real `<table>` w/ `<th scope=col>` | present — **but is `view.group_members.page_1`'s table, not `ManageMembersForm`'s** | FAIL (wrong surface) |
| 4 | Status badge | inspect `[data-state=active]` | visible "Active" text | **absent — no Status column at all on the served page** | FAIL |
| 5 | Joined/Requested label | inspect body text | "Joined:"/"Requested:" labels | absent (View shows generic "Updated"/"Joined" columns, not the module's labels) | FAIL |
| 6 | Add member (navigated) | click "Add member" | opens F's `AddMemberForm` | opens core Group's **generic "Add existing content"/"Add Group membership"** form (`/group/1/content/add/group_membership`) — a different local-actions-block link entirely, not this story's route | FAIL |
| 7 | Add member (direct route) | `GET /group/1/members/add` | F's `AddMemberForm` renders | confirmed correct: title "Add member", autocomplete, Member pre-checked (screenshot 16) | PASS |
| 8 | Add-member submit | fill autocomplete, submit | success message, relationship created | "u_addtarget has been added to this group." (screenshot 18) | PASS |
| 9 | Change role (direct route) | `GET .../role`, check Moderator, Save | success, role updated | "The role has been updated." | PASS |
| 10 | Remove (direct route) | `GET .../remove` | ConfirmFormBase step, not instant-fire | "Are you sure you want to remove X..." shown, no deletion until confirmed (screenshot 20) | PASS |
| 11 | Remove confirm | click "Remove member" | success, relationship deleted | "u_plainmember has been removed from this group." (screenshot 21) | PASS |
| 12 | Last-Organizer guard (server backstop) | attempt remove on sole Organizer | blocked, error message, no deletion | "A group must have at least one Organizer." relationship intact (screenshot 22) | PASS (backstop only) |
| 13 | Last-Organizer guard (disable-before-attempt UI) | inspect Organizer's row on the Manage-members page | disabled buttons + `aria-describedby` note | unreachable — page never renders | FAIL (unreachable) |
| 14 | Pending row visible | inspect table for seeded pending memberships | `tr[data-status=pending]` present | absent — the shadowed View has no status column/attribute at all | FAIL |
| 15 | Approve pending | click Approve | success message, row becomes active | unreachable via UI (button lives only on the shadowed form's submit handlers) | FAIL (unreachable) |
| 16 | Approve pending (service layer) | `drush php:eval` calling `approvePending()` directly | pending → active | confirmed: `status=pending` → `status=active` | PASS (service layer only) |
| 17 | Deny pending | click Deny | success message, relationship deleted | unreachable via UI | FAIL (unreachable) |
| 18 | Keyboard operability / real buttons | inspect Actions column | real `<button>` elements | N/A on the shadowed page (has a "View member" dropdown instead); confirmed real `<button type=submit>` on all THREE reachable satellite forms | PARTIAL |
| 19 | Pagination | load /group/1/members with 55 members | 50 rows page 1, pager present, 5 rows page 2 | 50 + pager on page 1, 5 on page 2 — **but this is the View's OWN pre-existing pager, not the module's `PagerManagerInterface` pager F built** (the View also paginates at 50 by its own separate config) | PASS on the visible symptom, but does NOT verify F's own pagination code (AC-15/W-2) since that code never executes on this route |
| 20 | Plain-Member access denied (AC-11) | u_plainmember → `/group/1/members` | HTTP 403 | HTTP 403 (screenshot 10) | PASS |
| 21 | Groups-Moderate access allowed (AC-12) | u_groupsmoderate (never a member) → `/group/1/members` | HTTP 200, table renders | HTTP 200, table renders (the View's table — access-gate itself passed correctly, screenshot 11) | PASS (access logic proven; rendered surface is again the View) |
| 22 | 360px viewport | navigate at 360×740 | page renders, table visible | renders, no obvious horizontal-scroll break (screenshot 12) — same View-page caveat | PASS (rendering only) |
| 23 | Hard reload spot-check | direct `/group/1/members` reload | identical to navigated-path result | identical (both hit the View) — no navigated-vs-reload discrepancy, the bug is deterministic | PASS (no discrepancy, but both paths are wrong) |

## AC-15 axe-core pass

Ran `@axe-core/playwright` (ad-hoc `npm install --no-save`, not committed) against every
reachable Manage-members-family surface:

**Steady-state page as actually served (`/group/1/members`, i.e. the shadowed View):**
4 violations — 1 serious (`listitem`: `<li>` outside `<ul>/<ol>`, in the "List additional
actions" dropdown widget), 3 moderate (`heading-order`, `landmark-unique`, `region`). These
are pre-existing site-theme/View markup issues, **not** attributable to `do_group_membership`
— they exist on a page this story's code never actually renders.

**Real `AddMemberForm` (`/group/1/members/add`, reachable, actually F's code):**
1 violation — moderate `landmark-unique` only (duplicate landmark role/label — a
`groups_chrome` theme-level region issue present site-wide, not introduced by this module's
markup). **No serious/critical violations.**

**Real `ChangeRoleForm` (`/group/1/members/{id}/role`, reachable, actually F's code):**
1 violation — the same moderate `landmark-unique` theme issue. **No serious/critical
violations.**

**Steady-state table with badges + last-Organizer disabled state:** **could not be scanned
live** — the route never renders. Static source read of `ManageMembersForm::buildBadge()`
and the guard's `#disabled`/`aria-describedby` wiring (`ManageMembersForm.php` lines 160-271)
confirms the markup shape matches the wireframe's non-color-alone spec (`aria-hidden` glyph +
visible text + `data-state` class) and the guard's `aria-describedby` requirement — but this
is a code read, not a live axe result, and is flagged as such, not claimed as verified.

**AC-15 verdict:** the two reachable module-owned forms are axe-clean of
serious/critical findings (1 pre-existing moderate theme-level `landmark-unique` finding on
each, not module-introduced). **The steady-state table + badge + guard surface — the majority
of AC-15's own listed requirements (badge non-color-alone, guard `aria-describedby`,
every-action-a-real-button) — cannot be axe-verified at all until the route collision is
fixed**, because the page under test does not execute this story's code.

## Findings summary (for F / O)

1. **[BLOCKING] Route-path collision**: `do_group_membership.manage_members`
   (`/group/{group}/members`) collides with the pre-existing
   `views.view.group_members.page_1` display at the identical path. The View wins for every
   GET request, permanently shadowing this story's entire steady-state UI (badges,
   Approve/Deny/Unblock, Role/Remove buttons, last-Organizer disable-before-attempt guard).
   **Fix options for F/O to choose (not my call):** (a) change this story's route path (e.g.
   `/group/{group}/manage-members` or `/group/{group}/members/manage`) and update the local
   task + all internal redirects/E2E spec/tests accordingly — cheapest, no risk to the
   pre-existing View's own consumers; (b) disable/remove the pre-existing
   `views.view.group_members` page display if it is genuinely superseded by this story
   (needs an explicit non-goals check — the brief never mentions this View, so removing it
   may be its own scope decision, not implied by #138); (c) something else A/O judges safer.
   This needs a real decision, not a guess — flagging both options rather than picking one.
2. **[Confirmed correct, unreachable]** `AddMemberForm`, `ChangeRoleForm`, `RemoveMemberForm`,
   and `GroupMembershipManager::approvePending()`/`denyPending()` (service layer) are all
   verified working correctly. Once the collision is fixed, I would expect these to need no
   further UI rework — only the routing fix plus a re-walkthrough to confirm the steady-state
   table (badges, approve/deny, guard) renders as authored.
3. **[Minor, pre-existing, not this story's introduction]** `landmark-unique` (moderate) axe
   finding on every page in this theme (`groups_chrome`), including both of this story's own
   reachable forms — a site-theme-level duplicate-landmark issue, worth a separate ticket, not
   blocking this story specifically since it's not module-introduced.
4. **[Copy nit]** The add-member success message reads "u_addtarget has been added to this
   group." — the wireframe's Screen 3 success copy specifies "Sam Okonkwo has been added to
   this group **as Member**." (including the granted role). Minor, non-blocking, flag for F.

## Evidence

All screenshots + JSON results copied to
`docs/handoffs/0138-mc7-manage-members/evidence-U/`:
- `01-manage-members-navigated.png` — the shadowed View rendering after real tab navigation.
- `02-04` — the WRONG "Add member" flow (core's generic content-add form, reached via the
  local-actions block).
- `05, 07a, 09-15` — further shadowed-View evidence (change-role attempt, pending rows
  absent, last-Organizer guard absent, 360px, hard-reload, pagination).
- `16-22` — the REAL module forms, reached directly, all verified working.
- `23` — real `ChangeRoleForm` axe scan target.
- `axe-satellite-routes.json`, `axe-changerole.json` — raw axe results for the two reachable
  forms.
- `results.json` — full structured step-by-step log from the walkthrough script.

## Docker/env hygiene confirmation

Created only `gm138-mysql` (Docker MySQL 8, port 13306); removed only `gm138-mysql` on
teardown. `docker ps -a` before and after: `o119t3-mysql` and every `ddev-*` sibling
container present and unchanged both before and after this session. No `ddev` project was
started or stopped. Drush runserver process (PID captured, killed via `pkill -f "drush.php
--root"` matching only this session's process) torn down cleanly. Throwaway `.u-walk.mjs`
and its scratch `node_modules`/`package.json` were NOT committed to the repo (scratchpad-only:
`/private/tmp/claude-501/.../scratchpad/u-walk-138/`).

## Verdict

**REWORK.** The route-path collision is a confirmed, reproducible, blocking defect: the
Manage-members steady-state UI this story exists to ship (AC-7, AC-9's UI half, AC-10's UI
half, and the majority of AC-15) is completely unreachable on the real navigated path (and
identically unreachable on a hard reload — deterministic, not a caching/one-time-init
artifact). The underlying Form/service code is verified correct via the satellite routes and
direct service calls, so this should be a narrow, well-understood fix (resolve the path
collision + re-verify), not a rewrite — but it must go back through F (routing fix) → T
(re-verify GREEN, including a live check that `/group/{group}/members` now serves
`ManageMembersForm`) → U (re-walkthrough of the steady-state table, badges, approve/deny,
and the last-Organizer disable-before-attempt UI, which I could not verify live this round)
before this can reach S.

---

# Re-run after REWORK — Phase 8, round 2 (2026-07-22)

**Verdict: PASS**

## TL;DR

The route-path collision that blocked round 1 is **fixed and confirmed live**. Navigating to
`/group/1/members` via the real "Manage members" local-task-tab click now serves
`do_group_membership.manage_members` (F's `ManageMembersForm`) — the stock
`view.group_members.page_1` route no longer exists in the router at all (confirmed via
`router.route_provider->getRoutesByPattern()`, which returns exactly ONE route for the path).
Every mandated control was driven live in a real headless Chromium browser and PASSED: the
steady-state table (badges, `th scope="col"` headers, Joined/Requested labels), add member,
change role, remove-with-confirm-step, approve pending, deny pending, the last-Organizer
disable-before-attempt guard (with `aria-describedby` note), 50-row pagination to page 2, and
both access-control ACs (plain Member denied, Groups-Moderate-non-member allowed). AC-15's
axe-core pass found **zero serious/critical violations** on the steady-state table (2
pre-existing moderate theme findings, not module-introduced) and **zero violations at all** on
the Add-member form.

## Run environment (reproducible)

Followed the mandated recipe — assemble, seed via the docs demo scripts, serve via
`drush runserver` — mirroring `.github/workflows/test.yml`'s `e2e` job exactly:

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0138-mc7-manage-members
bash scripts/ci/assemble-config.sh
docker run -d --name gm138-mysql -e MYSQL_DATABASE=drupal -e MYSQL_ROOT_PASSWORD=root -p 13306:3306 mysql:8
php vendor/drush/drush/drush.php site:install standard \
  --db-url="mysql://root:root@127.0.0.1:13306/drupal" --account-name=admin --account-pass=admin -y
php vendor/drush/drush/drush.php config:set system.site uuid "<config/sync uuid>" -y
echo "\$settings['config_sync_directory'] = '../config/sync';" >> web/sites/default/settings.php
php vendor/drush/drush/drush.php config:import -y
php vendor/drush/drush/drush.php en -y do_group_membership do_tests do_group_extras do_group_language \
  do_group_mission do_group_pin do_multigroup do_notifications do_profile_stats do_discovery
# seed docs/groups/scripts/step_700_demo_data.php + step_720_group_types.php as uid 1
php vendor/drush/drush/drush.php --root="$PWD/web" runserver --no-browser 127.0.0.1:8080
```

- **URL:** `http://127.0.0.1:8080` (group under test: group id 1, "DrupalCon Portland 2026").
- **Auth:** created 8 dedicated test-persona users via a `drush php:script` seed helper
  (`u_organizer` — SOLE Organizer on group 1, `community_group-organizer`; `u_plainmember` —
  `community_group-member`, no admin permission; `u_groupsmoderate` — site role
  `groups_moderate`, deliberately never added to group 1; `u_addtarget` — plain site user, not
  yet a member, target for the add-member flow; `u_toremove`/`u_torole` — members for the
  remove/change-role flows; `u_pendingapprove`/`u_pendingdeny` — seeded with
  `field_membership_status = pending` directly via the `GroupMembership` entity API; 45×
  `u_pag_N` to push group 1 to 56 total `group_membership` relationships for pagination) and
  logged in via the standard `/user/login` form (username/password), matching round 1's
  approach.
- **Browser:** Playwright (Chromium), fully headless, ad-hoc `npm install --no-save playwright
  @axe-core/playwright` in the session scratchpad (no repo `package.json` change, reused the
  round-1 scratch install). Scripts: `.u-walk.mjs`, `.u-walk2.mjs`, `.axe-forms.mjs`,
  `.check-msgs.mjs`, `.pager-recheck.mjs` (all throwaway, deleted after the run).
- **Docker hygiene:** created only `gm138-mysql`; removed only `gm138-mysql` on teardown.
  `docker ps -a` before and after this round: name-set diff is empty — every `ddev-*` sibling
  and every other pre-existing container untouched.
- **Process hygiene:** `drush runserver`'s 4 `PHP_CLI_SERVER_WORKERS` child processes
  (`d8-rs-router.php` on 127.0.0.1:8080) confirmed killed; `lsof -i :8080` empty afterward.

## THE FIX, CONFIRMED LIVE

```
$ drush php:eval '$r = Drupal::service("router.route_provider")->getRoutesByPattern("/group/1/members");
  foreach ($r->all() as $name => $route) { echo $name . " => " . $route->getPath() . "\n"; }'
do_group_membership.manage_members => /group/{group}/members
```

Only ONE route resolves for the path now — `view.group_members.page_1` is absent from the
router entirely (round 1 showed both routes present, with the View winning). Confirmed at the
config layer too:

```
$ drush php:eval '$view = Drupal::entityTypeManager()->getStorage("view")->load("group_members");
  echo implode(",", array_keys($view->get("display")));'
default
```

Only the `default` (Master) display remains; `page_1` is gone — matches F's hook_install fix
(round-1 site-config deletion + round-2/3 `hook_install`/`hook_modules_installed` +
same-request router rebuild), exercised here via the real `config:import` + `drush en` path
(not just `drush php:eval` per-process checks, which round 1's own findings noted could mask
staleness — this round's checks were run against the SAME long-lived served process the
browser hit).

## Steady-state renders live — DOM evidence

Real navigated path: logged in as `u_organizer`, navigated to `/group/1` (canonical page),
clicked the **"Manage members" local task tab** (not a direct URL), landed on
`http://127.0.0.1:8080/group/1/members`.

- **H1:** "Manage members" (screenshot `02-crop-top.png` — also shows the active "Manage
  members" tab, breadcrumb "Home › DrupalCon Portland 2026", exactly matching wireframe
  Screen 1's chrome).
- **`do-group-membership__*` markup count:** 161 occurrences in the rendered HTML (round 1: 0).
- **`views-table` class (the old View's marker):** absent (round 1: present).
- **Real `<form>` elements on the page:** 3 (this is `ManageMembersForm`, a real Drupal form —
  round 1 had 0, since the View is a controller render with no form).
- **Table columns:** Member name / Role(s) / Status / Joined/Requested / Actions — matches
  wireframe Screen 1 exactly (screenshot `02-crop-table.png`).
- **Status badges:** `<span class="do-group-membership__badge do-group-membership__badge--active" data-state="active"><span aria-hidden="true">✓</span> Active</span>` —
  confirmed live in the DOM, exact match to the wireframe's badge spec (glyph `aria-hidden`,
  visible text, `data-state` modifier class).
- **Hard-reload spot-check:** reloading `/group/1/members` directly produced the identical
  markup (161 `do-group-membership` occurrences both times) — no navigated-vs-reload
  discrepancy, the fix is deterministic on both paths.

## `<th scope="col">` headers — confirmed live

```json
[
  {"text": "Member name", "scope": "col"},
  {"text": "Role(s)", "scope": "col"},
  {"text": "Status", "scope": "col"},
  {"text": "Joined/Requested", "scope": "col"},
  {"text": "Actions", "scope": "col"}
]
```

All 5 `<th>` elements carry `scope="col"` in the live-rendered DOM. **PASS.**

## Per-control checklist (this round)

| # | Control | Action | Expected | Observed | Verdict |
|---|---|---|---|---|---|
| 1 | Login | log in as u_organizer | authenticated | redirected past login form | PASS |
| 2 | "Manage members" tab | navigate group/1, click tab | tab visible + navigates to the NEW controller | tab present, navigated, 161 `do-group-membership` markup hits, 0 `views-table` | PASS |
| 3 | th scope=col | inspect all 5 `<th>` | `scope="col"` on each | confirmed on all 5 | PASS |
| 4 | Status badge (text+glyph) | inspect `[data-state=active]` | visible "✓ Active" text, not color-only | `<span aria-hidden>✓</span> Active` present live | PASS |
| 5 | Pending badge | inspect `[data-state=pending]` | visible "⏳ Pending" text | present live, "Member (requested)" role qualifier also present | PASS |
| 6 | Joined/Requested labels | inspect body text | "Joined:"/"Requested:" | both present, correct per row status | PASS |
| 7 | Hard-reload spot-check | direct reload of `/group/1/members` | identical to navigated result | identical (161 markup hits both times), no discrepancy | PASS |
| 8 | Add member | click "+ Add member", fill autocomplete, submit | success message, relationship created | `"...has been added to this group."` (screenshot `04-add-member-result.png`) | PASS |
| 9 | Change role | click `[Role ▾]` on u_torole's row, check Moderator, Save | success, role updated | `/role has been updated/i` matched (screenshot `06b-change-role-result.png`) | PASS |
| 10 | Remove — confirm step | click `[Remove]` on u_toremove's row | ConfirmFormBase step, NOT instant-fire | "Are you sure..." rendered, no deletion yet (screenshot `07b-remove-confirm-step.png`) | PASS |
| 11 | Remove — confirm | click "Remove member" | success, relationship deleted | `"...has been removed from this group."` (screenshot `08b-remove-result.png`) | PASS |
| 12 | Last-Organizer guard — disable-before-attempt | inspect u_organizer's row (SOLE Organizer on group 1) | Role/Remove `disabled`, `aria-describedby` note visible | `removeDisabled: true`, `roleDisabled: true`, `describedBy: "do-group-membership-guard-43"`, note text `"ⓘ Last Organizer — promote another member first."` | PASS |
| 13 | Pending row visible | inspect table for seeded pending rows | 2 pending rows present | 2 found (`u_pendingapprove`, `u_pendingdeny`) | PASS |
| 14 | Approve pending | click `[Approve]` on u_pendingapprove's row | success, row becomes active | `/approved|now an active/i` matched, `messages--status` div present | PASS |
| 15 | Deny pending | click `[Deny]` on u_pendingdeny's row | success, relationship deleted | `/denied/i` matched, `messages--status` div present | PASS |
| 16 | Pagination — page 1 | load `/group/1/members` (56 total group_membership relationships) | 50 rows | 50 rows confirmed | PASS |
| 17 | Pagination — page 2 | click pager "2" | remaining rows (6 = 56−50) | 6 rows confirmed — mathematically exact | PASS |
| 18 | Access — plain Member denied (AC-11) | u_plainmember → `/group/1/members` | HTTP 403 | HTTP 403 (screenshot `13-access-plainmember.png`) | PASS |
| 19 | Access — Groups-Moderate allowed (AC-12) | u_groupsmoderate (never joined group 1) → `/group/1/members` | HTTP 200, real module table renders | HTTP 200, `do-group-membership` markup present (screenshot `14-access-groupsmoderate.png`) | PASS |
| 20 | 360px viewport | navigate at 360×740 | page renders, badges/buttons legible, no broken overflow | renders responsively via `tableresponsive` treatment, badges/buttons legible (screenshot `15-360px-manage-members.png`) | PASS |
| 21 | Console errors | inspect console/pageerror events on the steady-state page | none | 0 console errors captured | PASS |

## AC-15 axe-core pass

Ran `@axe-core/playwright` against every reachable Manage-members-family surface now that the
route resolves correctly:

**Steady-state table (`/group/1/members`, the real `ManageMembersForm`, u_organizer session):**
2 violations, both **moderate**, **zero serious/critical**:
- `heading-order` (moderate, 1 node) — pre-existing theme-level finding (same as round 1's
  reachable-forms result), not module-introduced.
- `region` (moderate, 1 node) — same theme-level class of finding.

**Add-member form (`/group/1/members/add`, real F code):** **0 violations at all** — fully
axe-clean, autocomplete field present (1), 3 role checkboxes present, matching wireframe
Screen 3.

**AC-15 verdict:** **PASS.** No serious/critical violations anywhere on the now-reachable
Manage-members surface. The badge non-color-alone requirement, the guard's `aria-describedby`
wiring, and the every-action-a-real-button requirement (all `<input type="submit">`, keyboard
operable, correct disabled state) are now axe- and DOM-verified live, closing the exact gap
round 1 flagged as unverifiable.

## Copy nit (carried over from round 1, still present, non-blocking)

The add-member success message still reads `"...has been added to this group."` without the
role suffix the wireframe's Screen 3 specifies (`"...as Member."`). Minor, non-blocking,
flagged again for F/O — does not affect this verdict.

## Docker/env hygiene confirmation (this round)

Created only `gm138-mysql` (MySQL 8, port 13306); removed only `gm138-mysql` on teardown.
`docker ps -a` name-set diff before/after this round: **empty** — every `ddev-*` sibling and
every other pre-existing container (including `o119t3-mysql`, absent from the current
inventory, and the various `ddev-pl-*`/`ddev-da-*`/`reportportal-*`/`language-buddy-*`
containers) confirmed untouched. `drush runserver`'s 4 worker child processes on port 8080
confirmed killed (`lsof -i :8080` empty post-teardown). Throwaway Playwright scripts
(`.u-walk.mjs`, `.u-walk2.mjs`, `.axe-forms.mjs`, `.check-msgs.mjs`, `.pager-recheck.mjs`) and
their scratch npm install were NOT committed (scratchpad-only). Pre-existing worktree build
artifacts (`config/sync/*`, `web/modules/custom/`, `web/sites/simpletest/` — all
`assemble-config.sh` output, matching the state found at session start after a `git
stash`/restore round-trip) left as found; `web/sites/default/settings.php` is gitignored build
output.

## Evidence

All screenshots + JSON results copied to
`docs/handoffs/0138-mc7-manage-members/evidence-U-rerun/`:
- `01-group-canonical.png` — group canonical page with the "Manage members" tab visible.
- `02-manage-members-navigated.png`, `02-crop-top.png`, `02-crop-table.png` — the REAL
  steady-state Manage-members table after real tab navigation (active tab, H1, badges,
  columns — matches wireframe Screen 1).
- `03-04` — Add-member form + success result.
- `05b-06b` — Change-role sub-form + success result.
- `07b-08b` — Remove confirm step + success result.
- `09b-10b` — Approve/Deny pending results.
- `11-12` — Pagination page 1 (50 rows) / page 2 (6 rows).
- `13-14` — Access control (plain-Member 403 / Groups-Moderate 200).
- `15` — 360px viewport.
- `axe-manage-members.json`, `axe-forms.json` — raw axe results.
- `results.json`, `results2.json` — full structured step-by-step logs.
- `manage-members-navigated.html` — full page source for DOM-evidence cross-checks.

## Verdict

**PASS.** The route-collision fix (F's multi-layered `hook_install` +
`hook_modules_installed` + same-request router rebuild + views-guard) is confirmed live: the
steady-state Manage-members UI this story exists to ship now renders correctly on the real
navigated path (and identically on a hard reload), with every mandated interactive control
(add member, change role, remove-with-confirm, approve/deny pending, the last-Organizer
disable-before-attempt guard, 50-row pagination, and both access-control ACs) exercised live
and passing. AC-15's axe-core pass found zero serious/critical violations. Ready for S.
