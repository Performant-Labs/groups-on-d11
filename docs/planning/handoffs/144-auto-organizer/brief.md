# Brief — #144 MC-6 Create-Group flow (creator auto-Organizer + guided preview)

Run slug: `144-auto-organizer` | Branch: `144-auto-organizer` | Epic: #137
Review rigor: **second-opinion** (dual-review.sh mandatory on the T(GREEN) diff, per issue).
Survey: `docs/planning/handoffs/144-auto-organizer/survey.md`
Architecture review: `docs/planning/handoffs/144-auto-organizer/handoff-A.md` (PASS, 3 warns — folded in below)

## Objective
An authenticated user creates a `community_group` via the existing add form
(`/group/add/community_group`) and is immediately granted the **Organizer** group role
(`community_group-organizer`) on their auto-created creator membership (currently they only get
`community_group-admin` via the group type's `creator_roles` setting). After submit, the user
lands on a lightweight guided-preview page (`/group/{group}/created`) with a "you're the
Organizer" message and clear next-step CTAs (edit group, manage members, view group) — not a
wizard, POC-minimal.

## IMPORTANT — `creator_wizard: true` (A finding #1, must verify empirically)
`docs/groups/config/group.type.community_group.yml:10` sets **`creator_wizard: true`** on the
ASSEMBLED community_group type — this makes the real `/group/add/community_group` a **multi-step
wizard**, not a single-page form. The existing regression test
(`CreatorMembershipFormTest::setUp()`) reconstructs its OWN group type via `createGroupType([...])`
with only `creator_membership: TRUE` set — it does **NOT** set `creator_wizard: true`, so that
test's single-page `submitForm()` call does **not** exercise the real wizard flow at all. This is a
genuine gap between the existing test's simplified fixture and real assembled-config behavior.
**F and T must NOT assume a single submitForm() call reaches the final save/redirect.** T(RED)'s
functional/E2E coverage must drive the ACTUAL wizard (however many steps it has) through to
completion against the real assembled `community_group` type (not a hand-rolled `createGroupType()`
that omits `creator_wizard`), and F must confirm which wizard step actually persists the group +
membership and issues the final redirect before implementing the form_alter's form_id filter and
submit-handler placement.

## Reuse map summary (full detail in survey.md; A's confirmations folded in)
- **EXTEND** `do_group_membership` module. No new module.
- **EXTEND** `GroupMembershipManager` with one new public method `ensureRole()` — additive,
  idempotent role grant on an EXISTING relationship. **A confirmed:** must NOT reuse/generalize
  `changeRole()` (line 194's `->setValue(array_values($roles))` is a wholesale REPLACE that would
  erase the `community_group-admin` role `creator_roles` just granted, and it also runs the
  irrelevant last-Organizer guard). `ensureRole(GroupRelationshipInterface $relationship, string
  $role_id): void` must: (a) early-return if `hasRole()` already true (reuse the existing protected
  `hasRole()` from within the same class — no visibility change needed), (b) otherwise APPEND the
  role to the existing `group_roles` values (read-then-append, never `set`/replace), (c) save.
- **NEW (justified)** ONE Hook class in `do_group_membership/src/Hook/` (e.g.
  `CreateGroupOrganizerHook`) holding TWO `#[Hook]` methods. **A confirmed:** one class, not two,
  and NOT folded into `GroupAccessHook` (that class is scoped to create-ACCESS gating —
  `group_relationship_create_access` — a different hook and different concern; mixing role-mutation
  + redirect into it would blur its single responsibility):
  1. `#[Hook('group_relationship_insert')]` — modeled directly on the already-shipped
     `DoNotificationsHooks::groupRelationshipInsert()` (`do_notifications/src/Hook/DoNotificationsHooks.php:165-198`).
     Fires AFTER Group 4.x's own form-save has created the creator's membership (with
     `community_group-admin` from `creator_roles`); filters to `group_membership` relationships
     whose member-uid equals the group's owner id, then calls
     `GroupMembershipManager::ensureRole()` to ADD the Organizer role. **A confirmed sound:** the
     two-part filter (`plugin_id === 'group_membership'` AND owner-equality) correctly avoids
     misfiring on other memberships created in the same request (e.g. a batch-added member), and
     `ensureRole()`'s idempotency makes any incidental re-fire harmless. T must add a kernel test
     asserting a NON-owner membership created in the same request does NOT receive Organizer.
  2. `#[Hook('form_alter')]` — filtered to the community_group add-form's actual form_id(s) (F
     confirms empirically — see the wizard note above, there may be more than one form_id across
     wizard steps), appends a submit handler that redirects to the new preview route after the
     final wizard step saves the group. Falls back to the `OrderAfter(modules: ['group'])` +
     submit-array-reorder pattern (`do_multigroup/src/Hook/DoMultigroupHooks.php:168-187`) ONLY if
     F finds plain appending insufficient (F verifies empirically, does not guess). **A's
     best-effort read (no vendor access):** the redirect handler does not depend on the membership
     row existing (only on the saved group id from
     `$form_state->getFormObject()->getEntity()`), so plain appending is LIKELY sufficient unless
     Group's own submit handler sets a redirect after ours and clobbers it — if the landing page
     ends up being the group canonical page instead of `/created`, adopt the `OrderAfter` fallback.
  Does NOT fork or duplicate #36's creator-membership mechanism — the insert hook runs strictly
  after it and only adds a role; the form_alter only changes the post-submit redirect destination.
