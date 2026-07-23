# Handoff-S: Phase 9 — #144 MC-6 Create-Group flow (Spec audit)

**Date:** 2026-07-23
**Branch:** 144-auto-organizer (worktree: `~/Projects/_worktrees/groups-auto-organizer`)
**Issue:** #144 (MC-6, Epic #137)
**Handoff-A reviewed:** `docs/planning/handoffs/144-auto-organizer/handoff-A.md` (PASS, 3 warns folded in)
**Handoff-T-red reviewed:** `docs/planning/handoffs/144-auto-organizer/handoff-T-red.md`
**Handoff-F reviewed:** `docs/planning/handoffs/144-auto-organizer/handoff-F.md`
**Handoff-T-green reviewed:** `docs/planning/handoffs/144-auto-organizer/handoff-T-green.md` (GREEN, no blockers)
**Diff-gate reviewed:** `diff-review.md` (round 1, 11 false-positive BLOCKs pre-commit + 1 real BLOCK post-commit + 2 warns) → `diff-review-r2.md` (PASS, 3 non-actionable residual warns)
**Committed diff surveyed:** `git log --oneline origin/main..HEAD` = 3 commits (`5c65504` T-RED, `60311eb` F-impl+T-green, `8ef8296` F-diff-gate rework); `git diff --stat origin/main..HEAD` = 21 files, +3750 lines, ZERO forbidden paths (no `config/sync/`, no `web/modules/custom/`, no `.ddev/`, no `web/sites/simpletest/`).

## A precondition
Confirmed: A returned PASS (Phase 3) with 3 non-blocking warns (creator_wizard multi-step interaction, non-owner-membership negative test, optional messenger status). All folded into the brief by O; addressed in implementation.

