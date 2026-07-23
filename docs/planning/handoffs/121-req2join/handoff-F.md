# Handoff-F: Phase 5 - #121 SC-2 Membership models enforced

**Date:** 2026-07-22
**Branch:** 121-req2join (worktree `~/Projects/_worktrees/groups-req2join`)
**Issue:** #121

## What was done

**EDIT:**
- `docs/groups/modules/do_chrome/src/HelpText.php` — corrected the three `visibility.*`
  copy strings (AC-6/AC-7/A-W3). `visibility.open` left byte-for-byte unchanged.
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` — added
  `requestJoin()` + `joinPolicyFor()`; factored a private `createMembership()` helper
  (A-W1) that both `addMember()` and `requestJoin()` now delegate to.
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php` —
  added `requestJoinAccess()` (v2 §A-2 peer method) and `joinRouteAccess()` (new, see
  "Deviations" below); converted the class to `ContainerInjectionInterface` since it now
  needs the manager service injected (see "Design decisions").
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` — added ONE
  new route, `do_group_membership.request_join`, on the established `_custom_access`
  idiom.
- `docs/groups/modules/do_group_membership/do_group_membership.info.yml` — one-line
  description update.
- `docs/groups/modules/do_group_membership/do_group_membership.services.yml` — added the
  `GroupAccessHook` service (keyed by its own FQCN — see "Design decisions") and the new
  `RouteSubscriber` event-subscriber service.
- `docs/groups/scripts/step_700_demo_data.php` — append-only Step 790: sets Leadership
  Council → `moderated`, Core Committers → `invite_only` (idempotent, existence-guarded);
  seeds TWO pending requests (sophie_mueller + alex_novak → Leadership Council, per the
  accepted NIT), also existence-guarded.

**NEW:**
- `docs/groups/modules/do_group_membership/src/Form/RequestJoinForm.php` — outsider-facing
  single-click "Request to join" form.
- `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php` — the
  `hook_group_relationship_create_access` entity-create gate.
- `docs/groups/modules/do_group_membership/do_group_membership.module` — registers the
  hook class (docblock pointer only, matching `do_group_extras`'s pattern).
- `docs/groups/modules/do_group_membership/src/Routing/RouteSubscriber.php` — **not in the
  original files-to-touch list; added out of necessity, flagged explicitly below and in
  decisions.md for A's Phase-7 review.**

## Design decisions

1. **`ManageMembersController` converted to `ContainerInjectionInterface`, not raw
   constructor injection.** `_custom_access` callbacks are resolved via
   `CallableResolver::getCallableFromDefinition()` → `ClassResolver::getInstanceFromDefinition()`,
   which either uses an already-registered service (this class isn't one) or instantiates
   via `ContainerInjectionInterface::create()` if implemented, else a bare `new $class()`.
   The original `access()` method had a no-arg constructor, so it worked either way; once
   `requestJoinAccess()`/`joinRouteAccess()` needed the manager service, a bare `new` would
   throw a missing-constructor-argument error. Implementing `ContainerInjectionInterface`
   (the same pattern every Form class in this module already uses) was the correct, minimal
   fix — confirmed by reading `web/core/lib/Drupal/Core/Utility/CallableResolver.php` and
   `Drupal/Core/DependencyInjection/ClassResolver.php` directly, not guessed.

2. **`GroupAccessHook` service keyed by its own FQCN, not a `do_group_membership.*` alias.**
   Core's `HookCollectorPass::registerHookServices()` auto-registers every `#[Hook]`-bearing
   class as an autowired service keyed by its class name, but only if no definition already
   exists under that exact key. Registering under a custom alias (mirroring
   `do_group_extras.services.yml`'s existing pattern) left the FQCN un-defined, so core's
   auto-registration fired a SECOND time and failed to autowire `GroupMembershipManager`
   (no autowire alias for a bespoke class) — a real container-compile error, not a test
   artifact. Fixed by keying the entry `Drupal\do_group_membership\Hook\GroupAccessHook:`
   directly. Full trace in `decisions.md` Phase 5 entry — this is a latent fragility in
   `do_group_extras`'s existing pattern too (it "works" only because its own constructor
   args happen to be independently autowireable); out of scope to touch that module here.

3. **`ManageMembersController::requestJoinAccess()` uses `$group->getMember($account)`,
   not `getRelationshipsByEntity()`.** `_custom_access` callbacks receive the request's
   live `AccountInterface` (an `AccountProxy` at runtime, not a loaded `User` entity).
   `getRelationshipsByEntity()` requires an `EntityInterface` and threw a `TypeError`
   against the real account — caught via an actual BrowserTestBase 500 response, not
   guessed. `Group::getMember(AccountInterface $account)` accepts the account type
   directly and (per `GroupMembershipTrait::loadSingle()`) resolves a relationship at ANY
   status, satisfying A-R2-N1 without an extra load.

