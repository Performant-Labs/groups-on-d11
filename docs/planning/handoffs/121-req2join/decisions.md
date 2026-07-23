# Decisions — #121 SC-2 Membership models enforced

Append-only. Every phase adds an entry (Decided / Assumed / Hedged / Evidence).
Orchestrator writes the closing Chain Summary at post-merge sweep.

## Phase 1 — Orchestrator: survey + brief (2026-07-22)

**Decided:**
- Slug `121-req2join`; worktree `~/Projects/_worktrees/groups-req2join`; branch `121-req2join` off `origin/main`.
- Namespace containers `gm121-*` if any spawned (E2E job.)
- HelpText edit lands first in this story (this story is the HelpText serialization leader per WAVE-EXECUTION-HANDOFF §3).
- Test personas for the join flow: `sophie_mueller`, `alex_novak` (NOT Elena — she is already a member of both Leadership Council and Core Committers per `step_700_demo_data.php` lines 99, 101).
- Request-to-join is **bespoke** on the customized `group_membership` relationship (status pending → active/deleted). No `grequest` (unusable on Group 4.0.x).
- Manager reuse: `GroupMembershipManager::approvePending()` / `denyPending()` / `addMember()` already exist in `do_group_membership`. New request flow will call a NEW `requestJoin()` method on the same manager (extend, not new class) that creates a `group_membership` relationship with `field_membership_status = pending` and no `group_roles`. Approval reuses `approvePending()`; denial reuses `denyPending()`.

**Assumed (to verify with A):**
- Two-axes reconciliation for THIS story: the existing single `field_group_visibility` field carries both axes for now — `open` = public+open-join, `moderated` = public+request-join, `invite_only` = visible+closed-to-join. Private/unlisted (visibility=private/unlisted axis) is deferred to #134. This preserves back-compat with #95, HelpText #88 copy shape, and the seeded fixtures. If A rejects this, escalate — a full two-field split is a scope-cap that should not land in #121.
- Seed edit: append-only `field_group_visibility` assignments for Leadership Council (`moderated`) and Core Committers (`invite_only`); one pending request seeded (sophie_mueller → Leadership Council). Other groups stay `open` (default_value already `open`).
- E2E persona flow: use existing seeded logins (sophie/alex) rather than fresh registration.

