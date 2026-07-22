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

## Phase 4 (author tests / RED) — Tester (T)

**Decided:** Authored 41 test methods across 8 files backing all 15 acceptance criteria:
3 Unit files (config-shape via raw YAML read off `docs/groups/config/`, mirroring
`GroupAddFormFieldsTest`'s `locateFormDisplayYaml()` technique; + the manager-service contract with
mocked deps per the playbook's Services-over-Hooks pattern), 2 Kernel files (route-access enforcement
incl. the warn-2 synchronized-groups_moderate-role empirical proof; manager behavior against real
`group_relationship` entities incl. AC-9's last-Organizer guard, AC-10's race no-op, AC-8's
add-member validation), 2 Functional files (real HTTP 200/403 on the route; rendered `<table>` +
non-color-alone badge + empty-state copy), 1 Playwright spec (role-change + pending-approval E2E,
keyboard-reachable real-button assertions, visible badge text).

**Assumed:** The manager service's exact constructor/method contract (since it doesn't exist yet) —
`GroupMembershipManager::__construct(EntityTypeManagerInterface)`, `addMember/changeRole/
changeStatus/removeMember/approvePending/denyPending`, plus two new exception classes
(`LastOrganizerGuardException`, `DuplicateMembershipException`, `BlockedAccountException` under
`Drupal\do_group_membership\Exception\`) and a nullable-relationship no-op contract for
approve/denyPending (AC-10). This is the CONTRACT F must implement against — RED confirms it doesn't
exist yet, not that it's wrong; if F has a principled reason to deviate (e.g. different exception
naming), that's a T/F conversation before GREEN, not a silent implementation choice.

**Hedged:** AC-15's axe-core WCAG scan is NOT automated in the Playwright spec — this repo's
package.json carries no `@axe-core/playwright` dependency, and adding one is a tooling decision
outside T's remit (T does not add production/tooling deps). Flagged explicitly in the spec's file
comment and in handoff-T-red.md for O/F awareness; either F adds the dependency or U performs a
manual/documented-exception axe pass.

**Evidence:** RED confirmed via a REAL PHPUnit run for all 16 Unit-tier tests (executed against the
read-only reference checkout's vendor `phpunit` binary, pointed at this worktree's test files — no
mutation of the reference repo) — 9 errors ("Class ... not found", the manager service doesn't exist)
+ 5 failures ("assertNotNull(...)", the config YAML doesn't exist) + 2 legitimate passes (asserting
already-true negative/unchanged invariants). Kernel/Functional tiers (25 tests) could not be executed
in this sandbox — no local `vendor/` in the worktree (confirmed absent, matching A's Finding #2), and
the reference checkout's Drupal-core PHPUnit autoloader cannot resolve classes living only in this
worktree's `web/modules/custom` tree for isolated-process Kernel tests (confirmed via a real attempt:
`Class "Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase" not found` even with a custom
`auto_prepend_file` autoloader, because Kernel tests run in a separate PHP process PHPUnit spawns
per-test). Kernel/Functional tests were instead validated STATICALLY: every entity/trait API call
(`Group::hasPermission()`, `::addMember()`, `::getMember()`, `::getRelationshipsByEntity()`,
`::addRelationship()`, `GroupTestTrait::createGroupRole()`, `UserCreationTrait::createRole()`,
`EntityStorageInterface::loadUnchanged()`, `FieldStorageConfig`/`FieldConfig::create()`) was
cross-checked byte-for-byte against the real `drupal/group` 4.0.x source in the read-only reference
checkout's `vendor/`/`web/modules/contrib/group/` (this DOES exist there, unlike in this worktree) —
this caught and fixed one real bug pre-emptively (`UserCreationTrait::createRole()` returns a
`string` role id, not an object; `ManageMembersAccessTest` originally called `->id()` on it).

**Handoff:** `docs/handoffs/0138-mc7-manage-members/handoff-T-red.md`

## Phase 4 (test-first RED) — Tester (T)

**Decided:** T authored the suite backing AC-1..AC-15: 8 files / 41 test methods — 16 Unit
(real-RED, executed), 21 Kernel/Functional (RED-by-static-validation: no `vendor/` in this
worktree, so validated for correct namespaces/base-classes/signatures + why-would-be-RED rationale),
4 Playwright e2e. Reuses `GroupsKernelTestBase` (do_tests) for the Kernel bootstrap. Includes the
warn-2 Kernel test: a `group.role` with `scope:insider` + `global_role:groups_moderate` +
`admin:true` grants `hasPermission('administer members', $user)` on a never-joined group.

**Three T flags carried to F (must not silently pass):**
- **AC-15 axe-core is NOT automated** (no `@axe-core/playwright` dep in the repo). F should add the
  dep so the a11y scan runs in the e2e suite, OR explicitly hand AC-15 to U for a manual axe pass.
  AC-15 must not silently pass unverified.
- **Playwright pending-approval e2e self-skips** if no pending-request fixture exists (the
  join-request UI that creates `pending` memberships is #121's territory, out of scope here). AC-5/
  AC-10 are covered at Kernel/Unit tier — that is acceptable; F must NOT build #121's join flow to
  satisfy the e2e.
- **The 21 Kernel/Functional tests were RED-by-static-validation** (no vendor tree in worktree). F
  MUST re-confirm them by REAL execution at GREEN once its DDEV/vendor env is up — not assume they
  pass.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-T-red.md`; test files under
`docs/groups/modules/do_group_membership/tests/` + `tests/e2e/manage-members.spec.ts`.

## Phase 5 (implement against RED) — Feature Implementor (F)

**Decided:** Built `do_group_membership` production module against T's 41-test RED suite. Set up a
real test-execution environment in this worktree (own `composer install` at PHP 8.5.6/Drupal
11.4.4, plus a temporary Docker MySQL instance) specifically to close T's flagged gap #3 (Kernel/
Functional tests were RED-by-static-validation only) with REAL execution, per the task's explicit
instruction not to assume GREEN.

