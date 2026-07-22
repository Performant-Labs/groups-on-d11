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

## Objective

Ship the MVP membership-management foundation on the demo:
1. Three `community_group` group roles: **Organizer**, **Moderator**, **Member** (reuse/extend the
   existing `community_group-member` role for Member; Organizer and Moderator are new).
2. Extend the `community_group-group_membership` relationship type with a **status** field
   (member / organizer / moderator / pending / blocked) and a **joined date**.
3. A **Manage-members** tab/route (organizer-or-site-admin-gated): list members + status, add/
   remove, change role, approve/deny pending requests.
4. A site-level **Groups Moderate** global role: administers any group's membership without being a
   member of it, anchored to the Group-module-recognized `administer group` permission.
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
- **Groups Moderate:** NEW site-level `user.role` (e.g. `groups_moderate`), carrying `administer
  group` (the existing Group-module bypass permission already anchoring `do_group_extras`'s
  pending-group logic) — no new access-control wiring invented.

## Acceptance criteria (checkboxes; each must be backed by a T-authored test)

- [ ] `group.role.community_group-organizer.yml` and `group.role.community_group-moderator.yml` exist
      (new config entities, `docs/groups/config/` authored, assembled into `config/sync/`), each with
      an explicit, non-trivial `permissions:` list (not `{}`) — Organizer: manage members (add/
      remove/change role/approve/deny), edit group, post/edit/delete any content. Moderator: approve/
      deny pending + manage member status, narrower than Organizer (no group-settings edit) — exact
      list finalized in D/A, not pre-decided here beyond "must be real and documented" (forward-compat
      requirement from #91).
- [ ] `community_group-member.yml` reused unchanged (or minimally extended only if an acceptance
      criterion requires it — default: unchanged).
- [ ] `community_group-group_membership` relationship type gains a `status` field (values: member /
      organizer / moderator / pending / blocked) and a `joined date` field (or the relationship
      entity's own `created` base field is reused for "joined date" — decide in D/A: reusing `created`
      avoids a new field if it is already populated correctly on membership creation; default to
      reuse unless a test shows it insufficient).
- [ ] `/group/{group}/members` (or similar; finalize path in D) Manage-members route + controller +
      local task tab, visible/accessible only to an Organizer-role member of that group, a
      Groups-Moderate global-role user, or a site admin.
- [ ] Manage-members page: lists all members + their status; supports add member, remove member,
      change role, approve pending, deny pending — each action keyboard-operable, each status
      conveyed with text/icon in addition to any color.
- [ ] A **Member** persona (no Organizer role) gets access-denied on the Manage-members route.
- [ ] A **Groups-Moderate** global-role user can manage members of a group they do NOT belong to.
- [ ] `user.role.groups_moderate.yml` (or equivalent name) exists, carries `administer group`.
- [ ] Existing suite stays green (Kernel/Functional/Unit + the 3 existing Playwright specs).
- [ ] New Playwright spec `tests/e2e/manage-members.spec.ts` exercises a role change AND a pending
      approval end to end.
- [ ] WCAG 2.2 AA: axe-clean (or documented exceptions) on the Manage-members page; all actions
      reachable/operable via keyboard; status never conveyed by color alone.
- [ ] `scripts/ci/assemble-config.sh` run confirms clean assembly (no missing files, no
      `core.extension.yml` module-enable drift).

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
