# Handoff-F: Phase 5 - #144 MC-6 Create-Group flow (creator auto-Organizer + guided preview)

**Date:** 2026-07-23
**Branch:** 144-auto-organizer
**Issue:** #144

## What was done

- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` —
  added `ensureRole(GroupRelationshipInterface $relationship, string $role_id): void`.
  Early-returns via existing `hasRole()` if already present; otherwise reads
  the current `group_roles` values, appends `$role_id`, and saves. Never
  calls `setValue()`/replace; never invokes the last-Organizer guard.
- `docs/groups/modules/do_group_membership/src/Hook/CreateGroupOrganizerHook.php`
  (new) — one class, two `#[Hook]` methods:
  - `groupRelationshipInsert()` (`#[Hook('group_relationship_insert')]`) —
    filters to `plugin_id === 'group_membership'` AND member-uid ===
    group's owner id, then calls `GroupMembershipManager::ensureRole()`.
  - `formAlter()` (`#[Hook('form_alter')]`) — filters to form_id
    `group_community_group_add_form`, appends `redirectToPreview` (a static
    submit handler) to the submit button's `#submit` array. No `OrderAfter`
    fallback needed (see Empirical decisions below).
  - `redirectToPreview()` also sets a `messenger()->addStatus()` confirmation
    (A finding #3, optional — included, low risk).
- `docs/groups/modules/do_group_membership/src/Controller/GroupCreatedPreviewController.php`
  (new) — `ContainerInjectionInterface`, `StringTranslationTrait`. `title()`
  supplies the page's sole h1 via `_title_callback` (see Deviations below).
  `view()` returns p/h2/ul>li>a x3 in wireframe DOM order, with cache
  metadata (group cache tags, `user.permissions` context) and the
  `group_created_preview` library attached. `access()` mirrors
  `ManageMembersController::access()`'s shape (owner OR `administer members`
  OR `administer group`), with a `(string)`-normalized owner-id comparison.
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` —
  added `do_group_membership.group_created_preview`
  (`/group/{group}/created`), using `_title_callback` (not `_title`).
- `docs/groups/modules/do_group_membership/do_group_membership.services.yml` —
  registered `CreateGroupOrganizerHook`, FQCN-keyed, `autowire: false`,
  `@do_group_membership.manager` arg, matching `GroupAccessHook`'s precedent.
- `docs/groups/modules/do_group_membership/do_group_membership.libraries.yml` —
  added `group_created_preview` (`css/create-group.css`).
- `docs/groups/modules/do_group_membership/css/create-group.css` (new) —
  spacing/list-reset only, `.do-group-membership--next-steps` class, no hex
  colors.
- `docs/groups/modules/do_tests/tests/src/Functional/CreateGroupWizardOrganizerTest.php`
  — T-red-flagged fixture fix (delegated to F): rewrote
  `importRealCommunityGroupConfig()`'s field-storage-handling only (no
  assertion touched). See "T-red fixture-helper fix status" below for the
  full multi-gap story.

## Design decisions

- **`_title_callback` instead of `_title`** — the wireframe specifies
  exactly ONE `<h1>` naming the group as the page's first content element.
  A static/dynamic `_title` route default PLUS an in-content `<h1>` in the
  controller's render array produces TWO `<h1>` elements (the theme's
  page-title block renders `_title` as its own `<h1>`, independent of
  content). Discovered via a throwaway Playwright-style Functional probe
  (deleted before this handoff) showing `h1_elements` count = 2. Fix: route
  uses `_title_callback: GroupCreatedPreviewController::title()`; the
  controller's `view()` supplies only p/h2/ul (everything after the h1).
  DOM order (h1 -> p -> h2 -> ul>li>a x3) is preserved because the theme
  renders the title block before the main content region.
- **Messenger status message included** (A finding #3, optional) — low
  risk, matches Drupal's post-save convention, does not complicate the
  redirect handler meaningfully.

## Reuse / extend-vs-new

Extended `do_group_membership` per the brief's Reuse map — no new module.
`GroupMembershipManager::ensureRole()` extends the existing manager (not a
new class). `CreateGroupOrganizerHook` is the ONE brief-justified new hook
class (survey/A confirmed: not folded into `GroupAccessHook`, a different
hook/concern). `GroupCreatedPreviewController` is the brief-justified new
controller (module's first content-only, non-form route). No parallel path
created where the brief called for extension.

## Architecture notes for A

- New route is `_controller`-based (first in this module; every other route
  is `_form`), matching the survey's "content-only, so `_controller` is more
  appropriate" note.
- Hook-service registration follows `GroupAccessHook`'s exact FQCN-keyed
  `autowire: false` convention (required because `GroupMembershipManager`
  isn't autowire-aliased).
- No schema/contract changes to shared entities — `ensureRole()` mutates an
  existing field (`group_roles`) additively; no new fields, no config
  schema changes to production config (only the disposable test fixture's
  import mechanism changed).
- `redirectToPreview()` is a static submit handler (matches
  `DoMultigroupHooks::nodeFormSubmit()`'s convention for serializable submit
  callbacks).

## Deviations from spec / wireframe

- **Route uses `_title_callback` instead of the brief's literal
  `_title: 'Your group is ready'`** — necessary to avoid a duplicate `<h1>`
  (see Design decisions above). The static `'Your group is ready'` string
  from the brief/survey is used ONLY as this file's own prose reference; the
  actual page `<title>` (browser tab) and `<h1>` both now read `Your group
  "{label}" is ready!` per the wireframe's exact heading copy — this is
  MORE aligned with the wireframe than the brief's generic placeholder
  title was, not a regression.
- No other deviations from the brief, survey, wireframe, or handoff-A.md.

## Tier 1 self-check (incl. tests now GREEN)

Ran via `ddev exec` inside a fresh `gm144-auto-organizer` DDEV project
(renamed from the worktree's stale `pl-groups-on-d11` copy; stopped/removed
at the end of this phase — no other worktree/project touched).

```
bash scripts/ci/assemble-config.sh   # (run via `ddev exec bash ...` — no local php binary on this host)