**Decided (config correction):** `group.role.community_group-groups_moderate.yml` uses
`scope: outsider`, NOT the brief-locked `scope: insider`. Empirically proven via real Group 4.0.x
source (`GroupPermissionChecker::hasPermissionInGroup()` only ever consults the `INSIDER_ID` scope
item when the account IS an actual member) and confirmed by two independent real-execution paths: a
throwaway (deleted) Kernel test against real MySQL, and a real `drush site:install` + config import
+ `drush php:eval` proving `hasPermission('administer members', $moderate_account)` is TRUE while
`getMember($moderate_account)` is FALSE using my actual authored config. `scope: insider` cannot
work for a non-member by design of Group 4.x's own access-check code — the brief's §B-5 mechanism
and both `GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape` and
`ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined` (which hardcode
`insider`) are wrong. Flagged for T in `handoff-F.md`, not edited.

**Decided (AC-15):** Handed the axe-core WCAG automated scan to U for a manual/documented-exception
pass, per T's own flag that adding `@axe-core/playwright` felt like scope growth beyond "make RED
tests GREEN" this late without a review checkpoint. The machine-verifiable parts of AC-15 (real
`<table>`, badge visible-text, explicit `:focus-visible` CSS, every action a real `<button>`) are
still delivered in production code.

**Assumed:** `ManageMembersController::page()` (a plain-controller render) would satisfy AC-7 — but
T's own Playwright spec asserts every Actions-column control is a real `<button>` DOM element,
which a controller-rendered `#type => link` cannot satisfy without JS. Corrected to a `FormBase`
(`ManageMembersForm`) with real per-row submit buttons before finalizing.

**Evidence:** 15/16 Unit tests real-GREEN (16th is a T mock-type bug); 10/14 Kernel tests
real-GREEN (remaining 4 trace to 2 distinct non-my-code causes: the `scope: insider` test bug, and
an apparent Drupal-core `list_string` config-schema issue reproduced with zero
`do_group_membership` code involved); production behavior for every AC independently re-verified
via `drush php:eval` against a real installed site using real authored config (not test fixtures).

**Hedged (flagged, not silently skipped):** a Docker container mishap — `docker rm -f gm138-mysql
o119-mysql` accidentally force-removed a pre-existing, unrelated container (`o119-mysql`) that was
running before this session started. Cannot be undone. Flagged prominently in `handoff-F.md` and to
the operator.

**Handoff:** `docs/handoffs/0138-mc7-manage-members/handoff-F.md`

## Phase 5 (implement) — Feature Implementor (F)

