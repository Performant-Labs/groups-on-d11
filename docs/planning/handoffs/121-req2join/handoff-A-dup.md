# Handoff-A-dup: Phase 7 - #121 SC-2 Membership models enforced (anti-duplication gate)

**Date:** 2026-07-22
**Branch:** 121-req2join (worktree `~/Projects/_worktrees/groups-req2join`)
**Diff base:** e269c66 (origin/main)  **Diff head:** 5bd0210
**Reuse map:** `docs/planning/handoffs/121-req2join/brief-response-v2.md` §"Files to touch after amendment"; `survey.md`.
**Verdict:** PASS

## Summary

F extended the analogous objects the map named (`GroupMembershipManager`,
`ManageMembersController`, the shared `groups_chrome_preprocess_group()` action-picker) rather
than building parallel paths. The two flagged deviations — `Routing/RouteSubscriber.php` and the
theme-picker third branch — are both correctly-scoped, minimally-invasive extensions of existing
seams, not parallel implementations of something already available. Duplication risk in
`joinRouteAccess` vs. `groupRelationshipCreateAccess` is real to look for but does not exist in
this diff: the two callbacks share a single classifier (`joinPolicyFor()`) and gate distinct
concerns (route-URL admissibility vs. entity-create policy). No block-severity findings.

## Findings

