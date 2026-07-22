# Survey — Issue #138 (MC-7): Organizer Manage-members UI + group roles + Groups Moderate

Run slug: `0138-mc7-manage-members`
Surveyed by: O-138
Date: 2026-07-22

> **Adoption note (this run):** this survey was salvaged from a prior run whose worktree was
> reaped. It has been re-verified against the current repo (post PR #146 / #91 permission-matrix
> merge) rather than re-run from scratch — see
> `docs/handoffs/0138-mc7-manage-members/decisions.md` Phase-0/1 entry for the verification
> record and one new finding: `do_chrome/src/PermissionMatrix.php` (landed via #91/PR #146) still
> encodes the OLD role scheme and is explicitly NOT touched by this story (out of scope, #91's own
> follow-up). All claims below were spot-checked true as of 2026-07-22 against
> `origin/main` @ c18f417 unless noted.

## Scope recap (from issue #138 + epic #137)

- Define Group roles Organizer / Moderator / Member on `community_group`.
- Extend the `group_membership` relationship with status (member/organizer/moderator/pending/blocked)
  + joined date.
- Manage-members tab (organizer/admin only): list members + status, add/remove, change role,
  approve/deny pending requests.
- Site-level "Groups Moderate" global permission/role administering any group without membership.
- WCAG 2.2 AA. Edit access flows from the Organizer **group role**, not a field.
- Foundational for #144 (create-flow), #120 (persona switcher), #91 (permission matrix).

## Repo topology — the load-bearing finding

Two config trees exist and are **not** interchangeable:

- **`docs/groups/config/`** — the RUNBOOK's staging/source-of-truth config tree (96 files). This is
  what a human/agent hand-edits per `docs/groups/RUNBOOK.md` ("All configuration is managed as YAML,
  never through the Drupal UI").
- **`config/sync/`** — the live Drupal config-sync export (many more files: full Olivero/Claro block
  layout, all core config, etc.). This is what `drush cim` actually imports and what CI/DDEV boot
  against.

**`scripts/ci/assemble-config.sh`** is the single mechanism that reconciles them: it copies every
`docs/groups/config/*.yml` into `config/sync/` (excluding 7 env-specific files — none role-related)
and copies every `docs/groups/modules/*` directory into `web/modules/custom/`, then patches
`config/sync/core.extension.yml` to enable the copied modules. `.github/workflows/test.yml` runs this
script before every kernel/functional/E2E job. **Consequence for this story:** I author config in
`docs/groups/config/` and module code in `docs/groups/modules/do_group_membership/` (new module,
following the `do_*` convention) — never hand-edit `config/sync/` or `web/modules/custom/` directly;
`assemble-config.sh` is what materializes them, and CI/local verification both depend on running it.

## Relevant code mapped

| File | Role |
|---|---|
| `docs/groups/config/group.type.community_group.yml`* (not present — group type lives only in `config/sync/group.type.community_group.yml`) | The `community_group` group type definition (creator_membership: true). |
| `config/sync/group.role.community_group-{admin,anonymous,member,outsider}.yml` | **Live** group roles today. `admin` (scope=individual, admin:true, permissions: `{}` — relies on the `admin` flag to bypass all group permission checks), `member`/`anonymous`/`outsider` (scope=individual, non-admin, `view group_node:*` permissions only). No `organizer` or `moderator` role exists anywhere. |
| `docs/groups/config/group.role.community_group-{admin,anon_view,insider_view,outsider_view}.yml` | An **earlier RUNBOOK-phase** subset mirroring a slightly different naming scheme (`anon_view` etc. vs. live `anonymous`). Only `admin` overlaps 1:1 (identical UUID/content) with `config/sync`'s `admin`. This tree is stale relative to `config/sync` for the other three — a pre-existing drift, out of this story's scope to reconcile beyond not making it worse. |
| `config/sync/group.relationship_type.community_group-group_membership.yml` / `docs/groups/config/group.relationship_type.community_group-group_membership.yml` (identical) | The `group_membership` relationship type — `group_cardinality: 0`, `entity_cardinality: 1`, `use_creation_wizard: false`. This is the relationship entity I extend with `status` + `joined date` fields. |
| `field.storage.group_relationship.group_roles.yml` + `field.field.group_relationship.community_group-group_membership.group_roles.yml` | The **existing** `group_roles` entity-reference field on the membership relationship (`entity_reference` → `group_role`, unlimited cardinality). This is the Group module's standard mechanism for "which role(s) does this membership carry" — Organizer/Moderator/Member assignment is a `group_roles` value, not a new field. |
| `core.entity_form_display.group_relationship.community_group-group_membership.default.yml` / `core.entity_view_display...default.yml` | Form/view display config for the membership relationship — will need a widget/formatter entry for the new `status` field if it's user-facing via the standard entity form (it will not be; the Manage-members tab is a custom controller, not the generic Group UI, so these displays are lower priority — confirmed below under Reuse map). |
| `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` | Existing global-permission pattern: `$this->currentUser->hasPermission('administer group')` / `'administer groups'` already gates group-admin bypass logic (used for the "auto-unpublish new group unless admin" rule). **This is the exact permission name pattern "Groups Moderate" should anchor to** — Group module ships `administer group` as an omnipotent-bypass permission recognized by its own access-control handlers; a global role carrying it satisfies "administers any group without membership" without inventing new Group-module wiring. |
| `docs/groups/RUNBOOK.md` (Step ~"Verify: navigate to `/admin/groups/pending`") | Confirms `administer group` already gates a pending-groups admin view — establishing precedent for a `/group/{group}/members` (or similar) admin-gated route. |
| `docs/groups/modules/do_notifications/{routing.yml,links.task.yml,src/Controller/NotificationSettingsController.php}` | **Closest structural analog** for "a route + controller + local task tab, entity-context-aware, permission-gated, rendering a settings/management page for an entity". Uses `ControllerBase`, injects one service (`flag`) via `create()`, has a `{user}` route param upcast to `UserInterface`, local task attached via `base_route`. |
| `docs/groups/modules/do_discovery/{routing.yml,src/Controller/IcalController.php}` | Closest analog for `{group}` route-parameter upcasting to `GroupInterface` (`type: entity:group` in routing options), used for group-context admin/read endpoints. |
| `docs/playbook/frameworks/drupal/best-practices.md` §"Custom Module Architecture: Services over Hooks" | **Mandatory architecture**: business logic lives in an injected service class (`MyModuleManager`), the `.module` file (or `#[Hook]` PHP-attribute class, per `do_group_extras`'s newer pattern) is a thin wrapper, controllers delegate to the service. Every service needs a Unit test with mocked deps. |
| `docs/groups/modules/do_tests/tests/src/Kernel/{GroupsKernelTestBase.php,AccessPolicyEnforcementTest.php}` | **T's test fixture base.** `GroupsKernelTestBase` builds a real `community_group` type + relationship types via 4.x storage APIs (not by reading YAML) and exposes `createGroup()`, `addMember($group, $account, $roles = [])`, using Group's own `GroupTestTrait`. `AccessPolicyEnforcementTest` demonstrates asserting `$group->hasPermission(...)` across outsider/insider/individual scopes and via the raw `access_policy_processor` — this is the enforcement-path pattern T should reuse for "Organizer can manage members / Member cannot / Groups-Moderate can manage a non-member group". |
| `.github/workflows/test.yml` | CI job discovery: Kernel and Functional suites are auto-discovered via `find web/modules/custom -type d -path '*/tests/src/{Kernel,Functional}'` — a new module's tests are picked up automatically once `assemble-config.sh` places it, no CI-file edit needed. E2E job seeds full demo data via `docs/groups/scripts/step_7{00,20,80}_*.php` then serves via `drush runserver` and runs `npx playwright test` (full suite, not just new specs). |
| `tests/e2e/{directory-cards,nav,phase1-4}.spec.ts` | Playwright conventions: `test.describe('CH-X — Title (#issue)')`, a shared `login(page, user, pass)` helper reading `ADMIN_USER`/`ADMIN_PASS` env vars (default `admin`/`admin`), locator classes prefixed `.gc-` or similar BEM-ish component class, asserting on rendered DOM not network calls. |
| `web/themes/custom/groups_chrome/` | The only custom theme (`groups_chrome`) — subtheme CSS for `manage-members.css` goes under `docs/groups/modules/do_group_membership/css/` if the CSS ships with the module (matching `do_group_extras`/`do_chrome`/`do_multigroup` precedent, all of which ship their own `css/*.css` + `*.libraries.yml` rather than editing the theme directly), OR under `web/themes/custom/groups_chrome/css/` if it must be theme-level. **Recommendation: module-owned CSS** (matches every existing `do_*` module with a UI surface — do_chrome, do_group_extras, do_multigroup, do_profile_stats all ship `css/<module>.css` + a `.libraries.yml`), attached only on the Manage-members route via `#attached['library']`. This avoids touching the shared theme (coexistence single-owner rule) and matches issue's own path guess `.../css/manage-members.css` closely enough (module CSS dir, not theme CSS dir). |
| `docs/groups/scripts/` (`step_700_demo_data.php`, `step_720_group_types.php`) | Existing membership-creation seed scripts — if the new `status`/`joined date` fields need seed defaults for demo groups to look populated (e.g. one pending request per group for the Playwright approve/deny test to exercise), a new or amended seed step may be needed. Flagged as a design question for D/brief, not pre-decided here. |

## Reuse & Analogous-Feature map (required)

- **Relevant code mapped:** see table above.
- **Closest analogous feature:** the existing `group_roles` field + Group's built-in
  admin/member/anonymous/outsider role scheme, PLUS the `do_notifications` settings-page pattern
  (route + controller + local task, entity-context-aware) as the UI-surface analog.
- **Objects this change would touch:**
  - `group.role.community_group-{organizer,moderator,member}.yml` (NEW config entities)
  - `group_relationship_type.community_group-group_membership` (extend: add `status` + `joined_date`
    fields — new `field.storage.group_relationship.membership_status.yml` /
    `field.field.group_relationship.community_group-group_membership.membership_status.yml`, and
    likewise for a joined-date field, or reuse the relationship entity's own `created` base field for
    "joined date" — see Forward-compat / Design note below)
  - A new custom module `do_group_membership` (route, controller, service, permissions,
    manage-members template, CSS)
  - `user.role.groups_moderate.yml` (NEW site-level role, or equivalently
    `user.permissions` additions to an existing role — default: new dedicated role, matching the
    `content_editor` precedent in `config/sync/user.role.content_editor.yml`)
- **Extend-vs-new recommendation:**
  - Group roles: **EXTEND** the existing `group.role.community_group-*` family — add three new
    sibling config entities (`organizer`, `moderator`, `member`) using the same schema as the current
    `admin`/`member` roles. Do NOT touch `admin`/`anonymous`/`outsider` (those are outside this
    story's Owns list and #121/#134 territory).
    - Naming decision: the issue names the three roles `organizer`/`moderator`/`member`. A
      `community_group-member` role **already exists** in `config/sync` (scope=individual,
      `view group_node:*` perms). Per "extend, don't duplicate": **reuse/extend the existing
      `community_group-member` role** as the Member role in this scheme rather than creating a
      parallel `community_group-member2` or similar — confirmed no ID collision risk since the issue
      explicitly names the target ID `community_group-member`, matching what's live today. Organizer
      and Moderator are genuinely new roles (no existing analog carries `admin`-equivalent or
      moderation-scoped permissions), so **NEW** for those two, justified: the existing `admin` role
      has `admin: true` (unconditional bypass of ALL group permission checks) which is broader than
      "Organizer manages membership" — collapsing Organizer into `admin` would over-grant. Moderator
      is a distinct narrower scope (per the issue: role management + pending approval, likely without
      full group-settings edit). Both are new, justified by needing genuinely different permission
      sets than any existing role.
  - `group_membership` relationship status: **EXTEND** the existing
    `community_group-group_membership` relationship type by adding new fields — do not create a
    parallel relationship type.
  - Manage-members UI: **NEW** module `do_group_membership` — justified because no existing `do_*`
    module owns membership-management UI; `do_notifications` is a structural analog but a different
    domain (notification prefs, not membership admin), and folding membership-admin into it would
    violate its single responsibility. This matches the issue's own "Owns" list (new module files).
  - Groups-Moderate: **NEW** site-level role — justified because no existing role carries
    `administer group` combined with a Groups-specific label; `content_editor` is a different domain
    (content editing permissions, not group administration).

## Forward-compat check (required — this story creates a shared contract)

This story is explicitly foundational for #144 (create-flow), #120 (persona switcher), #91
(permission matrix). Extracting what each downstream story needs from this story's output:

| Consumer story | Required capability | Satisfied? |
|---|---|---|
| #144 (MC-6, creator auto-becomes Organizer) | A group-role ID `community_group-organizer` (or stable equivalent) that #144 can assign programmatically to a group's creator at creation time, mirroring the existing `addMember($group, $account, $roles)` API already used by `GroupsKernelTestBase`/`do_tests`. | **Yes** — `group.role.community_group-organizer.yml` will exist with a stable ID; `Group::addMember()` / the `group_roles` field accepts any group-role ID by design, so #144 can pass `['community_group-organizer']` without any new API from this story. No blocking dependency beyond the role existing. |
| #120 (persona switcher) | A well-known role ID (or a small fixed set of IDs) per persona: "Organizer", "Moderator", "Member", "Groups-Moderate" (global) — plus a way to check "is this user a Groups-Moderate global user" distinct from group membership. | **Yes** — `community_group-{organizer,moderator,member}` group-role IDs and a `groups_moderate` (or similarly named) global `user.role` ID, checkable via `$account->hasPermission('administer group')` or a role-ID check (`$account->hasRole('groups_moderate')`), are exactly what a persona switcher needs to impersonate/represent each persona. No conflicting shape identified. |
| #91 (permission matrix) | A concrete, inspectable permission-to-role mapping that #91 can render as a matrix — i.e., the four group roles' `permissions:` lists plus the global role's permission list must be real, non-empty, and documented (not just role *names* with empty permission sets). | **Needs discipline, not a blocker**: I must ensure `organizer`/`moderator`/`member` config each carry an explicit, non-trivial `permissions:` list (not `{}`) so #91 has real data to render. This is a design/brief-writing responsibility, not a structural conflict — recorded here so brief.md's acceptance criteria include "each role's permissions list is explicit and documented," not just "roles exist." |

No conflicts found between the three consumers' required shapes — all three are satisfied by the
same underlying config (three group-role config entities + one global role), so this story's design
does not need to compromise between them. **Forward-compat: satisfied, no halt.**

## Existing test coverage that will be touched or must be extended

- No existing test references `community_group-organizer`, `community_group-moderator`, or a
  membership `status` field — this is genuinely new coverage, nothing breaks by adding it.
- `AccessPolicyEnforcementTest` and `GroupsKernelTestBase` are **not modified** by this story (they
  are Group-module-generic fixtures) but T should **reuse** `GroupsKernelTestBase` (via a new test
  class in `do_group_membership/tests/src/Kernel/`) rather than duplicating its group-type/relationship
  bootstrap.
- `tests/e2e/manage-members.spec.ts` is new (named in the issue's Owns list) — no existing E2E spec
  touches group roles or membership status, so no regression risk to the existing 3 E2E specs from
  adding a 4th, provided seed data additions (if any) are additive only (per epic #137's "seed script:
  append-only sections" shared-surface rule).

## Key findings for the brief

1. Author config in `docs/groups/config/`, module code in `docs/groups/modules/do_group_membership/`
   — never hand-edit `config/sync/` or `web/modules/custom/` directly; `scripts/ci/assemble-config.sh`
   materializes both, and CI depends on it running first.
2. No conflicting `organizer`/`moderator` role YAML exists anywhere (confirmed: neither `config/sync/`
   nor `docs/groups/config/` has them) — genuinely greenfield, not a #121/#134 collision.
3. `community_group-member` already exists live — extend/reuse that exact ID rather than inventing a
   new one, per the issue's own naming and the extend-first default.
4. `administer group` is the existing Group-module-recognized global bypass permission — anchor
   "Groups Moderate" to it (new `user.role` carrying it, or an equivalent Group-recognized permission)
   rather than inventing new access-control wiring.
5. Manage-members CSS should be module-owned (`do_group_membership/css/manage-members.css` +
   `.libraries.yml`), matching every existing UI-bearing `do_*` module, not edited into
   `groups_chrome` directly (coexistence single-owner rule — the subtheme is shared).
6. Services-over-Hooks is mandatory: a `do_group_membership.manager` (or similarly named) service
   class does the membership CRUD/status-transition logic; the controller and any hook wrappers are
   thin delegators; every service method gets a mocked-dependency Unit test in addition to T's Kernel/
   Functional/E2E coverage.
7. `GroupsKernelTestBase` (from `do_tests`) is the correct base class for T's Kernel tests — reuse,
   don't reinvent group-type bootstrap.
8. Downstream stories (#144/#120/#91) are all satisfiable by this story's planned shape with no
   design compromise required — forward-compat check passes clean.
9. Seed-data changes (if any, for a demo pending-request to exist) must be additive-only per epic
   #137's shared-surface rule on the seed scripts.
10. WCAG 2.2 AA and non-color status conveyance are acceptance criteria D must design for explicitly
    (e.g., a status badge with both a color AND a text/icon label, not color alone).