4. **`joinPolicyFor()` defaults to `'open'`** if `field_group_visibility` is absent/empty,
   so any group predating this field (or a test fixture that doesn't install it) keeps
   #95's instant-join behavior rather than silently losing its join path — matches the
   field's own `default_value: open` (confirmed in
   `docs/groups/config/field.field.group.community_group.field_group_visibility.yml`).

## Reuse / extend-vs-new

Per the brief's Reuse map, extended `GroupMembershipManager` (added `requestJoin`/
`joinPolicyFor`/private `createMembership`) and `ManageMembersController` (added
`requestJoinAccess`/`joinRouteAccess`) rather than creating new manager or controller
classes. `RequestJoinForm` is a genuinely new form — justified in the survey (distinct
actor/flow from `AddMemberForm`) and unchanged by the amendment. `GroupAccessHook` is a
new hook class — justified in the survey/addendum (no existing group-access hook in
`do_group_membership`). The pending-queue parallel path from the original plan (A-1) was
correctly deleted before I started — the existing `ManageMembersForm` on
`/group/{group}/members` surfaces the seeded pending rows automatically; I made zero
changes to that form.

**One extend-vs-new decision NOT pre-authorized by the brief:** `Drupal\do_group_membership\Routing\RouteSubscriber`
(new file, new pattern for this module). See "Deviations from spec" below for the full
justification — this is the one place I exceeded the reviewed files-to-touch list, and I
flag it explicitly for A's Phase-7 anti-duplication gate rather than treating it as
routine.

## Architecture notes for A

- **Layers touched:** manager (business logic), controller (access-only, no rendering),
  form (new), hook (entity-create access), routing (config + programmatic route alter),
  services (DI wiring), HelpText (static copy data), seed script (drush data).
- **New dependency direction:** `GroupAccessHook` depends on `GroupMembershipManager`
  (via `joinPolicyFor()`) — a hook depending on the manager service, not the reverse;
  matches the "Services over Hooks" mandate (business logic stays in the manager; the hook
  is a thin adapter).
