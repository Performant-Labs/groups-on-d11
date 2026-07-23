# Survey — #144 MC-6 Create-Group flow (creator auto-Organizer + guided preview)

## Scope recap (from issue #144)
1. Creator of a new `community_group` becomes its **Organizer** (group role
   `community_group-organizer`), automatically, immediately on group creation via the add form.
2. A lightweight guided/preview surface renders after create ("here's your group, add
   links/about, invite members") — POC-minimal, not a wizard.
3. WCAG 2.2 AA on the new surface.
4. Existing suite stays green; Playwright walks create -> land as Organizer.
5. Owns (disjoint files): create-flow hook/controller (own file, extend not fork #36), group
   add-form display config edit (if needed), subtheme `create-group.css` (new),
   `tests/e2e/create-group.spec.ts` (new).
6. Depends on #138 (Organizer role — already defined and live) + persona work #120.

## Current-state facts (verified by direct file read)

### 1. Creator auto-membership is FORM-ONLY (Group 4.x)
- `docs/groups/RUNBOOK.md:287-292` — CAUTION block: `Group::create()->save()` does NOT add the
  creator as a member, even with `creator_membership: true`. Only the add-FORM path does.
- Confirmed by:
  - `docs/groups/modules/do_tests/tests/src/Kernel/CreatorMembershipApiTest.php` — API path,
    memberless group after `Group::create()->save()`.
  - `docs/groups/modules/do_tests/tests/src/Functional/CreatorMembershipFormTest.php` — real
    request stack, submits `/group/add/community_group`, asserts creator IS a member. Submit
    button text is `"Create Community Group and become a member"`.

### 2. group.type.community_group already sets creator_roles
`docs/groups/config/group.type.community_group.yml`:
```yaml
creator_membership: true
creator_wizard: true
creator_roles:
  - community_group-admin
```
So TODAY, the form-created creator membership already gets the **Admin** group role
(`community_group-admin`, `admin: true` — bypasses all group permission checks, per
`docs/groups/config/group.role.community_group-admin.yml:10`), NOT Organizer. This is the actual
gap #144 closes: the creator needs the **Organizer** role (`community_group-organizer`,
`docs/groups/config/group.role.community_group-organizer.yml`) — the role that carries the
concrete permission set (`edit group`, `administer members`, the group_node CRUD grants) the
acceptance criteria describe ("can edit, manage members"). `community_group-admin` has
`permissions: {}` and relies purely on `admin: true` bypass — semantically different from
"Organizer".

**Judgment (recorded in decisions.md):** ADD Organizer to the creator's membership (both Admin +
Organizer on one relationship) rather than replacing `creator_roles`. Removing Admin would be an
out-of-scope regression to any existing behavior relying on the admin bypass; adding Organizer is
additive and satisfies the acceptance criterion cleanly.

### 3. Reuse pattern for granting a role to an existing membership
`docs/groups/scripts/step_790_persona_switcher.php:94-130` — the exact pattern:
```php
$existing_membership = $group->getMember($user);
// ... check hasRole(ORGANIZER_ROLE_ID) via group_roles field ...
$existing_membership->set('group_roles', [...]); // preserves/adds roles
if ($existing_membership->hasField('field_membership_status') && ->isEmpty()) {
  $existing_membership->set('field_membership_status', [['value' => STATUS_ACTIVE]]);
}
$existing_membership->save();
```
This is the reuse target for the hook's role-grant logic. Must ADD to existing `group_roles`
(Admin already present) rather than overwrite, per the judgment above.

### 4. GroupMembershipManager — the service-over-hooks manager (do_group_membership/src/GroupMembershipManager.php)
- `ORGANIZER_ROLE_ID = 'community_group-organizer'` (public const, line 54) — reuse directly.
- `STATUS_ACTIVE = 'active'` (line 59) — reuse directly.
- `hasRole()` (protected, line 287) — logic to detect if a relationship already carries a role;
  will need a small public or reusable equivalent, OR inline the same simple loop in the new hook
  class (survey recommends: add a small public method `hasRole()` visibility bump, OR a new public
  `grantRoleIfMissing()`-style method on the manager — **judgment for A to confirm**: extending the
  manager with one new public method keeps "services over hooks" discipline rather than duplicating
  the loop in a Hook class). Recommend: add `GroupMembershipManager::ensureRole(GroupRelationshipInterface $relationship, string $role_id): void` — idempotent add-role-if-missing, reusing existing `hasRole()`.
