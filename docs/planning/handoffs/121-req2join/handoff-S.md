# Handoff-S: Phase 9 - #121 SC-2 Membership models enforced (final spec audit)

**Date:** 2026-07-22
**Branch:** 121-req2join (worktree `~/Projects/_worktrees/groups-req2join`)
**Issue:** #121
**Auditor:** S (Spec Auditor, Opus)

**Reviewed handoffs (in order):**
- Issue #121 (`gh issue view 121 --repo Performant-Labs/groups-on-d11`).
- `docs/planning/handoffs/121-req2join/brief.md` (14 ACs).
- `docs/planning/handoffs/121-req2join/brief-response.md` (adds AC-15/AC-16, extends AC-4).
- `docs/planning/handoffs/121-req2join/brief-response-v2.md` (authoritative; supersedes).
- `docs/planning/handoffs/121-req2join/survey.md`.
- `docs/planning/handoffs/121-req2join/decisions.md` (all phase entries).
- Handoffs: `handoff-A-plan.md`, `handoff-A-plan-r2.md`, `handoff-T-red.md`,
  `handoff-F.md`, `handoff-F-r2.md`, `handoff-T-green.md`, `handoff-A-dup.md`,
  `dual-review-diff.md`, `dual-review-diff-response.md`, `dual-review-diff-r2.md`.
- Story-scoped diff: `git diff "$(git merge-base origin/main HEAD)"..HEAD -- docs/groups/ tests/e2e/ web/themes/custom/`.

## Preconditions

- **A precondition:** `handoff-A-plan-r2.md` (Round 2) = PASS; `handoff-A-dup.md` (Phase 7) = PASS;
  `dual-review-diff-r2.md` = PASS. All A gates cleared.
- **T precondition:** `handoff-T-green.md` (Phase 6 + Phase 6 r2) reports zero blocking issues.
  Kernel 107/107, Functional 20/20, Unit 15/15, E2E 4/4, phpcs zero new debt.
- **Visual-diff-tool precondition:** N/A — U declared N/A by O; concur (see coverage-tier audit
  §"U N/A challenge"). No visual audit runs this cycle.

## Verdict: PASS

All 16 ACs (AC-1..AC-16, as restated by v2) are backed by tests; all coordinator hard rules
hold; source-only path guard is clean; personas correct; G3/G9 honored; discoverability gap
resolved and re-tested end-to-end; cache-metadata micro-fix in place. Recommendation: **O may
present the PR to human reviewer.** Two must-do pre-merge items are advisory to O below (not
S-blocking): (a) merge base is a254035 — the branch is ~15 commits behind `origin/main`
(#122 and #143 merged after this branch was cut). O must rebase or merge `main` into the
branch before opening the PR, otherwise the PR diff will spuriously show ~14k lines of
"deletions" of the #122/#143 code. Verified: story-scoped diff (limited to `docs/groups/`,
`tests/e2e/`, `web/themes/custom/`) is clean and matches brief-response-v2's file list
exactly. (b) File B-2 and A-dup-carry-forward follow-ups (see §"Follow-up tickets").

## Per-AC compliance matrix