# Step 1 (ensureRole): 2/2 GREEN
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  docs/groups/modules/do_group_membership/tests/src/Unit/EnsureRoleTest.php
# => OK, but there were issues! Tests: 2, Assertions: 12, Deprecations: 3.

# Step 2 (insert hook, both ensureRole halves + insert-hook halves): 4/4 GREEN
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  docs/groups/modules/do_group_membership/tests/src/Kernel/CreateGroupOrganizerHookTest.php
# => OK, but there were issues! Tests: 4, Assertions: 100, Deprecations: 3.

# Full do_group_membership Unit+Kernel regression: 43/43 GREEN
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  docs/groups/modules/do_group_membership/tests/src/Unit \
  docs/groups/modules/do_group_membership/tests/src/Kernel
# => OK, but there were issues! Tests: 43, Assertions: 694, Deprecations: 3, PHPUnit Deprecations: 39.

# #36 regression (must-not-touch): 3/3 GREEN
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  docs/groups/modules/do_tests/tests/src/Functional/CreatorMembershipFormTest.php \
  docs/groups/modules/do_tests/tests/src/Kernel/CreatorMembershipApiTest.php
# => OK, but there were issues! Tests: 3, Assertions: 54, Deprecations: 1.

# CreateGroupWizardOrganizerTest (AC-1/AC-2/AC-3, the real wizard-driven
# end-to-end proof): 1/1 GREEN — see "T-red fixture-helper fix status" for
# the full multi-gap fix story this test needed.
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  docs/groups/modules/do_tests/tests/src/Functional/CreateGroupWizardOrganizerTest.php
# => OK, but there were issues! Tests: 1, Assertions: 17, Deprecations: 3.

# Lint (correct standard — see Known issues re: bare `phpcs` invocation):
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_group_membership/src/Hook/CreateGroupOrganizerHook.php \
  docs/groups/modules/do_group_membership/src/Controller/GroupCreatedPreviewController.php \
  docs/groups/modules/do_group_membership/src/GroupMembershipManager.php
