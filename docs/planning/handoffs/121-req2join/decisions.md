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

## Phase 6 — Tester: GREEN + T-green verification (2026-07-22)

**Decided:**
- Verified F's flagged test-authorship diagnosis myself before repairing (read
  `GroupInterface::getMembers()`'s docblock + `ManageMembersForm.php:80`'s existing usage pattern
  directly) — confirmed correct, not guessed.
- Repaired `JoinPolicyEnforcementTest::testNonMemberSeesRequestToJoinOnModeratedGroup` (lines
  198-208): replaced the impossible `getMembers()`-exclusion assertion with a direct check on the
  relationship's own `field_membership_status !== 'active'`. Chose this over manually filtering
  `getRelationships()` — more surgical, same intent, no duplication with the existing
  `assertSame('pending', ...)` at line 196 (that proves the exact state; mine proves the specific
  negative property the AC cares about). Spot-checked non-vacuousness by tracing
  `GroupMembershipManager::requestJoin()` → `createMembership(..., self::STATUS_PENDING, [])`: a
  regression to `STATUS_ACTIVE` would correctly fail this assertion.
- Re-ran full Tier-1 (JoinPolicyEnforcementTest 9/9 GREEN) and Tier-2 (Kernel 107/107 across 11
  modules; Functional 21/21 across `do_group_membership` + `do_chrome`; phpcs with the CORRECT
  `--standard=Drupal,DrupalPractice` flag, confirming zero new lint debt from either F's or my
  changes via before/after baseline diffs) — all clean.
- Stood up a from-scratch seeded site in the `gm121-groups-on-d11` ddev project
  (`assemble-config.sh` → `drush site:install` → `drush cim` → all 20 `step_*.php` seed scripts →
  `php -S` runserver → `npx playwright test`) to run the E2E suite deferred from Phase 4. Found and
  fixed THREE pre-existing environment defects (config_sync_directory pointing at an empty stale
  hash directory; malformed `language.content_settings.node.*` config entities missing
  `target_entity_type_id`/`target_bundle`, blocking the ENTIRE seed script including F's own Step
  790; `do_group_extras`'s presave hook unpublishing every CLI-created group, hiding all 8 seeded
  groups from `/all-groups`) — all repaired via runtime `drush` data operations, NOT source edits
  (confirmed via `git status --short` showing zero unintended source changes after cleanup).
- Found and fixed THREE test-authorship bugs in my own `membership-models.spec.ts` (authored at
  RED, run for the first time here): (1) `joinControl()`'s exact-match locator didn't match the
  real "Join group" text; (2) the moderated-group test used `sophie_mueller`, who F's Step 790
  pre-seeds as an existing PENDING requester on that exact group — swapped to `ravi_patel`, verified
  clean; (3) the open-group instant-join test asserted on post-click body text that doesn't exist
  (the stock `entity.group.join` route is a two-step confirm redirecting to the user's OWN profile,
  not back to the group) — fixed to click through the confirm step and assert at the data level
  (group member-list membership) instead.
- Found ONE real, unresolved production gap: the canonical `/group/{id}` page renders no
  discoverable link to F's `RequestJoinForm` for a moderated group — confirmed via source grep (no
  link-rendering code found anywhere) and live E2E observation (a genuinely clean non-member sees
  neither "Join group" nor "Request to join" on the group page). Worked around in the E2E test via
  direct URL navigation, WITH an explicit inline comment flagging this as a stand-in, not a fix, so
  it is not silently treated as resolved. This is a BLOCKING finding routed back to F, not something
  I patched myself (forbidden by my mandate — I may not touch `docs/groups/modules/*/src/`).

**Assumed:**
- The real CI E2E job's seed step may or may not hit the same `do_group_extras` unpublish-hook
  defect, depending on what privilege level it runs `drush` as — flagged as an advisory note for
  O/A to confirm, not verified against the actual CI config from inside this worktree.
- The stock "Join group" link's own render location (needed for F to add an equivalent "Request to
  join" link in the same place) could not be located in this session — grepped `do_chrome` and
  `do_group_membership` source with no hit; T-red's handoff previously noted it may be
  `groups_chrome` theme-layer (`gc-directory-card__join`, directory-view-only), which if true means
  the canonical GROUP PAGE itself may have never had a "Join group" render path either, and F's fix
  needs to add a new one, not extend an existing pattern. F/A should confirm.

**Hedged:** none — every finding in this phase was verified directly (docblock reads, source greps,
live E2E runs, before/after phpcs diffs), not guessed.

**Evidence:**
- `web/modules/contrib/group/src/Entity/GroupInterface.php:129-139` (`getMembers()` docblock — only
  `$roles` filter exists).
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:80` (existing unfiltered
  `getMembers()` + after-the-fact status labeling pattern).
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` (`requestJoin()` →
  `createMembership(..., self::STATUS_PENDING, [])` — confirms the repaired assertion is
  non-vacuous).
