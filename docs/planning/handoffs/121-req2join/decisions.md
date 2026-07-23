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

## Phase 5 — Feature Implementor: GREEN (2026-07-22)

**Decided:**
- Hook signature verified directly against core source (not guessed): `EntityAccessControlHandler::createAccess()`
  (`web/core/lib/Drupal/Core/Entity/EntityAccessControlHandler.php:256-259`) invokes
  `$this->moduleHandler()->invokeAll($this->entityTypeId . '_create_access', [$account, $context, $entity_bundle])`
  — for `group_relationship`, this is `hook_group_relationship_create_access(AccountInterface $account,
  array $context, $entity_bundle): AccessResultInterface`, with `$context['group']` carrying the
  target `GroupInterface` (confirmed via `GroupRelationshipAccessControlHandler::checkCreateAccess()`,
  which reads `$context['group']` the same way). Implemented via `#[Hook('group_relationship_create_access')]`
  on `Drupal\do_group_membership\Hook\GroupAccessHook` — the A-W2 fallback
  (`hook_entity_create_access` keyed on plugin_id) was NOT needed; the type-specific hook exists.
- `AccessResult::orIf()` merge semantics confirmed (`processAccessHookResults()` +
  `createAccess()`'s `if (!$return->isForbidden()) { $return = $return->orIf($this->checkCreateAccess(...)) }`):
  a hook `forbidden()` result short-circuits the group_membership relation plugin's own default
  `join group`-permission check; a `neutral()` result falls through to it. This is WHY the hook only
  needs to actively deny on `invite_only` — `open`/`moderated` already pass the default permission
  grant on their own, so `neutral()` there is correct and sufficient (traced through
  `GroupMembershipAccessControl`/`AccessControlTrait::relationshipCreateAccess()` down to the
  `outsider_view` role's `join group` grant).
- Manager: added `requestJoin(GroupInterface, UserInterface): GroupRelationshipInterface` and
  `joinPolicyFor(GroupInterface): string` (`'open'|'request'|'invite'`, defaulting to `'open'` if the
  field is absent/empty — back-compat for pre-#121 groups). Factored private `createMembership(GroupInterface,
  UserInterface, string $status, array $roles): GroupRelationshipInterface` per A-W1; `addMember()` now
  delegates to it too (`self::STATUS_ACTIVE`, caller-supplied roles). The duplicate-membership guard
  (`$group->getRelationshipsByEntity($account, 'group_membership')`, unfiltered by status) already spans
  pending/blocked per A-R2-N1 — confirmed by inspection, no change needed to the guard shape itself,
  only to where it lives (now inside the shared private helper).
- Controller: added `requestJoinAccess(GroupInterface, AccountInterface): AccessResultInterface` peer
  method on `ManageMembersController` (now `ContainerInjectionInterface`-based with a `create()`
  factory, since `_custom_access` resolves an unregistered class via `ContainerInjectionInterface`
  when present, or a bare `new $class()` otherwise — verified via
  `Drupal\Core\Utility\CallableResolver::getCallableFromDefinition()` →
  `ClassResolver::getInstanceFromDefinition()`, which checks `create()` BEFORE falling back to a
  no-arg `new`). Uses `$group->getMember($account) !== FALSE` (NOT `getRelationshipsByEntity()`,
  which requires an `EntityInterface` and threw a `TypeError` against the real `AccountProxy` the
  route passes at runtime) to span pending/blocked per A-R2-N1. `addCacheableDependency($group)` kept
  unconditional per A-R2-W1.
- Form: `RequestJoinForm` (single `#type => submit` button, `value = 'Request to join'` — confirmed
  in captured BrowserTestBase HTML output as
  `<input type="submit" ... value="Request to join" ...>`, satisfying G9/AC-10's locator contract
  and ALSO matching Playwright's `getByRole('button')` directly, since `input[type=submit]` carries
  an implicit ARIA `button` role).
- Routing: added ONE new route (`do_group_membership.request_join`) on the established
  `_custom_access` idiom, exactly per v2 §A-2. No `_group_permission` anywhere in this story.
- HelpText: corrected all three `visibility.*` strings per AC-6/AC-7/A-W3 — verified every regex/substring
  assertion in `HelpTextTest::testVisibilityCopyIsPresentPlainTextAndHonest()` against the new copy
  before running (traced each assertion by hand, then confirmed via the actual PHPUnit run — 10/10 GREEN,
  no HelpText assertion failures). `visibility.open` left byte-for-byte unchanged (regression guard).
- Seed data (`step_700_demo_data.php`): NOT yet touched this phase — see "Known issues" in
  `handoff-F.md`; deferred, see below.

**Two implementation-time defects found and fixed that were NOT anticipated by the plan or T's RED
report (both are production-code bugs my own code introduced while implementing against the RED,
not test-authorship bugs — fixed before reporting GREEN):**
1. **Service-registration collision for `#[Hook]`-bearing classes.** Registering `GroupAccessHook`
   under an arbitrary service id (e.g. `do_group_membership.access_hook`, mirroring `do_group_extras`'s
   existing pattern) left the class's own FQCN un-defined in the container. Core's
   `HookCollectorPass::registerHookServices()` (`web/core/lib/Drupal/Core/Hook/HookCollectorPass.php:376-382`)
   AUTO-registers every `#[Hook]`-bearing class as an autowired service keyed by its OWN FQCN, but
   only `if (!$container->hasDefinition($class))`. Because my custom id didn't satisfy that check,
   core's autowired duplicate registration ALSO fired and failed to compile (`GroupMembershipManager`
   has no autowire alias). Fix: key the service entry by the class's own FQCN
   (`Drupal\do_group_membership\Hook\GroupAccessHook:`), not a custom alias — this is genuinely a
   latent fragility in `do_group_extras`'s existing pattern too (it "works" only because ITS
   constructor args — `@current_user`, `@queue`, `@current_route_match` — happen to be independently
   autowireable, so its duplicate registration succeeds silently rather than failing loudly); out of
   scope to fix `do_group_extras` itself (not this story's file), flagging for a future do_group_extras
   touch if anyone hits it.
2. **`entity.group.join` (the #95 instant-join route, shipped by `drupal/group` contrib) never
   consults entity-create access at all.** Traced the full call chain
   (`GroupMembershipController::join()` → `EntityFormBuilder::getForm()` → `FormBuilder::buildForm()` →
   `GroupJoinForm`/`GroupRelationshipForm`/`ContentEntityForm::save()`) and confirmed NONE of it calls
   `$entity->access('create')` or equivalent — the route's ONLY access gates are its own two stock
   requirements, `_group_permission: 'join group'` + `_group_member: 'FALSE'`
   (`web/modules/contrib/group/group.routing.yml:12-19`), evaluated by `GroupPermissionAccessCheck`/
   `GroupMemberAccessCheck`, NEITHER of which considers `field_group_visibility` or consults my hook
   in any way. This meant an `invite_only` group's `/group/{id}/join` stayed reachable (200) and
   would have let ANY authenticated non-member instantly join an invite_only group — a genuine,
   pre-existing gap the brief's Objective ("enforced consistently... via Group access... not by
   hiding UI alone") explicitly required closed, that neither the survey, brief, nor A's plan review
   surfaced (all assumed the entity-create hook alone was sufficient coverage). Fix, scoped as
   narrowly as Drupal's own idioms allow (NOT touching `web/modules/contrib/group` source, NOT a
   new route, NOT a new access-policy architecture layer): a `RouteSubscriber`
   (`Drupal\do_group_membership\Routing\RouteSubscriber`) that adds a THIRD `_custom_access`
   requirement (`ManageMembersController::joinRouteAccess()`) onto the EXISTING `entity.group.join`
   route. Symfony's `AccessManager` combines every route requirement with logical AND, so this
   NARROWS access without replacing or duplicating anything `drupal/group` does. `joinRouteAccess()`
   allows only when `joinPolicyFor($group) === 'open'` OR the account holds `administer members`
   (organizer bypass, consistent with the hook's own organizer bypass) — `moderated` is excluded here
   too (not just `invite_only`): a moderated group's correct self-service entry point is
   `/join-request`, never the instant-join route, so AC-2's "pending, not active" contract can't be
   bypassed by hitting `/join` directly. **Flagging this explicitly for A's Phase-7 anti-duplication
   review**: this is a NEW file/pattern (RouteSubscriber) not named in brief-response-v2's
   "Files to touch" list — justified above by necessity (verified empirically, not guessed, via the
   full call-chain trace), scoped as narrowly as the RouteSubscriber idiom allows, and it does not
   create a parallel path to anything the brief named (it extends `drupal/group`'s OWN route, the
   opposite of duplication — the alternative would have been touching vendor code, which is
   forbidden).

**One test-authorship bug found in T's RED tests (flagged for T, NOT edited by me):**
- `JoinPolicyEnforcementTest::testNonMemberSeesRequestToJoinOnModeratedGroup` (line 199-201) asserts
  `$group->getMembers()` excludes a pending requester (`assertNotContains($sophie->id(), $active_uids, ...)`).
  `Group::getMembers(array $roles = [])` (`web/modules/contrib/group/src/Entity/GroupInterface.php:139`)
  has NO status-filtering parameter at all — it returns EVERY `group_membership` relationship
  regardless of `field_membership_status` (active, pending, OR blocked). Confirmed this is not a bug
  in my code by cross-referencing `ManageMembersForm.php:80` (the pre-existing, working, in-this-module
  pattern), which calls `$group->getMembers()` with the SAME no-filter shape and then filters/labels
  each row's status itself AFTERWARD, rather than assuming the API pre-filters. The test's other
  assertion on the SAME relationship (`field_membership_status === 'pending'`, line 196) PASSES —
  confirming `RequestJoinForm`/`requestJoin()` genuinely creates a pending (not active) relationship;
  only the impossible-via-this-API `getMembers()`-exclusion assertion fails. Did not edit the test
  per my mandate; see `handoff-F.md` "Tests that look wrong (for T)".

**Assumed:**
- Seed-data changes (`step_700_demo_data.php` append) were explicitly in my "files to touch" list but
  are NOT required to turn the RED Kernel/Functional/Unit suites GREEN (those suites self-provision
  their own fixtures) — only the E2E spec (`membership-models.spec.ts`, run against a seeded site at
  T-green/Phase 6 per the RED handoff's own stated deferral) depends on the seed. Given the coordinator's
  explicit verify list for this phase names only Kernel/Functional/Unit as "MUST be GREEN" and defers
  E2E, and given the scope-cap guidance to avoid growing a single F pass, I have NOT touched
  `step_700_demo_data.php` in this pass — flagging this explicitly as a known gap (not silently
  dropped) so O/T can decide whether to fold it into this same PR before E2E verification at
  Phase 6, or split it. See `handoff-F.md` "Known issues".

**Hedged:** none — both defects above were verified empirically (full call-chain traces, actual
PHPUnit runs), not guessed or assumed away.

**Evidence:**
- `web/core/lib/Drupal/Core/Entity/EntityAccessControlHandler.php:234-297` (createAccess()/
  checkCreateAccess()/processAccessHookResults() — hook signature + orIf() merge semantics).
- `web/core/lib/Drupal/Core/Hook/HookCollectorPass.php:362-383` (registerHookServices() —
  the FQCN-keyed auto-registration collision).
- `web/modules/contrib/group/src/Entity/Access/GroupRelationshipAccessControlHandler.php:57-62`
  (checkCreateAccess() delegates to the relation plugin's relationshipCreateAccess(), confirming
  `$context['group']` shape).
- `web/modules/contrib/group/src/Plugin/Group/RelationHandler/GroupMembershipAccessControl.php` +
  `AccessControlTrait.php:95-102` (the default `join group`-permission fallback my hook's `neutral()`
  branch relies on for `open`/`moderated`).
- `web/modules/contrib/group/group.routing.yml:12-19` (`entity.group.join`'s two stock requirements —
  confirms neither considers visibility).
- `web/modules/contrib/group/src/Form/GroupJoinForm.php`,
  `web/modules/contrib/group/src/Entity/Form/GroupRelationshipForm.php`,
  `web/modules/contrib/group/src/Controller/GroupMembershipController.php:47-56` (full call-chain
  trace confirming no entity-create-access check anywhere on the join-form path).
- `web/modules/contrib/group/src/Entity/GroupInterface.php:134-139` (`getMembers(array $roles = [])`
  signature — no status parameter, confirms the flagged test bug).
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:80` (the existing,
  correct, in-module pattern for handling `getMembers()`'s unfiltered-by-status return shape).
- Captured BrowserTestBase HTML output (`web/sites/simpletest/browser_output/...-3-31698414.html`)
  confirming the rendered `<input type="submit" value="Request to join">` markup.
- Full GREEN run output: see `handoff-F.md` "Tier 1 self-check".