# => exit code 0, zero errors, zero warnings.
```

**Combined final run** (Unit+Kernel do_group_membership + the three
Functional/Kernel files above), all in one PHPUnit invocation: **47/47
GREEN**, deprecation warnings only, zero failures/errors.

## Tests that look wrong (for T)

1. **`GroupCreatedPreviewControllerTest.php` (T-authored, NOT modified by
   F) is missing `do_group_membership` from its `protected static $modules`
   array.** It only inherits `GroupBrowserTestBase::$modules = ['group']`,
   so the new route genuinely 404s in THIS test's own environment (not
   because the route/controller are wrong — verified via a throwaway probe
   copy of this exact test with `'do_group_membership'` added to `$modules`,
   which passed 2/2 cleanly, then deleted before this handoff). Fix needed:
   add `protected static $modules = ['group', 'do_group_membership'];` (or
   similar) to this test class. I did NOT edit this file myself — it is a
   different file/defect class than the `CreateGroupWizardOrganizerTest`
   fixture-helper fix explicitly delegated to me in this issue's task text.
2. `phpcs` run bare (`php vendor/bin/phpcs docs/groups/modules/do_group_membership`,
   no `--standard` flag) reports thousands of errors across EVERY file in
   the module, including untouched pre-existing files — this is because no
   `--standard` is specified and it falls back to a default ruleset (not
   this project's `Drupal,DrupalPractice` convention). Not a test per se,
   but flagging since the issue's own verification-commands section uses
   the bare form. `--standard=Drupal,DrupalPractice` must be passed
   explicitly (confirmed working: 0 errors/warnings on all 3 changed
   production PHP files with the flag).

## Known issues

None blocking. Two non-blocking items:
1. `GroupCreatedPreviewControllerTest.php`'s missing-`$modules` bug (above)
   — needs T-green's fix; my controller/route/access logic is independently
   verified correct via the throwaway probe.
2. `phpcbf`/`phpcs` docblock-style findings on
   `CreateGroupWizardOrganizerTest.php` (lines 14/147/308/333/370/437 per a
   `--standard=Drupal,DrupalPractice` run) are ALL pre-existing in T's
   original authored file (confirmed via `git show HEAD:<path>` diff — none
   of these four docblock-style/fully-qualified-class-reference patterns
   were introduced by my edit; I preserved T's exact style in the two loops
   I split the original single loop into). Not fixed, since cleaning up
   T's authored prose/style beyond the delegated fixture-mechanism fix would
   be scope creep into test authorship.

## T-red fixture-helper fix status: FIXED (non-trivial — 6 sequential gaps)

T-red flagged one known gap (field-storage config imported via
`config.storage->write()` skips schema-installation, so the DB table for
`field_group_description` never gets created) and recommended option (a):
switch to `FieldStorageConfig::create($data)->save()`. Empirically, this
recommendation needed real correction — the FULL entity-API save triggers
`Config::save()`'s schema-VALIDATION path, which itself threw an unrelated
`InvalidArgumentException` on `list_string` fields' `allowed_values` in this
minimal test environment (reproduced in isolation; not a data-shape bug).
The surgical fix that ultimately worked, across 6 rounds of empirical
correction (all documented in the file's own docblock, `importRealCommunityGroupConfig()`):

1. (T-red's own gap) field-storage table creation — fixed via raw
   `config.storage->write()` + manually invoking
   `field_storage_definition.listener`'s `onFieldStorageDefinitionCreate()`
   (the same call `FieldStorageConfig::postSave()` itself makes), instead of
   the full entity-API save.
2. `entity_type.bundle.info`'s cached bundle list (for `group_relationship`)
   was stale — needed `clearCachedBundles()`.
3. PHP8.1+ `hash(null, ...)` deprecation-as-error from
   `DefaultTableMapping::generateFieldTableName()` — `FieldStorageConfig::
   getUniqueStorageIdentifier()` needs a real `uuid`, so field-storage files
   (ONLY) keep their `uuid` key when written (every other config type still
   strips it, unaffected).
4. Group's OWN `GroupRelationTypeManager` caches its group-type-to-plugin
   map in `cache.discovery`, independent of every core cache above — fixed
   via the officially-supported `clearCachedPluginMaps()` method on the
   `group_relation_type.manager` service.

`testWizardCreateGrantsOrganizerAndRedirectsToPreview` (the ONLY test in
this file) is now 1/1 GREEN, independently confirming AC-1 (additive
Organizer grant), AC-2 (creator can edit/administer members), and AC-3
(redirect to `/group/{group}/created`, not canonical) against the REAL
assembled `community_group` config through the real form submission —
including confirming the wizard resolves as effectively single-step in this
environment too (matching T-red's own empirical finding, now confirmed
architecturally — see the class docblock's "F EMPIRICAL FINDING" note).
ONLY `importRealCommunityGroupConfig()` was changed; no assertion in
`testWizardCreateGrantsOrganizerAndRedirectsToPreview()` or
`advanceThroughWizard()` was touched.

## Empirical decisions (form_id / OrderAfter / wizard shape)

- **Real form_id confirmed: `group_community_group_add_form`.** Verified
  by reading core source directly (`EntityForm::getFormId()` derives
  `{entity_type}_{bundle}_{operation}_form`; `Group.php`'s entity annotation
  has no per-bundle form-class override for `add`/`edit`), NOT a guess.
- **`creator_wizard: true` does NOT produce a multi-step Form-API wizard.**
  Confirmed by reading `GroupForm::form()`/`CreateFormEnhancer::
  enhanceGroupForm()` directly: `creator_wizard` gates whether
  `enhanceGroupForm()` runs at all (single form, enhanced with extra
  fields/details), not a separate wizard controller with distinct steps/
  URLs. This matches T-red's own hedged empirical finding
  (`handoff-T-red.md` "Ambiguity for O" #1) — now confirmed
  architecturally, not just observationally. `advanceThroughWizard()`'s
  defensive multi-step walk still works correctly (resolves in exactly one
  iteration) and needed no changes.
- **Plain-append (no `OrderAfter` fallback) is sufficient for the redirect.**
  Confirmed by reading `GroupForm::save()` directly: it calls
  `$form_state->setRedirect('entity.group.canonical', ...)` as part of the
  BASE `#submit` array (`['::submitForm', '::save']`, set at form-build
  time by `EntityForm::actions()`/`GroupForm::actions()`), which is already
  populated BEFORE `hook_form_alter()` ever runs. My hook's `form_alter`
  simply appends to this already-populated array, so `redirectToPreview()`
  runs strictly AFTER `save()`, and `FormState::setRedirectUrl()`'s
  last-write-wins semantics (`$this->redirect = $url`, no accumulation)
  mean my `setRedirect()` call wins. Empirically confirmed end-to-end by
  `CreateGroupWizardOrganizerTest`'s AC-3 assertion passing (landing on
  `/group/{group}/created`, not canonical) — no `OrderAfter` fallback
  needed, matching A's best-effort prediction.