- **NEW (justified)** Controller (`GroupCreatedPreviewController`) + route
  (`do_group_membership.group_created_preview`, `/group/{group}/created`) — content-only render,
  modeled on `ManageMembersController`'s DI/access-callback shape but no form. Optional
  enhancement (A finding #3, non-blocking, F's discretion): the redirect submit handler may also
  call `\Drupal::messenger()->addStatus(...)` with a confirmation message, matching Drupal's normal
  post-save convention, for users who navigate away from the preview page and back. Not required
  for AC-5 (the wireframe's h1-first-content-element already satisfies "focus lands sensibly").
- **NEW** CSS `docs/groups/modules/do_group_membership/css/create-group.css` + library entry in
  `do_group_membership.libraries.yml`, same convention as `manage-members.css`.
- **NEW** `tests/e2e/create-group.spec.ts`.

## Acceptance criteria (from issue #144, verbatim + made testable)
- [ ] AC-1: An authenticated persona with `create community_group group` permission submits
      `/group/add/community_group` (through however many real wizard steps it has) and the
      resulting group's creator membership carries the `community_group-organizer` role (in
      addition to the existing `community_group-admin` role — additive, not a replacement).
- [ ] AC-2: The creator, as Organizer, can immediately edit the group and reach the manage-members
      page (i.e. `$group->hasPermission('edit group', $creator)` and
      `$group->hasPermission('administer members', $creator)` both TRUE post-create).
- [ ] AC-3: Submitting the add form (final wizard step) redirects to `/group/{group}/created` (the
      guided-preview route), not the group's default canonical page.
- [ ] AC-4: The guided-preview page renders: a confirmation message naming the group, an
      "You're the Organizer" statement, and CTA links to edit group / manage members / view group.
- [ ] AC-5: WCAG 2.2 AA on the preview page — proper heading structure, link text is descriptive
      (no bare "click here"), focus lands sensibly on redirect (page has a logical heading as the
      first focusable/landmark content), color contrast via existing subtheme tokens (no new
      hard-coded colors).
- [ ] AC-6: Existing suite stays green (no regression to #36 creator-membership behavior, #121
      join-policy enforcement, #138 manage-members UI).
- [ ] AC-7: Playwright E2E (`tests/e2e/create-group.spec.ts`) walks: log in as a persona with
      create permission -> submit the add form (all real wizard steps) -> land on the preview page
      -> click through to manage-members and confirm the creator is listed with the Organizer role.
- [ ] AC-8 (from A finding #2): a kernel test proves a non-owner membership created in the same
      request as the creator's does NOT receive the Organizer role (guards the insert-hook filter's
      precision).

## Input documents
- Issue: `gh issue view 144 --repo Performant-Labs/groups-on-d11`
- Survey: `docs/planning/handoffs/144-auto-organizer/survey.md`
- Architecture review: `docs/planning/handoffs/144-auto-organizer/handoff-A.md`
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php`
- `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php` (hook-class precedent —
  do NOT extend this class; new sibling class per A's confirmation)
- `docs/groups/modules/do_notifications/src/Hook/DoNotificationsHooks.php:165-198`
  (`#[Hook('group_relationship_insert')]` precedent — exact signature to mirror)
- `docs/groups/modules/do_multigroup/src/Hook/DoMultigroupHooks.php:140-240` (form_alter +
  OrderAfter + submit-handler-reorder fallback precedent)
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php`
  (controller/DI/access-callback precedent)
- `docs/groups/scripts/step_790_persona_switcher.php:94-130` (role-grant-on-existing-membership
  precedent — note: uses `set()`/replace semantics; do NOT copy that shape into `ensureRole()`,
  which must append/read-then-append instead)
- `docs/groups/RUNBOOK.md:270-292` (community_group type facts, form-only creator membership)
- `docs/groups/config/group.type.community_group.yml` (note `creator_wizard: true` at line 10),
  `docs/groups/config/group.role.community_group-organizer.yml`,
  `docs/groups/config/group.role.community_group-admin.yml`
- `docs/groups/modules/do_tests/tests/src/Functional/CreatorMembershipFormTest.php` (existing
  regression coverage for #36 — must stay green; NOTE this test's fixture does NOT set
  `creator_wizard: true`, so it does not itself exercise the real wizard flow — T's NEW coverage
  must do so against the real assembled config)
- `docs/groups/modules/do_tests/tests/src/Kernel/CreatorMembershipApiTest.php` (existing
  regression coverage — must stay green)

## Files to touch (disjoint, per issue's "Owns" list)
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` (add `ensureRole()`)
- `docs/groups/modules/do_group_membership/src/Hook/CreateGroupOrganizerHook.php` (new; 2 methods)
- `docs/groups/modules/do_group_membership/src/Controller/GroupCreatedPreviewController.php` (new)
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` (add route)
- `docs/groups/modules/do_group_membership/do_group_membership.services.yml` (register new hook
  class, FQCN-keyed per `GroupAccessHook`'s existing convention)
- `docs/groups/modules/do_group_membership/do_group_membership.libraries.yml` (add
  `group_created_preview` library)
- `docs/groups/modules/do_group_membership/css/create-group.css` (new)
- `docs/groups/modules/do_group_membership/templates/` (new Twig template for the preview page, if
  F judges a template cleaner than an inline render array — F's call, not a scope change either
  way)
- `docs/groups/modules/do_group_membership/tests/src/Kernel/...` (new kernel test(s), T authors —
  including the AC-8 non-owner-membership guard test)
- `docs/groups/modules/do_group_membership/tests/src/Functional/...` (new functional test(s), T
  authors — against the REAL assembled community_group config, wizard included)
- `tests/e2e/create-group.spec.ts` (new, T authors)

## Non-goals (explicit, to prevent scope creep)
- No wizard / multi-step create flow **redesign** — the wizard already exists
  (`creator_wizard: true`); this story does not change its steps, only adds a post-save role grant
  and redirect.
- No change to who CAN create a group (permissions untouched).
- No removal of the existing `community_group-admin` creator role.
- No new group type or role config changes (Organizer role already exists per #138).
- No changes to #121 join-policy or #138 manage-members UI beyond the creator now appearing with
  an additional role.

## Verification commands (assembled layout)
```
bash scripts/ci/assemble-config.sh
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Kernel')
php vendor/bin/phpcs docs/groups/modules/do_group_membership
# Functional: BrowserTestBase, served docroot at http://127.0.0.1:8080
# E2E: assemble -> drush site:install -> drush cim -> seed scripts -> runserver -> npx playwright test
```

## Review-rigor dial
**second-opinion** — run `bash docs/playbook/workflow/dual-review.sh` on the T(GREEN) diff. `hard`
findings block merge; respawn F, re-enter at A.
