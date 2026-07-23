# Handoff-U: Phase 8 - #126 SD-1 Page-level ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 126-page-tooltips
**Container:** `ddev-gm126-page-tooltips-web`, direct port `https://127.0.0.1:53099` (ddev-router bound to a different project — same env quirk T-green documented). Ran `drush cr` before testing per T-green advisory.

## Verdict: **REWORK**

## Per-page results

| Page | HTTP | H1 | ⓘ present | Copy correct | Hover tooltip | Keyboard | Contrast |
|---|---|---|---|---|---|---|---|
| `/stream` (anon) | 200 | "Activity Stream" | YES | YES ("site-wide activity stream…") | YES (tippy) | Tab reaches, focus outline `solid 2px`, Enter opens | 5.42:1 (rgb(0,103,184) on rgb(246,248,248)) — PASS AA |
| `/all-groups` (anon) | 200 | "All Groups" | YES | YES ("Every community group…") | YES | PASS | 5.42:1 — PASS AA |
| `/group/1/stream` (anon) | 200 | "Group Content" | YES | YES ("group's activity…") | YES | PASS | 5.42:1 — PASS AA |
| `/group/1/events` (anon) | 200 | "Group Events" | YES | YES ("events organised…") | YES | PASS | 5.42:1 — PASS AA |
| `/group/1/members` (anon) | 403 | "Access denied" | N/A (access denied, expected — visibility rules) | — | — | — | — |
| `/group/1/members` (admin, authenticated) | 200 | "Manage members" | **NO — DEFECT** | N/A | — | — | — |

## Root-cause finding (blocking)

`/group/{group}/members` does **not** resolve to Views route `view.group_members.page_1` in this
codebase. Verified via Drupal's router (no-access-check match) against the live request path:

```
ROUTE: do_group_membership.manage_members
```

