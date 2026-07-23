# Handoff-A-dup: Phase 7 - #143 Archive/Restore  (anti-duplication gate)

**Date:** 2026-07-22
**Branch:** 143-archive-restore
**Diff base:** origin/main   **Diff head:** 6988e38
**Reuse map:** docs/planning/handoffs/143-archive-restore/brief.md (§ Reuse map), survey.md
**Verdict:** PASS

## Summary
F extended `do_group_extras` with a `ConfirmFormBase` subclass + shared access-controller class, faithfully mirroring `do_group_membership`'s form-per-action + `ManageMembersController` pattern. No parallel path, no parallel state field, no cross-module leakage. The narrow `TrustedCallbackInterface` + `preRenderAsButtonTag` addition is a one-off render fix kept private to `RestoreGroupForm` — correct not to extract yet.

## Findings
| # | Severity | File:line | Finding | Suggested fix |
|---|---|---|---|---|
| — | — | — | No duplication; extension is clean. | — |

Detail (evidence, not findings):
- **Route** (`do_group_extras.routing.yml:1-12`) uses `_form` + `_custom_access` — identical mechanism to `do_group_membership.routing.yml`'s four routes. No event subscriber / route enhancer / service invented.
- **Task plugin** (`do_group_extras.links.task.yml`) uses `base_route: entity.group.canonical` + `weight` — same shape as MMC's `manage_members` task.
- **Access controller** (`RestoreGroupAccess::access`) returns `AccessResult` with `addCacheableDependency($group)` + `cachePerPermissions()` + `cachePerUser()` — same shape as `ManageMembersController::access`. Uses a scoped permission (`edit group`) + site-admin escape hatch (`administer group`), same two-clause pattern as MMC. `_custom_access` referenced FQCN string via `::access` (matches MMC).
- **State field**: only `field_group_type` read/written; the three-clause Archive-detection matches the existing `DoGroupExtrasHooks::preprocessGroup()`/`nodeAccess()` shape verbatim. No boolean, no computed property, no parallel status entity.
- **Cross-module leakage**: `git diff --stat` confirms every source path is under `docs/groups/modules/do_group_extras/` (plus tests + planning docs). Zero edits to `do_group_membership`, `group`, or any other module.
- **`TrustedCallbackInterface` + `preRenderAsButtonTag`**: kept private to `RestoreGroupForm`; not extracted to a trait or base class. Given the WCAG-debt follow-up is scoped out of this POC (per O), premature extraction would presume the shape of the eventual shared fix. Confirming: extract when #138's fix lands, not now.

## Notes for F
None. Proceed to U/S.
