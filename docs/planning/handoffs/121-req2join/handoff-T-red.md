# Handoff-T-red: Phase 4 - #121 SC-2 Membership models enforced

**Date:** 2026-07-22
**Branch:** 121-req2join (worktree `~/Projects/_worktrees/groups-req2join`)
**Brief / wireframe reviewed:** `docs/planning/handoffs/121-req2join/brief.md`,
`brief-response.md`, `brief-response-v2.md` (authoritative — supersedes conflicts),
`handoff-A-plan.md` (Round 1, BLOCK), `handoff-A-plan-r2.md` (Round 2, **PASS**),
`survey.md`, `decisions.md`. No wireframe artifact exists for this story (A confirmed
this is not blocking — "the plan is UI-simple enough that D-phase output can follow
after the amendment").

## A precondition

Confirmed: A returned **PASS** on the plan at Round 2 (`handoff-A-plan-r2.md`) — all
three Round-1 BLOCKs (A-1 parallel pending-queue surface, A-2 route access idiom
drift, A-3 speculative manager surface) were surgically resolved by
`brief-response-v2.md`. Proceeding to author tests against the v2-amended plan.

## Tests authored

### 1. `docs/groups/modules/do_group_membership/tests/src/Kernel/RequestJoinFlowTest.php`

Kernel-tier (real `group_relationship` entities, not mocks — mirrors
`GroupMembershipManagerKernelTest`'s pattern via `GroupsKernelTestBase`):

| Test | Criterion / behavior pinned | Tier rationale |
|---|---|---|
| `testRequestJoinCreatesPendingRelationship` | AC-2 (kernel half) — `requestJoin()` on a moderated group creates a relationship with `field_membership_status=pending`, no `group_roles` | Kernel: real field-storage persistence, cheapest tier that proves entity state |
| `testApprovePendingFlipsToActiveWithNoRoles` | AC-4 (extended, brief-response §4) — approval flips to active AND assigns no roles | Kernel: state-transition correctness, no HTTP needed |
| `testDenyPendingDeletesRelationship` | AC-4 (second half) — deny deletes the relationship entirely | Kernel |
| `testDuplicateRequestJoinThrows` | AC-5 — duplicate `requestJoin()` throws `DuplicateMembershipException` | Kernel: exception-contract test, no UI needed |
| `testRequestJoinOnInviteOnlyGroupIsForbidden` | AC-3 (kernel half) + AC-11 (kernel half) — entity-create access is forbidden on invite_only | Kernel: asserts on the access-control API directly, not on a specific hook name (A-W2) |
| `testRequestJoinOnOpenGroupUsesJoinPath` | AC-1 (kernel half) — `joinPolicyFor()` returns `'open'`; `addMember()` unregressed | Kernel |
| `testJoinPolicyForReturnsCorrectStringPerVisibility` | classifier contract — `'open' \| 'request' \| 'invite'` per visibility value | Kernel: pure classifier logic |

### 2. `docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php`

Functional-tier (BrowserTestBase, self-provisions, real HTTP — mirrors
`ManageMembersRouteAccessTest`'s pattern):

| Test | Criterion pinned | Tier rationale |
|---|---|---|
| `testNonMemberSeesJoinButtonOnOpenGroup` | AC-1 — the `entity.group.join` route is reachable and one submit makes a non-member active | Functional: real route access + HTTP round trip |
| `testNonMemberSeesRequestToJoinOnModeratedGroup` | AC-2 (functional half) — the request-join route/form creates a pending relationship, not visible as active member | Functional |
| `testNonMemberSeesNoJoinPathOnInviteOnlyGroup` | AC-3 (functional half) — no join/request markup on the canonical page AND the join route itself is 403 | Functional |
| `testDirectPostToRequestJoinOnInviteOnlyIs403` | AC-11 — direct GET/POST to `/group/{group}/join-request` on invite_only is 403 | Functional: proves enforcement is real, not UI-hidden |
| `testOrganizerSeesPendingRowInExistingManageMembers` | AC-4 (authoritative, v2 §A-1) — the EXISTING `/group/{group}/members` + ManageMembersForm surfaces the pending row; Approve/Deny work | Functional: exercises the real form submit round trip |
| `testAnonymousGetOnManageMembersIs403` | AC-15 (restated) — regression on the existing gate | Functional |
| `testPlainMemberGetOnManageMembersIs403` | AC-15 (restated) — regression on the existing gate | Functional |
| `testAnonymousPostToApproveIs403` | AC-16 (restated) — regression on the existing gate | Functional |
| `testPlainMemberPostToApproveIs403` | AC-16 (restated) — regression on the existing gate | Functional |

### 3. `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` (UPDATED)

`testVisibilityCopyIsPresentPlainTextAndHonest()` rewritten (AC-6/AC-7/A-W3):
- AC-6 (sweeping): loops every `visibility.*` key and asserts none contains
  `"Not yet enforced"`.
- AC-7: `visibility.invite_only` copy must contain `/\bvisible\b/i` and must NOT
  contain `"hidden"`.
- Moderated copy must describe both "request" and "approv[al]" as live.
- A-W3: `visibility.field` copy must retain a "join" or "view" distinction.
- `visibility.open` unchanged assertion retained (regression guard).

Tier: Unit (pure string assertions on a static data array — no framework
bootstrap needed, cheapest possible tier).

### 4. `tests/e2e/membership-models.spec.ts`

Playwright, vs the seeded site (personas `sophie_mueller` / `alex_novak`, groups
Drupal France / Leadership Council / Core Committers):
- Sophie joins Drupal France (open) instantly.
- Sophie sees "Request to join" on Leadership Council (moderated), requests it,
  keyboard-focus smoke-checked (WCAG).
- Sophie sees no Join/Request control on Core Committers (invite_only).
- Alex (second non-member persona) also sees no join path on Core Committers.
- Locators use `role=button,name=/Request to join/i` OR
  `input[type=submit][value*=Request]` (G9 belt-and-braces) per AC-10.

RED verification for the E2E spec happens at T-green once a seeded site is spun
up (per task instructions) — no seeded install was stood up in this Phase-4
session. The spec is authored now; its selectors/flow are the contract F
implements against.

## RED confirmation

Environment: `ddev` (`gm121-groups-on-d11`), `composer install` run, config assembled
via `ddev exec bash scripts/ci/assemble-config.sh`, `SIMPLETEST_DB='mysql://db:db@db:3306/db'`,
a `php -S 127.0.0.1:8888 -t web web/.ht.router.php` test-router server for Functional.

### Kernel (`RequestJoinFlowTest`) — command:
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SYMFONY_DEPRECATIONS_HELPER=disabled \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_group_membership/tests/src/Kernel/RequestJoinFlowTest.php
```
Result: **7 tests, 6 Errors + 1 Failure** (all fail for the right reason):
```
✘ Request join creates pending relationship
  Error: Call to undefined method Drupal\do_group_membership\GroupMembershipManager::requestJoin()
✘ Approve pending flips to active with no roles
  Error: Call to undefined method ...::requestJoin()
✘ Deny pending deletes relationship
  Error: Call to undefined method ...::requestJoin()
✘ Duplicate request join throws
  Error: Call to undefined method ...::requestJoin()
✘ Request join on invite only group is forbidden
  Failed asserting that false is true.
  (assertion: $access->isForbidden() — no create-access gate exists yet, so the
  access result is neutral, not forbidden)
✘ Request join on open group uses join path
  Error: Call to undefined method ...::joinPolicyFor()
✘ Join policy for returns correct string per visibility
  Error: Call to undefined method ...::joinPolicyFor()
```

### Functional (`JoinPolicyEnforcementTest`) — command:
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8888' \
SYMFONY_DEPRECATIONS_HELPER=disabled BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browsertest-output \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php
```
Result: **9 tests, 3 Failures, 6 pass** (see note below on the 6 passing tests):
```
✘ Non member sees request to join on moderated group
  Current response status code is 404, but 200 expected.
  (the /group/{group}/join-request route does not exist yet)
✘ Non member sees no join path on invite only group
  Current response status code is 200, but 403 expected.
  (entity.group.join is not yet gated per field_group_visibility — currently
  allowed for everyone, including on an invite_only group)
✘ Direct post to request join on invite only is 403
  Current response status code is 404, but 403 expected.
  (the /group/{group}/join-request route does not exist yet)
```
The other 6 tests (`testNonMemberSeesJoinButtonOnOpenGroup`,
`testOrganizerSeesPendingRowInExistingManageMembers`, both AC-15 tests, both
AC-16 tests) pass **today**, before F writes any code — this is CORRECT and
expected, not an invalid RED: these ACs pin behavior that already exists
(the entity.group.join route from #95, and the existing ManageMembersForm /
`ManageMembersController::access` gate from #138). They are regression guards,
not net-new-behavior RED tests, and they must stay GREEN through F's change
(F's `hook_group_relationship_create_access` gate must not regress #95's open
join, and F must not touch the existing `/members` route's access).

### Unit (`HelpTextTest`) — command:
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php
```
Result: **10 tests, 1 Failure** (fails for the right reason):
```
✘ Visibility copy is present plain text and honest
  "visibility.moderated" copy must not claim to be unenforced (AC-6) — #121
  makes join-policy enforcement live.
  Failed asserting that 'Moderated: the intent is that people request to
  join and an admin approves each request. Not yet enforced on this demo —
  shown to illustrate the model.' does not contain "Not yet enforced".
```
The other 9 tests in this file are pre-existing, unrelated coverage and pass
unchanged (no regression from my edit).

### E2E (`membership-models.spec.ts`)
Authored, not run in Phase 4 (no seeded site was stood up in this session — the
brief and task instructions defer E2E RED verification to T-green/Phase 6, once
F's implementation + a seeded install exist). Selectors documented above.

## Confirmation that failures are for the RIGHT reason

Every genuine RED failure above traces to either:
- a missing method (`GroupMembershipManager::requestJoin()` /
  `::joinPolicyFor()` — `Call to undefined method`), or
- a missing route (`/group/{group}/join-request` → 404), or
- a missing access gate (invite_only's `entity.group.join` currently allowed,
  should be forbidden; the create-access check is neutral, not forbidden), or
- stale copy that still says "Not yet enforced" (HelpText.php not yet edited).

None fail due to autoload errors, fixture bugs, or test-authorship mistakes —
those WERE found during authoring (see "Surprises for F" below) and were fixed
by me before accepting the RED, per the Tester's mandate ("if a test is wrong,
fix the test").

## Ready for F

**Confirmed RED is valid.** F may implement against these tests. The surviving
production-code surface F must add (per `brief-response-v2.md` §"Files to touch
after amendment"):
- `GroupMembershipManager::requestJoin()` + `::joinPolicyFor()` (+ optional
  private `createMembership()` helper per A-W1).
- `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php`
  (`hook_group_relationship_create_access` gate, or the A-W2 documented
  fallback — T does not commit to the hook name).
- `docs/groups/modules/do_group_membership/src/Form/RequestJoinForm.php`.
- `do_group_membership.routing.yml`: ONE new route,
  `do_group_membership.request_join` at `/group/{group}/join-request`.
- `ManageMembersController::requestJoinAccess()` peer method.
- `HelpText.php` visibility.* copy corrections.
- `step_700_demo_data.php` append-only seed changes (2 pending rows).

## Surprises for F (things T could not cleanly test, or had to work around)

1. **`entity.group.join`'s clickable UI affordance is theme-layer, not
   module-layer.** #95's "Join group" link (`gc-directory-card__join`) lives in
   `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`,
   rendered only on the `/all-groups` directory view — it is NOT rendered on
   the group's own canonical page (`/group/{group}`), and the custom theme
   is not wired in a minimal module-level Functional fixture (`stark` theme,
   `group`+`do_group_membership` modules only). `testNonMemberSeesJoinButtonOnOpenGroup`
   therefore asserts on the underlying `entity.group.join` route directly
   (200 + successful submit) rather than a themed link, since that is the
   real enforcement surface AC-1 requires and is tier-appropriate for a
   module Functional test. **F: do not expect a Join link to render on
   `/group/{group}` itself — if the wireframe (once produced) calls for one
   there, that is new theme/template work, not covered by this test.**
2. **`field_membership_status` and `field_group_visibility` are not installed
   by `GroupTestTrait::createGroupType()`/`createGroup()`.** Both Kernel and
   Functional suites must install these fields via the entity API in `setUp()`
   (mirroring the existing pattern in `GroupMembershipManagerKernelTest` /
   `ManageMembersPageRenderTest`) — a seeded `'pending'` status silently
   no-ops without this (the field is unknown on the bundle, and
   `GroupMembershipManager::relationshipStatus()`'s fallback treats an absent
   field as `'active'`). This is already handled in both new test files; F
   doesn't need to do anything extra for this, but should be aware if adding
   further tests against these bundles.
3. **`AccessResult::neutral()->isAllowed()` is also `FALSE`.** The invite_only
   forbidden-access kernel test had to assert `isForbidden()` specifically
   (not merely `!isAllowed()`), because a neutral result (the default, no gate
   implemented) also fails `isAllowed()` — asserting only `!isAllowed()` would
   have been trivially true before F writes any code, an invalid RED. Fixed
   before accepting RED.
4. **`drupalCreateUser($permissions, $name)` signature** — the first
   positional argument is a permissions array, not a values array; passing
   `['name' => 'sophie_mueller_test']` throws `Invalid permission
   sophie_mueller_test`. Fixed to `drupalCreateUser([], 'sophie_mueller_test')`.
5. **Symfony's CssSelector does not support jQuery-style `:contains()` /
   `:has-text()` pseudo-classes** — an earlier draft of
   `testNonMemberSeesJoinButtonOnOpenGroup` used
   `a:has-text("Join"), button:contains("Join")` and threw
   `ExpressionErrorException: Function "has-text" not supported`. Replaced
   with a real CSS selector plus a manual text-match fallback loop.
6. **AC-4/AC-15/AC-16 are legitimately GREEN today**, not RED — see the
   Functional results above. This is expected per v2 §A-1 (the organizer
   surface already exists in `ManageMembersForm`); flagging so F and the
   orchestrator don't mistake "6 of 9 Functional tests already pass" for a
   broken RED. Only 3 of 9 pin genuinely new behavior.

## Per-AC coverage matrix

| AC | Test(s) | Status at RED |
|---|---|---|
| AC-1 (open, instant join) | `RequestJoinFlowTest::testRequestJoinOnOpenGroupUsesJoinPath` (kernel), `JoinPolicyEnforcementTest::testNonMemberSeesJoinButtonOnOpenGroup` (functional), E2E spec 1 | Kernel RED (joinPolicyFor missing); Functional GREEN (regression guard, #95 already live) |
| AC-2 (moderated request-to-join) | `testRequestJoinCreatesPendingRelationship` (kernel), `testNonMemberSeesRequestToJoinOnModeratedGroup` (functional), E2E spec 2 | RED (route/method missing) |
| AC-3 (invite_only no join path) | `testRequestJoinOnInviteOnlyGroupIsForbidden` (kernel), `testNonMemberSeesNoJoinPathOnInviteOnlyGroup` (functional), E2E spec 3/4 | RED (no create-access gate yet) |
| AC-4 (organizer pending queue on existing surface) | `testApprovePendingFlipsToActiveWithNoRoles`, `testDenyPendingDeletesRelationship` (kernel), `testOrganizerSeesPendingRowInExistingManageMembers` (functional) | Kernel RED (requestJoin missing to seed the pending relationship in the first place — approvePending/denyPending themselves already exist and pass in isolation); Functional GREEN (existing form surface, regression guard) |
| AC-5 (duplicate request throws) | `testDuplicateRequestJoinThrows` | RED |
| AC-6 (no "Not yet enforced" in visibility.*) | `HelpTextTest::testVisibilityCopyIsPresentPlainTextAndHonest` | RED |
| AC-7 (invite_only copy contains "visible") | same | RED |
| AC-8 (HelpTextTest updated) | self-referential — this file itself is the update | done (this phase) |
| AC-9 (seed data, 2 pending rows) | not directly unit/kernel-tested (seed script is F's append); E2E spec relies on it existing | N/A for T — F's responsibility; E2E will exercise it at T-green |
| AC-10 (E2E walks all three flows, G9 locator) | `membership-models.spec.ts` | Authored; RED verification deferred to T-green |
| AC-11 (direct POST 403 on invite_only) | `testRequestJoinOnInviteOnlyGroupIsForbidden` (kernel), `testDirectPostToRequestJoinOnInviteOnlyIs403` (functional) | RED |
| AC-12 (WCAG AA) | E2E keyboard-focus smoke check; full axe is U's remit | Partial (T can only smoke-check headlessly) |
| AC-13 (existing suites stay green) | verified at T-green via full Tier-1 run | Deferred to T-green |
| AC-14 (source-only commits) | staged by explicit path this phase (see below) | Done |
| AC-15 (anon/plain-member GET 403 on /members) | `testAnonymousGetOnManageMembersIs403`, `testPlainMemberGetOnManageMembersIs403` | GREEN (regression guard, existing gate) |
| AC-16 (anon/plain-member POST 403 on approve/deny) | `testAnonymousPostToApproveIs403`, `testPlainMemberPostToApproveIs403` | GREEN (regression guard, existing gate) |

## Staged files (explicit paths, source-only)

```
docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php               (modified)
docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php  (new)
docs/groups/modules/do_group_membership/tests/src/Kernel/RequestJoinFlowTest.php            (new)
tests/e2e/membership-models.spec.ts                                        (new)
```

No file under `web/modules/custom/*` or `config/sync/*` was staged (those are
assembled build artifacts from local verification only).
