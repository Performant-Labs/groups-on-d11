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
