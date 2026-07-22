# Handoff-S: Phase 9 (Spec Audit) — Issue #138 (MC-7) Organizer Manage-members UI + group roles + Groups Moderate

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Issue:** #138 (MC-7)
**Handoff-T reviewed:** `docs/handoffs/0138-mc7-manage-members/handoff-T-green.md`
**Handoff-A reviewed:** `docs/handoffs/0138-mc7-manage-members/handoff-A-plan.md` + `handoff-A-dup.md`
**Handoff-F reviewed:** `docs/handoffs/0138-mc7-manage-members/handoff-F.md`
**Handoff-U reviewed:** `docs/handoffs/0138-mc7-manage-members/handoff-U.md` (re-run after REWORK — PASS)
**Operator-facing report:** N/A — non-visual audit path (see "Visual path" note below).

## A precondition
Confirmed: A returned **PASS** at both gates — up-front plan (`handoff-A-plan.md`: "No `block`
findings") and anti-duplication (`handoff-A-dup.md`: PASS, single enforcement path, reuse-first
held). One optional low-priority `warn` (promote the manager's protected count helper to public to
drop a UI-only duplicate read) is carried to the PR description, not a blocker.

## T precondition
Confirmed: T reported **zero blocking issues**. Final full-suite confirmation
(`handoff-T-green.md`, "Final full-suite confirmation … ASSEMBLED layout"): **41/41 GREEN**
(16 Unit + 14 Kernel + 11 Functional), 534 assertions, **zero failures / errors / skips**, run from
the assembled `web/modules/custom/` layout that mirrors `.github/workflows/test.yml`. The earlier
"14 env-blocked / core list_string bug" framing was fully retired — root-caused to a
test-authorship `allowed_values`-shape bug (structured YAML shape passed to `FieldStorageConfig::
create()`, which wants the simple `[value => label]` shape), now fixed; `grep markTestSkipped` = 0.

## Visual path
This story has a rendered UI surface (the Manage-members table). Per the auditor mandate and the
run's own division of labor, the **live-browser + axe + responsive** verification is U's job and was
completed at the re-run: `handoff-U.md` records a headless Playwright walkthrough at steady state and
360px, driving every control live, with **axe zero serious/critical** (2 pre-existing moderate
theme-level `landmark-unique`/`landmark` findings, not module-introduced). There is no approved
static pixel reference to diff against (the design artifact is a low-fi ASCII wireframe, not a
rendered comp), so a Tier-3 pixel-diff report is not applicable; U's live axe/DOM evidence is the
authoritative UI result. No CANNOT-AUDIT condition arises.

## AC-to-test traceability