**Hedged:** Group access enforcement path for `invite_only` (no `join group` grant → the outsider_view role's `join group` permission is stripped/gated per-visibility). Two candidate paths: (a) split into two group roles + assign per visibility (heavy), or (b) hook_group_access alter on `group_membership.create` gated on the group's `field_group_visibility` (lighter). Recommend (b) — matches "extend not new" and does not touch the shared outsider_view role config. Flagging for A.

**Evidence:**
- `gh issue view 121` re-read at survey start.
- `docs/groups/modules/do_chrome/src/HelpText.php` lines 84–87 (current visibility copy).
- `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` lines 71–73 (assertions that turn RED).
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` (manager already carries approvePending/denyPending — reuse).
- `docs/groups/scripts/step_700_demo_data.php` lines 99 + 101 (Elena is member of both closed groups — cannot exercise flows).
- `docs/groups/config/group.role.community_group-outsider_view.yml` (holds `join group` grant — the surface to gate).

## Phase 1b — Orchestrator: brief-gate dual-review (2026-07-22)

**Decided:**
- Coordinator direction executed: composite `field_group_visibility` retained; axis-independence AC deferred to #134 (documented in PR body).
- Ran `dual-review.sh --mode brief` (o4-mini). Round 1 = BLOCK (5 findings).
- Amended brief via `brief-response.md` addendum §§1–6: hook mechanism named (`hook_group_relationship_create_access`), organizer defined (`community_group-organizer` + `administer members`), added AC-15/AC-16 (approve/deny access guards), AC-4 extended (approval assigns no roles), seed idempotency spelled out with existence guards.
- Round 2 = **PASS** — all 5 BLOCKs ACCEPTED by reviewer.

**Assumed (to verify with A):**
- `hook_group_relationship_create_access()` is the correct extension point on Group 4.0.x for gating create. A should validate against the module's actual hook list.

**Hedged:** none — addendum is authoritative and A operates on brief + brief-response together.

**Evidence:**
- `docs/planning/handoffs/121-req2join/dual-review-brief.md` (Round 1 — 5 BLOCK).
- `docs/planning/handoffs/121-req2join/brief-response.md` (adjudication + addendum).
- `docs/planning/handoffs/121-req2join/dual-review-brief-r2.md` (Round 2 — PASS).
- `docs/groups/config/group.role.community_group-organizer.yml` L16 (`administer members` grant confirms B-2 resolution).

## Phase 3 — Architecture Reviewer: plan review (2026-07-22)

**Decided:**
- Verdict: **BLOCK**. Three findings; the plan must be amended before T writes tests.
- A-1 (block): The "pending requests queue" the plan proposes as a NEW `/group/{group}/members/pending` route + local task + `ManageMembersController::pendingQueue()` + `PendingRequestActionForm` is a parallel path — `ManageMembersForm` already renders pending memberships with Approve/Deny row buttons wired to `approvePending`/`denyPending` (see `ManageMembersForm.php:70,134-136,214-234,296-329`). Fix: fold the queue back into the existing surface; re-scope AC-4/AC-15/AC-16 to `/group/{group}/members`.
- A-2 (block): Brief-response §2's claim that the new route uses `_group_permission: 'administer members'` is factually wrong about the "same gate" — every existing `do_group_membership.*` route uses `_custom_access` referencing `ManageMembersController::access` (with a site-admin escape hatch + explicit cache metadata). Fix: correct the wording; new routes reuse `_custom_access` idiom.
- A-3 (block): Manager surface `countPendingFor`/`getPendingFor` proposed in survey has no consumer once A-1 is applied (ManageMembersForm reads via `$group->getMembers()`). Fix: trim to `requestJoin` + `joinPolicyFor` only.
- WARNs recorded (not blocking): W1 factor a shared `createMembership` helper on the manager to prevent `requestJoin`/`addMember` divergence; W2 F must verify `hook_group_relationship_create_access` signature against `vendor/drupal/group` at implementation; W3 `visibility.field` copy must retain view-vs-join distinction; W4 source-only path guard rail confirmed.
- NIT: consider seeding a second pending relationship (alex_novak) so the queue demos >1 row.

**Assumed:**
- Group 4.0.x-dev exposes `hook_group_relationship_create_access` at the signature the addendum implies (accepted on R2 dual-review, but not verifiable from source-only worktree — flagged W2).
- No wireframe artifact exists yet; the plan is UI-simple enough that D-phase output can follow after the amendment. Not blocking.

**Hedged:**
- If the wireframe (once produced by D) genuinely requires a distinct "pending only" landing surface as an organizer shortcut, the correct extension is a query-string filter on the existing route (`/group/{group}/members?status=pending`) or a Views filter — NOT a second controller. That would be reviewed as a WARN, not a BLOCK, at that time.

**Evidence:**
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,134-136,214-234,296-329` (pending queue already live inside the existing form).
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml:1-51` (all four routes use `_custom_access`, none use `_group_permission`).
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php:38-45` (shared access callback + cache metadata contract).
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php:36-297` (manager cadence — state-transition verbs; `approvePending`/`denyPending` already exist and are already called from `ManageMembersForm`).
- `docs/groups/modules/do_chrome/src/HelpText.php:84-87` (visibility copy target — reuse map correct).
- `docs/groups/config/field.storage.group.field_group_visibility.yml:12-23` (composite values retained per coordinator direction).
- Full findings: `docs/planning/handoffs/121-req2join/handoff-A-plan.md`.

## Phase 3 — Architecture Reviewer + Orchestrator amendment (2026-07-22)

**A verdict:** BLOCK (3 findings) — see `handoff-A-plan.md`.

**Decided (O adjudication):**
- A-1 ACCEPTED: parallel pending-queue surface DELETED from plan. Organizer surface = existing `ManageMembersForm` on `/group/{group}/members` (already renders pending rows with Approve/Deny — verified `ManageMembersForm.php:70,80,214-234,296-329`).
- A-2 ACCEPTED: new request-join route uses `_custom_access` (peer `ManageMembersController::requestJoinAccess()`), matching the established idiom (`routing.yml` — all 4 existing routes use `_custom_access`). No `_group_permission` YAML anywhere.
- A-3 ACCEPTED: manager surface trimmed to `requestJoin` + `joinPolicyFor` only. `countPendingFor` / `getPendingFor` dropped (no consumer).
- A-W1 recorded: F factors a private `createMembership()` helper shared by `addMember` + `requestJoin`.
- A-W2 recorded: F verifies hook signature in `vendor/drupal/group/`; T asserts on behavior, not hook name.
- A-W3 recorded: `visibility.field` copy must retain view/join distinction; HelpTextTest asserts it.
- NIT accepted: seed a SECOND pending row (alex_novak → Leadership Council) for row-action isolation demo.

**Amendment authoritative:** `brief-response-v2.md` supersedes conflicting text in prior brief/survey/response docs. Corrected file-touch list is in v2 §"Files to touch after amendment".

**Assumed (to verify with A on re-review):**
- No further duplication surface remains once the pending-queue route is deleted. A re-review will confirm.

**Hedged:** none.

**Evidence:**
- `docs/planning/handoffs/121-req2join/handoff-A-plan.md` (A's full report).
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,80,214-234,296-329` (existing pending-row surface — the parallel path A-1 avoids).
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml:1-51` (established `_custom_access` idiom).

## Phase 3 (Round 2) — Architecture Reviewer re-review (2026-07-22)

**Decided:**
- Verdict: **PASS**. All 3 Round-1 BLOCKs (A-1, A-2, A-3) surgically resolved by `brief-response-v2.md`. No re-amendment needed. Proceed to Phase 4 (T).
- A-1 resolved: parallel pending-queue surface deleted in full (route + local task + `pendingQueue()` controller method + `PendingRequestActionForm.php`); AC-4/AC-15/AC-16 restated against existing `/group/{group}/members` + `ManageMembersForm`.
- A-2 resolved: `do_group_membership.request_join` route uses `_custom_access` via new `ManageMembersController::requestJoinAccess()` peer method that inherits the existing cache-metadata contract; no `_group_permission` YAML.
- A-3 resolved: manager surface trimmed to exactly `requestJoin` + `joinPolicyFor`.
- Two implementation-time notes recorded (non-blocking, not plan-level):
  - A-R2-W1 (warn): `requestJoinAccess()` cache metadata — keep `addCacheableDependency($group)` unconditional; group's cache tag covers `field_group_visibility` mutations.
  - A-R2-N1 (nit): "already a member" short-circuit and `requestJoin` guard should treat pending/blocked as already-member (symmetry with `DuplicateMembershipException` guard shape).

**Assumed:**
- F will honor A-R2-W1/N1 at implementation without a plan amendment. If missed, will re-surface at Phase 7 dup gate.
- `createMembership()` private helper shape (A-W1) intentionally left to F. Amendment does not prescribe signature — correct.

**Hedged:** none.

**Evidence:**
- `docs/planning/handoffs/121-req2join/handoff-A-plan-r2.md` (this round's full report).
- `docs/planning/handoffs/121-req2join/brief-response-v2.md` (amendment reviewed).
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php:38-45` (peer method shape confirmed as faithful mirror).

## Phase 4 — Tester: RED authored (2026-07-22)

**Decided:**
- Authored 4 test files against the v2-amended plan: `RequestJoinFlowTest.php` (Kernel,
  7 tests), `JoinPolicyEnforcementTest.php` (Functional, 9 tests), `HelpTextTest.php`
  (Unit, updated `testVisibilityCopyIsPresentPlainTextAndHonest()`), and
  `membership-models.spec.ts` (Playwright E2E, authored not run).
- Verified RED against the assembled layout (`ddev` at `gm121-groups-on-d11`,
  `SIMPLETEST_DB` pointed at the ddev `db` service, a `php -S` test-router server for
  Functional). 7/7 Kernel tests fail (missing `requestJoin`/`joinPolicyFor` methods,
  or a neutral-not-forbidden access result). 3/9 Functional tests fail for the right
  reason (missing `/join-request` route; `entity.group.join` not yet gated on
  invite_only); the other 6/9 Functional tests are legitimate regression guards on
  pre-existing behavior (#95's open-join route, #138's ManageMembersForm surface) and
  are correctly GREEN already — not an invalid RED, since AC-4/AC-15/AC-16 target that
  existing surface per v2 §A-1. 1/10 Unit tests fails (stale "Not yet enforced" copy).
- Per A-W2: the invite_only-forbidden kernel test asserts on `AccessResultInterface::isForbidden()`
  against the real entity-create access API (`getAccessControlHandler('group_relationship')->createAccess()`),
  not on a specific hook name — satisfies "T must not commit to the exact hook name."
- Fixed 5 test-authorship bugs found during RED authoring (documented in detail in
  `handoff-T-red.md` "Surprises for F"): (1) `AccessResult::neutral()` also fails
  `isAllowed()`, so the invite_only test needed `isForbidden()` specifically to avoid
  a trivial pre-F pass; (2) `field_membership_status`/`field_group_visibility` are not
  installed by `GroupTestTrait::createGroupType()`/`createGroup()` — both Kernel and
  Functional suites install them explicitly in `setUp()`, mirroring
  `GroupMembershipManagerKernelTest`/`ManageMembersPageRenderTest`; (3)
  `drupalCreateUser($permissions, $name)`'s first arg is permissions, not values —
  fixed two calls that mistakenly passed `['name' => ...]`; (4) Symfony's CssSelector
  does not support `:contains()`/`:has-text()` — replaced with a real selector + manual
  text-match fallback; (5) discovered #95's "Join group" clickable affordance is
  theme-layer (`groups_chrome`'s `gc-directory-card__join` link on `/all-groups`), not
  rendered on the group canonical page nor present in a minimal module Functional
  fixture — the open-group AC-1 Functional test asserts on the `entity.group.join`
  route directly (200 + successful submit) instead, which is the real enforcement
  surface and the tier-appropriate assertion for a module-level test.

**Assumed:**
- E2E RED verification is deferred to T-green (Phase 6) once F's implementation +
  a seeded site exist, per task instructions — the spec is authored now as the
  contract F implements against.
- F's theme/template work (if any) for a Join/Request affordance directly on the
  group canonical page, if the (still-absent) wireframe calls for one, is out of
  this test suite's scope — flagged as a surprise for F, not assumed away silently.

**Hedged:** none.

**Evidence:**
- RED run output for all three PHPUnit suites (see `handoff-T-red.md` "RED
  confirmation" for full pasted testdox + exception text).
- `docs/groups/modules/do_group_membership/tests/src/Kernel/GroupMembershipManagerKernelTest.php`
  (Kernel field-install pattern mirrored).
- `docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersPageRenderTest.php`
  (Functional field-install pattern mirrored).
- `docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersRouteAccessTest.php`
  (Functional access-test pattern mirrored for AC-15/AC-16).
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`
  (confirms the Join affordance is theme-layer, directory-view-only).
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` (confirms
  `/group/{group}/join-request` does not yet exist — 404 is the correct RED reason).
- Full report: `docs/planning/handoffs/121-req2join/handoff-T-red.md`.