| # | Severity | File:line | Finding | Suggested fix |
|---|---|---|---|---|
| 7.1 | PASS | `docs/groups/modules/do_group_membership/src/Routing/RouteSubscriber.php:43-60` | F's diagnosis verified. `web/modules/contrib/group/group.routing.yml:12-19` shows `entity.group.join` requirements are exactly `_group_permission: 'join group'` + `_group_member: 'FALSE'`. `GroupMembershipController::join()` calls `$this->entityFormBuilder->getForm($group_relationship, 'group-join')`, which reaches `ContentEntityForm::save()` — none of the form-submit path invokes `$entity->access('create')`, so `GroupAccessHook` alone cannot gate this route. `RouteSubscriberBase` is the documented Drupal-standard mechanism for altering another module's route without patching vendor code; `_custom_access` combines AND-wise with other route requirements in Drupal's route access resolution, so the added third requirement narrows without duplicating. No parallel path — this is a genuinely-necessary layered extension. | none |
| 7.2 | PASS | `web/themes/custom/groups_chrome/groups_chrome.theme:344-378` | Third `elseif` branch slots into the same `$gc['action']` output the existing Join/Leave branches populate — verified they mutate the SAME variable (single assignment path per render), so no double-render. F's revert of the module-level `JoinAffordanceHook` was correct: had both shipped, the theme's picker and a `hook_ENTITY_TYPE_view` extra field would each render into the header region. Theme-layer ownership of this affordance is consistent with the pre-existing #85 pattern for `entity.group.join`/`entity.group.leave` — nothing about `do_group_membership.request_join` warrants a different render surface. The theme knowing one more route name is not a new architectural coupling; the file already names `entity.group.join`/`entity.group.leave` for the same reason. | none |
| 7.3 | PASS | (diff review) | Every changed file matches brief-response-v2 §"Files to touch" EXCEPT the two F-flagged additions (`RouteSubscriber.php`, `web/themes/custom/groups_chrome/groups_chrome.theme`), both individually justified above, plus the sibling-pattern one-liner `do_group_membership.module` (docblock-only, mirrors `do_group_extras/do_group_extras.module` exactly — required for `#[Hook]` attribute discovery). Two services.yml entries added (`GroupAccessHook` keyed by FQCN per F's HookCollectorPass rationale; `do_group_membership.route_subscriber` event-subscriber tag) — both are the minimal wiring for the two justified new classes. No stealth methods on other modules; no drive-by edits to shared code beyond the two theme-file docblocks. | none |
| 7.4 | PASS | `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php:98-136,162-173,383+` | `createMembership()` genuinely unifies `addMember` (line 98-99, one-line delegate) and `requestJoin` (line 135-136, one-line delegate) — no duplicated guard logic remains in either public method. `joinPolicyFor()` maps `open→'open'`, `moderated→'request'`, `invite_only→'invite'` (line 166-170), matching both the hook's `match($policy)` arm names (`GroupAccessHook.php:102-106`: `'request'`, `'invite'`, default) and the controller's checks (`joinRouteAccess` compares `=== 'open'`; `requestJoinAccess` compares `=== 'request'`). All three consumers agree on the enum. Field-absent default of `'open'` is consistent with the field's own `default_value`. | none |

### Duplication-focused deep check on 7.1 vs. `GroupAccessHook`

Asked explicitly per your brief: does `joinRouteAccess` duplicate hook logic? No —
`joinRouteAccess` allows on `administer members` OR `policy === 'open'`; the hook returns
`neutral` (pass-through) for `administer members` and for `open`, `allowed` for `request`, and
`forbidden` for `invite`. Different response tables, different concerns:

- **Hook** answers "may a `group_membership` entity of this bundle be *created* against this
  group by this account, once the caller has actually built one?" — governs entity-create
  access uniformly across every code path that invokes it (`AddMemberForm`, `RequestJoinForm`,
  future callers).
- **`joinRouteAccess`** answers "should the URL `/group/{group}/join` even be reachable?" —
  governs route admissibility, and correctly refuses `moderated` at this URL (moderated
  groups' proper entry point is `/join-request`, not `/join`), which the hook allows because
  the hook does not know which route the caller came from and shouldn't.

Textually distinct, semantically distinct, coordinated through the shared classifier
`joinPolicyFor()`. This is the correct anti-duplication shape (one classifier, multiple
consumers) — not parallel logic.

## Advisory / WARN (non-blocking)

- **`/all-groups` directory-card affordance still lacks the request-to-join branch**
  (`groups_chrome_preprocess_views_view_fields__all_groups()`, same theme file, different
  function, roughly `:546-569`). It only sets `$gc['can_join']` on `is_open`, so a moderated
  group's directory card shows no affordance at all. F declined to fold this in to keep the
  surgical fix scoped; T's E2E confirmed AC-2 discoverability on the *canonical group page*
  (which is what the brief's AC-2 wording targets most directly). This is a real, pre-existing
  parallel-branch gap that should be captured as a follow-up story rather than blocked here —
  the AC as-written is satisfied by the canonical-page link, and expanding to the directory
  view is additive coverage, not a regression F introduced. WARN, not BLOCK.
- **Branch is behind `origin/main`** — `docs/groups/scripts/step_700_demo_data.php` (Step 740d
  from #122), `do_group_extras/*` (from #143), and several `groups_chrome/templates` files
  appear as deletions in `origin/main..HEAD` because they landed on main after this branch was
  cut. These are not story-#121 changes (verified via `git log origin/main..HEAD --name-only`
  — story commits touch none of them). O will need to rebase before merge. Advisory only —
  outside my Phase-7 scope.
- **`do_group_extras` unpublish-on-CLI-create hook** (T-green Advisory #3) may silently affect
  CI's E2E directory listing if CI's seed step doesn't run as uid 1. Not this story's remit
  but worth a follow-up.

## Notes for F

None — verdict is PASS, no rework required.

## Patterns referenced

- `docs/groups/modules/do_group_extras/do_group_extras.module` (sibling `.module` docblock pattern for `#[Hook]` classes).
- `web/modules/contrib/group/group.routing.yml:12-19` (verified `entity.group.join`'s stock requirements — the gap `RouteSubscriber` fills).
- `web/modules/contrib/group/src/Controller/GroupMembershipController.php:47-55` (verified the join controller's `entityFormBuilder->getForm()` path never invokes entity-create access).
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,80` (established pattern of unfiltered `getMembers()` followed by inline status labelling — validates the theme picker's route-access-only approach as consistent).
- `web/themes/custom/groups_chrome/groups_chrome.theme:240-378` (the pre-existing Join/Leave picker whose two-branch shape the third branch extends).
