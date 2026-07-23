# Handoff-A: Phase 3 (Round 2) — #121 SC-2 Membership models enforced — plan re-review

**Date:** 2026-07-22
**Branch:** 121-req2join
**Amendment reviewed:** `docs/planning/handoffs/121-req2join/brief-response-v2.md` (authoritative; supersedes)
**Prior report:** `docs/planning/handoffs/121-req2join/handoff-A-plan.md` (Round 1 — BLOCK, 3 findings)
**Verdict:** **PASS**

## Summary

The v2 amendment surgically resolves all three Round 1 BLOCKs. The parallel pending-queue
surface is deleted in full (route, local task, `pendingQueue()` controller method, and
`PendingRequestActionForm.php`), and AC-4/AC-15/AC-16 are restated against the existing
`/group/{group}/members` + `ManageMembersForm` surface. The one surviving new route
(`do_group_membership.request_join`) is on the established `_custom_access` idiom via a peer
method `ManageMembersController::requestJoinAccess()` that inherits the same cache-metadata
contract as the existing `::access()` callback. The manager surface is trimmed to exactly
`requestJoin` + `joinPolicyFor`. No new drift introduced. Cleared to proceed to Phase 4 (T).

## Per Round-1 finding

| # | Round-1 severity | Status | Justification |
|---|---|---|---|
| A-1 | block | **ACCEPTED — resolved** | v2 §A-1 fix deletes all four surface elements (route, local task, controller method, form) and restates AC-4/AC-15/AC-16 against `/group/{group}/members` + existing Approve/Deny row buttons. "DELETED from original plan" list at v2 §"Files to touch" confirms. |
| A-2 | block | **ACCEPTED — resolved** | v2 §A-2 fix pins the new `do_group_membership.request_join` route to `_custom_access` targeting a new peer method `ManageMembersController::requestJoinAccess()`. Peer method spec explicitly includes the same cache-metadata contract (`addCacheableDependency($group)`, `cachePerPermissions()`, `cachePerUser()`, `addCacheContexts(['url.path'])`) as the existing `::access()` callback (verified `ManageMembersController.php:38-45`). Amendment states in bold "Do NOT introduce `_group_permission` YAML gating anywhere in this story." |
| A-3 | block | **ACCEPTED — resolved** | v2 §A-3 fix trims to exactly `requestJoin` + `joinPolicyFor`. `countPendingFor` / `getPendingFor` dropped. Consumer-set now consistent (ManageMembersForm continues to read via `$group->getMembers()`). |

## New findings introduced by the amendment

None at BLOCK. Two NITs and one WARN below.

### [A-R2-W1] `requestJoinAccess()` cache metadata needs `field_group_visibility` cacheable dependency

**Severity:** warn.
**Where:** `ManageMembersController::requestJoinAccess()` spec (v2 §A-2, lines 77–83).

The peer method logic keys on `$group->get('field_group_visibility')->value === 'moderated'`.
`addCacheableDependency($group)` already covers the group entity's cache tags — mutations to
`field_group_visibility` on that group will bust the group's cache tag — so this is
**technically covered**. Calling it out explicitly so F does not "optimize" it away or split
the value out into a separate cache key: keep `addCacheableDependency($group)` unconditional
regardless of the visibility branch, so cache invalidation follows the group entity as a whole.
Non-blocking; a note for F.

### [A-R2-N1] `requestJoinAccess()` — allowed states include membership status filter

**Severity:** nit.
The proposed logic says "authenticated user is not already a member". F should be aware that
`$group->getMember($account)` in the customized model may return a **pending** or **blocked**
relationship — the "already a member" check must include pending and blocked, not only active
(otherwise a spammer who already has a pending row could re-POST and duplicate). The
`GroupMembershipManager::requestJoin()` state-transition method already needs the same guard
(`DuplicateMembershipException` pattern from `addMember`), so the access short-circuit and the
manager guard stay symmetrical. Non-blocking; F picks the exact check shape. Recording it here
so it does not surface as a Phase-7 dup drift finding.

### [A-R2-N2] Supersedes wording explicit enough

**Severity:** nit.
v2 line 4 says "Amendments below supersede any conflicting text in `brief.md` / `survey.md` /
`brief-response.md`." Clear. T and F will land on v2 as authoritative. No action.

## Validation of amendment-specific items

- **`requestJoinAccess()` peer method logic** (authenticated + non-member +
  `field_group_visibility === 'moderated'` → allowed): correct and consistent with the hook
  gate semantics (defense-in-depth pairing is standard — route-level short-circuit + hook at
  entity-create). Semantics match the joinPolicyFor classifier (`moderated` ↔ `request`).
- **Cache metadata contract:** faithfully mirrors `::access()` at `ManageMembersController.php:38-45`.
  See A-R2-W1 note.
- **`createMembership()` private helper (A-W1)**: v2 explicitly says "F picks the exact shape"
  and does not prescribe the signature. Appropriate — F has the flexibility to extract based
  on what `addMember`/`requestJoin` actually share once written. Consistent with the manager's
  current cadence of state-transition verbs.
- **Files-to-touch list consistency:** EDIT (7) + NEW (5) + DELETED (4) list matches the AC
  set after restatement:
  - AC-3/AC-11 → RequestJoinForm + RequestJoinFlowTest + JoinPolicyEnforcementTest.
  - AC-4 → existing ManageMembersForm (no file change) + Kernel/Functional coverage.
  - AC-9 (2 pending rows) → `step_700_demo_data.php` idempotent append.
  - AC-15/AC-16 → JoinPolicyEnforcementTest against existing routes.
  - AC-7/HelpText copy → HelpText.php + HelpTextTest.php + A-W3 view/join guard assertion.
- **Conflicts with prior artifacts:** v2 sufficiently marks itself authoritative. F and T
  will read `handoff-A-plan-r2.md` (this file) + `brief-response-v2.md` as the current plan.

## Notes for O

None — no re-amendment required. Proceed to Phase 4 (T authors RED tests). Both A-R2-W1 and
A-R2-N1 are notes F will address at implementation without a plan-level change; if F ignores
them, they'll re-surface at Phase 7 dup gate.

## Patterns referenced

- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php:38-45` — `::access()` shape the peer method mirrors.
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml:1-51` — `_custom_access` idiom confirmed universal.
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,80,214-234,296-329` — the extant pending-row surface that AC-4/AC-15/AC-16 now target.
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` — manager cadence (state-transition verbs, `DuplicateMembershipException` guard shape).
- `docs/planning/handoffs/121-req2join/handoff-A-plan.md` — Round 1 findings (for traceability).
- `docs/planning/handoffs/121-req2join/brief-response-v2.md` — the authoritative amendment reviewed here.