- Manager currently has no method for "add a role to an EXISTING relationship without touching
  status/other roles" — `changeRole()` REPLACES the whole `group_roles` array (line 194), which
  would erase Admin. A new idempotent `ensureRole()` avoids that footgun and is the right reuse
  extension (extend the manager, not fork it).

### 5. Hook mechanism — SPLIT into two concerns (revised after deeper vendor research)

**Research finding (subagent, vendor `web/modules/contrib/group/` — Drupal composer convention;
not `vendor/`, which does not contain `drupal/group` in either checkout):**
- `GroupType::getCreatorRoleIds()` (`GroupType.php:192-194`) is read exactly once, in
  `GroupForm.php:54-59`, and handed to `CreateFormEnhancer::enhanceGroupForm()`
  (`CreateFormEnhancer.php:205-244`), which merges `group_roles` as an INITIAL FIELD VALUE on a
  brand-new, unsaved `group_relationship` entity (`$gr_storage->create()` at line 234). The actual
  save happens in `GroupForm::submitForm()` (`GroupForm.php:293-301`) via a plain
  `$storage->save()` — **no pre/post-save hook is involved in applying `creator_roles` itself**.
- Confirms: (a) FORM-ONLY (already known from RUNBOOK/kernel-test evidence); (b) `creator_roles` is
  a SET of the initial field value at construction time, not an additive merge — but this only
  matters at the moment Group's own code builds the relationship; nothing prevents OUR code from
  adding to `group_roles` AFTER that save completes, via a normal insert hook.
- **`#[Hook('group_relationship_insert')]` already has a working precedent in this codebase**:
  `do_notifications/src/Hook/DoNotificationsHooks.php:165-198` — exact signature
  `groupRelationshipInsert(GroupRelationshipInterface $relationship): void`, filtering by
  `$relationship->getPluginId()`. This fires AFTER Group's own save (core's entity API invokes
  `hook_ENTITY_TYPE_insert` post-save), so by the time our hook runs, `creator_roles` (Admin) is
  already on the relationship — safe to ADD Organizer to it without any form_alter/ordering
  gymnastics.
- No core `hook_ENTITY_TYPE_insert` fires for creator-role logic itself — Group's own
  `EntityHooks.php` implements only `entity_bundle_info`, `entity_access`, `entity_delete`,
  `entity_field_access` — confirming the insert hook we add is purely additive, not colliding with
  or duplicating any core hook.