| AC | Requirement (short) | Pinning test(s) | Result |
|----|---------------------|-----------------|--------|
| AC-1 | Organizer role: scope individual, admin false, edit group + administer members + 20× content perms | `GroupRoleConfigShapeTest::testOrganizerRoleConfigShape` (exact sorted permission-set equality) | PASS |
| AC-2 | Moderator role: administer members + 5× view-only, no edit group, no content CRUD | `GroupRoleConfigShapeTest::testModeratorRoleConfigShape` (+ explicit `assertNotContains 'edit group'`, `'create group_node:post entity'`) | PASS |
| AC-3 | `community_group-member` reused unchanged | `GroupRoleConfigShapeTest::testMemberRoleConfigUnchanged` (pins live shape) + git diff shows the file is absent from the diff | PASS |
| AC-4 | `field_membership_status` list_string active/pending/blocked; no new joined-date field | `MembershipStatusFieldConfigShapeTest::testFieldStorageShape` + `testFieldInstanceAttachedToMembershipBundle` + `testNoNewJoinedDateFieldAdded` (asserts the negative) | PASS |
| AC-5 | Status state machine: pending→active, pending→deleted, active↔blocked, any→deleted; change-role orthogonal | `GroupMembershipManagerKernelTest::testApprovePendingTransitionsToActive` / `testDenyPendingDeletesTheRelationship` / `testBlockAndUnblockAreSymmetricAndPreserveRelationship` / `testChangeRoleDoesNotTouchMembershipStatus`; Unit mirrors in `GroupMembershipManagerTest` | PASS |
| AC-6 | Route `/group/{group}/members`, task "Manage members" weight 20, access = hasPermission('administer members') OR administer group | `ManageMembersRouteAccessTest::testOrganizerCanAccessManageMembers` / `testSiteAdminEscapeHatchGrantsAccess`; `ManageMembersAccessTest::testOrganizerHasAdministerMembers` / `testModeratorHasAdministerMembers`; route/task YAML | PASS |
| AC-7 | Real `<table>` + `<th scope="col">`, badge color+text, actions as real buttons, confirm step | `ManageMembersPageRenderTest::testMemberListRendersAsRealTableWithScopedHeaders` + `testStatusBadgeCarriesVisibleTextNotColorAlone`; U live DOM (real `<button>`s, ConfirmForm remove) | PASS |
| AC-8 | Add-member validation: existing membership (any status) rejected; blocked account rejected | `GroupMembershipManagerKernelTest::testAddMemberRejectsExistingMembershipAnyStatus` + `testAddMemberRejectsBlockedUserAccount` | PASS |
| AC-9 | Remove OR demote last Organizer blocked; Groups-Moderate exempt (not counted) | `GroupMembershipManagerKernelTest::testRemoveMemberRefusesLastOrganizer` / `testRemoveMemberAllowedWhenAnotherOrganizerRemains` / `testChangeRoleRefusesToDemoteLastOrganizer`; whole-group count proven by `ManageMembersPaginationTest` (guard-note absent on both pages) | PASS |
| AC-10 | Approve/deny already-resolved request = no-op, not fatal | `GroupMembershipManagerKernelTest::testApprovePendingRaceIsNoOp`; Unit `testApprovePendingOnAlreadyResolvedRequestIsNoOp` / `testDenyPendingOnAlreadyResolvedRequestIsNoOp` (NULL→false) | PASS |
| AC-11 | Plain Member → access denied on the route | `ManageMembersRouteAccessTest::testPlainMemberGetsAccessDenied` + `testUnprivilegedAuthenticatedUserGetsAccessDenied`; `ManageMembersAccessTest::testPlainMemberLacksAdministerMembers` | PASS |
| AC-12 | Groups-Moderate manages a group never joined | `ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined` (asserts NO relationship exists, THEN permission granted); U live (403/200 personas) | PASS |
| AC-13 | `user.role.groups_moderate.yml` + `group.role.community_group-groups_moderate.yml` exist as specified | `GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape` (site role + `scope: outsider`, `admin: true`, `global_role: groups_moderate`) | PASS (with the outsider correction — see Advisory A-1) |
| AC-14 | Unit tests for the six manager API methods; suite stays green; e2e | `GroupMembershipManagerTest` (8 methods across add/changeRole/changeStatus/remove/approve/deny + 2 no-op) | PASS |
| AC-15 | WCAG 2.2 AA (axe / documented), keyboard, non-color status, clean assembly, t() strings, 50-row pagination | `ManageMembersPageRenderTest` (th-scope, badge text); `ManageMembersPaginationTest::testMemberTablePaginatesAt50RowsAndGuardSeesWholeGroup`; U axe zero serious/critical; assemble-config clean | PASS |

Every AC-1..AC-15 is pinned by at least one specific, non-vacuous, currently-GREEN test. No AC is
asserted only in a handoff narrative.

**Previously-buggy tests re-checked for genuineness (not vacuous/tautological):**
- **allowed_values-shape fixes** (`GroupMembershipManagerKernelTest`, `ManageMembersPageRenderTest`,
  `ManageMembersPaginationTest`): now pass the simple `[value => label]` shape to
  `FieldStorageConfig::create()`; the tests still assert real membership/status/pagination behavior
  and would fail if that behavior regressed. Genuine.
- **route-resolution** (`ManageMembersRouteResolutionTest`): asserts the actual
  `router.no_access_checks->matchRequest()` resolves to `do_group_membership.manage_members` AND the
  old View's markers (`views-view-table`, `.view-group-members`, "View member") are absent, AND the
  live tab click lands on the new form. Would fail (and did, as a real RED) if the collision
  reappeared. Genuine and strong — this is the test class whose absence let the collision slip
  through the earlier gates.
- **th-scope** (`ManageMembersPageRenderTest::testMemberListRendersAsRealTableWithScopedHeaders`):
  asserts `table th[scope="col"]` exists; the production `#header` uses `['data' => …, 'scope' =>
  'col']` ×5. Would fail if reverted to bare string headers. Genuine.

## Precise-spec conformance findings

- **Three roles carry the exact permission lists.** Verified against shipped source:
  - `group.role.community_group-organizer.yml`: `scope: individual`, `admin: false`, `edit group` +
    `administer members` + the full 20× view/create/update-own/delete-own across documentation/
    event/forum/page/post. Matches AC-1 exactly.
  - `group.role.community_group-moderator.yml`: `scope: individual`, `admin: false`, `administer
    members` + 5× `view group_node:* entity` only. No `edit group`, no content CRUD. Matches AC-2.
  - `community_group-member.yml`: untouched — absent from the diff entirely (git-confirmed).