## Brief ambiguity encountered

None that blocked implementation. The brief's `_title: 'Your group is
ready'` literal (survey §6) turned out to be incompatible with AC-5's
"exactly one h1" once the render pipeline was actually run (a duplicate-h1
issue only observable empirically, not from reading the YAML/render-array
code alone) — resolved via `_title_callback` (see Deviations above), which
I judge as a refinement consistent with the wireframe's own heading-copy
spec (`Your group "{Group Name}" is ready!`) rather than a genuine
ambiguity or brief defect.

## Files changed

- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` (modified)
- `docs/groups/modules/do_group_membership/src/Hook/CreateGroupOrganizerHook.php` (new)
- `docs/groups/modules/do_group_membership/src/Controller/GroupCreatedPreviewController.php` (new)
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` (modified)
- `docs/groups/modules/do_group_membership/do_group_membership.services.yml` (modified)
- `docs/groups/modules/do_group_membership/do_group_membership.libraries.yml` (modified)
- `docs/groups/modules/do_group_membership/css/create-group.css` (new)
- `docs/groups/modules/do_tests/tests/src/Functional/CreateGroupWizardOrganizerTest.php`
  (modified — ONLY `importRealCommunityGroupConfig()`'s field-storage-import
  mechanism, per the brief's explicit F-delegation; no assertion touched)

**NOT staged/committed by F** (per constraints — O handles): `config/sync/*.yml`
(touched by `scripts/ci/assemble-config.sh`, must be reverted before staging),
`web/modules/custom/*` (gitignored-equivalent build artifact, untracked).