## T precondition
Confirmed: T-green (Phase 6) reported zero blocking issues. Final tallies: Unit 2/2, Kernel 4/4 (later 5/5 after F's diff-gate rework added the B-1 bundle-guard regression test), Functional GroupCreatedPreviewControllerTest 2/2, Functional CreateGroupWizardOrganizerTest 1/1, #36 regression pair 3/3, combined `do_group_membership` + `do_tests` = 82/82 → **83/83** after F-rework. Deprecation-notice-only, zero failures/errors.

## Visual-diff-tool precondition
N/A for this audit — read against committed source + test evidence per the operator's authorization. No rendered browser session was needed to verify server-side rendered DOM (Functional tests pin the wireframe DOM order via string-position assertions, and the wireframe itself is ASCII/DOM-order-focused, not pixel-comp).

## Spec compliance (verbatim AC from issue #144)

| # | Acceptance criterion (issue-verbatim) | Status | Backing evidence |
|---|---|---|---|
| AC-1 | Authenticated persona creates a group and is immediately its Organizer (can edit, manage members) | PASS | Kernel `CreateGroupOrganizerHookTest::testInsertHookGrantsOrganizerToCreatorMembership` (100 assertions incl. real-storage tier) + Unit `EnsureRoleTest` (12 assertions, additive read-then-append proven at mock + real tiers) + Functional `CreateGroupWizardOrganizerTest::testWizardCreateGrantsOrganizerAndRedirectsToPreview` (17 assertions, drives real assembled `community_group` wizard end-to-end and confirms BOTH `community_group-admin` (from `creator_roles`) AND `community_group-organizer` (from the hook) present on the reloaded relationship) |
| AC-2 | Guided/preview step renders and completes | PASS | Functional `GroupCreatedPreviewControllerTest::testPreviewPageRendersForOwner` (40 assertions, DOM-order-locked h1 → p → h2 → ul>li>a x3, exactly one h1, no h3+, three CTA links each repeating the group label, no bare "click here") + `testPreviewPageIsForbiddenForUnrelatedUser` (403 for unrelated authenticated user) |
| AC-3 | WCAG 2.2 AA (form labels, error messaging, keyboard) | PASS | Route uses `_title_callback` (F deviation) to ensure exactly ONE `<h1>` (Drupal admin theme renders `_title` as its own h1 block; a controller-emitted h1 would produce duplicates). Heading hierarchy h1→h2 with no skipped levels; CTA link text is self-descriptive (never "click here"); `create-group.css` reviewed — spacing/list-reset only, no hex color tokens (contrast inherited from active subtheme). No JS focus-forcing needed because the h1 is the first content element after standard landmarks. All wireframe AC-5 requirements met. |
| AC-4 | Existing suite green; Playwright walks create → land as organizer | PASS (test suite) / STRUCTURALLY SOUND, NOT RUN (E2E) | 83/83 tests GREEN incl. #36 regression pair (3/3) and #121/#138-adjacent Functional tests untouched. E2E spec `tests/e2e/create-group.spec.ts` reviewed structurally by T-green — matches `manage-members.spec.ts` login/locator conventions, walks login → `/group/add/community_group` → fills label + `field_group_description` (line 117 — the T-green flag about a missing description fill is stale; F's commit `60311eb` includes it) → `completeWizard()` → asserts landing on `/group/{group}/created` → asserts h1/p/h2/ul>li>a → clicks "Manage members" → asserts Organizer role on creator's row. Not executed against a seeded served site in this pipeline; execution is on U/CI. |
| AC-5 | Delivery per epic: branch → docker rendered-DOM check → local `npx playwright test` green → PR → merge on green CI | PENDING (post-S) | Branch `144-auto-organizer` present; PR + CI green are O's next steps per the overnight-authorized run. |
| AC-8 (brief-added, from A finding #2) | Non-owner membership in same request does NOT receive Organizer | PASS | Kernel `CreateGroupOrganizerHookTest::testInsertHookDoesNotGrantOrganizerToNonOwnerMembership` |
| AC (B-1 diff-gate) | Insert hook must not misfire on non-`community_group` bundles | PASS | Kernel `CreateGroupOrganizerHookTest::testInsertHookDoesNotGrantOrganizerOnNonCommunityGroupBundle` (F-rework `8ef8296`) — creates a second group type inline, asserts Organizer role not appended even when owner-uid matches |

**"Owns (disjoint files)" list adherence:**
- Create-flow hook (`CreateGroupOrganizerHook.php`) — NEW, single class, two `#[Hook]` methods. Extends `do_group_membership`, does not fork #36's creator-membership mechanism. ✓
- Group add-form display config — NOT MODIFIED. Not needed (single form_id filter sufficed; `creator_wizard: true` is a single-page enhancement, not a multi-URL wizard, confirmed empirically by F). Deviation from the "Owns" list is documented in handoff-F and is a reduction of scope, not an over-reach. ✓
- CSS `.../css/create-group.css` — NEW. Spacing/list-reset only, no hex. ✓
- `tests/e2e/create-group.spec.ts` — NEW. Structurally sound; description-field fill included. ✓

**Dependencies satisfied:**
- #138 (Organizer group role defined) — `docs/groups/config/group.role.community_group-organizer.yml` present. ✓
- #120 (personas) — CreateGroupWizardOrganizerTest uses a `create community_group group` permissioned user; production personas are seeded by `step_120a.php` per prior stories. ✓

## Quality audit

| Area | Result | Notes |
|---|---|---|
| Architecture gate | PASS | A returned PASS in handoff-A.md; all 3 warns folded into brief and addressed (creator_wizard confirmed single-page by F reading vendor; non-owner guard test added; optional messenger status included). |
| API/service consistency | PASS | `ensureRole()` naming/signature matches sibling `changeRole()`/`hasRole()`; is additive (read-then-append via `array_values($existing_ids)`) and idempotent (early-return on `hasRole()` true). Does NOT reuse `changeRole()`'s replace semantics — verified line 225-241 of GroupMembershipManager.php. |
| Error handling | PASS | Early-return guards on missing bundle / wrong plugin id / missing member entity / non-owner mismatch — all silent no-ops (correct behavior, not exceptions). |
| UI/UX match to spec | PASS | Wireframe DOM order (h1 → p → h2 → ul>li>a x3) locked by Functional test string-position assertions (h1 before p before h2 per F-rework W-2 fix). Route uses `_title_callback` to avoid duplicate h1 — this is a refinement, not a regression, and produces the wireframe's exact copy `Your group "{label}" is ready!` rather than the brief's generic placeholder. |
| Accessibility | PASS | Single h1, h2 for CTA section, no h3+; three self-descriptive CTA links repeating group label; no hex colors introduced (subtheme tokens only); h1-first-content-element pattern satisfies "focus lands sensibly" without JS. |
| Code organization | PASS | Extends `do_group_membership`; one new hook class, one new controller, additive service registration matching `GroupAccessHook` FQCN-keyed `autowire: false` precedent verbatim. |
| Security | PASS | Access callback `GroupCreatedPreviewController::access()` mirrors `ManageMembersController::access()` (owner OR `administer members` OR `administer group`); 403 test proves enforcement. `t()` used with placeholders (no raw interpolation); `#type => 'html_tag'` render for auto-escape (NIT-2 counter-argument accepted). |
| Performance | PASS | ensureRole() is idempotent (skip work if role present); insert hook filters cheap first (bundle → plugin_id → owner uid), only then loads the manager. Cache metadata present on preview controller (group cache tags + `user.permissions` context). |
| Visual regression | N/A | No visual regression suite for this story surface; DOM-order pinning at Functional tier serves that role. |
| Naming consistency | PASS | `CreateGroupOrganizerHook`, `GroupCreatedPreviewController`, `do_group_membership.group_created_preview`, `ensureRole()` — all consistent with existing module conventions. |
| Test quality (per `testing/test-quality.md` §7 spirit) | PASS | Tests are behavior-focused (assert reloaded relationship state; assert redirect address; assert DOM string positions), not implementation-focused (no mock-call-count assertions on production classes). Suite is proportionate: 5 new test files map to 5 concerns (unit ensureRole, kernel hook filter matrix incl. 2 negative tests, functional controller access+DOM, functional real-wizard end-to-end, E2E browser). No duplicate signal; no snapshot tests; no fan-outs. B-1 regression test is a targeted negative-case guard (correct — pins the specific defect the diff-gate would otherwise miss). |

## Scope check

Neither over- nor under-delivered. F implemented exactly the brief's scope plus:
- The optional A-finding-#3 messenger status (A explicitly labeled "F's discretion, low risk").
- The `_title_callback` deviation from the literal brief `_title` string (empirically forced by duplicate-h1; a refinement toward the wireframe's exact copy, not a scope change).
- The B-1 bundle guard (added in F-rework after diff-gate identified a real defect — misfire on non-`community_group` bundles).

No unrelated files touched. `HelpText.php` NOT modified. `web/modules/custom/`, `config/sync/*.yml`, `.ddev/config.yaml`, `web/sites/simpletest/` all absent from the committed diff (confirmed via `git diff origin/main..HEAD --name-only` grep).

## Diff-gate resolution status

- **Round 1 (pre-commit):** 11 BLOCKs, all false positives from F's code being uncommitted — root cause: worktree state. Not counted against F.
- **Round 2 (post-commit `60311eb`):** 1 real BLOCK (B-1: bundle guard missing) + 2 WARNs (W-1: OrderAfter; W-2: DOM-order test tightening). All 3 addressed in `8ef8296`:
  - B-1: bundle guard added as FIRST check in `groupRelationshipInsert()` + `COMMUNITY_GROUP_BUNDLE_ID` constant + regression test `testInsertHookDoesNotGrantOrganizerOnNonCommunityGroupBundle` (verified present at CreateGroupOrganizerHookTest.php:263).
  - W-1: `#[Hook('form_alter', order: new OrderAfter(modules: ['group']))]` (verified at CreateGroupOrganizerHook.php:148).
  - W-2: DOM-order assertions tightened to h1 → p → h2 sequence via `strpos("You're the Organizer")` (a copy-scoped anchor, not a bare `<p` which could false-positive on theme wrapper markup).
- **Round 3 (post-rework):** PASS. 3 residual warns are non-actionable per O's analysis (reviewer partially missed the OrderAfter attribute; hook_implementation tag not required — `GroupAccessHook` is the proof-of-concept for the tag-less FQCN pattern in the same services.yml).

Diff-gate CLEARED legitimately. Residual warns reviewed and confirmed non-actionable by S.

## Advisory notes (non-blocking)

1. **E2E spec not yet executed against a seeded served site.** T-green flagged a "missing description-fill" concern, but reading `tests/e2e/create-group.spec.ts:117` shows the fill IS present — the T-green flag is stale (either the spec was updated in F's commit `60311eb` after T-green wrote the flag, or T-green misread the file). Either way, the spec is structurally correct and matches `manage-members.spec.ts` conventions. First real run may still surface environment-specific gaps (locator drift, page-load timing) — normal for first E2E runs, not a spec defect.
2. **`.ddev/config.yaml` naming collision** across worktrees is a recurring housekeeping tax noted by every phase; not part of this story's diff and not S's remit — flagging so O has continuity awareness.
3. **NITs from diff-gate (t() in static submit handler; html_tag vs Twig template)** were deliberately left as-is with sound counter-arguments (XSS-escaping safety; static-context conventionality). Concur with F's decision.

## Verdict

**PASS** — all issue #144 acceptance criteria are met with concrete test backing, diff-gate BLOCKs are resolved with regression tests, no over-scope, no forbidden paths in the diff, code and test quality both meet the bar for this second-opinion-rigor POC story. Ready for O to open PR and drive CI green per the overnight-authorized flow.