- **Scope insider→outsider correction is present and genuinely correct.**
  `group.role.community_group-groups_moderate.yml` ships `scope: outsider`, `admin: true`,
  `global_role: groups_moderate`, with a real config dependency on `user.role.groups_moderate`. The
  correctness is empirically pinned by `ManageMembersAccessTest::
  testGroupsModerateUserManagesGroupTheyNeverJoined`, which first asserts the account is NOT a member
  (`getMember()` falsy) and THEN asserts `hasPermission('administer members', …)` is TRUE — a
  synchronized global role granting on a non-member group, exactly per Group 4.x's
  `GroupPermissionChecker::hasPermissionInGroup()` (OUTSIDER scope selected when
  `GroupMembership::loadSingle()` is falsy). This is the correct mechanism; `scope: insider` cannot
  satisfy AC-12 by design. See Advisory A-1 for the stale brief text.
- **`field_membership_status` shape + state machine.** list_string active/pending/blocked;
  transitions enforced in the single `GroupMembershipManager` (approve = set active; deny = delete;
  block/unblock = symmetric setValue; remove = delete at any status; changeRole mutates `group_roles`
  only). Pinned at Kernel tier against real entities.
- **Last-Organizer guard counts the WHOLE group, not a page slice (AC-9).**
  `GroupMembershipManager::assertNotLastOrganizer()` and `ManageMembersForm::countActiveOrganizers()`
  both iterate `$group->getMembers([ORGANIZER_ROLE_ID])` (whole group), independent of the
  `array_slice()` page window. `ManageMembersPaginationTest` proves the guard note is absent on both
  page 1 and page 2 of a 55-member group with a second Organizer on page 2. Confirmed.
- **Real 50-row pagination (AC-15 / W-2).** `PagerManagerInterface::createPager(count($all), 50)` +
  `array_slice(..., $current_page * 50, 50)` — real pagination over the full count, the exact fix for
  the diff-gate B-1 BLOCK. Pinned by `ManageMembersPaginationTest` (50 rows page 1, pager element
  present, 5 rows page 2).
- **`<th scope="col">` on the header (AC-7/AC-15).** Five array-cell headers with `'scope' => 'col'`;
  the fix ships from SOURCE (`docs/groups/…/ManageMembersForm.php`) after the Phase-8.6 catch that F
  had first edited the assembled artifact. Confirmed 5 occurrences in source.
- **WCAG 2.2 AA.** Non-color status conveyed by glyph (`aria-hidden`) + always-visible text label +
  `data-state`/modifier class; last-Organizer guard uses `aria-describedby`; U's live axe pass found
  zero serious/critical. Confirmed.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| Access / security (Drupal) | PASS | Real permission check — `$group->hasPermission('administer members', $account) OR $account->hasPermission('administer group')`; NO `_access: 'TRUE'` anywhere. Access result carries cacheability (`cachePerPermissions`/`cachePerUser`/group dep). Add-member validates duplicate + Drupal-blocked account. |
| Config / schema | PASS | list_string field uses core-provided `options`/`field` schema (no custom config type → no `config/schema/` entry required). Field config matches two existing shipped list_string fields byte-for-byte in format. Every new config has proper `dependencies:`. |
| Error handling | PASS | Typed exceptions (Duplicate/Blocked/LastOrganizer) surfaced as form errors; approve/deny race is a NULL→false no-op with a "This request was already handled." warning, not a fatal. |
| UI/UX match to spec | PASS | Matches the approved wireframe (Screen 1 table, badges, add/role/remove/approve/deny, guard). U live-verified. |
| Accessibility | PASS | `<th scope="col">`, non-color badges, `aria-describedby` guard note, real buttons, focus-visible CSS; axe zero serious/critical (U). |
| Architecture gate | PASS | A-plan PASS + A-dup PASS. |
| Code organization | PASS | Manager service centralizes all mutation (Services-over-Hooks per playbook); controller is access-only; forms delegate. Readable, no dead code / stray TODOs. |
| Docs (Keystatic-editable, links) | N/A | No docs-site content in this story. |
| Naming consistency | PASS | `do_group_membership`, `community_group-{organizer,moderator,groups_moderate}`, `groups_moderate`, `field_membership_status`, route `do_group_membership.manage_members` — all snake_case / Drupal config-ID conventions, consistent with the brief and siblings. |
| Test quality | PASS | See below. |

**Test-quality assessment (rubric applied):**
- **Per-test:** each names one behavior, sits at the cheapest sufficient tier (config-shape at Unit
  via YAML read; manager contract at Unit with mocks; real-entity behavior at Kernel; route
  access/resolution/render/pagination at Functional), and asserts behavior not implementation. The
  Unit manager tests deliberately do NOT mock the last-Organizer count chain (which would encode
  implementation) — AC-9 lives at Kernel with real entities, an explicitly reasoned, correct tier
  choice.
