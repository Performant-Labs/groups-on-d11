# Decision Journal — Issue #138 (MC-7: Organizer Manage-members UI + group roles + Groups Moderate)

Run slug: `0138-mc7-manage-members`
Repo: `Performant-Labs/groups-on-d11` (GitHub flow — origin push + PR, NOT the drupalcode fork model)
Worktree: `/Users/andreangelantoni/Projects/_worktrees/groups-0138-mc7-manage-members`
Branch: `0138-mc7-manage-members` (off `origin/main` @ c18f417)

## Phase 0/1 — Orchestrator (O) — Survey adoption + verification

**Decided:** Adopt the salvaged Phase-1 survey
(`/private/tmp/claude-501/.../scratchpad/o138-salvage/survey.md`) as the base survey — re-verified
against the current repo (post PR #146) rather than re-run from scratch.

**Verified true (still valid post-#146):**
- `docs/groups/config/` (staging source of truth) vs `config/sync/` (live export) split, reconciled
  by `scripts/ci/assemble-config.sh` — unchanged.
- `drupal/group: 4.0.x-dev` in composer.json; no `grequest` in composer.json — confirmed absent.
- Live group roles today: `config/sync/group.role.community_group-{admin,anonymous,member,outsider}.yml`.
  `docs/groups/config/` has a *different*, newer-looking subset:
  `{admin,anon_view,insider_view,outsider_view}.yml` — confirmed still diverged/stale as the survey
  described (pre-existing drift, out of this story's scope beyond "don't make it worse").
- `community_group-member.yml` (live, `config/sync/`) confirmed: scope=individual, admin=false,
  permissions = 5x `view group_node:* entity`. This is the role I extend/reuse for "Member" per the
  issue's own naming.
- `community_group-admin.yml` confirmed: `admin: true`, `permissions: {}` (unconditional bypass) —
  confirms survey's reasoning that Organizer must be a **new**, narrower role, not a reuse of admin.
- `group.relationship_type.community_group-group_membership.yml` + the existing
  `group_roles` entity-reference field (`field.storage.group_relationship.group_roles.yml`,
  cardinality -1) confirmed as the correct mechanism for assigning Organizer/Moderator/Member to a
  membership.
- `do_notifications` routing/links.task/controller pattern confirmed as the closest UI-surface
  structural analog (route + controller + local task, entity-context-aware via `{user}` upcasting;
  I'll mirror with `{group}` upcasting per `do_discovery`'s `IcalController` pattern).
- `do_group_extras/src/Hook/DoGroupExtrasHooks.php` confirmed: `administer group` /
  `administer groups` permission-gates the existing "auto-unpublish new group" bypass logic — the
  established anchor point for "Groups Moderate."
- `GroupsKernelTestBase` (`do_tests`) confirmed present and matches the survey's description
  (community_group type + relationship bootstrap via 4.x storage APIs, `GroupTestTrait` reuse).
- `content_editor` (`config/sync/user.role.content_editor.yml`) confirmed as the site-role config
  shape analog for a new `groups_moderate`-style global role.

**New finding beyond the salvaged survey (post-#146):** `do_chrome/src/PermissionMatrix.php` (landed
in PR #146 for issue #91) is a **live artifact** that hard-codes the *old* role scheme
(anonymous/outsider/member/admin, sourced from the stale `anon_view`/`outsider_view`/`insider_view`
config) even though issue #91's own body (updated 2026-07-22) says the matrix should show
Anonymous/Member/**Organizer**/**Groups-Moderate**. This confirms #91 is only partially done and
this story (#138) is the one that must actually produce the Organizer/Groups-Moderate roles before
#91 can be updated. **Decision: do not touch `do_chrome/PermissionMatrix.php` in this story** — it's
`do_chrome`'s Owns in a separate issue; updating it to consume the new roles is #91's follow-up
work, out of scope here. Recorded so #91's eventual re-run isn't surprised.

**Confirmed via `gh issue view` (live, not just survey inference):**
- #144 (create-flow) explicitly: "Depends on: #138 (Organizer group role must be defined)".
- #120 (persona switcher) explicitly names "Organizer group role" (Maria Chen) and a
  "Groups-Moderate" global role, sourced from MC-7/#138 — confirms exact role-shape contract.
- #121/#134 own the *vestigial role cleanup* (`anon_view`/`outsider_view`/`insider_view`/stale
  `admin`/`anonymous`/`outsider` duplicates) and the two-axis visibility/join-policy model — I must
  **not** touch those files; only add `organizer`/`moderator` roles and reuse `community_group-member`
  as this issue's own Owns.
- Epic #137 confirms #138 is explicitly "foundational," underpins #144/#120/#91, and the shared
  coordination note: "group role YMLs (#138 owns positive role defs, coordinates with #121/#134
  cleanup)".

**Forward-compat check:** re-confirmed clean (matches salvaged survey's conclusion) — no conflicting
shape required by #144/#120/#91.

**Environment fix:** `OPENAI_API_KEY` was present in the canonical checkout's gitignored `.env` but
not copied into this fresh worktree (git worktrees don't share untracked/gitignored files). Copied
`.env` from `/Users/andreangelantoni/Projects/groups-on-d11/.env` into the worktree (chmod 600) so
`dual-review.sh` can run.

**Evidence:** `gh issue view 138/91/120/121/134/144/137 --repo Performant-Labs/groups-on-d11`;
direct file reads of `config/sync/group.role.community_group-{member,admin}.yml`,
`do_chrome/src/PermissionMatrix.php`, `do_group_extras/src/Hook/DoGroupExtrasHooks.php`,
`do_notifications/do_notifications.routing.yml`, `do_tests/tests/src/Kernel/GroupsKernelTestBase.php`.

## Phase 1 (brief gate) — o4-mini second-opinion review

**Decided:** Ran `docs/playbook/workflow/dual-review.sh --mode brief` (round 1) against
`brief.md`. Result: **BLOCK**, 7 blocking findings (B-1..B-7), 4 WARN, 4 NIT — all substantive, none
dismissed as noise.

**Key correction (B-5, factual, not just a design gap):** the brief's original assumption that a
bare `administer group` permission on a global role bypasses per-group membership-management access
was **wrong**. Verified directly against `drupal/group` 4.0.x source
(`git.drupalcode.org/project/group`, fetched live since no local vendor checkout exists in this
worktree): `admin_permission: 'administer group'` on the `GroupRole` entity type gates who may
administer `group_role` **config entities** (a site-administration permission), not a per-group
access bypass. The actual "site role administers every group without membership" mechanism is
Group's built-in **synchronized global role**: a `group_role` config entity with
`scope: insider|outsider` + `global_role: <user.role ID>` is auto-applied to every account holding
that site role, for every group of the matching type (confirmed via `GroupRoleStorage.php`'s
`condition('scope', ...)->condition('global_role', $roles, 'IN')` and `PermissionScopeInterface::
SYNCHRONIZED_IDS`). Corrected design: `user.role.groups_moderate.yml` (site role, no group perms of
its own) + `group.role.community_group-groups_moderate.yml` (`scope: insider`, `admin: true`,
`global_role: groups_moderate`). Also verified `admin_permission: 'administer members'` IS the real,
correct permission on the `GroupMembership` relation-type plugin attribution (`src/Plugin/Group/
Relation/GroupMembership.php`) — the B-1/B-2 Organizer/Moderator permission lists using
`administer members` were correct as originally drafted.

**All 7 BLOCKs resolved by decision** (not deferred to D/A): exact permission lists (B-1), exact
route/path/access logic (B-2), a full status state-machine incl. the pending-deny-vs-blocked
distinction (B-3), joined-date via reused `created` base field — confirmed present on
`GroupRelationship::baseFieldDefinitions()` (B-4), the corrected Groups-Moderate mechanism (B-5),
the add-member form + validation rules (B-6), and 4 new edge-case acceptance criteria (B-7). Brief
acceptance criteria renumbered AC-1..AC-15 for traceability (NIT-4).

**Round 2 re-submission: PASS** — all 7 BLOCK findings accepted as resolved by o4-mini. Transcript:
`docs/handoffs/0138-mc7-manage-members/dual-review-brief.md`.

**Assumed (flagged for A to re-verify with actual DDEV/PHPStorm access, since I verified via
`git.drupalcode.org` source reads rather than a local vendor install):** the exact permission string
`administer members` and the synchronized-global-role `GroupRoleStorage` query behavior as described
above. High confidence (read directly from `4.0.x` branch source), but A should confirm against the
actual installed `vendor/drupal/group` in DDEV before F starts, since no local vendor tree was
available in this worktree to cross check.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/dual-review-brief.md` (full o4-mini transcript,
both rounds); `git.drupalcode.org/project/group` blob reads at ref `4.0.x`:
`src/Entity/GroupRole.php`, `src/Entity/Storage/GroupRoleStorage.php`, `src/PermissionScopeInterface.php`,
`src/Plugin/Group/Relation/GroupMembership.php`, `src/Entity/GroupRelationship.php`,
`src/Entity/Access/GroupAccessControlHandler.php`, `group.permissions.yml`.

## Phase 2 (Design) — Manage-members wireframe, 2026-07-22

**Decided:** Low-fi ASCII wireframe (`wireframe.md`) covering all required screens/states —
many/empty/one-row table variants, add-member form + its validation/success states, inline
change-role sub-form, remove-member confirm step, last-remaining-Organizer guard (disabled
controls + server backstop), approve/deny including the concurrent-race no-op, and 50-row
pagination. Status badges specified as glyph (`aria-hidden`) + always-visible text label +
color-carrying modifier class, satisfying the non-color-alone WCAG 2.2 AA requirement.

**Decided:** Reuse `#type => 'table'` render-array idiom (matches
`do_notifications/NotificationSettingsController::page()`), the `do_chrome`
permission-matrix's glyph+label badge convention, `button`/`button--primary`/`button--danger`
classes, and `messages messages--{status,warning,error}` — no new visual language introduced.
Module-owned CSS (`do_group_membership/css/manage-members.css`), no `groups_chrome` theme edits.

**Assumed:** the confirm-step for Remove uses Drupal core's standard `ConfirmFormBase` pattern
(full-page navigation) rather than a modal/JS dialog — matches this codebase's convention of
avoiding JS-only interaction patterns for destructive actions; F can substitute an
`aria-expanded` inline pattern if preferred without changing this wireframe's information
architecture.

**Hedged (flagged as open questions for human approval, each with a recommended default):**
OQ-1 (change-role: checkboxes vs. radio — recommend checkboxes), OQ-2 (last-Organizer guard:
disable-before-attempt vs. fail-after-submit-only — recommend disable-before-attempt), OQ-3
(add-member form: inline toggle vs. separate page — recommend inline toggle), OQ-4
(Groups-Moderate never appears as a table row, by design per B-5 — recommend confirming as
intentional), OQ-5 (pending-row role display: "Member (requested)" vs. "—" — recommend showing
the requested role).

**Evidence:** `docs/groups/modules/do_notifications/src/Controller/NotificationSettingsController.php`
(table/button/messages conventions); `docs/groups/modules/do_chrome/templates/
do-chrome-permission-matrix.html.twig` + `docs/groups/modules/do_chrome/css/do_chrome.css`
(badge/glyph + focus-state precedent); brief.md's locked AC-1..AC-15 and "WCAG 2.2 AA specifics
for D" section (constraints this wireframe designs against, not re-decides).

**Not self-approved:** per pipeline convention, this wireframe requires explicit human approval
via O before Phase 3 (Architecture/A) or any test/code authoring begins.

## Phase 2 (Design) — Designer (D) + D-GATE approval

**Decided:** D produced a text-based low-fi wireframe (`wireframe.md`, 7 screens/states + focus/
keyboard notes) reusing established codebase idioms: Drupal `#type => 'table'` (matches
`do_notifications` controller), the `do_chrome` permission-matrix badge pattern (glyph + always-
visible text label + color modifier class — non-color-alone, WCAG 2.2 AA), real `<button>`s
throughout, a `ConfirmFormBase`-style confirm step for member removal, and module-owned CSS
(`do_group_membership/css/manage-members.css`, no `groups_chrome` theme edits). Covers empty / one /
many / error states, the last-Organizer guard (AC-9), and the approve/deny concurrent-race no-op
(AC-10).

**D-GATE: APPROVED by operator 2026-07-22** (relayed by coordinator). Five open questions resolved
(recorded in `handoff-D.md`): OQ-1 checkboxes; OQ-2 disable-before-attempt + server-side backstop
(trivial "count active Organizers" query, not an elaborate mechanism); OQ-3 inline toggle (F may
fall back to a separate `/add` route); OQ-4 no Groups-Moderate row (intentional — synchronized
global roles have no `group_relationship`); OQ-5 show requested role with "(requested)" qualifier.

**Hedged/for A & F:** OQ-3's inline-vs-separate-route is explicitly F's implementation call (both
AC-compliant); OQ-2's guard must stay a trivial per-render count query, not over-engineered.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/wireframe.md`, `handoff-D.md`; coordinator
D-gate approval message.

## Phase 3 (Architecture) — A — Up-front plan review

**Decided:** PASS. Verified the plan's reuse-first fidelity and architectural soundness against
actual sibling-module source (`do_notifications`, `do_discovery`, `do_group_extras`, plus every
other `do_*` module's `.services.yml`) and the mandatory Services-over-Hooks playbook doc. Group-role
extension, relationship-type field addition, and B-5's synchronized-global-role mechanism for
Groups-Moderate all check out. No `block` findings.

**Two `warn` findings recorded** (full detail in `handoff-A-plan.md`):
1. The brief's "manager service" pattern is not exemplified in any *existing* `do_*` module (all of
   them use only a `*Hooks` class or inline controller logic) — but it IS the mandatory documented
   pattern in `docs/playbook/frameworks/drupal/best-practices.md`. Not drift; flagged so F builds the
   DI/service shape from the playbook's own example rather than copy-pasting `do_notifications`'s
   inline-logic controller style.
2. The B-5 synchronized-global-role mechanism remains a verified-via-source-read assumption (no local
   `vendor/drupal/group` tree exists in this worktree or the reference checkout to do a byte-level
   check) — but is independently corroborated by this repo's own live
   `AccessPolicyEnforcementTest`, which already documents `SynchronizedGroupRoleAccessPolicy` feeding
   the OUTSIDER/INSIDER scopes via `GroupInterface::hasPermission()`. Recommended T add one Kernel
   test asserting the exact `groups_moderate` config grants cross-group access, to close this
   empirically at GREEN.

**Evidence:** `docs/groups/modules/do_notifications/*`, `docs/groups/modules/do_group_extras/*`,
`docs/groups/modules/do_discovery/*`, every `do_*` `.services.yml`,
`docs/playbook/frameworks/drupal/best-practices.md`,
`docs/groups/modules/do_tests/tests/src/Kernel/{GroupsKernelTestBase.php,AccessPolicyEnforcementTest.php}`,
`composer.json`/`composer.lock` (confirmed no local vendor tree, `drupal/group: 4.0.x-dev`).

**Handoff:** `docs/handoffs/0138-mc7-manage-members/handoff-A-plan.md`

## Phase 3 (up-front architecture review) — Architecture Reviewer (A)

**Decided:** A reviewed the plan (brief + approved wireframe + reuse map) against actual sibling
modules. **Verdict: PASS** — no `block` findings; plan is reuse-first and architecturally sound.
The B-5 synchronized-global-role correction is corroborated live in this repo's own
`do_tests/tests/src/Kernel/AccessPolicyEnforcementTest.php` (documents `SynchronizedGroupRole
AccessPolicy` feeding OUTSIDER/INSIDER scopes), not just the git.drupalcode.org source read.

**Two `warn`s carried forward (not blocking, but must reach F and T):**
- **warn-1 (for F):** NO existing `do_*` module implements the mandatory Services-over-Hooks
  manager-service pattern — they all put logic in a `*Hooks` class or inline in the controller
  (`do_notifications` even service-locates `\Drupal::state()` inline, the exact anti-pattern the
  brief avoids). F must base `do_group_membership.manager` + its `*.services.yml` DI wiring on the
  playbook's own `MyModuleManager` example (`docs/playbook/frameworks/drupal/best-practices.md`
  §"Services over Hooks"), and use `do_notifications` ONLY for the routing / `links.task.yml` /
  `{group}`-param-upcasting shape — NOT for the service-injection pattern.
- **warn-2 (for T):** the synchronized-global-role mechanism could not be closed by a byte-level
  vendor read (no local `vendor/drupal/group` tree in this worktree or the reference checkout). T
  must add one Kernel test asserting that a `group.role` config with `scope: insider` +
  `global_role: groups_moderate` + `admin: true` actually grants
  `hasPermission('administer members', $groups_moderate_user)` on a group the user never joined —
  this closes the residual assumption empirically at GREEN.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-A-plan.md`.