`do_group_membership` (story #138, "Manage-members UI") ships a custom controller route at this
path, which supersedes/shadows the Views page the #126 brief assumed. `PageHelp::getRouteMap()`
(`docs/groups/modules/do_chrome/src/Hook/PageHelp.php:75`) keys the members entry as
`'view.group_members.page_1' => 'page.group.members'` — a route name that is never the active
route for the real "Members" tab visitors reach. The default-deny gate (by design) then correctly
suppresses the ⓘ, but the practical effect is that **AC-1 fails for the Members tab**: 4/5 live
pages render the ⓘ correctly, the 5th (Members) never does, on any group, for any user, because
the map key doesn't match reality — not an access-control artifact.

Confirmed this is not a permissions fluke: re-tested as `uid 1` (admin, full access) — page loads
200 with H1 "Manage members" — `.page-help-info` still absent.

Fix needed: `PageHelp::getRouteMap()` must key `page.group.members` to
`do_group_membership.manage_members` (or add both if a bare Views fallback route can still be
reached in some config). T's kernel test `testRouteMapContainsExactlyTenEntries` and the route-map
unit test pinned the wrong route name and will need updating alongside the fix; the map-key change
does not add/remove an entry so the count assertion should still hold.

## Other checks (all pass, unaffected by the above)

- **Default-deny sanity** — `/user/login` (200, no `.page-help-info`), `/admin` (403, no
  `.page-help-info`), `/node/1/edit` (403, no `.page-help-info`), `/user/register` (403, no
  `.page-help-info`). All correctly absent.
- **W2 inert check** — `/my-feed` → 404, no crash, no orphan ⓘ, `.page-help-info` count 0. PASS.
- **Persona Elena** — not separately re-verified given the Members-tab defect already establishes
  REWORK; the map/gate logic is auth-state-independent by design (confirmed by code inspection —
  `preprocessPageTitle` keys only on route name, not user), so this is low-risk, but should be
  re-checked by U on the fix re-run along with the corrected Members route.

## WCAG 2.2 AA spot-check (on the 4 passing pages)

- Keyboard-focusable: YES, Tab reaches `.page-help-info`, `tabindex="0"` confirmed.
- Accessible name: YES, non-empty `aria-label` matching visible/hover copy.
- Visible focus: YES, `outline: solid 2px` computed on focus.
- Contrast: 5.42:1 (fg `rgb(0,103,184)` / bg `rgb(246,248,248)`) — exceeds both the 4.5:1 AA floor
  and the 5.36:1 baseline cited in the brief (#122).
- Tooltip content is text, not color-only status: YES.

No AA concerns on the 4 rendering pages.

## Evidence

- Screenshots (1440x900): `docs/planning/handoffs/126-page-tooltips/screenshots/stream-desktop.png`,
  `all-groups-desktop.png`, `group-1-stream-desktop.png`, `group-1-events-desktop.png` (all ⓘ
  visible next to H1), and `group-1-members-desktop.png` (authenticated, "Manage members" H1, no ⓘ
  — defect evidence).
- Route confirmation: `router.no_access_checks` match for `/group/1/members` → `do_group_membership.manage_members`.
- `PageHelp.php:75` — the mismatched map entry.

## Action for O/F

F must repoint the `page.group.members` map entry in
`docs/groups/modules/do_chrome/src/Hook/PageHelp.php::getRouteMap()` from
`view.group_members.page_1` to `do_group_membership.manage_members`, update the corresponding
kernel/unit test assertions that reference the old route name, then re-run T (green), then U
re-walks all 5 live pages (including a fresh Elena-persona pass) before handing to S.

---

## Re-walk (Phase 8b) — 2026-07-23

**Container:** `gm126-page-tooltips` (`.ddev/config.yaml` name mismatch fixed — was still
`pl-groups-on-d11`, colliding with the primary checkout's running container; renamed to
`gm126-page-tooltips` and restarted). Direct port `https://127.0.0.1:53099` confirmed reachable
(200 on `/user/login`). Seeded via `docs/groups/scripts/step_700_demo_data.php`,
`step_720_group_types.php`, `step_780_nav_menu.php` (persisted DB volume already had 8 groups +
all persona users, incl. `elena_garcia`, from an earlier build). `drush cr` run before testing.

**Fix confirmed in code:** `PageHelp::getRouteMap()` now keys
`'do_group_membership.manage_members' => 'page.group.members'` (was
`'view.group_members.page_1'`).

### Re-verified checks

| Check | Result |
|---|---|
| `/group/1/members` (authenticated admin, then persona-switched Elena) HTTP | 200 |
| H1 | "Manage members" — visible |
| `.do-chrome-info.page-help-info` present | YES (count 1) |
| `aria-label` | "Everyone who has joined this group. Organizers manage the roster; joining rules depend on the group's visibility (Open, Moderated, or Invite Only)." — matches `HelpText.php:225` verbatim |
| `data-do-tooltip` | same copy, non-empty |
| Hover → tippy tooltip | visible, contains matching copy |
| Tab → focus reaches ⓘ | YES (within extended 80-tab budget — Manage-members page has a larger roster/table focus order than the simpler pages); `outline-width: 2px` on focus |
| Enter opens tooltip | YES |
| Elena persona on `/stream` | ⓘ present (count 1), `aria-label` = "The site-wide activity stream: recent posts, replies, and events from every public group. This is what a signed-out visitor sees to get a sense of the community." — correct, auth-state-independent as predicted |

Console: 3 transient 400/405 responses observed only during `/persona-switch/*` and one-time-login
navigations (not on `/stream` or `/group/1/members` themselves — confirmed by isolated re-check
with a plain `/stream` load producing zero >=400 responses). Not related to the tooltip feature;
no JS errors, no dead-component signals.

### Evidence

- Screenshots (1440x900): `screenshots/group-members-desktop-authed.png` (ⓘ visible next to
  "Manage members" H1), `screenshots/stream-elena-desktop.png` (Elena persona, ⓘ visible on
  `/stream`).

### Verdict: **PASS**

AC-1 now holds for all 5 live routes, including Members. The route-map fix in `80325ba` resolves
the prior REWORK; no new defects found. Ready for S.