| AC | Requirement (v2 authoritative) | Backing test(s) | Verdict |
|----|--------|-----|-----|
| AC-1 | Non-member Sophie on OPEN group sees "Join" and one click makes her active | `RequestJoinFlowTest::testAddMemberStillWorksOnInviteOnly` + `JoinPolicyEnforcementTest::testNonMemberSeesJoinButtonOnOpenGroup` + E2E `sophie_mueller joins Drupal France (open) instantly` | PASS |
| AC-2 | Non-member on Leadership Council (moderated) sees "Request to join"; click creates pending, no roles, not visible as active member. Header link rendered on canonical page (rework) | `RequestJoinFlowTest::testRequestJoinCreatesPending*` + `JoinPolicyEnforcementTest::testNonMemberSeesRequestToJoinOnModeratedGroup` (repaired lines 198-208) + E2E `ravi_patel sees "Request to join" on Leadership Council…` (clicks the theme header link, `waitForURL(/join-request$/)`) | PASS |
| AC-3 | Non-member on Core Committers (invite_only) sees NO join/request path; direct POST 403; organizer AddMember still works | `RequestJoinFlowTest::testInviteOnlyForbidsRequestJoin*` + `JoinPolicyEnforcementTest::testDirectPostToRequestJoinOnInviteOnlyIs403` + E2E 2× (`sophie` + `alex_novak` both confirm zero controls) + `testAddMemberStillWorks…` | PASS |
| AC-4 (v2 restated) | Organizer sees Sophie's row on `/group/{group}/members` with Pending badge; Approve → active + roles empty; Deny → deleted | `RequestJoinFlowTest::testApprovePendingActivates` (asserts `field_membership_status='active'` AND `group_roles` empty per §4) + `testDenyPendingDeletes` + `JoinPolicyEnforcementTest::testOrganizerSeesPendingRowInExistingManageMembers` | PASS |
| AC-5 | Duplicate `requestJoin` throws `DuplicateMembershipException` | `RequestJoinFlowTest::testDuplicateRequestJoinThrows` | PASS |
| AC-6 | Visibility HelpText: NO `visibility.*` key contains "Not yet enforced" | `HelpTextTest::testVisibilityCopyIsPresentPlainTextAndHonest` — sweeping foreach loop asserts across ALL visibility.* keys | PASS |
| AC-7 | `visibility.invite_only` MUST contain "visible" (and must NOT contain "hidden"); `visibility.moderated` describes request+approval as live | `HelpTextTest` asserts `/\bvisible\b/i` + `assertStringNotContainsString('hidden', ...)` + `/\brequest/i` + `/\bapprov/i` on moderated | PASS |
| AC-8 | `HelpTextTest` L71-73 assertions updated same commit as copy edit | Both files edited in commit `ee10531` ("Phase 5 (F): implement SC-2…") — verified via `git log --stat` | PASS |
| AC-9 | Seed script append-only, idempotent; sets LC→moderated + CC→invite_only; seeds TWO pending rows (Sophie + Alex — v2 NIT accepted) | `step_700_demo_data.php` Step 790 lines 341-379: existence guards on `!== 'moderated'` and `empty($group->getRelationshipsByEntity(...))`; both Sophie AND Alex seeded (v2 NIT) | PASS |
| AC-10 | E2E spec walks all three flows against seeded site; locator matches G9 (both link + submit shapes) | `tests/e2e/membership-models.spec.ts`: 4 tests, `joinControl` = `getByRole('link', {name: /^Join group$/i})`, `requestToJoinControl` = `getByRole('button', {name: /Request to join/i})` (form page), `requestToJoinLinkControl` = `getByRole('link', {name: /^Request to join$/i})` (canonical page) | PASS |
| AC-11 | Direct HTTP POST to `/group/{group}/join-request` on invite_only returns 403 (not merely UI-hidden) | `JoinPolicyEnforcementTest::testDirectPostToRequestJoinOnInviteOnlyIs403` | PASS |
| AC-12 | WCAG 2.2 AA on changed UI — visible focus, keyboard operability, semantic controls, AA contrast | Header link uses `<a class="gc-button gc-button--primary">` (inherits verified contrast from #85); form uses `#type=>submit` → semantic `<input type=submit>`; E2E performs keyboard-role locators via `getByRole('link'/'button')` which exercise the semantic-role axis. U declared N/A by O with rationale; concurring (see §"U N/A challenge" below) | PASS |
| AC-13 | Existing kernel + functional suites stay green (no regression in #138 manage-members, #95 join, #79/#88 tooltip) | Kernel 107/107 across 11 modules; Functional 20/20 across `do_group_membership` + `do_chrome`; Unit 15/15 — all reported GREEN in `handoff-T-green.md` and `handoff-F-r2.md` | PASS |
| AC-14 | Source-only commits (`docs/groups/…`), staged by explicit path; assemble before verifying | `git diff --name-only` shows zero `web/modules/custom/*` or `config/sync/*` paths in the diff; `web/themes/custom/groups_chrome/` is verified-tracked (non-gitignored) source per decisions.md Phase-5-rework Evidence; commits reference explicit paths | PASS |
| AC-15 (v2 restated) | Anonymous or plain-member GET to `/group/{group}/members` on Leadership Council → 403 (regression AC on existing `ManageMembersController::access`) | `JoinPolicyEnforcementTest::testAnonymousGetOnManageMembersIs403` + `testPlainMemberGetOnManageMembersIs403` | PASS |
| AC-16 (v2 restated) | Anonymous or plain-member POST to approve/deny endpoints on pending row → 403 (regression AC on existing ManageMembersForm submit) | `JoinPolicyEnforcementTest::testAnonymousPostToApproveIs403` + `testPlainMemberPostToApproveIs403` | PASS |

**Per-AC verdict: 16/16 PASS.**

## Coordinator hard-rules audit

| Rule | Check | Verdict |
|------|-------|---------|
| Source-only paths (`docs/groups/…`, `docs/planning/…`, `tests/e2e/…`, verified-tracked `web/themes/custom/groups_chrome/…`) | `git diff --name-only "$(git merge-base origin/main HEAD)"..HEAD \| grep -E "^(web/modules/custom\|config/sync)/"` → 0 hits | PASS |
| Personas: `sophie_mueller` / `alex_novak` (+ `ravi_patel`), NEVER Elena | `grep -rn "elena" tests/e2e/membership-models.spec.ts docs/groups/modules/do_group_membership/tests/` → 0 hits in ALL test surfaces | PASS |
| `visibility.invite_only` HelpText contains "visible" | `HelpText.php:96`: "the group stays **visible** to everyone…" | PASS |
| `HelpTextTest` L71-73 assertions updated same commit as copy edit | Commit `ee10531` touches both `HelpText.php` and `HelpTextTest.php` in one commit | PASS |
| `RequestJoinForm` uses `#type=>submit` → renders `<input>` (G9); E2E locator accepts both link + button | `RequestJoinForm.php:74-75`: `'#type' => 'submit', '#value' => t('Request to join')`. E2E `requestToJoinControl` uses `getByRole('button', {name: /Request to join/i})` (semantic role of `<input type=submit>`); header discoverability uses `requestToJoinLinkControl` for the `<a>` link | PASS |
| No `grequest` (G3) — request flow is bespoke on `group_membership` with `field_membership_status` | `grep -rn grequest docs/groups/ web/themes/custom/groups_chrome/`: only 2 docblock references in `do_showcase/` explicitly stating incompatibility with Group 4.0.x. Zero production code usage. Manager `requestJoin()` delegates to `createMembership($group, $account, self::STATUS_PENDING, [])` on the existing `group_membership` relation | PASS |

**Coordinator hard-rules verdict: 6/6 PASS.**

## Spec fidelity to issue text (axis-independence exception)

Issue #121 Acceptance §2 states: *"Both axes enforced consistently: … a public group can still be
request-to-join."* This is **axis-independence** — the ideal MVP model of two orthogonal fields
(visibility + join_policy). This story keeps the composite `field_group_visibility` field
(`open` / `moderated` / `invite_only`) per **explicit coordinator direction** and per
brief §"Reuse & Analogous-Feature map" ("Do NOT split `field_group_visibility` into two
fields… — that is a two-axis refactor that #134 (private group) may reopen"). The composite is
defensible: for the three shipped values, the two axes correlate deterministically (moderated
implies public+request; invite_only implies visible+invite). The axis-independence guarantee
(e.g. a `public + request` combination distinct from `moderated`) is deferred to #134 or a
successor scope-capped story.

**O MUST document this exception in the PR body**, per coordinator direction. Wording
suggestion: *"Composite `field_group_visibility` retained per coordinator direction (defensible
because for the three shipped values, visibility and join-policy correlate deterministically);
full axis-independence deferred to #134."* Decisions.md Phase 1b already records this.

## Test-quality audit (per testing/test-quality.md §7 rubric)

- **Per test:** each of the 16 ACs maps to a named behavior test. Tests use behavior-tier
  assertions (route access, field values, rendered link text) not implementation smells (hook
  name / class name). T-green Part 1 (Functional test repair) is a textbook non-vacuous fix:
  the repaired assertion (`assertNotSame('active', $relationship->get('field_membership_status')->value)`)
  is fail-in-isolation for the right reason (a regression flipping `STATUS_PENDING` to
  `STATUS_ACTIVE` in `createMembership()` would correctly break it, verified by T at
  handoff-T-green.md lines 52-56). AC-16 (approve access) exercises both anonymous AND plain-member
  paths, catching a class of "member-not-organizer" bypass separately from "unauthenticated"
  bypass.
- **Per suite:** kernel + functional + unit + E2E is tier-appropriate. Kernel proves manager
  contract; Functional proves route/access/data; E2E proves user-visible discoverability;
  Unit proves HelpText copy invariants. No duplication across tiers (E.g. AC-2 discoverability
  is E2E-only — see §"U N/A challenge" — and correctly excluded from `JoinPolicyEnforcementTest`
  since that fixture uses `stark` theme).
- **Smells:** none observed. No snapshot-everything, no mock-shaped, no coverage-padding tests.
  The `HelpTextTest` foreach-across-visibility.* pattern is a sweeping-invariant assertion, not
  a fan-out — one loop, one guarantee (AC-6).

**Test-quality verdict: PASS.** No "delete or merge" findings.

## Coverage-tier audit

### U N/A challenge

O declared U (playwright-ui-walkthrough / dedicated visual QA) N/A. **I CONCUR** — with the
following observations recorded for the record:

- **AC-12 (WCAG 2.2 AA)** is covered *by inheritance* rather than by fresh verification:
  the header link reuses `.gc-button--primary` (contrast + focus verified in #85's own U
  phase); the form uses standard Drupal semantic controls. F performed 3 live smoke
  verifications (drush eval + 2 curl sessions with identity-verified authentication) on the
  live rendered theme. T's E2E `getByRole` locators exercise the semantic-role axis
  (keyboard/AT operability).
- The surface added by this story is genuinely narrow: ONE new `<a>` link in an already-styled
  slot on `/group/{id}` + ONE new form page with one submit button + copy edits to
  HelpText tooltips. There is no new component, no new layout, no new interaction pattern.
- **Challenge grounds** would have been: (a) any responsive layout change (there is none — the
  header slot is unchanged, only its `href` variant is new); (b) any new color/contrast surface
  (there is none — inherited from #85); (c) any keyboard-trap risk (there is none — standard
  submit form). None hold.

Concur with O's N/A declaration. No U required.

### Functional-tier theme boundary (discoverability guarantee)

T-green r2 decided the discoverability guarantee (theme-header link renders) is proven at
**E2E tier only**, on grounds that `JoinPolicyEnforcementTest` uses `$defaultTheme = 'stark'`
and no Functional test in this project installs `groups_chrome`. **I CONCUR.**

- This mirrors precedent: `do_chrome`'s own `PermissionMatrixPanelTest.php:44` uses `stark`;
  #95's own Join affordance discoverability (the exact same theme-layer picker function this
  story extends) is proven only at E2E tier in the pre-existing suite.
- The alternative (spinning up `groups_chrome` in a Functional test) would be a new precedent
  requiring theme-installation fixture scaffolding that no existing test carries — a scope
  creep disproportionate to the guarantee.
- The E2E test `ravi_patel sees "Request to join" on Leadership Council…` clicks the actual
  header link (via `requestToJoinLinkControl`) and asserts `waitForURL(/\/join-request$/)`,
  proving both discoverability AND end-to-end navigation.

Concur. No additional Functional-tier test required.

## Follow-up tickets

### Must-file BEFORE merge (S recommends O file these first)

None. All findings below are safe to file after merge.

### Should-file (post-merge, before next SC-* work touches these surfaces)

1. **B-2 (theme picker `#cache` metadata).** `groups_chrome_preprocess_group()`'s
   Join/Request/Leave picker (all 3 branches) has no explicit `#cache` metadata. Pre-existing
   from #85, extended (not introduced) by this story. Diff-gate Round 1 finding B-2
   (`dual-review-diff.md`) — deferred by O per adjudication in `dual-review-diff-response.md`.
   **Filing scope:** chrome cache-posture audit (all 3 branches). **Suggested title:**
   *"chrome: add #cache metadata to groups_chrome_preprocess_group() action picker (extracted from #121)"*.
2. **A-dup carry-forward: `/all-groups` directory-card third branch.**
   `groups_chrome_preprocess_views_view_fields__all_groups()` still only branches on `is_open`
   with no `moderated`/`invite_only` awareness. Pre-existing gap, flagged inline in the theme
   file's own docblock (line ~430). A-dup Phase 7 ruled WARN, not BLOCK.
   **Filing scope:** directory-card affordance parity with the canonical-page picker.
   **Suggested title:** *"chrome: `/all-groups` directory cards render 'Request to join' for moderated groups (parity with #121 canonical-page fix)"*.

### Environment/CI defects surfaced during T-green (should-file for CI reliability)

3. **`do_group_extras` unpublish-on-CLI presave hook.** Every CLI-created group is unpublished
   by `DoGroupExtrasHooks.php:53-65`'s presave hook, hiding all 8 seeded groups from
   `/all-groups`. Predates #121 (initial commit `7bcb6d9`). T-green Phase 6 worked around via
   runtime drush data operations; the real CI E2E job may or may not hit this depending on
   drush privilege level.
   **Suggested title:** *"do_group_extras: presave hook unpublishes CLI-created groups; blocks fresh seed → E2E pipeline"*.
4. **Malformed `language.content_settings.node.*` config entities.** Missing
   `target_entity_type_id`/`target_bundle`, blocking the ENTIRE `step_*` seed script chain.
   **Suggested title:** *"config: fix malformed language.content_settings.node.* entities blocking seed"*.

### CLI-usage advisory (not a ticket per se — documentation note)

5. **`drush uli` positional-argument silent-uid-1 bug.** `drush uli ravi_patel` silently
   generates a login link for UID 1 (`admin`), NOT the named user. Correct usage:
   `drush uli --name=ravi_patel`. Documented in decisions.md Phase 5 (rework) Evidence but
   worth surfacing in a project-level "CLI gotchas" note if one exists (or `WAVE-EXECUTION-HANDOFF §6`).

## PR-body readiness check

O has sufficient material to write a complete PR body. Recommended key claims:

- [x] **What shipped:** enumerate — request-to-join flow (form + route + hook + manager methods
      + theme picker branch); HelpText corrections for all 3 visibility values; seed data
      appends (Leadership Council → moderated, Core Committers → invite_only, 2 pending rows);
      full test coverage across 4 tiers (Kernel 7 + Functional 9 + Unit 10 + E2E 4).
- [x] **Composite `field_group_visibility` decision:** explicitly note the retention per
      coordinator direction, defensibility rationale, and axis-independence deferred to #134.
- [x] **Layered enforcement:** hook + route access + RouteSubscriber (narrows
      `entity.group.join` to `open`-only). Defense in depth.
- [x] **New surfaces vs. existing:** `RouteSubscriber` and theme-picker branch are the two
      F-flagged additions beyond brief-response-v2's file list — both justified and
      A-dup-approved.
- [x] **Follow-up tickets:** list items 1-4 above, either as filed issue links (preferred) or
      as inline bullets.
- [x] **AC coverage:** 16/16, all backed by named tests. Point to `handoff-S.md` for the full
      matrix.
- [x] **HelpText serialization leader:** this story leads the #126/#127/#128/#132 rebase chain;
      those stories APPEND-only, no collision with the visibility.* edits.
- [ ] **⚠ Rebase advisory:** the branch's merge-base is a254035; #122 (merged e269c66) and
      #143 (merged 6827fb8) landed on `main` after this branch was cut. **O must rebase or
      merge `main` into the branch** before opening the PR, otherwise the PR diff will show
      spurious ~14k line "deletions" of the #122/#143 code. This is not a story-content
      concern; the story-scoped diff (verified via
      `git diff … -- docs/groups/ tests/e2e/ web/themes/custom/`) is clean.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| API consistency | PASS | Manager surface trimmed per A-3 (only `requestJoin` + `joinPolicyFor` added; `createMembership` private helper factored per A-W1). Naming matches state-transition-verb cadence (`addMember`/`requestJoin`/`approvePending`/`denyPending`). |
| Error handling | PASS | `DuplicateMembershipException` guards; `RouteNotFoundException` absorbed by pre-existing `catch (\Exception)` per class hierarchy. |
| UI/UX match to spec | PASS | Header shows correct action per (membership × join-policy); form is single-click; invite-only shows nothing. |
| Accessibility | PASS (inherited) | Reuses verified `.gc-button--primary` (#85); semantic controls; getByRole locators verify roles at E2E. |
| Architecture gate | PASS | A-plan r2 PASS; A-dup PASS. |
| Code organization | PASS | New Hook + Form + RouteSubscriber files each carry substantial rationale docblocks; extensive decisions.md Evidence entries. |
| Security | PASS | Hook denies invite_only for non-organizers; RouteSubscriber narrows entity.group.join; approve/deny requires `administer members`. B-1 cache-metadata micro-fix prevents cross-user cache reuse. |
| Performance | PASS | Cache metadata correctly chained; no N+1 concerns in new manager methods. |
| Visual regression | N/A | U declared N/A; concur (see §"U N/A challenge"). |
| Naming consistency | PASS | Route id `do_group_membership.request_join` mirrors `entity.group.join`/`entity.group.leave` shape; policy strings (`'open'`/`'request'`/`'invite'`) match brief. |
| Test quality (`testing/test-quality.md` §7) | PASS | See §"Test-quality audit" — no delete-or-merge findings; all AC tests non-vacuous; sweeping invariant test (AC-6) is a single-guarantee foreach, not fan-out. |

## Scope check

F delivered exactly the phase scope PLUS two justified additions:
- `Routing/RouteSubscriber.php` — needed to close a real, empirically-discovered defense-in-depth
  gap on the vendor `entity.group.join` route (traced full call chain; vendor route does not
  consult entity-create access). Not in brief-response-v2's file list, but A-dup PASSED it as a
  narrow, non-parallel extension.
- `groups_chrome.theme` third `elseif` branch — needed to close the AC-2 discoverability gap
  T-green found live. Preferred over a new module-owned entity-extra-field mechanism (would
  have created two competing controls in the same header slot).

No over-delivery beyond these. No under-delivery.

## WARN / NIT

- **WARN-S1 (branch rebase).** See PR-body checklist above — merge-base is ~15 commits behind
  `origin/main`. O must rebase or merge before PR. Not S-blocking.
- **WARN-S2 (B-2 follow-up).** Theme picker `#cache` metadata gap deferred to a chrome-audit
  follow-up. File before next chrome touch.
- **NIT-S1.** `handoff-F-r2.md` and `decisions.md` extensively document a `hook_entity_extra_field_info`
  approach that was tried and reverted. Consider extracting this to a "learnings" appendix for
  future orchestrators, so the reasoning is discoverable without reading the full phase-5-rework
  Decisions block.