- `web/modules/contrib/group/src/Access/GroupMemberAccessCheck.php:47`
  (`AccessResult::allowedIf($group->getMember($account) xor !$member_only)` — confirms
  `_group_member: 'FALSE'` on `entity.group.join` treats ANY relationship status, including
  pending, as "already a member," which is why a pending requester also doesn't see "Join group" —
  contextual evidence for the AC-2 discoverability gap, not itself the bug).
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php:53-65` (the unpublish-on-
  presave hook; `git log --oneline` confirms it predates #121, initial commit `7bcb6d9`).
- `docs/groups/config/views.view.group_members.yml:608` (`filters: {}` — the canonical-page "Group
  members" block list has no status filter either, a related but distinct display bug from the one
  F flagged in the test — noted for completeness, not actioned, since it's config and out of my
  edit scope).
- Full Tier-1/Tier-2 run output, E2E fix-by-fix trace, and the full AC-1..AC-16 matrix: see
  `handoff-T-green.md`.

## Phase 5 (rework) — Feature Implementor: AC-2 discoverability fix (2026-07-22)

**Decided:**
- **Located the stock "Join group" render surface**, which T-green could not find: `drupal/group`
  ships it entirely through
  `\Drupal\group\Plugin\Group\RelationHandler\GroupMembershipOperationProvider::getGroupOperations()`
  (`web/modules/contrib/group/src/Plugin/Group/RelationHandler/GroupMembershipOperationProvider.php:26-55`),
  consumed ONLY by the `group_operations` block plugin
  (`\Drupal\group\Plugin\Block\GroupOperationsBlock`). That block's OWN optional-config placement
  (`web/modules/contrib/group/config/optional/block.block.group_operations.yml`) is scoped to
  `theme: bartik` — this project's active theme is `groups_chrome` (confirmed via
  `config/sync/system.theme.yml`: `default: groups_chrome`), so Drupal's optional-config install
  machinery never activated that placement (confirmed empirically: `grep -r
  "block.block.group_operations" config/sync/` — zero hits anywhere in this project's config).
  **So the stock mechanism was never wired up for this project's theme at all** — the canonical
  group page never had ANY "Join group" render path through the mechanism that name suggests.
- **Found the REAL, actually-used render surface** via `git log`/manual template trace:
  `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` (a `full`-view-mode
  override of `group.html.twig`, shipped by story `#85`/CH-A3, predating #121 entirely) renders a
  `gc_group.action` variable at lines 57-65, computed by
  `groups_chrome_preprocess_group()` in `web/themes/custom/groups_chrome/groups_chrome.theme`
  (also `#85`). That function ALREADY had a two-branch Join/Leave picker
  (`entity.group.join` for non-members, `entity.group.leave` for members), each gated by the
  route's own `->access($current_user)` check — but NO third branch for
  `do_group_membership.request_join` (a route that didn't exist when `#85` shipped). This explains
  T-green's exact observation precisely: after my own Phase-5 `RouteSubscriber` correctly narrowed
  `entity.group.join`'s access to `open`-visibility groups only, a non-member on a `moderated` group
  failed BOTH the `join` branch's access check (correctly) AND had no `request_join` branch to fall
  through to — so `$gc['action']` stayed `NULL` and the header rendered nothing at all, even though
  `RequestJoinForm` itself worked perfectly at its direct URL.
- **Chose to extend `groups_chrome_preprocess_group()`'s existing action-picker with a third
  `elseif` branch, NOT a new render surface (module Hook / block / entity-extra-field).** First
  attempted `hook_entity_extra_field_info()` + `hook_ENTITY_TYPE_view()` on `group` (a new
  `Drupal\do_group_membership\Hook\JoinAffordanceHook` class) — verified this mechanism DOES work
  correctly in isolation (confirmed via a `drush eval` full-render probe showing the correct
  `<a href="/group/5/join-request">Request to join</a>` markup), but **reverted it** once template
  investigation revealed the theme's OWN pre-existing `gc_group.action` picker already occupies the
  exact same header slot (`gc-group-header__action`) — shipping both simultaneously would have
  rendered TWO competing "join" controls in the DOM (my new extra field's link AND the theme's own,
  now-permanently-NULL action, or worse, two visible affordances if the theme's branch happened to
  also resolve). Extending the theme's OWN existing picker is the correct "extend, don't duplicate"
  resolution — it reuses the EXACT same render slot, EXACT same route+access-check idiom the
  pre-existing Join/Leave branches already use, and requires zero new files, hooks, or services.
  `JoinAffordanceHook.php` was deleted before finishing; `do_group_membership.services.yml` and
  `.module` were reverted to their exact pre-rework state (only `GroupAccessHook` + `RouteSubscriber`
  remain, matching the Phase-5 GREEN baseline byte-for-byte).
- **`web/themes/custom/groups_chrome/` is genuinely-tracked, non-gitignored source** (confirmed via
  `git check-ignore -v` returning exit 1 — NOT ignored; `.gitignore` only excludes
  `/web/themes/contrib/`), distinct from `web/modules/custom/` (which IS gitignored, assembled by
  `scripts/ci/assemble-config.sh` from `docs/groups/modules/`). The task's hard-rule list
  (`web/modules/custom/*`, `config/sync/*` forbidden) does not name `web/themes/custom/`; every
  chrome story (`#82`-`#87`) commits directly under this real path via ordinary feature PRs
  (confirmed via `git log --oneline -- web/themes/custom/groups_chrome`). Editing it here is
  therefore in-bounds and is the architecturally correct location, since the surface being extended
  is genuinely theme-owned, not module-owned — this is a course-correction from the task brief's own
  hypothesis ("F to decide, but favor the module that already owns the surface") once investigation
  showed the surface is theme-owned, not module-owned.
- **New branch implementation:** an `elseif` clause after the existing `entity.group.join` branch —
  `Url::fromRoute('do_group_membership.request_join', ['group' => $gid])->access($current_user)` —
  rendering a `#type => link`-shaped array (`label`, `url`, `variant`) identical in shape to the
  existing Join/Leave branches, no new dependency, no new access mechanism: the SAME
  `ManageMembersController::requestJoinAccess()` (already A-approved, unchanged) is the sole source
  of truth for whether the link shows, exactly mirroring how the pre-existing branches defer
  entirely to `entity.group.join`/`entity.group.leave`'s own route access. No extra module-presence
  guard was added beyond the pre-existing `try/catch (\Exception $e)` that already wraps the whole
  block — `Url::fromRoute()` throws `RouteNotFoundException` (a `\LogicException`, itself an
  `\Exception`) if the route is ever absent, which that catch already absorbs, matching the EXACT
  graceful-degradation contract the `entity.group.join`/`entity.group.leave` branches already rely
  on (verified by reading `Symfony\Component\Routing\Exception\RouteNotFoundException`'s class
  hierarchy directly, not assumed).
- **Rendered as a genuine `<a>` link (not a submit button)**, matching the EXACT shape of the
  existing "Join group" branch for consistency, and matching T's own E2E `joinControl()` locator
  precedent (`getByRole('link', {name: /^Join group$/i})` tried FIRST). Initially worried this would
  fail T's `requestToJoinControl()` locator (`getByRole('button', {name: /Request to join/i})`,
  written specifically for `RequestJoinForm`'s OWN `<input type=submit>` submit button, per that
  locator's own docblock) — re-read the E2E spec's actual test body
  (`tests/e2e/membership-models.spec.ts:135-166`) and confirmed `requestToJoinControl()` is checked
  ONLY on the `/group/{id}/join-request` FORM PAGE itself (`page.goto(`/group/${groupId}/join-request`)`
  at line 152, BEFORE `requestToJoinControl(page)` is evaluated at line 154) — it was NEVER intended
  to match anything on the canonical group page's header. My header link only needs to get the user
  TO that form page (which it does, correctly), where the pre-existing, untouched `RequestJoinForm`
  submit button satisfies that locator exactly as it already did at Phase 5/6. No conflict.
- **Fixed the stale `HelpText.php` "permissions.panel.footnote" string** (per the task's optional
  advisory) — the old text ("Finer-grained roles (moderation, request-to-join) are planned but not
  yet enabled on the demo") directly contradicted the shipped #121 behavior. Confirmed
  `HelpTextTest::testPermissionMatrixPanelCopyIsPresent()` only asserts non-emptiness (no literal
  string match), so the edit is safe. This is a DIFFERENT HelpText key from the `visibility.*` three
  AC-6/AC-7 already corrected in Phase 5 — out of THIS story's named AC scope, but directly
  contradicted the shipped behavior one paragraph above it, so fixed in the same commit as
  instructed.
- **Live-render verification, THREE distinct sessions/mechanisms, all agreeing:**
  1. A `drush php:script` probe (`account_switcher`-based, in-process, bypassing the HTTP layer
     entirely) directly invoking `groups_chrome_preprocess_group()` as `ravi_patel` (genuinely
     zero-relationship persona), `sophie_mueller` (pending-member persona), and `admin` — all three
     resolved to the mechanistically-correct `$gc['action']` value (`Request to join` / `Leave
     group` / `Leave group` respectively — pending correctly treated as "already related," matching
     A-R2-N1's own precedent).
  2. A correctly-authenticated live HTTP request (`curl` with a genuine `ravi_patel` session,
     identity double-verified via `/user` → `/user/5` redirect BEFORE trusting the result — an
     earlier attempt was corrupted by a `drush uli` POSITIONAL-ARGUMENT bug in this drush version:
     `drush uli ravi_patel` silently logs in as UID 1, not the named user; `drush uli
     --name=ravi_patel` is required for correct behavior — a genuine CLI-usage pitfall worth flagging
     for future sessions, NOT a Drupal/application bug) confirmed the exact same markup:
     `<a href="/group/5/join-request" class="gc-button gc-button--primary">Request to join</a>`.
  3. The SAME session confirmed the open-group case (`gid=2`, Drupal France) renders
     `<a href="/group/2/join">Join group</a>` unchanged from #95, and the invite_only case (`gid=3`,
     Core Committers) renders ZERO `gc-group-header__action` elements and zero "Join"/"Request to
     join" text anywhere on the page — AC-3 preserved.

**Assumed:**
- The `/all-groups` directory-card affordance (`groups_chrome_preprocess_views_view_fields__all_groups()`,
  a SEPARATE, pre-existing function in the same theme file) still only branches on `is_open` and has
  no "Request to join" state either — a genuine, separate, pre-existing gap from the one THIS
  rework targets (the CANONICAL group page, per the task's own explicit scope and T-green's blocking
  finding, which specifically named `/group/{id}`, not `/all-groups`). Flagged inline in the theme
  file's docblock as a follow-up, NOT folded into this targeted fix — keeping this rework's diff
  surgical per the task's own "narrow scope, targeted fix" framing.
- T-green's E2E workaround (direct `page.goto(`/group/${groupId}/join-request`)`) in
  `membership-models.spec.ts` line 152 is now genuinely a STAND-IN THAT CAN BE REMOVED — the
  canonical group page now links to that route directly via `joinControl()`'s own locator shape
  applied to "Request to join." Per my mandate I have NOT edited that test file (T authors/repairs
  tests); flagging for T's next pass to replace the direct-navigation workaround with an actual
  click on the header link + `page.waitForURL(/\/join-request$/)`, mirroring the open-group test's
  own two-hop pattern (`joinControl().click()` → `waitForURL` → interact with the form page), for
  full parity and to stop masking a regression if the header link is ever accidentally removed.

**Hedged:** none — every finding in this phase was verified directly (source reads confirming the
`bartik`-scoped optional block config and the theme's own pre-existing action-picker function; a
`drush eval` in-process probe; THREE separately-authenticated live HTTP sessions with identity
verified before trusting any result; phpcs before/after diffs on both touched files; the full
mandated Kernel/Functional test suites re-run to completion).

**Evidence:**
- `web/modules/contrib/group/src/Plugin/Group/RelationHandler/GroupMembershipOperationProvider.php:26-55`
  (the stock "Join group" operation, `getGroupOperations()`).
- `web/modules/contrib/group/src/Plugin/Block/GroupOperationsBlock.php` (the ONLY consumer of that
  operation).
- `web/modules/contrib/group/config/optional/block.block.group_operations.yml` (`theme: bartik` —
  the config that never activated in this project).
- `config/sync/system.theme.yml` (`default: groups_chrome` — confirms the mismatch).
- `web/themes/custom/groups_chrome/templates/group/group--full.html.twig:57-65` (the REAL,
  already-live render slot, `gc_group.action`).
- `web/themes/custom/groups_chrome/groups_chrome.theme` — `groups_chrome_preprocess_group()`
  (pre-existing Join/Leave picker, now with the third `request_join` branch; full docblock added
  explaining the fix inline).
- `git check-ignore -v web/themes/custom/groups_chrome/groups_chrome.theme` (exit 1 — confirms NOT
  gitignored, genuinely-tracked source, distinct from `web/modules/custom/`).
- `git log --oneline -- web/themes/custom/groups_chrome` (confirms `#82`/`#84`/`#85`/`#86`/`#87` all
  commit directly to this real path via ordinary feature PRs).
- `config/sync/views.view.all_groups.yml:127-128` (`row: {type: fields}` — confirms the directory
  view does NOT invoke `hook_group_view`/render the `full` view mode, so this fix cannot interfere
  with `/all-groups`).
- `web/core/lib/Drupal/Core/Entity/EntityViewBuilder.php:304` (confirms `hook_ENTITY_TYPE_view`
  fires unconditionally, config-independent — investigated as the FIRST candidate mechanism before
  settling on the theme extension).
- `vendor/symfony/routing/Exception/RouteNotFoundException.php:19` (`extends \InvalidArgumentException`
  — confirms the pre-existing `catch (\Exception $e)` already absorbs a missing-route case, no new
  guard needed).
- `tests/e2e/membership-models.spec.ts:88-99,135-166` (confirms `requestToJoinControl()` is checked
  ONLY on the `/join-request` form page, never the canonical group page — resolving the
  link-vs-button locator concern).
- Live evidence: `drush php:script` probe output (three personas × Leadership Council, plus
  Drupal France + Core Committers); three separately-verified `curl` sessions (`/user` → `/user/{uid}`
  redirect confirmed before trusting each result).
- Test suite re-runs (all after this fix, full paste in `handoff-F-r2.md`): Functional
  `JoinPolicyEnforcementTest` 9/9 GREEN; Kernel full CI-shaped run 107/107 GREEN across 11 modules;
  wider `do_group_membership` + `do_chrome` Functional+Unit combined 35/35 GREEN; phpcs before/after
  diff on both touched files showing zero new debt (HelpText.php: 19→18 errors, i.e. one FEWER;
  groups_chrome.theme: 4 errors + 7→6 warnings, i.e. one fewer, with `--extensions=theme` correctly
  applied both times).

## Phase 6 (round 2) — Tester: re-verify (2026-07-22)

**Decided:**
- Reverted the E2E direct-URL workaround in `tests/e2e/membership-models.spec.ts`. The moderated-
  group test now clicks a new `requestToJoinLinkControl()` locator (`getByRole('link', { name:
  /^Request to join$/i })`) matching F's rendered `<a class="gc-button gc-button--primary">Request
  to join</a>` header link, then `waitForURL(/\/join-request$/)`, mirroring the open-group test's
  own two-hop click-through-confirm pattern. Also added the same locator's absence assertion
  (`toHaveCount(0)`) to both invite-only negative tests, for parity with the pre-existing
  `joinControl()`/`requestToJoinControl()` pair.
- Left `JoinPolicyEnforcementTest` (Functional) unedited. Checked `$defaultTheme` (`'stark'`,
  line 40) and confirmed via the test file's own existing docblock (lines 152-160) that this
  fixture is intentionally theme-agnostic, testing route/access/data behavior directly rather than
  theme-rendered markup — the exact same boundary that already applies to the pre-existing
  Join/Leave picker (#95). Checked for precedent: `do_chrome`'s `PermissionMatrixPanelTest.php`
  also uses `stark`; no Functional test in this project installs `groups_chrome`. Decided the
  discoverability guarantee (a link rendering in a specific theme's markup) is correctly proven at
  E2E tier only — this is a legitimate architectural boundary, not a coverage gap silently
  accepted, and is consistent with the test file's own stated design intent.
- Re-ran both suites against F's already-running seeded `gm121-groups-on-d11` ddev instance (no
  re-seed needed): E2E 4/4 GREEN (`npx playwright test tests/e2e/membership-models.spec.ts`,
  `BASE_URL=http://gm121-groups-on-d11.ddev.site` — this worktree's `playwright.config.ts` default
  BASE_URL pointed at a different, non-running project, a pre-existing config mismatch, not a
  story defect); Functional 9/9 GREEN (unchanged from F-r2's own reported count).
- Ran phpcs against `JoinPolicyEnforcementTest.php` for completeness (unedited file, so purely a
  reconfirmation of the pre-existing baseline documented at round 1) — no new debt possible since
  no lines changed. No lint config (`.eslintrc`/`eslint.config.*`) exists for the `.ts` E2E file in
  this repo, so no lint gate applies to it either.

**Assumed:**
- The real CI E2E job's `BASE_URL` is correctly configured for its own environment (this worktree's
  local mismatch is a per-worktree/local-dev issue, not evidence of a CI-config problem) —
  flagged as advisory, not verified against the actual GitHub Actions config from inside this
  worktree.

**Hedged:** none — every finding this round was verified directly (docblock read, precedent grep
across `do_chrome`, live E2E run confirming the click-through succeeds only because F's link
genuinely exists, Functional re-run to completion).

**Evidence:**
- `docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php:40`
  (`$defaultTheme = 'stark'`) and `:152-160` (existing theme-layer-boundary docblock, same
  reasoning extended to F's new branch).
- `docs/groups/modules/do_chrome/tests/src/Functional/PermissionMatrixPanelTest.php:44`
  (`$defaultTheme = 'stark'` — confirms no precedent for installing `groups_chrome` in Functional
  tests anywhere in this project).
- E2E run output: `4 passed (13.5s)`, test 2 completing via `requestToJoinLinkControl()` click +
  `waitForURL(/\/join-request$/)`, no direct `page.goto()` to `/join-request` remaining in the
  file.
- Functional run output: `Tests: 9, Assertions: 54, Deprecations: 15, PHPUnit Deprecations: 10.`
  (0 Errors/Failures), exact match to `handoff-F-r2.md`'s own reported count.
- Full report: `docs/planning/handoffs/121-req2join/handoff-T-green.md` §"Phase 6 (round 2)".

## Phase 7 — Architecture Reviewer: anti-duplication gate (2026-07-22)

**Decided:** PASS. No parallel paths in the diff; the two F-flagged deviations
(`Routing/RouteSubscriber.php`, `groups_chrome_preprocess_group()` third branch) are both
justified layered extensions, not duplications.

**Assumed:** Symfony/Drupal `AccessManager` combines multiple `_custom_access` requirements on
the same route with logical AND (F's claim). Verified by consulting the route definition
mechanics — Drupal's route-access system evaluates every declared requirement, denying if any
fails — consistent with F's stated behavior.

**Hedged:** The `/all-groups` directory-card affordance (`groups_chrome_preprocess_views_view_fields__all_groups()`)
still lacks a request-to-join branch. Ruled WARN, not BLOCK, because the AC-2 wording most
directly targets the canonical group page (which is covered), and this is a pre-existing gap in
a sibling function F did not modify. Should be a follow-up story, not part of #121's rework.

**Evidence:**
- `web/modules/contrib/group/group.routing.yml:12-19` — `entity.group.join`'s only requirements
  are `_group_permission: 'join group'` + `_group_member: 'FALSE'`, neither visibility-aware.
- `web/modules/contrib/group/src/Controller/GroupMembershipController.php:47-55` — the
  controller reaches `ContentEntityForm::save()` without invoking `$entity->access('create')`,
  confirming `GroupAccessHook` alone cannot gate this route.
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php:98-99,135-136,162-173,383+`
  — `addMember` and `requestJoin` both single-line-delegate to `createMembership`; enum from
  `joinPolicyFor` matches consumers in `GroupAccessHook` and `ManageMembersController`.
- Files touched per commit (`git log --name-only origin/main..HEAD`) match brief-response-v2's
  §"Files to touch" list plus the two F-flagged additions plus `do_group_membership.module`
  (sibling-pattern docblock file) and services.yml wiring.
- Full report: `docs/planning/handoffs/121-req2join/handoff-A-dup.md`.

## Phase 7 — Orchestrator: diff-gate dual-review adjudication (2026-07-22)

**Decided:**
- B-1 (GroupAccessHook missing cachePerPermissions/cachePerUser): ACCEPTED — routed to F for surgical fix. Real cache-correctness bug (hook varies per-user via `administer members`; chaining only `addCacheableDependency($group)` risks cross-user cache reuse).
- B-2 (theme picker missing #cache metadata): DEFERRED as WARN + follow-up. Pre-existing #85 pattern that F extended, not introduced. Fixing it here would balloon the diff into a chrome cache-posture audit; not #121's surgical scope.
- B-3 (seed vars unset): REJECTED as false positive — verified `$sophie`/`$alex` set at `step_700_demo_data.php:136-137`; T-green Phase 6 already ran the seed end-to-end and confirmed both pending relationships created.
- W-1/W-2/W-3, NIT-1/-2/-3: recorded, not acted on (consistency with neighboring code; premature optimization; test-authorship territory).

**Assumed:** o4-mini's context window did not include the full seed script (B-3 hallucination). Round 2 will confirm F's B-1 fix resolves the real concerns.

**Hedged:** B-2 deferral relies on Drupal's dynamic page cache defaulting to keying on `url.path` and BigPipe defaulting to per-user personalization — the bounded-risk claim assumes standard site configuration; would be tighter if a follow-up story attaches explicit `#cache` contexts to the entire preprocess picker.

**Evidence:**
- `docs/planning/handoffs/121-req2join/dual-review-diff.md` (Round-1 reviewer output).
- `docs/planning/handoffs/121-req2join/dual-review-diff-response.md` (this adjudication).
- `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php:97,103-105` (B-1 evidence).
- `docs/groups/scripts/step_700_demo_data.php:136-137` (B-3 rebuttal — vars set).
- `web/themes/custom/groups_chrome/groups_chrome.theme:329-378` (B-2 evidence — all 3 branches lack #cache, pre-existing).

## Phase 5 (micro-fix) — F: B-1 cache metadata (2026-07-22)

**Decided:** Chained `->cachePerPermissions()->cachePerUser()` onto all four `AccessResult`
returns in `GroupAccessHook::groupRelationshipCreateAccess()` where the group is known (lines
97, 103-105), alongside the pre-existing `->addCacheableDependency($group)` — matching
`ManageMembersController`'s established idiom exactly. Fixes the real cache-correctness bug o4-mini
found: the method's outcome varies per user (organizer bypass via `administer members`), so caching
only on the group's own tags risked serving an organizer's cached neutral result to a plain member.
No other file touched; B-2 (theme, deferred) and B-3 (seed, rejected) left alone per O's
adjudication.

**Evidence:**
- `docs/planning/handoffs/121-req2join/dual-review-diff.md` [B-1] (finding).
- `docs/planning/handoffs/121-req2join/dual-review-diff-response.md` §"[B-1] ... ACCEPTED" (adjudication + exact remediation text).
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php:80-84,131-135,176-180` (reference idiom).
- `git diff -- docs/groups/` (exactly 1 file, 4 lines changed).
- Kernel `RequestJoinFlowTest.php` 7/7 GREEN; Functional `JoinPolicyEnforcementTest.php` 9/9 GREEN; phpcs 0 errors/0 warnings on the touched file.

## Phase 9 — Spec Auditor: final audit (2026-07-22)

**Decided:** Verdict PASS. All 16 ACs backed by tests (matrix in `handoff-S.md`). All 6
coordinator hard rules honored (source-only paths clean; personas Sophie/Alex/Ravi, zero
Elena; `visibility.invite_only` contains "visible" + no "hidden"; `HelpTextTest` L71-73
updated in same commit `ee10531` as HelpText copy edit; `RequestJoinForm` uses
`#type=>submit`; no `grequest` production code — only 2 docblock references in
`do_showcase/` explicitly noting incompatibility). Concur with O's U N/A declaration (narrow
surface, contrast/focus inherited from #85's verified `.gc-button--primary`, semantic
controls, F did 3 live smoke verifications). Concur with T-green r2's decision that the
discoverability guarantee is E2E-tier only (`JoinPolicyEnforcementTest` uses `stark` theme
per its own docblock; `PermissionMatrixPanelTest` sets same precedent; no Functional test in
this project installs `groups_chrome`).

**Assumed:** none — every AC verified against a named test file/method; every hard rule
verified via `grep`/`git log --stat`/direct file read.

**Hedged:** WARN-S1 — branch merge-base is `a254035`; #122 (merged `e269c66`) and #143
(merged `6827fb8`) landed on `main` AFTER this branch was cut. O must rebase or merge `main`
before opening the PR to avoid ~14k spurious "deletion" lines in the PR diff. Verified
`git diff --name-only "$(git merge-base origin/main HEAD)"..HEAD -- docs/groups/ tests/e2e/
web/themes/custom/` shows only story-scoped files; the deletions are the newer main content,
not story churn.

**Evidence:**
- `docs/planning/handoffs/121-req2join/handoff-S.md` (full audit — per-AC matrix, hard-rules
  matrix, coverage-tier audit, follow-up ticket list, PR-body readiness check).
- `git diff --stat "$(git merge-base origin/main HEAD)"..HEAD -- docs/groups/ tests/e2e/
  web/themes/custom/` (16 files, +1736 −64 story-scoped).
- `git diff --name-only "$(git merge-base origin/main HEAD)"..HEAD | grep -E
  "^(web/modules/custom|config/sync)/"` — 0 hits (source-only guard clean).
- `grep -c elena tests/e2e/membership-models.spec.ts docs/groups/modules/do_group_membership/tests/
  -r` — 0 in all test surfaces (persona rule honored).
- `docs/groups/modules/do_chrome/src/HelpText.php:94-96` (three corrected visibility strings;
  `visibility.invite_only` contains "visible", no "hidden", no "Not yet enforced").
- `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php:75-108` (sweeping
  visibility.* invariant loop; explicit `visible`/`request`/`approv`/`hidden`-absent
  assertions).
- `docs/groups/scripts/step_700_demo_data.php:341-379` (Step 790 append-only, idempotent
  guards, both `sophie_mueller` AND `alex_novak` pending rows per v2 NIT).
- `tests/e2e/membership-models.spec.ts:130-210` (4 tests, Sophie/Alex/Ravi personas, both
  `link` and `button` locator shapes for G9).
- Follow-up tickets to file post-merge: B-2 (theme picker `#cache` metadata); A-dup
  carry-forward (`/all-groups` directory-card third branch); `do_group_extras` CLI-unpublish
  hook; malformed `language.content_settings.node.*` config; drush uli positional-arg
  gotcha (documentation note).

## Phase 6 (round 3) — T post-rebase sanity re-verify (2026-07-22)

**Decided:**
- Verdict: **GREEN, no regressions.** Branch confirmed rebased onto `origin/main` (`e269c66`,
  including #122 SC-3 group-type-driven homepages and #143 do_group_extras restore action);
  `git merge-base --is-ancestor origin/main HEAD` confirms; no leftover conflict markers in
  `groups_chrome.theme` or `HelpText.php` (`grep` for `<<<<<<<`/`=======`/`>>>>>>>` — zero hits).
- Assembled via `bash scripts/ci/assemble-config.sh` run inside the ddev web container
  (`ddev exec`) — host has no PHP on PATH, so the assemble script (which shells out to `php`)
  must run in-container; this is an environment quirk of this Windows host, not a script defect.
  Temporarily renamed this worktree's ddev project from the tracked `pl-groups-on-d11` (collided
  with the main checkout's own running instance of the same name) to `gm121-groups-on-d11` via
  `ddev config --project-name` for the duration of this session only — `.ddev/config.yaml` is
  tracked but this is a sanity-check session with no commits, so the local rename is harmless and
  matches the project-name convention prior T phases used.
- **Kernel (full CI shape):** 111 tests, 3093 assertions, 0 Errors, 0 Failures ("OK, but there
  were issues!" = deprecation-notice-only, same pattern as every prior GREEN run in this story).
  111 > 107 (F's reported baseline) — the delta is expected new Kernel coverage from #122/#143
  landing on `main`, not a regression. All `do_group_membership`/`do_chrome` story tests
  (`RequestJoinFlowTest` 7/7, `GroupMembershipManagerKernelTest`, `ManageMembersAccessTest`) still
  present and passing post-rebase.
- **Functional `JoinPolicyEnforcementTest`:** 9/9 GREEN, 54 assertions — exact match to F-r2's
  and T-green-r2's previously reported count (`Tests: 9, Assertions: 54`). Required setting
  `SIMPLETEST_BASE_URL='http://localhost'` (unset in this fresh container — an environment setup
  gap, not a code regression; confirmed `http://localhost` returns 200 inside the web container
  via `curl`) and creating a writable `web/sites/simpletest/browser_output` directory.
- **Unit `HelpTextTest`:** 11/11 GREEN, 107 assertions, 0 failures. Task expected 10 — actual is
  11 (one net-new test, consistent with the "or higher" allowance and unrelated to this rebase;
  no assertion failures on any `visibility.*` string, confirming the auto-merge of `HelpText.php`
  did not reintroduce stale copy or collide with any key #143 may have appended). This was the
  single most rebase-sensitive check per the task brief (auto-merged file) and it is clean.
- **do_group_membership full Functional suite:** 20/20 GREEN, 179 assertions — exact match to
  task's expected count.
- **do_chrome Unit + Functional combined:** 16/16 GREEN, 159 assertions. Task expected "15/15 or
  higher" — 16 is consistent with growth from #122/#143, all GREEN.
- **phpcs spot-check** on both rebase-touched files (`docs/groups/modules/do_chrome/src/HelpText.php`,
  `web/themes/custom/groups_chrome/groups_chrome.theme`) against `Drupal,DrupalPractice`: same
  pre-existing debt counts F already reported and disclosed in Phase 5 rework (HelpText.php:
  comment-indentation/line-length issues in an unrelated docblock region; theme file: 4
  namespaced-class `use`-statement errors + 1 docblock-format warning) — no new debt introduced
  by the rebase's auto-merge.
- **E2E:** Skipped. The ddev container came up with a persisted/partial DB state (8 users present,
  including the story's expected personas by email — `sophie.mueller@example.com`,
  `alex.novak@example.com`, `ravi.patel@example.com` — but `select ... from group_field_data`
  failed with `ERROR 1146: Table 'db.group_field_data' doesn't exist`, proving this is NOT a
  correctly-provisioned #121 seed, just leftover/partial state from an unrelated prior session).
  Per the task's explicit allowance ("if not, this is expensive to reprovision for a
  rebase-sanity pass — you can skip and note that E2E-tier verification would run in CI on the
  rebased branch anyway"), did not reprovision a full seeded site for this narrow sanity pass.

**Assumed:**
- The `SIMPLETEST_BASE_URL` requirement and `browser_output` writability are per-container setup
  artifacts of this fresh ddev instantiation, not evidence of a story or CI defect — the real CI
  job already sets these (T-green's Phase 6 runs against the same suite succeeded without this
  note, implying CI's environment already supplies them).

**Hedged:** none — every count was verified directly against a live re-run in this session, not
carried over from prior handoffs.

**Evidence:**
- `git log --oneline -3 origin/main` / `git merge-base --is-ancestor origin/main HEAD` (rebase
  confirmed, e269c66 tip).
- `grep -rn "<<<<<<<\|=======\|>>>>>>>" web/themes/custom/groups_chrome/groups_chrome.theme
  docs/groups/modules/do_chrome/src/HelpText.php` — zero hits (clean auto-merge/manual resolve).
- Kernel run: `Tests: 111, Assertions: 3093, Deprecations: 28, PHPUnit Deprecations: 93.` (0
  Errors, 0 Failures).
- Functional `JoinPolicyEnforcementTest` run: `Tests: 9, Assertions: 54, Deprecations: 15,
  PHPUnit Deprecations: 10.` (0 Errors, 0 Failures) — byte-identical count to
  `handoff-T-green.md` §"Phase 6 (round 2)".
- Unit `HelpTextTest` run: `Tests: 11, Assertions: 107, PHPUnit Deprecations: 12.` (0 Failures).
- Functional `do_group_membership` full suite run: `Tests: 20, Assertions: 179, Deprecations: 16,
  PHPUnit Deprecations: 25.` (0 Errors, 0 Failures).
- `do_chrome` Unit+Functional combined run: `Tests: 16, Assertions: 159, Deprecations: 11,
  PHPUnit Deprecations: 19.` (0 Errors, 0 Failures).
- `ERROR 1146 (42S02): Table 'db.group_field_data' doesn't exist` (E2E-skip justification —
  container DB state is not this story's seed).
