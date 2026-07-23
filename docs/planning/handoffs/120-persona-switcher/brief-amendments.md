# Brief Amendments — #120 after A Phase-3 BLOCK (2026-07-22)

Supersedes and extends `brief.md`. A returned BLOCK with 3 blockers + 3 warnings. All accepted.

## Amendment 1 (A blocker #1): Groups-Moderate perms live on the GROUP role, not just the user role
The permission checks in `ManageMembersController` (`administer members`) and `RestoreGroupAccess`
(`edit group`) evaluate GROUP-scoped perms via `$group->hasPermission(...)`. The scope=outsider
group role `docs/groups/config/group.role.community_group-groups_moderate.yml` is where these must
be granted (currently `admin: true, permissions: {}` — bypass mode; hides scope-limit test).

- **EDIT** `group.role.community_group-groups_moderate.yml`: flip `admin: true` -> `admin: false`;
  enumerate the exact perms: `administer members` (covers pending queue / approve / remove per
  ManageMembersController), `edit group` (covers restore per RestoreGroupAccess), plus the archive
  perm from `do_group_extras` (F to inspect its archive controller's access check). NO
  `view group_node:*` perms; Moderator is not a content viewer. Negative test asserts the scope.
- `user.role.groups_moderate.yml` also appended: minimally `access content`.

## Amendment 2 (A blocker #2): DROP `PersonaRegistry`; EXTEND `ShowcaseCatalog::personas()`
`ShowcaseCatalog::personas()` (lines 97-120) already ships the 4-persona list with matching IDs.

- **EDIT** `ShowcaseCatalog.php`: extend each `personas()` entry with two new fields: `uname`
  (NULL for anonymous; `elena_garcia`, `maria_chen`, `groups_moderate_demo` for the others) and
  `tooltip_key` (`persona.anonymous`, `persona.elena`, `persona.maria`, `persona.moderator`).
  Also add a helper `ShowcaseCatalog::personaSpec(string id): ?array` returning one persona by id.
- **DELETE** `PersonaRegistry.php` from the plan — no such file created. `PersonaSwitcher` service
  consumes `ShowcaseCatalog` via constructor injection.

## Amendment 3 (A blocker #3): DROP `drupal/masquerade` dependency; ship bespoke
The plan's "always full logout+login" bypasses every masquerade guarantee; enabling the module
with no consumer is dead wiring.

- **REMOVE** `drupal/masquerade` from `composer.json` + `composer.lock` (DDEV composer update)
  and from `docs/groups/config/core.extension.yml` (module list).
- **DO NOT CREATE** `docs/groups/config/masquerade.settings.yml`.
- **AC UPDATE:** the D11-compat recording becomes "masquerade dep DROPPED; bespoke persona-switch
  path — masquerade is designed for authenticated-to-authenticated switching with unmasquerade
  session preservation; our POC flow is anonymous-to-single-persona (ephemeral), so full
  logout+login is simpler, honest, and needs no dep." Record on GH issue + PR body in place of
  the D11 masquerade-compat verification.
- **NEW** `PersonaSwitchController::switch(string persona)`: `persona=anonymous` -> `user_logout`
  + redirect to referer or `<front>`; allowlisted persona -> load user, `user_login_finalize`,
  redirect back (fallback `<front>` if destination 403s).

## Amendment 4 (A warn #4): Route-level access check
- **NEW** `src/Access/PersonaAccessCheck.php` implementing `AccessInterface`; registered as service
  `do_showcase.persona_access` with tag `access_check` applies_to `[_persona_access]`.
- **EDIT** `do_showcase.routing.yml`: `do_showcase.persona_switch` at `/persona-switch/{persona}`
  with `requirements: { _persona_access: TRUE }`. `methods: [GET, POST]` on the route;
  controller branches on `persona=anonymous` (GET ok) vs any other (require POST, 405 on GET).
- Access check asserts: uid-1 target denied ALWAYS; target uname not in allowlist denied;
  each of 4 allowlisted ids allowed (with `anonymous` always allowed regardless of session).

## Amendment 5 (A warn #5): Split banner into its own `#[Hook('page_top')]` method
- **EDIT** `DoShowcaseHooks.php`: add `personaBanner(array &page_top): void` as a sibling method
  with its own `#[Hook('page_top')]` attribute. DO NOT touch `pageTop()` (ribbon). Both hooks
  fire independently; `page_top` accepts multiple keyed children (`do_showcase_ribbon`,
  `do_showcase_persona_banner`).

## Amendment 6 (A warn #7): Cache contexts `[user]` on both widget + banner
- Widget block render array: `#cache[contexts] => [user]`.
- Banner render array: `#cache[contexts] => [user]`.
- T asserts both on the built render arrays.

## Amendment 7 (A warn #11): Shorten `persona.*` HelpText values to <=140 chars
Per option, must fit an HTML `title=` attribute cleanly. Draft:

- `persona.anonymous` (68) — "The logged-out visitor view (default). No session, no persona."
- `persona.elena` (117) — "Elena Garcia is an active Member across several groups. Plain-member view: can post and join, cannot manage members."
- `persona.maria` (114) — "Maria Chen holds the Organizer role on a seeded group. Can edit the group and manage its members."
- `persona.moderator` (135) — "Groups-Moderate is site moderation. Reviews the pending-join queue and approves, archives, or restores any group. Nothing else."

## Amendment 8 (A warn #8): Seed uses `GroupMembershipManager::STATUS_PENDING`
- Seed `step_790_persona_switcher.php`: import `GroupMembershipManager` and write via the const,
  not the literal string `pending`. Field itself shipped in #138; safe against #121 flow.

## Route/service naming (A warn #10)
- Route id: `do_showcase.persona_switch` at path `/persona-switch/{persona}`. One route, both
  switch-to-persona (POST) and switch-back (`persona=anonymous`, GET).
- Access service: `do_showcase.persona_access` tagged `access_check`.

## Files touched — REVISED

- **NEW** `docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php` (service)
- **NEW** `docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php`
- **NEW** `docs/groups/modules/do_showcase/src/Access/PersonaAccessCheck.php`
- **NEW** `docs/groups/modules/do_showcase/src/Plugin/Block/PersonaSwitcherBlock.php` (block plugin wraps `PersonaSwitcher::build()`)
- **EDIT** `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` (extend `personas()` + add `personaSpec()`)
- **EDIT** `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (add `personaBanner()` sibling `#[Hook('page_top')]` — never touch `pageTop()`)
- **EDIT** `docs/groups/modules/do_showcase/do_showcase.routing.yml` (add `do_showcase.persona_switch`)
- **EDIT** `docs/groups/modules/do_showcase/do_showcase.services.yml` (register `do_showcase.persona_switcher` + `do_showcase.persona_access`)
- **EDIT** `docs/groups/modules/do_showcase/do_showcase.info.yml` (NO masquerade dep)
- **NEW** `docs/groups/modules/do_showcase/css/persona-switcher.css`
- **NEW** `docs/groups/modules/do_showcase/templates/persona-banner.html.twig`
- **NEW** `docs/groups/modules/do_showcase/tests/src/Kernel/Persona*.php` (PersonaSpecTest, PersonaAccessCheckTest, PersonaSwitcherRenderTest)
- **NEW** `docs/groups/modules/do_showcase/tests/src/Functional/Persona*.php` (Dropdown, Banner, AccessNegative, AccessPositive, UidOneGuard)
- **EDIT** `docs/groups/modules/do_chrome/src/HelpText.php` (append 4 `persona.*` keys, <=140 chars each)
- **EDIT** `docs/groups/config/group.role.community_group-groups_moderate.yml` (flip admin, enumerate scoped perms)
- **EDIT** `docs/groups/config/user.role.groups_moderate.yml` (append minimal site perms)
- **EDIT** `composer.json` + `composer.lock` (REMOVE `drupal/masquerade`)
- **EDIT** `docs/groups/config/core.extension.yml` (REMOVE `masquerade` from module list)
- **NEW** `docs/groups/scripts/step_790_persona_switcher.php` (Groups-Moderate account, Maria Organizer group role, one pending join req via `GroupMembershipManager::STATUS_PENDING`)
- **NEW** `tests/e2e/persona-switcher.spec.ts`

## Test surface for T (from A's handoff-A-plan.md §Test surface)

Kernel:
- `PersonaSpecTest` — `ShowcaseCatalog::personas()` returns 4 ids in order, each with non-empty name+description+uname+tooltip_key; moderator uname = `groups_moderate_demo`.
- `PersonaAccessCheckTest` — AccessResult for uid-1 target (denied), non-allowlisted uname (denied), 4 allowlisted ids (allowed), unknown id (denied), `anonymous` always allowed.
- `PersonaSwitcherRenderTest` — `#cache[contexts]` includes `user`, contains 4 `<option>`, current selection matches session.

Functional (BrowserTestBase):
- `PersonaSwitcherDropdownTest` — anonymous sees widget; 4 options with `title=` attribute non-empty; `for`/`id` label association.
- `PersonaBannerTest` — after switch to Elena, banner has `role=status`, exact copy, real `<a>` switch-back link; anonymous session = no banner in DOM.
- `PersonaAccessNegativeTest` — logged in as `groups_moderate_demo`, GET `/admin/config` -> 403, `/admin/people` -> 403, `/admin/modules` -> 403.
- `PersonaAccessPositiveTest` — Moderator: `/group/{n}/members` with pending relationship -> 200 + pending row visible; POST restore on archived group -> succeeds. Maria edit-group -> 200. Elena edit-group -> 403.
- `PersonaUidOneGuardTest` — POST `/persona-switch/root` (or however uid-1 uname is named) -> 403 (never `user_login_finalize`d).

E2E:
- Full switch -> verify banner -> click switch-back -> verify no banner, for `moderator` + `maria-chen`.
- Keyboard-only: Tab to `<select>`, arrow to Maria, Enter/Tab commits, banner appears; Tab to switch-back, Enter, banner gone.
- Visible focus ring on `<select>`, "Go" button, switch-back link (computed style non-zero outline).