- `#[Hook]` attribute auto-discovery: `Drupal\Core\Hook\HookCollectorPass::registerHookServices()`
  scans `src/Hook/` classes and registers each `#[Hook]`-bearing class keyed by its FQCN
  (`!$container->hasDefinition($class)` guard) — no `services.yml` entry required UNLESS the class
  needs non-default construction (matches `GroupAccessHook`'s existing FQCN-keyed
  `autowire: false` entry in `do_group_membership.services.yml:16-23`, needed because
  `GroupMembershipManager` isn't autowire-aliased).

**Revised design — split into two independent, simpler pieces (recommend BOTH in ONE hook class,
since they serve the identical "create-group flow" concern — see verdict below):**

1. **Role grant — `#[Hook('group_relationship_insert')]`**, modeled directly on
   `DoNotificationsHooks::groupRelationshipInsert()`:
   - Filter: `$relationship->getPluginId() === 'group_membership'` (not `group_node:*`).
   - Filter: the group's type is `community_group` AND the relationship's member-user id equals
     `$relationship->getGroup()->getOwnerId()` (this membership IS the creator's, not some other
     member being added concurrently — guards against mis-firing e.g. during demo-data seeding of
     other members).
   - Call `GroupMembershipManager::ensureRole($relationship, ORGANIZER_ROLE_ID)`.
   - POST-SAVE — no interaction with `creator_roles`'s pre-save SET semantics, no form_alter
     ordering needed. Also correctly handles memberships created some other way (defense in depth,
     matches `step_790`'s existing pattern for non-form paths).

2. **Redirect to guided preview — `#[Hook('form_alter')]`** (no `OrderAfter` needed a priori — this
   handler does not depend on the membership existing, only on which group WAS created, which
   `$form_state->getFormObject()->getEntity()` provides regardless of submit order):
   - Filter to the community_group add-form id specifically (**F must confirm the exact `$form_id`
     empirically** — e.g. via a quick kernel/functional assertion — do not hardcode a guess; likely
     `group_community_group_add_form` per Drupal's `{entity_type}_{bundle}_{operation}_form`
     convention, but unverified).
   - Append a submit handler that, after the form's own submit handlers run and the group is
     saved, calls
     `$form_state->setRedirect('do_group_membership.group_created_preview', ['group' => $group->id()])`.
   - **F must verify empirically** whether appending (default array order) is sufficient or whether
     Group's own submit handler also calls `setRedirect()` in a way that would run after ours and
     clobber it — if so, fall back to the proven `OrderAfter(modules: ['group'])` +
     explicit-submit-array-reorder pattern from `do_multigroup/src/Hook/DoMultigroupHooks.php:168-187`.

**Alternative considered and rejected (original plan, superseded):** combining role-grant AND
redirect into a single `form_alter`-only implementation. Rejected in favor of the split above
because the insert-hook half has a direct, already-shipped precedent
(`DoNotificationsHooks::groupRelationshipInsert()`) requiring zero ordering complexity, while only
the redirect half genuinely needs form_alter — splitting the concerns onto the mechanism each
naturally fits is simpler than forcing both through form_alter.

### 6. Preview/guided page — route + controller pattern to model
`do_group_membership.routing.yml` + `ManageMembersController` (DI via `ContainerInjectionInterface::create()`,
`_custom_access` callback, `entity:group` param upcasting). Existing routes use `_form` (the
controller class only holds the shared `_custom_access` callback); this new route is content-only,
so it is the module's first `_controller`-based route. New route:
```yaml
do_group_membership.group_created_preview:
  path: '/group/{group}/created'
  defaults:
    _controller: '\Drupal\do_group_membership\Controller\GroupCreatedPreviewController::view'
    _title: 'Your group is ready'
  requirements:
    _custom_access: '\Drupal\do_group_membership\Controller\GroupCreatedPreviewController::access'
  options:
    parameters:
      group:
        type: entity:group
```
Controller renders a render array: group label/description, and CTA links to:
- edit group (`entity.group.edit_form`)
- manage members (`do_group_membership.manage_members`)
- view group (`entity.group.canonical`)
Access: only the group's owner (or an Organizer/site-admin) may view — reuse the same
`hasPermission('administer members', $account)` shape from `ManageMembersController::access()`,
OR simpler: `$account->id() === $group->getOwnerId() || $group->hasPermission('administer members', $account)`.

### 7. CSS
No `create-group.css` exists yet (new, per issue's "Owns" list). Subtheme CSS location — need to
confirm subtheme path convention; `manage-members.css` lives at
`docs/groups/modules/do_group_membership/css/manage-members.css` and is wired via
`do_group_membership.libraries.yml`. **New CSS should follow this exact same convention** — new
file `docs/groups/modules/do_group_membership/css/create-group.css` + new library entry in
`do_group_membership.libraries.yml`, attached via the controller's render array
`#attached: ['library' => ['do_group_membership/group_created_preview']]`. This satisfies "subtheme
CSS create-group.css (new)" from the issue's Owns list without introducing a new module or theme
touch (do_group_membership already handles its own CSS as shown by manage-members.css — no
separate subtheme module edit needed; if this reviewer's understanding of "subtheme" differs from
this repo's existing CSS-per-module convention, A will flag it).

### 8. E2E test
`tests/e2e/create-group.spec.ts` (new) — no existing file at this path;
`docs/groups/../tests/e2e/` convention to confirm at F/T time (existing spec files under `tests/e2e/`
at repo root, sibling to `docs/groups/`, per project root note in orchestrator instructions
referencing `npx playwright test`).

### 9. Locator gotchas (per orchestrator brief + prior stories)
- `#type => submit` renders `<input type="submit">`, NOT `<button>` — Playwright locators must use
  `input[type=submit]` or `getByRole('button', { name: ... })` (Playwright treats `<input
  type=submit>` as role `button` — safe) but NOT assume a `<button>` tag exists in the DOM.
  `CreatorMembershipFormTest` confirms button text `"Create Community Group and become a member"`
  — the E2E spec must match that exact label (from `creator_membership: true` +
  `creator_wizard: true`, need to verify exact suffix text FROM THE ACTUAL FORM at T/F time, not
  assumed).
- `getByLabel(/.../)` strict-mode collisions on seeded pages — scope locators to the specific form.

## Reuse & Analogous-Feature map (summary)

| Concern | Analogous/existing code | Action |
|---|---|---|
| Organizer role grant on existing membership | `step_790_persona_switcher.php:94-130` pattern; `GroupMembershipManager` consts | **EXTEND** `GroupMembershipManager` with new `ensureRole()` method |
| Role-grant hookpoint | `do_notifications/src/Hook/DoNotificationsHooks.php:165-198` (`#[Hook('group_relationship_insert')]`, exact signature match) | **NEW hook method** in `do_group_membership/src/Hook/` (justified — no existing do_group_membership hook targets `group_relationship_insert`; `GroupAccessHook` targets `group_relationship_create_access` only, a different hook) |
| Redirect-after-create hookpoint | `do_multigroup/src/Hook/DoMultigroupHooks.php:168-187` (`form_alter`, `OrderAfter` fallback if needed); `RequestJoinForm.php`/`AddMemberForm.php` (`setRedirect()` idiom) | **NEW hook method**, same class as role-grant (one "create-group flow" concern) |
| Guided-preview route+controller | `do_group_membership.routing.yml` + `ManageMembersController` (DI, `_custom_access`, `entity:group` upcasting) | **NEW controller** (justified — first content-only, non-form route in this module) + **NEW route** |
| CSS | `manage-members.css` + `do_group_membership.libraries.yml` | **NEW** `create-group.css` + new library entry, same convention |
| E2E | (none yet at this path) | **NEW** `tests/e2e/create-group.spec.ts` |

**Overall extend-vs-new verdict:** Extend `do_group_membership` module (no new module). ONE new
justified Hook class (e.g. `CreateGroupOrganizerHook`) holding TWO `#[Hook]` methods
(`group_relationship_insert` for the role grant, `form_alter` for the redirect) — both serve the
identical "create-group flow" concern, so keeping them together aids readability (unlike
`do_chrome`'s genuinely-separate tooltip surfaces, which warrant separate classes). One new
Controller (`GroupCreatedPreviewController`), one new manager method (`ensureRole()`), one new
route, one new CSS file + library entry. No forking of #36's creator-membership mechanism — the
insert hook runs strictly AFTER Group's own form-save and only ADDS the Organizer role to the
membership Group's own form-save already created; the redirect hook only changes where the
response goes, not what gets saved.

## Forward-compat check
No downstream phase in the epic (#137) is known to consume a contract this phase produces (this is
a leaf UI feature, not a shared shell/partial). N/A.

## Open questions for A (architecture-reviewer)
1. Confirm `ensureRole()` on `GroupMembershipManager` (additive, non-destructive to existing roles)
   is the right shape vs. reusing/generalizing `changeRole()`.
2. Confirm ONE hook class with two `#[Hook]` methods (role-grant + redirect) vs. splitting into two
   classes — survey recommends one class (single "create-group flow" concern).
3. Confirm the exact `$form_id` for the community_group add form, and whether appending the
   redirect submit handler (default array order) is sufficient or whether `OrderAfter` is needed —
   **F verifies empirically**, not a guess.
