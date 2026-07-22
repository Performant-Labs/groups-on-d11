# Brief — Issue #138 (MC-7): Organizer Manage-members UI + group roles + site-level Groups Moderate

Run slug: `0138-mc7-manage-members`
Repo: `Performant-Labs/groups-on-d11` (GitHub flow: branch on `origin`, PR via `gh`)
Worktree: `/Users/andreangelantoni/Projects/_worktrees/groups-0138-mc7-manage-members`
Branch: `0138-mc7-manage-members` (off `origin/main` @ c18f417)
Survey: `docs/handoffs/0138-mc7-manage-members/survey.md`
Decision journal: `docs/handoffs/0138-mc7-manage-members/decisions.md`

## Review rigor

**`panel`** per the issue's own footer ("Review rigor: panel"). Per the task instructions, this run
uses **second-opinion only** (single o4-mini via `dual-review.sh`, no fresh-Opus panel arm) at the
brief gate and the diff gate — a deliberate, explicitly authorized reduction from `panel` to
`second-opinion` for token-budget reasons. Recorded here so the reduction is visible, not silent.

## Round-1 brief-gate resolution (o4-mini, 2026-07-22)

Round 1 returned **BLOCK** with 7 findings (B-1..B-7) plus 4 WARN and 4 NIT. All BLOCKs are resolved
below by decision (not deferral) before re-submitting. Full transcript:
`docs/handoffs/0138-mc7-manage-members/dual-review-brief.md`.

**[B-5] resolved by direct verification against `drupal/group` 4.0.x source** (fetched from
`git.drupalcode.org/project/group` at `4.0.x`, since no local vendor checkout exists in this
worktree): the brief's original assumption — that a bare `administer group` permission on a global
role bypasses per-group access — was **WRONG** and has been corrected:
- `GroupRole::admin_permission` = `'administer group'` is the **Drupal entity-level admin permission**
  gating who may create/edit/delete `group_role` **config entities** (site administration), NOT a
  per-group membership-management bypass.
- The actual mechanism for "a site-level role administers every group without being a member" is
  **`GroupRoleStorage`**'s **synchronized global role** feature: a `group_role` config entity with
  `scope: insider` (or `outsider`) and `global_role: <a user.role ID>` is automatically applied to
  every account holding that site-level role, for every group of the matching `group_type` — no
  per-group membership relationship needed. Confirmed via `GroupRoleStorage.php` (`condition('scope',
  ...)->condition('global_role', $roles, 'IN')`) and `PermissionScopeInterface` (`SYNCHRONIZED_IDS =
  [OUTSIDER_ID, INSIDER_ID]`).
- **Corrected design:** `user.role.groups_moderate.yml` (new site-level role, no group-specific
  permissions itself) + `group.role.community_group-groups_moderate.yml` (new group-role config,
  `scope: insider`, `admin: true`, `global_role: groups_moderate`, `group_type: community_group`) —
  the `admin: true` flag gives full per-group bypass (mirroring how `community_group-admin` already
  works, just synchronized via a global role instead of an individual per-group assignment). This
  satisfies the acceptance criterion "Groups-Moderate persona manages a group they're not a member
  of" exactly, without inventing new access-control wiring — it reuses Group's own built-in
  synchronized-role mechanism, which is more correct than the original `administer group`-only
  proposal. Superseded acceptance criterion below reflects this correction.