- **Positive AND negative cases present:** Organizer/Moderator/site-admin get 200 vs plain-Member/
  unprivileged 403; remove-last-Organizer refused vs remove-when-second-Organizer-remains allowed;
  Groups-Moderate granted while explicitly non-member.
- **Proportionality:** 41 tests for a new module spanning config, a service, four forms, access,
  routing, rendering, pagination, and a11y — proportionate, not padded.
- **Minor, non-blocking nit (advisory only):** in the two `expectException` guard tests
  (`testRemoveMemberRefusesLastOrganizer`, `testChangeRoleRefusesToDemoteLastOrganizer`) the
  assertions written after the throwing call are unreachable — harmless PHPUnit idiom; the
  `expectException` is the real, correct assertion. No "delete or merge" findings; no
  assertion-free/tautological/duplicate-signal/coverage-padding tests found.

## Scope check
F delivered exactly the phase scope. Diff is additive `docs/groups/` module + role/field config,
plus the single coordinator-settled removal of the `page_1` display from
`views.view.group_members.yml` (supersession, not a route-priority hack; belt-and-suspenders
`hook_install`/`hook_modules_installed` strip for the contrib-shipped optional-config source).
git-confirmed **zero** touches to `do_chrome/PermissionMatrix.php`, the #121/#134 vestigial-role
files (`anon_view`/`outsider_view`/`insider_view`), `community_group-member.yml`, or `grequest`. No
join-flow implemented. No over- or under-delivery.

## Verdict

**PASS** — all 15 acceptance criteria are met and pinned by specific, non-vacuous, GREEN tests; the
implementation is spec-compliant against the shipped source; access/security, config, error
handling, a11y, naming, and test quality are all acceptable; scope discipline held. Ready for O to
open the MR.

The one spec-vs-shipped divergence (brief AC-13/[B-5] says `scope: insider`; shipped config is
`scope: outsider`) is a **clean, well-documented, empirically-verified correction**, not an
unreconciled contradiction — the shipped config is authoritative and correct, the brief text is
stale. This is a PASS-with-advisory (Advisory A-1), NOT an ADVISORY-HOLD: F faithfully implemented
the *correct* mechanism, T independently adjudicated it against real Group 4.x source + an empirical
DB flip, and the correction is documented in `decisions.md`. Downgrading to ADVISORY-HOLD would
wrongly pause a conforming pipeline over an upstream doc typo the team has already resolved.

## Advisories (carry into the PR description)

- **A-1 (stale brief text — MUST document in PR):** The brief's AC-13 / Round-1 [B-5] specify
  `scope: insider` for `group.role.community_group-groups_moderate.yml`. The shipped config and the
  two adjudicating tests use **`scope: outsider`**, which is the empirically-correct value: Group
  4.x's `GroupPermissionChecker::hasPermissionInGroup()` selects the INSIDER scope item only for
  actual members, so a synchronized global role that must act on groups the user has NOT joined
  (the Groups-Moderate case, AC-12) can only be granted via the OUTSIDER scope. Verified against
  `git.drupalcode.org/project/group` @ 4.0.x and a real MySQL Kernel flip (insider → FAIL, outsider
  → PASS). The PR description should note this insider→outsider correction with that rationale; the
  shipped `scope: outsider` is authoritative.
- **A-2 (route-collision root cause — document in PR):** `/group/{group}/members` collided with the
  stock `views.view.group_members` `page_1` display from TWO sources: this project's own site config
  (fixed by deleting the display from `docs/groups/config/views.view.group_members.yml`) AND
  `drupal/group` contrib's `config/optional/views.view.group_members.yml` (fixed by
  `do_group_membership.install`'s `hook_install`/`hook_modules_installed` strip + same-request
  `router.builder->rebuild()` + a `views` module-exists guard). Both layers ship; document the
  two-source collision and the supersession decision.
- **A-3 (optional cleanup, non-blocking — A-dup finding):** `ManageMembersForm` duplicates the
  manager's Organizer-count read for UI-only disable-before-attempt because the manager's count
  helper is `protected`. The manager's `assertNotLastOrganizer()` remains the sole server-side
  enforcement path (duplicate READ, not duplicate ENFORCEMENT). Low-priority follow-up: promote the
  count helper to `public` and have the form call it. Not required for merge.
- **A-4 (minor test nit, non-blocking):** two `expectException` guard tests have unreachable
  assertions after the throwing call — harmless; can be tidied whenever those files are next touched.
- **A-5 (env/process note, not a code issue):** F's earlier accidental `docker rm -f o119-mysql`
  (an unrelated sibling container) is recorded in `decisions.md` and does not affect this story's
  code; flagged so O can confirm #119's owner re-established its container if needed.