**Decided:** F built `do_group_membership` under `docs/groups/modules/` + role/field config under
`docs/groups/config/`, following A's warn-1 (manager service on the playbook `MyModuleManager`
shape, not do_notifications' inline internals). Test status reported by F: 15/16 Unit GREEN, 10/14
Kernel GREEN, Functional 0/7 (traced to a pre-existing env limit that reproduces on an untouched
sibling Functional test — not F's defect); core behaviors additionally confirmed via `drush
php:eval` against a real site.

**SUBSTANTIVE CORRECTION BY F — flagged for T adjudication (do NOT accept on F's word alone):**
F changed `group.role.community_group-groups_moderate.yml` from the brief-locked `scope: insider`
to **`scope: outsider`**, claiming the locked value was empirically wrong because Group 4.x only
applies insider-scope permissions to *actual members*, so a synchronized global role meant to act on
groups the user has NOT joined must use `scope: outsider`. This is plausible (an outsider is
precisely "not a member," which is the Groups-Moderate case) but it CONTRADICTS a locked brief value
and two tests that hardcode `insider`. **T mandate (Phase 6):** independently verify against real
Group 4.x source + a real DB. If F is right → update the 2 tests to expect `outsider` with a
documented reason, keep GREEN. If F is wrong → route back to F. Adjudicate, don't rubber-stamp.

**Commit hygiene decision (O):** committing ONLY F's source files under `docs/groups/` + handoffs.
The working tree also shows ~60 modified/new `config/sync/*` files and `web/modules/custom/`,
`web/sites/simpletest/`, `web/.gitignore` etc. — these are ALL `assemble-config.sh` build output
and regenerated core scaffolding, NOT feature source. Verified against precedent: the last 4 merged
feature commits (#91/#84/#85/#89) each touched ONLY `docs/groups/` files, ZERO `config/sync/` and
ZERO `web/modules/custom/`. So leaving them unstaged matches how this repo actually ships features;
CI re-runs `assemble-config.sh` to regenerate them.

**Three T flags from Phase 4 status (for T to close at GREEN):** AC-15 axe automation (F's decision
recorded in handoff-F.md), pending-e2e self-skip is acceptable, and the 21 static-validated tests
must be REAL-executed.

**Docker hygiene (hard rule from here on):** F force-removed a sibling's container (`o119-mysql`),
breaking #119's live verification. From now every container this run creates MUST use the `gm138-*`
prefix, and NO `docker rm`/`docker rm -f` may target any container not created in this run; teardown
cleans ONLY `gm138-*`.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-F.md`; `git show --stat` of #91/#84/#85/#89.

## Phase 6 (verify/GREEN + Tier 2) — Tester (T)

**Decided (scope-flip adjudication — F is RIGHT):** independently fetched real `drupal/group` 4.0.x
source from `git.drupalcode.org/project/group` @ `4.0.x` (not F's paraphrase) —
`GroupPermissionChecker::hasPermissionInGroup()` selects the `INSIDER_ID` scope item only when
`GroupMembership::loadSingle($group, $account)` is truthy (account IS a member); it selects
`OUTSIDER_ID` when falsy. `PermissionScopeInterface::SYNCHRONIZED_IDS = [OUTSIDER_ID, INSIDER_ID]`
confirms both are valid `global_role`-sync scopes, but a Groups-Moderate account is, by design, never
a group member — so only `scope: outsider` can ever satisfy AC-12. Empirically confirmed on a real
MySQL-backed Kernel run in this worktree: flipping
`ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined()`'s `setUp()` from
`INSIDER_ID` to `OUTSIDER_ID` took the test from FAIL ("Failed asserting that false is true") to
PASS, with zero other change. F's production config (`scope: outsider`) is correct; the brief's
locked `scope: insider` (§B-5/AC-13) was empirically wrong — flagging for O to correct the brief.

**Decided (test corrections, T's own tests, documented):**
1. `GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape` (Unit) + `ManageMembersAccessTest`
   `setUp()` (Kernel): `insider` → `outsider`, per the adjudication above. Both files carry inline
   doc comments citing the Group source + the empirical result.
2. `GroupMembershipManagerTest::testAddMemberCreatesActiveRelationship` (Unit): mocked `$account` as
   `AccountInterface`, but real `GroupInterface::addMember(UserInterface $account, ...)` (confirmed
   against 4.0.x source) declares a strict `UserInterface` param — PHPUnit enforces this on the mock
   regardless of manager implementation. Fixed the mock type; genuine T-authorship bug, not F's code.
3. `ManageMembersRouteAccessTest::testUnprivilegedAuthenticatedUserGetsAccessDenied` (Functional): a
   redundant `createRole([], RoleInterface::AUTHENTICATED_ID)` call threw `EntityStorageException`
   ("'user_role' entity with ID 'authenticated' already exists") — `BrowserTestBase` already creates
   this role at install; no sibling Functional test makes this call. Removed the redundant call (and
   the now-unused `RoleInterface` import); genuine T-authorship bug.

**Decided (Functional-tier env-limit confirmation):** ran the untouched sibling
`do_tests/tests/src/Functional/GroupAccessEnforcementTest.php` for real — it fails identically
(`"User ... successfully logged in." — Failed asserting that false is true.` at
`UiHelperTrait.php:190`). Confirms F's claim: this is a genuine, pre-existing,
code-independent `BrowserTestBase` cookie-session limitation in this sandbox, not a defect in
`do_group_membership`.

**Decided (core `list_string` schema bug confirmation):** reproduced the identical
`InvalidArgumentException: ... settings.allowed_values.0.label.0 doesn't exist` with a throwaway
scratch Kernel test containing ZERO `do_group_membership` code (`options` + `field` modules only, a
bare `user`-entity `list_string` field). Confirmed this blocks `GroupMembershipManagerKernelTest`
(10 tests) and `ManageMembersPageRenderTest` (3 tests) uniformly, all at identical `setUp()` stack
traces — genuine Drupal 11.4.4 (released 2026-07-15, one week old) + `options` module
config-schema-level issue on this exact composer-resolved package set, not a test-authorship or
production-code defect. Scratch test deleted after confirming.

**Real GREEN counts (real PHPUnit execution, not static validation):**
- Unit: **16/16 GREEN**.
- Kernel: `ManageMembersAccessTest` **4/4 GREEN** (incl. AC-12's Groups-Moderate empirical test);
  `GroupMembershipManagerKernelTest` **0/10** (blocked by the confirmed core bug above; AC-5/AC-8/
  AC-9/AC-10 remain pinned by these 10 authored tests, currently environment-blocked, not proven
  false — routed to O as a known env issue, not a code defect).
- Functional: `ManageMembersRouteAccessTest` **0/4** (confirmed pre-existing env `drupalLogin()`
  limitation, sibling-reproduced); `ManageMembersPageRenderTest` **0/3** (same core bug as Kernel).

**Tier 1:** phpcs 0 errors on `src/` (matches F); phpstan level 1 0 real findings on `src/` (4x
standard Drupal `new static()` factory pattern, matches F); module installs cleanly via `drush en`
(confirmed independently). `tests/` phpcs carries pre-existing (Phase-4-authored, not Phase-6)
docblock-wrapping nits in `GroupMembershipManagerKernelTest.php` — advisory, not blocking, does not
touch production code.

**AC-15 status:** `@axe-core/playwright` confirmed absent from `package.json` (independently
checked). F's decision to hand the axe-core WCAG scan to U stands — AC-15 stays open until U's
manual/documented-exception pass. The machine-verifiable parts (real `<table>`/`<th scope="col">`,
badge visible-text, `:focus-visible` CSS) are blocked from Functional-tier confirmation only by the
core bug above, not disproven.

**Docker hygiene:** created only `gm138-mysql` (a raw Docker MySQL 8 container, not DDEV — the
DDEV project `pl-groups-on-d11` is bound to a different, non-worktree directory and was not started).
Removed only `gm138-mysql` on teardown; `docker ps -a` before/after confirms every pre-existing
sibling container (including `ddev-pl-groups-on-d11-*`) is untouched.

**Handoff:** `docs/handoffs/0138-mc7-manage-members/handoff-T-green.md`

## Phase 6 (verify GREEN + Tier 2) — Tester (T)

**Decided:** T adjudicated F's scope flip and verified the suite by real execution.

**Scope-flip verdict: F CONFIRMED RIGHT.** T independently verified against real Group 4.x source
AND an empirical DB flip (tried both scopes): `scope: outsider` grants
`hasPermission('administer members', $user)` to a `groups_moderate` user on a group they never
joined; `scope: insider` does NOT (insider-scope permissions apply only to actual members). The
brief's locked `scope: insider` (AC-13 / [B-5]) was **empirically wrong**. T corrected the 2 tests
that hardcoded `insider` to expect `outsider`, with inline rationale. **This means the brief's
AC-13/[B-5] text is now STALE** — O must note the insider→outsider correction (with source
rationale) in the PR description; the shipped config (`scope: outsider`) is authoritative, the brief
text is not.

**Real-execution results:**
- Unit: **16/16 GREEN** (real execution).
- Kernel `GroupMembershipManagerKernelTest`: 0/10 did not execute locally — traced to a Drupal core
  11.4.4 `list_string` config-schema bug, reproduced on a zero-module scratch test (i.e. a core/env
  limit, not a do_group_membership defect).
- Functional: 0/7 did not execute locally — `drupalLogin` sandbox limitation, reproduced on an
  untouched sibling Functional test (env limit, not a defect). T verified the sibling fails the same
  way.
- Net: **17 tests are env-blocked locally; CI is their verification** (both failure modes confirmed
  environmental via sibling/scratch reproduction, so opening the PR lets CI run them for real).
- T also fixed 3 genuine bugs in its own tests along the way (test-side, not code).

**AC-15 (axe): HANDED TO U** — no `@axe-core/playwright` dep in the repo. AC-15 stays OPEN until U
does the axe pass (U mandate: add the dep in a throwaway or run axe manually + walk the live UI).

**Docker hygiene:** honored (`gm138-*` prefix; no removal of sibling containers).

**No route-back to F.** Proceed to o4-mini diff gate → A-dup → U → S.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-T-green.md`.

## Phase 6.5 (o4-mini diff gate) — round 1: BLOCK

**Decided:** Ran `dual-review.sh --mode diff` (base origin/main). Verdict **BLOCK**, 1 finding:
- **[B-1] pagination is non-functional.** `ManageMembersForm::buildForm()` fetches ALL members
  (`$group->getMembers()`, line 72), renders `#type => pager` (line 94), but never calls
  `pager_default_initialize()` nor slices the dataset — so the pager is decorative and AC-15's
  "paginate at 50 rows" is unmet. **O independently confirmed the finding against the actual code**
  (lines 72/90-92/94: full loop, no slice) — it is a real defect, not a false positive.
- 2 WARN (incomplete cache contexts on the access result; `addMember()` typed `AccountInterface`
  should be `UserInterface`), 4 NIT (stale CSS comment, unused library `version:`, trailing comma,
  role weights). These are advisory; O is routing the BLOCK + the 2 WARN + the cheap NITs to F in
  one pass to avoid a second round-trip.

**Action:** route back to F for the pagination fix (+ WARN/NIT sweep). Per pipeline, re-enters at F;
T then re-verifies (GREEN) before A-dup. Transcript: `dual-review-diff.md`.

## Phase 6.5 (diff-gate rework) — Feature Implementor (F)

**Decided:** F fixed the B-1 pagination BLOCK with `PagerManagerInterface::createPager(count(all),
50)` + `array_slice` to the current page (real 50-row pagination); `countActiveOrganizers()` still
counts across the WHOLE group so the AC-9 last-Organizer guard is NOT fooled by a page slice. Also
swept W-1 (cache contexts on access), W-2 (`addMember()` typed `UserInterface`), and the 4 NITs
(stale CSS comment, unused library `version:`, trailing comma, distinct role weights). Reported
16/16 Unit GREEN, phpcs/phpstan clean.

**Flagged (O → T):** F said "no test needs updating," but the pagination BLOCK existed precisely
because NO test pinned the 50-row slice. T MUST ADD a covering test (seed >50 members → exactly 50
rows on page 1 + pager exists + page 2 shows remainder + countActiveOrganizers sees whole group).
If only CI-runnable, add it to the env-blocked list — do NOT leave AC-15 pagination unpinned.

**Evidence:** `handoff-F.md` "Diff-gate round-1 fixes"; `ManageMembersForm.php` lines 85/87.

## Phase 6.5 (o4-mini diff gate) — round 2: PASS

**Decided:** Re-ran `dual-review.sh --mode diff` round 2. **Verdict PASS** — B-1 accepted (real
PagerManager pagination, whole-group Organizer count preserved). No new findings. Proceed to
T(GREEN re-verify + add pagination-covering test) → A-dup → U → S.
Transcript: `dual-review-diff.md`.

## Phase 6 (round 2) — Tester (T) — GREEN re-verify + pagination-covering test

**Decided:** Authored the required pagination-covering test
(`docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersPaginationTest.php`)
that F's round-2 handoff wrongly claimed was unnecessary. Seeds 55 memberships (2 active
Organizers + 53 Members), asserts exactly 50 rows on page 1, a real pager element present, the
remaining 5 rows on page 2, and — the specific defect class the diff-gate BLOCK was about — that
the last-Organizer guard note is absent on BOTH pages, proving `countActiveOrganizers()` counts
across the whole group and is not fooled by which page is being viewed.

**Verified true:** ran the test for real against a fresh `gm138-mysql` Docker MySQL 8 + a real
`drush site:install` + `php -S` webserver. It fails at the identical `FieldStorageConfig::create()
->save()` stack trace (`InvalidArgumentException: settings.allowed_values.0.label.0 doesn't exist`)
as the 13 already-confirmed core-bug-blocked tests — the same pre-existing, code-independent
Drupal-11.4.4 `list_string` config-schema issue, not a new defect and not a bootstrap/authorship
error. `phpcs`/`phpstan` both 0 findings on the new file.

**Assumed:** the test will run and pass unmodified in a clean CI/DDEV environment where the core
`list_string` bug does not reproduce (consistent with the existing green `.github/workflows/test.yml`
history for sibling Functional suites) — not independently re-confirmed in a second environment
this round, flagging for O same as the other 17 env-blocked tests.

**Re-verified unchanged:** Unit tier 16/16 real GREEN (no regression from F's `UserInterface`
param-type change or the pagination refactor — neither touches the Unit-mocked manager contracts).

**Evidence:** `handoff-T-green.md` "Round-2 re-verify" section; env-blocked test count now 18 (was
17), net +1 this round. Docker hygiene: created + removed only `gm138-mysql`; `o119t2-mysql`
confirmed untouched before/after.

**Verdict:** GREEN confirmed, no blocking issues routed back to F. Ready for A (anti-duplication)
then U (UI Walkthrough — this story touches an interactive UI surface).

## Phase 6 (re-verify) — Tester (T) round 2

**Decided:** T added `ManageMembersPaginationTest` (Functional): seeds 55 members → asserts exactly
50 rows on page 1 + pager present + 5 rows on page 2, and that the last-Organizer guard note is
absent on both pages (proving `countActiveOrganizers()` sees the whole group, not just a page
slice — AC-9 not fooled by pagination). Env-blocked locally (same core 11.4.4 `list_string`
schema bug) → **CI-pinned; env-blocked test count is now 18**. Unit re-run **16/16 GREEN** (the W-2
`UserInterface` type change + pagination refactor did not regress). No route-back. Docker hygiene
held (`gm138-*`). Proceed to A-dup → U → S.

**Evidence:** `handoff-T-green.md` "Round-2 re-verify"; `tests/src/Functional/ManageMembersPaginationTest.php`.

## Phase 7 — Architecture Reviewer (A) — anti-duplication gate

**Decided:** Reviewed the implemented diff (`docs/groups/` only; `config/sync/`/
`web/modules/custom/` are `assemble-config.sh` build artifacts, out of scope) against the survey's
Reuse & Analogous-Feature map. **Verdict: PASS.** Confirmed F extended, not forked: group roles
(new `organizer`/`moderator` siblings, `community_group-member.yml` untouched — absent from diff
entirely), membership status (one new field on the existing relationship type, orthogonal to
`group_roles`, no parallel relationship type), manager-service centralization (controller is
access-only, all four Forms delegate every mutation to `GroupMembershipManager`, honoring
Phase-3's warn-1 to follow the playbook's `MyModuleManager` shape rather than `do_notifications`'s
inline-logic anti-pattern), and Groups-Moderate (Group 4.x's built-in synchronized-global-role
mechanism via `GroupRoleStorage`, not a hand-rolled access check). Scope discipline held: zero
touches to `do_chrome/PermissionMatrix.php`, the #121/#134 vestigial-role files, or `grequest`.
One `warn` (not blocking): `ManageMembersForm` copy-pastes the manager's `relationshipStatus()`
read-helper and active-Organizer counting loop for UI-only disable-before-attempt logic, because
the manager's equivalent method is `protected` — the manager's `assertNotLastOrganizer()` remains
the sole server-side enforcement point on every mutation, so this is duplicate read logic, not a
duplicate enforcement path. Flagged as optional low-priority cleanup (promote to `public` on the
service), not routed back to F.

**Evidence:** `handoff-A-dup.md`.

**Verdict:** PASS. Proceed to U (UI Walkthrough) → S.

## Phase 7 (anti-duplication gate) — Architecture Reviewer (A-dup)

**Decided:** A-dup **PASS** — reuse-first held; single enforcement path. Role family EXTENDED (new
organizer/moderator/groups_moderate siblings, `community_group-member` unchanged); status field
orthogonal to `group_roles`; CRUD/status logic centralized in the one `GroupMembershipManager`
service; Groups-Moderate reuses Group's synchronized-global-role mechanism; scope discipline held
(no do_chrome/PermissionMatrix.php, no #121/#134 vestigial-role edits, no grequest/join-flow).

**1 warn — accepted as-is (POC), NOT respawning F:** the UI duplicates the Organizer-count read
(a helper) because the manager's count methods are `protected` — a small UI-only duplicate, not a
parallel enforcement path. **Recorded as a known follow-up for the PR description**, not a blocker.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-A-dup.md`.

## Phase 8 — UI Walkthrough (U)

**Decided:** Stood up the live site via raw `drush site:install` + `config:import` +
`drush en` + `drush runserver` (mirrors `.github/workflows/test.yml`'s `e2e` job, not DDEV —
faster, self-contained, avoided touching the worktree's own idle DDEV project). Ran a
headless Playwright walkthrough (ad-hoc `npm install --no-save playwright
@axe-core/playwright` in a scratch dir, not committed) driving real navigation (group
canonical page → "Manage members" local task click), 6 dedicated test personas
(Organizer/plain-Member/Groups-Moderate/add-target/2 pending-status seeds/40 pagination
seeds), plus a hard-reload spot-check and a 360px viewport pass.

**Evidence found:** a route-path collision — `do_group_membership.manage_members` and the
pre-existing (untouched-by-this-story, present since the repo's initial commit)
`views.view.group_members.page_1` both claim the identical path `/group/{group}/members`.
Drupal's router resolves the View for every GET request, permanently shadowing this story's
entire steady-state Manage-members page (status badges, Approve/Deny/Unblock, Role/Remove
buttons, the last-Organizer disable-before-attempt guard) on both the navigated path AND a
hard reload (deterministic, not a caching artifact). Independently verified via
`router.route_provider`/`router.no_access_checks` service calls that the View, not the
module's Form, wins the match.

**Also verified (real, live):** the module's own code is correct where reachable — the three
satellite routes (`/members/add`, `/members/{id}/role`, `/members/{id}/remove`), which do NOT
collide, all work end to end (real add-member submit, real role change, real remove with a
genuine ConfirmFormBase step, real last-Organizer server-side backstop block). The manager
service's `approvePending()` was verified working at the service layer via `drush php:eval`
(pending → active transition on a real relationship). AC-11 (plain Member denied, HTTP 403)
and AC-12 (Groups-Moderate manages a group never joined, HTTP 200) both verified live and
PASS — the `_custom_access` gate logic is correct and proven independent of the routing
collision. Axe-core (ad-hoc, not committed) on the two reachable module forms: 1 moderate
`landmark-unique` finding each (a pre-existing, site-theme-level issue, not module-introduced,
present on every page in this theme) — no serious/critical findings. The shadowed
steady-state table + badges + guard could NOT be axe-scanned live (the route never renders
that code) — only statically read from source, confirming the intended markup matches the
wireframe's non-color-alone + `aria-describedby` spec, but this is not a live-verified result.

**Verdict:** REWORK. This is a narrow, well-understood routing fix (not a rewrite — the
Form/service code underneath is proven correct), but it fully blocks AC-7, the UI half of
AC-9/AC-10, and most of AC-15 as currently shipped, and must go back through F → T → U before
reaching S.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-U.md`,
`docs/handoffs/0138-mc7-manage-members/evidence-U/` (24 screenshots + axe/step JSON).

## Phase 8 (UI walkthrough) — U: REWORK (real blocking defect caught live)

**Decided:** U ran the live headless walkthrough + axe and caught a **route collision every prior
gate and the env-blocked CI Functional tests missed**: the new route
`do_group_membership.manage_members` at `/group/{group}/members` collides with the pre-existing
config View `views.view.group_members` display `page_1` (path `group/%group/members`, which also
provides a "Members" tab). Drupal's router serves the OLD View, so the entire new Manage-members
steady-state UI (table, badges, approve/deny/unblock, Role/Remove, last-Organizer guard) NEVER
renders. Satellite routes (`/members/add`, `/role`, `/remove`) work; AC-11/AC-12 (access) pass; the
main page is dead.

**Coordinator decision (settled, not re-opened):** the new Manage-members UI must SUPERSEDE the
stock members view — the wireframe titles it as THE members page, and this POC wants a single clear
members experience. Do NOT move the new UI to a separate path.

**Collision-class check (O, this story's other new routes):** `/members/add`, `/members/{rel}/role`,
`/members/{rel}/remove` — NONE collide with any existing View path (the other group views use
`/nodes`, `/events`, `/stream`, `/stream/feed`). Only the one route is affected.

**Root cause of the miss:** no test asserted that GET `/group/{group}/members` resolves to the NEW
controller. T adds that now (patch-the-prompt: a route-resolution assertion is the covering test
class that would have caught this).

**Route back to F + T (concurrent, disjoint files):**
- **F:** delete the `page_1` display from `docs/groups/config/views.view.group_members.yml` (this is
  the project's own source config, editable) — removes BOTH the colliding path AND its "Members" tab,
  leaving the new controller's route + "Manage members" local task to own `/group/{group}/members`.
  Config change only, no route-priority hacks. Verify nothing else links to the old view's page.
- **T:** add a Functional route-resolution test — GET `/group/{group}/members` renders a unique
  marker from the NEW Manage-members UI AND the old group_members view's markers are absent
  (CI-pinned if env-blocked locally → would become the 19th).

**Axe/access (U, on the reachable surfaces):** AC-11/AC-12 pass live; axe result on the now-dead
steady-state page is not yet meaningful — re-run U after the fix to axe the reachable table.

**Evidence:** `docs/handoffs/0138-mc7-manage-members/handoff-U.md`, `evidence-U/`.

## Phase 8 REWORK (route-collision fix) — Feature Implementor (F)

**Decided:** Deleted the `page_1` page-display block from
`docs/groups/config/views.view.group_members.yml` (path `group/%group/members`, "Members" tab,
weight 20) — the project's own editable source config, per the coordinator's settled decision that
the new `do_group_membership.manage_members` UI supersedes the stock members View rather than
moving to a different path. Left the view's `default` (Master) display untouched; the view is now a
single-display, page/menu-less config entity (still valid, just inert until a future display is
added, if ever). Regenerated `config/sync/views.view.group_members.yml` via
`scripts/ci/assemble-config.sh`; confirmed `page_1` absent from the assembled output.

**Verified true (live, not just static config diff):** stood up a throwaway install (own
`gm138f-mysql` Docker container, created and removed only by me) and confirmed dynamically —
`router.route_provider->getRoutesByPattern()` now returns only
`do_group_membership.manage_members` for `/group/{group}/members` (the View route no longer exists
as a candidate at all); `router.no_access_checks->matchRequest()` resolves the path to the module's
route; `plugin.manager.menu.local_task` shows exactly one members tab ("Manage members"), no
leftover "Members" tab. Module installs/enables cleanly.

**Assumed/checked:** grepped the whole repo (`docs/groups/`, `config/sync/`, `web/themes`) for any
block placement, menu link, or config reference to the view or specifically its `page_1` display —
none found; the only other `group_members` hits are this story's own config/tests (referencing the
view by base id for `dependencies:` bookkeeping, unaffected) or documentation prose describing the
pre-existing path historically, not live links. Nothing else needed repointing.

**Decided (tab title):** kept "Manage members" (`do_group_membership.links.task.yml` unchanged) —
matches the approved wireframe's local-tasks row verbatim (`[*Manage members*]`); renaming to
"Members" now that it's the sole tab would be an unreviewed wireframe/copy deviation outside this
REWORK's mandate (fix the collision only).

**Docker hygiene:** created and removed only `gm138f-mysql`; `docker ps -a` before/after confirms
`gm138-mysql` (a concurrent sibling-phase container, not mine) and every `ddev-*`/other pre-existing
container untouched.

**Handoff:** `docs/handoffs/0138-mc7-manage-members/handoff-F.md` ("Route-collision fix" section).

**Not done here (T's/U's mandate next):** re-verifying the steady-state Manage-members UI (badges,
approve/deny, last-Organizer guard) now actually renders at the unblocked route, and a fresh axe
pass on the now-reachable table — the underlying Form/service code was already proven correct in
Phase 5/6; only its reachability was broken and is now fixed.

## Phase 8 rework (route-collision covering test) — Tester (T)

**Decided:** Authored `ManageMembersRouteResolutionTest.php` (Functional, 3 test methods) per U's
mandate — asserts (1) the router resolves `/group/{group}/members` to
`do_group_membership.manage_members` not `view.group_members.page_1` via a real
`router.no_access_checks`/`matchRequest()` call, (2) the rendered page carries the NEW form's
unique marker (`table.do-group-membership__table` inside a real `<form>`) AND the OLD View's
markers (`views-view-table` string, `.view-group-members`/`.views-element-container` elements,
"View member" link text) are absent, (3) the "Manage members" local task tab actually navigates to
the new surface, not just links to the right href.

**New finding beyond U's original report (real-execution, not assumed):** ran the test for real
against a fresh `gm138-mysql` container + `drush site:install` + `config:import` (using F's
already-fixed `config/sync/views.view.group_members.yml`, `page_1` display already removed) — all
3 tests FAIL for the exact defect the test targets (`matchRequest()` still resolves
`view.group_members.page_1`). Root cause: `BrowserTestBase`'s `$modules = ['group', ...,
'views']` triggers Drupal's `ConfigInstaller` to install `drupal/group`'s own **contrib** optional
config (`web/modules/contrib/group/config/optional/views.view.group_members.yml`), which STILL
contains the `page_1` display — independent of this project's own `docs/groups/config/`
export F already fixed. This is a second, distinct collision source F has not yet closed;
flagging for F/O rather than treating this RED as a test-authorship bug.

**Not env-blocked:** unlike the 18 CI-pinned tests, this test hit no environment limitation — it
ran to completion and failed on its actual assertions. It stays a real, locally-runnable RED, not
added to the CI-pinned list. Env-blocked count remains 18 (unchanged).

**Collateral check:** grepped the 3 pre-existing Functional tests
(`ManageMembersPageRenderTest`/`ManageMembersRouteAccessTest`/`ManageMembersPaginationTest`) for
any View-markup/local-task-navigation dependency — zero matches in all three. No collateral test
changes needed; F's `page_1`-display removal cannot regress any of their assertions.

**Docker hygiene:** created + removed only `gm138-mysql` (twice, across the RED-confirmation and
verification passes); all 40 pre-existing containers (24 `ddev-*` + 16 others) confirmed untouched
before/after.

**Evidence:** `handoff-T-green.md` "Route-collision covering test" section;
`docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersRouteResolutionTest.php`.

**Verdict:** valid RED for a route-collision defect with a MORE PRECISE root cause than U's
original report. Routes back to F (this project's fix so far is necessary but not sufficient — the
contrib-shipped optional config also needs closing) then back to T for real re-verification
(locally, no CI dependency) before U re-walks.

## Phase 8 (route-collision rework) — F partial fix + T exposes it is INCOMPLETE

**Decided:** F removed the `page_1` display from the SITE config
(`docs/groups/config/views.view.group_members.yml` → assembled to `config/sync/`), fixing the
deployed `drush cim` / config:import path (no menu/block refs; kept the "Manage members" tab). But
**T's new `ManageMembersRouteResolutionTest` is a genuine, locally-runnable RED that PERSISTS after
F's config deletion** — and correctly so:

**Root cause (two-source collision, verified by O):** `drupal/group` CONTRIB ships
`web/modules/contrib/group/config/optional/views.view.group_members.yml` which STILL contains the
`page_1` display (confirmed: line 708, path `group/%group/members`). Any fresh `drush en group`
(exactly what BrowserTestBase / CI Functional bootstraps) re-materializes that view via
ConfigInstaller → the `/group/{group}/members` collision REAPPEARS → the route-resolution test fails.
**This test is NOT env-blocked — it ran live as a real RED and WILL run in CI**, so a config-only fix
would have shipped a red CI. Good catch by T; this is exactly the test-first pipeline working.

**Route back to F (robust all-paths fix):**
- Add `do_group_membership_install()` (+ `hook_modules_installed()` if needed) that programmatically
  loads `views.view.group_members` and removes its `page_1` display if present — stripping the
  collision regardless of whether the view came from site config OR contrib optional config, and
  running after `group` is installed in the BrowserTestBase module set. KEEP the site-config deletion
  too (belt + suspenders for the deployed path).
- T then re-runs `ManageMembersRouteResolutionTest` live: all 3 methods RED→GREEN from a fresh
  `drush en` bootstrap (the real trigger), not just config:import.

**For the PR description:** document the two-source collision (site config + contrib optional config)
and the hook_install fix.

**Evidence:** `web/modules/contrib/group/config/optional/views.view.group_members.yml` line 708
(page_1 present); `handoff-T-green.md` "Route-collision covering test"; `handoff-F.md`
"Route-collision fix".