**[B-1] resolved — explicit permission lists (no longer deferred):**
- `group.role.community_group-organizer.yml`: `scope: individual`, `admin: false`, permissions:
  `edit group`, `administer members` (Group 4.x's real membership-admin permission — confirmed to
  exist as the relationship-type's own permission set via `GroupRelationTypeInterface`; Organizer
  gets it plus the 5x `view group_node:*`, `create group_node:*`, `update own group_node:*`,
  `delete own group_node:*` for all 5 content types (documentation/event/forum/page/post) — i.e.
  Organizer = Member's content permissions + edit group + administer members. Does NOT get
  `admin: true` (would over-grant per survey's reasoning).
- `group.role.community_group-moderator.yml`: `scope: individual`, `admin: false`, permissions:
  `administer members` only, plus the same `view group_node:*` view permissions as Member (Moderator
  can see content but the issue does not ask for content CRUD). No `edit group`. This is the
  "narrower than Organizer" distinction called for in the survey.
- `community_group-member.yml`: **unchanged**, reused as-is (already has the 5x `view group_node:*`).

**[B-2] resolved — exact route locked:**
- Route name: `do_group_membership.manage_members`
- Path: `/group/{group}/members`
- Local task title: "Manage members", `base_route: entity.group.canonical`, weight 20 (after any
  existing group tabs).
- Access: `_custom_access` callback in the manager service — TRUE if
  `$group->hasPermission('administer members', $account)` (covers Organizer, Moderator, and any
  Groups-Moderate synchronized-role user) OR `$account->hasPermission('administer group')` (site
  admin escape hatch, matching the RUNBOOK's existing `/admin/groups/pending` precedent).

**[B-3] resolved — status field state machine (locked):**
- `field_membership_status` (a `list_string` field on the `group_relationship` bundle
  `community_group-group_membership`), allowed values: `active` (member/organizer/moderator all use
  this once approved — the field distinguishes membership *lifecycle*, not *role*; role is carried by
  the existing `group_roles` field, kept orthogonal per the issue's own "edit access flows from the
  role, not a field" acceptance criterion), `pending` (awaiting organizer/moderator approval),
  `blocked` (denied/banned, relationship persists but access revoked). Default on creation:
  `active` for organizer/admin-created memberships (e.g. direct add), `pending` when created via a
  self-service join request flow (#121's territory — this story only needs the value to exist and be
  actionable, not the join-request UI itself).
- **Transitions:** `pending → active` (approve), `pending → <removed>` (deny — the relationship
  entity is DELETED, not set to `blocked`; deny means "never joined", blocked means "was in, now
  banned" — this distinction is the answer to the o4-mini's exact question). `active → blocked`
  (organizer/moderator/groups-moderate action). `blocked → active` (unblock, symmetric). `active/
  blocked → <removed>` (remove member = delete the relationship entity outright, at any status).
  "Change role" mutates the `group_roles` field value(s), independent of `field_membership_status`.
- Status is conveyed via a real field, not the `group_roles` field, to keep role (#91's matrix
  concern) and lifecycle-status (this story's approve/deny/block concern) orthogonal, per the issue's
  explicit "edit access flows from the role, not a field" line — i.e. that line means *access*
  decisions must key off `group_roles`/permissions, not off `field_membership_status`; the status
  field is legitimately a different concern (membership lifecycle) and does not violate that rule.

**[B-4] resolved — joined date:** REUSE the `group_relationship` entity's own base `created` field
(populated automatically by Drupal core on entity save) as "joined date" — no new field. Rationale:
`created` is set at relationship-creation time, which is exactly "joined date" for an `active`
membership; for a `pending` membership, `created` becomes "requested date" until approved, which is
still the correct display value (a pending row shows "Requested: <created>", an active row shows
"Joined: <created>") — no semantic mismatch, no new field needed. This resolves the deferred
open question with a documented default (reuse), not a new decision point for D/A.

**[B-6] resolved — add-member workflow (locked):**
- Add-member form: a `user` entity-autocomplete field (Drupal core's standard `entity_reference
  autocomplete` widget against the `user` entity type) + a `group_roles` multi-select (checkboxes:
  Organizer / Moderator / Member) defaulting to Member checked.
- Validation: reject if the selected user already has ANY `community_group-group_membership`
  relationship to this group (active, pending, or blocked) — surfaced as a form validation error
  ("This user is already a member of this group.") rather than silently creating a duplicate.
  Reject if the target account is blocked at the Drupal user-entity level (`$account->isBlocked()`)
  — surfaced as "This user's site account is blocked."
- Default status on add: `active` (an organizer/moderator directly adding someone is not a
  self-service request, so no approval step applies).

**[B-7] resolved — error/edge cases (added as acceptance criteria below):**
- Adding a user with an existing membership (any status) to the same group → validation error, no
  duplicate relationship created.
- Attempting to remove or demote the **last remaining Organizer** of a group → blocked with a form
  validation error ("A group must have at least one Organizer.") — prevents an orphaned group with
  no one able to manage it. (Groups-Moderate accounts are exempt from this floor since they are not
  counted as the group's own Organizer.)
- Approve/deny a pending request that no longer exists (race: two organizers act concurrently) →
  the action is a no-op with a status message ("This request was already handled."), not a fatal
  error.
- Service-layer failures (e.g. entity save exception) are caught and surfaced as a form error, not
  an uncaught exception / white screen.

**[W-1] resolved — added to acceptance criteria:** unit tests for the manager service's core API
methods (`addMember()`, `changeRole()`, `changeStatus()`, `removeMember()`, `approvePending()`,
`denyPending()`) with mocked dependencies, per the mandatory Services-over-Hooks architecture.

**[W-2] resolved:** the Manage-members table paginates at 50 rows (Drupal core pager), consistent
with typical Views-based admin listings elsewhere in the codebase (though this is a controller-
rendered table, not a View, per B-2's route design — pagination is implemented via a Drupal
`PagerSelectExtender`-equivalent or a simple `array_slice` + `pager_default_initialize()` in the
controller, whichever the survey's sibling-module precedent suggests is idiomatic; F decides the
exact mechanism, not the requirement).

**[W-3] resolved:** every user-facing string in the new module wrapped in `$this->t()` (controller/
form context) or `t()` (procedural/service context) — added as a Tier-1 self-check item for F.

**[W-4] noted, not required:** no hooks/events are added in this story (no consumer currently needs
them); `do_group_extras`'s existing `entity_insert`/`entity_presave` hooks on `GroupInterface` remain
the model if a future story needs membership-change hooks. Out of scope here, recorded so it isn't
silently forgotten.

**[NIT-1..4] resolved:** `groups_moderate` (underscore) kept — matches Drupal's own `user.role` ID
convention (machine names are snake_case throughout this codebase, e.g. `content_editor`,
`site_moderator`); hyphens are a GitLab/frontend convention, not a Drupal config-entity-ID one.
Non-goals section YAML filenames reflowed for readability (this brief, already applied). Module name
`do_group_membership` kept (matches the `do_*` + domain-noun convention of every sibling module;
"membership" is the correct domain noun, not ambiguous with Group-module internals in practice — no
existing `do_*` module or core Group class shares that exact name). Acceptance criteria below are
now uniquely labeled AC-1..AC-15 for traceability.

## Objective

Ship the MVP membership-management foundation on the demo:
1. Three `community_group` group roles: **Organizer**, **Moderator**, **Member** (reuse/extend the
   existing `community_group-member` role for Member; Organizer and Moderator are new).
2. Extend the `community_group-group_membership` relationship type with a **status** field
   (member / organizer / moderator / pending / blocked) and a **joined date**.
3. A **Manage-members** tab/route (organizer-or-site-admin-gated): list members + status, add/
   remove, change role, approve/deny pending requests.
4. A site-level **Groups Moderate** global role: administers any group's membership without being a
   member of it, implemented via Group 4.x's synchronized-global-role mechanism (a `group_role`
   config entity with `global_role: groups_moderate` + `admin: true` — see Round-1 brief-gate
   resolution [B-5] above for the corrected mechanism).
5. WCAG 2.2 AA on the new UI (accessible table, keyboard-operable actions, non-color status
   conveyance).

This story is explicitly foundational for #144 (creator auto-becomes Organizer), #120 (persona
switcher — Organizer/Groups-Moderate personas), and #91 (permission matrix rows) per epic #137.
Forward-compat re-verified clean in the survey — no design compromise needed across the three
consumers.

## Non-goals / explicit exclusions (scope discipline)

- **Do not touch** `docs/groups/config/group.role.community_group-{anon_view,insider_view,
  outsider_view}.yml` or the stale `config/sync`-only `{anonymous,outsider}.yml` duplicates — that
  vestigial-role cleanup belongs to #121/#134 per epic #137's coordination note. This story only
  **adds** `organizer`/`moderator` role config and **reuses** the existing `community_group-member`
  role (already live in both trees with identical shape) — genuinely additive, no collision.
- **Do not touch** `do_chrome/src/PermissionMatrix.php` (the #91 matrix) — it currently encodes the
  OLD role scheme and updating it to consume Organizer/Groups-Moderate is #91's own follow-up, not
  this story's Owns.
- **Do not implement** the two-axis visibility/join-policy model (#121/#134) — this story's "pending"
  membership status is a data value on the membership relationship + the approve/deny action in the
  Manage-members UI, not the request-to-join enforcement flow itself (#121 owns that investigation
  and the actual "Request to join" join-policy wiring). Where the two overlap (a pending membership
  needing to exist for #121's request flow to act on), this story's status field is the substrate
  #121 will consume — confirmed non-conflicting shape.
- **No `grequest`** — confirmed absent from composer.json/lock and incompatible with group 4.0.x;
  "pending" is a first-class value of this story's own `status` field, not a submodule integration.

## Reuse & Analogous-Feature map (from survey, restated)

- **Group roles:** EXTEND the `community_group-*` role family. `organizer` and `moderator` are
  **NEW** config entities (justified: no existing role carries the right permission scope —
  `admin` is `admin: true` unconditional bypass, too broad for Organizer; Moderator needs a distinct
  narrower scope). `member` is **REUSE** of the existing `community_group-member.yml` — same ID,
  extend its permissions list only if the acceptance criteria require it (baseline view-only
  permissions already match "Member" role expectations; do not widen unless a criterion demands it).
- **Membership status:** EXTEND `group.relationship_type.community_group-group_membership` by adding
  two new fields (`field_membership_status` list, `field_joined_date` datetime-ish) — no new
  relationship type.
- **Manage-members UI:** NEW module `do_group_membership` (justified: no existing `do_*` module owns
  membership-admin UI; `do_notifications` is a structural analog, different domain). Structure
  mirrors `do_notifications`: `.routing.yml` + `.services.yml` + `.links.task.yml` +
  `src/Controller/` + a manager service (Services-over-Hooks mandatory per
  `docs/playbook/frameworks/drupal/best-practices.md`) + `src/Form/` for add/remove/role-change/
  approve-deny actions + `css/manage-members.css` + `.libraries.yml` (module-owned CSS, not
  `groups_chrome` theme edits, per every existing UI-bearing `do_*` module).
- **Groups Moderate:** NEW site-level `user.role` (`groups_moderate`, no group-specific permissions
  itself) + NEW `group.role.community_group-groups_moderate.yml` (`scope: insider`, `admin: true`,
  `global_role: groups_moderate`) — Group 4.x's built-in synchronized-role mechanism, corrected per
  Round-1 [B-5] above. No new access-control wiring invented; reuses Group's own
  `GroupRoleStorage` global-role synchronization.

## Acceptance criteria (checkboxes; each must be backed by a T-authored test)

- [ ] **AC-1** `group.role.community_group-organizer.yml` exists (`docs/groups/config/`, assembled
      into `config/sync/`): `scope: individual`, `admin: false`, permissions = `edit group`,
      `administer members`, `view group_node:{documentation,event,forum,page,post} entity`,
      `create group_node:{...} entity`, `update own group_node:{...} entity`,
      `delete own group_node:{...} entity` (all 5 content types).
- [ ] **AC-2** `group.role.community_group-moderator.yml` exists: `scope: individual`, `admin: false`,
      permissions = `administer members`, `view group_node:{documentation,event,forum,page,post}
      entity` (view-only content access, no create/edit/delete, no `edit group`).
- [ ] **AC-3** `community_group-member.yml` reused unchanged (no edits).
- [ ] **AC-4** `community_group-group_membership` relationship type gains one new field,
      `field_membership_status` (`list_string`; allowed values `active` / `pending` / `blocked`) — no
      new joined-date field; "joined date" is the relationship entity's existing `created` base field
      (confirmed present on `GroupRelationship::baseFieldDefinitions()` in `drupal/group` 4.0.x),
      displayed as "Requested: <created>" when `pending`, "Joined: <created>" otherwise.
- [ ] **AC-5** Status transitions enforced exactly as specified: `pending → active` (approve),
      `pending → <relationship deleted>` (deny), `active → blocked` / `blocked → active`
      (block/unblock), `<any> → <relationship deleted>` (remove member). "Change role" mutates
      `group_roles` only, independent of `field_membership_status`.
- [ ] **AC-6** Route `do_group_membership.manage_members` at `/group/{group}/members`, local task
      "Manage members" on `entity.group.canonical`, weight 20. Access: `$group->hasPermission(
      'administer members', $account)` OR `$account->hasPermission('administer group')`.
- [ ] **AC-7** Manage-members page: real `<table>` with `<th scope="col">` headers listing all
      members + status (badge with color AND text/icon label) + joined/requested date; add member
      (user autocomplete + role checkboxes, default Member), remove member (with confirm step),
      change role, approve pending, deny pending — every action a real `<button>`/form submit,
      keyboard-operable, visible focus states.
- [ ] **AC-8** Add-member validation: reject (form error) adding a user with an existing membership
      (any status) to the same group; reject (form error) adding a Drupal-blocked user account.
- [ ] **AC-9** Removing or demoting the **last remaining Organizer** of a group is blocked with a
      form validation error ("A group must have at least one Organizer."); Groups-Moderate accounts
      are exempt from this floor (not counted as the group's own Organizer).
- [ ] **AC-10** Approving/denying an already-resolved pending request is a no-op with a status
      message, not a fatal error (concurrent-organizer race).
- [ ] **AC-11** A **Member** persona (no Organizer/Moderator role, not Groups-Moderate) gets
      access-denied on `/group/{group}/members`.
- [ ] **AC-12** A **Groups-Moderate** persona (holds the `groups_moderate` site role, synchronized via
      `group.role.community_group-groups_moderate.yml`) can manage members of a group they are NOT a
      member of.
- [ ] **AC-13** `user.role.groups_moderate.yml` + `group.role.community_group-groups_moderate.yml`
      exist as specified in the Reuse map above.
- [ ] **AC-14** Unit tests (mocked dependencies) for the manager service's core API:
      `addMember()`, `changeRole()`, `changeStatus()`, `removeMember()`, `approvePending()`,
      `denyPending()`. Existing suite stays green (Kernel/Functional/Unit + the 3 existing Playwright
      specs). New Playwright spec `tests/e2e/manage-members.spec.ts` exercises a role change AND a
      pending approval end to end.
- [ ] **AC-15** WCAG 2.2 AA: axe-clean (or documented exceptions) on the Manage-members page; all
      actions reachable/operable via keyboard; status never conveyed by color alone.
      `scripts/ci/assemble-config.sh` run confirms clean assembly (no missing files, no
      `core.extension.yml` module-enable drift). Every user-facing string wrapped in `t()`/`$this->t()`.
      Manage-members table paginates (50 rows) if member count exceeds one page.

## WCAG 2.2 AA specifics for D

- Manage-members member list: a real `<table>` with `<th scope="col">` headers, not div-grid.
- Status column: badge with BOTH a color AND a text label (e.g. "● Pending" not just a colored dot).
- Add/remove/change-role/approve/deny: real `<button>`/form-submit elements, not JS-only div
  click-handlers; visible focus states (WCAG 2.2 focus-visible AA).
- Confirmation for destructive actions (remove member) — a confirm step, not instant-fire on click.

## Input documents

- Issue #138 (`gh issue view 138 --repo Performant-Labs/groups-on-d11`)
- Epic #137, and the coordination notes in #121, #134, #144, #120, #91 (read via `gh issue view`,
  quoted in `decisions.md`)
- Survey: `docs/handoffs/0138-mc7-manage-members/survey.md`
- `docs/playbook/frameworks/drupal/best-practices.md` (Services-over-Hooks)
- `docs/groups/RUNBOOK.md` (config-YAML-only convention; existing `administer group` precedent)
- Sibling `do_*` modules: `do_notifications` (route/controller/local-task structural analog),
  `do_discovery` (`{group}` route-param upcasting analog), `do_group_extras` (`administer group`
  permission-gate analog), `do_tests` (`GroupsKernelTestBase` fixture reuse)

## Handoff locations

All phase handoffs live under `docs/handoffs/0138-mc7-manage-members/` in this worktree, mirrored to
`/private/tmp/claude-501/-Users-andreangelantoni-Projects-groupsdrupalorg/99b8a43d-3273-49d0-a460-63e285878867/scratchpad/o138-run/`
after each phase (worktree-reap resilience).

## Branch / PR

- Branch: `0138-mc7-manage-members` (created off `origin/main`, pushed to `origin` — normal GitHub
  flow, NOT the drupalcode issue-fork model).
- PR title (Conventional Commit): `feat: #138 Organizer manage-members UI + group roles + Groups Moderate`
- PR assigned to self; mirror issue's labels if tooling allows (issue currently carries only
  `enhancement` — mirror that; no scoped `category::`/`priority::`/`component::` taxonomy exists in
  this repo, that convention is drupalcode-specific).
- Human merges on green CI — no self-merge.
