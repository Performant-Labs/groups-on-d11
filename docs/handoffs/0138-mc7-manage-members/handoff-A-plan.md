# Handoff-A: Phase 3 - Issue #138 (MC-7) Organizer manage-members UI + group roles + Groups Moderate (up-front plan review)

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Brief reviewed:** `docs/handoffs/0138-mc7-manage-members/brief.md`   **Reuse map:** `docs/handoffs/0138-mc7-manage-members/survey.md` (§"Reuse & Analogous-Feature map")   **Wireframe:** `docs/handoffs/0138-mc7-manage-members/wireframe.md` (approved, `handoff-D.md`)
**Verdict:** PASS

## Summary

The plan is architecturally sound and reuse-first. Group-role extension, relationship-type field
addition, and the synchronized-global-role mechanism for Groups-Moderate all match real,
independently-verifiable Group 4.x behavior — the B-5 correction is not just a `git.drupalcode.org`
source read, it is corroborated live in this repo's own `AccessPolicyEnforcementTest`
(`SynchronizedGroupRoleAccessPolicy` feeding OUTSIDER/INSIDER scopes). The one drift risk — the
brief's "manager service" being unprecedented in any existing sibling `do_*` module — is not
invented architecture; it is the **documented, mandatory** pattern in
`docs/playbook/frameworks/drupal/best-practices.md` §"Custom Module Architecture: Services over
Hooks," which every existing module actually violates (they all use only a `*Hooks` class or
inline controller logic). This is a `warn`, not a `block`: F should be told there is no living
sibling exemplar of the manager-service pattern to mirror, only the playbook's inline code sample.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | `do_group_membership.manager` service (Reuse map, brief §"Manage-members UI") | pattern consistency | Every existing `do_*` module (`do_notifications`, `do_group_extras`, `do_multigroup`, `do_chrome`, `do_discovery`, `do_group_pin`, `do_profile_stats`) registers only a `*Hooks` class tagged `hook_implementations` in `*.services.yml`; none has a standalone CRUD/business-logic "Manager" service injected into a Controller/Form. `NotificationSettingsController` (the brief's own named structural analog) puts logic directly in the controller and even uses `\Drupal::state()`/`\Drupal::request()` service-location inline, which the playbook itself flags as the anti-pattern. The brief's manager-service design is *correct per the mandatory playbook doc*, not drift — but F needs to be told explicitly there is no sibling module to copy-paste the DI wiring from, since blindly mirroring `do_notifications`'s actual code would reproduce the anti-pattern the brief is trying to avoid. | Note in F's handoff/brief: base the service class + `*.services.yml` wiring on the playbook's own `MyModuleManager` example (verbatim shape), not on `do_notifications`'s controller internals — use `do_notifications` only for the routing/`links.task.yml`/`{group}`-param-upcasting shape, not for the service-injection pattern. |
| 2 | warn | AC-6 access logic / synchronized-global-role mechanism (B-5) | residual assumption (flagged by O) | O flagged this for A to verify since no local `vendor/drupal/group` tree exists in this worktree (confirmed: none exists in this worktree, nor in the read-only reference checkout `~/Projects/groups-on-d11`). I could not do a byte-level vendor-source check either. However, the mechanism is independently corroborated by this repo's own live test, `do_tests/tests/src/Kernel/AccessPolicyEnforcementTest.php`, which already asserts `GroupInterface::hasPermission()` as the enforcement entry point and documents `SynchronizedGroupRoleAccessPolicy` (priority -50) feeding the OUTSIDER/INSIDER scopes — the exact mechanism B-5's correction describes. Confidence is high, but this is still an assumption not closed by a byte-for-byte vendor read. | No brief change needed. T should add one Kernel test asserting the specific `scope: insider` + `global_role: groups_moderate` + `admin: true` config entity actually grants `hasPermission('administer members', $groups_moderate_user)` on a group the user never joined — this closes the residual assumption empirically at GREEN, which is the earliest point it can be closed without a vendor tree. |

No `block` findings. Plan is consistent with existing patterns.

## Notes for O

None required (PASS). For F: when authoring `do_group_membership.manager`, follow the playbook's
literal `MyModuleManager` example for the service/DI shape (not `do_notifications`'s inline-logic
controller), and mirror `do_notifications`/`do_discovery` only for routing, `{group}` param
upcasting (`do_discovery`'s `type: entity:group` pattern), and `.links.task.yml` shape. For T: add
the Kernel-level synchronized-global-role assertion described in Finding #2 to close the one
residual assumption empirically.

## Patterns referenced

- `docs/playbook/frameworks/drupal/best-practices.md` §"Custom Module Architecture: Services over
  Hooks" — the mandatory manager-service pattern the brief's design must follow.
- `docs/groups/modules/do_notifications/{do_notifications.routing.yml,do_notifications.links.task.yml,src/Controller/NotificationSettingsController.php}` — structural analog for route/controller/local-task; also the counter-example showing why the manager-service pattern is *not* yet exemplified anywhere in this codebase.
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` + `do_group_extras.services.yml` — confirms `administer group`/`administer groups` as the live permission-gate anchor pattern.
- `docs/groups/modules/do_discovery/do_discovery.routing.yml` — confirms `{group}` route-param upcasting (`type: entity:group`) precedent for AC-6's route.
- `docs/groups/modules/do_tests/tests/src/Kernel/{GroupsKernelTestBase.php,AccessPolicyEnforcementTest.php}` — confirms `GroupsKernelTestBase`'s `addMember()`/`createGroup()` fixture API is real and matches the brief's forward-compat claims for #144; independently corroborates the B-5 synchronized-global-role mechanism live in this repo (not just via `git.drupalcode.org` reads).
- `composer.json`/`composer.lock` (`drupal/group: 4.0.x-dev`) — confirms no local vendor tree exists in this worktree or the reference checkout, so the B-5/AC-6/AC-13 mechanism remains a verified-via-source-read (and now test-corroborated) assumption rather than a byte-for-byte vendor check.