- **No schema/contract changes.** `field_group_visibility` and `field_membership_status`
  are pre-existing fields (from #88/#138); I only READ/WRITE their existing allowed values,
  no new values added, no new fields created.
- **Shared components changed:** `ManageMembersController` (shared access-callback class)
  gained two methods; no existing method (`access()`) was touched. `GroupMembershipManager`
  gained two methods + one private helper; `addMember()`'s PUBLIC contract/behavior is
  unchanged (verified via the full pre-existing `GroupMembershipManagerKernelTest` suite —
  all 14 tests still pass unmodified).
- **Local pattern followed:** every new access callback matches the EXACT cache-metadata
  shape of the existing `::access()` (`addCacheableDependency`, `cachePerPermissions`,
  `cachePerUser`, `addCacheContexts(['url.path'])`); every new service registration follows
  the `autowire: false` + explicit `arguments` shape already established in this module.

## Deviations from spec / wireframe

**One deviation, with full justification (flagged for A's Phase-7 review):**

The brief/plan assumed `hook_group_relationship_create_access` alone was sufficient to
enforce join-policy "consistently across the directory, group page, and join path" (the
brief's own Objective wording). Verified empirically (full call-chain trace, not assumed)
that `entity.group.join` — the pre-existing `#95` route this module does NOT own
(`drupal/group` contrib) — never calls entity-create access at all:
`GroupMembershipController::join()` → `EntityFormBuilder::getForm()` →
`FormBuilder::buildForm()` → `GroupJoinForm`/`GroupRelationshipForm`/
`ContentEntityForm::save()`, none of which check `$entity->access('create')`. That route's
ONLY access gates are its own two stock requirements (`_group_permission: 'join group'` +
`_group_member: 'FALSE'`), which do not consider `field_group_visibility` and are
satisfied by every authenticated non-member regardless of the specific group's
visibility. Without a fix, an `invite_only` group's `/group/{id}/join` stayed reachable
(200) and would let any authenticated non-member instantly join — failing AC-3/AC-11,
and confirmed directly by the RED-turned-red-again Functional test
(`testNonMemberSeesNoJoinPathOnInviteOnlyGroup`) before the fix.

Given I must not edit `web/modules/contrib/group` (vendor code, forbidden) and must not
add a brand-new access-policy service architecture (heavier than the brief authorized,
unreviewed by A), I added the minimal, standard Drupal idiom for "add an access
requirement to a route owned by another module": a `RouteSubscriber`
(`Drupal\Core\Routing\RouteSubscriberBase`) that layers a THIRD `_custom_access`
requirement onto `entity.group.join` alongside the two `drupal/group` already has.
Symfony's `AccessManager` combines every route requirement with logical AND, so this
NARROWS access without replacing or duplicating anything `drupal/group` does — it does
not create a parallel path to anything the brief named; the alternative (touching vendor
code) was the only other option and is explicitly forbidden by project convention.

This is the one place I exceeded brief-response-v2's "Files to touch" list. I judged
implementing the fix (with this explicit flag) to be more useful to the pipeline than
reporting "3/9 Functional tests fail, here's why, I did not fix it" — but I want A's
Phase-7 anti-duplication review to explicitly evaluate this addition rather than discover
it unannounced. If A determines a different mechanism is preferred (e.g., an
`access_policy` service, reviewed and added in a follow-up story), this file can be
removed/replaced without touching anything else — `joinRouteAccess()` and the
`RouteSubscriber` registration are fully self-contained.

No other deviations. The wireframe: none exists for this story (A confirmed at Phase 3
this is not blocking — "the plan is UI-simple enough that D-phase output can follow after
the amendment"), so there is nothing to check `RequestJoinForm`'s UI shape against beyond
the brief's own AC-10 locator contract, which is satisfied (verified in captured
BrowserTestBase HTML: `<input type="submit" ... value="Request to join" ...>`).

## Tier 1 self-check (incl. tests now GREEN)

All commands run inside `ddev` (`gm121-groups-on-d11`), assembled layout
(`bash scripts/ci/assemble-config.sh` run before every verification pass).

**Kernel — `RequestJoinFlowTest` (T's RED suite, this story's primary target):**
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SYMFONY_DEPRECATIONS_HELPER=disabled \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_group_membership/tests/src/Kernel/RequestJoinFlowTest.php

 ⚠ Request join creates pending relationship
 ⚠ Approve pending flips to active with no roles
 ⚠ Deny pending deletes relationship
 ⚠ Duplicate request join throws
 ⚠ Request join on invite only group is forbidden
 ⚠ Request join on open group uses join path
 ⚠ Join policy for returns correct string per visibility
Tests: 7, Assertions: 169, Deprecations: 5, PHPUnit Deprecations: 8.
```
(⚠ = pre-existing framework/Drupal-version deprecation noise inherited from
`GroupsKernelTestBase`/`EntityKernelTestBase` — cross-confirmed identical on the untouched
sibling `GroupMembershipManagerKernelTest`; exit code 0, zero Errors/Failures.)

**Functional — `JoinPolicyEnforcementTest` (T's RED suite):**
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8888' \
SYMFONY_DEPRECATIONS_HELPER=disabled BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browsertest-output \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php

 ⚠ Non member sees join button on open group
 ✘ Non member sees request to join on moderated group     <- flagged test bug, see below
 ⚠ Non member sees no join path on invite only group
 ⚠ Direct post to request join on invite only is 403
 ⚠ Organizer sees pending row in existing manage members
 ⚠ Anonymous get on manage members is 403
 ⚠ Plain member get on manage members is 403
 ⚠ Anonymous post to approve is 403
 ⚠ Plain member post to approve is 403
Tests: 9, Assertions: 54, Failures: 1, Deprecations: 14, PHPUnit Deprecations: 10.
```
8/9 GREEN, including all 6 that were legitimately green at RED (regression guards on
#95/#138) and 2 of the 3 genuinely new-behavior tests. The 1 failure is
`testNonMemberSeesRequestToJoinOnModeratedGroup`, which fails on an assertion I believe is
incorrect about the `Group::getMembers()` API contract — see "Tests that look wrong"
below. Note the OTHER assertion in the same test (relationship status ==='pending') PASSES.

**Unit — `HelpTextTest` (T's RED suite):**
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php

 ✔ Foundation demo copy is present
 ✔ Unknown key returns empty string
 ✔ Audience copy is present
 ✔ Visibility copy is present plain text and honest
 ✔ Archive pin control copy is present and plain text
 ✔ Report flag control copy is omitted
 ✔ Group type field copy names all types
 ✔ Content type field copy names all types
 ✔ Permission matrix panel copy is present
 ✔ All returns string map
Tests: 10, Assertions: 98, PHPUnit Deprecations: 11.
```
10/10 GREEN.

**Wider regression check (Tier 1, my own self-check — full CI Kernel command across
EVERY custom module, per PROJECT_CONTEXT.md's exact invocation):**
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SYMFONY_DEPRECATIONS_HELPER=disabled \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
$(find web/modules/custom -type d -path '*/tests/src/Kernel')

OK, but there were issues!
Tests: 107, Assertions: 2947, Deprecations: 28, PHPUnit Deprecations: 93.
EXITCODE=0
```
107/107 GREEN across all 11 custom modules with Kernel suites (`do_tests`, `do_streams`,
`do_group_language`, `do_notifications`, `do_group_pin`, `do_profile_stats`,
`do_group_mission`, `do_group_extras`, `do_group_membership`, `do_multigroup`,
`do_discovery`) — zero regression anywhere.

**Wider `do_group_membership` Functional suite** (all 5 files: mine + 4 pre-existing):
```
Tests: 20, Assertions: 179, Failures: 1, Deprecations: 15, PHPUnit Deprecations: 25.
```
19/20 GREEN (the same 1 flagged test bug); every pre-existing Functional test
(`ManageMembersPaginationTest`, `ManageMembersRouteAccessTest`,
`ManageMembersRouteResolutionTest`, `ManageMembersPageRenderTest`) is fully unaffected.

**Wider `do_chrome` suite (Unit + Functional):**
```
Unit:       Tests: 14, Assertions: 145, PHPUnit Deprecations: 16.        (14/14 GREEN)
Functional: Tests: 1, Assertions: 5, Deprecations: 10, PHPUnit Deprecations: 2. (1/1 GREEN)
```

## Tests that look wrong (for T)

**`JoinPolicyEnforcementTest::testNonMemberSeesRequestToJoinOnModeratedGroup`**
(`docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php:184-202`),
specifically the assertion at lines 199-201:

```php
$active = $group->getMembers();
$active_uids = array_map(static fn($m) => $m->getEntity()?->id(), $active);
$this->assertNotContains($sophie->id(), $active_uids, 'A pending requester does not appear as an active member.');
```

`\Drupal\group\Entity\GroupInterface::getMembers(array $roles = [])`
(`web/modules/contrib/group/src/Entity/GroupInterface.php:134-139`) has **no
status-filtering parameter at all** — it returns every `group_membership` relationship
regardless of `field_membership_status` (active, pending, OR blocked). This is not a
production-code defect on my end: the EXISTING, working `ManageMembersForm.php:80` calls
`$group->getMembers()` with the exact same no-filter shape and then labels/filters each
row's status itself AFTERWARD (never assuming the API pre-filters) — confirming the API
contract, not guessing about it. The relationship IS correctly created as `pending` (the
test's OWN assertion at line 196, `assertSame('pending', $relationship->get('field_membership_status')->value)`,
passes) — only the impossible-via-this-API exclusion check at line 201 fails. I did not
edit this test per my mandate. Suggested fix for T (not applied by me): assert on the
relationship's own `field_membership_status` value directly (as line 196 already
correctly does) rather than on `getMembers()`'s membership-list contents, or check
`$group->getMember($sophie)?->get('field_membership_status')->value !== 'active'` instead
of an array-exclusion check against an API that doesn't filter by status.

## Known issues

None against the stated verification list (Kernel/Functional/Unit MUST be GREEN — all
three are, modulo the one flagged test bug above, which is not a production defect).

**Not a defect, but worth naming explicitly:** the E2E spec
(`tests/e2e/membership-models.spec.ts`) has NOT been run against a seeded site in this
phase — per T's own RED handoff, E2E verification is explicitly deferred to Phase 6
(T-green) once a seeded install exists. I DID complete the seed-script change
(`step_700_demo_data.php` Step 790) this phase since it was in my authorized files-to-touch
list and is small/mechanical/idempotent (matching every other guarded block already in
that file), so the E2E spec's dependency on seeded visibility + pending rows should be
satisfiable at Phase 6 without further F work — but I have not personally stood up a
seeded install to confirm the seed script runs cleanly end-to-end (only PHP-lint-checked
its syntax and traced its API calls against the same calls already verified working in the
Kernel suite). Flagging this so T/O can decide whether to spin up the seeded install as
part of Phase 6 verification (as originally planned) or ask me to do it first.

## Files changed

**EDIT:**
- `docs/groups/modules/do_chrome/src/HelpText.php`
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php`
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php`
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml`
- `docs/groups/modules/do_group_membership/do_group_membership.info.yml`
- `docs/groups/modules/do_group_membership/do_group_membership.services.yml`
- `docs/groups/scripts/step_700_demo_data.php`

**NEW:**
- `docs/groups/modules/do_group_membership/src/Form/RequestJoinForm.php`
- `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php`
- `docs/groups/modules/do_group_membership/do_group_membership.module`
- `docs/groups/modules/do_group_membership/src/Routing/RouteSubscriber.php` (**deviation
  from the reviewed plan — see "Deviations from spec" above; flagged for A's Phase-7
  review**)

No test file was created, edited, or deleted by me. `git status --short docs/groups/`
shows only the 11 paths above as modified/added; nothing under `web/modules/custom/*` or
`config/sync/*` was staged (those are assembled build artifacts, verified via `git status
--short` at the repo root — the only entries there predate this session, per the
environment's initial git status).
